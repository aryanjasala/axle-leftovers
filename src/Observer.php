<?php
/**
 * Watches which post types and taxonomies code registers, and on which kinds of request.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Registration happens on `init`, and some types register only in wp-admin,
 * in REST or on demand. So one look at the registered types proves nothing.
 * The observer notes, at the end of each request, which post types and
 * taxonomies were registered and which kind of request it was (front, admin,
 * rest, cron, ajax or cli). A type is only called unregistered once the
 * watched kinds have all been seen for the whole observation period (7 days
 * by default) and the type was not registered in any of them since.
 *
 * The record is one small autoloaded option, written at most once an hour per
 * type, so a page load costs a comparison and nothing else.
 */
final class Observer implements Service {

	public const OPTION = 'axle_leftovers_observed';

	/**
	 * A record is refreshed when it is older than this.
	 */
	private const REFRESH = HOUR_IN_SECONDS;

	/**
	 * Adds the hook.
	 */
	public function register(): void {
		add_action( 'shutdown', array( $this, 'record' ), 0 );
	}

	/**
	 * The kind of the current request.
	 *
	 * @return string front, admin, rest, cron, ajax or cli.
	 */
	public static function kind(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$kind = 'cli';
		} elseif ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			$kind = 'cron';
		} elseif ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			$kind = 'ajax';
		} elseif ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) ) {
			$kind = 'rest';
		} elseif ( is_admin() ) {
			$kind = 'admin';
		} else {
			$kind = 'front';
		}

		/**
		 * Filters the kind Leftovers files the current request under.
		 *
		 * @param string $kind front, admin, rest, cron, ajax or cli.
		 */
		$filtered = (string) apply_filters( 'axle_leftovers_request_kind', $kind );

		return in_array( $filtered, array( 'front', 'admin', 'rest', 'cron', 'ajax', 'cli' ), true ) ? $filtered : $kind;
	}

	/**
	 * The stored record.
	 *
	 * @return array{since: int, kinds: array<string, array{first: int, last: int}>, post_types: array<int|string, int>, taxonomies: array<int|string, int>}
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'since'      => (int) ( $stored['since'] ?? 0 ),
			'kinds'      => is_array( $stored['kinds'] ?? null ) ? $stored['kinds'] : array(),
			'post_types' => is_array( $stored['post_types'] ?? null ) ? $stored['post_types'] : array(),
			'taxonomies' => is_array( $stored['taxonomies'] ?? null ) ? $stored['taxonomies'] : array(),
		);
	}

	/**
	 * Notes what this request registered. Runs on `shutdown`, when every
	 * plugin and theme has had its chance to register.
	 *
	 * @param int|null $now Time, for tests.
	 */
	public function record( $now = null ): void {
		if ( ( function_exists( 'wp_installing' ) && wp_installing() ) || ! did_action( 'init' ) ) {
			return;
		}

		$now   = is_int( $now ) ? $now : time();
		$state = self::state();
		$kind  = self::kind();
		$types = array_map( 'strval', array_keys( (array) get_post_types() ) );
		$taxes = array_map( 'strval', array_keys( (array) get_taxonomies() ) );
		$due   = $now - (int) ( $state['kinds'][ $kind ]['last'] ?? 0 ) >= self::REFRESH;
		foreach ( $types as $type ) {
			$due = $due || $now - (int) ( $state['post_types'][ $type ] ?? 0 ) >= self::REFRESH;
		}
		foreach ( $taxes as $tax ) {
			$due = $due || $now - (int) ( $state['taxonomies'][ $tax ] ?? 0 ) >= self::REFRESH;
		}
		if ( ! $due ) {
			return;
		}

		if ( ! $state['since'] ) {
			$state['since'] = $now;
		}
		$state['kinds'][ $kind ] = array(
			'first' => (int) ( $state['kinds'][ $kind ]['first'] ?? $now ),
			'last'  => $now,
		);
		foreach ( $types as $type ) {
			$state['post_types'][ $type ] = $now;
		}
		foreach ( $taxes as $tax ) {
			$state['taxonomies'][ $tax ] = $now;
		}
		update_option( self::OPTION, $state, true );

		/**
		 * Fires when the observation record was written: the first sighting of a
		 * kind of request or of a type, or a refresh after an hour.
		 *
		 * @param string $kind  The kind of the current request: front, admin, rest, cron, ajax or cli.
		 * @param array  $state The record: since, kinds, post_types, taxonomies.
		 */
		do_action( 'axle_leftovers_observation_recorded', $kind, $state );
	}

	/**
	 * Whether a post type or taxonomy counts as unregistered.
	 *
	 * - `registered`: code registers it on this request.
	 * - `recent`: it was registered in the last N days, on some kind of request.
	 * - `watching`: the watched kinds have not all been seen for N days yet.
	 * - `unregistered`: nothing registered it in the last N days, and every watched kind was seen for those N days.
	 *
	 * @param string   $type post_type or taxonomy (the item types `posttype` and `taxonomy` are accepted too).
	 * @param string   $name Slug.
	 * @param int|null $now  Time, for tests.
	 * @return array{status: string, days: int, last: int, ready: int}
	 */
	public static function verdict( string $type, string $name, $now = null ): array {
		$now   = is_int( $now ) ? $now : time();
		$days  = Settings::observe_days();
		$taxo  = in_array( $type, array( 'taxonomy', 'taxonomies' ), true );
		$state = self::state();
		$last  = (int) ( $taxo ? ( $state['taxonomies'][ $name ] ?? 0 ) : ( $state['post_types'][ $name ] ?? 0 ) );
		$out   = array(
			'status' => 'unregistered',
			'days'   => $days,
			'last'   => $last,
			'ready'  => 0,
		);

		if ( $taxo ? taxonomy_exists( $name ) : post_type_exists( $name ) ) {
			$out['status'] = 'registered';

			return $out;
		}

		$cutoff = $now - ( $days * DAY_IN_SECONDS );
		if ( $last > $cutoff ) {
			// Registered lately: the clock starts when it was last seen.
			$out['status'] = 'recent';
			$out['ready']  = $last + ( $days * DAY_IN_SECONDS );

			return $out;
		}

		// Every watched kind has to have been seen from the start of the period until now.
		$ready = $state['since'] ? $state['since'] + ( $days * DAY_IN_SECONDS ) : $now + ( $days * DAY_IN_SECONDS );
		foreach ( Settings::observe_kinds() as $kind ) {
			$seen = $state['kinds'][ $kind ] ?? null;
			if ( ! $seen ) {
				$ready = max( $ready, $now + ( $days * DAY_IN_SECONDS ) );
				continue;
			}
			$ready = max( $ready, (int) $seen['first'] + ( $days * DAY_IN_SECONDS ) );
		}
		if ( $ready > $now ) {
			$out['status'] = 'watching';
			$out['ready']  = $ready;
		}

		return $out;
	}

	/**
	 * What the observer has seen, for WP-CLI, REST and abilities.
	 *
	 * @param int|null $now Time, for tests.
	 * @return array{days: int, since: string, kinds: array<int, array{kind: string, first: string, last: string, watched_days: int, required: bool}>, post_types: int, taxonomies: int, ready: string}
	 */
	public static function report( $now = null ): array {
		$now      = is_int( $now ) ? $now : time();
		$state    = self::state();
		$required = Settings::observe_kinds();
		$kinds    = array();
		$ready    = $state['since'] ? $state['since'] + ( Settings::observe_days() * DAY_IN_SECONDS ) : $now + ( Settings::observe_days() * DAY_IN_SECONDS );
		foreach ( array( 'front', 'admin', 'rest', 'cron', 'ajax', 'cli' ) as $kind ) {
			$seen = $state['kinds'][ $kind ] ?? null;
			if ( in_array( $kind, $required, true ) ) {
				$ready = max( $ready, ( $seen ? (int) $seen['first'] : $now ) + ( Settings::observe_days() * DAY_IN_SECONDS ) );
			}
			$kinds[] = array(
				'kind'         => $kind,
				'first'        => $seen ? gmdate( 'Y-m-d H:i:s', (int) $seen['first'] ) : '',
				'last'         => $seen ? gmdate( 'Y-m-d H:i:s', (int) $seen['last'] ) : '',
				'watched_days' => $seen ? (int) floor( ( $now - (int) $seen['first'] ) / DAY_IN_SECONDS ) : 0,
				'required'     => in_array( $kind, $required, true ),
			);
		}

		return array(
			'days'       => Settings::observe_days(),
			'since'      => $state['since'] ? gmdate( 'Y-m-d H:i:s', $state['since'] ) : '',
			'kinds'      => $kinds,
			'post_types' => count( $state['post_types'] ),
			'taxonomies' => count( $state['taxonomies'] ),
			'ready'      => $ready > $now ? gmdate( 'Y-m-d H:i:s', $ready ) : '',
		);
	}
}
