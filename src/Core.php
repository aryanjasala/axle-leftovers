<?php
/**
 * What belongs to WordPress itself and is never listed.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Core tables, options, cron hooks, roles, capabilities, meta keys and uploads
 * folders, plus the plugin's own items. Lists are exact names; patterns are
 * regular expressions for families such as `theme_mods_*`.
 *
 * The constants are plain PHP so bin/build-signatures.php can drop core names
 * from the dataset. The methods add the site prefix and the filters.
 */
final class Core {

	public const TABLES = array( 'posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'users', 'usermeta', 'links', 'options' );

	public const NETWORK_TABLES = array( 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log', 'blog_versions', 'users', 'usermeta' );

	public const OPTIONS = array( 'siteurl', 'home', 'blogname', 'blogdescription', 'users_can_register', 'admin_email', 'start_of_week', 'use_balanceTags', 'use_smilies', 'require_name_email', 'comments_notify', 'posts_per_rss', 'rss_use_excerpt', 'mailserver_url', 'mailserver_login', 'mailserver_pass', 'mailserver_port', 'default_category', 'default_comment_status', 'default_ping_status', 'default_pingback_flag', 'posts_per_page', 'date_format', 'time_format', 'links_updated_date_format', 'comment_moderation', 'moderation_notify', 'permalink_structure', 'rewrite_rules', 'hack_file', 'blog_charset', 'moderation_keys', 'active_plugins', 'category_base', 'ping_sites', 'comment_max_links', 'gmt_offset', 'default_email_category', 'recently_edited', 'template', 'stylesheet', 'comment_registration', 'html_type', 'use_trackback', 'default_role', 'db_version', 'uploads_use_yearmonth_folders', 'upload_path', 'blog_public', 'default_link_category', 'show_on_front', 'tag_base', 'show_avatars', 'avatar_rating', 'upload_url_path', 'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop', 'medium_size_w', 'medium_size_h', 'avatar_default', 'large_size_w', 'large_size_h', 'image_default_link_type', 'image_default_size', 'image_default_align', 'close_comments_for_old_posts', 'close_comments_days_old', 'thread_comments', 'thread_comments_depth', 'page_comments', 'comments_per_page', 'default_comments_page', 'comment_order', 'sticky_posts', 'widget_categories', 'widget_text', 'widget_rss', 'uninstall_plugins', 'timezone_string', 'page_for_posts', 'page_on_front', 'default_post_format', 'link_manager_enabled', 'finished_splitting_shared_terms', 'site_icon', 'medium_large_size_w', 'medium_large_size_h', 'wp_page_for_privacy_policy', 'show_comments_cookies_opt_in', 'admin_email_lifespan', 'disallowed_keys', 'comment_previously_approved', 'auto_plugin_theme_update_emails', 'auto_update_core_dev', 'auto_update_core_minor', 'auto_update_core_major', 'wp_force_deactivated_plugins', 'wp_attachment_pages_enabled', 'wp_notes_notify', 'initial_db_version', 'fresh_site', 'user_count', 'widget_block', 'sidebars_widgets', 'widget_pages', 'widget_calendar', 'widget_archives', 'widget_media_audio', 'widget_media_image', 'widget_media_gallery', 'widget_media_video', 'widget_meta', 'widget_search', 'widget_recent-posts', 'widget_recent-comments', 'widget_tag_cloud', 'widget_nav_menu', 'widget_custom_html', 'widget_links', 'cron', 'recently_activated', 'can_compress_scripts', 'db_upgraded', 'nav_menu_options', 'theme_switched', 'theme_switched_via_customizer', 'customize_stashed_theme_mods', 'dashboard_widget_options', 'fileupload_url', 'WPLANG', 'new_admin_email', 'adminhash', 'https_detection_errors', 'recovery_keys', 'recovery_mode_email_last_sent', 'wp_calendar_block_has_published_posts', 'category_children', 'post_format_children', 'site_logo', 'auto_core_update_notified', 'auto_core_update_failed', 'core_updater.lock', 'auto_updater.lock', '_wp_suggested_policy_text_has_changed', 'ftp_credentials', 'allowedthemes', 'blog_upload_space', 'upload_space_check_disabled', 'active_sitewide_plugins', 'embed_autourls', 'embed_size_w', 'embed_size_h', 'secret', 'use_linksupdate', 'default_post_edit_rows', 'gzipcompression', 'advanced_edit', 'blacklist_keys', 'comment_whitelist', 'wp_global_styles_revision', 'layout_definitions', 'stylesheet_root', 'template_root', 'wp_template_part_area', 'wp_user_roles_backup', 'using_application_passwords', 'finished_updating_comment_type', 'https_migration_required', 'current_theme', 'theme_switch_menu_locations', '_split_terms', 'dismissed_update_core', 'update_core', 'update_plugins', 'update_themes', 'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt' );

	public const OPTION_PATTERNS = array( '/^theme_mods_/', '/^_site_transient_(timeout_)?(feed_|feed_mod_|update_(core|plugins|themes)|theme_roots|browser_|php_check_|wp_remote_block_patterns_|community-events-|available_translations|poptags_|wp_plugin_dependencies_plugin_data|wp_theme_files_patterns-|wp_font_|dirsize_cache|health-check|wp_rest_)/', '/^_transient_(timeout_)?(dash_|feed_|feed_mod_|health-check-site-status-result|doing_cron|wp_core_block_css_files|wp_styles_for_blocks|global_styles|wp_theme_files_patterns-|is_multi_author|plugin_slugs|wp_remote_block_patterns_|wp_font_|dirsize_cache|update_|wp_rest_|wp_theme_patterns|_wp_|wp_request_|mod_rewrite_|oembed_|wp_get_global|php_check|browser_|community-events|wp_block|block_)/', '/^_transient_timeout_/', '/^_site_transient_timeout_/', '/^widget_(categories|text|rss|pages|calendar|archives|media_\w+|meta|search|recent-posts|recent-comments|tag_cloud|nav_menu|custom_html|block|links)$/', '/^[a-z_]+_children$/', '/^wp_global_styles/', '/^_wp_/', '/^wp_persisted_preferences$/', '/^auto_update_(plugin|theme)s$/' );

	public const CRON = array( 'wp_version_check', 'wp_update_plugins', 'wp_update_themes', 'wp_scheduled_delete', 'wp_scheduled_auto_draft_delete', 'delete_expired_transients', 'wp_privacy_delete_old_export_files', 'wp_privacy_personal_data_cleanup_requests', 'recovery_mode_clean_expired_keys', 'wp_site_health_scheduled_check', 'wp_https_detection', 'wp_update_user_counts', 'wp_delete_temp_updater_backups', 'importer_scheduled_cleanup', 'upgrader_scheduled_cleanup', 'wp_maybe_auto_update', 'wp_split_shared_term_batch', 'publish_future_post', 'do_pings', 'wp_update_comment_type_batch', 'update_network_counts', 'wp_batch_split_terms', 'wp_maybe_auto_update', 'wp_cron_test' );

	public const ROLES = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

	public const CAPS = array( 'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins', 'edit_users', 'edit_files', 'manage_options', 'moderate_comments', 'manage_categories', 'manage_links', 'upload_files', 'import', 'unfiltered_html', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'edit_pages', 'read', 'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5', 'level_6', 'level_7', 'level_8', 'level_9', 'level_10', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_private_posts', 'read_private_posts', 'delete_private_pages', 'edit_private_pages', 'read_private_pages', 'delete_users', 'create_users', 'unfiltered_upload', 'edit_dashboard', 'update_plugins', 'delete_plugins', 'install_plugins', 'update_themes', 'install_themes', 'update_core', 'list_users', 'remove_users', 'promote_users', 'edit_theme_options', 'delete_themes', 'export', 'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_plugins', 'manage_network_themes', 'manage_network_options', 'upgrade_network', 'setup_network', 'resume_plugins', 'resume_themes', 'view_site_health_checks', 'customize', 'delete_site', 'install_languages', 'update_languages', 'edit_block_binding', 'edit_css', 'edit_comment', 'erase_others_personal_data', 'export_others_personal_data', 'manage_privacy_options' );

	public const META = array(
		'post'    => array( '_edit_lock', '_edit_last', '_thumbnail_id', '_pingme', '_encloseme', '_trackbackme', 'enclosure', 'footnotes', 'origin', '_customize_changeset_uuid', '_customize_draft_post_name', '_customize_restore_dismissed', '_oembed_' ),
		'user'    => array( 'nickname', 'first_name', 'last_name', 'description', 'rich_editing', 'syntax_highlighting', 'infinite_scrolling', 'comment_shortcuts', 'admin_color', 'use_ssl', 'show_admin_bar_front', 'locale', 'dismissed_wp_pointers', 'show_welcome_panel', 'session_tokens', 'default_password_nag', 'community-events-location', '_application_passwords', 'primary_blog', 'source_domain', 'nav_menu_recently_edited', 'enable_custom_fields', 'wporg_favorites', '_new_email', 'show_try_gutenberg_panel', 'aim', 'yim', 'jabber', 'user_url' ),
		'term'    => array(),
		'comment' => array(),
	);

	public const META_PATTERNS = array(
		'post'    => array( '/^_wp_/', '/^_menu_item_/', '/^_oembed_/', '/^_customize_/' ),
		'user'    => array( '/^(closedpostboxes|metaboxhidden|meta-box-order|screen_layout)_/', '/^manage.+columnshidden$/', '/^(edit_)?[a-z0-9_\-]+_per_page$/', '/^_wp_/' ),
		'term'    => array( '/^_wp_/' ),
		'comment' => array( '/^_wp_/' ),
	);

	public const FOLDERS = array( 'sites', 'fonts', 'wp-personal-data-exports', 'axle-leftovers' );

	public const SITEMETA = array( 'site_name', 'admin_email', 'admin_user_id', 'registration', 'upload_filetypes', 'blog_upload_space', 'fileupload_maxk', 'site_admins', 'allowedthemes', 'illegal_names', 'wpmu_upgrade_site', 'welcome_email', 'first_post', 'siteurl', 'add_new_users', 'upload_space_check_disabled', 'subdomain_install', 'ms_files_rewriting', 'user_count', 'initial_db_version', 'active_sitewide_plugins', 'WPLANG', 'blog_count', 'menu_items', 'first_page', 'first_comment', 'first_comment_url', 'first_comment_author', 'welcome_user_email', 'limited_email_domains', 'banned_email_domains', 'global_terms_enabled', 'recently_activated', 'can_compress_scripts', 'dismissed_update_core', 'db_version', 'registrationnotification', 'new_admin_email', 'network_admin_hash', 'site_meta_supported', 'wp_force_deactivated_plugins', 'auto_core_update_notified', 'recovery_keys', 'recovery_mode_email_last_sent', 'update_services', 'admin_email_lifespan', 'customize_stashed_theme_mods', 'nonce_key', 'nonce_salt', 'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt', 'secure_auth_key', 'secure_auth_salt', 'secret_key', 'blog_count_ts', 'user_count_ts' );

	/**
	 * Core and protected lists for this request.
	 *
	 * @var array<string, array<string, string[]>>|null
	 */
	private static ?array $cache = null;

	/**
	 * Forgets the lists, so the filters run again (after switch_to_blog(), in tests).
	 */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Exact core names by item type, after the `axle_leftovers_core_items` filter.
	 *
	 * @return array<string, string[]>
	 */
	public static function items(): array {
		global $wpdb;

		$prefix = (string) $wpdb->prefix;
		$base   = (string) $wpdb->base_prefix;
		$tables = array_map(
			static function ( string $table ) use ( $prefix ): string {
				return $prefix . $table;
			},
			self::TABLES
		);
		foreach ( self::NETWORK_TABLES as $table ) {
			$tables[] = $base . $table;
		}

		$items = array(
			'table'       => $tables,
			'option'      => array_merge( self::OPTIONS, array( $prefix . 'user_roles' ) ),
			'sitemeta'    => self::SITEMETA,
			'cron'        => self::CRON,
			'role'        => self::ROLES,
			'cap'         => self::CAPS,
			'postmeta'    => self::META['post'],
			'usermeta'    => self::META['user'],
			'termmeta'    => self::META['term'],
			'commentmeta' => self::META['comment'],
			'folder'      => self::FOLDERS,
		);

		/**
		 * Filters the core items, by type, that are never listed.
		 *
		 * @param array<string, string[]> $items Type => exact names.
		 */
		$filtered = (array) apply_filters( 'axle_leftovers_core_items', $items );

		return self::clean_lists( $filtered, $items );
	}

	/**
	 * The plugin's own items and any others a site wants hidden, by type.
	 *
	 * @return array<string, string[]>
	 */
	public static function protected_items(): array {
		global $wpdb;

		$prefix = (string) $wpdb->prefix;
		$items  = array(
			'table'  => array( $prefix . 'axle_leftovers_ledger', $prefix . 'axle_leftovers_seen', $prefix . 'axle_leftovers_trash' ),
			'option' => array( Settings::OPTION, Scanner::OPTION, Schema::DB_VERSION_OPTION ),
			'cron'   => array( Schema::CRON_DAILY, Schema::CRON_STEP ),
			'folder' => array( 'axle-leftovers' ),
		);

		/**
		 * Filters items that are never listed, by type, on top of core's.
		 *
		 * @param array<string, string[]> $items Type => exact names.
		 */
		$filtered = (array) apply_filters( 'axle_leftovers_protected_items', $items );

		return self::clean_lists( $filtered, $items );
	}

	/**
	 * Whether an item is core, the plugin's own, or protected by a filter.
	 *
	 * @param string $type Item type.
	 * @param string $name Item name (tables with the prefix).
	 * @return bool
	 */
	public static function is_protected( string $type, string $name ): bool {
		if ( null === self::$cache ) {
			self::$cache = array(
				'core'      => self::items(),
				'protected' => self::protected_items(),
			);
		}

		foreach ( array( 'core', 'protected' ) as $list ) {
			if ( isset( self::$cache[ $list ][ $type ] ) && in_array( $name, self::$cache[ $list ][ $type ], true ) ) {
				return true;
			}
		}

		// Our own transients and options, whatever their suffix.
		if ( in_array( $type, array( 'option', 'sitemeta' ), true ) && preg_match( '/^(_site)?(_transient_(timeout_)?)?axle_leftovers_/', $name ) ) {
			return true;
		}

		return self::matches_pattern( $type, $name );
	}

	/**
	 * Whether a name matches one of core's name families.
	 *
	 * @param string $type Item type.
	 * @param string $name Name.
	 * @return bool
	 */
	public static function matches_pattern( string $type, string $name ): bool {
		global $wpdb;

		$patterns = array();
		if ( 'option' === $type || 'sitemeta' === $type ) {
			$patterns = self::OPTION_PATTERNS;
		} elseif ( 'usermeta' === $type ) {
			$base       = preg_quote( (string) $wpdb->base_prefix, '/' );
			$patterns   = self::META_PATTERNS['user'];
			$patterns[] = '/^' . $base . '(\d+_)?(capabilities|user_level|user-settings|user-settings-time|dashboard_quick_press_last_post_id|persisted_preferences|media_library_mode|autosave_draft_ids)$/';
		} elseif ( preg_match( '/^(post|term|comment)meta$/', $type, $match ) ) {
			$patterns = self::META_PATTERNS[ $match[1] ];
		} elseif ( 'folder' === $type ) {
			$patterns = array( '/^\d{4}$/', '/^\./' );
		} elseif ( 'table' === $type && is_multisite() ) {
			// Another site's tables are that site's business.
			$prefix = (string) $wpdb->prefix;
			$base   = (string) $wpdb->base_prefix;
			if ( $prefix === $base ) {
				$patterns = array( '/^' . preg_quote( $base, '/' ) . '\d+_/' );
			}
		}

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keeps only arrays of strings, falling back per type.
	 *
	 * @param array $lists    Filtered lists.
	 * @param array $fallback Defaults.
	 * @return array<string, string[]>
	 */
	private static function clean_lists( array $lists, array $fallback ): array {
		$out = array();
		foreach ( $lists + $fallback as $type => $names ) {
			if ( ! is_string( $type ) ) {
				continue;
			}
			$out[ $type ] = array_values(
				array_filter(
					array_map( 'strval', is_array( $names ) ? $names : (array) ( $fallback[ $type ] ?? array() ) ),
					static function ( string $name ): bool {
						return '' !== $name;
					}
				)
			);
		}

		return $out;
	}
}
