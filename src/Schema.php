<?php
/**
 * Tables, schedules, activation and deactivation.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the ledger, seen and trash tables with dbDelta on activation and
 * whenever the stored version differs (so sites added to a network later get
 * them on their first request), and keeps the daily schedule.
 */
final class Schema {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'axle_leftovers_db_version';
	public const CRON_DAILY        = 'axle_leftovers_daily';
	public const CRON_STEP         = 'axle_leftovers_scan_step';

	/**
	 * A table's full name.
	 *
	 * @param string $name ledger, seen or trash.
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'axle_leftovers_' . $name;
	}

	/**
	 * Activation.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ): void {
		self::each_site( (bool) $network_wide, array( self::class, 'install' ) );
	}

	/**
	 * Deactivation: stops the schedules. Data and trash stay until uninstall.
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( $network_wide = false ): void {
		self::each_site(
			(bool) $network_wide,
			static function (): void {
				wp_clear_scheduled_hook( self::CRON_DAILY );
				wp_clear_scheduled_hook( self::CRON_STEP );
			}
		);
	}

	/**
	 * Installs when the stored version differs.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Creates the tables and the daily schedule.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$ledger  = self::table( 'ledger' );
		$seen    = self::table( 'seen' );
		$trash   = self::table( 'trash' );

		dbDelta(
			"CREATE TABLE {$ledger} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				plugin_file varchar(255) NOT NULL default '',
				slug varchar(191) NOT NULL default '',
				name varchar(255) NOT NULL default '',
				version varchar(64) NOT NULL default '',
				first_seen_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				last_seen_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				removed_gmt datetime NULL default NULL,
				removed_how varchar(20) NOT NULL default '',
				signature longtext NULL,
				signature_version varchar(64) NOT NULL default '',
				sources varchar(255) NOT NULL default '',
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug)
			) {$charset};
			CREATE TABLE {$seen} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				item_type varchar(20) NOT NULL default '',
				name varchar(191) NOT NULL default '',
				owner_slug varchar(191) NOT NULL default '',
				first_seen_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				evidence varchar(40) NOT NULL default '',
				PRIMARY KEY  (id),
				UNIQUE KEY item (item_type,name),
				KEY owner_slug (owner_slug)
			) {$charset};
			CREATE TABLE {$trash} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				batch_id varchar(40) NOT NULL default '',
				scope varchar(10) NOT NULL default 'site',
				item_type varchar(20) NOT NULL default '',
				name varchar(255) NOT NULL default '',
				owner_slug varchar(191) NOT NULL default '',
				confidence varchar(20) NOT NULL default '',
				payload longtext NULL,
				original_location varchar(255) NOT NULL default '',
				trash_location varchar(255) NOT NULL default '',
				size_bytes bigint(20) unsigned NOT NULL default 0,
				export_path varchar(255) NOT NULL default '',
				trashed_by bigint(20) unsigned NOT NULL default 0,
				trashed_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				purge_after_gmt datetime NOT NULL default '0000-00-00 00:00:00',
				restored_gmt datetime NULL default NULL,
				status varchar(20) NOT NULL default 'trashed',
				PRIMARY KEY  (id),
				KEY batch_id (batch_id),
				KEY status (status),
				KEY trashed_by (trashed_by)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );

		if ( ! wp_next_scheduled( self::CRON_DAILY ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_DAILY );
		}
	}

	/**
	 * Runs a callback on the current site, or every site for network-wide.
	 *
	 * @param bool     $network_wide Every site.
	 * @param callable $callback     Callback.
	 */
	public static function each_site( bool $network_wide, callable $callback ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			$callback();
			return;
		}

		$offset = 0;
		do {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 100,
					'offset' => $offset,
				)
			);
			foreach ( $sites as $site ) {
				switch_to_blog( (int) $site );
				$callback();
				restore_current_blog();
			}
			$offset += 100;
		} while ( $sites );
	}
}
