<?php
/**
 * Who owns an item, how sure that is, and why.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Combines four sources into an owner, a confidence level and the evidence:
 *
 * 1. Certain: the first-seen ledger recorded the plugin creating it.
 * 2. Likely: the name (or its prefix) is in a plugin's code signature, from
 *    the ledger or the bundled dataset.
 * 3. Guess: the name starts like a known plugin slug.
 * 4. Unknown: nothing points anywhere.
 *
 * An item that any installed plugin, must-use plugin or theme may own is in
 * use and never a leftover.
 */
class Attribution {

	public const RANK = array(
		'unknown' => 0,
		'guess'   => 1,
		'likely'  => 2,
		'certain' => 3,
	);

	/**
	 * Words that make poor guess prefixes.
	 */
	private const WEAK_WORDS = array( 'wp', 'the', 'and', 'for', 'by', 'of', 'to', 'plugin', 'lite', 'pro', 'free', 'simple', 'easy', 'custom', 'advanced', 'all', 'my', 'super', 'ultimate', 'best', 'smart', 'better', 'really', 'classic', 'social', 'contact', 'user', 'users', 'post', 'posts', 'page', 'pages', 'site', 'block', 'blocks', 'google', 'admin', 'image', 'images', 'media' );

	/**
	 * The ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * The dataset.
	 *
	 * @var Dataset
	 */
	private Dataset $dataset;

	/**
	 * Cached online lookups.
	 *
	 * @var Lookup
	 */
	private Lookup $lookup;

	/**
	 * Names of plugins found by online lookups, by slug.
	 *
	 * @var array<string, string>
	 */
	private array $lookup_names = array();

	/**
	 * Lookup tables, built once.
	 *
	 * @var array|null
	 */
	private ?array $index = null;

	/**
	 * Installed owners, built once.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $installed = null;

	/**
	 * Sets up attribution.
	 *
	 * @param Ledger      $ledger  Ledger.
	 * @param Dataset     $dataset Dataset.
	 * @param Lookup|null $lookup  Cached online lookups. Default: the stored ones.
	 */
	public function __construct( Ledger $ledger, Dataset $dataset, ?Lookup $lookup = null ) {
		$this->ledger  = $ledger;
		$this->dataset = $dataset;
		$this->lookup  = $lookup ?? new Lookup();
	}

	/**
	 * Forgets the lookup tables, after the ledger or the installed plugins changed.
	 */
	public function flush(): void {
		$this->index     = null;
		$this->installed = null;
		$this->ledger->flush();
	}

	/**
	 * The signature key for an item type.
	 *
	 * @param string $type Item type.
	 * @return string
	 */
	public static function key_for( string $type ): string {
		$keys = array(
			'table'       => 't',
			'option'      => 'o',
			'sitemeta'    => 'o',
			'cron'        => 'c',
			'postmeta'    => 'm',
			'usermeta'    => 'm',
			'termmeta'    => 'm',
			'commentmeta' => 'm',
			'role'        => 'r',
			'cap'         => 'r',
			'folder'      => 'u',
		);

		return $keys[ $type ] ?? 'o';
	}

	/**
	 * The name signatures use: tables without the site prefix.
	 *
	 * @param string $type Item type.
	 * @param string $name Name.
	 * @return string
	 */
	public static function bare_name( string $type, string $name ): string {
		global $wpdb;

		if ( 'table' !== $type ) {
			return $name;
		}
		foreach ( array( (string) $wpdb->prefix, (string) $wpdb->base_prefix ) as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) {
				return substr( $name, strlen( $prefix ) );
			}
		}

		return $name;
	}

	/**
	 * Owner, confidence and evidence of one item.
	 *
	 * @param string $type Item type.
	 * @param string $name Name.
	 * @return array{owner: string, owner_name: string, confidence: string, evidence: array<int, array{code: string, text: string}>, in_use: bool, in_use_by: string}
	 */
	public function attribute( string $type, string $name ): array {
		$index      = $this->index();
		$installed  = $this->installed();
		$candidates = array();
		$bare       = self::bare_name( $type, $name );
		$key        = self::key_for( $type );

		if ( isset( $index['seen'][ $type ][ $name ] ) ) {
			$seen         = $index['seen'][ $type ][ $name ];
			$candidates[] = array(
				'owner'      => $seen['owner'],
				'confidence' => 'certain',
				'weight'     => 9,
				'code'       => $seen['evidence'],
				'detail'     => substr( $seen['first_seen'], 0, 10 ),
			);
		}

		foreach ( $index['names'][ $key ][ $bare ] ?? array() as $hit ) {
			$candidates[] = array(
				'owner'      => $hit[0],
				'confidence' => 'likely',
				'weight'     => 'ledger' === $hit[1] ? 8 : 6,
				'code'       => 'ledger' === $hit[1] ? 'signature' : 'dataset',
				'detail'     => '',
			);
		}
		foreach ( $index['names']['n'][ $bare ] ?? array() as $hit ) {
			$candidates[] = array(
				'owner'      => $hit[0],
				'confidence' => 'likely',
				'weight'     => 'ledger' === $hit[1] ? 7 : 5,
				'code'       => 'uninstall',
				'detail'     => '',
			);
		}

		$plain = (string) preg_replace( '/^_(site_)?transient_/', '', $bare );
		foreach ( $index['prefixes'] as $hit ) {
			if ( 0 === strpos( $bare, $hit[0] ) || 0 === strpos( $plain, $hit[0] ) ) {
				$candidates[] = array(
					'owner'      => $hit[1],
					'confidence' => 'likely',
					'weight'     => 'ledger' === $hit[2] ? 4 : 3,
					'code'       => 'ledger' === $hit[2] ? 'signature-prefix' : 'dataset-prefix',
					'detail'     => $hit[0],
				);
			}
		}

		$lower = strtolower( $plain );
		foreach ( $index['guesses'] as $hit ) {
			if ( 0 === strpos( $lower, $hit[0] ) ) {
				$candidates[] = array(
					'owner'      => $hit[1],
					'confidence' => 'guess',
					'weight'     => 1,
					'code'       => 'guess',
					'detail'     => $hit[0],
				);
			}
		}

		// An online lookup the site owner ran: the plugins whose code names this item.
		$found = $this->lookup->cached( $type, $name );
		if ( $found && ! empty( $found['candidates'] ) && isset( self::RANK[ (string) ( $found['confidence'] ?? '' ) ] ) ) {
			$names = array();
			foreach ( (array) $found['candidates'] as $plugin ) {
				$names[]                               = (string) $plugin['name'];
				$this->lookup_names[ $plugin['slug'] ] = (string) $plugin['name'];
			}
			$candidates[] = array(
				'owner'      => (string) $found['candidates'][0]['slug'],
				'confidence' => (string) $found['confidence'],
				'weight'     => 1.2,
				'code'       => 'lookup',
				'detail'     => implode( ', ', $names ),
			);
		}

		$in_use_by = '';
		foreach ( $candidates as $candidate ) {
			if ( isset( $installed[ $candidate['owner'] ] ) ) {
				$in_use_by = $candidate['owner'];
				break;
			}
		}
		if ( '' === $in_use_by && 0 === strpos( (string) ( $candidates[0]['owner'] ?? '' ), 'mu-plugin:' ) ) {
			$in_use_by = (string) $candidates[0]['owner'];
		}

		// Ties go to the owner whose slug the name starts with (redirection_logs: Redirection).
		foreach ( $candidates as $index => $candidate ) {
			foreach ( self::guess_prefixes( (string) $candidate['owner'] ) as $prefix ) {
				if ( 'guess' !== $candidate['confidence'] && 0 === strpos( $lower, $prefix ) ) {
					$candidates[ $index ]['weight'] += 0.5;
					break;
				}
			}
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				return array( self::RANK[ $b['confidence'] ], $b['weight'] ) <=> array( self::RANK[ $a['confidence'] ], $a['weight'] );
			}
		);

		$best     = $candidates[0] ?? null;
		$owner    = $best ? (string) $best['owner'] : '';
		$evidence = array();
		$listed   = array();
		foreach ( $candidates as $candidate ) {
			$signature = $candidate['code'] . '|' . $candidate['detail'];
			// A guess says nothing once something better names the same owner.
			if ( $candidate['owner'] !== $owner || isset( $listed[ $signature ] ) || ( 'guess' === $candidate['confidence'] && 'guess' !== (string) ( $best['confidence'] ?? '' ) ) ) {
				continue;
			}
			$listed[ $signature ] = true;
			$evidence[]           = array(
				'code' => (string) $candidate['code'],
				'text' => $this->evidence_text( $candidate ),
			);
		}

		$result = array(
			'owner'      => $owner,
			'owner_name' => $this->owner_name( $owner ),
			'confidence' => $best ? (string) $best['confidence'] : 'unknown',
			'evidence'   => $evidence,
			'in_use'     => '' !== $in_use_by,
			'in_use_by'  => $in_use_by,
		);

		/**
		 * Filters the owner, confidence and evidence of an item.
		 *
		 * @param array  $result owner, owner_name, confidence (certain, likely, guess, unknown), evidence, in_use, in_use_by.
		 * @param string $type   Item type.
		 * @param string $name   Item name.
		 */
		$filtered = (array) apply_filters( 'axle_leftovers_owner', $result, $type, $name );
		if ( isset( $filtered['owner'] ) && is_string( $filtered['owner'] ) ) {
			$result['owner']      = $filtered['owner'];
			$result['owner_name'] = isset( $filtered['owner_name'] ) && is_string( $filtered['owner_name'] ) ? $filtered['owner_name'] : $this->owner_name( $filtered['owner'] );
		}
		if ( isset( $filtered['confidence'] ) && isset( self::RANK[ $filtered['confidence'] ] ) ) {
			$result['confidence'] = (string) $filtered['confidence'];
		}
		if ( isset( $filtered['evidence'] ) && is_array( $filtered['evidence'] ) ) {
			$result['evidence'] = array_values( $filtered['evidence'] );
		}
		if ( isset( $filtered['in_use'] ) ) {
			$result['in_use']    = (bool) $filtered['in_use'];
			$result['in_use_by'] = (string) ( $filtered['in_use_by'] ?? $result['in_use_by'] );
		}
		if ( '' === $result['owner'] ) {
			$result['confidence'] = 'unknown';
		}

		return $result;
	}

	/**
	 * A readable name for an owner slug.
	 *
	 * @param string $owner Owner.
	 * @return string
	 */
	public function owner_name( string $owner ): string {
		if ( '' === $owner ) {
			return '';
		}
		if ( 0 === strpos( $owner, 'theme:' ) ) {
			/* translators: %s: theme folder. */
			return sprintf( __( 'Theme %s', 'axle-leftovers' ), substr( $owner, 6 ) );
		}
		if ( 0 === strpos( $owner, 'mu-plugin:' ) ) {
			/* translators: %s: must-use plugin file. */
			return sprintf( __( 'Must-use plugin %s', 'axle-leftovers' ), substr( $owner, 10 ) );
		}
		$index = $this->index();
		if ( isset( $index['ledger'][ $owner ] ) && '' !== $index['ledger'][ $owner ]['name'] ) {
			return (string) $index['ledger'][ $owner ]['name'];
		}
		$name = $this->dataset->name( $owner );
		if ( '' === $name && isset( $this->lookup_names[ $owner ] ) ) {
			$name = $this->lookup_names[ $owner ];
		}

		return '' !== $name ? $name : $owner;
	}

	/**
	 * Installed owners: plugins (active or not), must-use plugins and themes.
	 *
	 * @return array<string, string> Owner => kind.
	 */
	public function installed(): array {
		if ( null !== $this->installed ) {
			return $this->installed;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			$out[ Ledger::slug_for( (string) $file ) ] = 'plugin';
		}
		foreach ( array_keys( get_mu_plugins() ) as $file ) {
			$out[ 'mu-plugin:' . Ledger::slug_for( (string) $file ) ] = 'mu-plugin';
		}
		foreach ( array_keys( wp_get_themes() ) as $theme ) {
			$out[ 'theme:' . $theme ] = 'theme';
		}
		$out['axle-leftovers'] = 'plugin';

		$this->installed = $out;

		return $out;
	}

	/**
	 * Prefixes a plugin slug suggests for a guess.
	 *
	 * @param string $slug Slug.
	 * @return string[]
	 */
	public static function guess_prefixes( string $slug ): array {
		$slug  = strtolower( $slug );
		$out   = array( str_replace( '-', '_', $slug ) . '_', $slug . '-', $slug . '_' );
		$words = array_values(
			array_filter(
				(array) preg_split( '/[-_]+/', $slug ),
				static function ( $word ): bool {
					return '' !== (string) $word;
				}
			)
		);
		$kept  = array_values( array_diff( $words, self::WEAK_WORDS ) );

		if ( count( $kept ) >= 2 ) {
			$initials = '';
			foreach ( $kept as $word ) {
				$initials .= $word[0];
			}
			$out[] = $initials . '_';
		}
		if ( isset( $kept[0] ) && strlen( $kept[0] ) >= 5 && $kept[0] !== $slug ) {
			$out[] = $kept[0] . '_';
		}

		return array_values(
			array_unique(
				array_filter(
					$out,
					static function ( string $prefix ): bool {
						return strlen( $prefix ) >= 3;
					}
				)
			)
		);
	}

	/**
	 * Builds the lookup tables from the ledger and the dataset.
	 *
	 * @return array{seen: array, ledger: array, names: array, prefixes: array, guesses: array}
	 */
	private function index(): array {
		if ( null !== $this->index ) {
			return $this->index;
		}

		$index = array(
			'seen'     => $this->ledger->seen_all(),
			'ledger'   => array(),
			'names'    => array(),
			'prefixes' => array(),
			'guesses'  => array(),
		);

		$sources = array();
		foreach ( $this->ledger->all() as $slug => $entry ) {
			$index['ledger'][ $slug ] = array(
				'name'    => (string) $entry['name'],
				'removed' => $entry['removed_gmt'],
			);
			$sources[]                = array( $slug, 'ledger', is_array( $entry['signature'] ) ? $entry['signature'] : array() );
		}
		foreach ( $this->dataset->plugins() as $slug => $signature ) {
			$sources[] = array( $slug, 'dataset', $signature );
		}

		$guessed = array();
		foreach ( $sources as list( $slug, $source, $signature ) ) {
			foreach ( Signature::KEYS as $key ) {
				if ( 'p' === $key ) {
					continue;
				}
				foreach ( (array) ( $signature[ $key ] ?? array() ) as $name ) {
					$index['names'][ $key ][ (string) $name ][] = array( $slug, $source );
				}
			}
			foreach ( (array) ( $signature['p'] ?? array() ) as $prefix ) {
				if ( Signature::useful_prefix( (string) $prefix ) ) {
					$index['prefixes'][] = array( (string) $prefix, $slug, $source );
				}
			}
			if ( ! isset( $guessed[ $slug ] ) ) {
				$guessed[ $slug ] = true;
				foreach ( self::guess_prefixes( $slug ) as $prefix ) {
					$index['guesses'][] = array( $prefix, $slug );
				}
			}
		}

		$this->index = $index;

		return $index;
	}

	/**
	 * Text for one piece of evidence.
	 *
	 * @param array $candidate Candidate.
	 * @return string
	 */
	private function evidence_text( array $candidate ): string {
		$name   = $this->owner_name( (string) $candidate['owner'] );
		$detail = (string) $candidate['detail'];

		switch ( $candidate['code'] ) {
			case 'activation':
				/* translators: 1: plugin name, 2: date. */
				return sprintf( __( 'Created when %1$s was activated on %2$s', 'axle-leftovers' ), $name, $detail );
			case 'runtime':
				/* translators: 1: plugin name, 2: date. */
				return sprintf( __( 'Seen being created by %1$s on %2$s', 'axle-leftovers' ), $name, $detail );
			case 'uninstall-diff':
				/* translators: 1: plugin name, 2: date. */
				return sprintf( __( 'Removed by %1$s\'s uninstall.php on %2$s, and created again since', 'axle-leftovers' ), $name, $detail );
			case 'signature':
				/* translators: %s: plugin name. */
				return sprintf( __( 'Named in %s\'s code', 'axle-leftovers' ), $name );
			case 'uninstall':
				/* translators: %s: plugin name. */
				return sprintf( __( 'Named in %s\'s uninstall.php', 'axle-leftovers' ), $name );
			case 'signature-prefix':
				/* translators: 1: prefix, 2: plugin name. */
				return sprintf( __( 'Starts with %1$s, a prefix in %2$s\'s code', 'axle-leftovers' ), $detail, $name );
			case 'dataset':
				/* translators: %s: plugin name. */
				return sprintf( __( 'Dataset match: named in %s on WordPress.org', 'axle-leftovers' ), $name );
			case 'dataset-prefix':
				/* translators: 1: prefix, 2: plugin name. */
				return sprintf( __( 'Dataset match: starts with %1$s, a prefix in %2$s on WordPress.org', 'axle-leftovers' ), $detail, $name );
			case 'lookup':
				if ( false === strpos( $detail, ', ' ) ) {
					/* translators: %s: plugin name. */
					return sprintf( __( 'Online lookup: only %s on WordPress.org names it in its code', 'axle-leftovers' ), $detail );
				}
				/* translators: %s: plugin names, most installed first. */
				return sprintf( __( 'Online lookup: named in the code of %s on WordPress.org', 'axle-leftovers' ), $detail );
			default:
				/* translators: 1: prefix, 2: plugin name. */
				return sprintf( __( 'Prefix guess: starts with %1$s, like %2$s', 'axle-leftovers' ), $detail, $name );
		}
	}
}
