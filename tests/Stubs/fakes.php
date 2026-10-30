<?php
/**
 * Test doubles for the unit tests: an in-memory $wpdb that keeps the trash,
 * ledger and seen tables, and plugin constants.
 *
 * @package AryanX\Axle\Leftovers
 */

// phpcs:ignoreFile

namespace AryanX\Axle\Leftovers\Tests;

defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'ARRAY_N' ) || define( 'ARRAY_N', 'ARRAY_N' );
defined( 'WP_CONTENT_DIR' ) || define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/axle-leftovers-unit/wp-content' );
defined( 'WP_PLUGIN_DIR' ) || define( 'WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins' );
defined( 'WPMU_PLUGIN_DIR' ) || define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );
defined( 'AryanX\Axle\Leftovers\PLUGIN_FILE' ) || define( 'AryanX\Axle\Leftovers\PLUGIN_FILE', dirname( __DIR__, 2 ) . '/axle-leftovers.php' );
defined( 'AryanX\Axle\Leftovers\VERSION' ) || define( 'AryanX\Axle\Leftovers\VERSION', '1.0.0' );

/**
 * Prepared statements are kept as a query and its values. Tables are rows
 * keyed by name; only what the plugin's own tables need is understood.
 */
class FakeWpdb {
	public $prefix      = 'wp_';
	public $base_prefix = 'wp_';
	public $options     = 'wp_options';
	public $postmeta    = 'wp_postmeta';
	public $usermeta    = 'wp_usermeta';
	public $termmeta    = 'wp_termmeta';
	public $commentmeta = 'wp_commentmeta';
	public $sitemeta    = 'wp_sitemeta';
	public $insert_id   = 0;

	/** @var array<string, array<int, array>> */
	public $tables = array();

	/** @var array<int, string> Queries run with query(). */
	public $queries = array();

	/** @var array<string, mixed> Canned get_var answers, by a substring of the query. */
	public $vars = array();

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return array( 'q' => $query, 'a' => array_values( $args ) );
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function remove_placeholder_escape( $text ) {
		return $text;
	}

	public function get_charset_collate() {
		return '';
	}

	public function insert( $table, $data ) {
		$this->insert_id                          = count( $this->tables[ $table ] ?? array() ) + 1;
		$this->tables[ $table ][ $this->insert_id ] = array( 'id' => $this->insert_id ) + $data;
		return 1;
	}

	public function update( $table, $data, $where ) {
		$count = 0;
		foreach ( $this->tables[ $table ] ?? array() as $id => $row ) {
			if ( array_intersect_assoc( array_map( 'strval', $where ), array_map( 'strval', $row ) ) == array_map( 'strval', $where ) ) {
				$this->tables[ $table ][ $id ] = array_merge( $row, $data );
				++$count;
			}
		}
		return $count;
	}

	public function delete( $table, $where ) {
		foreach ( $this->tables[ $table ] ?? array() as $id => $row ) {
			if ( (string) $row['id'] === (string) $where['id'] ) {
				unset( $this->tables[ $table ][ $id ] );
			}
		}
		return 1;
	}

	public function query( $prepared ) {
		$query           = is_array( $prepared ) ? $prepared['q'] : $prepared;
		$this->queries[] = $query;
		if ( is_array( $prepared ) && 0 === strpos( $query, 'INSERT IGNORE' ) ) {
			list( $table, $type, $name, $owner, $time, $evidence ) = $prepared['a'];
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
				if ( $row['item_type'] === $type && $row['name'] === $name ) {
					return 0;
				}
			}
			$this->insert( $table, array( 'item_type' => $type, 'name' => $name, 'owner_slug' => $owner, 'first_seen_gmt' => $time, 'evidence' => $evidence ) );
			return 1;
		}
		return 1;
	}

	public function get_var( $prepared ) {
		$query = is_array( $prepared ) ? $prepared['q'] : $prepared;
		foreach ( $this->vars as $needle => $value ) {
			if ( false !== strpos( $query . ' ' . wp_json_encode_fake( $prepared ), $needle ) ) {
				return $value;
			}
		}
		return null;
	}

	public function get_col( $prepared ) {
		$query = is_array( $prepared ) ? $prepared['q'] : $prepared;
		if ( false !== strpos( $query, 'SELECT name FROM %i WHERE item_type' ) ) {
			list( $table, $type ) = $prepared['a'];
			$out = array();
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
				if ( $row['item_type'] === $type ) {
					$out[] = $row['name'];
				}
			}
			return $out;
		}
		return array();
	}

	public function get_row( $prepared, $output = null ) {
		$query = is_array( $prepared ) ? $prepared['q'] : $prepared;
		if ( false !== strpos( $query, 'WHERE slug = %s' ) ) {
			list( $table, $slug ) = $prepared['a'];
			foreach ( $this->tables[ $table ] ?? array() as $row ) {
				if ( $row['slug'] === $slug ) {
					return $row + array( 'removed_gmt' => null, 'signature' => null, 'signature_version' => '', 'sources' => '' );
				}
			}
		}
		return null;
	}

	public function get_results( $prepared, $output = null ) {
		$query = is_array( $prepared ) ? $prepared['q'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['a'] : array();
		$table = $args[0] ?? '';
		$rows  = array_values( $this->tables[ $table ] ?? array() );

		if ( false !== strpos( $query, 'WHERE id IN' ) ) {
			$ids = array_map( 'intval', array_slice( $args, 1 ) );
			return array_values( array_filter( $rows, static function ( $row ) use ( $ids ) { return in_array( (int) $row['id'], $ids, true ); } ) );
		}
		if ( false !== strpos( $query, "( %s = '' OR batch_id = %s )" ) ) {
			$columns = array( 'batch_id' => $args[1], 'status' => $args[3], 'scope' => $args[5], 'item_type' => $args[7] );
			foreach ( $columns as $column => $value ) {
				if ( '' !== (string) $value ) {
					$rows = array_values( array_filter( $rows, static function ( $row ) use ( $column, $value ) { return (string) ( $row[ $column ] ?? '' ) === (string) $value; } ) );
				}
			}
			if ( $args[9] ) {
				$user = (int) $args[9];
				$rows = array_values( array_filter( $rows, static function ( $row ) use ( $user ) { return (int) ( $row['trashed_by'] ?? 0 ) === $user; } ) );
			}
			return array_reverse( $rows );
		}
		return $rows;
	}
}

function wp_json_encode_fake( $value ) {
	return json_encode( $value );
}
