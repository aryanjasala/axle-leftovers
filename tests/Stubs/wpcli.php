<?php
/**
 * Enough of WP-CLI to run the command class in unit tests: output is recorded,
 * and error() throws like the real one exits.
 *
 * @package AryanX\Axle\Leftovers
 */

// phpcs:ignoreFile

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		class WP_CLI {
			/**
			 * Recorded lines: array( level, text ).
			 *
			 * @var array
			 */
			public static $lines = array();

			public static function success( $text ) {
				self::$lines[] = array( 'success', $text );
			}

			public static function warning( $text ) {
				self::$lines[] = array( 'warning', $text );
			}

			public static function log( $text ) {
				self::$lines[] = array( 'log', $text );
			}

			public static function error( $text ) {
				self::$lines[] = array( 'error', $text );
				throw new \RuntimeException( $text );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\format_items' ) ) {
		function format_items( $format, $items, $fields ) {
			\WP_CLI::$lines[] = array( 'table', array( $format, $items, $fields ) );
		}
	}
}
