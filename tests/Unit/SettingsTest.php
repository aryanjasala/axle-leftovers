<?php
/**
 * Settings and their filters.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Settings;
use Brain\Monkey\Filters;

/**
 * Every default runs through its filter, and bad values fall back.
 */
final class SettingsTest extends LeftoversTestCase {

	public function test_defaults_and_sanitizing(): void {
		$this->assertSame( array( 'retention_days' => 30, 'export_max_mb' => 200, 'keep_exports' => false, 'runtime_ledger' => true, 'track_meta_keys' => true ), Settings::all() );

		Settings::save( array( 'retention_days' => '0', 'export_max_mb' => '-5', 'keep_exports' => '1' ) );
		$this->assertSame( array( 'retention_days' => 1, 'export_max_mb' => 0, 'keep_exports' => true, 'runtime_ledger' => false, 'track_meta_keys' => false ), Settings::all() );
	}

	public function test_capability_filter_and_fallback(): void {
		Filters\expectApplied( 'axle_leftovers_capability' )->once()->with( 'manage_options', false )->andReturn( 'edit_others_posts' );
		$this->assertSame( 'edit_others_posts', Settings::capability() );

		Filters\expectApplied( 'axle_leftovers_capability' )->once()->with( 'manage_network', true )->andReturn( '  ' );
		$this->assertSame( 'manage_network', Settings::capability( true ) );
	}

	public function test_number_filters_are_clamped(): void {
		Filters\expectApplied( 'axle_leftovers_retention_days' )->once()->with( 30 )->andReturn( 99999 );
		$this->assertSame( 3650, Settings::retention_days() );

		Filters\expectApplied( 'axle_leftovers_export_max_bytes' )->once()->with( 200 * MB_IN_BYTES )->andReturn( -1 );
		$this->assertSame( 0, Settings::export_max_bytes() );

		Filters\expectApplied( 'axle_leftovers_scan_batch_size' )->once()->with( 20000 )->andReturn( 5 );
		$this->assertSame( 100, Settings::scan_batch_size() );

		Filters\expectApplied( 'axle_leftovers_meta_batch_size' )->once()->with( 1000 )->andReturn( 50000 );
		$this->assertSame( 10000, Settings::meta_batch_size() );
	}

	public function test_boolean_filters(): void {
		Filters\expectApplied( 'axle_leftovers_keep_exports' )->once()->with( false )->andReturn( true );
		$this->assertTrue( Settings::keep_exports() );

		Filters\expectApplied( 'axle_leftovers_track_meta_keys' )->once()->with( true, 'user' )->andReturn( false );
		$this->assertFalse( Settings::track_meta_keys( 'user' ) );
	}

	public function test_typed_confirmation_can_be_added_but_not_removed(): void {
		Filters\expectApplied( 'axle_leftovers_require_typed_confirmation' )->twice()->andReturn( false, true );
		$this->assertTrue( Settings::require_typed_confirmation( 'guess' ) );
		$this->assertTrue( Settings::require_typed_confirmation( 'likely' ) );
	}

	public function test_uninstall_policy(): void {
		Filters\expectApplied( 'axle_leftovers_uninstall_trash_policy' )->once()->with( 'purge' )->andReturn( 'restore' );
		$this->assertSame( 'restore', Settings::uninstall_trash_policy() );
		Filters\expectApplied( 'axle_leftovers_uninstall_trash_policy' )->once()->andReturn( 'nonsense' );
		$this->assertSame( 'purge', Settings::uninstall_trash_policy() );
	}

	public function test_label_filters_keep_the_fixed_keys(): void {
		Filters\expectApplied( 'axle_leftovers_confidence_levels' )->once()->andReturn( array( 'certain' => 'Sure', 'extra' => 'No', 'likely' => '' ) );
		$this->assertSame( array( 'certain' => 'Sure', 'likely' => 'Likely', 'guess' => 'Guess', 'unknown' => 'Unknown' ), Settings::confidence_levels() );

		Filters\expectApplied( 'axle_leftovers_item_types' )->once()->andReturn( array( 'table' => 'Tables', 'made_up' => 'X', 'option' => '' ) );
		$this->assertSame( array( 'table' => 'Tables', 'option' => 'Option' ), Settings::item_types() );
	}
}
