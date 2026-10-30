<?php
/**
 * Core and protected items.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Core;
use AryanX\Axle\Leftovers\Messages;
use Brain\Monkey\Filters;

/**
 * Nothing core or of the plugin's own is ever a leftover.
 */
final class CoreTest extends LeftoversTestCase {

	/**
	 * @dataProvider core_items
	 */
	public function test_core_items_are_protected( string $type, string $name ): void {
		$this->assertTrue( Core::is_protected( $type, $name ) );
	}

	public function core_items(): array {
		return array(
			array( 'table', 'wp_posts' ),
			array( 'table', 'wp_axle_leftovers_trash' ),
			array( 'option', 'siteurl' ),
			array( 'option', 'wp_user_roles' ),
			array( 'option', 'theme_mods_twentytwentyfive' ),
			array( 'option', '_site_transient_update_core' ),
			array( 'option', '_site_transient_feed_abc' ),
			array( 'option', 'axle_leftovers_scan' ),
			array( 'option', '_transient_axle_leftovers_flash_1' ),
			array( 'cron', 'wp_version_check' ),
			array( 'cron', 'axle_leftovers_daily' ),
			array( 'role', 'editor' ),
			array( 'cap', 'edit_posts' ),
			array( 'postmeta', '_edit_lock' ),
			array( 'postmeta', '_wp_attached_file' ),
			array( 'usermeta', 'wp_capabilities' ),
			array( 'usermeta', 'closedpostboxes_post' ),
			array( 'usermeta', 'edit_post_per_page' ),
			array( 'folder', '2026' ),
			array( 'folder', 'axle-leftovers' ),
			array( 'sitemeta', 'site_admins' ),
		);
	}

	public function test_plugin_items_are_not(): void {
		foreach ( array( array( 'table', 'wp_acme_log' ), array( 'option', 'acme_settings' ), array( 'cron', 'acme_sync' ), array( 'folder', 'acme' ), array( 'postmeta', '_acme' ) ) as $item ) {
			$this->assertFalse( Core::is_protected( $item[0], $item[1] ), $item[1] );
		}
	}

	public function test_filters_add_items_and_bad_values_fall_back(): void {
		Filters\expectApplied( 'axle_leftovers_core_items' )->once()->andReturnUsing(
			static function ( $items ) {
				$items['option'][] = 'acme_core';
				$items['table']    = 'not a list';
				return $items;
			}
		);
		Filters\expectApplied( 'axle_leftovers_protected_items' )->once()->andReturnUsing(
			static function ( $items ) {
				$items['cron'][] = 'acme_keep';
				return $items;
			}
		);

		$this->assertTrue( Core::is_protected( 'option', 'acme_core' ) );
		$this->assertTrue( Core::is_protected( 'cron', 'acme_keep' ) );
		$this->assertTrue( Core::is_protected( 'table', 'wp_posts' ) );
	}

	public function test_messages_filter_keeps_placeholders(): void {
		Filters\expectApplied( 'axle_leftovers_messages' )->twice()->andReturn( array( 'missing' => 'Gone: %s!', 'in_use' => 'No placeholders' ) );

		$this->assertSame( 'Gone: acme!', Messages::get( 'missing', 'acme' ) );
		$this->assertSame( 'acme is in use: Acme is installed.', Messages::get( 'in_use', 'acme', 'Acme' ) );
	}
}
