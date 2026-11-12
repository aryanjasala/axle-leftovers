<?php
/**
 * The small HTML helpers of the screens.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Admin\Display;
use Brain\Monkey\Functions;

/**
 * Counts, relative times and evidence.
 */
final class DisplayTest extends LeftoversTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'human_time_diff' )->justReturn( '3 days' );
		Functions\when( 'wp_date' )->justReturn( '2026-11-01 10:00' );
		Functions\when( 'get_option' )->justReturn( 'Y-m-d' );
	}

	public function test_count_list_names_theme_content_by_its_posts(): void {
		$this->assertSame(
			'1 option, 14 template changes, 1 global styles change and 2 custom CSS entries',
			Display::count_list(
				array(
					'option'       => 1,
					'template'     => 14,
					'globalstyles' => 1,
					'customcss'    => 2,
				)
			)
		);
		$this->assertSame( '1 template change', Display::count_list( array( 'template' => 1 ) ) );
	}

	public function test_time_is_relative_with_the_exact_time_and_empty_for_no_time(): void {
		$html = Display::time( '2026-11-01 10:00:00' );

		$this->assertStringContainsString( '<time class="axle-time" datetime="2026-11-01T10:00:00+00:00"', $html );
		$this->assertStringContainsString( 'title="2026-11-01 10:00"', $html );
		$this->assertStringContainsString( '3 days ago', $html );
		$this->assertSame( '', Display::time( '' ) );
		$this->assertSame( '', Display::time( '0000-00-00 00:00:00' ) );
	}

	public function test_evidence_lists_each_line_and_adds_the_time_when_there_is_one(): void {
		$html = Display::evidence(
			array(
				array(
					'code' => 'activation',
					'text' => 'Created when Acme was activated',
					'when' => '2026-11-01 10:00:00',
				),
				array(
					'code' => 'signature',
					'text' => 'Named in Acme code',
				),
			)
		);

		$this->assertStringStartsWith( '<li>Created when Acme was activated (<time', $html );
		$this->assertStringEndsWith( '<li>Named in Acme code</li>', $html );
	}

	public function test_removed_says_how_and_when_and_knows_a_theme_without_a_ledger_entry(): void {
		$entry = array(
			'version'     => '2.0.0',
			'removed_gmt' => '2026-11-01 10:00:00',
			'removed_how' => 'cli',
		);

		$html = Display::removed( $entry, 'theme' );

		$this->assertStringContainsString( 'Last version 2.0.0.', $html );
		$this->assertStringContainsString( 'Deleted with WP-CLI:', $html );
		$this->assertStringContainsString( '<time class="axle-time"', $html );
		$this->assertSame( 'Deleted before Leftovers was installed. Found from its name.', Display::removed( null, 'theme' ) );
		$this->assertStringContainsString( 'bundled dataset', Display::removed( null ) );
		$this->assertStringContainsString( 'Still installed.', Display::removed( array_merge( $entry, array( 'removed_gmt' => null ) ) ) );
	}
}
