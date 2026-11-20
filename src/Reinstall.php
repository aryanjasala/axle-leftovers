<?php
/**
 * A reinstalled plugin gets its data back.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

use AryanX\Axle\Leftovers\Admin\Page;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * When a plugin whose tables and options are in the Leftovers trash is activated
 * again, the data is offered back before activation runs. Activation creates empty
 * tables and options under the same names, and after that a restore is refused.
 *
 * - `activate_plugin` fires before the plugin's code and activation hook run. On the
 *   screen the activation stops at a preview where the person picks per item. In
 *   WP-CLI it stops with the commands to run. With the `restore` mode, or on AJAX and
 *   REST requests, what clashes with nothing is restored without a question.
 * - After the fact (the plugin is active already and wrote its own data) the same
 *   preview shows both sides of each clash and the person chooses per item. Nothing
 *   the plugin wrote is replaced without that choice, and what is replaced goes into
 *   the trash first.
 * - Every restore is logged and has Undo.
 */
final class Reinstall implements Service {

	public const OPTION = 'axle_leftovers_reinstall';

	/**
	 * The trash.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * The inventory.
	 *
	 * @var Inventory
	 */
	private Inventory $inventory;

	/**
	 * Sets up the service.
	 *
	 * @param Trash     $trash     Trash.
	 * @param Inventory $inventory Inventory.
	 */
	public function __construct( Trash $trash, Inventory $inventory ) {
		$this->trash     = $trash;
		$this->inventory = $inventory;
	}

	/**
	 * Adds the hooks. Priority 1: before the recorder and before the plugin's own code.
	 */
	public function register(): void {
		add_action( 'activate_plugin', array( $this, 'before_activation' ), 1, 2 );
	}

	/**
	 * Stops or prepares an activation when the trash holds the plugin's data.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Network activation.
	 */
	public function before_activation( $plugin, $network_wide = false ): void {
		$verdict = $this->intercept( (string) $plugin, (bool) $network_wide );
		if ( 'redirect' === $verdict['action'] ) {
			wp_safe_redirect( $verdict['url'] );
			exit;
		}
		if ( 'cli' === $verdict['action'] && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::error( $verdict['message'] );
		}
	}

	/**
	 * Decides what to do about an activation.
	 *
	 * @param string $plugin       Plugin file.
	 * @param bool   $network_wide Network activation.
	 * @return array{action: string, url: string, message: string} action is none, restored, redirect or cli.
	 */
	public function intercept( string $plugin, bool $network_wide = false ): array {
		$none = array(
			'action'  => 'none',
			'url'     => '',
			'message' => '',
		);
		$slug = Ledger::slug_for( $plugin );
		if ( 'axle-leftovers' === $slug ) {
			return $none;
		}
		$mode = Settings::reinstall_mode( $slug );
		if ( 'off' === $mode ) {
			return $none;
		}
		$rows = $this->rows_for( $slug );
		if ( ! $rows ) {
			return $none;
		}
		$decision = $this->decision( $slug );
		if ( '' !== $decision ) {
			return $none;
		}

		/**
		 * Fires when a plugin is about to be activated and the trash holds data it left.
		 *
		 * @param string $slug    Plugin slug.
		 * @param array  $counts  Item type => number of trashed items.
		 * @param string $context screen, cli, rest or other.
		 */
		do_action( 'axle_leftovers_reinstall_detected', $slug, self::counts( $rows ), $this->context() );

		$context = $this->context();
		if ( 'restore' === $mode || ( 'screen' !== $context && 'cli' !== $context ) || ( 'screen' === $context && $network_wide ) ) {
			$result = $this->restore( $slug, $this->defaults( $this->plan( $slug ) ), 'activation' );
			return is_wp_error( $result ) ? $none : array_merge( $none, array( 'action' => 'restored' ) );
		}
		if ( 'cli' === $context ) {
			return array_merge(
				$none,
				array(
					'action'  => 'cli',
					'message' => Messages::get( 'reinstall_cli', $this->plugin_name( $slug, $plugin ), $slug ),
				)
			);
		}

		return array_merge(
			$none,
			array(
				'action' => 'redirect',
				'url'    => self::screen_url( $slug, $plugin ),
			)
		);
	}

	/**
	 * The preview screen's URL.
	 *
	 * @param string $slug   Plugin slug.
	 * @param string $plugin Plugin file to activate afterwards, or ''.
	 * @return string
	 */
	public static function screen_url( string $slug, string $plugin = '' ): string {
		$args = array( 'reinstall' => $slug );
		if ( '' !== $plugin ) {
			$args['activate'] = $plugin;
		}

		return add_query_arg( $args, Page::url() );
	}

	/**
	 * The kind of request: screen (wp-admin page), cli, or another (AJAX, REST, cron).
	 *
	 * @return string
	 */
	private function context(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) {
			return 'other';
		}

		return is_admin() && current_user_can( Settings::capability() ) ? 'screen' : 'other';
	}

	/**
	 * Trashed rows that belong to a plugin slug and that the current user may see.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<int, array>
	 */
	public function rows_for( string $slug ): array {
		return array_values(
			array_filter(
				$this->trash->listed( array( 'status' => 'trashed' ) ),
				static function ( array $row ) use ( $slug ): bool {
					return (string) $row['owner_slug'] === $slug;
				}
			)
		);
	}

	/**
	 * Item type => count.
	 *
	 * @param array[] $rows Trash rows.
	 * @return array<string, int>
	 */
	public static function counts( array $rows ): array {
		$counts = array();
		foreach ( $rows as $row ) {
			$type            = (string) $row['item_type'];
			$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * Installed plugins whose slug owns trashed items.
	 *
	 * @return array<string, array{slug: string, name: string, file: string, active: bool, counts: array<string, int>, rows: int, decision: string}>
	 */
	public function pending(): array {
		if ( 'off' === Settings::reinstall_mode() ) {
			return array();
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$by_owner = array();
		foreach ( $this->trash->listed( array( 'status' => 'trashed' ) ) as $row ) {
			$owner = (string) $row['owner_slug'];
			if ( '' !== $owner && 0 !== strpos( $owner, 'theme:' ) ) {
				$by_owner[ $owner ][] = $row;
			}
		}
		if ( ! $by_owner ) {
			return array();
		}

		$out = array();
		foreach ( (array) get_plugins() as $file => $data ) {
			$slug = Ledger::slug_for( (string) $file );
			if ( ! isset( $by_owner[ $slug ] ) || 'axle-leftovers' === $slug ) {
				continue;
			}
			$out[ $slug ] = array(
				'slug'     => $slug,
				'name'     => (string) ( $data['Name'] ?? $slug ),
				'file'     => (string) $file,
				'active'   => is_plugin_active( (string) $file ),
				'counts'   => self::counts( $by_owner[ $slug ] ),
				'rows'     => count( $by_owner[ $slug ] ),
				'decision' => $this->decision( $slug ),
			);
		}

		return $out;
	}

	/**
	 * A plugin's name for messages.
	 *
	 * @param string $slug   Slug.
	 * @param string $plugin Plugin file.
	 * @return string
	 */
	private function plugin_name( string $slug, string $plugin ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = (array) get_plugins();

		return (string) ( $all[ $plugin ]['Name'] ?? $slug );
	}

	/**
	 * What restoring each trashed item of a plugin would do now, item by item.
	 *
	 * Each entry: id (trash row), type, name, state, default, old, live, note, size.
	 * State: free (nothing is in the way), conflict (the plugin wrote its own: pick),
	 * same (identical value, nothing to pick), taken (something else holds the name, stays
	 * in the trash) or gone (the trashed copy is missing). The default is restore, keep or skip.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<int, array>
	 */
	public function plan( string $slug ): array {
		$rows = $this->rows_for( $slug );
		if ( ! $rows ) {
			return array();
		}

		$names = array();
		foreach ( $rows as $row ) {
			if ( 'table' === $row['item_type'] ) {
				$names[] = (string) $row['name'];
				$names[] = (string) $row['trash_location'];
			}
		}
		$stats = $names ? $this->inventory->table_stats( $names ) : array();
		$plan  = array();
		foreach ( $rows as $row ) {
			$type  = (string) $row['item_type'];
			$name  = (string) $row['name'];
			$entry = array(
				'id'      => (int) $row['id'],
				'type'    => $type,
				'name'    => $name,
				'state'   => 'free',
				'default' => 'restore',
				'old'     => '',
				'live'    => '',
				'note'    => '',
				'size'    => (int) $row['size_bytes'],
			);

			$live = $this->trash->exists( $type, $name );
			if ( 'table' === $type ) {
				$trashed = (string) $row['trash_location'];
				if ( ! $this->inventory->table_exists( $trashed ) ) {
					$entry['state']   = 'gone';
					$entry['default'] = 'skip';
				} else {
					$entry['old'] = $this->table_text( $stats[ $trashed ] ?? array() );
					if ( $live ) {
						$entry['state']   = 'conflict';
						$entry['default'] = 'keep';
						$entry['live']    = $this->table_text( $stats[ $name ] ?? array() );
					}
				}
			} elseif ( 'option' === $type ) {
				$payload      = (array) ( $row['payload']['rows'][0] ?? array() );
				$old          = (string) ( $payload['option_value'] ?? '' );
				$entry['old'] = $this->value_text( $name, $old );
				if ( $live ) {
					$now = $this->live_option( $name );
					if ( $now === $old ) {
						$entry['state']   = 'same';
						$entry['default'] = 'skip';
					} else {
						$entry['state']   = 'conflict';
						$entry['default'] = 'keep';
						$entry['live']    = $this->value_text( $name, (string) $now );
					}
				}
			} elseif ( $live ) {
				$entry['state']   = 'taken';
				$entry['default'] = 'skip';
			}
			if ( 'posttype' === $type && 'free' === $entry['state'] ) {
				$entry['note'] = __( 'Media attached to these posts stayed in the Media Library with a parent that no longer exists. Restoring the posts connects it again.', 'axle-leftovers' );
			}
			$plan[] = $entry;
		}

		return $plan;
	}

	/**
	 * The default choice for each restorable entry: what is free comes back, what clashes stays as written.
	 *
	 * @param array[] $plan Plan.
	 * @return array<int, string> Trash row ID => restore.
	 */
	public function defaults( array $plan ): array {
		$picked = array();
		foreach ( $plan as $entry ) {
			if ( 'free' === $entry['state'] ) {
				$picked[ (int) $entry['id'] ] = 'restore';
			}
		}

		return $picked;
	}

	/**
	 * Rows and size of a table, as text.
	 *
	 * @param array $stat rows and bytes.
	 * @return string
	 */
	private function table_text( array $stat ): string {
		/* translators: 1: number of rows, 2: size such as "16 KB". */
		return sprintf( _n( '%1$s row, %2$s', '%1$s rows, %2$s', (int) ( $stat['rows'] ?? 0 ), 'axle-leftovers' ), number_format_i18n( (int) ( $stat['rows'] ?? 0 ) ), (string) size_format( (int) ( $stat['bytes'] ?? 0 ) ) );
	}

	/**
	 * A stored value, cut short. Names that look like keys or secrets show only a length.
	 *
	 * @param string $name  Option name.
	 * @param string $value Raw value.
	 * @return string
	 */
	private function value_text( string $name, string $value ): string {
		/**
		 * Filters whether an option's value is hidden in the reinstall preview.
		 *
		 * @param bool   $hide  True when the name looks like a key, secret, token, password or salt.
		 * @param string $name  Option name.
		 */
		$hide = (bool) apply_filters( 'axle_leftovers_reinstall_hide_value', 1 === preg_match( '/(key|secret|token|passw|salt|licen[cs]e|auth)/i', $name ), $name );
		if ( $hide ) {
			/* translators: %s: number of characters. */
			return sprintf( __( '(hidden, %s characters)', 'axle-leftovers' ), number_format_i18n( strlen( $value ) ) );
		}

		/**
		 * Filters how many characters of an option's value the reinstall preview shows.
		 *
		 * @param int $length Characters. Default 160 (20 to 2000).
		 */
		$length = max( 20, min( 2000, (int) apply_filters( 'axle_leftovers_reinstall_preview_length', 160 ) ) );
		if ( strlen( $value ) <= $length ) {
			return '' === $value ? __( '(empty)', 'axle-leftovers' ) : $value;
		}

		return substr( $value, 0, $length ) . '...';
	}

	/**
	 * The raw stored value of a live option.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private function live_option( string $name ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The raw stored value, to compare with the trashed one.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
	}

	/**
	 * Restores a plugin's trashed data.
	 *
	 * @param string             $slug   Plugin slug.
	 * @param array<int, string> $picked Trash row ID => restore (free items) or use_old (clashes: the current data goes to the trash first). Anything not listed is left alone.
	 * @param string             $source screen, cli, rest, ability or activation.
	 * @return array{entry: array, failed: array}|WP_Error
	 */
	public function restore( string $slug, array $picked, string $source = 'screen' ) {
		$plan = $this->plan( $slug );
		if ( ! $plan ) {
			return new WP_Error( 'axle_leftovers_not_found', Messages::get( 'reinstall_none', $slug ), array( 'status' => 404 ) );
		}

		$ids     = array();
		$swapped = array();
		foreach ( $plan as $entry ) {
			$choice = (string) ( $picked[ $entry['id'] ] ?? '' );
			if ( 'free' === $entry['state'] && 'restore' === $choice ) {
				$ids[] = (int) $entry['id'];
			} elseif ( 'conflict' === $entry['state'] && 'use_old' === $choice && in_array( $entry['type'], array( 'table', 'option' ), true ) ) {
				if ( ! Settings::user_can_item( (string) $entry['type'] ) ) {
					$this->put_back( $swapped );
					return new WP_Error( 'axle_leftovers_forbidden', Messages::get( 'item_cap', (string) $entry['name'] ), array( 'status' => 403 ) );
				}
				$row = $this->trash->swap_out( (string) $entry['type'], (string) $entry['name'], $slug );
				if ( is_wp_error( $row ) ) {
					$this->put_back( $swapped );
					return new WP_Error( 'axle_leftovers_swap', Messages::get( 'reinstall_swap', (string) $entry['name'] ) . ' ' . $row->get_error_message(), array( 'status' => 409 ) );
				}
				$swapped[ (int) $entry['id'] ] = array(
					'id'   => (int) $row['id'],
					'type' => (string) $entry['type'],
					'name' => (string) $entry['name'],
				);
				$ids[]                         = (int) $entry['id'];
			}
		}
		if ( ! $ids ) {
			return new WP_Error( 'axle_leftovers_nothing', Messages::get( 'reinstall_nothing' ), array( 'status' => 400 ) );
		}

		$result = $this->trash->restore( '', $ids );
		if ( is_wp_error( $result ) ) {
			$this->put_back( $swapped );
			return $result;
		}

		// An item that did not come back must not have lost the data it was to replace.
		$done = array_column( $result['restored'], 'id' );
		foreach ( $swapped as $row_id => $swap ) {
			if ( ! in_array( $row_id, array_map( 'intval', $done ), true ) ) {
				$this->put_back( array( $swap ) );
				unset( $swapped[ $row_id ] );
			}
		}
		if ( ! $result['restored'] ) {
			return new WP_Error( 'axle_leftovers_nothing', array() !== $result['failed'] ? implode( ' ', array_column( $result['failed'], 'message' ) ) : Messages::get( 'reinstall_nothing' ), array( 'status' => 409 ) );
		}

		$entry = array(
			'id'       => 'r' . gmdate( 'ymdHis' ) . strtolower( wp_generate_password( 4, false ) ),
			'slug'     => $slug,
			'gmt'      => gmdate( 'Y-m-d H:i:s' ),
			'user'     => get_current_user_id(),
			'source'   => $source,
			'restored' => array_map(
				static function ( array $row ): array {
					return array(
						'id'   => (int) $row['id'],
						'type' => (string) $row['type'],
						'name' => (string) $row['name'],
					);
				},
				$result['restored']
			),
			'swapped'  => array_values( $swapped ),
			'undone'   => '',
		);
		$this->log( $entry );
		$this->set_decision( $slug, 'handled' );

		/**
		 * Fires after a plugin's trashed data was brought back.
		 *
		 * @param array $entry The log entry: id, slug, gmt, user, source, restored (id, type, name), swapped (the current data put in the trash first).
		 */
		do_action( 'axle_leftovers_reinstall_restored', $entry );

		return array(
			'entry'  => $entry,
			'failed' => $result['failed'],
		);
	}

	/**
	 * Puts data that was moved to the trash for a replacement back.
	 *
	 * @param array[] $swaps Entries with the trash row ID of the current data.
	 */
	private function put_back( array $swaps ): void {
		foreach ( array_reverse( $swaps ) as $swap ) {
			$this->trash->restore( '', array( (int) $swap['id'] ) );
		}
	}

	/**
	 * Undoes a restore: what came back goes into the trash again, and what it replaced comes back.
	 *
	 * @param string $id Log entry ID.
	 * @return array{entry: array, failed: array}|WP_Error
	 */
	public function undo( string $id ) {
		$store = $this->store();
		$found = null;
		foreach ( $store['log'] as $index => $entry ) {
			if ( (string) $entry['id'] === $id ) {
				$found = $index;
				break;
			}
		}
		if ( null === $found || '' !== (string) $store['log'][ $found ]['undone'] ) {
			return new WP_Error( 'axle_leftovers_undo_gone', Messages::get( 'reinstall_undo_gone' ), array( 'status' => 404 ) );
		}
		$entry  = $store['log'][ $found ];
		$failed = array();

		foreach ( array_reverse( (array) $entry['restored'] ) as $item ) {
			if ( ! Settings::user_can_item( (string) $item['type'] ) ) {
				$failed[] = array(
					'name'    => (string) $item['name'],
					'message' => Messages::get( 'item_cap', (string) $item['name'] ),
				);
				continue;
			}
			$row = $this->trash->swap_out( (string) $item['type'], (string) $item['name'], (string) $entry['slug'] );
			if ( is_wp_error( $row ) ) {
				$failed[] = array(
					'name'    => (string) $item['name'],
					'message' => $row->get_error_message(),
				);
			}
		}
		$back = array_map( 'intval', array_column( (array) $entry['swapped'], 'id' ) );
		if ( $back ) {
			$result = $this->trash->restore( '', $back );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'name'    => '',
					'message' => $result->get_error_message(),
				);
			} else {
				$failed = array_merge( $failed, $result['failed'] );
			}
		}

		$store['log'][ $found ]['undone'] = gmdate( 'Y-m-d H:i:s' );
		$this->save( $store );
		$entry['undone'] = $store['log'][ $found ]['undone'];

		/**
		 * Fires after a reinstall restore was undone.
		 *
		 * @param array $entry The log entry, with `undone` set.
		 */
		do_action( 'axle_leftovers_reinstall_undone', $entry );

		return array(
			'entry'  => $entry,
			'failed' => $failed,
		);
	}

	/**
	 * Restores that can still be undone, newest first.
	 *
	 * @param int $user_id Only this user's, or 0 for all.
	 * @return array<int, array>
	 */
	public function undoable( int $user_id = 0 ): array {
		$days = Settings::retention_days();
		$out  = array();
		foreach ( $this->store()['log'] as $entry ) {
			$time = strtotime( (string) $entry['gmt'] . ' UTC' );
			if ( '' !== (string) $entry['undone'] || ( $time && $time < time() - $days * DAY_IN_SECONDS ) ) {
				continue;
			}
			if ( $user_id > 0 && (int) $entry['user'] !== $user_id ) {
				continue;
			}
			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * A plugin's decision: '', skipped (start fresh, no stop at activation), handled or dismissed.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public function decision( string $slug ): string {
		return (string) ( $this->store()['decisions'][ $slug ]['state'] ?? '' );
	}

	/**
	 * Records a decision.
	 *
	 * @param string $slug  Slug.
	 * @param string $state skipped, handled or dismissed; '' forgets it.
	 */
	public function set_decision( string $slug, string $state ): void {
		$store = $this->store();
		if ( '' === $state ) {
			unset( $store['decisions'][ $slug ] );
		} else {
			$store['decisions'][ $slug ] = array(
				'state' => $state,
				'gmt'   => gmdate( 'Y-m-d H:i:s' ),
				'user'  => get_current_user_id(),
			);
		}
		$this->save( $store );

		/**
		 * Fires when a decision about a reinstalled plugin's data is recorded.
		 *
		 * @param string $slug  Plugin slug.
		 * @param string $state skipped, handled, dismissed or '' (forgotten).
		 */
		do_action( 'axle_leftovers_reinstall_decided', $slug, $state );
	}

	/**
	 * Adds a log entry, keeping the newest few.
	 *
	 * @param array $entry Entry.
	 */
	private function log( array $entry ): void {
		$store = $this->store();
		array_unshift( $store['log'], $entry );

		/**
		 * Filters how many reinstall restores are kept for Undo.
		 *
		 * @param int $size Entries. Default 20 (1 to 200).
		 */
		$size         = max( 1, min( 200, (int) apply_filters( 'axle_leftovers_reinstall_log_size', 20 ) ) );
		$store['log'] = array_slice( $store['log'], 0, $size );
		$this->save( $store );
	}

	/**
	 * The stored decisions and log.
	 *
	 * @return array{decisions: array, log: array}
	 */
	private function store(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'decisions' => (array) ( $stored['decisions'] ?? array() ),
			'log'       => array_values( (array) ( $stored['log'] ?? array() ) ),
		);
	}

	/**
	 * Saves the store, not autoloaded.
	 *
	 * @param array $store Store.
	 */
	private function save( array $store ): void {
		update_option( self::OPTION, $store, false );
	}

	/**
	 * Takes a user out of the log, for the privacy eraser.
	 *
	 * @param int $user_id User.
	 * @return int Entries changed.
	 */
	public static function anonymize_user( int $user_id ): int {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return 0;
		}
		$changed = 0;
		foreach ( array( 'log', 'decisions' ) as $key ) {
			foreach ( (array) ( $stored[ $key ] ?? array() ) as $index => $entry ) {
				if ( (int) ( $entry['user'] ?? 0 ) === $user_id ) {
					$stored[ $key ][ $index ]['user'] = 0;
					++$changed;
				}
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $stored, false );
		}

		return $changed;
	}

	/**
	 * Log entries made by a user, for the privacy exporter.
	 *
	 * @param int $user_id User.
	 * @return array<int, array>
	 */
	public static function entries_for_user( int $user_id ): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		foreach ( (array) ( is_array( $stored ) ? ( $stored['log'] ?? array() ) : array() ) as $entry ) {
			if ( (int) ( $entry['user'] ?? 0 ) === $user_id ) {
				$out[] = (array) $entry;
			}
		}

		return $out;
	}

	/**
	 * A plain public form of a log entry.
	 *
	 * @param array $entry Entry.
	 * @return array
	 */
	public static function public_entry( array $entry ): array {
		return array(
			'id'       => (string) $entry['id'],
			'slug'     => (string) $entry['slug'],
			'gmt'      => (string) $entry['gmt'],
			'source'   => (string) $entry['source'],
			'restored' => (array) $entry['restored'],
			'replaced' => count( (array) $entry['swapped'] ),
			'undone'   => (string) $entry['undone'],
		);
	}
}
