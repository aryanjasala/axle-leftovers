<?php
/**
 * Notices, abilities, privacy and Site Health.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Abilities;
use AryanX\Axle\Leftovers\Admin\Notices;
use AryanX\Axle\Leftovers\Health\SiteHealth;
use AryanX\Axle\Leftovers\Privacy;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Trash;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * The services around the engine.
 */
final class ServicesTest extends LeftoversTestCase {

	public function test_deleted_notice_lists_counts_and_runs_its_filter(): void {
		Functions\when( 'get_transient' )->justReturn( array( 'acme', 'clean' ) );
		Functions\when( 'delete_transient' )->justReturn( true );
		$scanner = \Mockery::mock( Scanner::class );
		$scanner->shouldReceive( 'ensure_fresh' )->once();
		$scanner->shouldReceive( 'by_plugin' )->with( 'site' )->andReturn(
			array(
				'acme' => array(
					'owner_name' => 'Acme Forms',
					'counts'     => array( 'table' => 2, 'option' => 12, 'cron' => 3 ),
				),
			)
		);
		$scanner->shouldReceive( 'by_plugin' )->with( 'network' )->andReturn( array() );
		Filters\expectApplied( 'axle_leftovers_notice_message' )->once()->with( 'Acme Forms left 2 tables, 12 options and 3 cron events.', 'acme', array( 'table' => 2, 'option' => 12, 'cron' => 3 ) )->andReturnFirstArg();

		$messages = ( new Notices( $scanner, \Mockery::mock( Trash::class ) ) )->pending_messages();

		$this->assertSame( array( 'Acme Forms left 2 tables, 12 options and 3 cron events.' ), $messages );
	}

	public function test_abilities_register_with_the_right_annotations(): void {
		$registered = array();
		Functions\when( 'wp_register_ability' )->alias(
			static function ( $name, $args ) use ( &$registered ) {
				$registered[ $name ] = $args;
			}
		);

		( new Abilities( \Mockery::mock( Scanner::class ), \Mockery::mock( Trash::class ) ) )->register_abilities();

		$annotations = array_map(
			static function ( $args ) {
				return $args['meta']['annotations'];
			},
			$registered
		);
		$this->assertSame( array( 'axle-leftovers/list-leftovers', 'axle-leftovers/get-item', 'axle-leftovers/list-trash', 'axle-leftovers/trash-items', 'axle-leftovers/restore-items', 'axle-leftovers/purge-trash', 'axle-leftovers/rescan' ), array_keys( $registered ) );
		$this->assertTrue( $annotations['axle-leftovers/list-leftovers']['readonly'] );
		$this->assertTrue( $annotations['axle-leftovers/trash-items']['destructive'] );
		$this->assertTrue( $annotations['axle-leftovers/purge-trash']['destructive'] );
		$this->assertSame( array( 'readonly' => false, 'destructive' => false, 'idempotent' => true ), $annotations['axle-leftovers/restore-items'] );
		$this->assertFalse( $annotations['axle-leftovers/rescan']['readonly'] );
		$this->assertTrue( $registered['axle-leftovers/rescan']['meta']['mcp']['public'] );

		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) {
				return 'manage_options' === $cap;
			}
		);
		$this->assertTrue( $registered['axle-leftovers/trash-items']['permission_callback']() );
	}

	public function test_privacy_eraser_purges_batches_with_the_users_meta(): void {
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 7 ) );
		$trash = \Mockery::mock( Trash::class );
		$trash->shouldReceive( 'rows' )->andReturn(
			array(
				array( 'batch_id' => 'b1', 'payload' => array( 'user_ids' => array( 3, 7 ) ) ),
				array( 'batch_id' => 'b2', 'payload' => array( 'user_ids' => array( 3 ) ) ),
			)
		);
		$trash->shouldReceive( 'purge' )->once()->with( array( 'batch' => 'b1' ) )->andReturn( array( array( 'id' => 1 ) ) );

		$result = ( new Privacy( $trash ) )->erase( 'jane@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( array( "Deleted 1 trashed item that held the user's meta." ), $result['messages'] );
	}

	public function test_site_health_flags_heavy_autoloaded_leftovers(): void {
		Functions\when( 'admin_url' )->returnArg();
		$scanner = \Mockery::mock( Scanner::class );
		$scanner->shouldReceive( 'items' )->andReturn( array( array( 'autoload' => true, 'bytes' => 150000 ), array( 'autoload' => false, 'bytes' => 900000 ) ), array() );
		Functions\when( 'size_format' )->justReturn( '146 KB' );

		$health = new SiteHealth( $scanner );
		$this->assertSame( 'recommended', $health->test_autoload()['status'] );
		$this->assertSame( 'good', $health->test_autoload()['status'] );
	}
}
