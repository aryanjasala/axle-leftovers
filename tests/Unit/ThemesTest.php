<?php
/**
 * Theme leftovers: what counts as installed, owners, the ledger and the filters.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Attribution;
use AryanX\Axle\Leftovers\Core;
use AryanX\Axle\Leftovers\Dataset;
use AryanX\Axle\Leftovers\Inventory;
use AryanX\Axle\Leftovers\Ledger;
use AryanX\Axle\Leftovers\Recorder;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Settings;
use AryanX\Axle\Leftovers\Tests\FakeTheme;
use AryanX\Axle\Leftovers\Themes;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Deleted themes leave settings, options and block theme content.
 */
final class ThemesTest extends LeftoversTestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_plugins' )->justReturn( array() );
		Functions\when( 'get_mu_plugins' )->justReturn( array() );
	}

	/**
	 * Attribution over a ledger and an empty dataset.
	 */
	private function attribution( array $ledger = array(), array $seen = array() ): Attribution {
		$mock = \Mockery::mock( Ledger::class );
		$mock->shouldReceive( 'seen_all' )->andReturn( $seen );
		$mock->shouldReceive( 'all' )->andReturn( $ledger );
		$mock->shouldReceive( 'flush' );

		$file = tempnam( sys_get_temp_dir(), 'alo' );
		file_put_contents( $file, json_encode( array( 'plugins' => array() ) ) );

		return new Attribution( $mock, new Dataset( $file ) );
	}

	/**
	 * A ledger entry for a theme.
	 */
	private function entry( string $stylesheet, string $name, ?string $removed, array $signature = array() ): array {
		return array(
			'slug'        => 'theme:' . $stylesheet,
			'name'        => $name,
			'removed_gmt' => $removed,
			'signature'   => $signature ? \AryanX\Axle\Leftovers\Signature::normalize( $signature ) : null,
		);
	}

	public function test_installed_counts_broken_themes_the_active_theme_and_its_parent(): void {
		$asked = array();
		Functions\when( 'wp_get_themes' )->alias(
			static function ( $args = array() ) use ( &$asked ) {
				$asked = $args;
				return array( 'broken-theme' => true );
			}
		);
		Functions\when( 'get_stylesheet' )->justReturn( 'child' );
		Functions\when( 'get_template' )->justReturn( 'parent' );
		Themes::flush();

		$this->assertEqualsCanonicalizing( array( 'broken-theme', 'child', 'parent' ), array_keys( Themes::installed() ) );
		$this->assertSame( array( 'errors' => null ), $asked, 'Themes with errors count as installed too.' );
		$this->assertTrue( Themes::is_installed( 'parent' ) );
		$this->assertFalse( Themes::is_installed( 'old-theme' ) );
	}

	public function test_a_filter_can_protect_more_themes(): void {
		Filters\expectApplied( 'axle_leftovers_protected_themes' )->once()->andReturnUsing(
			static function ( $slugs ) {
				$slugs[] = 'keep-me';
				return $slugs;
			}
		);
		Themes::flush();

		$this->assertTrue( Themes::is_installed( 'keep-me' ) );
		$this->assertTrue( Core::is_protected( 'option', 'theme_mods_keep-me' ) );
	}

	public function test_theme_settings_are_core_only_while_the_theme_is_installed(): void {
		Functions\when( 'wp_get_themes' )->justReturn( array( 'twentytwentyfive' => true, 'astra' => true ) );
		Themes::flush();

		$this->assertTrue( Core::is_protected( 'option', 'theme_mods_twentytwentyfive' ) );
		$this->assertTrue( Core::is_protected( 'option', 'theme_mods_astra' ) );
		$this->assertFalse( Core::is_protected( 'option', 'theme_mods_old-theme' ) );
		$this->assertTrue( Core::is_protected( 'option', 'widget_text' ), 'Other core option families are untouched.' );
	}

	public function test_theme_content_is_protected_for_an_installed_theme_only(): void {
		Functions\when( 'wp_get_themes' )->justReturn( array( 'astra' => true ) );
		Themes::flush();

		foreach ( array( 'template', 'globalstyles', 'customcss' ) as $type ) {
			$this->assertTrue( Core::is_protected( $type, 'astra' ), $type );
			$this->assertTrue( Core::is_protected( $type, 'twentytwentyfive' ), $type . ' of the active theme' );
			$this->assertFalse( Core::is_protected( $type, 'old-theme' ), $type );
		}
	}

	public function test_owner_stylesheet_reads_the_name(): void {
		$this->assertSame( 'old-theme', Themes::owner_stylesheet( 'option', 'theme_mods_old-theme' ) );
		$this->assertSame( 'old-theme', Themes::owner_stylesheet( 'template', 'old-theme' ) );
		$this->assertSame( '', Themes::owner_stylesheet( 'option', 'old_theme_options' ) );
		$this->assertSame( '', Themes::owner_stylesheet( 'postmeta', 'theme_mods_old-theme' ) );
	}

	public function test_settings_of_a_theme_not_in_the_ledger_are_likely(): void {
		$who = $this->attribution()->attribute( 'option', 'theme_mods_old-theme' );

		$this->assertSame( 'theme:old-theme', $who['owner'] );
		$this->assertSame( 'Theme old-theme', $who['owner_name'] );
		$this->assertSame( 'likely', $who['confidence'] );
		$this->assertFalse( $who['in_use'] );
		$this->assertSame( 'Named after the theme old-theme, which is not installed', $who['evidence'][0]['text'] );
		$this->assertArrayNotHasKey( 'when', $who['evidence'][0] );
	}

	public function test_a_theme_seen_being_deleted_is_certain_and_keeps_its_name(): void {
		$attribution = $this->attribution( array( 'theme:old-theme' => $this->entry( 'old-theme', 'Old Theme', '2026-11-01 10:00:00' ) ) );

		foreach ( array( 'option' => 'theme_mods_old-theme', 'template' => 'old-theme', 'globalstyles' => 'old-theme', 'customcss' => 'old-theme' ) as $type => $name ) {
			$who = $attribution->attribute( $type, $name );
			$this->assertSame( 'certain', $who['confidence'], $type );
			$this->assertSame( 'Old Theme', $who['owner_name'], $type );
			$this->assertSame( 'Left by the theme Old Theme, which was deleted', $who['evidence'][0]['text'], $type );
			$this->assertSame( '2026-11-01 10:00:00', $who['evidence'][0]['when'], $type );
		}
	}

	public function test_an_installed_theme_owns_its_names(): void {
		Functions\when( 'wp_get_themes' )->justReturn( array( 'astra' => true ) );
		Themes::flush();
		$attribution = $this->attribution( array( 'theme:astra' => $this->entry( 'astra', 'Astra', null, array( 'o' => array( 'astra_settings' ) ) ) ) );

		$this->assertTrue( $attribution->attribute( 'option', 'astra_settings' )['in_use'] );
		$this->assertTrue( $attribution->attribute( 'option', 'theme_mods_astra' )['in_use'] );
		$this->assertTrue( $attribution->attribute( 'template', 'astra' )['in_use'] );
	}

	public function test_names_in_a_deleted_themes_code_are_likely_and_listed_under_the_theme(): void {
		$attribution = $this->attribution( array( 'theme:old-theme' => $this->entry( 'old-theme', 'Old Theme', '2026-11-01 10:00:00', array( 'o' => array( 'old_theme_options' ), 'c' => array( 'old_theme_cache' ) ) ) ) );

		$option = $attribution->attribute( 'option', 'old_theme_options' );
		$this->assertSame( 'theme:old-theme', $option['owner'] );
		$this->assertSame( 'Old Theme', $option['owner_name'] );
		$this->assertSame( 'likely', $option['confidence'] );
		$this->assertSame( "Named in Old Theme's code", $option['evidence'][0]['text'] );
		$this->assertSame( 'theme:old-theme', $attribution->attribute( 'cron', 'old_theme_cache' )['owner'] );
	}

	public function test_a_theme_template_name_is_not_matched_against_plugin_signatures(): void {
		$attribution = $this->attribution( array( 'acme' => array( 'slug' => 'acme', 'name' => 'Acme', 'removed_gmt' => null, 'signature' => \AryanX\Axle\Leftovers\Signature::normalize( array( 'o' => array( 'old-theme' ) ) ) ) ) );

		$this->assertSame( 'theme:old-theme', $attribution->attribute( 'template', 'old-theme' )['owner'] );
	}

	public function test_inactive_widgets_are_counted_from_the_inactive_and_orphaned_areas(): void {
		$this->options['sidebars_widgets'] = array(
			'sidebar-1'          => array( 'search-2', 'text-3' ),
			'wp_inactive_widgets' => array( 'archives-2', 'meta-2' ),
			'orphaned_widgets_1' => array( 'calendar-2' ),
			'array_version'      => 3,
		);

		$this->assertSame( 3, Themes::inactive_widgets() );

		$this->options['sidebars_widgets'] = 'broken';
		$this->assertSame( 0, Themes::inactive_widgets() );
	}

	public function test_a_theme_switch_reads_both_themes_and_fires_the_action(): void {
		$ledger = \Mockery::mock( Ledger::class );
		$ledger->shouldReceive( 'get' )->andReturn( null );
		$ledger->shouldReceive( 'record_plugin' )->twice()->andReturnUsing(
			static function ( $file, $data, $sources ) {
				return array( 'slug' => $file, 'name' => $data['name'], 'sources' => $sources );
			}
		);
		Functions\when( 'wp_get_theme' )->alias(
			static function ( $stylesheet ) {
				return new FakeTheme( $stylesheet, true, array( 'Name' => 'Name of ' . $stylesheet ) );
			}
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );
		Actions\expectDone( 'axle_leftovers_theme_switched' )->once()->with( 'new-theme', 'old-theme' );

		$recorder = new Recorder( $ledger, \Mockery::mock( Inventory::class ) );
		$recorder->theme_switched( 'New Theme', new FakeTheme( 'new-theme' ), new FakeTheme( 'old-theme' ) );

		$this->assertTrue( Scanner::state()['stale'] );
	}

	public function test_a_deleted_theme_is_marked_removed_and_the_action_fires(): void {
		$entry  = $this->entry( 'old-theme', 'Old Theme', null );
		$ledger = \Mockery::mock( Ledger::class );
		$ledger->shouldReceive( 'mark_removed' )->once()->with( 'theme:old-theme', 'admin' );
		$ledger->shouldReceive( 'get' )->with( 'theme:old-theme' )->andReturn( $entry );
		Functions\when( 'wp_next_scheduled' )->justReturn( 123 );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Actions\expectDone( 'axle_leftovers_theme_removed' )->once()->with( 'old-theme', $entry );

		$recorder = new Recorder( $ledger, \Mockery::mock( Inventory::class ) );
		$recorder->after_theme_delete( 'old-theme', true );
		$recorder->after_theme_delete( 'other-theme', false );
	}

	public function test_a_theme_that_is_gone_has_no_signature_to_read(): void {
		Functions\when( 'wp_get_theme' )->justReturn( new FakeTheme( 'gone', false ) );
		$ledger = \Mockery::mock( Ledger::class );
		$ledger->shouldNotReceive( 'record_plugin' );

		$recorder = new Recorder( $ledger, \Mockery::mock( Inventory::class ) );

		$this->assertNull( $recorder->ensure_theme_signature( 'gone' ) );
	}

	public function test_owner_kind_and_the_theme_and_kind_filters(): void {
		$this->assertSame( 'theme', Scanner::owner_kind( 'theme:old-theme' ) );
		$this->assertSame( 'mu-plugin', Scanner::owner_kind( 'mu-plugin:x.php' ) );
		$this->assertSame( 'plugin', Scanner::owner_kind( 'acme' ) );
		$this->assertSame( '', Scanner::owner_kind( '' ) );

		$this->options[ Scanner::OPTION ] = array(
			'status' => 'done',
			'items'  => array(
				array( 'id' => 'option:a', 'type' => 'option', 'name' => 'a', 'scope' => 'site', 'owner' => 'acme', 'confidence' => 'certain', 'rows' => 1 ),
				array( 'id' => 'option:theme_mods_old', 'type' => 'option', 'name' => 'theme_mods_old', 'scope' => 'site', 'owner' => 'theme:old', 'confidence' => 'certain', 'rows' => 1 ),
				array( 'id' => 'template:old', 'type' => 'template', 'name' => 'old', 'scope' => 'site', 'owner' => 'theme:old', 'confidence' => 'certain', 'rows' => 14 ),
			),
		);
		$scanner = new Scanner( \Mockery::mock( Ledger::class ), \Mockery::mock( Inventory::class ), \Mockery::mock( Attribution::class ), \Mockery::mock( \AryanX\Axle\Leftovers\References::class ), \Mockery::mock( Recorder::class ) );

		$this->assertCount( 2, $scanner->items( array( 'theme' => 'old' ) ) );
		$this->assertCount( 1, $scanner->items( array( 'kind' => 'plugin' ) ) );
		$this->assertCount( 2, $scanner->items( array( 'kind' => 'theme' ) ) );
		$this->assertCount( 1, $scanner->items( array( 'theme' => 'old', 'type' => 'template' ) ) );
	}

	public function test_a_theme_card_counts_template_changes_not_items(): void {
		$this->options[ Scanner::OPTION ] = array(
			'status' => 'done',
			'items'  => array(
				array( 'id' => 'option:theme_mods_old', 'type' => 'option', 'name' => 'theme_mods_old', 'scope' => 'site', 'owner' => 'theme:old', 'owner_name' => 'Old Theme', 'confidence' => 'certain', 'rows' => 1, 'bytes' => 100, 'autoload' => true ),
				array( 'id' => 'template:old', 'type' => 'template', 'name' => 'old', 'scope' => 'site', 'owner' => 'theme:old', 'owner_name' => 'Old Theme', 'confidence' => 'certain', 'rows' => 14, 'bytes' => 5000, 'autoload' => false ),
			),
		);
		$ledger = \Mockery::mock( Ledger::class );
		$ledger->shouldReceive( 'all' )->andReturn( array() );
		$scanner = new Scanner( $ledger, \Mockery::mock( Inventory::class ), \Mockery::mock( Attribution::class ), \Mockery::mock( \AryanX\Axle\Leftovers\References::class ), \Mockery::mock( Recorder::class ) );

		$groups = $scanner->by_plugin();

		$this->assertSame( array( 'option' => 1, 'template' => 14 ), $groups['theme:old']['counts'] );
		$this->assertSame( 'theme', $groups['theme:old']['kind'] );
		$this->assertSame( 'Old Theme left 1 option and 14 template changes.', sprintf( '%s left %s.', $groups['theme:old']['owner_name'], \AryanX\Axle\Leftovers\Admin\Display::count_list( $groups['theme:old']['counts'] ) ) );
	}

	public function test_the_new_item_types_are_scanned_and_labelled(): void {
		$types = Settings::item_types();

		foreach ( array( 'template', 'globalstyles', 'customcss' ) as $type ) {
			$this->assertArrayHasKey( $type, $types );
			$this->assertContains( $type, Scanner::STEPS );
		}
	}

	public function test_content_types_ask_for_the_editing_capabilities_of_their_posts(): void {
		$this->assertSame( 'edit_theme_options', Settings::item_capability( 'template' ) );
		$this->assertSame( 'edit_theme_options', Settings::item_capability( 'globalstyles' ) );
		$this->assertSame( 'edit_css', Settings::item_capability( 'customcss' ) );
		$this->assertSame( 'manage_options', Settings::item_capability( 'table' ) );

		Filters\expectApplied( 'axle_leftovers_item_capability' )->once()->with( 'edit_css', 'customcss' )->andReturn( 'edit_pages' );
		$this->assertSame( 'edit_pages', Settings::item_capability( 'customcss' ) );
		Filters\expectApplied( 'axle_leftovers_item_capability' )->once()->andReturn( '  ' );
		$this->assertSame( 'edit_css', Settings::item_capability( 'customcss' ) );
	}
}
