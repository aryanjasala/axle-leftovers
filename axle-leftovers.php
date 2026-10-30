<?php
/**
 * Plugin Name:       Axle Leftovers
 * Plugin URI:        https://wordpress.org/plugins/axle-leftovers/
 * Description:       Find the tables, options, cron events, meta, roles and uploads folders deleted plugins left behind, see who owns each one and why, and move them to a trash you can restore from.
 * Version:           1.0.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Aryan Jasala
 * Author URI:        https://profiles.wordpress.org/aryanjasala/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       axle-leftovers
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

// PSR-4: AryanX\Axle\Leftovers\Foo\Bar lives in src/Foo/Bar.php.
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Schema::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Schema::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );

/**
 * Who owns a table, option, cron hook, meta key, role, capability or uploads
 * folder, for other plugins (Axle Runtime Doctor's autoload owner column).
 *
 * @param string $type Item type: table, option, cron, postmeta, usermeta, termmeta, commentmeta, folder, role, cap or sitemeta.
 * @param string $name Item name, tables with the prefix.
 * @return array{owner: string, owner_name: string, confidence: string, evidence: array, in_use: bool, in_use_by: string}|null Null before the plugin has loaded.
 */
function owner_of( string $type, string $name ): ?array {
	$plugin = Plugin::instance();

	return $plugin ? $plugin->scanner()->attribution()->attribute( $type, $name ) : null;
}
