<?php
/**
 * Content of post types and taxonomies that nothing registers any more.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Two item types:
 *
 * - `posttype`: every post of one post type (all statuses, with revisions,
 *   meta, comments and term relationships). The name is the post type.
 * - `taxonomy`: every term of one taxonomy (with term meta and the
 *   relationships that point at it). The name is the taxonomy.
 *
 * An item is only listed once the Observer has watched long enough to say
 * that no code registered the type in the last N days.
 */
class Content {

	/**
	 * Item types that hold posts or terms.
	 */
	public const TYPES = array( 'posttype', 'taxonomy' );

	/**
	 * Whether an item type is post type or taxonomy content.
	 *
	 * @param string $type Item type.
	 * @return bool
	 */
	public static function is_content_type( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	/**
	 * Post types that have posts, with counts, for the types that are not registered now.
	 * Core types and the theme content types are left out. The caller applies the verdict.
	 *
	 * @param string $type posttype or taxonomy.
	 * @return array<string, array{rows: int, bytes: int, detail: array}> Name => stats.
	 */
	public function counts( string $type ): array {
		global $wpdb;

		$out = array();
		if ( 'posttype' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One grouped query, as small as the number of post types ever used.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT post_type, post_status, COUNT(*) AS c, SUM(LENGTH(post_content)) AS b, MIN(post_date_gmt) AS first_gmt, MAX(post_date_gmt) AS last_gmt FROM %i GROUP BY post_type, post_status', $wpdb->posts ), ARRAY_A );
			foreach ( $rows as $row ) {
				$name = (string) $row['post_type'];
				if ( '' === $name || $this->skip( 'posttype', $name ) ) {
					continue;
				}
				if ( ! isset( $out[ $name ] ) ) {
					$out[ $name ] = array(
						'rows'   => 0,
						'bytes'  => 0,
						'detail' => array(
							'statuses' => array(),
							'first'    => '',
							'last'     => '',
						),
					);
				}
				$out[ $name ]['rows']  += (int) $row['c'];
				$out[ $name ]['bytes'] += (int) $row['b'];
				$out[ $name ]['detail']['statuses'][ (string) $row['post_status'] ] = (int) $row['c'];
				$out[ $name ]['detail']['first']                                    = self::earlier( (string) $out[ $name ]['detail']['first'], (string) $row['first_gmt'] );
				$out[ $name ]['detail']['last']                                     = self::later( (string) $out[ $name ]['detail']['last'], (string) $row['last_gmt'] );
			}
			$this->add_post_details( $out );

			return $out;
		}

		if ( 'taxonomy' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One grouped query, as small as the number of taxonomies ever used.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT tt.taxonomy, COUNT(*) AS c, SUM(LENGTH(t.name) + LENGTH(tt.description)) AS b, SUM(tt.count) AS objects FROM %i tt INNER JOIN %i t ON t.term_id = tt.term_id GROUP BY tt.taxonomy', $wpdb->term_taxonomy, $wpdb->terms ), ARRAY_A );
			foreach ( $rows as $row ) {
				$name = (string) $row['taxonomy'];
				if ( '' === $name || $this->skip( 'taxonomy', $name ) ) {
					continue;
				}
				$out[ $name ] = array(
					'rows'   => (int) $row['c'],
					'bytes'  => (int) $row['b'],
					'detail' => array( 'objects' => (int) $row['objects'] ),
				);
			}
		}

		return $out;
	}

	/**
	 * Whether a name is never a candidate: core, registered now, or owned by the theme content types.
	 *
	 * @param string $type posttype or taxonomy.
	 * @param string $name Slug.
	 * @return bool
	 */
	private function skip( string $type, string $name ): bool {
		if ( 'posttype' === $type && ( 'revision' === $name || $this->is_theme_post_type( $name ) ) ) {
			return true;
		}

		return Core::is_protected( $type, $name );
	}

	/**
	 * Whether a post type is one of the theme content types, which have their own items.
	 *
	 * @param string $name Post type.
	 * @return bool
	 */
	private function is_theme_post_type( string $name ): bool {
		foreach ( Themes::CONTENT as $post_types ) {
			if ( in_array( $name, $post_types, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Meta, comment and relationship counts for the post types in a result, in three grouped queries.
	 *
	 * @param array<string, array> $out Result of counts(), updated.
	 */
	private function add_post_details( array &$out ): void {
		global $wpdb;

		if ( ! $out ) {
			return;
		}

		$names = array_map( 'strval', array_keys( $out ) );
		$marks = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$joins = array(
			'meta'      => array( $wpdb->postmeta, 'x.post_id' ),
			'comments'  => array( $wpdb->comments, 'x.comment_post_ID' ),
			'relations' => array( $wpdb->term_relationships, 'x.object_id' ),
		);
		foreach ( $joins as $key => $join ) {
			foreach ( $out as $name => $entry ) {
				$out[ $name ]['detail'][ $key ] = 0;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Grouped by post type, only for the unregistered ones; the column is one of three fixed names.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.post_type AS name, COUNT(*) AS c FROM %i p INNER JOIN %i x ON {$join[1]} = p.ID WHERE p.post_type IN ({$marks}) GROUP BY p.post_type", array_merge( array( $wpdb->posts, $join[0] ), $names ) ), ARRAY_A );
			foreach ( $rows as $row ) {
				if ( isset( $out[ (string) $row['name'] ] ) ) {
					$out[ (string) $row['name'] ]['detail'][ $key ] = (int) $row['c'];
				}
			}
		}
	}

	/**
	 * IDs of the posts of one post type, every status.
	 *
	 * @param string $name Post type.
	 * @return int[]
	 */
	public function post_ids( string $name ): array {
		global $wpdb;

		if ( '' === $name || $this->skip( 'posttype', $name ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be live, right before an export.
		$ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT ID FROM %i WHERE post_type = %s ORDER BY ID', $wpdb->posts, $name ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * The term_taxonomy IDs of one taxonomy.
	 *
	 * @param string $name Taxonomy.
	 * @return int[]
	 */
	public function term_taxonomy_ids( string $name ): array {
		global $wpdb;

		if ( '' === $name || $this->skip( 'taxonomy', $name ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Must be live, right before an export.
		$ids = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT term_taxonomy_id FROM %i WHERE taxonomy = %s ORDER BY term_taxonomy_id', $wpdb->term_taxonomy, $name ) );

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Examples that let a person recognise the content: the newest titles of a post
	 * type, or the biggest terms of a taxonomy.
	 *
	 * @param string $type  posttype or taxonomy.
	 * @param string $name  Slug.
	 * @param int    $limit Most examples. Default from Settings::sample_size().
	 * @return array<int, array{title: string, date: string, status: string}> Date is GMT; terms have no date.
	 */
	public function sample( string $type, string $name, int $limit = 0 ): array {
		global $wpdb;

		$limit = $limit > 0 ? $limit : Settings::sample_size();
		$out   = array();
		if ( 'posttype' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A handful of rows, for the screen the person is looking at.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_title, post_date_gmt, post_status FROM %i WHERE post_type = %s AND post_status <> 'auto-draft' ORDER BY post_date_gmt DESC, ID DESC LIMIT %d", $wpdb->posts, $name, $limit ), ARRAY_A );
			foreach ( $rows as $row ) {
				$title = trim( wp_strip_all_tags( (string) $row['post_title'] ) );
				$out[] = array(
					'title'  => '' !== $title ? $title : __( '(no title)', 'axle-leftovers' ),
					'date'   => (string) $row['post_date_gmt'],
					'status' => (string) $row['post_status'],
				);
			}
		} elseif ( 'taxonomy' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A handful of rows, for the screen the person is looking at.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT t.name, tt.count FROM %i tt INNER JOIN %i t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s ORDER BY tt.count DESC, t.name ASC LIMIT %d', $wpdb->term_taxonomy, $wpdb->terms, $name, $limit ), ARRAY_A );
			foreach ( $rows as $row ) {
				$out[] = array(
					'title'  => (string) $row['name'],
					'date'   => '',
					'status' => (string) (int) $row['count'],
				);
			}
		}

		/**
		 * Filters the examples shown for a post type or taxonomy.
		 *
		 * @param array[] $out   Examples: title, date (GMT), status. For terms the status holds the number of posts.
		 * @param string  $type  posttype or taxonomy.
		 * @param string  $name  Slug.
		 */
		return array_values( (array) apply_filters( 'axle_leftovers_content_sample', $out, $type, $name ) );
	}

	/**
	 * A summary of one item for the screen, WP-CLI and abilities.
	 *
	 * @param array $item A listed item (type, name, rows, detail).
	 * @return array{statuses: array<string, int>, first: string, last: string, meta: int, comments: int, relations: int, objects: int, sample: array<int, array>}
	 */
	public function summary( array $item ): array {
		$detail = (array) ( $item['detail'] ?? array() );

		return array(
			'statuses'  => (array) ( $detail['statuses'] ?? array() ),
			'first'     => (string) ( $detail['first'] ?? '' ),
			'last'      => (string) ( $detail['last'] ?? '' ),
			'meta'      => (int) ( $detail['meta'] ?? 0 ),
			'comments'  => (int) ( $detail['comments'] ?? 0 ),
			'relations' => (int) ( $detail['relations'] ?? 0 ),
			'objects'   => (int) ( $detail['objects'] ?? 0 ),
			'sample'    => $this->sample( (string) $item['type'], (string) $item['name'] ),
		);
	}

	/**
	 * The evidence line that says how long the type has gone unregistered.
	 *
	 * @param string $type posttype or taxonomy.
	 * @param string $name Slug.
	 * @return array{code: string, text: string, when?: string}
	 */
	public static function evidence( string $type, string $name ): array {
		$verdict = Observer::verdict( $type, $name );
		$line    = array(
			'code' => 'observed',
			'text' => sprintf(
				/* translators: %s: number of days. */
				_n( 'Not registered by any code in the last %s day, on any watched request', 'Not registered by any code in the last %s days, on any watched request', $verdict['days'], 'axle-leftovers' ),
				number_format_i18n( $verdict['days'] )
			),
		);
		if ( $verdict['last'] > 0 ) {
			$line['text'] = sprintf(
				/* translators: %s: number of days. */
				_n( 'Not registered by any code in the last %s day, on any watched request. Last seen registered', 'Not registered by any code in the last %s days, on any watched request. Last seen registered', $verdict['days'], 'axle-leftovers' ),
				number_format_i18n( $verdict['days'] )
			);
			$line['when'] = gmdate( 'Y-m-d H:i:s', $verdict['last'] );
		}

		return $line;
	}

	/**
	 * The earlier of two GMT datetimes, ignoring empty and zero dates.
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 * @return string
	 */
	private static function earlier( string $a, string $b ): string {
		$a = self::real( $a );
		$b = self::real( $b );

		return '' === $a ? $b : ( '' === $b ? $a : min( $a, $b ) );
	}

	/**
	 * The later of two GMT datetimes, ignoring empty and zero dates.
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 * @return string
	 */
	private static function later( string $a, string $b ): string {
		return max( self::real( $a ), self::real( $b ) );
	}

	/**
	 * A datetime, or '' for the zero date.
	 *
	 * @param string $date MySQL datetime.
	 * @return string
	 */
	private static function real( string $date ): string {
		return 0 === strpos( $date, '0000' ) ? '' : $date;
	}
}
