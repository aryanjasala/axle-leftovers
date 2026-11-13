<?php
/**
 * Watching which post types and taxonomies register, and when one counts as unregistered.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Attribution;
use AryanX\Axle\Leftovers\Content;
use AryanX\Axle\Leftovers\Inventory;
use AryanX\Axle\Leftovers\Ledger;
use AryanX\Axle\Leftovers\Recorder;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Tests\FakeWpdb;
use AryanX\Axle\Leftovers\Observer;
use AryanX\Axle\Leftovers\References;
use AryanX\Axle\Leftovers\Settings;
use AryanX\Axle\Leftovers\Signature;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Canned answers for the grouped queries of a scan of post types.
 */
class GroupedWpdb extends FakeWpdb {
	public $posts              = 'wp_posts';
	public $comments           = 'wp_comments';
	public $term_relationships = 'wp_term_relationships';

	public function get_results( $prepared, $output = null ) {
		$query = is_array( $prepared ) ? $prepared['q'] : $prepared;
		if ( 0 === strpos( $query, 'SELECT post_type, post_status' ) ) {
			return array(
				array( 'post_type' => 'event', 'post_status' => 'publish', 'c' => '40', 'b' => '4000', 'first_gmt' => '2020-01-01 00:00:00', 'last_gmt' => '2024-01-01 00:00:00' ),
				array( 'post_type' => 'event', 'post_status' => 'draft', 'c' => '2', 'b' => '10', 'first_gmt' => '0000-00-00 00:00:00', 'last_gmt' => '0000-00-00 00:00:00' ),
				array( 'post_type' => 'page', 'post_status' => 'publish', 'c' => '9', 'b' => '90', 'first_gmt' => '2020-01-01 00:00:00', 'last_gmt' => '2024-01-01 00:00:00' ),
				array( 'post_type' => 'revision', 'post_status' => 'inherit', 'c' => '5', 'b' => '50', 'first_gmt' => '2020-01-01 00:00:00', 'last_gmt' => '2024-01-01 00:00:00' ),
				array( 'post_type' => 'wp_template', 'post_status' => 'publish', 'c' => '2', 'b' => '20', 'first_gmt' => '2020-01-01 00:00:00', 'last_gmt' => '2024-01-01 00:00:00' ),
				array( 'post_type' => 'recent_thing', 'post_status' => 'publish', 'c' => '3', 'b' => '30', 'first_gmt' => '2020-01-01 00:00:00', 'last_gmt' => '2024-01-01 00:00:00' ),
				array( 'post_type' => 'live_thing', 'post_status' => 'publish', 'c' => '3', 'b' => '30', 'first_gmt' => '2020-01-01 00:00:00', 'last_gmt' => '2024-01-01 00:00:00' ),
			);
		}
		if ( 0 === strpos( $query, 'SELECT p.post_type AS name' ) && false !== strpos( $query, 'x.post_id' ) ) {
			return array( array( 'name' => 'event', 'c' => '7' ) );
		}
		return parent::get_results( $prepared, $output );
	}
}

/**
 * The observation record, the verdict and the names around it.
 */
final class ObserverTest extends LeftoversTestCase {

	private const NOW = 1800000000;

	/**
	 * Stores a record, as it would look after some days of watching.
	 *
	 * @param array $state Record.
	 */
	private function state( array $state ): void {
		$this->options[ Observer::OPTION ] = $state;
	}

	/**
	 * A record that has watched front and admin since 10 days before NOW.
	 *
	 * @param array $types Post type => last registered.
	 */
	private function watched( array $types = array() ): void {
		$this->state(
			array(
				'since'      => self::NOW - 10 * DAY_IN_SECONDS,
				'kinds'      => array(
					'front' => array( 'first' => self::NOW - 10 * DAY_IN_SECONDS, 'last' => self::NOW - 60 ),
					'admin' => array( 'first' => self::NOW - 9 * DAY_IN_SECONDS, 'last' => self::NOW - 60 ),
				),
				'post_types' => $types,
				'taxonomies' => array(),
			)
		);
	}

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'post_type_exists' )->justReturn( false );
		Functions\when( 'taxonomy_exists' )->justReturn( false );
	}

	public function test_a_type_registered_right_now_is_registered(): void {
		Functions\when( 'post_type_exists' )->justReturn( true );
		$this->watched();

		$this->assertSame( 'registered', Observer::verdict( 'posttype', 'event', self::NOW )['status'] );
	}

	public function test_a_type_seen_in_the_last_days_is_recent_and_says_when_it_can_be_listed(): void {
		$this->watched( array( 'event' => self::NOW - 2 * DAY_IN_SECONDS ) );

		$verdict = Observer::verdict( 'posttype', 'event', self::NOW );

		$this->assertSame( 'recent', $verdict['status'] );
		$this->assertSame( self::NOW + 5 * DAY_IN_SECONDS, $verdict['ready'] );
	}

	public function test_a_type_not_seen_for_the_whole_period_is_unregistered_when_every_watched_kind_was_seen_that_long(): void {
		$this->watched( array( 'event' => self::NOW - 8 * DAY_IN_SECONDS ) );

		$verdict = Observer::verdict( 'posttype', 'event', self::NOW );

		$this->assertSame( 'unregistered', $verdict['status'] );
		$this->assertSame( 7, $verdict['days'] );
		$this->assertSame( self::NOW - 8 * DAY_IN_SECONDS, $verdict['last'] );
	}

	public function test_a_type_never_seen_is_unregistered_after_the_watch_period(): void {
		$this->watched();

		$this->assertSame( 'unregistered', Observer::verdict( 'posttype', 'event', self::NOW )['status'] );
	}

	public function test_a_kind_watched_for_less_than_the_period_keeps_the_type_in_watching(): void {
		$this->state(
			array(
				'since'      => self::NOW - 10 * DAY_IN_SECONDS,
				'kinds'      => array(
					'front' => array( 'first' => self::NOW - 10 * DAY_IN_SECONDS, 'last' => self::NOW ),
					'admin' => array( 'first' => self::NOW - 2 * DAY_IN_SECONDS, 'last' => self::NOW ),
				),
				'post_types' => array(),
				'taxonomies' => array(),
			)
		);

		$verdict = Observer::verdict( 'posttype', 'event', self::NOW );

		$this->assertSame( 'watching', $verdict['status'] );
		$this->assertSame( self::NOW + 5 * DAY_IN_SECONDS, $verdict['ready'], 'Admin was first seen 2 days ago, so 5 more days.' );
	}

	public function test_a_kind_never_seen_means_watching_a_full_period_from_now(): void {
		$this->state(
			array(
				'since'      => self::NOW - 30 * DAY_IN_SECONDS,
				'kinds'      => array( 'front' => array( 'first' => self::NOW - 30 * DAY_IN_SECONDS, 'last' => self::NOW ) ),
				'post_types' => array(),
				'taxonomies' => array(),
			)
		);

		$verdict = Observer::verdict( 'taxonomy', 'genre', self::NOW );

		$this->assertSame( 'watching', $verdict['status'] );
		$this->assertSame( self::NOW + 7 * DAY_IN_SECONDS, $verdict['ready'] );
	}

	public function test_the_days_filter_changes_the_verdict(): void {
		$this->watched();
		Filters\expectApplied( 'axle_leftovers_observe_days' )->once()->with( 7 )->andReturn( 20 );

		$this->assertSame( 'watching', Observer::verdict( 'posttype', 'event', self::NOW )['status'], '20 days is more than the 10 watched.' );
	}

	public function test_days_are_clamped(): void {
		Filters\expectApplied( 'axle_leftovers_observe_days' )->once()->andReturn( 99999 );

		$this->assertSame( 365, Settings::observe_days() );
	}

	public function test_unknown_kinds_fall_back_to_the_default(): void {
		Filters\expectApplied( 'axle_leftovers_observe_kinds' )->once()->andReturn( array( 'bogus' ) );

		$this->assertSame( array( 'front', 'admin' ), Settings::observe_kinds() );
	}

	public function test_the_limits_for_content_are_filtered_and_clamped(): void {
		Filters\expectApplied( 'axle_leftovers_content_max_rows' )->once()->with( 100000 )->andReturn( 5 );
		$this->assertSame( 1000, Settings::content_max_rows() );

		Filters\expectApplied( 'axle_leftovers_sample_size' )->once()->with( 5 )->andReturn( 500 );
		$this->assertSame( 20, Settings::sample_size() );
	}

	public function test_a_record_is_written_once_and_then_only_when_something_is_due(): void {
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_installing' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post', 'event' => 'event' ) );
		Functions\when( 'get_taxonomies' )->justReturn( array( 'category' => 'category' ) );
		$writes = 0;
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) use ( &$writes ) {
				++$writes;
				$this->options[ $name ] = $value;
				return true;
			}
		);

		Actions\expectDone( 'axle_leftovers_observation_recorded' )->times( 2 );
		$observer = new Observer();
		$observer->record( self::NOW );
		$this->assertSame( 1, $writes );
		$state = Observer::state();
		$this->assertSame( self::NOW, $state['since'] );
		$this->assertSame( self::NOW, $state['post_types']['event'] );
		$this->assertSame( self::NOW, $state['kinds']['front']['last'] );

		$observer->record( self::NOW + 600 );
		$this->assertSame( 1, $writes, 'Ten minutes later nothing is due.' );

		$observer->record( self::NOW + 2 * HOUR_IN_SECONDS );
		$this->assertSame( 2, $writes );
		$this->assertSame( self::NOW, Observer::state()['kinds']['front']['first'], 'The first sighting of a kind is kept.' );
	}

	public function test_a_new_post_type_is_written_at_once(): void {
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_installing' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'get_taxonomies' )->justReturn( array() );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
		$observer = new Observer();
		$observer->record( self::NOW );

		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post', 'late' => 'late' ) );
		$observer->record( self::NOW + 60 );

		$this->assertSame( self::NOW + 60, Observer::state()['post_types']['late'] );
		$this->assertArrayHasKey( 'admin', Observer::state()['kinds'] );
	}

	public function test_nothing_is_recorded_before_init_or_while_installing(): void {
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'wp_installing' )->justReturn( false );
		( new Observer() )->record( self::NOW );
		$this->assertArrayNotHasKey( Observer::OPTION, $this->options );

		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_installing' )->justReturn( true );
		( new Observer() )->record( self::NOW );
		$this->assertArrayNotHasKey( Observer::OPTION, $this->options );
	}

	public function test_the_report_lists_kinds_and_when_listing_can_start(): void {
		$this->watched();
		$report = Observer::report( self::NOW );

		$this->assertSame( 7, $report['days'] );
		$this->assertSame( '', $report['ready'], 'Ten days of front and nine of admin cover seven.' );
		$kinds = array_column( $report['kinds'], null, 'kind' );
		$this->assertTrue( $kinds['front']['required'] );
		$this->assertFalse( $kinds['cron']['required'] );
		$this->assertSame( 9, $kinds['admin']['watched_days'] );
	}

	public function test_a_scan_lists_unregistered_types_and_reports_the_ones_still_watched(): void {
		global $wpdb;
		$wpdb = new GroupedWpdb();
		$this->watched( array( 'event' => time() - 12 * DAY_IN_SECONDS, 'recent_thing' => time() - DAY_IN_SECONDS ) );
		$this->options[ Observer::OPTION ]['since']                = time() - 10 * DAY_IN_SECONDS;
		$this->options[ Observer::OPTION ]['kinds']['front']['first'] = time() - 10 * DAY_IN_SECONDS;
		$this->options[ Observer::OPTION ]['kinds']['admin']['first'] = time() - 10 * DAY_IN_SECONDS;
		Functions\when( 'post_type_exists' )->alias(
			static function ( $name ) {
				return 'live_thing' === $name;
			}
		);
		Functions\when( 'get_plugins' )->justReturn( array() );
		Functions\when( 'wp_get_theme' )->justReturn( new \AryanX\Axle\Leftovers\Tests\FakeTheme( 'twentytwentyfive', false ) );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Filters\expectApplied( 'axle_leftovers_item_types' )->andReturn( array( 'posttype' => 'Post type content' ) );

		$ledger = \Mockery::mock( Ledger::class );
		$ledger->shouldReceive( 'all' )->andReturn( array() );
		$attribution = \Mockery::mock( Attribution::class );
		$attribution->shouldReceive( 'flush' );
		$attribution->shouldReceive( 'attribute' )->with( 'posttype', 'event' )->andReturn( array( 'owner' => 'events', 'owner_name' => 'Events', 'confidence' => 'certain', 'evidence' => array( array( 'code' => 'runtime', 'text' => 'Seen being created by Events' ) ), 'in_use' => false, 'in_use_by' => '' ) );
		$references = \Mockery::mock( References::class );
		$references->shouldReceive( 'check' )->andReturn( array() );
		Functions\when( 'number_format_i18n' )->returnArg();

		$scanner = new Scanner( $ledger, \Mockery::mock( Inventory::class ), $attribution, $references, \Mockery::mock( Recorder::class ) );
		$scanner->run_all();

		$items = $scanner->items();
		$this->assertSame( array( 'posttype:event' ), array_column( $items, 'id' ), 'Core types, theme content, revisions, a registered type and a recently seen type are not listed.' );
		$this->assertSame( 42, $items[0]['rows'] );
		$this->assertSame( 4010, $items[0]['bytes'] );
		$this->assertEquals( array( 'draft' => 2, 'publish' => 40 ), $items[0]['detail']['statuses'] );
		$this->assertSame( '2020-01-01 00:00:00', $items[0]['detail']['first'] );
		$this->assertSame( 7, $items[0]['detail']['meta'] );
		$this->assertSame( 0, $items[0]['detail']['comments'] );
		$this->assertSame( 'observed', end( $items[0]['evidence'] )['code'] );

		$watching = Scanner::state()['watching'];
		$this->assertSame( array( 'recent_thing' ), array_column( $watching, 'name' ) );
		$this->assertSame( 'recent', $watching[0]['status'] );
		$this->assertSame( array( 'posttype' => 42 ), $scanner->by_plugin()['events']['counts'] );
	}

	public function test_signatures_name_the_post_types_and_taxonomies_code_registers(): void {
		$signature = Signature::scan_source( "<?php register_post_type( 'acme_event', array() ); register_taxonomy( 'acme_genre', 'acme_event' ); register_post_type( \$dynamic );" );

		$this->assertSame( array( 'acme_event' ), $signature['y'] );
		$this->assertSame( array( 'acme_genre' ), $signature['x'] );
	}

	public function test_only_a_registration_counts_as_a_reference(): void {
		$pattern = References::registration_pattern( 'posttype', 'event' );

		$this->assertSame( 1, preg_match( $pattern, "register_post_type( 'event', \$args );" ) );
		$this->assertSame( 1, preg_match( $pattern, 'register_post_type("event"' ) );
		$this->assertSame( 0, preg_match( $pattern, "\$type = 'event';" ) );
		$this->assertSame( 0, preg_match( $pattern, "register_post_type( 'event_log'" ) );
		$this->assertSame( 1, preg_match( References::registration_pattern( 'taxonomy', 'genre' ), "register_taxonomy( 'genre', 'post' );" ) );
	}

	public function test_the_evidence_line_says_how_long_and_when_it_was_last_seen(): void {
		$this->watched( array( 'event' => self::NOW - 8 * DAY_IN_SECONDS ) );
		Functions\when( 'number_format_i18n' )->returnArg();

		$line = Content::evidence( 'posttype', 'event' );

		$this->assertSame( 'observed', $line['code'] );
		$this->assertStringContainsString( 'Not registered by any code in the last 7 days', $line['text'] );
		$this->assertArrayHasKey( 'when', $line );
	}
}
