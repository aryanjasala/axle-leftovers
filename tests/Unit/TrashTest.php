<?php
/**
 * Trash, restore and purge, with their hooks.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Attribution;
use AryanX\Axle\Leftovers\Inventory;
use AryanX\Axle\Leftovers\References;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Storage;
use AryanX\Axle\Leftovers\Trash;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * The safety checks, the batch and every lifecycle action.
 */
final class TrashTest extends LeftoversTestCase {

	/** @var \Mockery\MockInterface */
	private $scanner;

	/** @var \Mockery\MockInterface */
	private $inventory;

	/** @var \Mockery\MockInterface */
	private $storage;

	/** @var \Mockery\MockInterface */
	private $attribution;

	/** @var \Mockery\MockInterface */
	private $references;

	/** @var array */
	private $cron = array();

	protected function setUp(): void {
		parent::setUp();
		$this->cron = array( 'acme_sync' => array( array( 'timestamp' => 100, 'args' => array(), 'schedule' => 'daily', 'interval' => 86400 ) ) );

		$this->attribution = \Mockery::mock( Attribution::class );
		$this->attribution->shouldReceive( 'flush' );
		$this->attribution->shouldReceive( 'attribute' )->andReturn( $this->who() )->byDefault();
		$this->attribution->shouldReceive( 'owner_name' )->andReturn( 'Acme' );
		$this->references = \Mockery::mock( References::class );
		$this->references->shouldReceive( 'check' )->andReturn( array() )->byDefault();

		$this->scanner = \Mockery::mock( Scanner::class );
		$this->scanner->shouldReceive( 'attribution' )->andReturn( $this->attribution );
		$this->scanner->shouldReceive( 'references' )->andReturn( $this->references );
		$this->scanner->shouldReceive( 'item' )->andReturnUsing( array( $this, 'listed' ) )->byDefault();
		$this->scanner->shouldReceive( 'forget' );

		$this->inventory = \Mockery::mock( Inventory::class );
		$this->inventory->shouldReceive( 'cron_events' )->andReturnUsing(
			function () {
				return $this->cron;
			}
		);
		$this->inventory->shouldReceive( 'users_with_role' )->andReturn( array() )->byDefault();

		$this->storage = \Mockery::mock( Storage::class );
		$this->storage->shouldReceive( 'dir' )->andReturn( '/tmp/alo' );
		$this->storage->shouldReceive( 'put' )->andReturn( true )->byDefault();

		Functions\when( 'wp_unschedule_hook' )->alias(
			function ( $hook ) {
				unset( $this->cron[ $hook ] );
			}
		);
		Functions\when( 'update_option' )->justReturn( true );
	}

	/**
	 * The scanner's listed item.
	 */
	public function listed( string $type, string $name ): ?array {
		if ( 'missing' === $name ) {
			return null;
		}
		return array(
			'id'         => $type . ':' . $name,
			'type'       => $type,
			'name'       => $name,
			'scope'      => 'site',
			'owner'      => 'acme',
			'owner_name' => 'Acme',
			'confidence' => 'certain',
			'evidence'   => array(),
			'rows'       => 1,
			'bytes'      => 10,
			'autoload'   => false,
			'refs'       => array(),
		);
	}

	/**
	 * An attribution result.
	 */
	private function who( string $confidence = 'certain', bool $in_use = false ): array {
		return array(
			'owner'      => 'acme',
			'owner_name' => 'Acme',
			'confidence' => $confidence,
			'evidence'   => array(),
			'in_use'     => $in_use,
			'in_use_by'  => $in_use ? 'acme' : '',
		);
	}

	private function trash(): Trash {
		return new Trash( $this->scanner, $this->inventory, $this->storage );
	}

	public function test_trash_and_restore_a_cron_hook_fires_every_action(): void {
		Filters\expectApplied( 'axle_leftovers_can_trash' )->once()->with( true, \Mockery::type( 'array' ) )->andReturn( true );
		Actions\expectDone( 'axle_leftovers_before_trash' )->once()->with( \Mockery::type( 'array' ), \Mockery::type( 'string' ) );
		Actions\expectDone( 'axle_leftovers_exported' )->once()->with( \Mockery::type( 'string' ), \Mockery::pattern( '#^/tmp/alo/exports/\d{8}-\d{6}-abcd/1-cron-acme_sync\.json$#' ), \Mockery::type( 'array' ) );
		Actions\expectDone( 'axle_leftovers_trashed' )->once();

		$result = $this->trash()->trash( array( array( 'type' => 'cron', 'name' => 'acme_sync' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'acme_sync', $result['items'][0]['name'] );
		$this->assertArrayNotHasKey( 'acme_sync', $this->cron );
		$row = $this->db->tables['wp_axle_leftovers_trash'][1];
		$this->assertSame( 'trashed', $row['status'] );
		$this->assertStringStartsWith( 'exports/' . $result['batch'] . '/1-cron-acme_sync.json', $row['export_path'] );

		$saved = array();
		Functions\when( '_get_cron_array' )->justReturn( array() );
		Functions\when( '_set_cron_array' )->alias(
			static function ( $crons ) use ( &$saved ) {
				$saved = $crons;
			}
		);
		Actions\expectDone( 'axle_leftovers_before_restore' )->once();
		Actions\expectDone( 'axle_leftovers_restored' )->once()->with( \Mockery::type( 'array' ), $result['batch'] );

		$restored = $this->trash()->restore( $result['batch'] );

		$this->assertCount( 1, $restored['restored'] );
		$timestamp = (int) key( $saved );
		$this->assertGreaterThan( time(), $timestamp, 'A past run is moved to now plus the interval.' );
		$this->assertSame( 'daily', current( $saved[ $timestamp ]['acme_sync'] )['schedule'] );
		$this->assertSame( 'restored', $this->db->tables['wp_axle_leftovers_trash'][1]['status'] );
	}

	public function test_a_failed_export_rolls_back_and_fires_trash_failed(): void {
		$this->storage->shouldReceive( 'put' )->andReturn( false );
		Actions\expectDone( 'axle_leftovers_trash_failed' )->once()->with( \Mockery::type( 'array' ), \Mockery::type( \WP_Error::class ), \Mockery::type( 'string' ) );
		Actions\expectDone( 'axle_leftovers_trashed' )->never();

		$result = $this->trash()->trash( array( array( 'type' => 'cron', 'name' => 'acme_sync' ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertArrayHasKey( 'acme_sync', $this->cron );
		$this->assertSame( array(), $this->db->tables['wp_axle_leftovers_trash'] );
	}

	public function test_safety_checks_refuse_the_whole_batch(): void {
		$this->attribution->shouldReceive( 'attribute' )->with( 'cron', 'acme_used' )->andReturn( $this->who( 'certain', true ) );
		$this->attribution->shouldReceive( 'attribute' )->with( 'cron', 'acme_guess' )->andReturn( $this->who( 'guess' ) );
		$this->cron += array( 'acme_used' => array(), 'acme_guess' => array(), 'acme_ref' => array() );
		$this->references->shouldReceive( 'check' )->andReturn( array( 'cron:acme_ref' => array( 'mu-plugins/x.php' ) ) );

		$result = $this->trash()->trash(
			array(
				array( 'type' => 'cron', 'name' => 'wp_version_check' ),
				array( 'type' => 'cron', 'name' => 'missing' ),
				array( 'type' => 'cron', 'name' => 'acme_used' ),
				array( 'type' => 'cron', 'name' => 'acme_guess' ),
				array( 'type' => 'cron', 'name' => 'acme_ref' ),
				array( 'type' => 'nope', 'name' => 'x' ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( array( 'protected', 'not_listed', 'in_use', 'confirm', 'type', 'referenced' ), array_column( $result->get_error_data()['items'], 'code' ) );
		$this->assertStringContainsString( 'mu-plugins/x.php', $result->get_error_message() );
	}

	public function test_typed_name_confirms_a_guess(): void {
		$this->attribution->shouldReceive( 'attribute' )->andReturn( $this->who( 'guess' ) );
		$checked = $this->trash()->check( array( array( 'type' => 'cron', 'name' => 'acme_sync' ) ), array( 'confirm_names' => array( 'acme_sync' ) ) );

		$this->assertSame( array(), $checked['errors'] );
	}

	public function test_can_trash_filter_and_role_with_users(): void {
		Filters\expectApplied( 'axle_leftovers_can_trash' )->once()->andReturn( new \WP_Error( 'x', 'Not on Fridays.' ) );
		$checked = $this->trash()->check( array( array( 'type' => 'cron', 'name' => 'acme_sync' ) ) );
		$this->assertSame( 'Not on Fridays.', $checked['errors'][0]['message'] );

		$this->inventory->shouldReceive( 'roles' )->andReturn( array( 'acme_role' => array() ) );
		$this->inventory->shouldReceive( 'users_with_role' )->andReturn( array( 'jane' ) );
		$checked = $this->trash()->check( array( array( 'type' => 'role', 'name' => 'acme_role' ) ) );
		$this->assertSame( 'Users still have the role acme_role: jane. Change their role first.', $checked['errors'][0]['message'] );
	}

	public function test_restore_failure_fires_restore_failed(): void {
		$this->db->tables['wp_axle_leftovers_trash'][1] = array( 'id' => 1, 'batch_id' => 'b1', 'item_type' => 'role', 'name' => 'acme_role', 'status' => 'trashed', 'payload' => '{}', 'trash_location' => '', 'export_path' => '', 'scope' => 'site' );
		Functions\when( 'get_role' )->justReturn( (object) array( 'name' => 'acme_role' ) );
		Functions\when( 'update_option' )->justReturn( true );
		Actions\expectDone( 'axle_leftovers_restore_failed' )->once()->with( \Mockery::type( 'array' ), \Mockery::type( \WP_Error::class ) );
		Actions\expectDone( 'axle_leftovers_restored' )->never();

		$result = $this->trash()->restore( 'b1' );

		$this->assertSame( 'Something named acme_role exists now. Rename or remove it, then restore again.', $result['failed'][0]['message'] );
	}

	public function test_purge_fires_purged_and_respects_age(): void {
		$old = gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS );
		$new = gmdate( 'Y-m-d H:i:s' );
		foreach ( array( 1 => $old, 2 => $new ) as $id => $date ) {
			$this->db->tables['wp_axle_leftovers_trash'][ $id ] = array( 'id' => $id, 'batch_id' => 'b' . $id, 'item_type' => 'option', 'name' => 'o' . $id, 'status' => 'trashed', 'payload' => '{}', 'trash_location' => '', 'export_path' => '', 'trashed_gmt' => $date, 'purge_after_gmt' => $date, 'scope' => 'site', 'owner_slug' => '', 'confidence' => '', 'size_bytes' => 0 );
		}
		Actions\expectDone( 'axle_leftovers_purged' )->once()->with( \Mockery::on( static function ( $rows ) {
			return array( 'o1' ) === array_column( $rows, 'name' );
		} ) );

		$purged = $this->trash()->purge( array( 'older_than' => 30 ) );

		$this->assertSame( array( 'o1' ), array_column( $purged, 'name' ) );
		$this->assertSame( 'purged', $this->db->tables['wp_axle_leftovers_trash'][1]['status'] );
		$this->assertSame( 'trashed', $this->db->tables['wp_axle_leftovers_trash'][2]['status'] );
	}

	public function test_meta_table_map(): void {
		$this->assertSame( array( 'table' => 'wp_usermeta', 'id' => 'umeta_id', 'object' => 'user_id', 'cache' => 'user_meta' ), Trash::meta_table( 'usermeta' ) );
		$this->assertFalse( Trash::frozen() );
	}
}
