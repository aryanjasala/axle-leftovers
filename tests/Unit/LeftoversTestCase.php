<?php
/**
 * Base class for Leftovers unit tests: an in-memory $wpdb and the common
 * WordPress functions.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Core;
use AryanX\Axle\Leftovers\Tests\FakeWpdb;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Stubs/fakes.php';

/**
 * Sets up $wpdb and stubs.
 */
abstract class LeftoversTestCase extends TestCase {

	/**
	 * The fake database.
	 *
	 * @var FakeWpdb
	 */
	protected $db;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb     = new FakeWpdb();
		$this->db = $wpdb;
		Core::flush();

		Functions\stubs(
			array(
				'current_time'          => '2026-10-30 10:00:00',
				'wp_json_encode'        => static function ( $value, $flags = 0 ) {
					return json_encode( $value, $flags );
				},
				'get_current_user_id'   => 1,
				'wp_normalize_path'     => static function ( $path ) {
					return str_replace( '\\', '/', (string) $path );
				},
				'plugin_basename'       => 'axle-leftovers/axle-leftovers.php',
				'is_multisite'          => false,
				'is_main_site'          => true,
				'get_current_network_id' => 1,
				'wp_generate_password'  => 'abcd',
				'untrailingslashit'     => static function ( $path ) {
					return rtrim( (string) $path, '/\\' );
				},
				'trailingslashit'       => static function ( $path ) {
					return rtrim( (string) $path, '/\\' ) . '/';
				},
				'sanitize_file_name'    => static function ( $name ) {
					return preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) $name );
				},
				'is_wp_error'           => static function ( $thing ) {
					return $thing instanceof \WP_Error;
				},
				'wp_next_scheduled'     => false,
				'wp_schedule_single_event' => true,
				'get_theme_root'        => WP_CONTENT_DIR . '/themes',
				'number_format_i18n'    => static function ( $number ) {
					return (string) $number;
				},
			)
		);
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $count ) {
				return 1 === (int) $count ? $single : $plural;
			}
		);
	}
}
