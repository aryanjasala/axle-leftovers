<?php
/**
 * The ledger of plugins seen and the first-seen record of items.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `{prefix}axle_leftovers_ledger` (one row per plugin ever
 * seen, with its code signature, kept after the plugin is deleted) and
 * `{prefix}axle_leftovers_seen` (one row per item name, with the plugin that
 * created it).
 */
class Ledger {

	public const SOURCES = array( 'static', 'activation', 'runtime', 'uninstall-diff', 'dataset' );

	/**
	 * Seen names per type, loaded once per request.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $seen_names = array();

	/**
	 * The slug for a plugin file: its folder, or the file name for a single-file plugin.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins folder.
	 * @return string
	 */
	public static function slug_for( string $plugin_file ): string {
		$plugin_file = str_replace( '\\', '/', $plugin_file );
		$dir         = dirname( $plugin_file );

		return '.' === $dir || '' === $dir ? basename( $plugin_file, '.php' ) : (string) strtok( $plugin_file, '/' );
	}

	/**
	 * Adds or updates a plugin's ledger entry.
	 *
	 * @param string     $plugin_file Plugin file.
	 * @param array      $data        name, version.
	 * @param string[]   $sources     Sources to add.
	 * @param array|null $signature   New signature, or null to keep the stored one.
	 * @return array The entry.
	 */
	public function record_plugin( string $plugin_file, array $data, array $sources = array(), ?array $signature = null ): array {
		global $wpdb;

		$slug     = self::slug_for( $plugin_file );
		$existing = $this->get( $slug );
		$now      = current_time( 'mysql', true );
		$version  = (string) ( $data['version'] ?? ( $existing['version'] ?? '' ) );
		$merged   = array_values( array_intersect( self::SOURCES, array_unique( array_merge( $existing ? $existing['sources'] : array(), $sources ) ) ) );

		$row = array(
			'plugin_file'   => $plugin_file,
			'slug'          => $slug,
			'name'          => (string) ( $data['name'] ?? ( $existing['name'] ?? $slug ) ),
			'version'       => $version,
			'last_seen_gmt' => $now,
			'removed_gmt'   => null,
			'removed_how'   => '',
			'sources'       => implode( ',', $merged ),
		);
		if ( null !== $signature ) {
			$signature = Signature::compact( $signature );

			/**
			 * Filters a plugin's code signature before it is stored in the ledger.
			 *
			 * @param array  $signature Key => names: t tables, o options, c cron, m meta, r roles and caps, u uploads, p prefixes, n uninstall.php names.
			 * @param string $slug      Plugin slug.
			 * @param string $version   Plugin version.
			 */
			$filtered                 = (array) apply_filters( 'axle_leftovers_signature', $signature, $slug, $version );
			$row['signature']         = (string) wp_json_encode( Signature::compact( $filtered ) );
			$row['signature_version'] = $version;
		}

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
			$wpdb->update( Schema::table( 'ledger' ), $row, array( 'id' => $existing['id'] ) );
		} else {
			$row['first_seen_gmt'] = $now;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
			$wpdb->insert( Schema::table( 'ledger' ), $row );
		}

		$entry = (array) $this->get( $slug );

		/**
		 * Fires after a plugin's ledger entry is added or updated.
		 *
		 * @param array $entry  The entry: slug, name, version, sources, signature, ...
		 * @param bool  $is_new Whether the plugin was seen for the first time.
		 */
		do_action( 'axle_leftovers_plugin_recorded', $entry, ! $existing );

		return $entry;
	}

	/**
	 * Marks a plugin as removed.
	 *
	 * @param string $slug Slug.
	 * @param string $how  admin, cli or outside.
	 */
	public function mark_removed( string $slug, string $how ): void {
		global $wpdb;

		$entry = $this->get( $slug );
		if ( ! $entry || null !== $entry['removed_gmt'] ) {
			return;
		}

		$how = in_array( $how, array( 'admin', 'cli', 'outside' ), true ) ? $how : 'admin';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->update(
			Schema::table( 'ledger' ),
			array(
				'removed_gmt' => current_time( 'mysql', true ),
				'removed_how' => $how,
			),
			array( 'id' => $entry['id'] )
		);

		/**
		 * Fires when a plugin in the ledger is found deleted.
		 *
		 * @param array  $entry The ledger entry before the change.
		 * @param string $how   admin, cli, or outside (gone from the plugins folder without WordPress deleting it).
		 */
		do_action( 'axle_leftovers_plugin_removed', $entry, $how );
	}

	/**
	 * One ledger entry.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public function get( string $slug ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; entries change on every scan.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', Schema::table( 'ledger' ), $slug ), ARRAY_A );

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Every ledger entry, by slug.
	 *
	 * @return array<string, array>
	 */
	public function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, bounded by plugins ever installed.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name', Schema::table( 'ledger' ) ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$entry                 = self::hydrate( (array) $row );
			$out[ $entry['slug'] ] = $entry;
		}

		return $out;
	}

	/**
	 * Turns a row into an entry with decoded fields.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function hydrate( array $row ): array {
		$signature        = json_decode( (string) ( $row['signature'] ?? '' ), true );
		$row['id']        = (int) $row['id'];
		$row['signature'] = is_array( $signature ) ? Signature::normalize( $signature ) : null;
		$row['sources']   = array_values( array_filter( explode( ',', (string) $row['sources'] ) ) );

		return $row;
	}

	/**
	 * Records who created an item, the first time it is seen. Later sightings are ignored.
	 *
	 * @param string $type     Item type.
	 * @param string $name     Item name.
	 * @param string $owner    Owner slug, or `theme:<stylesheet>`.
	 * @param string $evidence activation, runtime or uninstall-diff.
	 * @return bool Whether it was new.
	 */
	public function record_seen( string $type, string $name, string $owner, string $evidence ): bool {
		global $wpdb;

		if ( '' === $owner || '' === $name || strlen( $name ) > 191 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; INSERT IGNORE keeps the first owner.
		$inserted = (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (item_type, name, owner_slug, first_seen_gmt, evidence) VALUES (%s, %s, %s, %s, %s)', Schema::table( 'seen' ), $type, $name, $owner, current_time( 'mysql', true ), $evidence ) );
		if ( isset( $this->seen_names[ $type ] ) ) {
			$this->seen_names[ $type ][ $name ] = true;
		}
		if ( $inserted < 1 ) {
			return false;
		}

		/**
		 * Fires when an item is recorded with its owner for the first time.
		 *
		 * @param string $type     Item type.
		 * @param string $name     Item name.
		 * @param string $owner    Owner slug, or `theme:<stylesheet>`.
		 * @param string $evidence activation, runtime or uninstall-diff.
		 */
		do_action( 'axle_leftovers_item_first_seen', $type, $name, $owner, $evidence );

		return true;
	}

	/**
	 * Whether an item name was already recorded. Loads the names of a type once per request.
	 *
	 * @param string $type Item type.
	 * @param string $name Name.
	 * @return bool
	 */
	public function is_seen( string $type, string $name ): bool {
		global $wpdb;

		if ( ! isset( $this->seen_names[ $type ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, bounded by distinct names.
			$names                     = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT name FROM %i WHERE item_type = %s', Schema::table( 'seen' ), $type ) );
			$this->seen_names[ $type ] = array_fill_keys( array_map( 'strval', $names ), true );
		}

		return isset( $this->seen_names[ $type ][ $name ] );
	}

	/**
	 * Every seen record, by type and name.
	 *
	 * @return array<string, array<string, array{owner: string, first_seen: string, evidence: string}>>
	 */
	public function seen_all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, bounded by distinct names.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT item_type, name, owner_slug, first_seen_gmt, evidence FROM %i', Schema::table( 'seen' ) ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['item_type'] ][ (string) $row['name'] ] = array(
				'owner'      => (string) $row['owner_slug'],
				'first_seen' => (string) $row['first_seen_gmt'],
				'evidence'   => (string) $row['evidence'],
			);
		}

		return $out;
	}

	/**
	 * Forgets the per-request cache.
	 */
	public function flush(): void {
		$this->seen_names = array();
	}
}
