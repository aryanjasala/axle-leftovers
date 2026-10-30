<?php
/**
 * The runtime first-seen ledger: who creates what.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

use AryanX\Axle\Leftovers\Admin\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Records the owner of tables, options, cron hooks, roles, capabilities,
 * meta keys and uploads folders as they are created:
 *
 * - Around plugin activation: a snapshot of names before and after. What is
 *   new belongs to that plugin (Certain).
 * - At runtime: new options, tables, cron hooks, roles, capabilities and meta
 *   keys get a backtrace, and the first frame in a plugin or theme folder names
 *   the owner. Known names are skipped without a backtrace.
 * - Around plugin deletion: a snapshot before and after uninstall.php, and the
 *   code signature, read while the files still exist.
 */
class Recorder implements Service {

	/**
	 * The ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * The inventory.
	 *
	 * @var Inventory
	 */
	private Inventory $inventory;

	/**
	 * Snapshots taken before activation or uninstall, by plugin file.
	 *
	 * @var array<string, array>
	 */
	private array $before = array();

	/**
	 * The plugin being activated, while it is: what appears meanwhile is its own.
	 *
	 * @var string
	 */
	private string $activating = '';

	/**
	 * Sets up the recorder.
	 *
	 * @param Ledger    $ledger    Ledger.
	 * @param Inventory $inventory Inventory.
	 */
	public function __construct( Ledger $ledger, Inventory $inventory ) {
		$this->ledger    = $ledger;
		$this->inventory = $inventory;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		global $wpdb;

		add_action( 'activate_plugin', array( $this, 'before_activation' ), 10, 1 );
		add_action( 'activated_plugin', array( $this, 'after_activation' ), 10, 1 );
		add_action( 'pre_uninstall_plugin', array( $this, 'before_uninstall' ), 10, 1 );
		add_action( 'delete_plugin', array( $this, 'before_delete' ), 10, 1 );
		add_action( 'deleted_plugin', array( $this, 'after_delete' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );

		if ( ! Settings::runtime_ledger() ) {
			return;
		}

		add_action( 'added_option', array( $this, 'option_added' ), 10, 1 );
		add_action( 'add_site_option', array( $this, 'site_option_added' ), 10, 1 );
		add_filter( 'dbdelta_create_queries', array( $this, 'dbdelta_tables' ) );
		add_filter( 'query', array( $this, 'query' ) );
		add_filter( 'schedule_event', array( $this, 'event_scheduled' ) );
		add_action( 'update_option_' . $wpdb->prefix . 'user_roles', array( $this, 'roles_changed' ), 10, 2 );
		foreach ( array( 'post', 'user', 'term', 'comment' ) as $meta_type ) {
			add_action( "added_{$meta_type}_meta", array( $this, 'meta_added' ), 10, 3 );
		}
	}

	/**
	 * Snapshot before a plugin is activated.
	 *
	 * @param string $plugin Plugin file.
	 */
	public function before_activation( $plugin ): void {
		$plugin = (string) $plugin;
		if ( $this->is_self( $plugin ) ) {
			return;
		}

		$this->activating        = $plugin;
		$this->before[ $plugin ] = $this->inventory->names();
	}

	/**
	 * Diff after activation: everything new belongs to the plugin.
	 *
	 * @param string $plugin Plugin file.
	 */
	public function after_activation( $plugin ): void {
		$plugin           = (string) $plugin;
		$this->activating = '';
		if ( $this->is_self( $plugin ) || ! isset( $this->before[ $plugin ] ) ) {
			return;
		}

		$slug = Ledger::slug_for( $plugin );
		$new  = Inventory::diff( $this->before[ $plugin ], $this->inventory->names() );
		unset( $this->before[ $plugin ] );

		foreach ( $new as $type => $names ) {
			foreach ( $names as $name ) {
				if ( ! Core::is_protected( $type, $name ) ) {
					$this->ledger->record_seen( $type, $name, $slug, 'activation' );
				}
			}
		}

		$this->ledger->record_plugin( $plugin, $this->plugin_data( $plugin ), array( 'activation' ) );
		// Read the code now: the plugin may be deleted over FTP before the next scan.
		$this->ensure_signature( $plugin );
		Scanner::mark_stale();
	}

	/**
	 * Snapshot and signature before uninstall.php runs.
	 *
	 * @param string $plugin Plugin file.
	 */
	public function before_uninstall( $plugin ): void {
		$plugin = (string) $plugin;
		if ( $this->is_self( $plugin ) ) {
			return;
		}

		$this->ensure_signature( $plugin );
		$this->before[ $plugin ] = $this->inventory->names();
	}

	/**
	 * Fires after uninstall.php and before the files go: what did it clean?
	 *
	 * @param string $plugin Plugin file.
	 */
	public function before_delete( $plugin ): void {
		$plugin = (string) $plugin;
		if ( $this->is_self( $plugin ) ) {
			return;
		}

		$this->ensure_signature( $plugin );
		if ( ! isset( $this->before[ $plugin ] ) ) {
			return;
		}

		$slug    = Ledger::slug_for( $plugin );
		$cleaned = Inventory::diff( $this->inventory->names(), $this->before[ $plugin ] );
		unset( $this->before[ $plugin ] );
		foreach ( $cleaned as $type => $names ) {
			foreach ( $names as $name ) {
				$this->ledger->record_seen( $type, $name, $slug, 'uninstall-diff' );
			}
		}
		$this->ledger->record_plugin( $plugin, array(), array( 'uninstall-diff' ) );
	}

	/**
	 * Marks the plugin removed, and leaves a notice for the person who deleted it.
	 *
	 * @param string $plugin  Plugin file.
	 * @param bool   $deleted Whether the files were deleted.
	 */
	public function after_delete( $plugin, $deleted = true ): void {
		$plugin = (string) $plugin;
		if ( ! $deleted || $this->is_self( $plugin ) ) {
			return;
		}

		$slug = Ledger::slug_for( $plugin );
		$this->ledger->mark_removed( $slug, defined( 'WP_CLI' ) && WP_CLI ? 'cli' : 'admin' );
		Scanner::mark_stale();
		Notices::remember_deleted( $slug );
	}

	/**
	 * Reads the signature again after a plugin update.
	 *
	 * @param mixed $upgrader Upgrader.
	 * @param mixed $extra    Hook extra.
	 */
	public function after_update( $upgrader, $extra = array() ): void {
		if ( ! is_array( $extra ) || 'plugin' !== ( $extra['type'] ?? '' ) ) {
			return;
		}

		$plugins = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
		foreach ( $plugins as $plugin ) {
			if ( ! $this->is_self( (string) $plugin ) ) {
				$this->ledger->record_plugin( (string) $plugin, $this->plugin_data( (string) $plugin ), array(), null );
			}
		}
		Scanner::mark_stale();
	}

	/**
	 * Scans a plugin's code when its stored signature is missing or for another version.
	 *
	 * @param string $plugin Plugin file.
	 * @param bool   $force  Read the code even when the stored signature is current.
	 * @return array The ledger entry.
	 */
	public function ensure_signature( string $plugin, bool $force = false ): array {
		$slug  = Ledger::slug_for( $plugin );
		$entry = $this->ledger->get( $slug );
		$data  = $this->plugin_data( $plugin );
		if ( ! $force && $entry && null !== $entry['signature'] && ( $data['version'] ?? '' ) === $entry['signature_version'] ) {
			return $entry;
		}

		$path = WP_PLUGIN_DIR . '/' . ( false !== strpos( $plugin, '/' ) ? dirname( $plugin ) : $plugin );

		return $this->ledger->record_plugin( $plugin, $data, array( 'static' ), Signature::scan_path( $path ) );
	}

	/**
	 * A new option.
	 *
	 * @param string $option Option name.
	 */
	public function option_added( $option ): void {
		$this->runtime( 'option', (string) $option );
	}

	/**
	 * A new network option.
	 *
	 * @param string $option Option name.
	 */
	public function site_option_added( $option ): void {
		$this->runtime( 'sitemeta', (string) $option );
	}

	/**
	 * Tables created through dbDelta().
	 *
	 * @param array $queries Table => CREATE query.
	 * @return array Unchanged.
	 */
	public function dbdelta_tables( $queries ) {
		foreach ( array_keys( (array) $queries ) as $table ) {
			$this->runtime( 'table', trim( (string) $table, '`' ) );
		}

		return $queries;
	}

	/**
	 * Tables created with a plain CREATE TABLE query.
	 *
	 * @param mixed $query SQL.
	 * @return mixed Unchanged.
	 */
	public function query( $query ) {
		if ( is_string( $query ) && 0 === strncasecmp( ltrim( $query ), 'CREATE TABLE', 12 ) && preg_match( '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_$]+)`?/i', $query, $match ) ) {
			$this->runtime( 'table', $match[1] );
		}

		return $query;
	}

	/**
	 * A scheduled cron event.
	 *
	 * @param object|false $event Event.
	 * @return object|false Unchanged.
	 */
	public function event_scheduled( $event ) {
		if ( is_object( $event ) && isset( $event->hook ) ) {
			$this->runtime( 'cron', (string) $event->hook );
		}

		return $event;
	}

	/**
	 * New roles and capabilities.
	 *
	 * @param mixed $old_value Roles before.
	 * @param mixed $new_value Roles after.
	 */
	public function roles_changed( $old_value, $new_value ): void {
		$old_value = is_array( $old_value ) ? $old_value : array();
		$new_value = is_array( $new_value ) ? $new_value : array();
		foreach ( array_diff_key( $new_value, $old_value ) as $role => $data ) {
			$this->runtime( 'role', (string) $role );
		}

		$old_caps = array();
		foreach ( $old_value as $data ) {
			$old_caps += (array) ( $data['capabilities'] ?? array() );
		}
		foreach ( $new_value as $data ) {
			foreach ( array_keys( array_diff_key( (array) ( $data['capabilities'] ?? array() ), $old_caps ) ) as $cap ) {
				$this->runtime( 'cap', (string) $cap );
			}
		}
	}

	/**
	 * A meta row added; records the key the first time it is seen.
	 *
	 * @param int    $meta_id   Meta ID.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Key.
	 */
	public function meta_added( $meta_id, $object_id, $meta_key ): void {
		$meta_type = (string) preg_replace( '/^added_(\w+)_meta$/', '$1', (string) current_action() );
		if ( '' === $meta_type || ! Settings::track_meta_keys( $meta_type ) ) {
			return;
		}

		$this->runtime( $meta_type . 'meta', (string) $meta_key );
	}

	/**
	 * Records an item's owner from the call stack, once per name.
	 *
	 * @param string $type Item type.
	 * @param string $name Name.
	 */
	private function runtime( string $type, string $name ): void {
		if ( '' === $name || $this->ledger->is_seen( $type, $name ) ) {
			return;
		}
		// Hashed transients would grow the table without telling anything.
		if ( preg_match( '/^_(site_)?transient_timeout_|[0-9a-f]{12,}/i', $name ) || Core::is_protected( $type, $name ) ) {
			return;
		}
		if ( '' !== $this->activating ) {
			// Whatever appears while a plugin activates is that plugin's.
			$this->ledger->record_seen( $type, $name, Ledger::slug_for( $this->activating ), 'activation' );
			return;
		}

		$owner = self::owner_from_trace();
		if ( '' !== $owner ) {
			$this->ledger->record_seen( $type, $name, $owner, 'runtime' );
		}
	}

	/**
	 * The plugin or theme whose code is running: the first stack frame in a
	 * plugin, must-use plugin or theme folder.
	 *
	 * @param array|null $frames Stack frames, for tests. Default: the current stack.
	 * @return string Plugin slug, `mu-plugin:<file>`, `theme:<folder>`, or '' for core.
	 */
	public static function owner_from_trace( ?array $frames = null ): string {
		if ( null === $frames ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Names the plugin that created an item; runs once per new name, never on known names.
			$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 60 );
		}

		$plugins = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
		$mu      = defined( 'WPMU_PLUGIN_DIR' ) ? wp_normalize_path( WPMU_PLUGIN_DIR ) . '/' : '';
		$themes  = wp_normalize_path( get_theme_root() ) . '/';
		$self    = wp_normalize_path( dirname( PLUGIN_FILE ) ) . '/';

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$file = wp_normalize_path( (string) $frame['file'] );
			if ( 0 === strpos( $file, $self ) ) {
				continue;
			}
			if ( 0 === strpos( $file, $plugins ) ) {
				return Ledger::slug_for( substr( $file, strlen( $plugins ) ) );
			}
			if ( '' !== $mu && 0 === strpos( $file, $mu ) ) {
				return 'mu-plugin:' . Ledger::slug_for( substr( $file, strlen( $mu ) ) );
			}
			if ( 0 === strpos( $file, $themes ) ) {
				return 'theme:' . (string) strtok( substr( $file, strlen( $themes ) ), '/' );
			}
		}

		return '';
	}

	/**
	 * Name and version from a plugin's header.
	 *
	 * @param string $plugin Plugin file.
	 * @return array{name?: string, version?: string}
	 */
	private function plugin_data( string $plugin ): array {
		$file = WP_PLUGIN_DIR . '/' . $plugin;
		if ( ! is_file( $file ) ) {
			return array();
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $file, false, false );

		return array(
			'name'    => (string) $data['Name'],
			'version' => (string) $data['Version'],
		);
	}

	/**
	 * Whether the plugin file is Axle Leftovers itself.
	 *
	 * @param string $plugin Plugin file.
	 * @return bool
	 */
	private function is_self( string $plugin ): bool {
		return plugin_basename( PLUGIN_FILE ) === $plugin;
	}
}
