<?php
/**
 * Trash with undo: export, move, restore and purge.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Moves leftovers to a trash instead of deleting them. Every removal writes an
 * export first, then moves the item; one action is one batch, restored as a
 * unit. Purging, by the daily cron after the retention period or by hand, is
 * the only step that deletes.
 *
 * Names only ever come from the scan results, are checked against the live
 * site, and go into SQL through `%i` placeholders.
 */
class Trash {

	/**
	 * The scanner.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * The inventory.
	 *
	 * @var Inventory
	 */
	private Inventory $inventory;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	private Storage $storage;

	/**
	 * Sets up the trash.
	 *
	 * @param Scanner   $scanner   Scanner.
	 * @param Inventory $inventory Inventory.
	 * @param Storage   $storage   Storage.
	 */
	public function __construct( Scanner $scanner, Inventory $inventory, Storage $storage ) {
		$this->scanner   = $scanner;
		$this->inventory = $inventory;
		$this->storage   = $storage;
	}

	/**
	 * Table, id column and object column of a meta type.
	 *
	 * @param string $type postmeta, usermeta, termmeta or commentmeta.
	 * @return array{table: string, id: string, object: string, cache: string}
	 */
	public static function meta_table( string $type ): array {
		global $wpdb;

		$map = array(
			'postmeta'    => array( $wpdb->postmeta, 'meta_id', 'post_id', 'post_meta' ),
			'usermeta'    => array( $wpdb->usermeta, 'umeta_id', 'user_id', 'user_meta' ),
			'termmeta'    => array( $wpdb->termmeta, 'meta_id', 'term_id', 'term_meta' ),
			'commentmeta' => array( $wpdb->commentmeta, 'meta_id', 'comment_id', 'comment_meta' ),
		);
		$row = $map[ $type ] ?? $map['postmeta'];

		return array(
			'table'  => (string) $row[0],
			'id'     => $row[1],
			'object' => $row[2],
			'cache'  => $row[3],
		);
	}

	/**
	 * Whether Axle Read-Only Mode has frozen the site.
	 *
	 * @return bool
	 */
	public static function frozen(): bool {
		$class = '\AryanX\Axle\ReadOnlyMode\Plugin';
		if ( ! class_exists( $class ) ) {
			return false;
		}

		$plugin = $class::instance();

		return $plugin && method_exists( $plugin, 'state' ) && $plugin->state()->is_frozen();
	}

	/**
	 * The refusal during a freeze.
	 *
	 * @return WP_Error
	 */
	private static function frozen_error(): WP_Error {
		return new WP_Error( 'axle_leftovers_frozen', Messages::get( 'frozen' ), array( 'status' => 423 ) );
	}

	/**
	 * Runs every safety check on items before a trash.
	 *
	 * @param array $requested Items: array of array( type, name ).
	 * @param array $args      confirm_names (string[]), check_caps (bool, default true).
	 * @return array{items: array<int, array>, errors: array<int, array{id: string, code: string, message: string}>}
	 */
	public function check( array $requested, array $args = array() ): array {
		$confirm    = array_map( 'strval', (array) ( $args['confirm_names'] ?? array() ) );
		$check_caps = $args['check_caps'] ?? true;
		$types      = Settings::item_types();
		$items      = array();
		$errors     = array();
		$attribute  = $this->scanner->attribution();
		$attribute->flush();
		Core::flush();

		foreach ( $requested as $request ) {
			$type = (string) ( $request['type'] ?? '' );
			$name = (string) ( $request['name'] ?? '' );
			$id   = $type . ':' . $name;
			$fail = static function ( string $code, string $message ) use ( &$errors, $id ): void {
				$errors[] = array(
					'id'      => $id,
					'code'    => $code,
					'message' => $message,
				);
			};

			if ( ! isset( $types[ $type ] ) ) {
				$fail( 'type', Messages::get( 'bad_type', $type ) );
				continue;
			}
			if ( Core::is_protected( $type, $name ) ) {
				$fail( 'protected', Messages::get( 'protected', $name ) );
				continue;
			}
			$item = $this->scanner->item( $type, $name );
			if ( ! $item ) {
				$fail( 'not_listed', Messages::get( 'not_listed', $name ) );
				continue;
			}
			if ( ! $this->exists( $type, $name ) ) {
				$fail( 'missing', Messages::get( 'missing', $name ) );
				continue;
			}
			$who = $attribute->attribute( $type, $name );
			if ( $who['in_use'] ) {
				$fail( 'in_use', Messages::get( 'in_use', $name, $attribute->owner_name( $who['in_use_by'] ) ) );
				continue;
			}
			$item = array_merge( $item, array_intersect_key( $who, array_flip( array( 'owner', 'owner_name', 'confidence', 'evidence' ) ) ) );
			if ( $check_caps && 'network' === $item['scope'] && ! current_user_can( Settings::capability( true ) ) ) {
				$fail( 'network', Messages::get( 'network', $name ) );
				continue;
			}
			if ( Settings::require_typed_confirmation( $item['confidence'] ) && ! in_array( $name, $confirm, true ) ) {
				$fail( 'confirm', Messages::get( 'confirm', $name ) );
				continue;
			}
			if ( 'role' === $type ) {
				$users = $this->inventory->users_with_role( $name );
				if ( $users ) {
					$fail( 'role_users', Messages::get( 'role_users', $name, implode( ', ', $users ) ) );
					continue;
				}
			}
			$items[] = $item;
		}

		$refs = $this->scanner->references()->check( $items );
		foreach ( $items as $index => $item ) {
			$files = $refs[ $item['id'] ] ?? array();
			if ( $files ) {
				$errors[] = array(
					'id'      => $item['id'],
					'code'    => 'referenced',
					'message' => Messages::get( 'referenced', $item['name'], implode( ', ', $files ) ),
				);
				unset( $items[ $index ] );
				continue;
			}

			/**
			 * Filters whether an item may be trashed, after every safety check passed.
			 * The safety checks still run first and cannot be skipped from here.
			 *
			 * @param bool|WP_Error $allowed True, false or a WP_Error with the reason.
			 * @param array         $item    The item: type, name, owner, confidence, evidence, rows, bytes.
			 */
			$allowed = apply_filters( 'axle_leftovers_can_trash', true, $item );
			if ( true !== $allowed ) {
				$errors[] = array(
					'id'      => $item['id'],
					'code'    => 'filtered',
					'message' => $allowed instanceof WP_Error ? $allowed->get_error_message() : Messages::get( 'filtered', $item['name'] ),
				);
				unset( $items[ $index ] );
			}
		}

		return array(
			'items'  => array_values( $items ),
			'errors' => $errors,
		);
	}

	/**
	 * Moves items to the trash as one batch, after exporting each.
	 *
	 * @param array $requested Items: array of array( type, name ).
	 * @param array $args      confirm_names, check_caps, user_id.
	 * @return array{batch: string, items: array<int, array>}|WP_Error
	 */
	public function trash( array $requested, array $args = array() ) {
		if ( self::frozen() ) {
			return self::frozen_error();
		}
		if ( ! $requested ) {
			return new WP_Error( 'axle_leftovers_empty', Messages::get( 'nothing_selected' ), array( 'status' => 400 ) );
		}

		$checked = $this->check( $requested, $args );
		if ( $checked['errors'] ) {
			return new WP_Error(
				'axle_leftovers_refused',
				implode( ' ', array_column( $checked['errors'], 'message' ) ),
				array(
					'status' => 409,
					'items'  => $checked['errors'],
				)
			);
		}

		$batch = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 4, false ) );
		$items = $checked['items'];

		/**
		 * Fires before a batch of leftovers is exported and moved to the trash.
		 *
		 * @param array[] $items Items: type, name, owner, confidence, evidence, rows, bytes.
		 * @param string  $batch Batch ID.
		 */
		do_action( 'axle_leftovers_before_trash', $items, $batch );

		$done = array();
		foreach ( $items as $item ) {
			$row = $this->trash_one( $item, $batch, (int) ( $args['user_id'] ?? get_current_user_id() ) );
			if ( is_wp_error( $row ) ) {
				// Put back what this batch already moved, so the batch is all or nothing.
				foreach ( array_reverse( $done ) as $moved ) {
					$this->restore_row( $moved );
					$this->delete_row( (int) $moved['id'] );
				}

				/**
				 * Fires when moving an item to the trash failed. Items already moved in the batch were put back.
				 *
				 * @param array    $item  The item that failed.
				 * @param WP_Error $error What went wrong.
				 * @param string   $batch Batch ID.
				 */
				do_action( 'axle_leftovers_trash_failed', $item, $row, $batch );

				return $row;
			}
			$done[] = $row;
		}

		$this->scanner->forget( $items );

		/**
		 * Fires after a batch of leftovers was moved to the trash.
		 *
		 * @param string  $batch Batch ID.
		 * @param array[] $rows  Trash rows: id, item_type, name, owner_slug, size_bytes, export_path, ...
		 */
		do_action( 'axle_leftovers_trashed', $batch, $done );

		return array(
			'batch' => $batch,
			'items' => array_map( array( self::class, 'public_row' ), $done ),
		);
	}

	/**
	 * Restores a batch, or single trashed items.
	 *
	 * @param string $batch    Batch ID, or ''.
	 * @param int[]  $item_ids Trash row IDs, when no batch is given.
	 * @return array{restored: array<int, array>, failed: array<int, array{id: int, name: string, message: string}>}|WP_Error
	 */
	public function restore( string $batch = '', array $item_ids = array() ) {
		if ( self::frozen() ) {
			return self::frozen_error();
		}

		$rows = '' !== $batch ? $this->rows( array( 'batch_id' => $batch ) ) : $this->rows_by_ids( array_map( 'intval', $item_ids ) );
		$rows = array_values(
			array_filter(
				$rows,
				static function ( array $row ): bool {
					return 'trashed' === $row['status'];
				}
			)
		);
		if ( ! $rows ) {
			return new WP_Error( 'axle_leftovers_not_found', Messages::get( 'nothing_to_restore' ), array( 'status' => 404 ) );
		}

		/**
		 * Fires before trashed items are restored.
		 *
		 * @param array[] $rows  Trash rows.
		 * @param string  $batch Batch ID, or '' when single items are restored.
		 */
		do_action( 'axle_leftovers_before_restore', $rows, $batch );

		$restored = array();
		$failed   = array();
		foreach ( $rows as $row ) {
			$result = $this->restore_row( $row );
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'id'      => (int) $row['id'],
					'name'    => (string) $row['name'],
					'message' => $result->get_error_message(),
				);

				/**
				 * Fires when a trashed item could not be restored.
				 *
				 * @param array    $row   Trash row.
				 * @param WP_Error $error Why.
				 */
				do_action( 'axle_leftovers_restore_failed', $row, $result );
				continue;
			}
			$this->update_row(
				(int) $row['id'],
				array(
					'status'       => 'restored',
					'restored_gmt' => current_time( 'mysql', true ),
					'payload'      => '',
				)
			);
			$restored[] = $row;
		}

		Scanner::mark_stale();

		if ( $restored ) {
			/**
			 * Fires after trashed items were restored.
			 *
			 * @param array[] $rows  Trash rows restored.
			 * @param string  $batch Batch ID, or ''.
			 */
			do_action( 'axle_leftovers_restored', $restored, $batch );
		}

		return array(
			'restored' => array_map( array( self::class, 'public_row' ), $restored ),
			'failed'   => $failed,
		);
	}

	/**
	 * Deletes trashed items for good.
	 *
	 * @param array $args older_than (days; null for all), batch, due_only (only items past their purge date).
	 * @return array<int, array>|WP_Error Purged rows.
	 */
	public function purge( array $args = array() ) {
		if ( self::frozen() ) {
			return self::frozen_error();
		}

		$rows = '' !== (string) ( $args['batch'] ?? '' ) ? $this->rows( array( 'batch_id' => (string) $args['batch'] ) ) : $this->rows( array( 'status' => 'trashed' ) );
		$now  = time();
		$keep = Settings::keep_exports();
		$out  = array();

		foreach ( $rows as $row ) {
			if ( 'trashed' !== $row['status'] ) {
				continue;
			}
			if ( ! empty( $args['due_only'] ) && strtotime( $row['purge_after_gmt'] . ' UTC' ) > $now ) {
				continue;
			}
			if ( isset( $args['older_than'] ) && strtotime( $row['trashed_gmt'] . ' UTC' ) > $now - ( (int) $args['older_than'] * DAY_IN_SECONDS ) ) {
				continue;
			}
			$this->purge_row( $row, $keep );
			$out[] = $row;
		}

		// Exports of restored items go after the retention period too.
		if ( ! empty( $args['due_only'] ) && ! $keep ) {
			foreach ( $this->rows( array( 'status' => 'restored' ) ) as $row ) {
				if ( '' !== $row['export_path'] && strtotime( $row['purge_after_gmt'] . ' UTC' ) <= $now ) {
					$this->delete_export( $row );
					$this->update_row( (int) $row['id'], array( 'export_path' => '' ) );
				}
			}
		}

		if ( $out ) {
			/**
			 * Fires after trashed items were deleted for good.
			 *
			 * @param array[] $rows Trash rows purged.
			 */
			do_action( 'axle_leftovers_purged', $out );
		}

		return array_map( array( self::class, 'public_row' ), $out );
	}

	/**
	 * Trash rows, newest first.
	 *
	 * @param array $where Column => value: batch_id, status, scope, trashed_by.
	 * @param int   $limit Most rows.
	 * @return array<int, array>
	 */
	public function rows( array $where = array(), int $limit = 5000 ): array {
		global $wpdb;

		$batch  = (string) ( $where['batch_id'] ?? '' );
		$status = (string) ( $where['status'] ?? '' );
		$scope  = (string) ( $where['scope'] ?? '' );
		$type   = (string) ( $where['item_type'] ?? '' );
		$user   = (int) ( $where['trashed_by'] ?? 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; an empty filter matches everything.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = '' OR batch_id = %s ) AND ( %s = '' OR status = %s ) AND ( %s = '' OR scope = %s ) AND ( %s = '' OR item_type = %s ) AND ( %d = 0 OR trashed_by = %d ) ORDER BY id DESC LIMIT %d", Schema::table( 'trash' ), $batch, $batch, $status, $status, $scope, $scope, $type, $type, $user, $user, $limit ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Trash rows by ID.
	 *
	 * @param int[] $ids IDs.
	 * @return array<int, array>
	 */
	public function rows_by_ids( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The plugin's own table; one %d per ID.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE id IN ({$placeholders}) ORDER BY id", array_merge( array( Schema::table( 'trash' ) ), $ids ) ), ARRAY_A );

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * Trashed batches with their items, newest first.
	 *
	 * @param string $scope site or network.
	 * @return array<string, array{batch: string, trashed_gmt: string, trashed_by: int, items: array, bytes: int}>
	 */
	public function batches( string $scope = 'site' ): array {
		$out = array();
		foreach ( $this->rows(
			array(
				'status' => 'trashed',
				'scope'  => $scope,
			)
		) as $row ) {
			$batch = $row['batch_id'];
			if ( ! isset( $out[ $batch ] ) ) {
				$out[ $batch ] = array(
					'batch'       => $batch,
					'trashed_gmt' => $row['trashed_gmt'],
					'trashed_by'  => (int) $row['trashed_by'],
					'items'       => array(),
					'bytes'       => 0,
				);
			}
			$out[ $batch ]['items'][] = $row;
			$out[ $batch ]['bytes']  += (int) $row['size_bytes'];
		}

		return $out;
	}

	/**
	 * How many items are in the trash.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', Schema::table( 'trash' ), 'trashed' ) );
	}

	/**
	 * The absolute path of a row's export, when it is inside the exports folder.
	 *
	 * @param array $row Trash row.
	 * @return string '' when there is none.
	 */
	public function export_file( array $row ): string {
		if ( '' === (string) $row['export_path'] ) {
			return '';
		}
		$root = $this->storage->dir();
		$file = $root . '/' . ltrim( (string) $row['export_path'], '/' );

		return is_file( $file ) && Storage::is_inside( $file, $root . '/exports' ) ? $file : '';
	}

	/**
	 * Whether an item still exists on the site.
	 *
	 * @param string $type Type.
	 * @param string $name Name.
	 * @return bool
	 */
	public function exists( string $type, string $name ): bool {
		global $wpdb;

		switch ( $type ) {
			case 'table':
				return $this->inventory->table_exists( $name );
			case 'option':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be live, not the options cache.
				return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_id FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
			case 'sitemeta':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be live.
				return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT meta_id FROM %i WHERE meta_key = %s AND site_id = %d', $wpdb->sitemeta, $name, get_current_network_id() ) );
			case 'cron':
				return isset( $this->inventory->cron_events()[ $name ] );
			case 'postmeta':
			case 'usermeta':
			case 'termmeta':
			case 'commentmeta':
				$meta = self::meta_table( $type );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Must be live.
				return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i WHERE meta_key = %s LIMIT 1', $meta['id'], $meta['table'], $name ) );
			case 'folder':
				$path = $this->storage->uploads_base() . '/' . $name;
				return false === strpbrk( $name, '/\\' ) && is_dir( $path ) && Storage::is_inside( $path, $this->storage->uploads_base() );
			case 'role':
				return isset( $this->inventory->roles()[ $name ] );
			case 'cap':
				return isset( $this->inventory->caps()[ $name ] );
		}

		return false;
	}

	/**
	 * Exports and moves one item.
	 *
	 * @param array  $item    Item.
	 * @param string $batch   Batch ID.
	 * @param int    $user_id User.
	 * @return array|WP_Error The trash row.
	 */
	private function trash_one( array $item, string $batch, int $user_id ) {
		global $wpdb;

		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
		$wpdb->insert(
			Schema::table( 'trash' ),
			array(
				'batch_id'          => $batch,
				'scope'             => (string) $item['scope'],
				'item_type'         => (string) $item['type'],
				'name'              => (string) $item['name'],
				'owner_slug'        => (string) $item['owner'],
				'confidence'        => (string) $item['confidence'],
				'original_location' => (string) $item['name'],
				'size_bytes'        => (int) $item['bytes'],
				'trashed_by'        => $user_id,
				'trashed_gmt'       => gmdate( 'Y-m-d H:i:s', $now ),
				'purge_after_gmt'   => gmdate( 'Y-m-d H:i:s', $now + Settings::retention_days() * DAY_IN_SECONDS ),
				'status'            => 'pending',
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $id < 1 ) {
			return new WP_Error( 'axle_leftovers_db', Messages::get( 'failed', (string) $item['name'] ) );
		}

		$type   = (string) $item['type'];
		$name   = (string) $item['name'];
		$file   = 'exports/' . $batch . '/' . $id . '-' . $type . '-' . sanitize_file_name( Attribution::bare_name( $type, $name ) );
		$result = null;

		switch ( $type ) {
			case 'table':
				$result = $this->trash_table( $id, $name, (int) $item['bytes'], $file . '.sql' );
				break;
			case 'option':
			case 'sitemeta':
				$result = $this->trash_option( $type, $name, $file . '.json' );
				break;
			case 'cron':
				$result = $this->trash_cron( $name, $file . '.json' );
				break;
			case 'postmeta':
			case 'usermeta':
			case 'termmeta':
			case 'commentmeta':
				$result = $this->trash_meta( $type, $name, $file . '.jsonl' );
				break;
			case 'folder':
				$result = $this->trash_folder( $id, $name, $file . '.json' );
				break;
			case 'role':
				$result = $this->trash_role( $name, $file . '.json' );
				break;
			case 'cap':
				$result = $this->trash_cap( $name, $file . '.json' );
				break;
		}

		if ( ! is_array( $result ) ) {
			$this->delete_row( $id );
			return $result instanceof WP_Error ? $result : new WP_Error( 'axle_leftovers_failed', Messages::get( 'failed', $name ) );
		}

		if ( '' !== $result['export'] ) {
			/**
			 * Fires after an item was exported, before it is moved to the trash.
			 *
			 * @param string $batch Batch ID.
			 * @param string $file  Absolute path of the export file.
			 * @param array  $item  The item.
			 */
			do_action( 'axle_leftovers_exported', $batch, $this->storage->dir() . '/' . $result['export'], $item );
		}

		$this->update_row(
			$id,
			array(
				'payload'        => (string) wp_json_encode( $result['payload'] ),
				'trash_location' => (string) $result['location'],
				'export_path'    => (string) $result['export'],
				'status'         => 'trashed',
			)
		);

		return $this->rows_by_ids( array( $id ) )[0];
	}

	/**
	 * Table: SQL export under the size cap, then RENAME TABLE to {prefix}axlt_<id>.
	 *
	 * @param int    $id    Trash row ID.
	 * @param string $name  Table.
	 * @param int    $bytes Size.
	 * @param string $file  Export path, relative to the storage folder.
	 * @return array|WP_Error
	 */
	private function trash_table( int $id, string $name, int $bytes, string $file ) {
		global $wpdb;

		$target  = $wpdb->prefix . 'axlt_' . $id;
		$skipped = $bytes > Settings::export_max_bytes();
		if ( ! $skipped && ! $this->export_table( $name, $this->storage->dir( 'exports' ) . '/' . substr( $file, 8 ) ) ) {
			return new WP_Error( 'axle_leftovers_export', Messages::get( 'export_failed', $name ) );
		}
		if ( $this->inventory->table_exists( $target ) ) {
			return new WP_Error( 'axle_leftovers_taken', Messages::get( 'taken', $target ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Moving a leftover table to the trash keeps its data; the name came from SHOW TABLES.
		if ( false === $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $name, $target ) ) ) {
			$this->storage->delete( $this->storage->dir() . '/' . $file );
			return new WP_Error( 'axle_leftovers_rename', Messages::get( 'failed', $name ) );
		}

		return array(
			'payload'  => array( 'export_skipped' => $skipped ),
			'location' => $target,
			'export'   => $skipped ? '' : $file,
		);
	}

	/**
	 * Writes CREATE TABLE and INSERT statements, 500 rows at a time.
	 *
	 * @param string $name Table.
	 * @param string $path Absolute path of the export.
	 * @return bool
	 */
	private function export_table( string $name, string $path ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Reads the definition of a leftover table for its export; changes nothing.
		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $name ), ARRAY_N );
		if ( ! is_array( $create ) || empty( $create[1] ) ) {
			return false;
		}

		$handle = $this->storage->open_append( $path );
		if ( false === $handle ) {
			return false;
		}

		$this->storage->write( $handle, '-- Axle Leftovers export of ' . $name . ', ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n" . $create[1] . ";\n\n" );
		$offset = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Paged export of a leftover table.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $name, 500, $offset ), ARRAY_A );
			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $row as $value ) {
					$values[] = null === $value ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( esc_sql( (string) $value ) ) . "'";
				}
				$this->storage->write( $handle, 'INSERT INTO `' . $name . '` VALUES (' . implode( ', ', $values ) . ");\n" );
			}
			$offset += 500;
			$read    = count( $rows );
		} while ( 500 === $read );
		$this->storage->close( $handle );

		return true;
	}

	/**
	 * Option or network option (with a transient's timeout row): copied, then deleted.
	 *
	 * @param string $type option or sitemeta.
	 * @param string $name Name.
	 * @param string $file Export path.
	 * @return array|WP_Error
	 */
	private function trash_option( string $type, string $name, string $file ) {
		$rows = $this->option_rows( $type, $name );
		if ( ! $rows ) {
			return new WP_Error( 'axle_leftovers_missing', Messages::get( 'missing', $name ) );
		}

		$payload = array( 'rows' => $rows );
		if ( ! $this->storage->put( $this->storage->dir( 'exports' ) . '/' . substr( $file, 8 ), (string) wp_json_encode( array( 'type' => $type ) + $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
			return new WP_Error( 'axle_leftovers_export', Messages::get( 'export_failed', $name ) );
		}

		foreach ( $rows as $row ) {
			if ( 'sitemeta' === $type ) {
				delete_site_option( (string) $row['meta_key'] );
			} else {
				delete_option( (string) $row['option_name'] );
			}
		}

		return array(
			'payload'  => $payload,
			'location' => '',
			'export'   => $file,
		);
	}

	/**
	 * The raw rows of an option, with a transient's timeout row.
	 *
	 * @param string $type option or sitemeta.
	 * @param string $name Name.
	 * @return array<int, array>
	 */
	private function option_rows( string $type, string $name ): array {
		global $wpdb;

		$names = array( $name );
		if ( preg_match( '/^(_site)?_transient_(.+)$/', $name, $match ) ) {
			$names[] = $match[1] . '_transient_timeout_' . $match[2];
		}

		$rows = array();
		foreach ( $names as $option ) {
			if ( 'sitemeta' === $type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The raw row, to restore it exactly.
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE meta_key = %s AND site_id = %d', $wpdb->sitemeta, $option, get_current_network_id() ), ARRAY_A );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The raw row, to restore it exactly with its autoload value.
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_name, option_value, autoload FROM %i WHERE option_name = %s', $wpdb->options, $option ), ARRAY_A );
			}
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Cron hook: every event stored, then unscheduled.
	 *
	 * @param string $hook Hook.
	 * @param string $file Export path.
	 * @return array|WP_Error
	 */
	private function trash_cron( string $hook, string $file ) {
		$events = $this->inventory->cron_events()[ $hook ] ?? array();
		if ( ! $events ) {
			return new WP_Error( 'axle_leftovers_missing', Messages::get( 'missing', $hook ) );
		}

		$payload = array(
			'hook'   => $hook,
			'events' => $events,
		);
		if ( ! $this->storage->put( $this->storage->dir( 'exports' ) . '/' . substr( $file, 8 ), (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
			return new WP_Error( 'axle_leftovers_export', Messages::get( 'export_failed', $hook ) );
		}

		wp_unschedule_hook( $hook );

		return array(
			'payload'  => $payload,
			'location' => '',
			'export'   => $file,
		);
	}

	/**
	 * Meta key: rows copied to the export in batches, then deleted in batches.
	 *
	 * @param string $type Meta type.
	 * @param string $key  Meta key.
	 * @param string $file Export path; also what restore reads.
	 * @return array|WP_Error
	 */
	private function trash_meta( string $type, string $key, string $file ) {
		global $wpdb;

		$meta   = self::meta_table( $type );
		$batch  = Settings::meta_batch_size();
		$copied = $this->copy_meta( $type, $key, $this->storage->dir( 'exports' ) . '/' . substr( $file, 8 ) );
		if ( null === $copied ) {
			return new WP_Error( 'axle_leftovers_export', Messages::get( 'export_failed', $key ) );
		}
		list( $count, $objects ) = $copied;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Batched delete of one leftover meta key, after the copy.
			$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key = %s LIMIT %d', $meta['table'], $key, $batch ) );
		} while ( $deleted === $batch );

		wp_cache_delete_multiple( array_keys( $objects ), $meta['cache'] );
		if ( function_exists( 'wp_cache_set_last_changed' ) ) {
			wp_cache_set_last_changed( str_replace( '_meta', '', $meta['cache'] ) . 'meta' );
		}

		$payload = array(
			'rows' => $count,
			'file' => $file,
		);
		if ( 'usermeta' === $type ) {
			// The privacy eraser finds batches holding a user's meta by this list.
			$payload['user_ids'] = array_keys( $objects );
		}

		return array(
			'payload'  => $payload,
			'location' => $file,
			'export'   => $file,
		);
	}

	/**
	 * Copies every row of a meta key to a JSON-lines file, in batches.
	 *
	 * @param string $type Meta type.
	 * @param string $key  Meta key.
	 * @param string $path Absolute path.
	 * @return array{0: int, 1: array<int, true>}|null Rows copied and object IDs, or null when the file cannot be written.
	 */
	private function copy_meta( string $type, string $key, string $path ): ?array {
		global $wpdb;

		$meta   = self::meta_table( $type );
		$batch  = Settings::meta_batch_size();
		$handle = $this->storage->open_append( $path );
		if ( false === $handle ) {
			return null;
		}

		$cursor  = 0;
		$count   = 0;
		$objects = array();
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Batched copy of one leftover meta key.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT %i AS id, %i AS object_id, meta_key, meta_value FROM %i WHERE meta_key = %s AND %i > %d ORDER BY %i LIMIT %d', $meta['id'], $meta['object'], $meta['table'], $key, $meta['id'], $cursor, $meta['id'], $batch ), ARRAY_A );
			foreach ( $rows as $row ) {
				$cursor = (int) $row['id'];
				$this->storage->write( $handle, (string) wp_json_encode( $row, JSON_UNESCAPED_SLASHES ) . "\n" );
				$objects[ (int) $row['object_id'] ] = true;
				++$count;
			}
			$read = count( $rows );
		} while ( $read === $batch );
		$this->storage->close( $handle );

		return array( $count, $objects );
	}

	/**
	 * Writes exports of items without trashing them (WP-CLI `export`).
	 *
	 * @param array  $requested Items: array of array( type, name ).
	 * @param string $output    Folder. Default: a new folder under exports.
	 * @return string[]|WP_Error Files written.
	 */
	public function export_only( array $requested, string $output = '' ) {
		$dir = '' !== $output ? $output : $this->storage->dir( 'exports' ) . '/manual-' . gmdate( 'Ymd-His' );
		wp_mkdir_p( $dir );
		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'axle_leftovers_export', Messages::get( 'export_failed', $dir ) );
		}

		$files = array();
		foreach ( $requested as $request ) {
			$type = (string) ( $request['type'] ?? '' );
			$name = (string) ( $request['name'] ?? '' );
			if ( Core::is_protected( $type, $name ) ) {
				return new WP_Error( 'axle_leftovers_protected', Messages::get( 'protected', $name ) );
			}
			if ( ! $this->exists( $type, $name ) ) {
				return new WP_Error( 'axle_leftovers_missing', Messages::get( 'missing', $name ) );
			}

			$base = $dir . '/' . $type . '-' . sanitize_file_name( Attribution::bare_name( $type, $name ) );
			$data = null;
			switch ( $type ) {
				case 'table':
					$ok      = $this->export_table( $name, $base . '.sql' );
					$files[] = $base . '.sql';
					if ( ! $ok ) {
						return new WP_Error( 'axle_leftovers_export', Messages::get( 'export_failed', $name ) );
					}
					continue 2;
				case 'postmeta':
				case 'usermeta':
				case 'termmeta':
				case 'commentmeta':
					if ( null === $this->copy_meta( $type, $name, $base . '.jsonl' ) ) {
						return new WP_Error( 'axle_leftovers_export', Messages::get( 'export_failed', $name ) );
					}
					$files[] = $base . '.jsonl';
					continue 2;
				case 'option':
				case 'sitemeta':
					$data = array(
						'type' => $type,
						'rows' => $this->option_rows( $type, $name ),
					);
					break;
				case 'cron':
					$data = array(
						'hook'   => $name,
						'events' => $this->inventory->cron_events()[ $name ] ?? array(),
					);
					break;
				case 'role':
					$data = array( 'role' => $name ) + ( $this->inventory->roles()[ $name ] ?? array() );
					break;
				case 'cap':
					$data = array(
						'cap'   => $name,
						'roles' => $this->inventory->caps()[ $name ] ?? array(),
					);
					break;
				case 'folder':
					$data = array(
						'folder' => $name,
						'size'   => Inventory::folder_size( $this->storage->uploads_base() . '/' . $name ),
					);
					break;
			}
			if ( null === $data || ! $this->storage->put( $base . '.json', (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
				return new WP_Error( 'axle_leftovers_export', Messages::get( 'export_failed', $name ) );
			}
			$files[] = $base . '.json';
		}

		return $files;
	}

	/**
	 * Uploads folder: a file list exported, then the folder renamed into the trash.
	 *
	 * @param int    $id   Trash row ID.
	 * @param string $name Folder name.
	 * @param string $file Export path.
	 * @return array|WP_Error
	 */
	private function trash_folder( int $id, string $name, string $file ) {
		$base = $this->storage->uploads_base();
		$from = $base . '/' . $name;
		if ( false !== strpbrk( $name, '/\\' ) || is_link( $from ) || ! Storage::is_inside( $from, $base ) ) {
			return new WP_Error( 'axle_leftovers_unsafe', Messages::get( 'unsafe', $name ) );
		}

		$list     = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $entry ) {
			if ( $entry->isFile() ) {
				$list[] = array(
					'file'  => substr( wp_normalize_path( $entry->getPathname() ), strlen( $from ) + 1 ),
					'bytes' => (int) $entry->getSize(),
				);
				if ( count( $list ) >= 5000 ) {
					break;
				}
			}
		}
		if ( ! $this->storage->put(
			$this->storage->dir( 'exports' ) . '/' . substr( $file, 8 ),
			(string) wp_json_encode(
				array(
					'folder' => $name,
					'files'  => $list,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES 
			) 
		) ) {
			return new WP_Error( 'axle_leftovers_export', Messages::get( 'export_failed', $name ) );
		}

		$target = 'trash/' . $id . '/' . $name;
		$this->storage->dir( 'trash' );
		if ( ! $this->storage->move( $from, $this->storage->dir() . '/' . $target ) ) {
			return new WP_Error( 'axle_leftovers_move', Messages::get( 'failed', $name ) );
		}

		return array(
			'payload'  => array( 'files' => count( $list ) ),
			'location' => $target,
			'export'   => $file,
		);
	}

	/**
	 * Role: the definition stored, then removed.
	 *
	 * @param string $role Role.
	 * @param string $file Export path.
	 * @return array|WP_Error
	 */
	private function trash_role( string $role, string $file ) {
		$roles = $this->inventory->roles();
		if ( ! isset( $roles[ $role ] ) ) {
			return new WP_Error( 'axle_leftovers_missing', Messages::get( 'missing', $role ) );
		}

		$payload = array(
			'role'         => $role,
			'name'         => (string) $roles[ $role ]['name'],
			'capabilities' => (array) $roles[ $role ]['capabilities'],
		);
		if ( ! $this->storage->put( $this->storage->dir( 'exports' ) . '/' . substr( $file, 8 ), (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
			return new WP_Error( 'axle_leftovers_export', Messages::get( 'export_failed', $role ) );
		}
		remove_role( $role );

		return array(
			'payload'  => $payload,
			'location' => '',
			'export'   => $file,
		);
	}

	/**
	 * Capability: removed from every role that has it; the list stored.
	 *
	 * @param string $cap  Capability.
	 * @param string $file Export path.
	 * @return array|WP_Error
	 */
	private function trash_cap( string $cap, string $file ) {
		$holders = $this->inventory->caps()[ $cap ] ?? array();
		if ( ! $holders ) {
			return new WP_Error( 'axle_leftovers_missing', Messages::get( 'missing', $cap ) );
		}

		$payload = array(
			'cap'   => $cap,
			'roles' => $holders,
		);
		if ( ! $this->storage->put( $this->storage->dir( 'exports' ) . '/' . substr( $file, 8 ), (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
			return new WP_Error( 'axle_leftovers_export', Messages::get( 'export_failed', $cap ) );
		}
		foreach ( array_keys( $holders ) as $role ) {
			$object = get_role( (string) $role );
			if ( $object ) {
				$object->remove_cap( $cap );
			}
		}

		return array(
			'payload'  => $payload,
			'location' => '',
			'export'   => $file,
		);
	}

	/**
	 * Puts one trashed item back.
	 *
	 * @param array $row Trash row.
	 * @return true|WP_Error
	 */
	private function restore_row( array $row ) {
		global $wpdb;

		$payload = is_array( $row['payload'] ) ? $row['payload'] : array();
		$name    = (string) $row['name'];

		switch ( $row['item_type'] ) {
			case 'table':
				if ( $this->inventory->table_exists( $name ) ) {
					return new WP_Error( 'axle_leftovers_taken', Messages::get( 'taken', $name ) );
				}
				if ( ! $this->inventory->table_exists( (string) $row['trash_location'] ) ) {
					return new WP_Error( 'axle_leftovers_gone', Messages::get( 'gone', $name ) );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Renaming a trashed table back.
				return false === $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', (string) $row['trash_location'], $name ) ) ? new WP_Error( 'axle_leftovers_rename', Messages::get( 'failed', $name ) ) : true;

			case 'option':
			case 'sitemeta':
				foreach ( (array) ( $payload['rows'] ?? array() ) as $data ) {
					if ( $this->exists( (string) $row['item_type'], (string) ( $data['option_name'] ?? $data['meta_key'] ?? '' ) ) ) {
						return new WP_Error( 'axle_leftovers_taken', Messages::get( 'taken', $name ) );
					}
				}
				foreach ( (array) ( $payload['rows'] ?? array() ) as $data ) {
					if ( 'sitemeta' === $row['item_type'] ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Re-inserting the raw row.
						$wpdb->insert(
							$wpdb->sitemeta,
							array(
								'site_id'    => get_current_network_id(),
								'meta_key'   => (string) $data['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row.
								'meta_value' => (string) $data['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One row.
							)
						);
						wp_cache_delete( get_current_network_id() . ':' . $data['meta_key'], 'site-options' );
						wp_cache_delete( get_current_network_id() . ':notoptions', 'site-options' );
					} else {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Re-inserting the raw row keeps the value and autoload exactly.
						$wpdb->insert(
							$wpdb->options,
							array(
								'option_name'  => (string) $data['option_name'],
								'option_value' => (string) $data['option_value'],
								'autoload'     => (string) $data['autoload'],
							)
						);
						wp_cache_delete( (string) $data['option_name'], 'options' );
					}
				}
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				return true;

			case 'cron':
				$crons = (array) _get_cron_array();
				$now   = time();
				foreach ( (array) ( $payload['events'] ?? array() ) as $event ) {
					$interval  = (int) ( $event['interval'] ?? 0 );
					$timestamp = (int) $event['timestamp'];
					if ( $timestamp <= $now ) {
						$timestamp = $now + ( $interval > 0 ? $interval : MINUTE_IN_SECONDS );
					}
					$args  = (array) ( $event['args'] ?? array() );
					$entry = array(
						'schedule' => $event['schedule'] ?? false,
						'args'     => $args,
					);
					if ( false !== $entry['schedule'] ) {
						$entry['interval'] = $interval;
					}
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The cron array keys events by md5( serialize( $args ) ), as core does.
					$crons[ $timestamp ][ $name ][ md5( serialize( $args ) ) ] = $entry;
				}
				ksort( $crons );
				_set_cron_array( $crons );
				return true;

			case 'postmeta':
			case 'usermeta':
			case 'termmeta':
			case 'commentmeta':
				return $this->restore_meta( (string) $row['item_type'], $name, (string) ( $payload['file'] ?? '' ) );

			case 'folder':
				$to   = $this->storage->uploads_base() . '/' . $name;
				$from = $this->storage->dir() . '/' . (string) $row['trash_location'];
				if ( file_exists( $to ) ) {
					return new WP_Error( 'axle_leftovers_taken', Messages::get( 'taken', $name ) );
				}
				if ( ! is_dir( $from ) ) {
					return new WP_Error( 'axle_leftovers_gone', Messages::get( 'gone', $name ) );
				}
				if ( ! $this->storage->move( $from, $to ) ) {
					return new WP_Error( 'axle_leftovers_move', Messages::get( 'failed', $name ) );
				}
				$this->storage->delete( dirname( $from ) );
				return true;

			case 'role':
				if ( get_role( $name ) ) {
					return new WP_Error( 'axle_leftovers_taken', Messages::get( 'taken', $name ) );
				}
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Restores a role exactly as it was; this is not VIP-only code.
				add_role( $name, (string) ( $payload['name'] ?? $name ), (array) ( $payload['capabilities'] ?? array() ) );
				return true;

			case 'cap':
				foreach ( (array) ( $payload['roles'] ?? array() ) as $role => $grant ) {
					$object = get_role( (string) $role );
					if ( $object ) {
						$object->add_cap( $name, (bool) $grant );
					}
				}
				return true;
		}

		return new WP_Error( 'axle_leftovers_type', Messages::get( 'bad_type', (string) $row['item_type'] ) );
	}

	/**
	 * Re-inserts meta rows from the export, in batches, with their original IDs.
	 *
	 * @param string $type Meta type.
	 * @param string $key  Meta key.
	 * @param string $file Export path, relative to the storage folder.
	 * @return true|WP_Error
	 */
	private function restore_meta( string $type, string $key, string $file ) {
		global $wpdb;

		$meta = self::meta_table( $type );
		$path = $this->storage->dir() . '/' . $file;
		if ( '' === $file || ! is_file( $path ) ) {
			return new WP_Error( 'axle_leftovers_gone', Messages::get( 'gone', $key ) );
		}
		if ( $this->exists( $type, $key ) ) {
			return new WP_Error( 'axle_leftovers_taken', Messages::get( 'taken', $key ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read line by line; the file can be large.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return new WP_Error( 'axle_leftovers_gone', Messages::get( 'gone', $key ) );
		}

		$batch   = Settings::meta_batch_size();
		$rows    = array();
		$objects = array();
		$insert  = static function ( array $rows ) use ( $wpdb, $meta ): void {
			foreach ( $rows as $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Re-inserting trashed meta rows with their original IDs.
				$wpdb->insert(
					$meta['table'],
					array(
						$meta['id']     => (int) $row['id'],
						$meta['object'] => (int) $row['object_id'],
						'meta_key'      => (string) $row['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row.
						'meta_value'    => (string) $row['meta_value'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One row.
					)
				);
			}
		};

		while ( true ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}
			$row = json_decode( $line, true );
			if ( ! is_array( $row ) || ( $row['meta_key'] ?? null ) !== $key ) {
				continue;
			}
			$rows[]                             = $row;
			$objects[ (int) $row['object_id'] ] = true;
			if ( count( $rows ) >= $batch ) {
				$insert( $rows );
				$rows = array();
			}
		}
		if ( $rows ) {
			$insert( $rows );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See fopen() above.
		fclose( $handle );

		wp_cache_delete_multiple( array_keys( $objects ), $meta['cache'] );

		return true;
	}

	/**
	 * Deletes one trashed item and, unless kept, its export.
	 *
	 * @param array $row  Trash row.
	 * @param bool  $keep Keep exports.
	 */
	private function purge_row( array $row, bool $keep ): void {
		global $wpdb;

		if ( 'table' === $row['item_type'] && 0 === strpos( (string) $row['trash_location'], $wpdb->prefix . 'axlt_' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Purging a table the plugin renamed into its trash.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', (string) $row['trash_location'] ) );
		} elseif ( 'folder' === $row['item_type'] && '' !== (string) $row['trash_location'] ) {
			$folder = $this->storage->dir() . '/' . dirname( (string) $row['trash_location'] );
			if ( Storage::is_inside( $folder, $this->storage->dir( 'trash' ) ) ) {
				$this->storage->delete( $folder );
			}
		}
		if ( ! $keep ) {
			$this->delete_export( $row );
		}

		$this->update_row(
			(int) $row['id'],
			array(
				'status'      => 'purged',
				'payload'     => '',
				'export_path' => $keep ? (string) $row['export_path'] : '',
			)
		);
	}

	/**
	 * Deletes a row's export file and its batch folder once empty.
	 *
	 * @param array $row Trash row.
	 */
	private function delete_export( array $row ): void {
		$file = $this->export_file( $row );
		if ( '' === $file ) {
			return;
		}
		$this->storage->delete( $file );
		$folder = dirname( $file );
		if ( Storage::is_inside( $folder, $this->storage->dir( 'exports' ) ) && 2 >= count( (array) scandir( $folder ) ) ) {
			$this->storage->delete( $folder );
		}
	}

	/**
	 * Updates a trash row.
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields Fields.
	 */
	private function update_row( int $id, array $fields ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->update( Schema::table( 'trash' ), $fields, array( 'id' => $id ) );
	}

	/**
	 * Deletes a trash row.
	 *
	 * @param int $id Row ID.
	 */
	private function delete_row( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->delete( Schema::table( 'trash' ), array( 'id' => $id ) );
	}

	/**
	 * Decodes a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function hydrate( array $row ): array {
		$payload        = json_decode( (string) ( $row['payload'] ?? '' ), true );
		$row['id']      = (int) $row['id'];
		$row['payload'] = is_array( $payload ) ? $payload : array();

		return $row;
	}

	/**
	 * A row without its payload, for output.
	 *
	 * @param array $row Row.
	 * @return array{id: int, batch: string, type: string, name: string, owner: string, confidence: string, bytes: int, export: string, status: string, trashed_gmt: string}
	 */
	public static function public_row( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'batch'       => (string) $row['batch_id'],
			'type'        => (string) $row['item_type'],
			'name'        => (string) $row['name'],
			'owner'       => (string) $row['owner_slug'],
			'confidence'  => (string) $row['confidence'],
			'bytes'       => (int) $row['size_bytes'],
			'export'      => (string) $row['export_path'],
			'status'      => (string) $row['status'],
			'trashed_gmt' => (string) $row['trashed_gmt'],
		);
	}
}
