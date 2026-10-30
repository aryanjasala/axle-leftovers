<?php
/**
 * Online lookup: which WordPress.org plugin names an Unknown item in its code.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Asks Veloria (veloria.dev, the code search of every WordPress.org plugin,
 * formerly WPDirectory) which plugins mention an item's name. Opt-in, and only
 * from an admin action, WP-CLI or an ability: never on a page load, never in
 * the background.
 *
 * Veloria searches asynchronously and allows 10 requests a minute per IP, so a
 * lookup is one POST plus a few polls, lookups are spaced apart, and every
 * result (also "nothing") is cached.
 */
class Lookup {

	public const OPTION   = 'axle_leftovers_lookups';
	public const LOCK     = 'axle_leftovers_lookup_lock';
	public const ENDPOINT = 'https://veloria.dev/api/v1/search/';

	/**
	 * Names shorter than this match too much code to say anything.
	 */
	public const MIN_LENGTH = 6;

	/**
	 * Most cached lookups kept.
	 */
	private const MAX_CACHED = 500;

	/**
	 * The search endpoint.
	 *
	 * @return string
	 */
	public static function endpoint(): string {
		/**
		 * Filters the code search endpoint. It has to speak the Veloria API:
		 * POST a JSON search, GET `<endpoint><id>` for the result.
		 *
		 * @param string $endpoint HTTPS URL ending in a slash.
		 */
		$endpoint = esc_url_raw( (string) apply_filters( 'axle_leftovers_lookup_endpoint', self::ENDPOINT ), array( 'https' ) );

		return '' !== $endpoint ? trailingslashit( $endpoint ) : self::ENDPOINT;
	}

	/**
	 * Seconds between two lookups.
	 *
	 * @return int
	 */
	public static function min_interval(): int {
		/**
		 * Filters the least number of seconds between two online lookups. Veloria
		 * allows 10 requests a minute and one lookup sends up to 5.
		 *
		 * @param int $seconds 1 to 3600. Default 30.
		 */
		return max( 1, min( 3600, (int) apply_filters( 'axle_leftovers_lookup_min_interval', 30 ) ) );
	}

	/**
	 * Days a cached lookup stays valid.
	 *
	 * @return int
	 */
	public static function cache_days(): int {
		/**
		 * Filters how long a lookup result is cached.
		 *
		 * @param int $days 1 to 365. Default 30.
		 */
		return max( 1, min( 365, (int) apply_filters( 'axle_leftovers_lookup_cache_days', 30 ) ) );
	}

	/**
	 * How many candidates one result keeps.
	 *
	 * @return int
	 */
	public static function max_candidates(): int {
		/**
		 * Filters how many plugins a lookup result lists.
		 *
		 * @param int $max 1 to 20. Default 5.
		 */
		return max( 1, min( 20, (int) apply_filters( 'axle_leftovers_lookup_max_candidates', 5 ) ) );
	}

	/**
	 * Above this many matching plugins the name is too common to attribute.
	 *
	 * @return int
	 */
	public static function max_matches(): int {
		/**
		 * Filters how many plugins may name an item before the lookup gives no
		 * owner (a name in more plugins than this is shared, not owned).
		 *
		 * @param int $max 1 to 200. Default 20.
		 */
		return max( 1, min( 200, (int) apply_filters( 'axle_leftovers_lookup_max_matches', 20 ) ) );
	}

	/**
	 * The name to search for: tables without the site prefix.
	 *
	 * @param string $type Item type.
	 * @param string $name Item name.
	 * @return string
	 */
	public static function bare( string $type, string $name ): string {
		$bare = Attribution::bare_name( $type, $name );

		return (string) preg_replace( '/^_(site_)?transient_(timeout_)?/', '', $bare );
	}

	/**
	 * The regular expression sent to the search: the name, escaped.
	 *
	 * @param string $type Item type.
	 * @param string $name Item name.
	 * @return string
	 */
	public static function term( string $type, string $name ): string {
		$term = preg_quote( self::bare( $type, $name ), '/' );

		/**
		 * Filters the regular expression (Go RE2 syntax) a lookup sends. Only
		 * this string leaves the site.
		 *
		 * @param string $term Escaped name.
		 * @param string $type Item type.
		 * @param string $name Item name.
		 */
		return (string) apply_filters( 'axle_leftovers_lookup_term', $term, $type, $name );
	}

	/**
	 * Cache key of an item.
	 *
	 * @param string $type Item type.
	 * @param string $name Item name.
	 * @return string
	 */
	public static function key( string $type, string $name ): string {
		return sha1( $type . "\n" . self::bare( $type, $name ) );
	}

	/**
	 * Every cached lookup.
	 *
	 * @return array<string, array>
	 */
	public function all(): array {
		$all = get_option( self::OPTION, array() );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * A cached lookup that has not expired.
	 *
	 * @param string $type Item type.
	 * @param string $name Item name.
	 * @return array|null time, total, confidence, candidates (slug, name, installs, url).
	 */
	public function cached( string $type, string $name ): ?array {
		$hit = $this->all()[ self::key( $type, $name ) ] ?? null;
		if ( ! is_array( $hit ) || ! isset( $hit['time'] ) || (int) $hit['time'] < time() - self::cache_days() * DAY_IN_SECONDS ) {
			return null;
		}

		return $hit;
	}

	/**
	 * Forgets every cached lookup.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Looks up a listed leftover that has no owner (or only a guess), then works
	 * its owner out again. Only names the scan listed are ever sent: never core
	 * or protected names, and never a name typed in by hand.
	 *
	 * @param Scanner $scanner Scanner.
	 * @param string  $type    Item type.
	 * @param string  $name    Item name.
	 * @param bool    $refresh Ask again even when a result is cached.
	 * @return array{result: array, item: array|null}|WP_Error
	 */
	public function for_item( Scanner $scanner, string $type, string $name, bool $refresh = false ) {
		$item = $scanner->item( $type, $name );
		if ( ! $item ) {
			return new WP_Error( 'axle_leftovers_not_listed', Messages::get( 'not_listed', $name ) );
		}
		if ( 'unknown' !== $item['confidence'] && 'guess' !== $item['confidence'] ) {
			return new WP_Error( 'axle_leftovers_lookup_owned', Messages::get( 'lookup_owned', $name ) );
		}
		$result = $this->find( $type, $name, $refresh );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'result' => $result,
			'item'   => $scanner->reattribute( $type, $name ),
		);
	}

	/**
	 * Looks an item up, from the cache when it can.
	 *
	 * @param string $type    Item type.
	 * @param string $name    Item name.
	 * @param bool   $refresh Ask again even when a result is cached.
	 * @return array|WP_Error The result, see cached().
	 */
	public function find( string $type, string $name, bool $refresh = false ) {
		if ( ! Settings::online_lookup() ) {
			return new WP_Error( 'axle_leftovers_lookup_off', Messages::get( 'lookup_off' ) );
		}
		if ( strlen( self::bare( $type, $name ) ) < self::MIN_LENGTH ) {
			return new WP_Error( 'axle_leftovers_lookup_short', Messages::get( 'lookup_short', $name, (string) self::MIN_LENGTH ) );
		}
		if ( ! $refresh ) {
			$hit = $this->cached( $type, $name );
			if ( $hit ) {
				return $hit;
			}
		}

		$until = (int) get_transient( self::LOCK );
		if ( $until > time() ) {
			return new WP_Error( 'axle_leftovers_lookup_wait', Messages::get( 'lookup_wait', (string) ( $until - time() ) ) );
		}
		set_transient( self::LOCK, time() + self::min_interval(), self::min_interval() );

		$matches = $this->search( self::term( $type, $name ) );
		if ( is_wp_error( $matches ) ) {
			return $matches;
		}

		$result = $this->result( $matches );
		$this->store( $type, $name, $result );

		/**
		 * Fires after an online lookup found a result (also when nothing matched).
		 *
		 * @param array  $result Result: time, total, confidence, candidates.
		 * @param string $type   Item type.
		 * @param string $name   Item name.
		 */
		do_action( 'axle_leftovers_lookup_done', $result, $type, $name );

		return $result;
	}

	/**
	 * Runs one search and reads the matching plugins.
	 *
	 * @param string $term Regular expression.
	 * @return array|WP_Error Plugins (slug, name, installs), most installed first.
	 */
	private function search( string $term ) {
		$endpoint = self::endpoint();
		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout' => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Admin action or CLI only, never a page load; the search runs on the remote side.
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => (string) wp_json_encode(
					array(
						'term'               => $term,
						'repo'               => 'plugins',
						'file_match'         => '\.php$',
						'exclude_file_match' => '(^|/)(vendor|node_modules|tests?|languages)/',
						'case_sensitive'     => true,
						'public'             => false,
					)
				),
			)
		);
		$body     = $this->read( $response );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$id = isset( $body['id'] ) && is_string( $body['id'] ) ? $body['id'] : '';
		if ( '' === $id || 1 !== preg_match( '/^[A-Za-z0-9-]{8,64}$/', $id ) ) {
			return new WP_Error( 'axle_leftovers_lookup_bad', Messages::get( 'lookup_bad' ) );
		}

		/**
		 * Filters how many times a lookup asks whether the search finished.
		 * Every poll counts toward the service's rate limit.
		 *
		 * @param int $polls 1 to 10. Default 4.
		 */
		$polls = max( 1, min( 10, (int) apply_filters( 'axle_leftovers_lookup_polls', 4 ) ) );
		/**
		 * Filters the seconds between two polls.
		 *
		 * @param int $seconds 0 to 10. Default 2.
		 */
		$wait = max( 0, min( 10, (int) apply_filters( 'axle_leftovers_lookup_poll_interval', 2 ) ) );

		for ( $poll = 0; $poll < $polls; $poll++ ) {
			if ( $wait > 0 ) {
				sleep( $wait ); // phpcs:ignore WordPressVIPMinimum.Performance.NoSleep -- Waits for the remote search; admin action or CLI only.
			}
			$body = $this->read(
				wp_safe_remote_get( $endpoint . $id, array( 'timeout' => 15 ) ) // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Admin action or CLI only.
			);
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$status = (string) ( $body['status'] ?? '' );
			if ( 'failed' === $status ) {
				return new WP_Error( 'axle_leftovers_lookup_failed', Messages::get( 'lookup_failed' ) );
			}
			if ( 'completed' === $status ) {
				return $this->plugins( $body );
			}
		}

		return new WP_Error( 'axle_leftovers_lookup_pending', Messages::get( 'lookup_pending' ) );
	}

	/**
	 * Reads a response: the JSON body, or an error. Accepts the documented
	 * `{status, data}` envelope and a bare object.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return array|WP_Error
	 */
	private function read( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'axle_leftovers_lookup_http', Messages::get( 'lookup_http', $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			set_transient( self::LOCK, time() + 60, 60 );

			return new WP_Error( 'axle_leftovers_lookup_limited', Messages::get( 'lookup_limited' ) );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code > 299 || ! is_array( $body ) ) {
			return new WP_Error( 'axle_leftovers_lookup_http', Messages::get( 'lookup_http', (string) $code ) );
		}

		return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
	}

	/**
	 * The plugins of a finished search.
	 *
	 * @param array $body Search object.
	 * @return array{plugins: array, total: int}
	 */
	private function plugins( array $body ): array {
		$list    = $body['results']['results'] ?? array();
		$plugins = array();
		foreach ( is_array( $list ) ? $list : array() as $plugin ) {
			if ( ! is_array( $plugin ) || empty( $plugin['slug'] ) || ! is_string( $plugin['slug'] ) ) {
				continue;
			}
			$plugins[] = array(
				'slug'     => sanitize_key( $plugin['slug'] ),
				'name'     => sanitize_text_field( (string) ( $plugin['name'] ?? $plugin['slug'] ) ),
				'installs' => max( 0, (int) ( $plugin['active_installs'] ?? 0 ) ),
			);
		}
		usort(
			$plugins,
			static function ( array $a, array $b ): int {
				return array( $b['installs'], $a['slug'] ) <=> array( $a['installs'], $b['slug'] );
			}
		);

		return array(
			'plugins' => $plugins,
			'total'   => count( $plugins ),
		);
	}

	/**
	 * Turns the matching plugins into a result with a confidence.
	 *
	 * Exactly one plugin: likely. Two up to the limit: guess, the most installed
	 * first. None, or more than the limit: no owner.
	 *
	 * @param array $matches From plugins().
	 * @return array
	 */
	private function result( array $matches ): array {
		$total      = (int) $matches['total'];
		$confidence = '';
		if ( 1 === $total ) {
			$confidence = 'likely';
		} elseif ( $total > 1 && $total <= self::max_matches() ) {
			$confidence = 'guess';
		}

		$candidates = array();
		if ( '' !== $confidence ) {
			foreach ( array_slice( $matches['plugins'], 0, self::max_candidates() ) as $plugin ) {
				$candidates[] = $plugin + array( 'url' => 'https://wordpress.org/plugins/' . $plugin['slug'] . '/' );
			}
		}

		return array(
			'time'       => time(),
			'total'      => $total,
			'confidence' => $confidence,
			'candidates' => $candidates,
		);
	}

	/**
	 * Caches a result, keeping the newest ones.
	 *
	 * @param string $type   Item type.
	 * @param string $name   Item name.
	 * @param array  $result Result.
	 */
	private function store( string $type, string $name, array $result ): void {
		$all                              = $this->all();
		$all[ self::key( $type, $name ) ] = $result;
		if ( count( $all ) > self::MAX_CACHED ) {
			uasort(
				$all,
				static function ( array $a, array $b ): int {
					return (int) ( $b['time'] ?? 0 ) <=> (int) ( $a['time'] ?? 0 );
				}
			);
			$all = array_slice( $all, 0, self::MAX_CACHED, true );
		}
		update_option( self::OPTION, $all, false );
	}
}
