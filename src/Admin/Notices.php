<?php
/**
 * The Plugins screen: the notice after a delete, and the deactivation warning.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Admin;

use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Service;
use AryanX\Axle\Leftovers\Settings;
use AryanX\Axle\Leftovers\Trash;
use const AryanX\Axle\Leftovers\PLUGIN_FILE;

defined( 'ABSPATH' ) || exit;

/**
 * After a plugin is deleted, the person who deleted it sees what it left
 * behind, on the next Plugins screen or right away when the delete ran over
 * AJAX. While the trash holds items, deactivating Leftovers asks first.
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
	 * Sets up the notices.
	 *
	 * @param Scanner $scanner Scanner.
	 * @param Trash   $trash   Trash.
	 */
	public function __construct( Scanner $scanner, Trash $trash ) {
		$this->scanner = $scanner;
		$this->trash   = $trash;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'deleted_notice' ) );
		add_action( 'admin_footer-plugins.php', array( $this, 'deactivation_dialog' ) );
	}

	/**
	 * Remembers a deleted plugin for the current user's next screen.
	 *
	 * @param string $slug Plugin slug.
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
	 * Messages for plugins the current user deleted, and forgets them.
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
			/* translators: 1: plugin name, 2: list such as "2 tables, 12 options and 3 cron events". */
			$message = sprintf( __( '%1$s left %2$s.', 'axle-leftovers' ), $group['owner_name'], Display::count_list( $group['counts'] ) );

			/**
			 * Filters the notice shown after a plugin is deleted.
			 *
			 * @param string $message Plain text, such as "Contact Form 7 left 2 tables and 12 options." Return '' to show nothing.
			 * @param string $slug    The deleted plugin's slug.
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
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'plugins-network' ), true ) || ! current_user_can( Settings::capability() ) ) {
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
		echo '<dialog class="alo-modal" id="alo-deactivate" aria-labelledby="alo-deactivate-title" data-plugin="' . esc_attr( plugin_basename( PLUGIN_FILE ) ) . '">';
		echo '<h2 id="alo-deactivate-title">' . esc_html__( 'The Leftovers trash is not empty', 'axle-leftovers' ) . '</h2>';
		/* translators: %d: number of items. */
		echo '<p>' . esc_html( sprintf( _n( 'The trash holds %d item. Deactivating keeps it, but uninstalling Leftovers deletes it for good. Restore it first if you may need it.', 'The trash holds %d items. Deactivating keeps them, but uninstalling Leftovers deletes them for good. Restore them first if you may need them.', $count, 'axle-leftovers' ), $count ) ) . '</p>';
		echo '<div class="alo-modal-actions">';
		foreach ( array(
			'restore_all' => __( 'Restore all', 'axle-leftovers' ),
			'empty'       => __( 'Empty trash', 'axle-leftovers' ),
		) as $do => $label ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-inline-form">';
			echo '<input type="hidden" name="action" value="' . esc_attr( Page::ACTION ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="redirect" value="' . esc_attr( $redirect ) . '">';
			wp_nonce_field( Page::NONCE );
			echo '<button type="submit" class="button' . ( 'restore_all' === $do ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form> ';
		}
		echo '<a class="button alo-deactivate-anyway" href="#">' . esc_html__( 'Deactivate anyway', 'axle-leftovers' ) . '</a> ';
		echo '<button type="button" class="button-link alo-confirm-cancel">' . esc_html__( 'Cancel', 'axle-leftovers' ) . '</button>';
		echo '</div></dialog>';
	}
}
