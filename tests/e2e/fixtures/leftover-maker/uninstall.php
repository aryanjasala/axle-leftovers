<?php
/**
 * Removes only the version option, and forgets everything else.
 *
 * @package AryanX\Axle\Leftovers
 */

// phpcs:ignoreFile

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lm_version' );
