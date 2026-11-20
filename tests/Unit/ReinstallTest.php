<?php
/**
 * A reinstalled plugin gets its data back.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Inventory;
use AryanX\Axle\Leftovers\Reinstall;
use AryanX\Axle\Leftovers\Settings;
use AryanX\Axle\Leftovers\Trash;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * The preview states, the activation stop, the restore with its swaps, and Undo.
 */
final class ReinstallTest extends LeftoversTestCase {

	/**
	 * The trash.
	 *
	 * @var \Mockery\MockInterface
	 */
	private $trash;

	/**
	 * The inventory.
	 *
	 * @var \Mockery\MockInterface
	 */
	private $inventory;

	protected function setUp(): void {
		parent::setUp();
		$this->trash     = \Mockery::mock( Trash::class );
		$this->inventory = \Mockery::mock( Inventory::class );
		Functions\when( 'size_format' )->alias(
			static function ( $bytes ) {
				return $bytes . ' B';
			}
		);
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $url ) {
				return $url . '&' . http_build_query( $args );
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.test/wp-admin/' . $path;
			}
		);
	}

	private function service(): Reinstall {
		return new Reinstall( $this->trash, $this->inventory );
	}

	private function row( int $id, string $type, string $name, array $extra = array() ): array {
		return array_merge(
			array(
				'id'             => $id,
				'item_type'      => $type,
				'name'           => $name,
				'owner_slug'     => 'acme',
				'status'         => 'trashed',
				'size_bytes'     => 10,
				'trash_location' => 'table' === $type ? 'wp_axlt_' . $id : '',
				'payload'        => array(),
			),
			$extra
		);
	}

	private function rows(): array {
		return array(
			$this->row( 1, 'table', 'wp_acme_log' ),
			$this->row( 2, 'option', 'acme_settings', array( 'payload' => array( 'rows' => array( array( 'option_name' => 'acme_settings', 'option_value' => 'old', 'autoload' => 'yes' ) ) ) ) ),
			$this->row( 3, 'option', 'acme_version', array( 'payload' => array( 'rows' => array( array( 'option_name' => 'acme_version', 'option_value' => '1.0', 'autoload' => 'yes' ) ) ) ) ),
			$this->row( 4, 'option', 'acme_api_key', array( 'payload' => array( 'rows' => array( array( 'option_name' => 'acme_api_key', 'option_value' => 'secret-value', 'autoload' => 'yes' ) ) ) ) ),
			$this->row( 5, 'cron', 'acme_daily' ),
			$this->row( 6, 'option', 'acme_other', array( 'owner_slug' => 'other' ) ),
		);
	}

	public function test_plan_names_each_state_and_hides_secrets(): void {
		$this->trash->shouldReceive( 'listed' )->andReturn( $this->rows() );
		$this->trash->shouldReceive( 'exists' )->andReturnUsing(
			static function ( $type, $name ) {
				return in_array( $name, array( 'wp_acme_log', 'acme_settings', 'acme_version', 'acme_api_key', 'acme_daily' ), true );
			}
		);
		$this->inventory->shouldReceive( 'table_stats' )->andReturn(
			array(
				'wp_acme_log' => array( 'rows' => 3, 'bytes' => 100 ),
				'wp_axlt_1'   => array( 'rows' => 50, 'bytes' => 900 ),
			)
		);
		$this->inventory->shouldReceive( 'table_exists' )->with( 'wp_axlt_1' )->andReturn( true );
		global $wpdb;
		$wpdb->vars[ 'acme_settings' ] = 'new';
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$plan = array_column( $this->service()->plan( 'acme' ), null, 'name' );

		$this->assertCount( 5, $plan, 'Another plugin\'s item is not in the plan.' );
		$this->assertSame( 'conflict', $plan['wp_acme_log']['state'] );
		$this->assertSame( 'keep', $plan['wp_acme_log']['default'] );
		$this->assertSame( '50 rows, 900 B', $plan['wp_acme_log']['old'] );
		$this->assertSame( '3 rows, 100 B', $plan['wp_acme_log']['live'] );
		$this->assertSame( 'conflict', $plan['acme_settings']['state'] );
		$this->assertSame( 'old', $plan['acme_settings']['old'] );
		$this->assertSame( 'new', $plan['acme_settings']['live'] );
		$this->assertSame( '(hidden, 12 characters)', $plan['acme_api_key']['old'] );
		$this->assertSame( 'taken', $plan['acme_daily']['state'] );
	}

	public function test_a_free_item_and_an_identical_one(): void {
		$this->trash->shouldReceive( 'listed' )->andReturn( array_slice( $this->rows(), 1, 2 ) );
		$this->trash->shouldReceive( 'exists' )->andReturnUsing(
			static function ( $type, $name ) {
				return 'acme_version' === $name;
			}
		);
		global $wpdb;
		$wpdb->vars[ 'acme_version' ] = '1.0';
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$plan = array_column( $this->service()->plan( 'acme' ), null, 'name' );

		$this->assertSame( 'free', $plan['acme_settings']['state'] );
		$this->assertSame( 'same', $plan['acme_version']['state'] );
		$this->assertSame( array( 2 => 'restore' ), $this->service()->defaults( array_values( $plan ) ) );
	}

	public function test_activation_stops_on_the_screen_and_in_cli_and_restores_quietly_elsewhere(): void {
		$this->trash->shouldReceive( 'listed' )->andReturn( array( $this->row( 2, 'option', 'acme_settings', array( 'payload' => array( 'rows' => array( array( 'option_value' => 'old' ) ) ) ) ) ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_plugins' )->justReturn( array( 'acme/acme.php' => array( 'Name' => 'Acme' ) ) );
		Functions\when( 'get_admin_url' )->justReturn( 'x' );
		Actions\expectDone( 'axle_leftovers_reinstall_detected' )->once()->with( 'acme', array( 'option' => 1 ), 'screen' );

		$verdict = $this->service()->intercept( 'acme/acme.php' );
		$this->assertSame( 'redirect', $verdict['action'] );
		$this->assertStringContainsString( 'reinstall=acme', $verdict['url'] );
		$this->assertStringContainsString( 'activate=acme%2Facme.php', $verdict['url'] );
	}

	public function test_nothing_happens_without_data_decision_or_for_the_plugin_itself(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->trash->shouldReceive( 'listed' )->andReturn( array() );
		$this->assertSame( 'none', $this->service()->intercept( 'acme/acme.php' )['action'] );
		$this->assertSame( 'none', $this->service()->intercept( 'axle-leftovers/axle-leftovers.php' )['action'] );
	}

	public function test_a_decision_stops_the_stop(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->trash->shouldReceive( 'listed' )->andReturn( $this->rows() );
		$this->options[ Reinstall::OPTION ] = array( 'decisions' => array( 'acme' => array( 'state' => 'skipped' ) ) );
		$this->assertSame( 'none', $this->service()->intercept( 'acme/acme.php' )['action'] );
	}

	public function test_the_mode_filter_and_the_setting(): void {
		Filters\expectApplied( 'axle_leftovers_reinstall_mode' )->once()->with( 'ask', 'acme' )->andReturn( 'off' );
		$this->assertSame( 'off', Settings::reinstall_mode( 'acme' ) );

		$this->options[ Settings::OPTION ] = array( 'reinstall_offer' => false );
		Filters\expectApplied( 'axle_leftovers_reinstall_mode' )->once()->with( 'off', 'acme' )->andReturn( 'nonsense' );
		$this->assertSame( 'off', Settings::reinstall_mode( 'acme' ) );
	}

	public function test_restore_swaps_current_data_to_the_trash_first_and_logs_an_undo(): void {
		$this->trash->shouldReceive( 'listed' )->andReturn( array_slice( $this->rows(), 0, 3 ) );
		$this->trash->shouldReceive( 'exists' )->andReturnUsing(
			static function ( $type, $name ) {
				return 'acme_settings' === $name;
			}
		);
		$this->inventory->shouldReceive( 'table_stats' )->andReturn( array() );
		$this->inventory->shouldReceive( 'table_exists' )->andReturn( true );
		global $wpdb;
		$wpdb->vars[ 'acme_settings' ] = 'new';
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->trash->shouldReceive( 'swap_out' )->once()->with( 'option', 'acme_settings', 'acme' )->andReturn( array( 'id' => 90 ) );
		$this->trash->shouldReceive( 'restore' )->once()->with( '', array( 1, 2, 3 ) )->andReturn(
			array(
				'restored' => array(
					array( 'id' => 1, 'type' => 'table', 'name' => 'wp_acme_log' ),
					array( 'id' => 2, 'type' => 'option', 'name' => 'acme_settings' ),
					array( 'id' => 3, 'type' => 'option', 'name' => 'acme_version' ),
				),
				'failed'   => array(),
			)
		);
		Actions\expectDone( 'axle_leftovers_reinstall_restored' )->once();
		Actions\expectDone( 'axle_leftovers_reinstall_decided' )->once()->with( 'acme', 'handled' );

		// The table clashes too, but only the option is chosen for replacing; the table is left alone.
		$result = $this->service()->restore( 'acme', array( 1 => 'restore', 2 => 'use_old', 3 => 'restore' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 90 ), array_column( $result['entry']['swapped'], 'id' ) );
		$this->assertCount( 3, $result['entry']['restored'] );
		$this->assertCount( 1, $this->options[ Reinstall::OPTION ]['log'] );
		$this->assertSame( 'handled', $this->options[ Reinstall::OPTION ]['decisions']['acme']['state'] );
	}

	public function test_a_clash_is_never_replaced_without_a_choice(): void {
		$this->trash->shouldReceive( 'listed' )->andReturn( array( $this->rows()[1] ) );
		$this->trash->shouldReceive( 'exists' )->andReturn( true );
		global $wpdb;
		$wpdb->vars[ 'acme_settings' ] = 'new';
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->trash->shouldNotReceive( 'swap_out' );
		$this->trash->shouldNotReceive( 'restore' );

		$result = $this->service()->restore( 'acme', array( 2 => 'restore' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_a_failed_restore_puts_the_swapped_data_back(): void {
		$this->trash->shouldReceive( 'listed' )->andReturn( array( $this->rows()[1] ) );
		$this->trash->shouldReceive( 'exists' )->andReturn( true );
		global $wpdb;
		$wpdb->vars[ 'acme_settings' ] = 'new';
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->trash->shouldReceive( 'swap_out' )->once()->andReturn( array( 'id' => 91 ) );
		$this->trash->shouldReceive( 'restore' )->once()->with( '', array( 2 ) )->andReturn(
			array(
				'restored' => array(),
				'failed'   => array( array( 'id' => 2, 'name' => 'acme_settings', 'message' => 'Something named acme_settings exists now.' ) ),
			)
		);
		$this->trash->shouldReceive( 'restore' )->once()->with( '', array( 91 ) )->andReturn( array( 'restored' => array(), 'failed' => array() ) );

		$result = $this->service()->restore( 'acme', array( 2 => 'use_old' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertArrayNotHasKey( 'log', $this->options[ Reinstall::OPTION ] ?? array() );
	}

	public function test_undo_trashes_what_came_back_and_restores_what_it_replaced(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->options[ Reinstall::OPTION ] = array(
			'decisions' => array(),
			'log'       => array(
				array(
					'id'       => 'r1',
					'slug'     => 'acme',
					'gmt'      => '2026-11-20 10:00:00',
					'user'     => 1,
					'source'   => 'screen',
					'restored' => array( array( 'id' => 1, 'type' => 'table', 'name' => 'wp_acme_log' ), array( 'id' => 2, 'type' => 'option', 'name' => 'acme_settings' ) ),
					'swapped'  => array( array( 'id' => 90, 'type' => 'option', 'name' => 'acme_settings' ) ),
					'undone'   => '',
				),
			),
		);
		$this->trash->shouldReceive( 'swap_out' )->once()->with( 'option', 'acme_settings', 'acme' )->andReturn( array( 'id' => 100 ) )->ordered();
		$this->trash->shouldReceive( 'swap_out' )->once()->with( 'table', 'wp_acme_log', 'acme' )->andReturn( array( 'id' => 101 ) )->ordered();
		$this->trash->shouldReceive( 'restore' )->once()->with( '', array( 90 ) )->andReturn( array( 'restored' => array( array( 'id' => 90 ) ), 'failed' => array() ) );
		Actions\expectDone( 'axle_leftovers_reinstall_undone' )->once();

		$result = $this->service()->undo( 'r1' );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['failed'] );
		$this->assertNotSame( '', $this->options[ Reinstall::OPTION ]['log'][0]['undone'] );

		$again = $this->service()->undo( 'r1' );
		$this->assertInstanceOf( \WP_Error::class, $again );
	}

	public function test_privacy_helpers_find_and_anonymize_a_users_entries(): void {
		$this->options[ Reinstall::OPTION ] = array(
			'decisions' => array( 'acme' => array( 'state' => 'handled', 'user' => 7 ) ),
			'log'       => array( array( 'id' => 'r1', 'user' => 7 ), array( 'id' => 'r2', 'user' => 8 ) ),
		);
		$this->assertCount( 1, Reinstall::entries_for_user( 7 ) );
		$this->assertSame( 2, Reinstall::anonymize_user( 7 ) );
		$this->assertSame( 0, $this->options[ Reinstall::OPTION ]['log'][0]['user'] );
		$this->assertSame( 8, $this->options[ Reinstall::OPTION ]['log'][1]['user'] );
	}
}
