<?php
/**
 * The runtime first-seen ledger.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Inventory;
use AryanX\Axle\Leftovers\Ledger;
use AryanX\Axle\Leftovers\Recorder;
use Brain\Monkey\Functions;

/**
 * Activation diffs, runtime hooks and the call-stack owner.
 */
final class RecorderTest extends LeftoversTestCase {

	public function test_owner_from_trace(): void {
		$frames = array(
			array( 'file' => ABSPATH . 'wp-includes/option.php' ),
			array( 'file' => dirname( __DIR__, 2 ) . '/src/Recorder.php' ),
			array( 'file' => WP_PLUGIN_DIR . '/acme/inc/setup.php' ),
			array( 'file' => WP_PLUGIN_DIR . '/other/other.php' ),
		);
		$this->assertSame( 'acme', Recorder::owner_from_trace( $frames ) );
		$this->assertSame( 'hello', Recorder::owner_from_trace( array( array( 'file' => WP_PLUGIN_DIR . '/hello.php' ) ) ) );
		$this->assertSame( 'mu-plugin:tweaks', Recorder::owner_from_trace( array( array( 'file' => WPMU_PLUGIN_DIR . '/tweaks.php' ) ) ) );
		$this->assertSame( 'theme:twentytwentyfive', Recorder::owner_from_trace( array( array( 'file' => WP_CONTENT_DIR . '/themes/twentytwentyfive/functions.php' ) ) ) );
		$this->assertSame( '', Recorder::owner_from_trace( array( array( 'file' => ABSPATH . 'wp-includes/cron.php' ), array( 'function' => 'x' ) ) ) );
	}

	public function test_activation_diff_and_runtime_items_belong_to_the_plugin(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		$inventory = \Mockery::mock( Inventory::class );
		$inventory->shouldReceive( 'names' )->andReturn(
			array( 'table' => array( 'wp_posts' ), 'option' => array( 'siteurl' ) ),
			array( 'table' => array( 'wp_posts', 'wp_acme_log' ), 'option' => array( 'siteurl', 'acme_a', 'cron' ) )
		);
		$ledger = \Mockery::mock( Ledger::class );
		$ledger->shouldReceive( 'is_seen' )->andReturn( false );
		$ledger->shouldReceive( 'record_seen' )->once()->with( 'postmeta', '_acme_flag', 'acme', 'activation' );
		$ledger->shouldReceive( 'record_seen' )->once()->with( 'table', 'wp_acme_log', 'acme', 'activation' );
		$ledger->shouldReceive( 'record_seen' )->once()->with( 'option', 'acme_a', 'acme', 'activation' );
		$ledger->shouldReceive( 'record_plugin' )->once()->with( 'acme/acme.php', array(), array( 'activation' ) );
		$ledger->shouldReceive( 'get' )->andReturn( null );
		$ledger->shouldReceive( 'record_plugin' )->once()->with( 'acme/acme.php', array(), array( 'static' ), \Mockery::type( 'array' ) )->andReturn( array() );
		Functions\when( 'current_action' )->justReturn( 'added_post_meta' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$recorder = new Recorder( $ledger, $inventory );
		$recorder->before_activation( 'acme/acme.php' );
		$recorder->meta_added( 1, 2, '_acme_flag' );
		$recorder->after_activation( 'acme/acme.php' );
		$this->addToAssertionCount( 1 );
	}

	public function test_runtime_skips_known_hashed_and_core_names(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$ledger = \Mockery::mock( Ledger::class );
		$ledger->shouldReceive( 'is_seen' )->with( 'option', 'known' )->andReturn( true );
		$ledger->shouldReceive( 'is_seen' )->andReturn( false );
		$ledger->shouldNotReceive( 'record_seen' );

		$recorder = new Recorder( $ledger, \Mockery::mock( Inventory::class ) );
		$recorder->option_added( 'known' );
		$recorder->option_added( '_transient_feed_0123456789abcdef0123' );
		$recorder->option_added( 'siteurl' );
		// Called from this test, not from a plugin: no owner, nothing recorded.
		$recorder->option_added( 'acme_runtime' );
		$this->assertSame( 'x', $recorder->query( 'x' ) );
		$this->assertSame( array( 'wp_a' => 'CREATE' ), $recorder->dbdelta_tables( array( 'wp_a' => 'CREATE' ) ) );
	}

	public function test_roles_changed_reports_new_roles_and_caps(): void {
		$old      = array( 'editor' => array( 'capabilities' => array( 'read' => true ) ) );
		$new      = $old + array( 'acme_manager' => array( 'capabilities' => array( 'read' => true, 'acme_manage' => true ) ) );
		$ledger   = \Mockery::mock( Ledger::class );
		$ledger->shouldReceive( 'is_seen' )->andReturn( true );
		$recorder = new Recorder( $ledger, \Mockery::mock( Inventory::class ) );
		$recorder->roles_changed( $old, $new );
		$ledger->shouldHaveReceived( 'is_seen' )->with( 'role', 'acme_manager' );
		$ledger->shouldHaveReceived( 'is_seen' )->with( 'cap', 'acme_manage' );
		$this->addToAssertionCount( 2 );
	}

	public function test_inventory_diff(): void {
		$this->assertSame( array( 'table' => array( 'wp_b' ) ), Inventory::diff( array( 'table' => array( 'wp_a' ), 'option' => array( 'x' ) ), array( 'table' => array( 'wp_a', 'wp_b' ), 'option' => array( 'x' ) ) ) );
	}
}
