<?php
/**
 * The background scan.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Finds leftovers in steps: the plugins list and signatures, then each item
 * type, reading big tables (options, meta) in id ranges. Each step runs within
 * a time budget, from WP-Cron, the screen or WP-CLI, and continues where the
 * last one stopped. The result is cached in `axle_leftovers_scan` (not
 * autoloaded) and refreshed daily and after any plugin is deleted.
 */
class Scanner {

	public const OPTION = 'axle_leftovers_scan';

	public const STEPS = array( 'plugins', 'table', 'option', 'cron', 'postmeta', 'usermeta', 'termmeta', 'commentmeta', 'folder', 'role', 'cap', 'sitemeta', 'finish' );

	/**
	 * Most items listed per type, so a site with thousands of stray transients stays usable.
	 */
	public const MAX_PER_TYPE = 2000;

	/**
	 * Signatures read per step.
	 */
	private const SIGNATURES_PER_STEP = 5;

	/**
	 * The ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * The inventory.
	 *
	 * @var Inventory
	 */
	private Inventory $inventory;

	/**
	 * Attribution.
	 *
	 * @var Attribution
	 */
	private Attribution $attribution;

	/**
	 * The reference check.
	 *
	 * @var References
	 */
	private References $references;

	/**
	 * The recorder, for signatures.
	 *
	 * @var Recorder
	 */
	private Recorder $recorder;

	/**
	 * Sets up the scanner.
	 *
	 * @param Ledger      $ledger      Ledger.
	 * @param Inventory   $inventory   Inventory.
	 * @param Attribution $attribution Attribution.
	 * @param References  $references  Reference check.
	 * @param Recorder    $recorder    Recorder.
	 */
	public function __construct( Ledger $ledger, Inventory $inventory, Attribution $attribution, References $references, Recorder $recorder ) {
		$this->ledger      = $ledger;
		$this->inventory   = $inventory;
		$this->attribution = $attribution;
		$this->references  = $references;
		$this->recorder    = $recorder;
	}

	/**
	 * The attribution engine.
	 *
	 * @return Attribution
	 */
	public function attribution(): Attribution {
		return $this->attribution;
	}

	/**
	 * The reference check.
	 *
	 * @return References
	 */
	public function references(): References {
		return $this->references;
	}

	/**
	 * The stored scan state.
	 *
	 * @return array
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Saves the scan state.
	 *
	 * @param array $state State.
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Marks the results out of date, and asks WP-Cron to refresh them.
	 */
	public static function mark_stale(): void {
		$state          = self::state();
		$state['stale'] = true;
		self::save( $state );

		if ( ! wp_next_scheduled( Schema::CRON_STEP ) ) {
			wp_schedule_single_event( time(), Schema::CRON_STEP );
		}
	}

	/**
	 * Whether a scan is in progress.
	 *
	 * @return bool
	 */
	public function is_running(): bool {
		return 'running' === ( self::state()['status'] ?? '' );
	}

	/**
	 * Whether the results need a refresh.
	 *
	 * @return bool
	 */
	public function needs_scan(): bool {
		$state = self::state();

		return empty( $state['finished_gmt'] ) || ! empty( $state['stale'] );
	}

	/**
	 * Starts a new scan, dropping one in progress.
	 *
	 * @param bool $rescan_signatures Read every installed plugin's code again.
	 */
	public function start( bool $rescan_signatures = false ): void {
		$old   = self::state();
		$state = array(
			'status'            => 'running',
			'step'              => 0,
			'cursor'            => 0,
			'raw'               => array(),
			'started_gmt'       => current_time( 'mysql', true ),
			'finished_gmt'      => $old['finished_gmt'] ?? '',
			'items'             => $old['items'] ?? array(),
			'stale'             => false,
			'rescan_signatures' => $rescan_signatures,
		);
		self::save( $state );
		$this->attribution->flush();

		/**
		 * Fires when a scan for leftovers starts.
		 *
		 * @param bool $rescan_signatures Whether every installed plugin's code is read again.
		 */
		do_action( 'axle_leftovers_scan_started', $rescan_signatures );

		if ( ! wp_next_scheduled( Schema::CRON_STEP ) ) {
			wp_schedule_single_event( time() + 30, Schema::CRON_STEP );
		}
	}

	/**
	 * Runs scan steps until the budget is spent or the scan is done.
	 *
	 * @param float $budget Seconds.
	 * @return bool Whether the scan is finished.
	 */
	public function step( float $budget = 20.0 ): bool {
		$state = self::state();
		if ( 'running' !== ( $state['status'] ?? '' ) ) {
			return true;
		}

		$deadline = microtime( true ) + $budget;
		$types    = Settings::item_types();
		do {
			$step = self::STEPS[ (int) $state['step'] ] ?? 'finish';
			if ( 'plugins' === $step ) {
				$done = $this->scan_plugins( $state );
			} elseif ( 'finish' === $step ) {
				$this->finish( $state );
				return true;
			} elseif ( ! isset( $types[ $step ] ) ) {
				$done = true;
			} else {
				$done = $this->scan_type( $step, $state );
			}

			if ( $done ) {
				$state['step']   = (int) $state['step'] + 1;
				$state['cursor'] = 0;
			}
			self::save( $state );
		} while ( microtime( true ) < $deadline );

		return false;
	}

	/**
	 * Runs a whole scan now (WP-CLI).
	 *
	 * @param bool $rescan_signatures Read every installed plugin's code again.
	 */
	public function run_all( bool $rescan_signatures = false ): void {
		$this->start( $rescan_signatures );
		do {
			$done = $this->step( 30.0 );
		} while ( ! $done );
		wp_clear_scheduled_hook( Schema::CRON_STEP );
	}

	/**
	 * Brings the results up to date within a budget, for the screen.
	 *
	 * @param float $budget Seconds.
	 * @return bool Whether the results are current.
	 */
	public function ensure_fresh( float $budget = 8.0 ): bool {
		$this->detect_removed_outside();
		if ( ! $this->is_running() ) {
			if ( ! $this->needs_scan() ) {
				return true;
			}
			$this->start();
		}

		return $this->step( $budget );
	}

	/**
	 * WP-Cron: one step, then another event if needed.
	 */
	public function cron_step(): void {
		if ( ! $this->is_running() ) {
			if ( ! $this->needs_scan() ) {
				return;
			}
			$this->start();
		}
		if ( ! $this->step( 20.0 ) ) {
			wp_schedule_single_event( time() + 5, Schema::CRON_STEP );
		}
	}

	/**
	 * Records plugins gone from the folder without WordPress deleting them.
	 *
	 * @return string[] Slugs found removed.
	 */
	public function detect_removed_outside(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_cache_delete( 'plugins', 'plugins' );

		$installed = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			$installed[ Ledger::slug_for( (string) $file ) ] = true;
		}

		$removed = array();
		foreach ( $this->ledger->all() as $slug => $entry ) {
			if ( null === $entry['removed_gmt'] && ! isset( $installed[ $slug ] ) && 'axle-leftovers' !== $slug ) {
				$this->ledger->mark_removed( $slug, 'outside' );
				$removed[] = $slug;
			}
		}
		if ( $removed ) {
			$this->attribution->flush();
			self::mark_stale();
		}

		return $removed;
	}

	/**
	 * The listed leftovers.
	 *
	 * @param array $filters plugin (owner slug, or '' for unknown), type, confidence, scope.
	 * @return array<int, array>
	 */
	public function items( array $filters = array() ): array {
		$items = (array) ( self::state()['items'] ?? array() );

		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $filters ): bool {
					if ( isset( $filters['plugin'] ) && $item['owner'] !== $filters['plugin'] ) {
						return false;
					}
					if ( ! empty( $filters['type'] ) && $item['type'] !== $filters['type'] ) {
						return false;
					}
					if ( ! empty( $filters['confidence'] ) && $item['confidence'] !== $filters['confidence'] ) {
						return false;
					}
					if ( ! empty( $filters['scope'] ) && $item['scope'] !== $filters['scope'] ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	/**
	 * One listed item.
	 *
	 * @param string $type Type.
	 * @param string $name Name.
	 * @return array|null
	 */
	public function item( string $type, string $name ): ?array {
		foreach ( (array) ( self::state()['items'] ?? array() ) as $item ) {
			if ( $item['type'] === $type && $item['name'] === $name ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Leftovers grouped by owner, owners with the most autoloaded bytes first.
	 *
	 * @param string $scope site or network.
	 * @return array<string, array{owner: string, owner_name: string, confidence: string, items: array, counts: array<string, int>, bytes: int, autoload_bytes: int, ledger: array|null}>
	 */
	public function by_plugin( string $scope = 'site' ): array {
		$ledger = $this->ledger->all();
		$groups = array();
		foreach ( $this->items( array( 'scope' => $scope ) ) as $item ) {
			$owner = (string) $item['owner'];
			if ( '' === $owner ) {
				continue;
			}
			$group = $groups[ $owner ] ?? array(
				'owner'          => $owner,
				'owner_name'     => (string) $item['owner_name'],
				'confidence'     => (string) $item['confidence'],
				'items'          => array(),
				'counts'         => array(),
				'bytes'          => 0,
				'autoload_bytes' => 0,
				'ledger'         => $ledger[ $owner ] ?? null,
			);

			$type                     = (string) $item['type'];
			$counts                   = $group['counts'];
			$counts[ $type ]          = ( $counts[ $type ] ?? 0 ) + 1;
			$group['counts']          = $counts;
			$group['items'][]         = $item;
			$group['bytes']          += (int) $item['bytes'];
			$group['autoload_bytes'] += empty( $item['autoload'] ) ? 0 : (int) $item['bytes'];
			if ( Attribution::RANK[ $item['confidence'] ] > Attribution::RANK[ $group['confidence'] ] ) {
				$group['confidence'] = (string) $item['confidence'];
			}
			$groups[ $owner ] = $group;
		}

		uasort(
			$groups,
			static function ( array $a, array $b ): int {
				return array( $b['autoload_bytes'], $b['bytes'], $a['owner_name'] ) <=> array( $a['autoload_bytes'], $a['bytes'], $b['owner_name'] );
			}
		);

		return $groups;
	}

	/**
	 * Drops items from the cached results (after they were trashed).
	 *
	 * @param array<int, array{type: string, name: string}> $items Items.
	 */
	public function forget( array $items ): void {
		$state = self::state();
		$drop  = array();
		foreach ( $items as $item ) {
			$drop[ $item['type'] . ':' . $item['name'] ] = true;
		}
		$state['items'] = array_values(
			array_filter(
				(array) ( $state['items'] ?? array() ),
				static function ( $item ) use ( $drop ): bool {
					return ! isset( $drop[ $item['type'] . ':' . $item['name'] ] );
				}
			)
		);
		self::save( $state );
	}

	/**
	 * Plugins step: ledger entries for every installed plugin, plugins removed
	 * outside WordPress, then a few signatures per call.
	 *
	 * @param array $state State, updated.
	 * @return bool Done.
	 */
	private function scan_plugins( array &$state ): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		if ( 0 === (int) $state['cursor'] ) {
			$this->detect_removed_outside();
		}

		$files = array_keys( $plugins );
		sort( $files );
		$cursor = (int) $state['cursor'];
		$read   = 0;
		$self   = plugin_basename( PLUGIN_FILE );
		$total  = count( $files );
		for ( ; $cursor < $total && $read < self::SIGNATURES_PER_STEP; $cursor++ ) {
			$file = (string) $files[ $cursor ];
			if ( $file === $self ) {
				continue;
			}
			$entry = $this->ledger->get( Ledger::slug_for( $file ) );
			$stale = ! $entry || null === $entry['signature'] || $entry['signature_version'] !== (string) $plugins[ $file ]['Version'] || null !== $entry['removed_gmt'];
			if ( $stale || ! empty( $state['rescan_signatures'] ) ) {
				$this->recorder->ensure_signature( $file, true );
				++$read;
			}
		}
		$state['cursor'] = $cursor;

		if ( $cursor >= $total ) {
			$this->attribution->flush();
			return true;
		}

		return false;
	}

	/**
	 * Collects the candidates of one type into $state['raw'].
	 *
	 * @param string $type  Item type.
	 * @param array  $state State, updated.
	 * @return bool Done.
	 */
	private function scan_type( string $type, array &$state ): bool {
		global $wpdb;

		$raw     =& $state['raw'];
		$network = is_multisite();

		switch ( $type ) {
			case 'table':
				foreach ( $this->inventory->tables() as $table ) {
					if ( ! Core::is_protected( 'table', $table ) ) {
						$raw['table'][ $table ] = array();
					}
				}
				return true;

			case 'option':
				$batch = Settings::scan_batch_size();
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A batched read of names and sizes; there is no API for option sizes.
				$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT option_id, option_name, LENGTH(option_value) AS bytes, autoload FROM %i WHERE option_id > %d ORDER BY option_id LIMIT %d', $wpdb->options, (int) $state['cursor'], $batch ), ARRAY_A );
				$auto = Inventory::autoload_values();
				foreach ( $rows as $row ) {
					$state['cursor'] = (int) $row['option_id'];
					$name            = (string) $row['option_name'];
					if ( preg_match( '/^_(site_)?transient_timeout_/', $name ) || Core::is_protected( 'option', $name ) ) {
						continue;
					}
					$raw['option'][ $name ] = array(
						'rows'     => 1,
						'bytes'    => (int) $row['bytes'],
						'autoload' => in_array( (string) $row['autoload'], $auto, true ),
					);
				}
				return count( $rows ) < $batch;

			case 'cron':
				foreach ( $this->inventory->cron_events() as $hook => $events ) {
					if ( ! Core::is_protected( 'cron', $hook ) && ! has_action( $hook ) ) {
						$raw['cron'][ $hook ] = array( 'rows' => count( $events ) );
					}
				}
				return true;

			case 'postmeta':
			case 'usermeta':
			case 'termmeta':
			case 'commentmeta':
				if ( 'usermeta' === $type && $network && ! is_main_site() ) {
					// User meta is shared by the network; the main site lists it.
					return true;
				}
				return $this->scan_meta( $type, $state );

			case 'folder':
				$base = $this->inventory->uploads_base();
				foreach ( $this->inventory->folders() as $folder ) {
					if ( ! Core::is_protected( 'folder', $folder ) ) {
						$size                     = Inventory::folder_size( $base . '/' . $folder );
						$raw['folder'][ $folder ] = array(
							'rows'  => $size['files'],
							'bytes' => $size['bytes'],
						);
					}
				}
				return true;

			case 'role':
				foreach ( $this->inventory->roles() as $role => $data ) {
					if ( ! Core::is_protected( 'role', (string) $role ) ) {
						$raw['role'][ (string) $role ] = array( 'rows' => count( $this->inventory->users_with_role( (string) $role, 1000 ) ) );
					}
				}
				return true;

			case 'cap':
				$roles = $this->inventory->roles();
				foreach ( $this->inventory->caps() as $cap => $holders ) {
					if ( Core::is_protected( 'cap', $cap ) || isset( $roles[ $cap ] ) ) {
						continue;
					}
					$raw['cap'][ $cap ] = array( 'rows' => count( $holders ) );
				}
				return true;

			case 'sitemeta':
				if ( ! $network || ! is_main_site() ) {
					return true;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Names and sizes of network options; there is no API for sizes.
				$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, LENGTH(meta_value) AS bytes FROM %i WHERE site_id = %d', $wpdb->sitemeta, get_current_network_id() ), ARRAY_A );
				foreach ( $rows as $row ) {
					$name = (string) $row['meta_key'];
					if ( ! preg_match( '/^_site_transient_timeout_/', $name ) && ! Core::is_protected( 'sitemeta', $name ) ) {
						$raw['sitemeta'][ $name ] = array(
							'rows'  => 1,
							'bytes' => (int) $row['bytes'],
						);
					}
				}
				return true;
		}

		return true;
	}

	/**
	 * Meta keys with row counts and sizes, one id range per call.
	 *
	 * @param string $type  Meta item type.
	 * @param array  $state State, updated.
	 * @return bool Done.
	 */
	private function scan_meta( string $type, array &$state ): bool {
		global $wpdb;

		$meta  = Trash::meta_table( $type );
		$batch = Settings::scan_batch_size();
		$from  = (int) $state['cursor'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The end of the id range, read once per step.
		$max = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(%i) FROM %i', $meta['id'], $meta['table'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GROUP BY meta_key over one id range keeps each query small on big sites.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, COUNT(*) AS c, SUM(LENGTH(meta_value)) AS b FROM %i WHERE %i > %d AND %i <= %d GROUP BY meta_key', $meta['table'], $meta['id'], $from, $meta['id'], $from + $batch ), ARRAY_A );

		foreach ( $rows as $row ) {
			$key = (string) $row['meta_key'];
			if ( '' === $key || Core::is_protected( $type, $key ) ) {
				continue;
			}
			if ( ! isset( $state['raw'][ $type ][ $key ] ) ) {
				$state['raw'][ $type ][ $key ] = array(
					'rows'  => 0,
					'bytes' => 0,
				);
			}
			$state['raw'][ $type ][ $key ]['rows']  += (int) $row['c'];
			$state['raw'][ $type ][ $key ]['bytes'] += (int) $row['b'];
		}

		$state['cursor'] = $from + $batch;

		return $state['cursor'] >= $max;
	}

	/**
	 * Attribution, the in-use filter, sizes and the reference check; then stores the items.
	 *
	 * @param array $state State.
	 */
	private function finish( array $state ): void {
		$this->attribution->flush();

		$raw    = (array) $state['raw'];
		$items  = array();
		$tables = isset( $raw['table'] ) ? $this->inventory->table_stats( array_keys( $raw['table'] ) ) : array();
		$multi  = is_multisite();

		foreach ( $raw as $type => $names ) {
			$listed = 0;
			ksort( $names );
			foreach ( $names as $name => $stats ) {
				$name = (string) $name;
				$who  = $this->attribution->attribute( (string) $type, $name );
				if ( $who['in_use'] ) {
					continue;
				}
				if ( ++$listed > self::MAX_PER_TYPE ) {
					break;
				}
				if ( 'table' === $type ) {
					$stats = $tables[ $name ] ?? array(
						'rows'  => 0,
						'bytes' => 0,
					);
				}
				$scope   = 'site';
				$network = 'sitemeta' === $type || ( $multi && ( 'usermeta' === $type || ( 'table' === $type && is_main_site() ) ) );
				if ( $network ) {
					$scope = 'network';
				}
				$items[] = array(
					'id'         => $type . ':' . $name,
					'type'       => (string) $type,
					'name'       => $name,
					'scope'      => $scope,
					'owner'      => $who['owner'],
					'owner_name' => $who['owner_name'],
					'confidence' => $who['confidence'],
					'evidence'   => $who['evidence'],
					'rows'       => (int) ( $stats['rows'] ?? 0 ),
					'bytes'      => (int) ( $stats['bytes'] ?? 0 ),
					'autoload'   => ! empty( $stats['autoload'] ),
					'refs'       => array(),
				);
			}
		}

		$refs = $this->references->check( $items );
		foreach ( $items as &$item ) {
			$item['refs'] = $refs[ $item['id'] ] ?? array();
		}
		unset( $item );

		$was_stale = ! empty( self::state()['stale'] );
		self::save(
			array(
				'status'       => 'done',
				'step'         => 0,
				'cursor'       => 0,
				'raw'          => array(),
				'started_gmt'  => $state['started_gmt'] ?? '',
				'finished_gmt' => current_time( 'mysql', true ),
				'items'        => $items,
				// A plugin deleted while this scan ran needs another one.
				'stale'        => $was_stale,
			)
		);

		/**
		 * Fires when a scan for leftovers finishes.
		 *
		 * @param array[] $items The leftovers found: id, type, name, scope, owner, confidence, evidence, rows, bytes, autoload, refs.
		 */
		do_action( 'axle_leftovers_scan_finished', $items );
	}
}
