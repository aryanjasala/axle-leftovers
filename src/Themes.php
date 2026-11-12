<?php
/**
 * Themes: which are installed, and the content a deleted theme leaves in the database.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * A theme leaves three kinds of things beyond what plugins leave:
 *
 * - `theme_mods_<stylesheet>`, the Customizer settings, kept as a plain option.
 * - Block theme content: user templates and template parts (`template`) and
 *   global styles (`globalstyles`), posts tagged with the theme in the
 *   `wp_theme` taxonomy.
 * - Custom CSS (`customcss`), a `custom_css` post titled with the stylesheet.
 *
 * The three post-based kinds are item types of their own. Each item is named
 * by the theme's stylesheet and stands for every post of that kind that
 * belongs to the theme.
 */
final class Themes {

	/**
	 * Post-based item types and the post types each one covers.
	 */
	public const CONTENT = array(
		'template'     => array( 'wp_template', 'wp_template_part' ),
		'globalstyles' => array( 'wp_global_styles' ),
		'customcss'    => array( 'custom_css' ),
	);

	/**
	 * Installed stylesheets for this request.
	 *
	 * @var array<string, true>|null
	 */
	private static ?array $cache = null;

	/**
	 * Forgets the installed list (after a switch, a delete, or in tests).
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Whether an item type is theme content kept in posts.
	 *
	 * @param string $type Item type.
	 * @return bool
	 */
	public static function is_content_type( string $type ): bool {
		return isset( self::CONTENT[ $type ] );
	}

	/**
	 * Stylesheet folders of every installed theme (working or broken), the
	 * active theme and its parent, and any folder in the themes directory.
	 *
	 * @return array<string, true>
	 */
	public static function installed(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$out = array();
		// Both working and broken themes: a theme with a syntax error in style.css is still installed.
		foreach ( array_keys( (array) wp_get_themes( array( 'errors' => null ) ) ) as $slug ) {
			$out[ (string) $slug ] = true;
		}
		foreach ( array( get_stylesheet(), get_template() ) as $slug ) {
			if ( '' !== (string) $slug ) {
				$out[ (string) $slug ] = true;
			}
		}

		/**
		 * Filters the theme folders that count as installed. Anything of an
		 * installed theme is never listed as a leftover.
		 *
		 * @param string[] $stylesheets Theme folder names: installed themes, the active theme and its parent.
		 */
		$filtered = (array) apply_filters( 'axle_leftovers_protected_themes', array_keys( $out ) );
		$out      = array();
		foreach ( array_filter( array_map( 'strval', $filtered ) ) as $slug ) {
			$out[ $slug ] = true;
		}

		self::$cache = $out;

		return $out;
	}

	/**
	 * Whether a theme folder is installed.
	 *
	 * @param string $stylesheet Theme folder name.
	 * @return bool
	 */
	public static function is_installed( string $stylesheet ): bool {
		return isset( self::installed()[ $stylesheet ] );
	}

	/**
	 * The theme folder an item belongs to by its name alone, or ''.
	 *
	 * @param string $type Item type.
	 * @param string $name Item name.
	 * @return string
	 */
	public static function owner_stylesheet( string $type, string $name ): string {
		if ( self::is_content_type( $type ) ) {
			return $name;
		}
		if ( 'option' === $type && preg_match( '/^theme_mods_(.+)$/', $name, $match ) ) {
			return $match[1];
		}

		return '';
	}

	/**
	 * Posts of a content type per theme, from one grouped query.
	 *
	 * @param string $type template, globalstyles or customcss.
	 * @return array<string, array{rows: int, bytes: int}> Stylesheet => posts and size of their content.
	 */
	public function counts( string $type ): array {
		global $wpdb;

		$out = array();
		if ( ! self::is_content_type( $type ) ) {
			return $out;
		}

		$post_types   = self::CONTENT[ $type ];
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		if ( 'customcss' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Grouped by theme, so the result is as small as the number of themes ever used.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_title AS theme, COUNT(*) AS c, SUM(LENGTH(post_content)) AS b FROM %i WHERE post_type IN ({$placeholders}) GROUP BY post_title", array_merge( array( $wpdb->posts ), $post_types ) ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Grouped by theme, so the result is as small as the number of themes ever used.
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT t.name AS theme, COUNT(DISTINCT p.ID) AS c, SUM(LENGTH(p.post_content)) AS b FROM %i p INNER JOIN %i tr ON tr.object_id = p.ID INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wp_theme' INNER JOIN %i t ON t.term_id = tt.term_id WHERE p.post_type IN ({$placeholders}) GROUP BY t.name", array_merge( array( $wpdb->posts, $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms ), $post_types ) ), ARRAY_A );
		}

		foreach ( $rows as $row ) {
			$theme = (string) $row['theme'];
			if ( '' !== $theme ) {
				$out[ $theme ] = array(
					'rows'  => (int) $row['c'],
					'bytes' => (int) $row['b'],
				);
			}
		}

		return $out;
	}

	/**
	 * IDs of the posts of one theme and content type.
	 *
	 * @param string $type       template, globalstyles or customcss.
	 * @param string $stylesheet Theme folder name.
	 * @return int[]
	 */
	public function post_ids( string $type, string $stylesheet ): array {
		global $wpdb;

		if ( ! self::is_content_type( $type ) || '' === $stylesheet ) {
			return array();
		}

		$post_types   = self::CONTENT[ $type ];
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		if ( 'customcss' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Must be live, right before an export.
			$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM %i WHERE post_title = %s AND post_type IN ({$placeholders}) ORDER BY ID", array_merge( array( $wpdb->posts, $stylesheet ), $post_types ) ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Must be live, right before an export.
			$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM %i p INNER JOIN %i tr ON tr.object_id = p.ID INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'wp_theme' INNER JOIN %i t ON t.term_id = tt.term_id WHERE t.name = %s AND p.post_type IN ({$placeholders}) ORDER BY p.ID", array_merge( array( $wpdb->posts, $wpdb->term_relationships, $wpdb->term_taxonomy, $wpdb->terms, $stylesheet ), $post_types ) ) );
		}

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Widgets that sit in the inactive area or in an orphaned sidebar, which
	 * is where a theme switch leaves widgets the new theme has no sidebar for.
	 * Leftovers only reports them; it never moves or removes a widget.
	 *
	 * @return int
	 */
	public static function inactive_widgets(): int {
		$sidebars = get_option( 'sidebars_widgets', array() );
		$count    = 0;
		if ( ! is_array( $sidebars ) ) {
			return 0;
		}
		foreach ( $sidebars as $id => $widgets ) {
			if ( ( 'wp_inactive_widgets' === $id || 0 === strpos( (string) $id, 'orphaned_widgets_' ) ) && is_array( $widgets ) ) {
				$count += count( $widgets );
			}
		}

		return $count;
	}
}
