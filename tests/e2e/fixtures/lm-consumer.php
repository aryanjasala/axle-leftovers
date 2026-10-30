<?php
/**
 * Plugin Name: Leftover Maker consumer
 * Description: Test fixture: active code that still reads one of Leftover Maker's options by name, for the reference check. Copied into mu-plugins by the tests.
 *
 * @package AryanX\Axle\Leftovers
 */

// phpcs:ignoreFile

function lm_consumer_mode() {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'lm_settings' ) );
}
