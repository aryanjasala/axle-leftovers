<?php
/**
 * The opt-in download of the full signature list.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Abilities;
use AryanX\Axle\Leftovers\Cli\Command;
use AryanX\Axle\Leftovers\Dataset;
use AryanX\Axle\Leftovers\DatasetUpdater;
use AryanX\Axle\Leftovers\Ledger;
use AryanX\Axle\Leftovers\Lookup;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Settings;
use AryanX\Axle\Leftovers\Storage;
use AryanX\Axle\Leftovers\Trash;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Download, verification, storage and merge of the full list, plus the CLI
 * commands and abilities that use it.
 */
final class DatasetUpdaterTest extends LeftoversTestCase {

	use MocksHttp;

	/**
	 * Files "stored" by the mocked storage.
	 *
	 * @var array<string, string>
	 */
	private $files = array();

	protected function setUp(): void {
		parent::setUp();
		$this->files = array();
		\WP_CLI::$lines = array();
		Functions\when( 'wp_next_scheduled' )->justReturn( true );
	}

	private function storage(): Storage {
		$storage = \Mockery::mock( Storage::class );
		$storage->shouldReceive( 'dir' )->andReturn( '/uploads/axle-leftovers/dataset' );
		$storage->shouldReceive( 'put' )->andReturnUsing(
			function ( $path, $content ) {
				$this->files[ $path ] = $content;
				return true;
			}
		);
		$storage->shouldReceive( 'get' )->andReturnUsing(
			function ( $path ) {
				return $this->files[ $path ] ?? '';
			}
		);
		$storage->shouldReceive( 'delete' )->andReturnUsing(
			function ( $path ) {
				unset( $this->files[ $path ] );
				return true;
			}
		);

		return $storage;
	}

	private function list_json( ?array $plugins = null ): string {
		return (string) json_encode(
			array(
				'version' => 1,
				'built'   => '2026-10-01',
				'plugins' => $plugins ?? array(
					'acme-forms' => array( 'name' => 'Acme Forms', 'o' => array( 'acme_settings' ), 'p' => array( 'acme_' ) ),
					'other'      => array( 'name' => 'Other', 't' => array( 'other_log' ) ),
				),
			)
		);
	}

	/**
	 * Mocks raw.githubusercontent.com: the file and its checksum.
	 */
	private function github( string $gz, ?string $sum = null, int $file_code = 200 ): void {
		$sum = $sum ?? hash( 'sha256', $gz );
		$this->mock_http(
			function ( $method, $url ) use ( $gz, $sum, $file_code ) {
				if ( '.sha256' === substr( $url, -7 ) ) {
					return array( 'body' => $sum . "  signatures.json.gz\n", 'response' => array( 'code' => 200 ) );
				}
				return array( 'body' => $gz, 'response' => array( 'code' => $file_code ) );
			}
		);
	}

	private function enable(): void {
		$this->options[ Settings::OPTION ] = array( 'remote_dataset' => true );
	}

	public function test_off_by_default_and_sends_nothing(): void {
		$this->mock_http( fn() => $this->fail( 'No request while the setting is off.' ) );

		$result = ( new DatasetUpdater( $this->storage() ) )->download();

		$this->assertSame( 'axle_leftovers_dataset_off', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_the_enabled_filter_can_only_turn_the_download_off(): void {
		$this->enable();
		Filters\expectApplied( 'axle_leftovers_dataset_download_enabled' )->once()->with( true )->andReturn( false );
		$this->assertFalse( Settings::remote_dataset() );
	}

	public function test_a_verified_download_is_stored_and_merged_with_the_bundled_list(): void {
		$this->enable();
		$gz = gzencode( $this->list_json() );
		$this->github( $gz );
		Actions\expectDone( 'axle_leftovers_dataset_downloaded' )->once()->with( \Mockery::on( fn( $meta ) => 2 === $meta['count'] && '2026-10-01' === $meta['built'] ) );
		$updater = new DatasetUpdater( $this->storage() );

		$meta = $updater->download();

		$this->assertSame( 2, $meta['count'] );
		$this->assertSame( strlen( $gz ), $meta['size'] );
		$this->assertSame( hash( 'sha256', $gz ), $meta['sha256'] );
		$this->assertSame( $gz, $this->files['/uploads/axle-leftovers/dataset/signatures.json.gz'] );
		$this->assertSame( $meta, $this->options[ DatasetUpdater::OPTION ] );
		$this->assertSame( DatasetUpdater::URL . '.sha256', $this->requests[0][1] );
		$this->assertSame( DatasetUpdater::URL, $this->requests[1][1] );
		$this->assertSame( 15 * MB_IN_BYTES + 1, $this->requests[1][2]['limit_response_size'] );
		$this->assertTrue( $this->options[ Scanner::OPTION ]['stale'] );

		// The merge: downloaded wins for a slug in both, the bundled list fills the rest.
		$bundled = tempnam( sys_get_temp_dir(), 'alo' );
		file_put_contents( $bundled, json_encode( array( 'built' => '2026-09-01', 'plugins' => array( 'acme-forms' => array( 'name' => 'Old Acme', 'o' => array( 'old_option' ) ), 'bundled-only' => array( 'name' => 'Bundled Only', 'o' => array( 'bundled_option' ) ) ) ) ) );
		$dataset = new Dataset( $bundled, $updater );

		$plugins = $dataset->plugins();
		$this->assertSame( array( 'acme-forms', 'other', 'bundled-only' ), array_keys( $plugins ) );
		$this->assertSame( 'Acme Forms', $plugins['acme-forms']['name'] );
		$this->assertSame( array( 'acme_settings' ), $plugins['acme-forms']['o'] );
		$info = $dataset->info();
		$this->assertSame( 3, $info['count'] );
		$this->assertSame( 2, $info['bundled_count'] );
		$this->assertSame( '2026-09-01', $info['bundled_built'] );
		$this->assertSame( '2026-10-01', $info['downloaded']['built'] );
	}

	public function test_without_a_download_the_bundled_list_is_all_there_is(): void {
		$bundled = tempnam( sys_get_temp_dir(), 'alo' );
		file_put_contents( $bundled, json_encode( array( 'built' => '2026-09-01', 'plugins' => array( 'bundled-only' => array( 'name' => 'Bundled Only' ) ) ) ) );

		$dataset = new Dataset( $bundled, new DatasetUpdater( $this->storage() ) );

		$this->assertSame( array( 'bundled-only' ), array_keys( $dataset->plugins() ) );
		$this->assertNull( $dataset->info()['downloaded'] );
	}

	public function test_a_wrong_checksum_is_discarded(): void {
		$this->enable();
		$this->github( gzencode( $this->list_json() ), str_repeat( 'a', 64 ) );

		$result = ( new DatasetUpdater( $this->storage() ) )->download();

		$this->assertSame( 'axle_leftovers_dataset_checksum', $result->get_error_code() );
		$this->assertSame( array(), $this->files );
		$this->assertArrayNotHasKey( DatasetUpdater::OPTION, $this->options );
	}

	public function test_a_missing_or_malformed_checksum_file_is_an_error(): void {
		$this->enable();
		$this->github( gzencode( $this->list_json() ), 'not a checksum' );
		$this->assertSame( 'axle_leftovers_dataset_checksum', ( new DatasetUpdater( $this->storage() ) )->download()->get_error_code() );

		$this->mock_http( fn() => array( 'body' => '', 'response' => array( 'code' => 404 ) ) );
		$this->assertSame( 'axle_leftovers_dataset_http', ( new DatasetUpdater( $this->storage() ) )->download()->get_error_code() );
	}

	public function test_a_download_over_the_size_limit_is_discarded(): void {
		$this->enable();
		Filters\expectApplied( 'axle_leftovers_dataset_max_bytes' )->andReturn( 102400 );
		$big = str_repeat( 'x', 102401 );
		$this->github( $big );

		$result = ( new DatasetUpdater( $this->storage() ) )->download();

		$this->assertSame( 'axle_leftovers_dataset_large', $result->get_error_code() );
		$this->assertSame( array(), $this->files );
	}

	public function test_the_size_limit_filter_is_clamped(): void {
		Filters\expectApplied( 'axle_leftovers_dataset_max_bytes' )->once()->andReturn( 5 );
		$this->assertSame( 102400, DatasetUpdater::max_bytes() );
		Filters\expectApplied( 'axle_leftovers_dataset_max_bytes' )->once()->andReturn( PHP_INT_MAX );
		$this->assertSame( 104857600, DatasetUpdater::max_bytes() );
	}

	public function test_bad_gzip_and_bad_shapes_are_discarded(): void {
		$this->enable();
		$this->github( 'plain text, not gzip' );
		$this->assertSame( 'axle_leftovers_dataset_unpack', ( new DatasetUpdater( $this->storage() ) )->download()->get_error_code() );

		foreach (
			array(
				'not json',
				json_encode( array( 'version' => 2, 'built' => '2026-10-01', 'plugins' => array( 'a' => array( 'name' => 'A' ) ) ) ),
				json_encode( array( 'version' => 1, 'built' => 'yesterday', 'plugins' => array( 'a' => array( 'name' => 'A' ) ) ) ),
				json_encode( array( 'version' => 1, 'built' => '2026-10-01', 'plugins' => array() ) ),
				json_encode( array( 'version' => 1, 'built' => '2026-10-01', 'plugins' => array( 'a' => array( 'o' => array( 'x' ) ) ) ) ),
				json_encode( array( 'version' => 1, 'built' => '2026-10-01', 'plugins' => array( 'a' => array( 'name' => 'A', 'evil' => array( 'x' ) ) ) ) ),
				json_encode( array( 'version' => 1, 'built' => '2026-10-01', 'plugins' => array( 'a' => array( 'name' => 'A', 'o' => 'not-a-list' ) ) ) ),
				json_encode( array( 'version' => 1, 'built' => '2026-10-01', 'plugins' => array( '../etc' => array( 'name' => 'A' ) ) ) ),
			) as $json
		) {
			$this->github( gzencode( $json ) );
			$this->assertSame( 'axle_leftovers_dataset_shape', ( new DatasetUpdater( $this->storage() ) )->download()->get_error_code(), $json );
		}
		$this->assertSame( array(), $this->files );
	}

	public function test_the_url_filter_is_https_only_and_the_checksum_follows_it(): void {
		$this->assertSame( DatasetUpdater::URL, DatasetUpdater::url() );
		$this->mock_http( fn() => array() );

		Filters\expectApplied( 'axle_leftovers_dataset_url' )->once()->andReturn( 'https://mirror.example.test/s.json.gz' );
		$this->assertSame( 'https://mirror.example.test/s.json.gz', DatasetUpdater::url() );

		Filters\expectApplied( 'axle_leftovers_dataset_url' )->once()->andReturn( 'http://mirror.example.test/s.json.gz' );
		$this->assertSame( DatasetUpdater::URL, DatasetUpdater::url() );
	}

	public function test_remove_deletes_the_file_and_falls_back_to_the_bundled_list(): void {
		$this->enable();
		$this->github( gzencode( $this->list_json() ) );
		$updater = new DatasetUpdater( $this->storage() );
		$updater->download();
		Actions\expectDone( 'axle_leftovers_dataset_removed' )->once();

		$updater->remove();

		$this->assertSame( array(), $this->files );
		$this->assertNull( DatasetUpdater::meta() );
		$this->assertSame( array(), $updater->plugins() );
	}

	public function test_remove_works_with_the_setting_off_and_twice(): void {
		$this->enable();
		$this->github( gzencode( $this->list_json() ) );
		$updater = new DatasetUpdater( $this->storage() );
		$updater->download();
		$this->options[ Settings::OPTION ]['remote_dataset'] = false;
		$this->assertNotEmpty( $updater->plugins(), 'A downloaded list stays in use with the setting off.' );
		Actions\expectDone( 'axle_leftovers_dataset_removed' )->twice();

		$updater->remove();
		$updater->remove();

		$this->assertSame( array(), $updater->plugins() );
		$this->assertNull( DatasetUpdater::meta() );
	}

	public function test_the_delete_ability_reports_whether_a_list_was_deleted(): void {
		$this->enable();
		$this->github( gzencode( $this->list_json() ) );
		$updater = new DatasetUpdater( $this->storage() );
		$updater->download();
		$abilities = new Abilities( \Mockery::mock( Scanner::class ), \Mockery::mock( Trash::class ), $updater );
		Actions\expectDone( 'axle_leftovers_dataset_removed' )->twice();

		$this->assertSame( array( 'deleted' => true ), $abilities->delete_dataset() );
		$this->assertSame( array( 'deleted' => false ), $abilities->delete_dataset() );
		$this->assertNull( DatasetUpdater::meta() );
	}

	public function test_the_cli_dataset_command(): void {
		$command = new Command( \Mockery::mock( Scanner::class ), \Mockery::mock( Trash::class ), \Mockery::mock( Ledger::class ), new DatasetUpdater( $this->storage() ) );

		try {
			$command->dataset( array( 'download' ), array() );
			$this->fail( 'The download is off, so the command errors.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Downloading the full list is off', $e->getMessage() );
		}

		$command->dataset( array( 'status' ), array() );
		$lines = array_column( \WP_CLI::$lines, 1 );
		$this->assertStringContainsString( 'Full list: not downloaded.', implode( "\n", array_map( 'strval', $lines ) ) );

		Actions\expectDone( 'axle_leftovers_dataset_removed' )->twice();
		foreach ( array( 'delete', 'remove' ) as $word ) {
			$command->dataset( array( $word ), array() );
			$this->assertStringContainsString( 'Deleted the downloaded list', (string) end( \WP_CLI::$lines )[1] );
		}

		$this->expectException( \RuntimeException::class );
		$command->dataset( array( 'nonsense' ), array() );
	}

	public function test_the_cli_lookup_command_prints_the_candidates(): void {
		$this->options[ Settings::OPTION ] = array( 'online_lookup' => true );
		Filters\expectApplied( 'axle_leftovers_lookup_poll_interval' )->andReturn( 0 );
		$this->mock_http(
			function ( $method ) {
				return 'POST' === $method
					? $this->json_response( array( 'id' => 'abc12345-0000', 'status' => 'queued' ) )
					: $this->json_response( array( 'status' => 'completed', 'results' => array( 'results' => array( array( 'slug' => 'acme-forms', 'name' => 'Acme Forms', 'active_installs' => 5000 ) ) ) ) );
			}
		);
		$scanner = \Mockery::mock( Scanner::class );
		$scanner->shouldReceive( 'detect_removed_outside' );
		$scanner->shouldReceive( 'needs_scan' )->andReturn( false );
		$scanner->shouldReceive( 'is_running' )->andReturn( false );
		$scanner->shouldReceive( 'item' )->andReturn( array( 'confidence' => 'unknown' ) );
		$scanner->shouldReceive( 'reattribute' )->andReturn( array() );
		$command = new Command( $scanner, \Mockery::mock( Trash::class ), \Mockery::mock( Ledger::class ) );

		$command->lookup( array( 'option', 'acme_widget_colour' ), array( 'format' => 'json' ) );

		$table = \WP_CLI::$lines[0][1];
		$this->assertSame( 'json', $table[0] );
		$this->assertSame( 'acme-forms', $table[1][0]['slug'] );
		$this->assertSame( 'likely', $table[1][0]['confidence'] );
	}

	public function test_the_abilities_run_the_lookup_and_the_download(): void {
		$this->enable();
		$this->options[ Settings::OPTION ]['online_lookup'] = true;
		Filters\expectApplied( 'axle_leftovers_lookup_poll_interval' )->andReturn( 0 );
		$this->mock_http(
			function ( $method, $url ) {
				if ( '.sha256' === substr( $url, -7 ) || 'signatures.json.gz' === substr( $url, -18 ) ) {
					return array( 'body' => 'nope', 'response' => array( 'code' => 200 ) );
				}
				return 'POST' === $method
					? $this->json_response( array( 'id' => 'abc12345-0000', 'status' => 'queued' ) )
					: $this->json_response( array( 'status' => 'completed', 'results' => array( 'results' => array( array( 'slug' => 'a-plugin', 'name' => 'A Plugin', 'active_installs' => 1 ), array( 'slug' => 'b-plugin', 'name' => 'B Plugin', 'active_installs' => 2 ) ) ) ) );
			}
		);
		$scanner = \Mockery::mock( Scanner::class );
		$scanner->shouldReceive( 'item' )->andReturn( array( 'confidence' => 'unknown' ) );
		$scanner->shouldReceive( 'reattribute' )->andReturn( array() );
		$abilities = new Abilities( $scanner, \Mockery::mock( Trash::class ) );

		$looked = $abilities->lookup_item( array( 'type' => 'option', 'name' => 'ability_option_name' ) );
		$this->assertSame( 'guess', $looked['confidence'] );
		$this->assertSame( 2, $looked['total'] );
		$this->assertSame( 'b-plugin', $looked['candidates'][0]['slug'] );

		$this->assertInstanceOf( \WP_Error::class, $abilities->download_dataset(), 'The mocked checksum file is invalid.' );
		$this->assertInstanceOf( \WP_Error::class, $abilities->lookup_item( array( 'type' => 'option' ) ) );
	}

	public function test_reattribute_updates_the_cached_item_and_drops_one_that_is_in_use(): void {
		$attribution = \Mockery::mock( \AryanX\Axle\Leftovers\Attribution::class );
		$attribution->shouldReceive( 'attribute' )->with( 'option', 'looked_up_option' )->andReturn( array( 'owner' => 'acme-forms', 'owner_name' => 'Acme Forms', 'confidence' => 'likely', 'evidence' => array( array( 'code' => 'lookup', 'text' => 'x' ) ), 'in_use' => false, 'in_use_by' => '' ) );
		$attribution->shouldReceive( 'attribute' )->with( 'option', 'installed_option' )->andReturn( array( 'owner' => 'akismet', 'owner_name' => 'Akismet', 'confidence' => 'likely', 'evidence' => array(), 'in_use' => true, 'in_use_by' => 'akismet' ) );
		$scanner = new Scanner( \Mockery::mock( Ledger::class ), \Mockery::mock( \AryanX\Axle\Leftovers\Inventory::class ), $attribution, \Mockery::mock( \AryanX\Axle\Leftovers\References::class ), \Mockery::mock( \AryanX\Axle\Leftovers\Recorder::class ) );
		$base    = array( 'scope' => 'site', 'rows' => 1, 'bytes' => 10, 'autoload' => false, 'refs' => array(), 'owner' => '', 'owner_name' => '', 'confidence' => 'unknown', 'evidence' => array() );
		$this->options[ Scanner::OPTION ] = array(
			'items' => array(
				array( 'id' => 'option:looked_up_option', 'type' => 'option', 'name' => 'looked_up_option' ) + $base,
				array( 'id' => 'option:installed_option', 'type' => 'option', 'name' => 'installed_option' ) + $base,
			),
		);

		$updated = $scanner->reattribute( 'option', 'looked_up_option' );
		$this->assertSame( 'acme-forms', $updated['owner'] );
		$this->assertSame( 'likely', $scanner->item( 'option', 'looked_up_option' )['confidence'] );
		$this->assertSame( 10, $updated['bytes'], 'The size and rows are kept.' );

		$this->assertNull( $scanner->reattribute( 'option', 'installed_option' ) );
		$this->assertNull( $scanner->item( 'option', 'installed_option' ) );
		$this->assertNull( $scanner->reattribute( 'option', 'not_listed_at_all' ) );
	}
}
