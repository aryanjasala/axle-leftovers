<?php
/**
 * Plugin Name: Leftover Maker
 * Description: Test fixture for Axle Leftovers. On activation it creates a table, options, a transient, cron events, post meta, an uploads folder, a role and a capability. Its uninstall.php removes only one option, like many real plugins.
 * Version: 1.2.0
 *
 * @package AryanX\Axle\Leftovers
 */

// phpcs:ignoreFile

register_activation_hook(
	__FILE__,
	static function () {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$wpdb->prefix}lm_items ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, label varchar(100) NOT NULL default '', email varchar(100) NOT NULL default '', PRIMARY KEY  (id) ) {$wpdb->get_charset_collate()};" );
		for ( $i = 1; $i <= 50; $i++ ) {
			$wpdb->insert( $wpdb->prefix . 'lm_items', array( 'label' => 'Item ' . $i, 'email' => 'person' . $i . '@example.com' ) );
		}

		add_option( 'lm_settings', array( 'mode' => 'live', 'items' => 50 ) );
		add_option( 'lm_version', '1.2.0' );
		add_option( 'lm_cache', str_repeat( 'x', 200 * 1024 ), '', 'yes' );
		add_option( 'lm_flags', array( 'beta' => true ), '', 'no' );
		add_option( 'lm_installed_at', '2025-04-02 10:00:00' );
		set_transient( 'lm_remote_data', array( 'fetched' => 1 ) );

		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lm_daily_sync' );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'lm_cleanup', array( 'deep' ) );

		foreach ( get_posts( array( 'numberposts' => 20, 'post_type' => 'post', 'post_status' => 'any', 'fields' => 'ids' ) ) as $post_id ) {
			update_post_meta( $post_id, '_lm_flag', 'on' );
		}

		$uploads = wp_upload_dir();
		wp_mkdir_p( $uploads['basedir'] . '/leftover-maker/cache' );
		file_put_contents( $uploads['basedir'] . '/leftover-maker/report.csv', "id,label\n1,Item 1\n" );
		file_put_contents( $uploads['basedir'] . '/leftover-maker/cache/data.json', '{"ok":true}' );

		add_role( 'lm_manager', 'Leftover Manager', array( 'read' => true, 'lm_manage' => true ) );
		get_role( 'administrator' )->add_cap( 'lm_manage' );
	}
);

add_action( 'lm_daily_sync', '__return_null' );
add_action( 'lm_cleanup', '__return_null' );
