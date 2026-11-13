<?php
/**
 * Leftover Theme: creates its data once, the first time it runs, and has no clean-up.
 *
 * @package AryanX\Axle\Leftovers
 */

// phpcs:ignoreFile

add_action(
	'init',
	static function () {
		if ( get_option( 'lt_version' ) ) {
			return;
		}

		add_option( 'lt_settings', array( 'layout' => 'wide', 'sidebar' => 'left' ) );
		add_option( 'lt_version', '2.0.0' );
		add_option( 'lt_cache', str_repeat( 'x', 100 * 1024 ), '', 'yes' );
		set_transient( 'lt_feed', array( 'fetched' => 1 ) );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'lt_refresh' );
		set_theme_mod( 'lt_logo_width', 120 );
		foreach ( get_posts( array( 'numberposts' => 5, 'post_type' => 'post', 'post_status' => 'any', 'fields' => 'ids' ) ) as $post_id ) {
			update_post_meta( $post_id, '_lt_layout', 'wide' );
		}
		wp_update_custom_css_post( 'body { background: #fff; }' );
	}
);

add_action( 'lt_refresh', '__return_null' );
