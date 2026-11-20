<?php
/**
 * The Plugins screen: the notice after a delete, and the deactivation warning.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Admin;

use AryanX\Axle\Leftovers\Inventory;
use AryanX\Axle\Leftovers\Reinstall;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Service;
use AryanX\Axle\Leftovers\Settings;
use AryanX\Axle\Leftovers\Trash;
use const AryanX\Axle\Leftovers\PLUGIN_FILE;

defined( 'ABSPATH' ) || exit;

/**
 * After a plugin or theme is deleted, the person who deleted it sees what it
 * left behind, on the next Plugins or Themes screen or right away when the
 * delete ran over AJAX. While the trash holds items, deactivating Leftovers asks first.
 */
final class Notices implements Service {

	/**
	 * The scanner.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * The trash.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * The reinstall restore.
	 *
	 * @var Reinstall
	 */
	private Reinstall $reinstall;

	/**
	 * Sets up the notices.
	 *
	 * @param Scanner        $scanner   Scanner.
	 * @param Trash          $trash     Trash.
	 * @param Reinstall|null $reinstall Reinstall restore. Default: a new one.
	 */
	public function __construct( Scanner $scanner, Trash $trash, ?Reinstall $reinstall = null ) {
		$this->scanner   = $scanner;
		$this->trash     = $trash;
		$this->reinstall = $reinstall ?? new Reinstall( $trash, new Inventory() );
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'deleted_notice' ) );
		add_action( 'admin_notices', array( $this, 'reinstall_notice' ) );
		add_action( 'admin_footer-plugins.php', array( $this, 'deactivation_dialog' ) );
		add_action( 'after_plugin_row_' . plugin_basename( PLUGIN_FILE ), array( $this, 'trash_row_warning' ), 10, 0 );
	}

	/**
	 * On the Plugins screen: a plugin that is installed has data waiting in the trash, and a restore that can be undone.
	 */
	public function reinstall_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id || ! current_user_can( Settings::capability() ) ) {
			return;
		}

		foreach ( $this->reinstall->pending() as $entry ) {
			if ( '' !== $entry['decision'] ) {
				continue;
			}
			/* translators: 1: plugin name, 2: list such as "2 tables and 3 options". */
			$text = sprintf( __( '%1$s is installed and the Leftovers trash holds what it left before: %2$s. It may have started from scratch.', 'axle-leftovers' ), $entry['name'], Display::count_list( $entry['counts'] ) );
			echo '<div class="notice notice-warning alo-reinstall-notice" data-plugin="' . esc_attr( $entry['slug'] ) . '"><p>' . esc_html( $text ) . ' <a href="' . esc_url( Reinstall::screen_url( $entry['slug'] ) ) . '">' . esc_html__( 'Preview and restore', 'axle-leftovers' ) . '</a></p></div>';
		}

		foreach ( $this->reinstall->undoable( get_current_user_id() ) as $entry ) {
			$time = strtotime( (string) $entry['gmt'] . ' UTC' );
			if ( ! $time || $time < time() - 10 * MINUTE_IN_SECONDS ) {
				continue;
			}
			/* translators: 1: plugin slug, 2: number of items. */
			$text = sprintf( _n( 'Brought back %2$s item for %1$s.', 'Brought back %2$s items for %1$s.', count( (array) $entry['restored'] ), 'axle-leftovers' ), $entry['slug'], number_format_i18n( count( (array) $entry['restored'] ) ) );
			echo '<div class="notice notice-success alo-restored-notice"><p>' . esc_html( $text );
			echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-inline-form">';
			echo '<input type="hidden" name="action" value="' . esc_attr( Page::ACTION ) . '"><input type="hidden" name="do" value="reinstall_undo">';
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $entry['id'] ) . '"><input type="hidden" name="redirect" value="' . esc_url( self_admin_url( 'plugins.php' ) ) . '">';
			wp_nonce_field( Page::NONCE );
			echo '<button type="submit" class="button-link">' . esc_html__( 'Undo', 'axle-leftovers' ) . '</button></form></p></div>';
		}
	}

	/**
	 * A line under the plugin's row while the trash holds items: deleting the
	 * plugin deletes the trash for good, and WordPress asks nothing more.
	 */
	public function trash_row_warning(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}
		$count = $this->trash->count();
		if ( $count < 1 ) {
			return;
		}

		/* translators: %d: number of items. */
		$text = sprintf( _n( 'The Leftovers trash holds %d item. Deleting this plugin deletes it for good, and that cannot be undone. Restore it first if you may need it.', 'The Leftovers trash holds %d items. Deleting this plugin deletes them for good, and that cannot be undone. Restore them first if you may need them.', $count, 'axle-leftovers' ), $count );
		echo '<tr class="plugin-update-tr ' . ( is_plugin_active( plugin_basename( PLUGIN_FILE ) ) ? 'active' : 'inactive' ) . ' alo-trash-warning"><td colspan="4" class="plugin-update colspanchange"><div class="update-message notice inline notice-warning notice-alt"><p>' . esc_html( $text ) . ' <a href="' . esc_url( Page::url( 'trash' ) ) . '">' . esc_html__( 'Open the trash', 'axle-leftovers' ) . '</a></p></div></td></tr>';
	}

	/**
	 * Remembers a deleted plugin or theme for the current user's next screen.
	 *
	 * @param string $slug Plugin slug, or `theme:<folder>` for a theme.
	 */
	public static function remember_deleted( string $slug ): void {
		$user = get_current_user_id();
		if ( $user < 1 ) {
			return;
		}
		$key   = 'axle_leftovers_deleted_' . $user;
		$slugs = get_transient( $key );
		$slugs = is_array( $slugs ) ? $slugs : array();
		if ( ! in_array( $slug, $slugs, true ) ) {
			$slugs[] = $slug;
		}
		set_transient( $key, $slugs, HOUR_IN_SECONDS );
	}

	/**
	 * Messages for plugins and themes the current user deleted, and forgets them.
	 *
	 * @return string[] Plain-text messages.
	 */
	public function pending_messages(): array {
		$key   = 'axle_leftovers_deleted_' . get_current_user_id();
		$slugs = get_transient( $key );
		if ( ! is_array( $slugs ) || ! $slugs ) {
			return array();
		}
		delete_transient( $key );

		$this->scanner->ensure_fresh( 8.0 );
		$groups   = $this->scanner->by_plugin( 'site' ) + $this->scanner->by_plugin( 'network' );
		$messages = array();
		foreach ( $slugs as $slug ) {
			$group = $groups[ (string) $slug ] ?? null;
			if ( ! $group ) {
				continue;
			}
			/* translators: 1: plugin or theme name, 2: list such as "2 tables, 12 options and 3 cron events". */
			$message = sprintf( __( '%1$s left %2$s.', 'axle-leftovers' ), $group['owner_name'], Display::count_list( $group['counts'] ) );

			/**
			 * Filters the notice shown after a plugin or theme is deleted.
			 *
			 * @param string $message Plain text, such as "Contact Form 7 left 2 tables and 12 options." or "Old Theme left 1 option and 14 template changes." Return '' to show nothing.
			 * @param string $slug    The deleted plugin's slug, or `theme:<folder>` for a theme.
			 * @param array  $counts  Item type => count.
			 */
			$message = (string) apply_filters( 'axle_leftovers_notice_message', $message, (string) $slug, $group['counts'] );
			if ( '' !== $message ) {
				$messages[] = $message;
			}
		}

		return $messages;
	}

	/**
	 * The notice on the Plugins screen and the Leftovers screen.
	 */
	public function deleted_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network', 'themes', 'themes-network' ), true ) || ! current_user_can( Settings::capability() ) ) {
			return;
		}

		foreach ( $this->pending_messages() as $message ) {
			echo self::notice_html( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice_html().
		}
	}

	/**
	 * One notice.
	 *
	 * @param string $message Plain text.
	 * @return string HTML.
	 */
	public static function notice_html( string $message ): string {
		return '<div class="notice notice-warning is-dismissible alo-deleted-notice"><p>' . esc_html( $message ) . ' <a href="' . esc_url( Page::url() ) . '">' . esc_html__( 'Review', 'axle-leftovers' ) . '</a></p></div>';
	}

	/**
	 * The warning shown before deactivating Leftovers while its trash holds items.
	 */
	public function deactivation_dialog(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}
		$count = $this->trash->count();
		if ( $count < 1 ) {
			return;
		}

		$redirect = admin_url( 'plugins.php' );
		echo '<div class="axle-confirm" id="alo-deactivate" role="group" aria-labelledby="alo-deactivate-title" data-plugin="' . esc_attr( plugin_basename( PLUGIN_FILE ) ) . '" hidden>';
		echo '<strong id="alo-deactivate-title" tabindex="-1">' . esc_html__( 'The Leftovers trash is not empty', 'axle-leftovers' ) . '</strong>';
		/* translators: %d: number of items. */
		echo '<p>' . esc_html( sprintf( _n( 'The trash holds %d item. Deactivating keeps it, but uninstalling Leftovers deletes it for good, and that cannot be undone. Restore it first if you may need it.', 'The trash holds %d items. Deactivating keeps them, but uninstalling Leftovers deletes them for good, and that cannot be undone. Restore them first if you may need them.', $count, 'axle-leftovers' ), $count ) ) . '</p>';
		echo '<p class="axle-actions">';
		// The warning sits inside the plugins table's own form, and older WordPress releases (6.6) catch every
		// submit button in that form. So this is a plain button and the script submits the separate form.
		echo '<button type="button" class="button button-primary alo-deactivate-restore" data-form="alo-deactivate-restore_all">' . esc_html__( 'Restore all', 'axle-leftovers' ) . '</button> ';
		echo '<a class="button alo-deactivate-anyway" href="#">' . esc_html__( 'Deactivate anyway', 'axle-leftovers' ) . '</a> ';
		echo '<button type="button" class="button-link alo-confirm-cancel">' . esc_html__( 'Cancel', 'axle-leftovers' ) . '</button>';
		echo '</p></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="alo-deactivate-restore_all" hidden>';
		echo '<input type="hidden" name="action" value="' . esc_attr( Page::ACTION ) . '"><input type="hidden" name="do" value="restore_all"><input type="hidden" name="redirect" value="' . esc_attr( $redirect ) . '">';
		wp_nonce_field( Page::NONCE );
		echo '</form>';
	}
}
