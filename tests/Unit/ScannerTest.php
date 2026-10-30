<?php
/**
 * The scan, the dataset and the reference check.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Attribution;
use AryanX\Axle\Leftovers\Dataset;
use AryanX\Axle\Leftovers\Inventory;
use AryanX\Axle\Leftovers\Ledger;
use AryanX\Axle\Leftovers\Recorder;
use AryanX\Axle\Leftovers\References;
use AryanX\Axle\Leftovers\Scanner;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Steps, in-use filtering, grouping and their hooks.
 */
final class ScannerTest extends LeftoversTestCase {

	public function test_a_scan_lists_items_nothing_installed_owns(): void {
		Functions\when( 'get_plugins' )->justReturn( array() );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Functions\when( 'has_action' )->alias(
			static function ( $hook ) {
				return 'acme_live' === $hook;
			}
		);
		Filters\expectApplied( 'axle_leftovers_item_types' )->andReturn( array( 'cron' => 'Cron event' ) );

		$ledger = \Mockery::mock( Ledger::class );
		$ledger->shouldReceive( 'all' )->andReturn( array() );
		$inventory = \Mockery::mock( Inventory::class );
		$inventory->shouldReceive( 'cron_events' )->andReturn(
			array(
				'acme_sync'        => array( array(), array() ),
				'acme_live'        => array( array() ),
				'other_used'       => array( array() ),
				'wp_version_check' => array( array() ),
			)
		);
		$attribution = \Mockery::mock( Attribution::class );
		$attribution->shouldReceive( 'flush' );
		$attribution->shouldReceive( 'attribute' )->with( 'cron', 'acme_sync' )->andReturn( array( 'owner' => 'acme', 'owner_name' => 'Acme', 'confidence' => 'likely', 'evidence' => array(), 'in_use' => false, 'in_use_by' => '' ) );
		$attribution->shouldReceive( 'attribute' )->with( 'cron', 'other_used' )->andReturn( array( 'owner' => 'other', 'owner_name' => 'Other', 'confidence' => 'certain', 'evidence' => array(), 'in_use' => true, 'in_use_by' => 'other' ) );
		$references = \Mockery::mock( References::class );
		$references->shouldReceive( 'check' )->andReturn( array( 'cron:acme_sync' => array( 'themes/x/functions.php' ) ) );

		Actions\expectDone( 'axle_leftovers_scan_started' )->once()->with( false );
		Actions\expectDone( 'axle_leftovers_scan_finished' )->once()->with(
			\Mockery::on(
				static function ( $items ) {
					return array( 'cron:acme_sync' ) === array_column( $items, 'id' ) && 2 === $items[0]['rows'] && array( 'themes/x/functions.php' ) === $items[0]['refs'];
				}
			)
		);

		$scanner = new Scanner( $ledger, $inventory, $attribution, $references, \Mockery::mock( Recorder::class ) );
		$scanner->run_all();

		$this->assertSame( 'done', Scanner::state()['status'] );
		$this->assertCount( 1, $scanner->items( array( 'plugin' => 'acme' ) ) );
		$this->assertCount( 0, $scanner->items( array( 'plugin' => '' ) ) );
		$this->assertSame( 'acme_sync', $scanner->item( 'cron', 'acme_sync' )['name'] );

		$groups = $scanner->by_plugin();
		$this->assertSame( array( 'cron' => 1 ), $groups['acme']['counts'] );

		$scanner->forget( array( array( 'type' => 'cron', 'name' => 'acme_sync' ) ) );
		$this->assertSame( array(), $scanner->items() );
		$this->assertFalse( $scanner->needs_scan() );
		Scanner::mark_stale();
		$this->assertTrue( $scanner->needs_scan() );
	}

	public function test_dataset_filter(): void {
		$file = tempnam( sys_get_temp_dir(), 'alo' );
		file_put_contents( $file, json_encode( array( 'plugins' => array( 'acme' => array( 'name' => 'Acme', 'o' => array( 'acme_a' ) ) ) ) ) );
		Filters\expectApplied( 'axle_leftovers_dataset' )->once()->andReturnUsing(
			static function ( $plugins ) {
				$plugins['extra'] = array( 'name' => 'Extra', 't' => array( 'extra_log' ) );
				$plugins[5]       = 'junk';
				return $plugins;
			}
		);

		$dataset = new Dataset( $file );

		$this->assertSame( array( 'acme', 'extra' ), array_keys( $dataset->plugins() ) );
		$this->assertSame( 'Extra', $dataset->name( 'extra' ) );
		$this->assertSame( '', $dataset->name( 'nope' ) );
	}

	public function test_bundled_dataset_is_valid_and_small(): void {
		$file = dirname( __DIR__, 2 ) . '/data/signatures.json';
		$data = json_decode( (string) file_get_contents( $file ), true );

		$this->assertLessThanOrEqual( 1048576, filesize( $file ) );
		$this->assertGreaterThan( 100, count( $data['plugins'] ) );
		$this->assertContains( 'contact_form_7', $data['plugins']['contact-form-7']['t'] ?? array( 'contact_form_7' ) );
	}

	public function test_reference_check_finds_names_in_filtered_paths(): void {
		$dir = sys_get_temp_dir() . '/axle-leftovers-refs-' . uniqid();
		mkdir( $dir );
		file_put_contents( $dir . '/uses.php', "<?php get_option( 'acme_settings' ); \$wpdb->query( \"SELECT * FROM {\$wpdb->prefix}acme_log\" );" );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_stylesheet_directory' )->justReturn( '' );
		Functions\when( 'get_template_directory' )->justReturn( '' );
		Filters\expectApplied( 'axle_leftovers_reference_check_paths' )->once()->andReturn( array( $dir ) );

		$hits = ( new References() )->check(
			array(
				array( 'type' => 'option', 'name' => 'acme_settings' ),
				array( 'type' => 'table', 'name' => 'wp_acme_log' ),
				array( 'type' => 'option', 'name' => 'acme_unused' ),
			)
		);

		$this->assertSame( array( $dir . '/uses.php' ), $hits['option:acme_settings'] );
		$this->assertSame( array( $dir . '/uses.php' ), $hits['table:wp_acme_log'] );
		$this->assertSame( array(), $hits['option:acme_unused'] );
		$this->assertSame( array( "'acme_settings'", '"acme_settings"', "'acme_'", '"acme_"' ), References::needles( 'option', 'acme_settings' ) );

		unlink( $dir . '/uses.php' );
		rmdir( $dir );
	}
}
