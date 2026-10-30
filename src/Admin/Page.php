<?php
/**
 * Tools > Leftovers, and Network Admin > Settings > Leftovers.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Admin;

use AryanX\Axle\Leftovers\Ledger;
use AryanX\Axle\Leftovers\Recorder;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Service;
use AryanX\Axle\Leftovers\Settings;
use AryanX\Axle\Leftovers\Trash;
use AryanX\Axle\Leftovers\Signature;
use const AryanX\Axle\Leftovers\PLUGIN_FILE;
use const AryanX\Axle\Leftovers\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The screen: By plugin, Unknown, Trash, Ledger and Settings tabs, the
 * form handlers behind them, and the export download.
 */
final class Page implements Service {

	public const SLUG   = 'axle-leftovers';
	public const ACTION = 'axle_leftovers';
	public const NONCE  = 'axle_leftovers';

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
	 * The ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * The recorder.
	 *
	 * @var Recorder
	 */
	private Recorder $recorder;

	/**
	 * Sets up the screen.
	 *
	 * @param Scanner  $scanner  Scanner.
	 * @param Trash    $trash    Trash.
	 * @param Ledger   $ledger   Ledger.
	 * @param Recorder $recorder Recorder.
	 */
	public function __construct( Scanner $scanner, Trash $trash, Ledger $ledger, Recorder $recorder ) {
		$this->scanner  = $scanner;
		$this->trash    = $trash;
		$this->ledger   = $ledger;
		$this->recorder = $recorder;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'network_admin_menu', array( $this, 'network_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_axle_leftovers_download', array( $this, 'download' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Tools > Leftovers.
	 */
	public function menu(): void {
		add_management_page( __( 'Leftovers', 'axle-leftovers' ), __( 'Leftovers', 'axle-leftovers' ), Settings::capability(), self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Network Admin > Settings > Leftovers, for network-wide items.
	 */
	public function network_menu(): void {
		add_submenu_page( 'settings.php', __( 'Leftovers', 'axle-leftovers' ), __( 'Leftovers', 'axle-leftovers' ), Settings::capability( true ), self::SLUG, array( $this, 'render_network' ) );
	}

	/**
	 * The screen's URL.
	 *
	 * @param string $tab     Tab.
	 * @param bool   $network Network Admin.
	 * @return string
	 */
	public static function url( string $tab = '', bool $network = false ): string {
		$base = $network ? network_admin_url( 'settings.php?page=' . self::SLUG ) : admin_url( 'tools.php?page=' . self::SLUG );

		return '' === $tab ? $base : add_query_arg( 'tab', $tab, $base );
	}

	/**
	 * Styles and scripts on the screen and on Plugins.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ): void {
		$ours = in_array( (string) $hook, array( 'tools_page_' . self::SLUG, 'settings_page_' . self::SLUG ), true );
		if ( ! $ours && ! in_array( (string) $hook, array( 'plugins.php', 'plugins-network.php' ), true ) ) {
			return;
		}

		$base = plugins_url( 'assets/', PLUGIN_FILE );
		wp_enqueue_style( 'axle-leftovers', $base . 'admin.css', array(), VERSION );
		wp_enqueue_script( 'axle-leftovers', $base . 'admin.js', array( 'wp-hooks', 'wp-api-fetch' ), VERSION, true );
		wp_localize_script(
			'axle-leftovers',
			'axleLeftoversData',
			array(
				'noticeRoute' => '/axle-leftovers/v1/notice',
				'i18n'        => array(
					/* translators: %d: number of items. */
					'confirm'     => __( 'Move %d items to the trash? Each one is exported first, and you can restore the batch from the Trash tab.', 'axle-leftovers' ),
					'confirmOne'  => __( 'Move 1 item to the trash? It is exported first, and you can restore it from the Trash tab.', 'axle-leftovers' ),
					'typeName'    => __( 'The owner of these items is not certain. Type each name to confirm:', 'axle-leftovers' ),
					'nothing'     => __( 'Pick at least one item.', 'axle-leftovers' ),
					'mismatch'    => __( 'The typed name does not match.', 'axle-leftovers' ),
					/* translators: %s: item name. */
					'typeLabel'   => __( 'Type %s', 'axle-leftovers' ),
					'deleteError' => __( 'Could not load the leftovers notice.', 'axle-leftovers' ),
				),
			)
		);
	}

	/**
	 * Renders the site screen.
	 */
	public function render(): void {
		$this->render_screen( false );
	}

	/**
	 * Renders the network screen, from the main site's data.
	 */
	public function render_network(): void {
		switch_to_blog( get_main_site_id() );
		$this->render_screen( true );
		restore_current_blog();
	}

	/**
	 * Renders a screen.
	 *
	 * @param bool $network Network-wide items.
	 */
	private function render_screen( bool $network ): void {
		if ( ! current_user_can( Settings::capability( $network ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage leftovers.', 'axle-leftovers' ), 403 );
		}

		$fresh = $this->scanner->ensure_fresh( 8.0 );
		$scope = $network ? 'network' : 'site';
		$tabs  = array(
			'plugins' => __( 'By plugin', 'axle-leftovers' ),
			'unknown' => __( 'Unknown', 'axle-leftovers' ),
			'trash'   => __( 'Trash', 'axle-leftovers' ),
		);
		if ( ! $network ) {
			$tabs['ledger']   = __( 'Ledger', 'axle-leftovers' );
			$tabs['settings'] = __( 'Settings', 'axle-leftovers' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'plugins';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'plugins';

		$groups  = $this->scanner->by_plugin( $scope );
		$unknown = $this->scanner->items(
			array(
				'plugin' => '',
				'scope'  => $scope,
			)
		);
		$batches = $this->trash->batches( $scope );
		$counts  = array(
			'plugins' => count( $groups ),
			'unknown' => count( $unknown ),
			'trash'   => array_sum(
				array_map(
					static function ( array $batch ): int {
						return count( $batch['items'] );
					},
					$batches
				)
			),
		);
		$state   = Scanner::state();

		echo '<div class="wrap alo">';
		echo '<h1>' . esc_html( $network ? __( 'Leftovers on the network', 'axle-leftovers' ) : __( 'Leftovers', 'axle-leftovers' ) ) . '</h1>';
		$this->flash();

		echo '<div class="alo-status">';
		echo '<p>';
		if ( ! empty( $state['finished_gmt'] ) ) {
			/* translators: %s: date and time. */
			echo esc_html( sprintf( __( 'Last scan finished %s.', 'axle-leftovers' ), Display::date( (string) $state['finished_gmt'] ) ) ) . ' ';
		}
		if ( ! $fresh ) {
			echo '<strong>' . esc_html__( 'A scan is running in the background. Reload in a minute for complete results.', 'axle-leftovers' ) . '</strong>';
		}
		echo '</p>';
		$this->action_form( 'scan', __( 'Scan again', 'axle-leftovers' ), $network );
		echo '</div>';

		echo '<nav class="nav-tab-wrapper alo-tabs" aria-label="' . esc_attr__( 'Leftovers sections', 'axle-leftovers' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$count = isset( $counts[ $key ] ) ? ' <span class="alo-count">' . esc_html( number_format_i18n( $counts[ $key ] ) ) . '</span>' : '';
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s%5$s</a>',
				esc_url( self::url( 'plugins' === $key ? '' : $key, $network ) ),
				$tab === $key ? ' nav-tab-active' : '',
				$tab === $key ? ' aria-current="page"' : '',
				esc_html( $label ),
				$count // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}
		echo '</nav>';

		echo '<div class="alo-panel alo-panel-' . esc_attr( $tab ) . '">';
		switch ( $tab ) {
			case 'unknown':
				$this->render_unknown( $unknown, $network );
				break;
			case 'trash':
				$this->render_trash( $batches, $network );
				break;
			case 'ledger':
				$this->render_ledger();
				break;
			case 'settings':
				$this->render_settings();
				break;
			default:
				$this->render_plugins( $groups, $network );
		}
		echo '</div>';

		$this->render_modal();
		echo '</div>';
	}

	/**
	 * The By plugin tab.
	 *
	 * @param array $groups  Groups from Scanner::by_plugin().
	 * @param bool  $network Network screen.
	 */
	private function render_plugins( array $groups, bool $network ): void {
		if ( ! $groups ) {
			echo '<p class="alo-empty">' . esc_html__( 'No leftovers from deleted plugins. Everything in the database belongs to WordPress or to an installed plugin or theme.', 'axle-leftovers' ) . '</p>';
			return;
		}

		$autoload = array_sum( array_column( $groups, 'autoload_bytes' ) );
		if ( $autoload > 0 ) {
			/* translators: %s: size, such as 340 KB. */
			echo '<p class="alo-cost">' . esc_html( sprintf( __( 'Leftovers add %s of autoloaded options to every page load.', 'axle-leftovers' ), Display::size( (int) $autoload ) ) ) . '</p>';
		}

		foreach ( $groups as $group ) {
			$id = 'alo-card-' . sanitize_html_class( $group['owner'] );
			echo '<section class="alo-card" aria-labelledby="' . esc_attr( $id ) . '" data-owner="' . esc_attr( $group['owner'] ) . '">';
			echo '<div class="alo-card-head">';
			echo '<h2 id="' . esc_attr( $id ) . '">' . esc_html( $group['owner_name'] ) . '</h2> ';
			echo Display::badge( $group['confidence'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			echo '</div>';
			echo '<p class="alo-meta">' . esc_html( Display::removed( $group['ledger'] ) ) . '</p>';
			echo '<p class="alo-summary">';
			/* translators: 1: list such as "2 tables and 3 options", 2: size. */
			echo esc_html( sprintf( __( '%1$s, %2$s in total.', 'axle-leftovers' ), Display::count_list( $group['counts'] ), Display::size( (int) $group['bytes'] ) ) );
			if ( $group['autoload_bytes'] > 0 ) {
				/* translators: %s: size. */
				echo ' <strong>' . esc_html( sprintf( __( 'They add %s to every page load.', 'axle-leftovers' ), Display::size( (int) $group['autoload_bytes'] ) ) ) . '</strong>';
			}
			echo '</p>';
			echo '<details class="alo-review"><summary>' . esc_html__( 'Review and move to trash', 'axle-leftovers' ) . '</summary>';
			$this->items_form( $group['items'], $network, $id );
			echo '</details>';
			echo '</section>';
		}
	}

	/**
	 * The Unknown tab.
	 *
	 * @param array $items   Items without an owner.
	 * @param bool  $network Network screen.
	 */
	private function render_unknown( array $items, bool $network ): void {
		echo '<p>' . esc_html__( 'Nothing points to an owner for these items. They may belong to code that builds names at runtime, to a plugin deleted long ago, or to something run by hand. To trash one you have to type its name.', 'axle-leftovers' ) . '</p>';
		if ( ! $items ) {
			echo '<p class="alo-empty">' . esc_html__( 'No unknown items.', 'axle-leftovers' ) . '</p>';
			return;
		}
		$this->items_form( $items, $network, 'alo-unknown' );
	}

	/**
	 * A list of items with checkboxes and the trash button.
	 *
	 * @param array  $items   Items.
	 * @param bool   $network Network screen.
	 * @param string $id      ID prefix.
	 */
	private function items_form( array $items, bool $network, string $id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-trash-form">';
		$this->hidden( 'trash', $network );
		echo '<input type="hidden" name="tab" value="' . esc_attr( 'alo-unknown' === $id ? 'unknown' : '' ) . '">';
		echo '<table class="widefat striped alo-items">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Items', 'axle-leftovers' ) . '</caption>';
		echo '<thead><tr>';
		echo '<td class="check-column"><input type="checkbox" class="alo-check-all" id="' . esc_attr( $id ) . '-all" aria-label="' . esc_attr__( 'Select all', 'axle-leftovers' ) . '"></td>';
		echo '<th scope="col">' . esc_html__( 'Item', 'axle-leftovers' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Rows', 'axle-leftovers' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Size', 'axle-leftovers' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Confidence and evidence', 'axle-leftovers' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Reference check', 'axle-leftovers' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $items as $index => $item ) {
			$box     = $id . '-' . $index;
			$typed   = Settings::require_typed_confirmation( $item['confidence'] );
			$blocked = ! empty( $item['refs'] );
			echo '<tr data-item="' . esc_attr( $item['id'] ) . '">';
			echo '<th scope="row" class="check-column">';
			printf(
				'<input type="checkbox" name="items[]" id="%1$s" value="%2$s" data-name="%3$s" data-typed="%4$s"%5$s>',
				esc_attr( $box ),
				esc_attr( $item['id'] ),
				esc_attr( $item['name'] ),
				$typed ? '1' : '0',
				$blocked ? ' disabled' : ''
			);
			echo '</th>';
			echo '<td><label for="' . esc_attr( $box ) . '"><code>' . esc_html( $item['name'] ) . '</code></label><br><span class="alo-type">' . esc_html( Display::type( $item['type'] ) ) . '</span>';
			if ( ! empty( $item['autoload'] ) ) {
				echo ' <span class="alo-autoload">' . esc_html__( 'Autoloaded', 'axle-leftovers' ) . '</span>';
			}
			if ( 'network' === $item['scope'] ) {
				echo ' <span class="alo-network">' . esc_html__( 'Network-wide', 'axle-leftovers' ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $item['rows'] ) ) . '</td>';
			echo '<td>' . esc_html( Display::size( (int) $item['bytes'] ) ) . '</td>';
			echo '<td>' . Display::badge( $item['confidence'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			if ( $item['evidence'] ) {
				echo '<ul class="alo-evidence">';
				foreach ( $item['evidence'] as $evidence ) {
					echo '<li>' . esc_html( $evidence['text'] ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</td><td>';
			if ( $blocked ) {
				/* translators: %s: file paths. */
				echo '<span class="alo-blocked">' . esc_html( sprintf( __( 'Used by %s. Cannot be trashed.', 'axle-leftovers' ), implode( ', ', $item['refs'] ) ) ) . '</span>';
			} else {
				echo esc_html__( 'Not used by active code', 'axle-leftovers' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<div class="alo-confirm-names" hidden></div>';
		echo '<p><button type="submit" class="button button-primary alo-trash-button">' . esc_html__( 'Move selected to trash', 'axle-leftovers' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * The Trash tab.
	 *
	 * @param array $batches Batches.
	 * @param bool  $network Network screen.
	 */
	private function render_trash( array $batches, bool $network ): void {
		/* translators: %d: days. */
		echo '<p>' . esc_html( sprintf( __( 'Trashed items are deleted for good after %d days. Tables are renamed, rows and folders are kept as they were, so a restore is exact.', 'axle-leftovers' ), Settings::retention_days() ) ) . '</p>';
		if ( ! $batches ) {
			echo '<p class="alo-empty">' . esc_html__( 'The trash is empty.', 'axle-leftovers' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-empty-form">';
		$this->hidden( 'empty', $network );
		echo '<p><button type="submit" class="button alo-empty-trash" data-alo-confirm="' . esc_attr__( 'Delete everything in the trash for good? This cannot be undone.', 'axle-leftovers' ) . '">' . esc_html__( 'Empty trash', 'axle-leftovers' ) . '</button></p>';
		echo '</form>';

		foreach ( $batches as $batch ) {
			$user    = get_userdata( (int) $batch['trashed_by'] );
			$heading = 'alo-batch-' . sanitize_html_class( $batch['batch'] );
			echo '<section class="alo-batch" aria-labelledby="' . esc_attr( $heading ) . '" data-batch="' . esc_attr( $batch['batch'] ) . '">';
			/* translators: %s: batch ID. */
			echo '<h2 id="' . esc_attr( $heading ) . '">' . esc_html( sprintf( __( 'Batch %s', 'axle-leftovers' ), $batch['batch'] ) ) . '</h2>';
			echo '<p class="alo-meta">' . esc_html(
				sprintf(
					/* translators: 1: date, 2: user name, 3: size. */
					__( 'Trashed %1$s by %2$s, %3$s.', 'axle-leftovers' ),
					Display::date( (string) $batch['trashed_gmt'] ),
					$user ? $user->display_name : __( 'WP-CLI or a deleted user', 'axle-leftovers' ),
					Display::size( (int) $batch['bytes'] )
				)
			) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$this->hidden( 'batch', $network );
			echo '<input type="hidden" name="batch" value="' . esc_attr( $batch['batch'] ) . '">';
			echo '<table class="widefat striped alo-trash-items"><caption class="screen-reader-text">' . esc_html__( 'Items in this batch', 'axle-leftovers' ) . '</caption><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Item', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Owner', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Size', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Export', 'axle-leftovers' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'axle-leftovers' ) . '</span></th>';
			echo '</tr></thead><tbody>';
			foreach ( $batch['items'] as $row ) {
				echo '<tr data-trash-id="' . esc_attr( (string) $row['id'] ) . '">';
				echo '<td><code>' . esc_html( $row['name'] ) . '</code><br><span class="alo-type">' . esc_html( Display::type( $row['item_type'] ) ) . '</span></td>';
				echo '<td>' . esc_html( '' !== $row['owner_slug'] ? $this->scanner->attribution()->owner_name( (string) $row['owner_slug'] ) : __( 'Unknown', 'axle-leftovers' ) ) . '</td>';
				echo '<td>' . esc_html( Display::size( (int) $row['size_bytes'] ) ) . '</td>';
				echo '<td>';
				if ( '' !== $this->trash->export_file( $row ) ) {
					$url = wp_nonce_url( add_query_arg( 'id', (int) $row['id'], admin_url( 'admin-post.php?action=axle_leftovers_download' ) ), 'axle_leftovers_download_' . $row['id'] );
					/* translators: %s: item name. */
					echo '<a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( sprintf( __( 'Download the export of %s', 'axle-leftovers' ), $row['name'] ) ) . '">' . esc_html__( 'Download', 'axle-leftovers' ) . '</a>';
				} elseif ( ! empty( $row['payload']['export_skipped'] ) ) {
					echo esc_html__( 'None: over the export size limit, renamed only', 'axle-leftovers' );
				} else {
					echo esc_html__( 'None', 'axle-leftovers' );
				}
				echo '</td>';
				/* translators: %s: item name. */
				echo '<td><button type="submit" class="button-link" name="item" value="' . esc_attr( (string) $row['id'] ) . '" aria-label="' . esc_attr( sprintf( __( 'Restore %s', 'axle-leftovers' ), $row['name'] ) ) . '">' . esc_html__( 'Restore', 'axle-leftovers' ) . '</button></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<p class="alo-batch-actions"><button type="submit" class="button button-primary" name="do_batch" value="restore">' . esc_html__( 'Restore batch', 'axle-leftovers' ) . '</button> ';
			echo '<button type="submit" class="button" name="do_batch" value="purge" data-alo-confirm="' . esc_attr__( 'Delete this batch for good? This cannot be undone.', 'axle-leftovers' ) . '">' . esc_html__( 'Purge batch', 'axle-leftovers' ) . '</button></p>';
			echo '</form>';
			echo '</section>';
		}
	}

	/**
	 * The Ledger tab.
	 */
	private function render_ledger(): void {
		$entries = $this->ledger->all();
		echo '<p>' . esc_html__( 'Every plugin Leftovers has seen, with the names its code uses. The signature is kept after a plugin is deleted, even over FTP, so its leftovers can still be found.', 'axle-leftovers' ) . '</p>';
		$this->action_form( 'rescan', __( 'Read all installed plugins again', 'axle-leftovers' ), false );
		if ( ! $entries ) {
			echo '<p class="alo-empty">' . esc_html__( 'No plugins recorded yet. The first scan adds every installed plugin.', 'axle-leftovers' ) . '</p>';
			return;
		}

		$labels = array(
			't' => __( 'Tables', 'axle-leftovers' ),
			'o' => __( 'Options', 'axle-leftovers' ),
			'c' => __( 'Cron', 'axle-leftovers' ),
			'm' => __( 'Meta', 'axle-leftovers' ),
			'r' => __( 'Roles and capabilities', 'axle-leftovers' ),
			'u' => __( 'Uploads', 'axle-leftovers' ),
			'p' => __( 'Prefixes', 'axle-leftovers' ),
		);
		echo '<table class="widefat striped alo-ledger"><caption class="screen-reader-text">' . esc_html__( 'Plugins seen', 'axle-leftovers' ) . '</caption><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Plugin', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Status', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'First seen', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Sources', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Signature', 'axle-leftovers' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$signature = is_array( $entry['signature'] ) ? $entry['signature'] : Signature::blank();
			echo '<tr data-slug="' . esc_attr( $entry['slug'] ) . '"><td><strong>' . esc_html( $entry['name'] ) . '</strong><br><code>' . esc_html( $entry['slug'] ) . '</code></td>';
			echo '<td>' . esc_html( Display::removed( $entry ) ) . '</td>';
			echo '<td>' . esc_html( Display::date( (string) $entry['first_seen_gmt'] ) ) . '</td>';
			echo '<td>' . esc_html( implode( ', ', $entry['sources'] ) ) . '</td><td>';
			$parts = array();
			foreach ( $labels as $key => $label ) {
				if ( ! empty( $signature[ $key ] ) ) {
					$parts[] = $label . ': ' . count( $signature[ $key ] );
				}
			}
			echo esc_html( $parts ? implode( ', ', $parts ) : __( 'Not read yet', 'axle-leftovers' ) );
			if ( null === $entry['removed_gmt'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-inline-form">';
				$this->hidden( 'rescan', false );
				echo '<input type="hidden" name="slug" value="' . esc_attr( $entry['slug'] ) . '">';
				/* translators: %s: plugin name. */
				echo '<button type="submit" class="button-link" aria-label="' . esc_attr( sprintf( __( 'Read %s again', 'axle-leftovers' ), $entry['name'] ) ) . '">' . esc_html__( 'Rescan', 'axle-leftovers' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The Settings tab.
	 */
	private function render_settings(): void {
		$settings = Settings::all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-settings">';
		$this->hidden( 'settings', false );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="alo-retention">' . esc_html__( 'Keep trashed items for', 'axle-leftovers' ) . '</label></th><td><input type="number" min="1" max="3650" id="alo-retention" name="retention_days" value="' . esc_attr( (string) $settings['retention_days'] ) . '" class="small-text" aria-describedby="alo-retention-help"> ' . esc_html__( 'days', 'axle-leftovers' ) . '<p class="description" id="alo-retention-help">' . esc_html__( 'The daily cron deletes them after this.', 'axle-leftovers' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="alo-export-max">' . esc_html__( 'Largest table to export', 'axle-leftovers' ) . '</label></th><td><input type="number" min="0" id="alo-export-max" name="export_max_mb" value="' . esc_attr( (string) $settings['export_max_mb'] ) . '" class="small-text" aria-describedby="alo-export-help"> MB<p class="description" id="alo-export-help">' . esc_html__( 'Bigger tables are only renamed, with a warning. The data stays in the database either way.', 'axle-leftovers' ) . '</p></td></tr>';
		$checks = array(
			'keep_exports'    => array( __( 'Exports', 'axle-leftovers' ), __( 'Keep export files when the trash is purged', 'axle-leftovers' ) ),
			'runtime_ledger'  => array( __( 'Runtime ledger', 'axle-leftovers' ), __( 'Record which plugin creates new tables, options, cron events, roles and capabilities', 'axle-leftovers' ) ),
			'track_meta_keys' => array( __( 'Meta keys', 'axle-leftovers' ), __( 'Also record new post, user, term and comment meta keys', 'axle-leftovers' ) ),
		);
		foreach ( $checks as $key => $labels ) {
			echo '<tr><th scope="row">' . esc_html( $labels[0] ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( $settings[ $key ], true, false ) . '> ' . esc_html( $labels[1] ) . '</label></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'axle-leftovers' ) );
		echo '</form>';
	}

	/**
	 * The confirmation modal the script fills in.
	 */
	private function render_modal(): void {
		echo '<dialog class="alo-modal" id="alo-confirm" aria-labelledby="alo-confirm-title" aria-describedby="alo-confirm-message">';
		echo '<h2 id="alo-confirm-title">' . esc_html__( 'Move to trash', 'axle-leftovers' ) . '</h2>';
		echo '<p id="alo-confirm-message"></p>';
		echo '<ul class="alo-confirm-list"></ul>';
		echo '<div class="alo-confirm-typed"></div>';
		echo '<p class="alo-confirm-error" role="alert"></p>';
		echo '<p class="alo-modal-actions"><button type="button" class="button button-primary alo-confirm-ok">' . esc_html__( 'Move to trash', 'axle-leftovers' ) . '</button> <button type="button" class="button alo-confirm-cancel">' . esc_html__( 'Cancel', 'axle-leftovers' ) . '</button></p>';
		echo '</dialog>';
	}

	/**
	 * A one-button form.
	 *
	 * @param string $action  Action.
	 * @param string $label   Button label.
	 * @param bool   $network Network screen.
	 */
	private function action_form( string $action, string $label, bool $network ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-inline-form alo-' . esc_attr( $action ) . '-form">';
		$this->hidden( $action, $network );
		echo '<button type="submit" class="button">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * The hidden fields every form carries.
	 *
	 * @param string $action  Action.
	 * @param bool   $network Network screen.
	 */
	private function hidden( string $action, bool $network ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $action ) . '">';
		if ( $network ) {
			echo '<input type="hidden" name="network" value="1">';
		}
		wp_nonce_field( self::NONCE );
	}

	/**
	 * Handles every form on the screen.
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked on the next lines before anything is used.
		$network = ! empty( $_POST['network'] ) && is_multisite();
		if ( ! current_user_can( Settings::capability( $network ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage leftovers.', 'axle-leftovers' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$tab = '';
		if ( $network ) {
			switch_to_blog( get_main_site_id() );
		}

		switch ( $do ) {
			case 'scan':
				$this->scanner->start();
				$this->scanner->step( 15.0 );
				$this->set_flash( 'success', __( 'Scan started. Results below are up to date unless the screen says the scan still runs.', 'axle-leftovers' ) );
				break;

			case 'trash':
				$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
				$items = array();
				$raw   = isset( $_POST['items'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['items'] ) ) : array();
				foreach ( $raw as $id ) {
					$parts = explode( ':', (string) $id, 2 );
					if ( 2 === count( $parts ) ) {
						$items[] = array(
							'type' => $parts[0],
							'name' => $parts[1],
						);
					}
				}
				$confirm = isset( $_POST['confirm_names'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['confirm_names'] ) ) : array();
				$result  = $this->trash->trash( $items, array( 'confirm_names' => $confirm ) );
				if ( is_wp_error( $result ) ) {
					$this->set_flash( 'error', $result->get_error_message() );
				} else {
					/* translators: 1: number of items, 2: batch ID. */
					$this->set_flash( 'success', sprintf( _n( 'Moved %1$d item to the trash as batch %2$s.', 'Moved %1$d items to the trash as batch %2$s.', count( $result['items'] ), 'axle-leftovers' ), count( $result['items'] ), $result['batch'] ), 'trashed', $result );
				}
				break;

			case 'batch':
				$tab   = 'trash';
				$batch = isset( $_POST['batch'] ) ? sanitize_text_field( wp_unslash( $_POST['batch'] ) ) : '';
				$item  = isset( $_POST['item'] ) ? absint( $_POST['item'] ) : 0;
				$verb  = isset( $_POST['do_batch'] ) ? sanitize_key( wp_unslash( $_POST['do_batch'] ) ) : 'restore';
				if ( $item > 0 ) {
					$this->restore_flash( $this->trash->restore( '', array( $item ) ) );
				} elseif ( 'purge' === $verb ) {
					$result = $this->trash->purge( array( 'batch' => $batch ) );
					if ( is_wp_error( $result ) ) {
						$this->set_flash( 'error', $result->get_error_message() );
					} else {
						/* translators: %d: number of items. */
						$this->set_flash( 'success', sprintf( _n( 'Deleted %d item for good.', 'Deleted %d items for good.', count( $result ), 'axle-leftovers' ), count( $result ) ) );
					}
				} else {
					$this->restore_flash( $this->trash->restore( $batch ) );
				}
				break;

			case 'empty':
				$tab    = 'trash';
				$result = $this->trash->purge();
				if ( is_wp_error( $result ) ) {
					$this->set_flash( 'error', $result->get_error_message() );
				} else {
					/* translators: %d: number of items. */
					$this->set_flash( 'success', sprintf( _n( 'Deleted %d item for good.', 'Deleted %d items for good.', count( $result ), 'axle-leftovers' ), count( $result ) ) );
				}
				break;

			case 'restore_all':
				$tab    = 'trash';
				$result = array(
					'restored' => array(),
					'failed'   => array(),
				);
				foreach ( array_keys( $this->trash->batches( 'site' ) + $this->trash->batches( 'network' ) ) as $batch ) {
					$one = $this->trash->restore( (string) $batch );
					if ( is_wp_error( $one ) ) {
						$result = $one;
						break;
					}
					$result['restored'] = array_merge( $result['restored'], $one['restored'] );
					$result['failed']   = array_merge( $result['failed'], $one['failed'] );
				}
				$this->restore_flash( $result );
				break;

			case 'rescan':
				$tab  = 'ledger';
				$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
				if ( '' === $slug ) {
					$this->scanner->start( true );
					$this->scanner->step( 15.0 );
					$this->set_flash( 'success', __( 'Reading every installed plugin again.', 'axle-leftovers' ) );
				} else {
					$entry = $this->ledger->get( $slug );
					if ( $entry && null === $entry['removed_gmt'] ) {
						$this->recorder->ensure_signature( (string) $entry['plugin_file'], true );
						Scanner::mark_stale();
						/* translators: %s: plugin name. */
						$this->set_flash( 'success', sprintf( __( 'Read %s again.', 'axle-leftovers' ), $entry['name'] ) );
					}
				}
				break;

			case 'settings':
				$tab = 'settings';
				Settings::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Settings::sanitize() casts and clamps every field it keeps.
				$this->set_flash( 'success', __( 'Settings saved.', 'axle-leftovers' ) );
				break;
		}

		if ( $network ) {
			restore_current_blog();
		}

		$back = isset( $_POST['redirect'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['redirect'] ) ), '' ) : '';
		wp_safe_redirect( '' !== $back ? $back : self::url( $tab, $network ) );
		exit;
	}

	/**
	 * Streams an export file to the capability.
	 */
	public function download(): void {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'axle_leftovers_download_' . $id );
		$row = $this->trash->rows_by_ids( array( $id ) )[0] ?? null;
		if ( ! $row || ! current_user_can( Settings::capability( 'network' === $row['scope'] ) ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to download this export.', 'axle-leftovers' ), 403 );
		}

		$file = $this->trash->export_file( $row );
		if ( '' === $file ) {
			wp_die( esc_html__( 'This export no longer exists.', 'axle-leftovers' ), 404 );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( '.sql' === substr( $file, -4 ) ? 'application/sql' : 'application/json' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a possibly large export without loading it in memory.
		readfile( $file );
		exit;
	}

	/**
	 * Flash message for a restore.
	 *
	 * @param array|\WP_Error $result Result.
	 */
	private function restore_flash( $result ): void {
		if ( is_wp_error( $result ) ) {
			$this->set_flash( 'error', $result->get_error_message() );
			return;
		}

		/* translators: %d: number of items. */
		$text = sprintf( _n( 'Restored %d item.', 'Restored %d items.', count( $result['restored'] ), 'axle-leftovers' ), count( $result['restored'] ) );
		foreach ( $result['failed'] as $failed ) {
			$text .= ' ' . $failed['message'];
		}
		$this->set_flash( $result['failed'] ? 'warning' : 'success', $text, 'restored', $result );
	}

	/**
	 * Stores a message for the next screen.
	 *
	 * @param string $type    success, warning or error.
	 * @param string $text    Message.
	 * @param string $event   JS event to fire: trashed or restored.
	 * @param array  $payload Data for the event.
	 */
	private function set_flash( string $type, string $text, string $event = '', array $payload = array() ): void {
		set_transient(
			'axle_leftovers_flash_' . get_current_user_id(),
			array(
				'type'    => $type,
				'text'    => $text,
				'event'   => $event,
				'payload' => $payload,
			),
			MINUTE_IN_SECONDS * 5
		);
	}

	/**
	 * Prints and clears the stored message.
	 */
	private function flash(): void {
		$key   = 'axle_leftovers_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( ! is_array( $flash ) ) {
			return;
		}
		delete_transient( $key );

		$class = in_array( $flash['type'], array( 'success', 'warning', 'error' ), true ) ? $flash['type'] : 'info';
		printf(
			'<div class="notice notice-%1$s alo-notice"%2$s role="%3$s"><p>%4$s</p></div>',
			esc_attr( $class ),
			'' !== $flash['event'] ? ' data-alo-event="' . esc_attr( $flash['event'] ) . '" data-alo-payload="' . esc_attr( (string) wp_json_encode( $flash['payload'] ) ) . '"' : '',
			'error' === $class ? 'alert' : 'status',
			esc_html( $flash['text'] )
		);
	}
}
