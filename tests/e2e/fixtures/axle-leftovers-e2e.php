<?php
/**
 * Plugin Name: Axle Leftovers e2e helpers
 * Description: Test-only: application passwords on the plain HTTP test site. Copied into mu-plugins by the tests.
 *
 * @package AryanX\Axle\Leftovers
 */

// phpcs:ignoreFile

add_filter( 'wp_is_application_passwords_available', '__return_true' );
