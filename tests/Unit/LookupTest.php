<?php
/**
 * The opt-in online lookup.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Attribution;
use AryanX\Axle\Leftovers\Dataset;
use AryanX\Axle\Leftovers\Ledger;
use AryanX\Axle\Leftovers\Lookup;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Settings;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Cache, rate limit, confidence, filters and the item guard of Lookup.
 */
final class LookupTest extends LeftoversTestCase {

	use MocksHttp;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_plugins' )->justReturn( array() );
		Functions\when( 'get_mu_plugins' )->justReturn( array() );
		Functions\when( 'wp_get_themes' )->justReturn( array() );
		Filters\expectApplied( 'axle_leftovers_lookup_poll_interval' )->andReturn( 0 );
	}

	/**
	 * Veloria's answer to a finished search.
	 */
	private function finished( array $plugins ): array {
		return $this->json_response(
			array(
				'id'      => 'abc12345-0000',
				'status'  => 'completed',
				'results' => array( 'results' => $plugins, 'total' => 80000 ),
			)
		);
	}

	/**
	 * Mocks Veloria: the POST creates a search, the GET returns it finished.
	 */
	private function veloria( array $plugins ): void {
		$this->mock_http(
			function ( $method ) use ( $plugins ) {
				return 'POST' === $method ? $this->json_response( array( 'id' => 'abc12345-0000', 'status' => 'queued' ) ) : $this->finished( $plugins );
			}
		);
	}

	private function plugin( string $slug, int $installs ): array {
		return array( 'slug' => $slug, 'name' => ucfirst( $slug ), 'active_installs' => $installs, 'matches' => array() );
	}

	private function enable(): void {
		$this->options[ Settings::OPTION ] = array( 'online_lookup' => true );
	}

	public function test_off_by_default_and_sends_nothing(): void {
		$this->mock_http( fn() => $this->fail( 'No request while the setting is off.' ) );

		$result = ( new Lookup() )->find( 'option', 'acme_widget_colour' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'axle_leftovers_lookup_off', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_the_enabled_filter_can_only_turn_it_off(): void {
		Filters\expectApplied( 'axle_leftovers_lookup_enabled' )->once()->with( true )->andReturn( false );
		$this->enable();
		$this->assertFalse( Settings::online_lookup() );

		// With the setting off the filter is not even asked.
		Settings::save( array() );
		$this->assertFalse( Settings::online_lookup() );
	}

	public function test_a_unique_match_is_likely_and_is_cached(): void {
		$this->enable();
		$this->veloria( array( $this->plugin( 'acme-forms', 5000 ) ) );
		Actions\expectDone( 'axle_leftovers_lookup_done' )->once();

		$lookup = new Lookup();
		$result = $lookup->find( 'option', 'acme_widget_colour' );

		$this->assertSame( 'likely', $result['confidence'] );
		$this->assertSame( 'acme-forms', $result['candidates'][0]['slug'] );
		$this->assertSame( 'https://wordpress.org/plugins/acme-forms/', $result['candidates'][0]['url'] );
		$this->assertSame( 'POST', $this->requests[0][0] );
		$body = json_decode( $this->requests[0][2]['body'], true );
		$this->assertSame( 'acme_widget_colour', $body['term'] );
		$this->assertSame( 'plugins', $body['repo'] );
		$this->assertFalse( $body['public'] );
		$this->assertSame( 'https://veloria.dev/api/v1/search/abc12345-0000', $this->requests[1][1] );

		// Second call: from the cache, no request.
		$before = count( $this->requests );
		$this->assertSame( $result, $lookup->find( 'option', 'acme_widget_colour' ) );
		$this->assertCount( $before, $this->requests );
		$this->assertNotNull( $lookup->cached( 'option', 'acme_widget_colour' ) );
		$this->assertArrayHasKey( Lookup::OPTION, $this->options );
	}

	public function test_several_matches_are_a_guess_most_installed_first(): void {
		$this->enable();
		$this->veloria( array( $this->plugin( 'small', 10 ), $this->plugin( 'big', 9000 ), $this->plugin( 'mid', 500 ) ) );

		$result = ( new Lookup() )->find( 'cron', 'shared_cleanup_hook' );

		$this->assertSame( 'guess', $result['confidence'] );
		$this->assertSame( array( 'big', 'mid', 'small' ), array_column( $result['candidates'], 'slug' ) );
		$this->assertSame( 3, $result['total'] );
	}

	public function test_no_match_and_too_many_matches_give_no_owner_but_are_cached(): void {
		$this->enable();
		$this->veloria( array() );
		$none = ( new Lookup() )->find( 'option', 'nobody_has_this_name' );
		$this->assertSame( '', $none['confidence'] );
		$this->assertSame( array(), $none['candidates'] );

		Filters\expectApplied( 'axle_leftovers_lookup_max_matches' )->once()->andReturn( 2 );
		$this->transients = array();
		$this->veloria( array( $this->plugin( 'a', 3 ), $this->plugin( 'b', 2 ), $this->plugin( 'c', 1 ) ) );
		$many = ( new Lookup() )->find( 'option', 'too_common_name' );
		$this->assertSame( '', $many['confidence'] );
		$this->assertSame( 3, $many['total'] );
		$this->assertSame( array(), $many['candidates'] );
	}

	public function test_short_names_are_refused_without_a_request(): void {
		$this->enable();
		$this->mock_http( fn() => $this->fail( 'Too short to send.' ) );

		$result = ( new Lookup() )->find( 'option', 'abc' );

		$this->assertSame( 'axle_leftovers_lookup_short', $result->get_error_code() );
	}

	public function test_tables_are_searched_without_the_site_prefix(): void {
		$this->assertSame( 'acme_logs', Lookup::bare( 'table', 'wp_acme_logs' ) );
		$this->assertSame( 'acme_cache', Lookup::bare( 'option', '_transient_acme_cache' ) );
		Filters\expectApplied( 'axle_leftovers_lookup_term' )->once()->with( 'acme_logs', 'table', 'wp_acme_logs' )->andReturn( 'acme\\_logs' );
		$this->assertSame( 'acme\\_logs', Lookup::term( 'table', 'wp_acme_logs' ) );
		$this->assertSame( 'a\.b\-c', preg_replace( '/\\\\\//', '/', Lookup::term( 'option', 'a.b-c' ) ) );
	}

	public function test_requests_are_spaced_out(): void {
		$this->enable();
		$this->veloria( array( $this->plugin( 'acme-forms', 5000 ) ) );
		$lookup = new Lookup();
		$lookup->find( 'option', 'first_option_name' );

		$second = $lookup->find( 'option', 'second_option_name' );

		$this->assertSame( 'axle_leftovers_lookup_wait', $second->get_error_code() );
		$this->assertCount( 2, $this->requests, 'The second lookup sent nothing.' );

		Filters\expectApplied( 'axle_leftovers_lookup_min_interval' )->once()->andReturn( 0 );
		$this->assertSame( 1, Lookup::min_interval(), 'Clamped to 1 second.' );
		Filters\expectApplied( 'axle_leftovers_lookup_min_interval' )->once()->andReturn( 99999 );
		$this->assertSame( 3600, Lookup::min_interval() );
	}

	public function test_a_429_backs_off_for_a_minute(): void {
		$this->enable();
		$this->mock_http( fn() => $this->json_response( array( 'message' => 'rate limited' ), 429 ) );

		$result = ( new Lookup() )->find( 'option', 'some_option_name' );

		$this->assertSame( 'axle_leftovers_lookup_limited', $result->get_error_code() );
		$this->assertGreaterThan( time() + 50, $this->transients[ Lookup::LOCK ] );
	}

	public function test_bad_answers_are_errors_and_nothing_is_cached(): void {
		$this->enable();
		$this->mock_http( fn() => array( 'body' => 'not json', 'response' => array( 'code' => 200 ) ) );
		$this->assertSame( 'axle_leftovers_lookup_http', ( new Lookup() )->find( 'option', 'some_option_name' )->get_error_code() );

		$this->mock_http( fn() => new \WP_Error( 'http_request_failed', 'cURL error 28' ) );
		$this->assertSame( 'axle_leftovers_lookup_http', ( new Lookup() )->find( 'option', 'other_option_name' )->get_error_code() );

		$this->mock_http( fn() => $this->json_response( array( 'id' => '../../etc', 'status' => 'queued' ) ) );
		$this->assertSame( 'axle_leftovers_lookup_bad', ( new Lookup() )->find( 'option', 'third_option_name' )->get_error_code() );

		$this->assertArrayNotHasKey( Lookup::OPTION, $this->options );
	}

	public function test_a_search_that_never_finishes_is_pending_after_the_poll_limit(): void {
		$this->enable();
		Filters\expectApplied( 'axle_leftovers_lookup_polls' )->once()->andReturn( 2 );
		$this->mock_http(
			function ( $method ) {
				return $this->json_response( array( 'id' => 'abc12345-0000', 'status' => 'in_progress' ) );
			}
		);

		$result = ( new Lookup() )->find( 'option', 'slow_search_option' );

		$this->assertSame( 'axle_leftovers_lookup_pending', $result->get_error_code() );
		$this->assertCount( 3, $this->requests, 'One POST and two polls.' );
	}

	public function test_a_failed_search_and_the_documented_envelope(): void {
		$this->enable();
		$this->mock_http(
			function ( $method ) {
				return 'POST' === $method
					? $this->json_response( array( 'status' => 200, 'data' => array( 'id' => 'abc12345-0000', 'status' => 'queued' ) ) )
					: $this->json_response( array( 'status' => 200, 'data' => array( 'status' => 'failed' ) ) );
			}
		);

		$this->assertSame( 'axle_leftovers_lookup_failed', ( new Lookup() )->find( 'option', 'enveloped_option' )->get_error_code() );
	}

	public function test_the_endpoint_filter_and_https_only(): void {
		$this->mock_http( fn() => array() );
		$this->assertSame( 'https://veloria.dev/api/v1/search/', Lookup::endpoint() );

		Filters\expectApplied( 'axle_leftovers_lookup_endpoint' )->once()->andReturn( 'https://search.example.test/api/v1/search' );
		$this->assertSame( 'https://search.example.test/api/v1/search/', Lookup::endpoint() );

		Filters\expectApplied( 'axle_leftovers_lookup_endpoint' )->once()->andReturn( 'http://insecure.example.test/' );
		$this->assertSame( Lookup::ENDPOINT, Lookup::endpoint() );
	}

	public function test_cache_days_and_candidate_limits_are_clamped(): void {
		Filters\expectApplied( 'axle_leftovers_lookup_cache_days' )->once()->andReturn( 0 );
		$this->assertSame( 1, Lookup::cache_days() );
		Filters\expectApplied( 'axle_leftovers_lookup_max_candidates' )->once()->andReturn( 999 );
		$this->assertSame( 20, Lookup::max_candidates() );
		Filters\expectApplied( 'axle_leftovers_lookup_max_matches' )->once()->andReturn( 0 );
		$this->assertSame( 1, Lookup::max_matches() );
	}

	public function test_an_expired_cache_entry_is_ignored(): void {
		$lookup                  = new Lookup();
		$this->options[ Lookup::OPTION ] = array(
			Lookup::key( 'option', 'old_option_name' ) => array( 'time' => time() - 40 * DAY_IN_SECONDS, 'total' => 1, 'confidence' => 'likely', 'candidates' => array() ),
			Lookup::key( 'option', 'new_option_name' ) => array( 'time' => time() - DAY_IN_SECONDS, 'total' => 0, 'confidence' => '', 'candidates' => array() ),
		);

		$this->assertNull( $lookup->cached( 'option', 'old_option_name' ) );
		$this->assertNotNull( $lookup->cached( 'option', 'new_option_name' ) );
		$lookup->clear();
		$this->assertArrayNotHasKey( Lookup::OPTION, $this->options );
	}

	public function test_only_listed_items_without_an_owner_are_looked_up(): void {
		$this->enable();
		$this->mock_http( fn() => $this->fail( 'Nothing may be sent for these.' ) );
		$scanner = \Mockery::mock( Scanner::class );
		$scanner->shouldReceive( 'item' )->with( 'option', 'siteurl' )->andReturn( null );
		$scanner->shouldReceive( 'item' )->with( 'option', 'acme_known_option' )->andReturn( array( 'confidence' => 'certain' ) );

		$lookup = new Lookup();
		$this->assertSame( 'axle_leftovers_not_listed', $lookup->for_item( $scanner, 'option', 'siteurl' )->get_error_code() );
		$this->assertSame( 'axle_leftovers_lookup_owned', $lookup->for_item( $scanner, 'option', 'acme_known_option' )->get_error_code() );
	}

	public function test_for_item_looks_up_and_works_the_owner_out_again(): void {
		$this->enable();
		$this->veloria( array( $this->plugin( 'acme-forms', 5000 ) ) );
		$scanner = \Mockery::mock( Scanner::class );
		$scanner->shouldReceive( 'item' )->with( 'option', 'acme_widget_colour' )->andReturn( array( 'confidence' => 'unknown' ) );
		$scanner->shouldReceive( 'reattribute' )->once()->with( 'option', 'acme_widget_colour' )->andReturn( array( 'owner' => 'acme-forms' ) );

		$result = ( new Lookup() )->for_item( $scanner, 'option', 'acme_widget_colour' );

		$this->assertSame( 'likely', $result['result']['confidence'] );
		$this->assertSame( array( 'owner' => 'acme-forms' ), $result['item'] );
	}

	public function test_attribution_uses_a_cached_lookup_as_evidence(): void {
		$lookup = new Lookup();
		$this->options[ Lookup::OPTION ] = array(
			Lookup::key( 'option', 'acme_widget_colour' ) => array(
				'time'       => time(),
				'total'      => 1,
				'confidence' => 'likely',
				'candidates' => array( array( 'slug' => 'acme-forms', 'name' => 'Acme Forms', 'installs' => 5000, 'url' => 'https://wordpress.org/plugins/acme-forms/' ) ),
			),
			Lookup::key( 'option', 'shared_name_option' ) => array(
				'time'       => time(),
				'total'      => 2,
				'confidence' => 'guess',
				'candidates' => array(
					array( 'slug' => 'big', 'name' => 'Big', 'installs' => 900, 'url' => 'x' ),
					array( 'slug' => 'small', 'name' => 'Small', 'installs' => 9, 'url' => 'x' ),
				),
			),
		);
		$ledger = \Mockery::mock( Ledger::class );
		$ledger->shouldReceive( 'seen_all' )->andReturn( array() );
		$ledger->shouldReceive( 'all' )->andReturn( array() );
		$file = tempnam( sys_get_temp_dir(), 'alo' );
		file_put_contents( $file, '{"plugins":{}}' );
		$attribution = new Attribution( $ledger, new Dataset( $file ), $lookup );

		$one = $attribution->attribute( 'option', 'acme_widget_colour' );
		$this->assertSame( 'acme-forms', $one['owner'] );
		$this->assertSame( 'Acme Forms', $one['owner_name'] );
		$this->assertSame( 'likely', $one['confidence'] );
		$this->assertSame( 'lookup', $one['evidence'][0]['code'] );
		$this->assertStringContainsString( 'only Acme Forms', $one['evidence'][0]['text'] );

		$two = $attribution->attribute( 'option', 'shared_name_option' );
		$this->assertSame( 'big', $two['owner'] );
		$this->assertSame( 'guess', $two['confidence'] );
		$this->assertStringContainsString( 'Big, Small', $two['evidence'][0]['text'] );

		$none = $attribution->attribute( 'option', 'never_looked_up_option' );
		$this->assertSame( 'unknown', $none['confidence'] );
	}
}
