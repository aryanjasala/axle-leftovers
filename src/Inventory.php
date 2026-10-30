<?php
/**
 * Lists what exists on the site right now, cheaply.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Names and sizes of tables, options, cron events, roles, capabilities and
 * uploads folders. Used for the before and after snapshots around plugin
 * activation and uninstall, by the scan, and to check an item still exists.
 */
class Inventory {

	/**
	 * A snapshot of names by type, for diffs.
	 *
	 * @return array<string, string[]>
	 */
	public function names(): array {
		return array(
			'table'  => $this->tables(),
			'option' => $this->option_names(),
			'cron'   => array_keys( $this->cron_events() ),
			'role'   => array_keys( $this->roles() ),
			'cap'    => array_keys( $this->caps() ),
			'folder' => $this->folders(),
		);
	}

	/**
	 * What is in the second snapshot and not the first, by type.
	 *
	 * @param array $before Snapshot.
	 * @param array $after  Snapshot.
	 * @return array<string, string[]>
	 */
	public static function diff( array $before, array $after ): array {
		$out = array();
		foreach ( $after as $type => $names ) {
			$new = array_values( array_diff( (array) $names, (array) ( $before[ $type ] ?? array() ) ) );
			if ( $new ) {
				$out[ (string) $type ] = $new;
			}
		}

		return $out;
	}

	/**
	 * Tables with this site's prefix.
	 *
	 * @return string[]
	 */
	public function tables(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Listing tables has no API; the result changes with the action being checked.
		$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( (string) $wpdb->prefix ) . '%' ) );

		return array_values( array_map( 'strval', $tables ) );
	}

	/**
	 * Whether a table exists, by exact name.
	 *
	 * @param string $name Table.
	 * @return bool
	 */
	public function table_exists( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be live: checked right before a rename.
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}

	/**
	 * Rows and bytes of tables.
	 *
	 * @param string[] $names Tables.
	 * @return array<string, array{rows: int, bytes: int}>
	 */
	public function table_stats( array $names ): array {
		global $wpdb;

		$out = array();
		if ( ! $names ) {
			return $out;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table sizes have no API.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( (string) $wpdb->prefix ) . '%' ), ARRAY_A );
		foreach ( $rows as $row ) {
			$name = (string) ( $row['Name'] ?? '' );
			if ( in_array( $name, $names, true ) ) {
				$out[ $name ] = array(
					'rows'  => (int) ( $row['Rows'] ?? 0 ),
					'bytes' => (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ),
				);
			}
		}

		// InnoDB only estimates Rows; count the small ones exactly.
		foreach ( $out as $name => $stats ) {
			if ( $stats['rows'] < 100000 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact row count of a table from the scan.
				$out[ $name ]['rows'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $name ) );
			}
		}

		return $out;
	}

	/**
	 * Every option name.
	 *
	 * @return string[]
	 */
	public function option_names(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A snapshot of names for a before and after diff.
		$names = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_timeout_' ) . '%', $wpdb->esc_like( '_site_transient_timeout_' ) . '%' ) );

		return array_values( array_map( 'strval', $names ) );
	}

	/**
	 * Autoload values that load on every request.
	 *
	 * @return string[]
	 */
	public static function autoload_values(): array {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			return array_map( 'strval', (array) wp_autoload_values_to_autoload() );
		}

		return array( 'yes', 'on', 'auto-on', 'auto' );
	}

	/**
	 * Cron events grouped by hook.
	 *
	 * @return array<string, array<int, array{timestamp: int, args: array, schedule: string|false, interval: int}>>
	 */
	public function cron_events(): array {
		$out   = array();
		$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		foreach ( (array) $crons as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				foreach ( (array) $events as $event ) {
					$out[ (string) $hook ][] = array(
						'timestamp' => (int) $timestamp,
						'args'      => isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array(),
						'schedule'  => $event['schedule'] ?? false,
						'interval'  => (int) ( $event['interval'] ?? 0 ),
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Roles with their names and capabilities.
	 *
	 * @return array<string, array{name: string, capabilities: array}>
	 */
	public function roles(): array {
		return wp_roles()->roles;
	}

	/**
	 * Capabilities on roles, with the roles that have them and the grant.
	 *
	 * @return array<string, array<string, bool>> Capability => role => grant.
	 */
	public function caps(): array {
		$out = array();
		foreach ( $this->roles() as $role => $data ) {
			foreach ( (array) $data['capabilities'] as $cap => $grant ) {
				$out[ (string) $cap ][ (string) $role ] = (bool) $grant;
			}
		}

		return $out;
	}

	/**
	 * Users who have a role, up to a limit.
	 *
	 * @param string $role  Role.
	 * @param int    $limit Most users returned.
	 * @return string[] Logins.
	 */
	public function users_with_role( string $role, int $limit = 20 ): array {
		$users = get_users(
			array(
				'role'    => $role,
				'number'  => $limit,
				'fields'  => array( 'user_login' ),
				'blog_id' => get_current_blog_id(),
			)
		);

		return array_values(
			array_map(
				static function ( $user ): string {
					return (string) $user->user_login;
				},
				$users
			)
		);
	}

	/**
	 * The uploads base folder, without a trailing slash.
	 *
	 * @return string
	 */
	public function uploads_base(): string {
		$uploads = wp_upload_dir( null, false );

		return untrailingslashit( (string) $uploads['basedir'] );
	}

	/**
	 * Top-level folders in uploads.
	 *
	 * @return string[]
	 */
	public function folders(): array {
		$base = $this->uploads_base();
		$out  = array();
		if ( ! is_dir( $base ) ) {
			return $out;
		}

		foreach ( (array) scandir( $base ) as $name ) {
			$name = (string) $name;
			if ( '' === $name || '.' === $name[0] ) {
				continue;
			}
			$path = $base . '/' . $name;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * Size and file count of an uploads folder, counting up to a limit.
	 *
	 * @param string $path  Folder.
	 * @param int    $limit Most files counted.
	 * @return array{bytes: int, files: int}
	 */
	public static function folder_size( string $path, int $limit = 20000 ): array {
		$bytes = 0;
		$files = 0;
		if ( ! is_dir( $path ) ) {
			return array(
				'bytes' => 0,
				'files' => 0,
			);
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$bytes += (int) $file->getSize();
				++$files;
				if ( $files >= $limit ) {
					break;
				}
			}
		}

		return array(
			'bytes' => $bytes,
			'files' => $files,
		);
	}
}
