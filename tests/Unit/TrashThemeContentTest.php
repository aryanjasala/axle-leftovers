<?php
/**
 * Moving theme content to the trash and back, and who may restore what.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Attribution;
use AryanX\Axle\Leftovers\Inventory;
use AryanX\Axle\Leftovers\References;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Storage;
use AryanX\Axle\Leftovers\Tests\FakeWpdb;
use AryanX\Axle\Leftovers\Trash;
use Brain\Monkey\Functions;

/**
 * A database double that keeps posts, meta and term relationships.
 */
class PostsWpdb extends FakeWpdb {
	public $posts              = 'wp_posts';
	public $term_relationships = 'wp_term_relationships';
	public $term_taxonomy      = 'wp_term_taxonomy';
	public $terms              = 'wp_terms';

	/** @var array<int, array> Posts by ID. */
	public $post_rows = array();

	/** @var array<int, array> Meta rows by meta_id. */
	public $meta_rows = array();

	/** @var array<int, array> Relationship rows. */
	public $rel_rows = array();

	/** @var array<string, int[]> Post IDs by theme name. */
	public $by_theme = array();

	/** @var int Post ID whose insert fails. */
	public $fail_insert = 0;

	public function get_col( $prepared ) {
		$query = is_array( $prepared ) ? $prepared['q'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['a'] : array();
		if ( false !== strpos( $query, "post_type = 'revision' AND post_parent IN" ) ) {
			$parents = array_map( 'intval', array_slice( $args, 1 ) );
			return array_keys( array_filter( $this->post_rows, static function ( $row ) use ( $parents ) { return 'revision' === $row['post_type'] && in_array( (int) $row['post_parent'], $parents, true ); } ) );
		}
		if ( false !== strpos( $query, 'SELECT DISTINCT p.ID' ) ) {
			$theme = (string) $args[4];
			return array_values( array_filter( $this->by_theme[ $theme ] ?? array(), function ( $id ) { return isset( $this->post_rows[ $id ] ); } ) );
		}
		return parent::get_col( $prepared );
	}

	public function get_results( $prepared, $output = null ) {
		$query = is_array( $prepared ) ? $prepared['q'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['a'] : array();
		if ( 0 === strpos( $query, 'SELECT * FROM %i WHERE ID IN' ) ) {
			$ids = array_map( 'intval', array_slice( $args, 1 ) );
			return array_values( array_filter( $this->post_rows, static function ( $row ) use ( $ids ) { return in_array( (int) $row['ID'], $ids, true ); } ) );
		}
		if ( 0 === strpos( $query, 'SELECT meta_id, post_id' ) ) {
			$ids = array_map( 'intval', array_slice( $args, 1 ) );
			return array_values( array_filter( $this->meta_rows, static function ( $row ) use ( $ids ) { return in_array( (int) $row['post_id'], $ids, true ); } ) );
		}
		if ( 0 === strpos( $query, 'SELECT tr.object_id' ) ) {
			$ids = array_map( 'intval', array_slice( $args, 3 ) );
			return array_values( array_filter( $this->rel_rows, static function ( $row ) use ( $ids ) { return in_array( (int) $row['object_id'], $ids, true ); } ) );
		}
		return parent::get_results( $prepared, $output );
	}

	public function get_var( $prepared ) {
		$query = is_array( $prepared ) ? $prepared['q'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['a'] : array();
		if ( false !== strpos( $query, 'SELECT ID FROM %i WHERE ID IN' ) ) {
			foreach ( array_map( 'intval', array_slice( $args, 1 ) ) as $id ) {
				if ( isset( $this->post_rows[ $id ] ) ) {
					return $id;
				}
			}
			return null;
		}
		return parent::get_var( $prepared );
	}

	public function insert( $table, $data ) {
		if ( 'wp_posts' === $table ) {
			if ( $this->fail_insert && (int) $data['ID'] === $this->fail_insert ) {
				return false;
			}
			$this->post_rows[ (int) $data['ID'] ] = $data;
			return 1;
		}
		if ( 'wp_postmeta' === $table ) {
			$this->meta_rows[ (int) $data['meta_id'] ] = $data;
			return 1;
		}
		if ( 'wp_term_relationships' === $table ) {
			$this->rel_rows[] = $data + array( 'taxonomy' => '', 'slug' => '', 'name' => '' );
			return 1;
		}
		return parent::insert( $table, $data );
	}

	public function query( $prepared ) {
		$query = is_array( $prepared ) ? $prepared['q'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['a'] : array();
		$ids   = array_map( 'intval', array_slice( $args, 1 ) );
		if ( 0 === strpos( $query, 'DELETE FROM %i WHERE post_id IN' ) ) {
			$this->meta_rows = array_filter( $this->meta_rows, static function ( $row ) use ( $ids ) { return ! in_array( (int) $row['post_id'], $ids, true ); } );
			return 1;
		}
		if ( 0 === strpos( $query, 'DELETE FROM %i WHERE object_id IN' ) ) {
			$this->rel_rows = array_values( array_filter( $this->rel_rows, static function ( $row ) use ( $ids ) { return ! in_array( (int) $row['object_id'], $ids, true ); } ) );
			return 1;
		}
		if ( 0 === strpos( $query, 'DELETE FROM %i WHERE ID IN' ) ) {
			foreach ( $ids as $id ) {
				unset( $this->post_rows[ $id ] );
			}
			return 1;
		}
		return parent::query( $prepared );
	}
}

/**
 * Export, delete and restore of posts, meta and relationships, and the permission rules.
 */
final class TrashThemeContentTest extends LeftoversTestCase {

	/** @var PostsWpdb */
	private $posts_db;

	/** @var string */
	private $dir;

	/** @var \Mockery\MockInterface */
	private $scanner;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb           = new PostsWpdb();
		$this->db       = $wpdb;
		$this->posts_db = $wpdb;
		$this->dir      = sys_get_temp_dir() . '/axle-leftovers-posts-' . uniqid();
		mkdir( $this->dir, 0777, true );

		$wpdb->post_rows = array(
			10 => array( 'ID' => 10, 'post_type' => 'wp_template', 'post_name' => 'single', 'post_title' => 'Single', 'post_content' => '<!-- wp:post-content /-->', 'post_parent' => 0, 'post_status' => 'publish' ),
			11 => array( 'ID' => 11, 'post_type' => 'wp_template_part', 'post_name' => 'header', 'post_title' => 'Header', 'post_content' => 'header', 'post_parent' => 0, 'post_status' => 'publish' ),
			12 => array( 'ID' => 12, 'post_type' => 'revision', 'post_name' => '10-revision-v1', 'post_title' => 'Single', 'post_content' => 'old', 'post_parent' => 10, 'post_status' => 'inherit' ),
			99 => array( 'ID' => 99, 'post_type' => 'wp_template', 'post_name' => 'page', 'post_title' => 'Page', 'post_content' => 'kept', 'post_parent' => 0, 'post_status' => 'publish' ),
		);
		$wpdb->by_theme  = array(
			'old-theme' => array( 10, 11 ),
			'other'     => array( 99 ),
		);
		$wpdb->meta_rows = array(
			500 => array( 'meta_id' => 500, 'post_id' => 10, 'meta_key' => 'origin', 'meta_value' => 'theme' ),
			501 => array( 'meta_id' => 501, 'post_id' => 99, 'meta_key' => 'origin', 'meta_value' => 'plugin' ),
		);
		$wpdb->rel_rows  = array(
			array( 'object_id' => 10, 'term_taxonomy_id' => 5, 'term_order' => 0, 'taxonomy' => 'wp_theme', 'slug' => 'old-theme', 'name' => 'old-theme' ),
			array( 'object_id' => 11, 'term_taxonomy_id' => 5, 'term_order' => 0, 'taxonomy' => 'wp_theme', 'slug' => 'old-theme', 'name' => 'old-theme' ),
			array( 'object_id' => 11, 'term_taxonomy_id' => 7, 'term_order' => 0, 'taxonomy' => 'wp_template_part_area', 'slug' => 'header', 'name' => 'header' ),
			array( 'object_id' => 99, 'term_taxonomy_id' => 6, 'term_order' => 0, 'taxonomy' => 'wp_theme', 'slug' => 'other', 'name' => 'other' ),
		);

		$dir = $this->dir;
		$this->scanner = \Mockery::mock( Scanner::class );
		$attribution   = \Mockery::mock( Attribution::class );
		$attribution->shouldReceive( 'flush' );
		$attribution->shouldReceive( 'attribute' )->andReturn( array( 'owner' => 'theme:old-theme', 'owner_name' => 'Old Theme', 'confidence' => 'certain', 'evidence' => array(), 'in_use' => false, 'in_use_by' => '' ) );
		$references = \Mockery::mock( References::class );
		$references->shouldReceive( 'check' )->andReturn( array() );
		$this->scanner->shouldReceive( 'attribution' )->andReturn( $attribution );
		$this->scanner->shouldReceive( 'references' )->andReturn( $references );
		$this->scanner->shouldReceive( 'item' )->andReturnUsing(
			static function ( $type, $name ) {
				return array( 'id' => $type . ':' . $name, 'type' => $type, 'name' => $name, 'scope' => 'site', 'owner' => 'theme:old-theme', 'owner_name' => 'Old Theme', 'confidence' => 'certain', 'evidence' => array(), 'rows' => 2, 'bytes' => 30, 'autoload' => false, 'refs' => array() );
			}
		);
		$this->scanner->shouldReceive( 'forget' );

		Functions\when( 'wp_get_themes' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'taxonomy_exists' )->justReturn( true );
		Functions\when( 'wp_update_term_count_now' )->justReturn( true );
		Functions\when( 'get_term_by' )->alias(
			static function ( $field, $slug, $taxonomy ) {
				$ids = array( 'wp_theme:old-theme' => 5, 'wp_template_part_area:header' => 7 );
				return isset( $ids[ $taxonomy . ':' . $slug ] ) ? new \WP_Term( $ids[ $taxonomy . ':' . $slug ] ) : false;
			}
		);
	}

	protected function tearDown(): void {
		if ( is_dir( $this->dir ) ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $file ) {
				$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
			}
			rmdir( $this->dir );
		}
		parent::tearDown();
	}

	/**
	 * A storage that writes to a temporary folder.
	 */
	private function storage(): Storage {
		$dir     = $this->dir;
		$storage = \Mockery::mock( Storage::class );
		$storage->shouldReceive( 'dir' )->andReturnUsing(
			static function ( $sub = '' ) use ( $dir ) {
				return '' === $sub ? $dir : $dir . '/' . $sub;
			}
		);
		$storage->shouldReceive( 'open_append' )->andReturnUsing(
			static function ( $path ) {
				if ( ! is_dir( dirname( $path ) ) ) {
					mkdir( dirname( $path ), 0777, true );
				}
				return fopen( $path, 'ab' );
			}
		);
		$storage->shouldReceive( 'write' )->andReturnUsing(
			static function ( $handle, $text ) {
				return false !== fwrite( $handle, $text );
			}
		);
		$storage->shouldReceive( 'close' )->andReturnUsing(
			static function ( $handle ) {
				fclose( $handle );
			}
		);

		return $storage;
	}

	private function trash(): Trash {
		return new Trash( $this->scanner, \Mockery::mock( Inventory::class ), $this->storage() );
	}

	public function test_a_theme_s_templates_move_to_the_trash_with_revisions_meta_and_relationships(): void {
		$result = $this->trash()->trash( array( array( 'type' => 'template', 'name' => 'old-theme' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 99 ), array_keys( $this->posts_db->post_rows ), 'Only the other theme post stays, the revision goes with its parent.' );
		$this->assertSame( array( 501 ), array_keys( $this->posts_db->meta_rows ) );
		$this->assertSame( array( 99 ), array_unique( array_column( $this->posts_db->rel_rows, 'object_id' ) ) );

		$row = $this->posts_db->tables['wp_axle_leftovers_trash'][1];
		$this->assertSame( 'trashed', $row['status'] );
		$payload = json_decode( $row['payload'], true );
		$this->assertSame( 2, $payload['rows'] );
		$this->assertSame( 1, $payload['revisions'] );

		$lines = file( $this->dir . '/' . $payload['file'], FILE_IGNORE_NEW_LINES );
		$kinds = array_map(
			static function ( $line ) {
				return json_decode( $line, true )['t'];
			},
			$lines
		);
		$this->assertSame( array( 'post', 'post', 'post', 'meta', 'rel', 'rel', 'rel' ), $kinds );
	}

	public function test_restore_puts_the_rows_back_with_their_ids(): void {
		$result = $this->trash()->trash( array( array( 'type' => 'template', 'name' => 'old-theme' ) ) );
		$before = array( 10, 11, 12 );

		$restored = $this->trash()->restore( $result['batch'] );

		$this->assertCount( 1, $restored['restored'] );
		$this->assertSame( array(), $restored['failed'] );
		$this->assertEqualsCanonicalizing( array_merge( $before, array( 99 ) ), array_keys( $this->posts_db->post_rows ) );
		$this->assertSame( 'revision', $this->posts_db->post_rows[12]['post_type'] );
		$this->assertEqualsCanonicalizing( array( 500, 501 ), array_keys( $this->posts_db->meta_rows ) );
		$tt_ids = array();
		foreach ( $this->posts_db->rel_rows as $rel ) {
			if ( 11 === (int) $rel['object_id'] ) {
				$tt_ids[] = (int) $rel['term_taxonomy_id'];
			}
		}
		sort( $tt_ids );
		$this->assertSame( array( 5, 7 ), $tt_ids );
		$this->assertSame( 'restored', $this->posts_db->tables['wp_axle_leftovers_trash'][1]['status'] );
	}

	public function test_restore_refuses_when_a_post_id_is_taken_and_inserts_nothing(): void {
		$result = $this->trash()->trash( array( array( 'type' => 'template', 'name' => 'old-theme' ) ) );
		$this->posts_db->post_rows[11] = array( 'ID' => 11, 'post_type' => 'post', 'post_name' => 'new', 'post_title' => 'New', 'post_content' => '', 'post_parent' => 0, 'post_status' => 'publish' );

		$restored = $this->trash()->restore( $result['batch'] );

		$this->assertSame( array(), $restored['restored'] );
		$this->assertStringContainsString( 'Something named old-theme exists now', $restored['failed'][0]['message'] );
		$this->assertArrayNotHasKey( 10, $this->posts_db->post_rows );
		$this->assertSame( 'trashed', $this->posts_db->tables['wp_axle_leftovers_trash'][1]['status'] );
	}

	public function test_a_failed_insert_removes_what_the_restore_already_added(): void {
		$result = $this->trash()->trash( array( array( 'type' => 'template', 'name' => 'old-theme' ) ) );
		$this->posts_db->fail_insert = 11;

		$restored = $this->trash()->restore( $result['batch'] );

		$this->assertSame( array(), $restored['restored'] );
		$this->assertSame( array( 99 ), array_keys( $this->posts_db->post_rows ), 'Post 10 was inserted first and is removed again.' );
		$this->assertSame( array( 501 ), array_keys( $this->posts_db->meta_rows ) );
	}

	public function test_a_network_row_is_not_restored_or_purged_by_a_site_admin(): void {
		$this->posts_db->tables['wp_axle_leftovers_trash'][1] = array( 'id' => 1, 'batch_id' => 'b1', 'item_type' => 'sitemeta', 'name' => 'net_option', 'status' => 'trashed', 'payload' => '{}', 'trash_location' => '', 'export_path' => '', 'scope' => 'network', 'purge_after_gmt' => '2020-01-01 00:00:00', 'trashed_gmt' => '2020-01-01 00:00:00' );
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) {
				return 'manage_network' !== $cap;
			}
		);

		$restored = $this->trash()->restore( 'b1' );

		$this->assertInstanceOf( \WP_Error::class, $restored );
		$this->assertSame( 'axle_leftovers_forbidden', $restored->get_error_code() );
		$this->assertSame( array(), $this->trash()->purge( array( 'batch' => 'b1' ) ) );
		$this->assertSame( 'trashed', $this->posts_db->tables['wp_axle_leftovers_trash'][1]['status'] );
		$this->assertSame( array(), $this->trash()->batches( 'network' ) );
		$this->assertSame( array(), $this->trash()->listed( array( 'status' => 'trashed' ) ) );
		$this->assertCount( 1, $this->trash()->rows( array( 'status' => 'trashed' ) ), 'Internal reads and the privacy exporter still see it.' );
	}

	public function test_a_user_without_the_post_capability_cannot_trash_or_restore_theme_content(): void {
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) {
				return 'edit_theme_options' !== $cap;
			}
		);

		$checked = $this->trash()->check( array( array( 'type' => 'template', 'name' => 'old-theme' ) ) );
		$this->assertSame( 'item_cap', $checked['errors'][0]['code'] );

		$this->posts_db->tables['wp_axle_leftovers_trash'][1] = array( 'id' => 1, 'batch_id' => 'b1', 'item_type' => 'template', 'name' => 'old-theme', 'status' => 'trashed', 'payload' => '{}', 'trash_location' => '', 'export_path' => '', 'scope' => 'site' );
		$this->assertInstanceOf( \WP_Error::class, $this->trash()->restore( 'b1' ) );
	}

	public function test_wp_cli_and_cron_have_no_user_and_may_do_everything(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'current_user_can' )->justReturn( false );

		$checked = $this->trash()->check( array( array( 'type' => 'template', 'name' => 'old-theme' ) ), array( 'check_caps' => false ) );

		$this->assertSame( array(), $checked['errors'] );
	}
}
