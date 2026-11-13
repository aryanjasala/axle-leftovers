<?php
/**
 * Moving the posts of a post type and the terms of a taxonomy to the trash and back.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use AryanX\Axle\Leftovers\Attribution;
use AryanX\Axle\Leftovers\Inventory;
use AryanX\Axle\Leftovers\Observer;
use AryanX\Axle\Leftovers\Privacy;
use AryanX\Axle\Leftovers\References;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Storage;
use AryanX\Axle\Leftovers\Trash;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

require_once __DIR__ . '/TrashThemeContentTest.php';

/**
 * A database double that also keeps comments, terms, term meta and taxonomy rows.
 */
class ContentWpdb extends PostsWpdb {
	public $comments = 'wp_comments';

	/** @var array<int, array> Comments by ID. */
	public $comment_rows = array();

	/** @var array<int, array> Comment meta by meta_id. */
	public $cmeta_rows = array();

	/** @var array<int, array> term_taxonomy rows by ID. */
	public $tt_rows = array();

	/** @var array<int, array> Term rows by ID. */
	public $term_rows = array();

	/** @var array<int, array> Term meta by meta_id. */
	public $tmeta_rows = array();

	private function split( $prepared ): array {
		return array( is_array( $prepared ) ? $prepared['q'] : $prepared, is_array( $prepared ) ? $prepared['a'] : array() );
	}

	public function get_col( $prepared ) {
		list( $query, $args ) = $this->split( $prepared );
		if ( 0 === strpos( $query, 'SELECT ID FROM %i WHERE post_type = %s' ) ) {
			return array_keys( array_filter( $this->post_rows, static function ( $row ) use ( $args ) { return $row['post_type'] === $args[1]; } ) );
		}
		if ( 0 === strpos( $query, 'SELECT term_taxonomy_id FROM %i WHERE taxonomy = %s' ) ) {
			return array_keys( array_filter( $this->tt_rows, static function ( $row ) use ( $args ) { return $row['taxonomy'] === $args[1]; } ) );
		}
		if ( 0 === strpos( $query, 'SELECT DISTINCT term_id FROM %i WHERE term_id IN' ) ) {
			$ids = array_map( 'intval', array_slice( $args, 1 ) );
			return array_values( array_unique( array_filter( array_column( $this->tt_rows, 'term_id' ), static function ( $id ) use ( $ids ) { return in_array( (int) $id, $ids, true ); } ) ) );
		}
		if ( 0 === strpos( $query, 'SELECT ID FROM %i WHERE ID IN' ) ) {
			$ids = array_map( 'intval', array_slice( $args, 1 ) );
			return array_values( array_filter( $ids, function ( $id ) { return isset( $this->post_rows[ $id ] ); } ) );
		}
		return parent::get_col( $prepared );
	}

	public function get_results( $prepared, $output = null ) {
		list( $query, $args ) = $this->split( $prepared );
		$ids                  = array_map( 'intval', array_slice( $args, 1 ) );
		$pick                 = static function ( array $rows, string $column ) use ( $ids ): array {
			return array_values( array_filter( $rows, static function ( $row ) use ( $column, $ids ) { return in_array( (int) $row[ $column ], $ids, true ); } ) );
		};
		if ( 0 === strpos( $query, 'SELECT * FROM %i WHERE comment_post_ID IN' ) ) {
			return $pick( $this->comment_rows, 'comment_post_ID' );
		}
		if ( 0 === strpos( $query, 'SELECT meta_id, comment_id' ) ) {
			return $pick( $this->cmeta_rows, 'comment_id' );
		}
		if ( 0 === strpos( $query, 'SELECT * FROM %i WHERE term_taxonomy_id IN' ) ) {
			return $pick( $this->tt_rows, 'term_taxonomy_id' );
		}
		if ( 0 === strpos( $query, 'SELECT * FROM %i WHERE term_id IN' ) ) {
			return $pick( $this->term_rows, 'term_id' );
		}
		if ( 0 === strpos( $query, 'SELECT meta_id, term_id' ) ) {
			return $pick( $this->tmeta_rows, 'term_id' );
		}
		if ( 0 === strpos( $query, 'SELECT object_id, term_taxonomy_id, term_order FROM' ) ) {
			return $pick( $this->rel_rows, 'term_taxonomy_id' );
		}
		if ( 0 === strpos( $query, 'SELECT term_id, slug FROM %i' ) ) {
			return array_map(
				static function ( $row ) { return array( 'term_id' => $row['term_id'], 'slug' => $row['slug'] ); },
				$pick( $this->term_rows, 'term_id' )
			);
		}
		return parent::get_results( $prepared, $output );
	}

	public function get_var( $prepared ) {
		list( $query, $args ) = $this->split( $prepared );
		if ( 0 === strpos( $query, 'SELECT term_taxonomy_id FROM %i WHERE term_taxonomy_id IN' ) ) {
			foreach ( array_map( 'intval', array_slice( $args, 1 ) ) as $id ) {
				if ( isset( $this->tt_rows[ $id ] ) ) {
					return $id;
				}
			}
			return null;
		}
		if ( 0 === strpos( $query, 'SELECT term_taxonomy_id FROM %i WHERE term_taxonomy_id = %d' ) ) {
			$row = $this->tt_rows[ (int) $args[1] ] ?? null;
			return $row && $row['taxonomy'] === $args[2] ? $row['term_taxonomy_id'] : null;
		}
		return parent::get_var( $prepared );
	}

	public function insert( $table, $data ) {
		$maps = array(
			'wp_comments'     => array( 'comment_rows', 'comment_ID' ),
			'wp_commentmeta'  => array( 'cmeta_rows', 'meta_id' ),
			'wp_term_taxonomy' => array( 'tt_rows', 'term_taxonomy_id' ),
			'wp_terms'        => array( 'term_rows', 'term_id' ),
			'wp_termmeta'     => array( 'tmeta_rows', 'meta_id' ),
		);
		if ( isset( $maps[ $table ] ) ) {
			$this->{$maps[ $table ][0]}[ (int) $data[ $maps[ $table ][1] ] ] = $data;
			return 1;
		}
		return parent::insert( $table, $data );
	}

	public function query( $prepared ) {
		list( $query, $args ) = $this->split( $prepared );
		$ids                  = array_map( 'intval', array_slice( $args, 1 ) );
		$drop                 = static function ( array $rows, string $column, array $ids ): array {
			return array_filter( $rows, static function ( $row ) use ( $column, $ids ) { return ! in_array( (int) $row[ $column ], $ids, true ); } );
		};
		if ( 0 === strpos( $query, 'DELETE cm FROM' ) ) {
			$posts = array_map( 'intval', array_slice( $args, 2 ) );
			$gone  = array_column( array_filter( $this->comment_rows, static function ( $row ) use ( $posts ) { return in_array( (int) $row['comment_post_ID'], $posts, true ); } ), 'comment_ID' );
			$this->cmeta_rows = $drop( $this->cmeta_rows, 'comment_id', array_map( 'intval', $gone ) );
			return 1;
		}
		if ( 0 === strpos( $query, 'DELETE FROM %i WHERE comment_post_ID IN' ) ) {
			$this->comment_rows = $drop( $this->comment_rows, 'comment_post_ID', $ids );
			return 1;
		}
		if ( 0 === strpos( $query, 'DELETE FROM %i WHERE term_taxonomy_id IN' ) ) {
			if ( 'wp_term_relationships' === $args[0] ) {
				$this->rel_rows = array_values( $drop( $this->rel_rows, 'term_taxonomy_id', $ids ) );
			} else {
				$this->tt_rows = $drop( $this->tt_rows, 'term_taxonomy_id', $ids );
			}
			return 1;
		}
		if ( 0 === strpos( $query, 'DELETE FROM %i WHERE term_id IN' ) ) {
			if ( 'wp_termmeta' === $args[0] ) {
				$this->tmeta_rows = $drop( $this->tmeta_rows, 'term_id', $ids );
			} else {
				$this->term_rows = $drop( $this->term_rows, 'term_id', $ids );
			}
			return 1;
		}
		return parent::query( $prepared );
	}
}

/**
 * Content items: typed confirmation, observation, exact move and restore, and privacy.
 */
final class TrashContentTest extends LeftoversTestCase {

	/** @var ContentWpdb */
	private $content_db;

	/** @var string */
	private $dir;

	/** @var \Mockery\MockInterface */
	private $scanner;

	/** @var array The item the scanner returns. */
	private $item = array();

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb             = new ContentWpdb();
		$this->db         = $wpdb;
		$this->content_db = $wpdb;
		$this->dir        = sys_get_temp_dir() . '/axle-leftovers-content-' . uniqid();
		mkdir( $this->dir, 0777, true );

		$wpdb->post_rows    = array(
			20 => array( 'ID' => 20, 'post_type' => 'event', 'post_author' => 4, 'post_name' => 'gala', 'post_title' => 'Gala', 'post_content' => 'Party', 'post_parent' => 0, 'post_status' => 'publish' ),
			21 => array( 'ID' => 21, 'post_type' => 'event', 'post_author' => 4, 'post_name' => 'talk', 'post_title' => 'Talk', 'post_content' => 'Slides', 'post_parent' => 0, 'post_status' => 'draft' ),
			22 => array( 'ID' => 22, 'post_type' => 'revision', 'post_author' => 4, 'post_name' => '20-revision-v1', 'post_title' => 'Gala', 'post_content' => 'Old', 'post_parent' => 20, 'post_status' => 'inherit' ),
			90 => array( 'ID' => 90, 'post_type' => 'page', 'post_author' => 1, 'post_name' => 'about', 'post_title' => 'About', 'post_content' => 'Kept', 'post_parent' => 0, 'post_status' => 'publish' ),
		);
		$wpdb->meta_rows    = array(
			600 => array( 'meta_id' => 600, 'post_id' => 20, 'meta_key' => '_event_date', 'meta_value' => '2026-01-01' ),
			601 => array( 'meta_id' => 601, 'post_id' => 90, 'meta_key' => '_edit_last', 'meta_value' => '1' ),
		);
		$wpdb->comment_rows = array(
			70 => array( 'comment_ID' => 70, 'comment_post_ID' => 20, 'comment_author' => 'Jo', 'comment_author_email' => 'Jo@Example.com', 'user_id' => 0, 'comment_content' => 'Nice' ),
			71 => array( 'comment_ID' => 71, 'comment_post_ID' => 90, 'comment_author' => 'Al', 'comment_author_email' => 'al@example.com', 'user_id' => 0, 'comment_content' => 'Kept' ),
		);
		$wpdb->cmeta_rows   = array(
			800 => array( 'meta_id' => 800, 'comment_id' => 70, 'meta_key' => 'rating', 'meta_value' => '5' ),
			801 => array( 'meta_id' => 801, 'comment_id' => 71, 'meta_key' => 'rating', 'meta_value' => '1' ),
		);
		$wpdb->tt_rows      = array(
			31 => array( 'term_taxonomy_id' => 31, 'term_id' => 11, 'taxonomy' => 'genre', 'description' => '', 'parent' => 0, 'count' => 2 ),
			32 => array( 'term_taxonomy_id' => 32, 'term_id' => 12, 'taxonomy' => 'genre', 'description' => '', 'parent' => 11, 'count' => 0 ),
			33 => array( 'term_taxonomy_id' => 33, 'term_id' => 12, 'taxonomy' => 'post_tag', 'description' => '', 'parent' => 0, 'count' => 1 ),
		);
		$wpdb->term_rows    = array(
			11 => array( 'term_id' => 11, 'name' => 'Jazz', 'slug' => 'jazz', 'term_group' => 0 ),
			12 => array( 'term_id' => 12, 'name' => 'Live', 'slug' => 'live', 'term_group' => 0 ),
		);
		$wpdb->tmeta_rows   = array(
			900 => array( 'meta_id' => 900, 'term_id' => 11, 'meta_key' => 'color', 'meta_value' => 'blue' ),
			901 => array( 'meta_id' => 901, 'term_id' => 12, 'meta_key' => 'color', 'meta_value' => 'red' ),
		);
		$wpdb->rel_rows     = array(
			array( 'object_id' => 20, 'term_taxonomy_id' => 31, 'term_order' => 0, 'taxonomy' => 'genre', 'slug' => 'jazz', 'name' => 'Jazz' ),
			array( 'object_id' => 90, 'term_taxonomy_id' => 31, 'term_order' => 0, 'taxonomy' => 'genre', 'slug' => 'jazz', 'name' => 'Jazz' ),
			array( 'object_id' => 21, 'term_taxonomy_id' => 33, 'term_order' => 0, 'taxonomy' => 'post_tag', 'slug' => 'live', 'name' => 'Live' ),
		);

		$this->options[ Observer::OPTION ] = array(
			'since'      => time() - 20 * DAY_IN_SECONDS,
			'kinds'      => array(
				'front' => array( 'first' => time() - 20 * DAY_IN_SECONDS, 'last' => time() ),
				'admin' => array( 'first' => time() - 20 * DAY_IN_SECONDS, 'last' => time() ),
			),
			'post_types' => array( 'event' => time() - 12 * DAY_IN_SECONDS ),
			'taxonomies' => array( 'genre' => time() - 12 * DAY_IN_SECONDS ),
		);

		$this->item    = array( 'scope' => 'site', 'owner' => 'events-calendar', 'owner_name' => 'Events Calendar', 'confidence' => 'certain', 'evidence' => array(), 'rows' => 2, 'bytes' => 10, 'autoload' => false, 'refs' => array() );
		$item          = &$this->item;
		$this->scanner = \Mockery::mock( Scanner::class );
		$attribution   = \Mockery::mock( Attribution::class );
		$attribution->shouldReceive( 'flush' );
		$attribution->shouldReceive( 'attribute' )->andReturn( array( 'owner' => 'events-calendar', 'owner_name' => 'Events Calendar', 'confidence' => 'certain', 'evidence' => array(), 'in_use' => false, 'in_use_by' => '' ) );
		$references = \Mockery::mock( References::class );
		$references->shouldReceive( 'check' )->andReturn( array() );
		$this->scanner->shouldReceive( 'attribution' )->andReturn( $attribution );
		$this->scanner->shouldReceive( 'references' )->andReturn( $references );
		$this->scanner->shouldReceive( 'item' )->andReturnUsing(
			static function ( $type, $name ) use ( &$item ) {
				return array( 'id' => $type . ':' . $name, 'type' => $type, 'name' => $name ) + $item;
			}
		);
		$this->scanner->shouldReceive( 'forget' );

		Functions\when( 'wp_get_themes' )->justReturn( array() );
		Functions\when( 'post_type_exists' )->justReturn( false );
		Functions\when( 'taxonomy_exists' )->alias( static function ( $name ) { return 'post_tag' === $name; } );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_update_term_count_now' )->justReturn( true );
		Functions\when( 'wp_cache_delete_multiple' )->justReturn( true );
		Functions\when( 'get_term_by' )->justReturn( false );
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

	private function trash(): Trash {
		$dir     = $this->dir;
		$storage = \Mockery::mock( Storage::class );
		$storage->shouldReceive( 'dir' )->andReturnUsing( static function ( $sub = '' ) use ( $dir ) { return '' === $sub ? $dir : $dir . '/' . $sub; } );
		$storage->shouldReceive( 'open_append' )->andReturnUsing(
			static function ( $path ) {
				if ( ! is_dir( dirname( $path ) ) ) {
					mkdir( dirname( $path ), 0777, true );
				}
				return fopen( $path, 'ab' );
			}
		);
		$storage->shouldReceive( 'write' )->andReturnUsing( static function ( $handle, $text ) { return false !== fwrite( $handle, $text ); } );
		$storage->shouldReceive( 'close' )->andReturnUsing( static function ( $handle ) { fclose( $handle ); } );
		$storage->shouldReceive( 'delete' )->andReturn( true );

		return new Trash( $this->scanner, \Mockery::mock( Inventory::class ), $storage );
	}

	private function event(): array {
		return array( array( 'type' => 'posttype', 'name' => 'event' ) );
	}

	public function test_content_always_needs_its_name_typed_even_when_the_owner_is_certain(): void {
		$checked = $this->trash()->check( $this->event() );
		$this->assertSame( 'confirm', $checked['errors'][0]['code'] );
		$this->assertStringContainsString( 'moves posts or terms', $checked['errors'][0]['message'] );

		$this->assertSame( array(), $this->trash()->check( $this->event(), array( 'confirm_names' => array( 'event' ) ) )['errors'] );
	}

	public function test_a_type_that_is_registered_again_or_not_watched_long_enough_is_refused(): void {
		Functions\when( 'post_type_exists' )->justReturn( true );
		$checked = $this->trash()->check( $this->event(), array( 'confirm_names' => array( 'event' ) ) );
		$this->assertSame( 'registered', $checked['errors'][0]['code'] );

		Functions\when( 'post_type_exists' )->justReturn( false );
		$this->options[ Observer::OPTION ]['post_types']['event'] = time() - DAY_IN_SECONDS;
		$checked = $this->trash()->check( $this->event(), array( 'confirm_names' => array( 'event' ) ) );
		$this->assertSame( 'watching', $checked['errors'][0]['code'] );
		$this->assertStringContainsString( '7 days', $checked['errors'][0]['message'] );
	}

	public function test_a_big_item_is_refused_in_a_web_request_with_the_wp_cli_hint(): void {
		$this->item['rows'] = 250000;

		$checked = $this->trash()->check( $this->event(), array( 'confirm_names' => array( 'event' ) ) );

		$this->assertSame( 'too_big', $checked['errors'][0]['code'] );
		$this->assertStringContainsString( 'WP-CLI', $checked['errors'][0]['message'] );
	}

	public function test_a_user_without_the_post_capability_cannot_trash_or_restore_posts(): void {
		Functions\when( 'current_user_can' )->alias( static function ( $cap ) { return 'delete_others_posts' !== $cap; } );

		$checked = $this->trash()->check( $this->event(), array( 'confirm_names' => array( 'event' ) ) );
		$this->assertSame( 'item_cap', $checked['errors'][0]['code'] );

		Functions\when( 'current_user_can' )->alias( static function ( $cap ) { return 'manage_categories' !== $cap; } );
		$checked = $this->trash()->check( array( array( 'type' => 'taxonomy', 'name' => 'genre' ) ), array( 'confirm_names' => array( 'genre' ) ) );
		$this->assertSame( 'item_cap', $checked['errors'][0]['code'] );
	}

	public function test_a_post_type_moves_with_revisions_meta_comments_and_term_links_and_comes_back_exact(): void {
		$trash  = $this->trash();
		$before = array( $this->content_db->post_rows, $this->content_db->meta_rows, $this->content_db->comment_rows, $this->content_db->cmeta_rows, $this->content_db->rel_rows );

		$result = $trash->trash( $this->event(), array( 'confirm_names' => array( 'event' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 90 ), array_keys( $this->content_db->post_rows ) );
		$this->assertSame( array( 601 ), array_keys( $this->content_db->meta_rows ) );
		$this->assertSame( array( 71 ), array_keys( $this->content_db->comment_rows ), 'The comment of the page stays.' );
		$this->assertSame( array( 801 ), array_keys( $this->content_db->cmeta_rows ) );
		$this->assertSame( array( 90 ), array_column( $this->content_db->rel_rows, 'object_id' ) );

		$row     = $this->content_db->tables['wp_axle_leftovers_trash'][1];
		$payload = json_decode( $row['payload'], true );
		$this->assertSame( 2, $payload['rows'] );
		$this->assertSame( 1, $payload['revisions'] );
		$this->assertSame( 1, $payload['meta'] );
		$this->assertSame( 1, $payload['comments'] );
		$this->assertSame( 2, $payload['relations'] );
		$this->assertSame( array( 4 ), $payload['authors'] );
		$this->assertSame( array( Trash::email_hash( 'jo@example.com' ) ), $payload['emails'], 'Emails are kept as hashes, lower case.' );

		$restored = $this->trash()->restore( $result['batch'] );

		$this->assertSame( array(), $restored['failed'] );
		$this->assertEqualsCanonicalizing( array( 20, 21, 22, 90 ), array_keys( $this->content_db->post_rows ) );
		$this->assertEqualsCanonicalizing( array( 600, 601 ), array_keys( $this->content_db->meta_rows ) );
		$this->assertEqualsCanonicalizing( array( 70, 71 ), array_keys( $this->content_db->comment_rows ) );
		$this->assertEqualsCanonicalizing( array( 800, 801 ), array_keys( $this->content_db->cmeta_rows ) );
		$this->assertSame( 'Jo@Example.com', $this->content_db->comment_rows[70]['comment_author_email'] );
		$this->assertSame( 'draft', $this->content_db->post_rows[21]['post_status'] );
		ksort( $this->content_db->post_rows );
		$this->assertSame( $before[0], $this->content_db->post_rows );
	}

	public function test_term_counts_are_recounted_for_registered_taxonomies_only(): void {
		$recounted = array();
		Functions\when( 'wp_update_term_count_now' )->alias(
			static function ( $ids, $taxonomy ) use ( &$recounted ) {
				$recounted[ $taxonomy ] = $ids;
				return true;
			}
		);

		$result = $this->trash()->trash( $this->event(), array( 'confirm_names' => array( 'event' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'post_tag' => array( 33 ) ), $recounted );
		$this->assertSame( 2, (int) $this->content_db->tt_rows[31]['count'], 'The count of a taxonomy nothing registers is left alone.' );
	}

	public function test_a_failed_restore_of_posts_removes_the_comments_it_added(): void {
		$result = $this->trash()->trash( $this->event(), array( 'confirm_names' => array( 'event' ) ) );
		$this->content_db->fail_insert = 21;

		$restored = $this->trash()->restore( $result['batch'] );

		$this->assertSame( array(), $restored['restored'] );
		$this->assertSame( array( 90 ), array_keys( $this->content_db->post_rows ) );
		$this->assertSame( array( 71 ), array_keys( $this->content_db->comment_rows ) );
	}

	public function test_a_taxonomy_moves_with_terms_term_meta_and_links_and_comes_back_with_the_same_ids(): void {
		$result = $this->trash()->trash( array( array( 'type' => 'taxonomy', 'name' => 'genre' ) ), array( 'confirm_names' => array( 'genre' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 33 ), array_keys( $this->content_db->tt_rows ), 'The post_tag term stays.' );
		$this->assertSame( array( 12 ), array_keys( $this->content_db->term_rows ), 'Term 12 is shared with post_tag, so its row stays; term 11 goes.' );
		$this->assertSame( array( 901 ), array_keys( $this->content_db->tmeta_rows ) );
		$this->assertSame( array( 33 ), array_unique( array_column( $this->content_db->rel_rows, 'term_taxonomy_id' ) ) );
		$payload = json_decode( $this->content_db->tables['wp_axle_leftovers_trash'][1]['payload'], true );
		$this->assertSame( 2, $payload['rows'] );
		$this->assertSame( 2, $payload['relations'] );

		$restored = $this->trash()->restore( $result['batch'] );

		$this->assertSame( array(), $restored['failed'] );
		$this->assertEqualsCanonicalizing( array( 31, 32, 33 ), array_keys( $this->content_db->tt_rows ) );
		$this->assertEqualsCanonicalizing( array( 11, 12 ), array_keys( $this->content_db->term_rows ) );
		$this->assertEqualsCanonicalizing( array( 900, 901 ), array_keys( $this->content_db->tmeta_rows ) );
		$this->assertSame( 32, (int) $this->content_db->tt_rows[32]['term_taxonomy_id'] );
		$this->assertSame( 11, (int) $this->content_db->tt_rows[32]['parent'] );
		$linked = array();
		foreach ( $this->content_db->rel_rows as $rel ) {
			$linked[] = $rel['object_id'] . ':' . $rel['term_taxonomy_id'];
		}
		sort( $linked );
		$this->assertSame( array( '20:31', '21:33', '90:31' ), $linked );
	}

	public function test_a_taxonomy_restore_refuses_when_an_id_is_taken(): void {
		$result = $this->trash()->trash( array( array( 'type' => 'taxonomy', 'name' => 'genre' ) ), array( 'confirm_names' => array( 'genre' ) ) );
		$this->content_db->tt_rows[32] = array( 'term_taxonomy_id' => 32, 'term_id' => 50, 'taxonomy' => 'other', 'description' => '', 'parent' => 0, 'count' => 0 );

		$restored = $this->trash()->restore( $result['batch'] );

		$this->assertSame( array(), $restored['restored'] );
		$this->assertStringContainsString( 'Something named genre exists now', $restored['failed'][0]['message'] );
		$this->assertArrayNotHasKey( 31, $this->content_db->tt_rows );
	}

	public function test_a_link_to_a_post_that_is_gone_is_not_restored(): void {
		$result = $this->trash()->trash( array( array( 'type' => 'taxonomy', 'name' => 'genre' ) ), array( 'confirm_names' => array( 'genre' ) ) );
		unset( $this->content_db->post_rows[90] );

		$this->trash()->restore( $result['batch'] );

		$linked = array_map( 'intval', array_column( $this->content_db->rel_rows, 'object_id' ) );
		$this->assertNotContains( 90, $linked );
		$this->assertContains( 20, $linked );
	}

	public function test_the_privacy_exporter_and_eraser_find_batches_with_a_persons_posts_and_comments(): void {
		$result = $this->trash()->trash( $this->event(), array( 'confirm_names' => array( 'event' ) ) );
		Functions\when( 'get_user_by' )->justReturn( false );
		$privacy = new Privacy( $this->trash() );

		$exported = $privacy->export( 'jo@example.com' );
		$this->assertCount( 1, $exported['data'] );
		$this->assertSame( 'axle-leftovers-content', $exported['data'][0]['group_id'] );
		$this->assertSame( array(), $privacy->export( 'nobody@example.com' )['data'] );

		$erased = $privacy->erase( 'jo@example.com' );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertSame( 'purged', $this->content_db->tables['wp_axle_leftovers_trash'][1]['status'] );
		$this->assertNotEmpty( $result['batch'] );
	}

	public function test_summary_counts_and_samples_come_from_the_scan_and_one_small_query(): void {
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		$summary = ( new \AryanX\Axle\Leftovers\Content() )->summary(
			array(
				'type'   => 'posttype',
				'name'   => 'event',
				'detail' => array( 'statuses' => array( 'publish' => 3, 'draft' => 1 ), 'first' => '2020-01-01 00:00:00', 'last' => '2026-01-01 00:00:00', 'meta' => 9, 'comments' => 2, 'relations' => 4 ),
			)
		);

		$this->assertSame( array( 'publish' => 3, 'draft' => 1 ), $summary['statuses'] );
		$this->assertSame( 9, $summary['meta'] );
		$this->assertSame( array(), $summary['sample'], 'The fake database returns no rows for the sample query.' );
	}
}
