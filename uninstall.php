<?php
/**
 * Removes Axle Leftovers' tables, options, schedules and folder, on every
 * site. Items still in the trash are purged first (or restored, when the
 * `axle_leftovers_uninstall_trash_policy` filter says `restore`), because
 * their data lives in the tables and folder that go next.
 *
 * @package AryanX\Axle\Leftovers
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/axle-leftovers.php';

/**
 * Removes Axle Leftovers data from the current site.
 */
function axle_leftovers_uninstall_site(): void {
	global $wpdb;

	$tables = array( $wpdb->prefix . 'axle_leftovers_trash', $wpdb->prefix . 'axle_leftovers_ledger', $wpdb->prefix . 'axle_leftovers_seen' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Whether this site has the trash table at all.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tables[0] ) ) ) ) {
		$inventory = new \AryanX\Axle\Leftovers\Inventory();
		$ledger    = new \AryanX\Axle\Leftovers\Ledger();
		$scanner   = new \AryanX\Axle\Leftovers\Scanner( $ledger, $inventory, new \AryanX\Axle\Leftovers\Attribution( $ledger, new \AryanX\Axle\Leftovers\Dataset() ), new \AryanX\Axle\Leftovers\References(), new \AryanX\Axle\Leftovers\Recorder( $ledger, $inventory ) );
		$trash     = new \AryanX\Axle\Leftovers\Trash( $scanner, $inventory, new \AryanX\Axle\Leftovers\Storage() );
		if ( 'restore' === \AryanX\Axle\Leftovers\Settings::uninstall_trash_policy() ) {
			foreach ( array_keys( $trash->batches( 'site' ) + $trash->batches( 'network' ) ) as $axle_leftovers_batch ) {
				$trash->restore( (string) $axle_leftovers_batch );
			}
		}
		$trash->purge();
	}

	wp_clear_scheduled_hook( 'axle_leftovers_daily' );
	wp_clear_scheduled_hook( 'axle_leftovers_scan_step' );
	delete_option( 'axle_leftovers_settings' );
	delete_option( 'axle_leftovers_scan' );
	delete_option( 'axle_leftovers_db_version' );
	delete_option( 'axle_leftovers_lookups' );
	delete_option( 'axle_leftovers_dataset_meta' );

	// Per-user notices and messages that may still be waiting.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_axle_leftovers_' ) . '%', $wpdb->esc_like( '_transient_timeout_axle_leftovers_' ) . '%' ) );

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	$uploads = wp_upload_dir( null, false );
	$folder  = untrailingslashit( (string) $uploads['basedir'] ) . '/axle-leftovers';
	if ( is_dir( $folder ) && ! is_link( $folder ) ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		( new WP_Filesystem_Direct( null ) )->delete( $folder, true );
	}
}

if ( is_multisite() ) {
	$axle_leftovers_offset = 0;
	do {
		$axle_leftovers_sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
				'offset' => $axle_leftovers_offset,
			)
		);

		foreach ( $axle_leftovers_sites as $axle_leftovers_site ) {
			switch_to_blog( $axle_leftovers_site );
			axle_leftovers_uninstall_site();
			restore_current_blog();
		}

		$axle_leftovers_offset += 100;
	} while ( $axle_leftovers_sites );
} else {
	axle_leftovers_uninstall_site();
}
