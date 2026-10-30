<?php
/**
 * The ledger and the first-seen record.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Ledger;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

/**
 * Entries, signatures and their hooks.
 */
final class LedgerTest extends LeftoversTestCase {

	public function test_slug_for(): void {
		$this->assertSame( 'acme', Ledger::slug_for( 'acme/acme.php' ) );
		$this->assertSame( 'hello', Ledger::slug_for( 'hello.php' ) );
	}

	public function test_record_plugin_filters_the_signature_and_fires_recorded(): void {
		Filters\expectApplied( 'axle_leftovers_signature' )->once()->with( array( 'o' => array( 'acme_a' ) ), 'acme', '1.0' )->andReturn( array( 'o' => array( 'acme_a', 'acme_b' ) ) );
		Actions\expectDone( 'axle_leftovers_plugin_recorded' )->once()->with( \Mockery::on( static function ( $entry ) {
			return 'acme' === $entry['slug'] && array( 'acme_a', 'acme_b' ) === $entry['signature']['o'] && array( 'static' ) === $entry['sources'];
		} ), true );

		$entry = ( new Ledger() )->record_plugin( 'acme/acme.php', array( 'name' => 'Acme', 'version' => '1.0' ), array( 'static', 'bogus' ), array( 'o' => array( 'acme_a' ) ) );

		$this->assertSame( 'Acme', $entry['name'] );
		$this->assertSame( '1.0', $entry['signature_version'] );
	}

	public function test_second_record_updates_and_merges_sources(): void {
		$ledger = new Ledger();
		$ledger->record_plugin( 'acme/acme.php', array( 'name' => 'Acme', 'version' => '1.0' ), array( 'activation' ) );
		Actions\expectDone( 'axle_leftovers_plugin_recorded' )->once()->with( \Mockery::type( 'array' ), false );

		$entry = $ledger->record_plugin( 'acme/acme.php', array( 'version' => '1.1' ), array( 'static' ) );

		$this->assertSame( array( 'static', 'activation' ), $entry['sources'] );
		$this->assertSame( '1.1', $entry['version'] );
		$this->assertCount( 1, $this->db->tables['wp_axle_leftovers_ledger'] );
	}

	public function test_mark_removed_fires_once(): void {
		$ledger = new Ledger();
		$ledger->record_plugin( 'acme/acme.php', array( 'name' => 'Acme' ) );
		Actions\expectDone( 'axle_leftovers_plugin_removed' )->once()->with( \Mockery::on( static function ( $entry ) {
			return 'acme' === $entry['slug'];
		} ), 'outside' );

		$ledger->mark_removed( 'acme', 'outside' );
		$ledger->mark_removed( 'acme', 'outside' );
		$ledger->mark_removed( 'nobody', 'admin' );

		$this->assertSame( 'outside', $ledger->get( 'acme' )['removed_how'] );
	}

	public function test_record_seen_keeps_the_first_owner(): void {
		$ledger = new Ledger();
		Actions\expectDone( 'axle_leftovers_item_first_seen' )->once()->with( 'option', 'acme_a', 'acme', 'runtime' );

		$this->assertTrue( $ledger->record_seen( 'option', 'acme_a', 'acme', 'runtime' ) );
		$this->assertFalse( $ledger->record_seen( 'option', 'acme_a', 'other', 'runtime' ) );
		$this->assertFalse( $ledger->record_seen( 'option', 'x', '', 'runtime' ) );
		$this->assertTrue( $ledger->is_seen( 'option', 'acme_a' ) );
		$this->assertFalse( $ledger->is_seen( 'option', 'nope' ) );
		$this->assertSame( 'acme', $ledger->seen_all()['option']['acme_a']['owner'] );
	}
}
