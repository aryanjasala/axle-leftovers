<?php
/**
 * Tools > Leftovers, and Network Admin > Settings > Leftovers.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Admin;

use AryanX\Axle\Leftovers\Content;
use AryanX\Axle\Leftovers\Dataset;
use AryanX\Axle\Leftovers\DatasetUpdater;
use AryanX\Axle\Leftovers\Inventory;
use AryanX\Axle\Leftovers\Ledger;
use AryanX\Axle\Leftovers\Lookup;
use AryanX\Axle\Leftovers\Messages;
use AryanX\Axle\Leftovers\Observer;
use AryanX\Axle\Leftovers\Recorder;
use AryanX\Axle\Leftovers\Reinstall;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Service;
use AryanX\Axle\Leftovers\Settings;
use AryanX\Axle\Leftovers\Trash;
use AryanX\Axle\Leftovers\Signature;
use AryanX\Axle\Leftovers\Themes;
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

	public const STEPS_META = 'axle_leftovers_steps_dismissed';

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
	 * The online lookup.
	 *
	 * @var Lookup
	 */
	private Lookup $lookup;

	/**
	 * The full signature list.
	 *
	 * @var DatasetUpdater
	 */
	private DatasetUpdater $updater;

	/**
	 * The reinstall restore.
	 *
	 * @var Reinstall
	 */
	private Reinstall $reinstall;

	/**
	 * Sets up the screen.
	 *
	 * @param Scanner        $scanner   Scanner.
	 * @param Trash          $trash     Trash.
	 * @param Ledger         $ledger    Ledger.
	 * @param Recorder       $recorder  Recorder.
	 * @param Reinstall|null $reinstall Reinstall restore. Default: a new one.
	 */
	public function __construct( Scanner $scanner, Trash $trash, Ledger $ledger, Recorder $recorder, ?Reinstall $reinstall = null ) {
		$this->scanner   = $scanner;
		$this->trash     = $trash;
		$this->ledger    = $ledger;
		$this->recorder  = $recorder;
		$this->reinstall = $reinstall ?? new Reinstall( $trash, new Inventory() );
		$this->lookup    = new Lookup();
		$this->updater   = new DatasetUpdater();
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
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'network_action_links' ) );
	}

	/**
	 * Tools > Leftovers.
	 */
	public function menu(): void {
		$hook = add_management_page( __( 'Leftovers', 'axle-leftovers' ), __( 'Leftovers', 'axle-leftovers' ), Settings::capability(), self::SLUG, array( $this, 'render' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'help' ) );
		}
	}

	/**
	 * Network Admin > Settings > Leftovers, for network-wide items.
	 */
	public function network_menu(): void {
		$hook = add_submenu_page( 'settings.php', __( 'Leftovers', 'axle-leftovers' ), __( 'Leftovers', 'axle-leftovers' ), Settings::capability( true ), self::SLUG, array( $this, 'render_network' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'help' ) );
		}
	}

	/**
	 * A link to the screen in the plugin's row on the Plugins screen.
	 *
	 * @param mixed $links Row links.
	 * @return array
	 */
	public function action_links( $links ): array {
		return $this->with_screen_link( (array) $links, self::url(), false );
	}

	/**
	 * The same link on the network Plugins screen.
	 *
	 * @param mixed $links Row links.
	 * @return array
	 */
	public function network_action_links( $links ): array {
		return $this->with_screen_link( (array) $links, self::url( '', true ), true );
	}

	/**
	 * Puts a Leftovers link first in a plugin row, when the user may open the screen.
	 *
	 * @param array  $links   Row links.
	 * @param string $url     Screen URL.
	 * @param bool   $network Network Admin.
	 * @return array
	 */
	private function with_screen_link( array $links, string $url, bool $network ): array {
		if ( ! current_user_can( Settings::capability( $network ) ) ) {
			return $links;
		}

		return array_merge( array( 'leftovers' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Leftovers', 'axle-leftovers' ) . '</a>' ), $links );
	}

	/**
	 * The Help tab: an Overview, then one tab for each job the screen does.
	 */
	public function help(): void {
		AxleUi::help_tabs(
			array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-leftovers' ),
					'content' => '<p>' . esc_html__( 'Deleting a plugin or a theme does not always clean up after it. Leftovers lists the tables, options, cron events, meta, roles, uploads folders, theme settings and the posts and terms of removed post types that nothing installed owns any more, and says who they belonged to and how sure it is.', 'axle-leftovers' ) . '</p><p>' . esc_html__( 'Nothing is deleted on the first step. Items go to a trash and come back with one click.', 'axle-leftovers' ) . '</p>',
				),
				array(
					'id'      => 'evidence',
					'title'   => __( 'Owners and evidence', 'axle-leftovers' ),
					'content' => '<p>' . esc_html__( 'Certain means Leftovers saw the plugin or theme create the item, or saw it being deleted. Likely means the name is in its code. Guess means the name only starts like a known plugin. Unknown means nothing points anywhere.', 'axle-leftovers' ) . '</p><p>' . esc_html__( 'Each item lists its evidence. Anything an installed plugin, theme or must-use plugin may own is never listed, and a name that active code still uses cannot be trashed.', 'axle-leftovers' ) . '</p>',
				),
				array(
					'id'      => 'trash',
					'title'   => __( 'Trash and restore', 'axle-leftovers' ),
					'content' => '<p>' . esc_html__( 'Moving items to the trash exports each one first. Tables are renamed, options and posts are copied out, and folders are moved. Guess and Unknown items need their names typed.', 'axle-leftovers' ) . '</p><p>' . esc_html__( 'On the Trash tab, restore a whole batch or one item. Items are deleted for good after the retention period, or when you empty the trash.', 'axle-leftovers' ) . '</p>',
				),
				array(
					'id'      => 'reinstall',
					'title'   => __( 'A plugin comes back', 'axle-leftovers' ),
					'content' => '<p>' . esc_html__( 'When you install or activate a plugin whose tables and options are in the trash, Leftovers stops the activation and shows what is there. Activating first would create empty tables and options under the same names, and a restore is refused after that.', 'axle-leftovers' ) . '</p><p>' . esc_html__( 'Pick what comes back. If the plugin already wrote its own settings, you see both versions and choose per item. Whatever is replaced goes into the trash first, and every restore has an Undo.', 'axle-leftovers' ) . '</p>',
				),
				array(
					'id'      => 'themes',
					'title'   => __( 'Themes', 'axle-leftovers' ),
					'content' => '<p>' . esc_html__( 'A deleted theme leaves its Customizer settings, its options, and on block themes the templates, template parts, global styles and custom CSS you saved. Leftovers lists them under the theme name.', 'axle-leftovers' ) . '</p><p>' . esc_html__( 'Anything of the active theme, its parent or any installed theme is never listed. Widgets that a theme switch left inactive are only reported.', 'axle-leftovers' ) . '</p>',
				),
				array(
					'id'      => 'content',
					'title'   => __( 'Posts and terms', 'axle-leftovers' ),
					'content' => '<p>' . esc_html__( 'A plugin that registered its own post type or taxonomy and was deleted can leave thousands of posts and terms in the database, invisible in wp-admin. Leftovers lists them once no code has registered the type for the watch period, 7 days by default.', 'axle-leftovers' ) . '</p><p>' . esc_html__( 'Some types register only in wp-admin, in REST or on some pages, so Leftovers watches several kinds of request before it calls a type unregistered. Open What is in it to see counts per status and example titles. Moving posts or terms to the trash always needs the name typed. They come back with the same IDs, meta, comments and term links.', 'axle-leftovers' ) . '</p>',
				),
				array(
					'id'      => 'settings',
					'title'   => __( 'Settings and lookups', 'axle-leftovers' ),
					'content' => '<p>' . esc_html__( 'Settings control how long the trash keeps items and what gets recorded. The online lookup and the full signature list are off until you turn them on. They only ever send an item name or fetch a public list.', 'axle-leftovers' ) . '</p>',
				),
			)
		);
	}

	/**
	 * Whether the form being handled came from the network screen, for the flash message.
	 *
	 * @var bool
	 */
	private bool $network = false;

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
	 * Styles and scripts on the screen, and on the Plugins and Themes screens.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ): void {
		$ours = in_array( (string) $hook, array( 'tools_page_' . self::SLUG, 'settings_page_' . self::SLUG, 'settings_page_' . self::SLUG . '-network' ), true );
		if ( ! $ours && ! in_array( (string) $hook, array( 'plugins.php', 'plugins-network.php', 'themes.php', 'themes-network.php' ), true ) ) {
			return;
		}

		$base = plugins_url( 'assets/', PLUGIN_FILE );
		if ( $ours || 'plugins.php' === $hook ) {
			// The Plugins screen shows the in-place warning before deactivating Leftovers.
			AxleUi::enqueue();
		}
		wp_enqueue_style( 'axle-leftovers', $base . 'admin.css', $ours ? array( AxleUi::HANDLE ) : array(), VERSION );
		wp_enqueue_script( 'axle-leftovers', $base . 'admin.js', array( 'wp-hooks', 'wp-api-fetch', 'wp-a11y' ), VERSION, true );
		wp_localize_script(
			'axle-leftovers',
			'axleLeftoversData',
			array(
				'noticeRoute' => '/axle-leftovers/v1/notice',
				'i18n'        => array(
					/* translators: %d: number of items. */
					'confirm'     => __( 'Move %d items to the trash? Each one is exported first, and you can restore the batch from the Trash tab.', 'axle-leftovers' ),
					'confirmOne'  => __( 'Move 1 item to the trash? It is exported first, and you can restore it from the Trash tab.', 'axle-leftovers' ),
					'trashTitle'  => __( 'Move to the trash?', 'axle-leftovers' ),
					'trashOk'     => __( 'Move to trash', 'axle-leftovers' ),
					'deleteTitle' => __( 'Delete for good?', 'axle-leftovers' ),
					'typeName'    => __( 'Type each name to confirm. This is needed when the owner is not certain, and always for posts and terms:', 'axle-leftovers' ),
					'nothing'     => __( 'Pick at least one item.', 'axle-leftovers' ),
					'mismatch'    => __( 'The typed name does not match. Check it and try again.', 'axle-leftovers' ),
					/* translators: %s: item name. */
					'typeLabel'   => __( 'Type %s', 'axle-leftovers' ),
					'working'     => __( 'Working. This can take a moment.', 'axle-leftovers' ),
					'deleteError' => __( 'Could not check what the deleted plugin or theme left behind. Open Tools > Leftovers to review it.', 'axle-leftovers' ),
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
			'plugins' => __( 'By owner', 'axle-leftovers' ),
			'trash'   => __( 'Trash', 'axle-leftovers' ),
		);
		if ( ! $network ) {
			$tabs['settings'] = __( 'Settings', 'axle-leftovers' );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'plugins';
		// The Unknown tab became the last group of By owner, and the Ledger is a view of Settings.
		$tab = 'unknown' === $tab ? 'plugins' : $tab;
		$nav = 'ledger' === $tab && ! $network ? 'settings' : $tab;
		$tab = isset( $tabs[ $nav ] ) ? $tab : 'plugins';
		$nav = isset( $tabs[ $nav ] ) ? $nav : 'plugins';

		$groups  = $this->scanner->by_plugin( $scope );
		$unknown = $this->scanner->items(
			array(
				'plugin' => '',
				'scope'  => $scope,
			)
		);
		$batches = $this->trash->batches( $scope );
		$counts  = array(
			'plugins' => count( $groups ) + ( $unknown ? 1 : 0 ),
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		$reinstall = ! $network && isset( $_GET['reinstall'] ) ? sanitize_text_field( wp_unslash( $_GET['reinstall'] ) ) : '';

		echo '<div class="wrap axle-ui alo">';
		echo '<h1>' . esc_html( $network ? __( 'Leftovers on the network', 'axle-leftovers' ) : __( 'Leftovers', 'axle-leftovers' ) ) . '</h1>';
		$review = count( $groups ) + count( $unknown );
		if ( $review > 0 ) {
			/* translators: %s: number of things to review. */
			echo '<span class="axle-status"><span class="axle-badge axle-badge--warning">' . esc_html( sprintf( _n( '%s to review', '%s to review', $review, 'axle-leftovers' ), number_format_i18n( $review ) ) ) . '</span></span>';
		} else {
			echo '<span class="axle-status"><span class="axle-badge axle-badge--good">' . esc_html__( 'Nothing left over', 'axle-leftovers' ) . '</span></span>';
		}
		echo '<hr class="wp-header-end">';
		$this->flash();

		if ( '' !== $reinstall ) {
			$this->render_reinstall( $reinstall );
			$this->render_confirm();
			AxleUi::footer();
			echo '</div>';
			return;
		}

		if ( ! $network && 'plugins' === $tab ) {
			$this->render_reinstall_cards();
			$this->render_steps( $state );
			$this->render_watching( $state );
		}

		echo '<div class="axle-toolbar alo-status">';
		if ( ! empty( $state['finished_gmt'] ) ) {
			echo '<span class="axle-muted">' . esc_html__( 'Last scan finished:', 'axle-leftovers' ) . ' ' . Display::time( (string) $state['finished_gmt'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
		}
		if ( ! $fresh ) {
			echo '<strong>' . esc_html__( 'A scan is running in the background. Reload in a minute for complete results.', 'axle-leftovers' ) . '</strong>';
		}
		echo '<span class="axle-toolbar__end">';
		$this->action_form( 'scan', __( 'Scan again', 'axle-leftovers' ), $network );
		echo '</span></div>';

		echo '<nav class="nav-tab-wrapper alo-tabs" aria-label="' . esc_attr__( 'Leftovers sections', 'axle-leftovers' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$count = isset( $counts[ $key ] ) ? ' <span class="axle-badge alo-count">' . esc_html( number_format_i18n( $counts[ $key ] ) ) . '</span>' : '';
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s%5$s</a>',
				esc_url( self::url( 'plugins' === $key ? '' : $key, $network ) ),
				$nav === $key ? ' nav-tab-active' : '',
				$nav === $key ? ' aria-current="page"' : '',
				esc_html( $label ),
				$count // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}
		echo '</nav>';

		echo '<div class="alo-panel alo-panel-' . esc_attr( $tab ) . '">';
		switch ( $tab ) {
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
				$this->render_plugins( $groups, $unknown, $network );
		}
		echo '</div>';

		$this->render_confirm();
		AxleUi::footer();
		echo '</div>';
	}

	/**
	 * The Get started card: three steps, each done state read from real data,
	 * hidden once every step is done or the user dismisses it.
	 *
	 * @param array $state Scan state.
	 */
	private function render_steps( array $state ): void {
		if ( get_user_meta( get_current_user_id(), self::STEPS_META, true ) ) {
			return;
		}

		$removed = false;
		foreach ( $this->ledger->all() as $entry ) {
			if ( null !== $entry['removed_gmt'] ) {
				$removed = true;
				break;
			}
		}
		$steps = array(
			array(
				'title' => __( 'Scan the site', 'axle-leftovers' ),
				'text'  => __( 'Leftovers reads the code of every installed plugin and theme once, so it can tell who owns what after they are deleted.', 'axle-leftovers' ),
				'done'  => ! empty( $state['finished_gmt'] ),
				'form'  => array( 'scan', __( 'Scan now', 'axle-leftovers' ) ),
			),
			array(
				'title' => __( 'Delete a plugin or theme you do not use', 'axle-leftovers' ),
				'text'  => __( 'Leftovers records every deletion, and the notice afterwards says what the plugin or theme left behind.', 'axle-leftovers' ),
				'done'  => $removed,
				'link'  => array( admin_url( 'plugins.php' ), __( 'Open Plugins', 'axle-leftovers' ) ),
			),
			array(
				'title' => __( 'Review what was left and move it to the trash', 'axle-leftovers' ),
				'text'  => __( 'Each item shows its evidence. Moving it to the trash exports it first, and you can restore it from the Trash tab.', 'axle-leftovers' ),
				'done'  => array() !== $this->trash->rows( array(), 1 ),
				'link'  => array( self::url(), __( 'Review leftovers', 'axle-leftovers' ) ),
			),
		);
		if ( ! in_array( false, array_column( $steps, 'done' ), true ) ) {
			return;
		}

		echo '<section class="axle-card alo-steps" aria-labelledby="alo-steps-title">';
		echo '<h2 class="axle-card__title" id="alo-steps-title">' . esc_html__( 'Get started', 'axle-leftovers' ) . '</h2>';
		echo '<ol class="axle-steps">';
		foreach ( $steps as $step ) {
			echo '<li class="axle-step"><div class="axle-step__body"><strong class="axle-step__title">' . esc_html( $step['title'] ) . '</strong><p>' . esc_html( $step['text'] ) . '</p></div>';
			if ( $step['done'] ) {
				echo '<span class="axle-badge axle-badge--good">' . esc_html__( 'Done', 'axle-leftovers' ) . '</span>';
			} elseif ( isset( $step['form'] ) ) {
				$this->action_form( $step['form'][0], $step['form'][1], false );
			} else {
				echo '<a class="button" href="' . esc_url( $step['link'][0] ) . '">' . esc_html( $step['link'][1] ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ol>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-inline-form alo-dismiss-form">';
		$this->hidden( 'dismiss_steps', false );
		echo '<button type="submit" class="button-link">' . esc_html__( 'Hide this card', 'axle-leftovers' ) . '</button>';
		echo '</form></section>';
	}

	/**
	 * The By owner tab: one card for each deleted plugin or theme, and Unknown as the last card.
	 *
	 * @param array $groups  Groups from Scanner::by_plugin().
	 * @param array $unknown Items without an owner.
	 * @param bool  $network Network screen.
	 */
	private function render_plugins( array $groups, array $unknown, bool $network ): void {
		echo '<p class="axle-intro">' . esc_html__( 'Deleted plugins and themes often leave tables, options, templates and cron events behind. See what each one left and move it to the trash, which you can undo.', 'axle-leftovers' ) . '</p>';
		if ( ! $network ) {
			$this->render_inactive_widgets();
		}
		if ( ! $groups && ! $unknown ) {
			echo '<div class="axle-empty alo-empty"><p>' . esc_html__( 'No leftovers from deleted plugins or themes. When you delete one, what it left behind shows up here, with the evidence for each item. Everything in the database now belongs to WordPress or to an installed plugin or theme.', 'axle-leftovers' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Open Plugins', 'axle-leftovers' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'themes.php' ) ) . '">' . esc_html__( 'Open Themes', 'axle-leftovers' ) . '</a></p></div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$kind  = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$kind  = in_array( $kind, array( 'plugin', 'theme' ), true ) ? $kind : '';
		$kinds = array(
			'plugin' => 0,
			'theme'  => 0,
		);
		foreach ( $groups as $group ) {
			if ( isset( $kinds[ $group['kind'] ] ) ) {
				++$kinds[ $group['kind'] ];
			}
		}
		if ( $kinds['plugin'] && $kinds['theme'] ) {
			$links = array(
				''       => sprintf(
					/* translators: %s: number of owners. */
					__( 'All %s', 'axle-leftovers' ),
					'<span class="count">(' . esc_html( number_format_i18n( count( $groups ) ) ) . ')</span>'
				),
				'plugin' => sprintf(
					/* translators: %s: number of plugins. */
					__( 'Plugins %s', 'axle-leftovers' ),
					'<span class="count">(' . esc_html( number_format_i18n( $kinds['plugin'] ) ) . ')</span>'
				),
				'theme'  => sprintf(
					/* translators: %s: number of themes. */
					__( 'Themes %s', 'axle-leftovers' ),
					'<span class="count">(' . esc_html( number_format_i18n( $kinds['theme'] ) ) . ')</span>'
				),
			);
			echo '<div class="alo-views"><ul class="subsubsub alo-kinds">';
			$last = array_key_last( $links );
			foreach ( $links as $key => $label ) {
				$url = '' === $key ? self::url( '', $network ) : add_query_arg( 'kind', $key, self::url( '', $network ) );
				printf(
					'<li><a href="%1$s"%2$s%3$s>%4$s</a>%5$s</li>',
					esc_url( $url ),
					$kind === $key ? ' class="current"' : '',
					$kind === $key ? ' aria-current="page"' : '',
					$label, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above; the translated word is escaped by the translation.
					$key === $last ? '' : ' |'
				);
			}
			echo '</ul></div>';
			if ( '' !== $kind ) {
				$groups = array_filter(
					$groups,
					static function ( array $group ) use ( $kind ): bool {
						return $group['kind'] === $kind;
					}
				);
			}
		}

		$autoload = array_sum( array_column( $groups, 'autoload_bytes' ) );
		if ( $groups ) {
			echo '<div class="axle-stats">';
			echo '<div class="axle-stat axle-stat--warning"><span class="axle-stat__value">' . esc_html( number_format_i18n( count( $groups ) ) ) . '</span><span class="axle-stat__label">' . esc_html__( 'deleted plugins and themes left data', 'axle-leftovers' ) . '</span></div>';
			if ( $autoload > 0 ) {
				echo '<div class="axle-stat axle-stat--warning alo-cost"><span class="axle-stat__value">' . esc_html( Display::size( (int) $autoload ) ) . '</span><span class="axle-stat__label">' . esc_html__( 'autoloaded on every page load', 'axle-leftovers' ) . '</span></div>';
			}
			echo '</div>';
		}

		foreach ( $groups as $group ) {
			$id     = 'alo-card-' . sanitize_html_class( $group['owner'] );
			$common = self::common_evidence( $group['items'] );
			echo '<section class="axle-card alo-card" aria-labelledby="' . esc_attr( $id ) . '" data-owner="' . esc_attr( $group['owner'] ) . '" data-kind="' . esc_attr( $group['kind'] ) . '">';
			echo '<div class="axle-card__head alo-card-head">';
			echo '<h2 class="axle-card__title" id="' . esc_attr( $id ) . '">' . esc_html( $group['owner_name'] ) . '</h2> ';
			echo '<span class="axle-badge axle-badge--neutral alo-kind">' . esc_html( 'theme' === $group['kind'] ? __( 'Theme', 'axle-leftovers' ) : __( 'Plugin', 'axle-leftovers' ) ) . '</span> ';
			echo Display::badge( $group['confidence'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			echo '</div>';
			echo '<p class="axle-muted alo-meta">' . Display::removed( $group['ledger'], $group['kind'] ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			echo '<p class="alo-summary">';
			/* translators: 1: list such as "2 tables and 3 options", 2: size. */
			echo esc_html( sprintf( __( '%1$s, %2$s in total.', 'axle-leftovers' ), Display::count_list( $group['counts'] ), Display::size( (int) $group['bytes'] ) ) );
			if ( $group['autoload_bytes'] > 0 ) {
				/* translators: %s: size. */
				echo ' <strong>' . esc_html( sprintf( __( 'They add %s to every page load.', 'axle-leftovers' ), Display::size( (int) $group['autoload_bytes'] ) ) ) . '</strong>';
			}
			echo '</p>';
			if ( $common ) {
				echo '<ul class="alo-evidence alo-evidence--card">' . Display::evidence( $common ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			}
			echo '<details class="alo-review"><summary>' . esc_html__( 'Review and move to trash', 'axle-leftovers' ) . '</summary>';
			$this->items_form( $group['items'], $network, $id, $common, (string) $group['confidence'] );
			echo '</details>';
			echo '</section>';
		}

		if ( $unknown ) {
			$this->render_unknown_card( $unknown, $network );
		}
	}

	/**
	 * By owner, top: plugins that are installed again with data in the trash, and restores that can be undone.
	 */
	private function render_reinstall_cards(): void {
		$pending = $this->reinstall->pending();
		$offers  = array_filter(
			$pending,
			static function ( array $entry ): bool {
				return in_array( $entry['decision'], array( '', 'skipped' ), true );
			}
		);
		$handled = array_filter(
			$pending,
			static function ( array $entry ): bool {
				return 'handled' === $entry['decision'];
			}
		);
		foreach ( $offers as $entry ) {
			$id = 'alo-reinstall-' . sanitize_html_class( $entry['slug'] );
			echo '<section class="axle-card alo-reinstall-card" aria-labelledby="' . esc_attr( $id ) . '" data-plugin="' . esc_attr( $entry['slug'] ) . '">';
			/* translators: %s: plugin name. */
			echo '<h2 class="axle-card__title" id="' . esc_attr( $id ) . '">' . esc_html( sprintf( __( '%s is installed again', 'axle-leftovers' ), $entry['name'] ) ) . '</h2>';
			/* translators: 1: plugin name, 2: list such as "2 tables and 3 options". */
			echo '<p>' . esc_html( sprintf( __( 'The trash holds what %1$s left before: %2$s. Look at it before the plugin starts from scratch, and bring back what you want.', 'axle-leftovers' ), $entry['name'], Display::count_list( $entry['counts'] ) ) ) . '</p>';
			echo '<div class="axle-actions"><a class="button button-primary alo-reinstall-open" href="' . esc_url( Reinstall::screen_url( $entry['slug'] ) ) . '">' . esc_html__( 'Preview and restore', 'axle-leftovers' ) . '</a> ';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-inline-form">';
			$this->hidden( 'reinstall_dismiss', false );
			echo '<input type="hidden" name="slug" value="' . esc_attr( $entry['slug'] ) . '">';
			echo '<button type="submit" class="button-link">' . esc_html__( 'Not now, hide this', 'axle-leftovers' ) . '</button></form></div>';
			echo '</section>';
		}
		if ( $handled ) {
			echo '<div class="notice notice-info inline alo-reinstall-left"><ul>';
			foreach ( $handled as $entry ) {
				/* translators: 1: plugin name, 2: number of items. */
				echo '<li>' . esc_html( sprintf( _n( '%1$s: %2$s item stays in the trash.', '%1$s: %2$s items stay in the trash.', $entry['rows'], 'axle-leftovers' ), $entry['name'], number_format_i18n( $entry['rows'] ) ) ) . ' <a href="' . esc_url( Reinstall::screen_url( $entry['slug'] ) ) . '">' . esc_html__( 'Review them again', 'axle-leftovers' ) . '</a></li>';
			}
			echo '</ul></div>';
		}

		$undo = $this->reinstall->undoable();
		if ( $undo ) {
			echo '<section class="axle-card alo-restored-card" aria-labelledby="alo-restored-title"><h2 class="axle-card__title" id="alo-restored-title">' . esc_html__( 'Brought back for reinstalled plugins', 'axle-leftovers' ) . '</h2><ul class="alo-restored-list">';
			foreach ( array_slice( $undo, 0, 5 ) as $entry ) {
				$time = strtotime( (string) $entry['gmt'] . ' UTC' );
				/* translators: 1: plugin slug, 2: number of items. */
				echo '<li>' . esc_html( sprintf( _n( '%1$s: %2$s item restored', '%1$s: %2$s items restored', count( (array) $entry['restored'] ), 'axle-leftovers' ), $entry['slug'], number_format_i18n( count( (array) $entry['restored'] ) ) ) ) . ' ' . ( $time ? AxleUi::relative_time( (int) $time ) : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in AxleUi.
				$this->undo_form( (string) $entry['id'] );
				echo '</li>';
			}
			echo '</ul></section>';
		}
	}

	/**
	 * The preview a reinstalled plugin gets: what the trash holds, what the plugin has now, a choice per item.
	 *
	 * @param string $slug Plugin slug.
	 */
	private function render_reinstall( string $slug ): void {
		$plan = $this->reinstall->plan( $slug );
		$name = $slug;
		foreach ( $this->reinstall->pending() as $entry ) {
			if ( $entry['slug'] === $slug ) {
				$name = $entry['name'];
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only; the form below carries a nonce.
		$file = isset( $_GET['activate'] ) ? sanitize_text_field( wp_unslash( $_GET['activate'] ) ) : '';
		$file = '' !== $file && Ledger::slug_for( $file ) === $slug && $this->may_activate( $file ) ? $file : '';

		echo '<p><a href="' . esc_url( self::url() ) . '">&larr; ' . esc_html__( 'Back to By owner', 'axle-leftovers' ) . '</a></p>';
		/* translators: %s: plugin name. */
		echo '<h2 class="alo-reinstall-title">' . esc_html( sprintf( __( 'Bring back the data of %s', 'axle-leftovers' ), $name ) ) . '</h2>';
		if ( ! $plan ) {
			echo '<div class="axle-empty alo-empty"><p>' . esc_html( Messages::get( 'reinstall_none', $name ) ) . '</p></div>';
			if ( '' !== $file ) {
				echo '<p><a class="button button-primary" href="' . esc_url( $this->activate_url( $file ) ) . '">' . esc_html__( 'Activate the plugin', 'axle-leftovers' ) . '</a></p>';
			}
			return;
		}

		if ( '' !== $file ) {
			/* translators: %s: plugin name. */
			echo '<div class="notice notice-warning inline alo-reinstall-stop"><p>' . esc_html( sprintf( __( '%s is not active yet. Activating it first would create empty tables and options with the same names, so Leftovers stopped here. Pick what comes back, and the plugin is activated after.', 'axle-leftovers' ), $name ) ) . '</p></div>';
		}
		echo '<p class="axle-intro">' . esc_html__( 'Each item shows what the trash holds. Where the plugin already wrote its own, you see both and choose. Nothing the plugin wrote is replaced unless you pick the old data, and then it goes to the trash first. Every restore has an Undo.', 'axle-leftovers' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-reinstall-form">';
		$this->hidden( 'reinstall_restore', false );
		echo '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '">';
		echo '<input type="hidden" name="activate" value="' . esc_attr( $file ) . '">';
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Items to bring back, scroll sideways on a narrow screen', 'axle-leftovers' ) . '">';
		echo '<table class="widefat striped alo-reinstall-items"><caption class="screen-reader-text">' . esc_html__( 'Items to bring back', 'axle-leftovers' ) . '</caption><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Item', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'What the trash holds and what the plugin has now', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'What to do', 'axle-leftovers' ) . '</th>';
		echo '</tr></thead><tbody>';
		$restorable = 0;
		foreach ( $plan as $entry ) {
			echo '<tr data-trash-id="' . esc_attr( (string) $entry['id'] ) . '" data-state="' . esc_attr( $entry['state'] ) . '">';
			echo '<td><code>' . esc_html( $entry['name'] ) . '</code><br><span class="axle-muted alo-type">' . esc_html( Display::type( $entry['type'] ) ) . '</span></td>';
			echo '<td>';
			if ( 'conflict' === $entry['state'] ) {
				echo '<div class="axle-diff axle-diff--labelled"><del data-label="' . esc_attr__( 'In the trash', 'axle-leftovers' ) . '"><span class="screen-reader-text">' . esc_html__( 'In the trash:', 'axle-leftovers' ) . '</span> ' . esc_html( $entry['old'] ) . '</del><ins data-label="' . esc_attr__( 'The plugin wrote', 'axle-leftovers' ) . '"><span class="screen-reader-text">' . esc_html__( 'The plugin wrote:', 'axle-leftovers' ) . '</span> ' . esc_html( $entry['live'] ) . '</ins></div>';
			} elseif ( '' !== $entry['old'] ) {
				echo esc_html( $entry['old'] );
			} else {
				echo '<span class="axle-muted">' . esc_html__( 'Kept in the trash', 'axle-leftovers' ) . '</span>';
			}
			if ( '' !== $entry['note'] ) {
				echo '<p class="description alo-media-note">' . esc_html( $entry['note'] ) . '</p>';
			}
			echo '</td><td>';
			switch ( $entry['state'] ) {
				case 'free':
					++$restorable;
					echo '<label><input type="checkbox" name="choice[' . esc_attr( (string) $entry['id'] ) . ']" value="restore" checked> ' . esc_html__( 'Restore', 'axle-leftovers' ) . '</label>';
					break;
				case 'conflict':
					$can_swap = in_array( $entry['type'], array( 'table', 'option' ), true );
					if ( $can_swap ) {
						++$restorable;
						echo '<fieldset class="alo-choice"><legend class="screen-reader-text">' . esc_html( $entry['name'] ) . '</legend>';
						echo '<label><input type="radio" name="choice[' . esc_attr( (string) $entry['id'] ) . ']" value="keep" checked> ' . esc_html__( 'Keep what the plugin wrote', 'axle-leftovers' ) . '</label><br>';
						echo '<label><input type="radio" name="choice[' . esc_attr( (string) $entry['id'] ) . ']" value="use_old"> ' . esc_html__( 'Use the old data. The current data goes to the trash first', 'axle-leftovers' ) . '</label></fieldset>';
					}
					break;
				case 'same':
					echo '<span class="axle-badge axle-badge--neutral">' . esc_html__( 'Identical, nothing to restore', 'axle-leftovers' ) . '</span>';
					break;
				case 'taken':
					echo '<span class="axle-badge axle-badge--neutral">' . esc_html__( 'Already exists, stays in the trash', 'axle-leftovers' ) . '</span>';
					break;
				default:
					echo '<span class="axle-badge axle-badge--error">' . esc_html__( 'The trashed copy is missing', 'axle-leftovers' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="axle-actions">';
		if ( $restorable > 0 ) {
			echo '<button type="submit" class="button button-primary alo-reinstall-restore">' . esc_html( '' !== $file ? __( 'Restore selected and activate', 'axle-leftovers' ) : __( 'Restore selected', 'axle-leftovers' ) ) . '</button> ';
		}
		echo '</p></form>';

		echo '<div class="axle-actions alo-reinstall-others">';
		if ( '' !== $file ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-inline-form">';
			$this->hidden( 'reinstall_skip', false );
			echo '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '"><input type="hidden" name="activate" value="' . esc_attr( $file ) . '">';
			echo '<button type="submit" class="button alo-reinstall-skip">' . esc_html__( 'Activate without restoring', 'axle-leftovers' ) . '</button></form> ';
			echo '<a class="button-link" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Cancel', 'axle-leftovers' ) . '</a>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-inline-form">';
			$this->hidden( 'reinstall_dismiss', false );
			echo '<input type="hidden" name="slug" value="' . esc_attr( $slug ) . '">';
			echo '<button type="submit" class="button-link">' . esc_html__( 'Do not offer this again', 'axle-leftovers' ) . '</button></form>';
		}
		echo '</div>';
	}

	/**
	 * The evidence lines every item of an owner shares, so the card can say them once.
	 *
	 * @param array[] $items Items of one owner.
	 * @return array<int, array> Evidence entries.
	 */
	private static function common_evidence( array $items ): array {
		if ( ! $items ) {
			return array();
		}

		$sets = array();
		foreach ( $items as $item ) {
			$sets[] = array_map(
				static function ( $line ): string {
					return (string) ( $line['text'] ?? '' );
				},
				(array) $item['evidence']
			);
		}
		$shared = count( $sets ) > 1 ? array_intersect( ...$sets ) : $sets[0];
		$out    = array();
		foreach ( (array) $items[0]['evidence'] as $line ) {
			if ( in_array( (string) ( $line['text'] ?? '' ), $shared, true ) ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * A note about post types and taxonomies that nothing registers now but that have not
	 * been watched long enough to list. Leftovers says when each can be listed.
	 *
	 * @param array $state Scan state.
	 */
	private function render_watching( array $state ): void {
		$watching = (array) ( $state['watching'] ?? array() );
		if ( ! $watching || ! current_user_can( Settings::item_capability( 'posttype' ) ) ) {
			return;
		}

		$names = array();
		foreach ( array_slice( $watching, 0, 5 ) as $entry ) {
			$ready = strtotime( (string) $entry['ready'] . ' UTC' );
			/* translators: 1: post type or taxonomy slug, 2: number of rows, 3: time until it can be listed, such as "3 days". */
			$names[] = sprintf( __( '%1$s (%2$s rows, can be listed in %3$s)', 'axle-leftovers' ), (string) $entry['name'], number_format_i18n( (int) $entry['rows'] ), $ready && $ready > time() ? human_time_diff( time(), $ready ) : __( 'a moment', 'axle-leftovers' ) );
		}
		/* translators: 1: number of types, 2: number of days, 3: list of names. */
		$text = sprintf( _n( '%1$s post type or taxonomy is not registered right now, but Leftovers has not watched for %2$s days yet, so it is not listed. Some types register only in wp-admin, in REST or on some pages. %3$s', '%1$s post types or taxonomies are not registered right now, but Leftovers has not watched for %2$s days yet, so they are not listed. Some types register only in wp-admin, in REST or on some pages. %3$s', count( $watching ), 'axle-leftovers' ), number_format_i18n( count( $watching ) ), number_format_i18n( Observer::report()['days'] ), implode( ', ', $names ) . '.' );
		echo '<div class="notice notice-info inline alo-watching"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * A note about widgets a theme switch left inactive. Report only: Leftovers never touches widgets.
	 */
	private function render_inactive_widgets(): void {
		$count = Themes::inactive_widgets();
		if ( $count < 1 ) {
			return;
		}

		/* translators: %s: number of widgets. */
		$text = sprintf( _n( '%s widget is inactive, most likely after a theme switch. Leftovers does not move or delete widgets.', '%s widgets are inactive, most likely after a theme switch. Leftovers does not move or delete widgets.', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
		echo '<div class="notice notice-info inline alo-widgets-note"><p>' . esc_html( $text );
		if ( current_user_can( 'edit_theme_options' ) && current_theme_supports( 'widgets' ) ) {
			echo ' <a href="' . esc_url( admin_url( 'widgets.php' ) ) . '">' . esc_html__( 'Review them under Appearance > Widgets.', 'axle-leftovers' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Unknown: the last card of By owner.
	 *
	 * @param array $items   Items without an owner.
	 * @param bool  $network Network screen.
	 */
	private function render_unknown_card( array $items, bool $network ): void {
		echo '<section class="axle-card alo-card alo-unknown-card" id="alo-unknown-card" aria-labelledby="alo-unknown-title" data-owner="">';
		echo '<div class="axle-card__head alo-card-head"><h2 class="axle-card__title" id="alo-unknown-title">' . esc_html__( 'Unknown owner', 'axle-leftovers' ) . '</h2> ';
		echo Display::badge( 'unknown' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
		echo '<p class="axle-muted alo-meta">' . esc_html__( 'Nothing points to an owner for these items. They may belong to code that builds names at runtime, to a plugin deleted long ago, or to something run by hand. To trash one you have to type its name.', 'axle-leftovers' ) . '</p>';
		if ( Settings::online_lookup() ) {
			echo '<p class="description">' . esc_html__( 'Look it up searches the code of every plugin on WordPress.org for the name, through veloria.dev. Only the item name is sent, and only when you click.', 'axle-leftovers' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="alo-lookup-form" class="alo-lookup-form">';
			$this->hidden( 'lookup', $network );
			echo '</form>';
		} else {
			echo '<p class="description"><a href="' . esc_url( self::url( 'settings', $network ) ) . '">' . esc_html__( 'Turn on the online lookup in Settings', 'axle-leftovers' ) . '</a> ' . esc_html__( 'to search the code of WordPress.org plugins for these names.', 'axle-leftovers' ) . '</p>';
		}
		$this->items_form( $items, $network, 'alo-unknown' );
		echo '</section>';
	}

	/**
	 * A list of items with checkboxes and the trash button.
	 *
	 * @param array  $items      Items.
	 * @param bool   $network    Network screen.
	 * @param string $id         ID prefix.
	 * @param array  $common     Evidence entries the card above already shows for every item.
	 * @param string $card_level Confidence the card above already shows, or ''.
	 */
	private function items_form( array $items, bool $network, string $id, array $common = array(), string $card_level = '' ): void {
		$unknown     = 'alo-unknown' === $id;
		$content     = new Content();
		$shared      = array_map(
			static function ( $line ): string {
				return (string) ( $line['text'] ?? '' );
			},
			$common
		);
		$blocked_any = false;
		foreach ( $items as $item ) {
			$blocked_any = $blocked_any || ! empty( $item['refs'] );
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-trash-form">';
		$this->hidden( 'trash', $network );
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Items, scroll sideways on a narrow screen', 'axle-leftovers' ) . '">';
		echo '<table class="widefat striped alo-items">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Items', 'axle-leftovers' ) . '</caption>';
		echo '<thead><tr>';
		echo '<td class="check-column"><input type="checkbox" class="alo-check-all" id="' . esc_attr( $id ) . '-all" aria-label="' . esc_attr__( 'Select all', 'axle-leftovers' ) . '"></td>';
		echo '<th scope="col">' . esc_html__( 'Item', 'axle-leftovers' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Rows or posts', 'axle-leftovers' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Size', 'axle-leftovers' ) . '</th>';
		echo '<th scope="col"' . ( $unknown ? '' : ' class="alo-why-cell"' ) . '>' . esc_html( $unknown ? __( 'Confidence and evidence', 'axle-leftovers' ) : __( 'Evidence', 'axle-leftovers' ) ) . '</th>';
		if ( $blocked_any ) {
			echo '<th scope="col" class="alo-ref-cell">' . esc_html__( 'Reference check', 'axle-leftovers' ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $items as $index => $item ) {
			$box     = $id . '-' . $index;
			$typed   = Settings::require_typed_confirmation( $item['confidence'] ) || Content::is_content_type( (string) $item['type'] );
			$blocked = ! empty( $item['refs'] );
			$own     = array_values(
				array_filter(
					(array) $item['evidence'],
					static function ( $line ) use ( $shared ): bool {
						return ! in_array( (string) ( $line['text'] ?? '' ), $shared, true );
					}
				)
			);
			$level   = $item['confidence'] !== $card_level ? Display::badge( $item['confidence'] ) : '';
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
			echo '<td><label for="' . esc_attr( $box ) . '"><code>' . esc_html( $item['name'] ) . '</code></label><br><span class="axle-muted alo-type">' . esc_html( Display::type( $item['type'] ) ) . '</span>';
			if ( Content::is_content_type( (string) $item['type'] ) ) {
				echo Display::content_summary( $item, $content->summary( $item ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			}
			if ( ! empty( $item['autoload'] ) ) {
				echo ' <span class="axle-badge axle-badge--neutral alo-autoload">' . esc_html__( 'Autoloaded', 'axle-leftovers' ) . '</span>';
			}
			if ( 'network' === $item['scope'] ) {
				echo ' <span class="axle-badge axle-badge--info alo-network">' . esc_html__( 'Network-wide', 'axle-leftovers' ) . '</span>';
			}
			if ( 'posttype' === $item['type'] ) {
				echo '<p class="description alo-media-note">' . esc_html__( 'Media attached to these posts stays in the Media Library and points at posts that no longer exist, until you restore them.', 'axle-leftovers' ) . '</p>';
			}
			if ( ! $unknown && ( $own || '' !== $level || $blocked ) ) {
				echo '<div class="alo-why-inline">' . $level; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
				if ( $own ) {
					echo '<ul class="alo-why-list">' . Display::evidence( $own ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
				}
				if ( $blocked ) {
					/* translators: %s: file paths. */
					echo '<span class="alo-blocked">' . esc_html( sprintf( __( 'Used by %s. Cannot be trashed.', 'axle-leftovers' ), implode( ', ', $item['refs'] ) ) ) . '</span>';
				}
				echo '</div>';
			}
			echo '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $item['rows'] ) ) . '</td>';
			echo '<td>' . esc_html( Display::size( (int) $item['bytes'] ) ) . '</td>';
			echo '<td' . ( $unknown ? '' : ' class="alo-why-cell"' ) . '>' . $level; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			if ( $own ) {
				echo '<ul class="alo-evidence">' . Display::evidence( $own ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			} elseif ( '' === $level && $common ) {
				echo '<span class="axle-muted">' . esc_html__( 'As above', 'axle-leftovers' ) . '</span>';
			}
			$this->lookup_cell( $item );
			echo '</td>';
			if ( $blocked_any ) {
				echo '<td class="alo-ref-cell">';
				if ( $blocked ) {
					/* translators: %s: file paths. */
					echo '<span class="alo-blocked">' . esc_html( sprintf( __( 'Used by %s. Cannot be trashed.', 'axle-leftovers' ), implode( ', ', $item['refs'] ) ) ) . '</span>';
				} else {
					echo esc_html__( 'Not used by active code', 'axle-leftovers' );
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		if ( ! $blocked_any && $items ) {
			echo '<p class="axle-muted alo-refcheck">' . esc_html__( 'Reference check: none of these is used by active code.', 'axle-leftovers' ) . '</p>';
		}
		echo '<div class="alo-confirm-names" hidden></div>';
		echo '<p><button type="submit" class="button' . ( $unknown ? ' button-primary' : '' ) . ' alo-trash-button">' . esc_html__( 'Move selected to trash', 'axle-leftovers' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * What the online lookup adds to an item's evidence cell: the plugins it
	 * found, a note when it found nothing, or the Look it up button.
	 *
	 * @param array $item Item.
	 */
	private function lookup_cell( array $item ): void {
		$found = $this->lookup->cached( $item['type'], $item['name'] );
		if ( $found && ! empty( $found['candidates'] ) ) {
			echo '<ul class="alo-candidates">';
			foreach ( $found['candidates'] as $plugin ) {
				printf(
					/* translators: 1: plugin name linked to WordPress.org, 2: number of active installs. */
					'<li>' . esc_html__( '%1$s, %2$s active installs', 'axle-leftovers' ) . '</li>',
					'<a href="' . esc_url( $plugin['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $plugin['name'] ) . '</a>',
					esc_html( number_format_i18n( (int) $plugin['installs'] ) )
				);
			}
			echo '</ul>';
			return;
		}
		if ( 'unknown' !== $item['confidence'] || ! Settings::online_lookup() ) {
			return;
		}
		if ( $found ) {
			echo '<p class="alo-lookup-note">' . esc_html( $this->lookup_summary( $found, $item['name'] ) ) . '</p>';
			return;
		}
		printf(
			'<p><button type="submit" form="alo-lookup-form" name="item" value="%1$s" class="button button-small alo-lookup" aria-label="%2$s">%3$s</button></p>',
			esc_attr( $item['id'] ),
			/* translators: %s: item name. */
			esc_attr( sprintf( __( 'Look it up: %s on WordPress.org', 'axle-leftovers' ), $item['name'] ) ),
			esc_html__( 'Look it up', 'axle-leftovers' )
		);
	}

	/**
	 * One sentence about a lookup result.
	 *
	 * @param array  $result Lookup result.
	 * @param string $name   Item name.
	 * @return string
	 */
	private function lookup_summary( array $result, string $name ): string {
		$total = (int) $result['total'];
		if ( 0 === $total ) {
			/* translators: %s: item name. */
			return sprintf( __( 'Looked up %s: no plugin on WordPress.org names it in its code.', 'axle-leftovers' ), $name );
		}
		if ( empty( $result['candidates'] ) ) {
			/* translators: 1: item name, 2: number of plugins. */
			return sprintf( __( 'Looked up %1$s: %2$s plugins name it, too many to pick an owner.', 'axle-leftovers' ), $name, number_format_i18n( $total ) );
		}
		$first = $result['candidates'][0];
		if ( 1 === $total ) {
			/* translators: 1: item name, 2: plugin name. */
			return sprintf( __( 'Looked up %1$s: only %2$s names it in its code (Likely).', 'axle-leftovers' ), $name, $first['name'] );
		}
		/* translators: 1: item name, 2: number of plugins, 3: plugin name. */
		return sprintf( __( 'Looked up %1$s: %2$s plugins name it. The most installed is %3$s (Guess).', 'axle-leftovers' ), $name, number_format_i18n( $total ), $first['name'] );
	}

	/**
	 * The Trash tab.
	 *
	 * @param array $batches Batches.
	 * @param bool  $network Network screen.
	 */
	private function render_trash( array $batches, bool $network ): void {
		/* translators: %d: days. */
		echo '<p class="axle-intro">' . esc_html( sprintf( __( 'Trashed items are deleted for good after %d days. Tables are renamed, rows and folders are kept as they were, so a restore is exact.', 'axle-leftovers' ), Settings::retention_days() ) ) . '</p>';
		if ( ! $batches ) {
			echo '<div class="axle-empty alo-empty"><p>' . esc_html__( 'The trash is empty.', 'axle-leftovers' ) . '</p></div>';
			return;
		}

		foreach ( $batches as $batch ) {
			$user    = get_userdata( (int) $batch['trashed_by'] );
			$heading = 'alo-batch-' . sanitize_html_class( $batch['batch'] );
			echo '<section class="axle-card alo-batch" aria-labelledby="' . esc_attr( $heading ) . '" data-batch="' . esc_attr( $batch['batch'] ) . '">';
			/* translators: %s: batch ID. */
			echo '<h2 class="axle-card__title" id="' . esc_attr( $heading ) . '">' . esc_html( sprintf( __( 'Batch %s', 'axle-leftovers' ), $batch['batch'] ) ) . '</h2>';
			/* translators: 1: user name, 2: size. */
			echo '<p class="axle-muted alo-meta">' . esc_html__( 'Trashed:', 'axle-leftovers' ) . ' ' . Display::time( (string) $batch['trashed_gmt'] ) . ' ' . esc_html( sprintf( __( 'by %1$s, %2$s.', 'axle-leftovers' ), $user ? $user->display_name : __( 'WP-CLI or a deleted user', 'axle-leftovers' ), Display::size( (int) $batch['bytes'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$this->hidden( 'batch', $network );
			echo '<input type="hidden" name="batch" value="' . esc_attr( $batch['batch'] ) . '">';
			echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Items in this batch, scroll sideways on a narrow screen', 'axle-leftovers' ) . '">';
			echo '<table class="widefat striped alo-trash-items"><caption class="screen-reader-text">' . esc_html__( 'Items in this batch', 'axle-leftovers' ) . '</caption><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Item', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Owner', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Where it is now', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Size', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Export', 'axle-leftovers' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'axle-leftovers' ) . '</span></th>';
			echo '</tr></thead><tbody>';
			foreach ( $batch['items'] as $row ) {
				echo '<tr data-trash-id="' . esc_attr( (string) $row['id'] ) . '">';
				echo '<td><code>' . esc_html( $row['name'] ) . '</code><br><span class="axle-muted alo-type">' . esc_html( Display::type( $row['item_type'] ) ) . '</span></td>';
				echo '<td>' . esc_html( '' !== $row['owner_slug'] ? $this->scanner->attribution()->owner_name( (string) $row['owner_slug'] ) : __( 'Unknown', 'axle-leftovers' ) ) . '</td>';
				echo '<td>' . $this->moved_to( $row ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in moved_to().
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
			echo '</tbody></table></div>';
			echo '<p class="axle-actions alo-batch-actions"><button type="submit" class="button" name="do_batch" value="restore">' . esc_html__( 'Restore batch', 'axle-leftovers' ) . '</button> ';
			echo '<button type="submit" class="button axle-button--danger alo-purge-batch" name="do_batch" value="purge" data-alo-confirm="' . esc_attr__( 'Every item in this batch is deleted for good, including renamed tables and moved folders. This cannot be undone.', 'axle-leftovers' ) . '" data-alo-confirm-title="' . esc_attr__( 'Delete this batch for good?', 'axle-leftovers' ) . '" data-alo-confirm-label="' . esc_attr__( 'Delete for good', 'axle-leftovers' ) . '">' . esc_html__( 'Delete batch for good', 'axle-leftovers' ) . '</button></p>';
			echo '</form>';
			echo '</section>';
		}

		echo '<section class="axle-card axle-card--danger" aria-labelledby="alo-empty-title">';
		echo '<h2 class="axle-card__title" id="alo-empty-title">' . esc_html__( 'Empty the trash', 'axle-leftovers' ) . '</h2>';
		echo '<p>' . esc_html__( 'Deletes every trashed item for good, including the tables and uploads folders. This cannot be undone.', 'axle-leftovers' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-empty-form">';
		$this->hidden( 'empty', $network );
		echo '<p class="axle-actions"><button type="submit" class="button axle-button--danger alo-empty-trash" data-alo-confirm="' . esc_attr__( 'Every item in the trash is deleted for good, in all batches. This cannot be undone.', 'axle-leftovers' ) . '" data-alo-confirm-title="' . esc_attr__( 'Empty the trash?', 'axle-leftovers' ) . '" data-alo-confirm-label="' . esc_attr__( 'Empty trash', 'axle-leftovers' ) . '">' . esc_html__( 'Empty trash', 'axle-leftovers' ) . '</button></p>';
		echo '</form>';
		echo '</section>';
	}

	/**
	 * Where a trashed item is now: a renamed table or a moved folder as before and after, otherwise a short note.
	 *
	 * @param array $row Trash row.
	 * @return string HTML.
	 */
	private function moved_to( array $row ): string {
		$where = (string) $row['trash_location'];
		if ( in_array( $row['item_type'], array( 'table', 'folder' ), true ) && '' !== $where ) {
			$after = 'folder' === $row['item_type'] ? 'uploads/axle-leftovers/' . $where : $where;

			return '<div class="axle-diff"><del><span class="screen-reader-text">' . esc_html__( 'Before:', 'axle-leftovers' ) . '</span> ' . esc_html( (string) $row['name'] ) . '</del><ins><span class="screen-reader-text">' . esc_html__( 'After:', 'axle-leftovers' ) . '</span> ' . esc_html( $after ) . '</ins></div>';
		}

		return esc_html__( 'Copied out and removed', 'axle-leftovers' );
	}

	/**
	 * The Ledger tab.
	 */
	private function render_ledger(): void {
		$entries = $this->ledger->all();
		echo '<p><a href="' . esc_url( self::url( 'settings' ) ) . '">&larr; ' . esc_html__( 'Back to settings', 'axle-leftovers' ) . '</a></p>';
		echo '<p class="axle-intro">' . esc_html__( 'Every plugin and theme Leftovers has seen, with the names its code uses. The signature is kept after a plugin or theme is deleted, even over FTP, so its leftovers can still be found.', 'axle-leftovers' ) . '</p>';
		if ( ! $entries ) {
			echo '<div class="axle-empty alo-empty"><p>' . esc_html__( 'No plugins or themes recorded yet. The first scan adds every installed one, and the list grows as you install, update and delete them.', 'axle-leftovers' ) . '</p>';
			$this->action_form( 'scan', __( 'Scan now', 'axle-leftovers' ), false );
			echo '</div>';
			return;
		}
		echo '<div class="axle-toolbar"><span class="axle-toolbar__end">';
		$this->action_form( 'rescan', __( 'Read all installed plugins and themes again', 'axle-leftovers' ), false );
		echo '</span></div>';

		$labels = array(
			't' => __( 'Tables', 'axle-leftovers' ),
			'o' => __( 'Options', 'axle-leftovers' ),
			'c' => __( 'Cron', 'axle-leftovers' ),
			'm' => __( 'Meta', 'axle-leftovers' ),
			'r' => __( 'Roles and capabilities', 'axle-leftovers' ),
			'u' => __( 'Uploads', 'axle-leftovers' ),
			'p' => __( 'Prefixes', 'axle-leftovers' ),
		);
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Plugins and themes seen, scroll sideways on a narrow screen', 'axle-leftovers' ) . '">';
		echo '<table class="widefat striped alo-ledger"><caption class="screen-reader-text">' . esc_html__( 'Plugins and themes seen', 'axle-leftovers' ) . '</caption><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Plugin or theme', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Status', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'First seen', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Sources', 'axle-leftovers' ) . '</th><th scope="col">' . esc_html__( 'Signature', 'axle-leftovers' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$signature = is_array( $entry['signature'] ) ? $entry['signature'] : Signature::blank();
			$is_theme  = 0 === strpos( (string) $entry['slug'], 'theme:' );
			echo '<tr data-slug="' . esc_attr( $entry['slug'] ) . '"><td><strong>' . esc_html( $entry['name'] ) . '</strong> <span class="axle-badge axle-badge--neutral">' . esc_html( $is_theme ? __( 'Theme', 'axle-leftovers' ) : __( 'Plugin', 'axle-leftovers' ) ) . '</span><br><code>' . esc_html( $entry['slug'] ) . '</code></td>';
			echo '<td>' . Display::removed( $entry, $is_theme ? 'theme' : 'plugin' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
			echo '<td>' . Display::time( (string) $entry['first_seen_gmt'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display.
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
				/* translators: %s: plugin or theme name. */
				echo '<button type="submit" class="button-link" aria-label="' . esc_attr( sprintf( __( 'Read again: %s', 'axle-leftovers' ), $entry['name'] ) ) . '">' . esc_html__( 'Read again', 'axle-leftovers' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * The Settings tab.
	 */
	private function render_settings(): void {
		$settings = Settings::all();
		echo '<p class="axle-intro">' . esc_html__( 'Choose how long trashed items stay, what gets recorded and which online sources Leftovers may use.', 'axle-leftovers' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-settings">';
		$this->hidden( 'settings', false );
		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Trash and recording', 'axle-leftovers' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="alo-retention">' . esc_html__( 'Keep trashed items for', 'axle-leftovers' ) . '</label></th><td><input type="number" min="1" max="3650" id="alo-retention" name="retention_days" value="' . esc_attr( (string) $settings['retention_days'] ) . '" class="small-text" aria-describedby="alo-retention-help"> ' . esc_html__( 'days', 'axle-leftovers' ) . '<p class="description" id="alo-retention-help">' . esc_html__( 'The daily cron deletes them after this.', 'axle-leftovers' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="alo-export-max">' . esc_html__( 'Largest table to export', 'axle-leftovers' ) . '</label></th><td><input type="number" min="0" id="alo-export-max" name="export_max_mb" value="' . esc_attr( (string) $settings['export_max_mb'] ) . '" class="small-text" aria-describedby="alo-export-help"> MB<p class="description" id="alo-export-help">' . esc_html__( 'Bigger tables are only renamed, with a warning. The data stays in the database either way.', 'axle-leftovers' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="alo-observe">' . esc_html__( 'Watch post types for', 'axle-leftovers' ) . '</label></th><td><input type="number" min="1" max="365" id="alo-observe" name="observe_days" value="' . esc_attr( (string) $settings['observe_days'] ) . '" class="small-text" aria-describedby="alo-observe-help"> ' . esc_html__( 'days', 'axle-leftovers' ) . '<p class="description" id="alo-observe-help">' . esc_html__( 'A post type or taxonomy is listed as unregistered only after no code has registered it for this long, on front end and admin requests alike.', 'axle-leftovers' ) . '</p></td></tr>';
		$checks = array(
			'keep_exports'    => array( __( 'Exports', 'axle-leftovers' ), __( 'Keep export files when the trash is purged', 'axle-leftovers' ) ),
			'runtime_ledger'  => array( __( 'Runtime ledger', 'axle-leftovers' ), __( 'Record which plugin creates new tables, options, cron events, roles and capabilities', 'axle-leftovers' ) ),
			'reinstall_offer' => array( __( 'Reinstalls', 'axle-leftovers' ), __( 'When a plugin is activated again and the trash holds its data, stop and offer to restore it first. Activation would otherwise create empty tables and options under the same names', 'axle-leftovers' ) ),
			'track_meta_keys' => array( __( 'Meta keys', 'axle-leftovers' ), __( 'Also record new post, user, term and comment meta keys', 'axle-leftovers' ) ),
			'online_lookup'   => array( __( 'Online lookup', 'axle-leftovers' ), __( 'Show a Look it up button for Unknown items. It sends the item name to veloria.dev, which searches the code of every WordPress.org plugin', 'axle-leftovers' ) ),
			'remote_dataset'  => array( __( 'Full list', 'axle-leftovers' ), __( 'Allow downloading the full signature list (about 10,000 plugins) from GitHub. It sends nothing from this site. A list you already downloaded stays in use when this is off, and no request is made. Delete downloaded list removes it', 'axle-leftovers' ) ),
		);
		foreach ( $checks as $key => $labels ) {
			echo '<tr><th scope="row">' . esc_html( $labels[0] ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1"' . checked( $settings[ $key ], true, false ) . '> ' . esc_html( $labels[1] ) . '</label></td></tr>';
		}
		echo '</tbody></table></section>';
		submit_button( __( 'Save settings', 'axle-leftovers' ) );
		echo '</form>';
		$this->render_dataset();
		echo '<p class="alo-ledger-link"><a href="' . esc_url( self::url( 'ledger' ) ) . '">' . esc_html__( 'Open the ledger of plugins and themes Leftovers has seen', 'axle-leftovers' ) . '</a></p>';
	}

	/**
	 * The signature list: what is loaded, and the download and delete buttons.
	 */
	private function render_dataset(): void {
		$info = ( new Dataset() )->info();
		echo '<section class="axle-card alo-dataset">';
		echo '<h2 class="axle-card__title">' . esc_html__( 'Signature list', 'axle-leftovers' ) . '</h2>';
		echo '<ul class="ul-disc alo-dataset-info">';
		/* translators: 1: number of plugins, 2: date. */
		echo '<li class="alo-dataset-bundled">' . sprintf( esc_html__( 'Bundled list: %1$s plugins, built %2$s.', 'axle-leftovers' ), esc_html( number_format_i18n( $info['bundled_count'] ) ), '' !== $info['bundled_built'] ? Display::time( (string) $info['bundled_built'] ) : esc_html__( 'on an unknown date', 'axle-leftovers' ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display and above.
		if ( $info['downloaded'] ) {
			/* translators: 1: number of plugins, 2: build date, 3: download date. */
			echo '<li class="alo-dataset-full">' . sprintf( esc_html__( 'Full list: %1$s plugins, built %2$s, downloaded %3$s.', 'axle-leftovers' ), esc_html( number_format_i18n( (int) $info['downloaded']['count'] ) ), Display::time( (string) $info['downloaded']['built'] ), Display::time( (string) ( $info['downloaded']['fetched_gmt'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Display and above.
			$this->action_form( 'dataset_remove', __( 'Delete downloaded list', 'axle-leftovers' ), false, __( 'The downloaded list is deleted and the bundled list is used instead. You can download it again later.', 'axle-leftovers' ), __( 'Delete the downloaded list?', 'axle-leftovers' ), __( 'Delete the list', 'axle-leftovers' ) );
			echo '</li>';
		} else {
			echo '<li class="alo-dataset-full">' . esc_html__( 'Full list: not downloaded.', 'axle-leftovers' ) . '</li>';
		}
		/* translators: %s: number of plugins. */
		echo '<li class="alo-dataset-count">' . esc_html( sprintf( __( 'In use: %s plugins (the full list first, the bundled list for the rest).', 'axle-leftovers' ), number_format_i18n( $info['count'] ) ) ) . '</li>';
		echo '</ul>';
		if ( Settings::remote_dataset() ) {
			$this->action_form( 'dataset_download', $info['downloaded'] ? __( 'Update the full list', 'axle-leftovers' ) : __( 'Download the full list', 'axle-leftovers' ), false );
		} else {
			echo '<p class="description">' . esc_html__( 'To download the full list, allow it above and save the settings.', 'axle-leftovers' ) . '</p>';
		}
		echo '</section>';
	}

	/**
	 * The in-place confirm step the script fills in and moves under the button that asked.
	 */
	private function render_confirm(): void {
		echo '<div class="axle-confirm" id="alo-confirm" role="group" aria-labelledby="alo-confirm-title" hidden>';
		echo '<strong id="alo-confirm-title" tabindex="-1"></strong>';
		echo '<p id="alo-confirm-message"></p>';
		echo '<ul class="alo-confirm-list"></ul>';
		echo '<div class="alo-confirm-typed"></div>';
		echo '<p class="alo-confirm-error" role="alert"></p>';
		echo '<p class="axle-actions"><button type="button" class="button button-primary alo-confirm-ok"></button> <button type="button" class="button alo-confirm-cancel">' . esc_html__( 'Cancel', 'axle-leftovers' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * A one-button form.
	 *
	 * @param string $action  Action.
	 * @param string $label   Button label.
	 * @param bool   $network Network screen.
	 * @param string $confirm What will change and whether it can be undone, asked in place before submitting. Default: no question.
	 * @param string $title   The question's title, with a confirm text.
	 * @param string $ok      The label of the confirm button, with a confirm text.
	 */
	private function action_form( string $action, string $label, bool $network, string $confirm = '', string $title = '', string $ok = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-inline-form alo-' . esc_attr( $action ) . '-form">';
		$this->hidden( $action, $network );
		echo '<button type="submit" class="button"' . ( '' !== $confirm ? ' data-alo-confirm="' . esc_attr( $confirm ) . '" data-alo-confirm-title="' . esc_attr( $title ) . '" data-alo-confirm-label="' . esc_attr( '' !== $ok ? $ok : $label ) . '"' : '' ) . '>' . esc_html( $label ) . '</button>';
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

		$this->network = $network;
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
						$this->set_flash( 'success', sprintf( _n( 'Deleted %d item for good. This cannot be undone.', 'Deleted %d items for good. This cannot be undone.', count( $result ), 'axle-leftovers' ), count( $result ) ) );
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
					$this->set_flash( 'success', sprintf( _n( 'Deleted %d item for good. This cannot be undone.', 'Deleted %d items for good. This cannot be undone.', count( $result ), 'axle-leftovers' ), count( $result ) ) );
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
					$this->set_flash( 'success', __( 'Reading every installed plugin and theme again.', 'axle-leftovers' ) );
				} else {
					$entry = $this->ledger->get( $slug );
					if ( $entry && null === $entry['removed_gmt'] ) {
						if ( 0 === strpos( $slug, 'theme:' ) ) {
							$this->recorder->ensure_theme_signature( substr( $slug, 6 ), true );
						} else {
							$this->recorder->ensure_signature( (string) $entry['plugin_file'], true );
						}
						Scanner::mark_stale();
						/* translators: %s: plugin or theme name. */
						$this->set_flash( 'success', sprintf( __( 'Read %s again.', 'axle-leftovers' ), $entry['name'] ) );
					} else {
						$this->set_flash( 'warning', __( 'That plugin or theme is not installed any more, so its code cannot be read again. The signature kept in the ledger is still used.', 'axle-leftovers' ) );
					}
				}
				break;

			case 'reinstall_restore':
				$this->handle_reinstall_restore();
				break;

			case 'reinstall_skip':
				$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
				$file = isset( $_POST['activate'] ) ? sanitize_text_field( wp_unslash( $_POST['activate'] ) ) : '';
				$this->reinstall->set_decision( $slug, 'skipped' );
				$this->set_flash( 'warning', __( 'Left the old data in the trash. The plugin starts from scratch. You can still bring the data back from By owner, and choose per item.', 'axle-leftovers' ) );
				if ( '' !== $file && $this->may_activate( $file ) ) {
					wp_safe_redirect( $this->activate_url( $file ) );
					exit;
				}
				break;

			case 'reinstall_dismiss':
				$slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
				$this->reinstall->set_decision( $slug, 'dismissed' );
				$this->set_flash( 'success', __( 'Hid the offer. The data stays in the trash.', 'axle-leftovers' ) );
				break;

			case 'reinstall_undo':
				$id     = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
				$result = $this->reinstall->undo( $id );
				if ( is_wp_error( $result ) ) {
					$this->set_flash( 'error', $result->get_error_message() );
				} else {
					$text = __( 'Undone. What was restored is in the trash again, and anything it replaced is back.', 'axle-leftovers' );
					foreach ( $result['failed'] as $failed ) {
						$text .= ' ' . $failed['message'];
					}
					$this->set_flash( $result['failed'] ? 'warning' : 'success', $text );
				}
				break;

			case 'dismiss_steps':
				update_user_meta( get_current_user_id(), self::STEPS_META, '1' );
				$this->set_flash( 'success', __( 'Hid the Get started card. The Help tab at the top of the screen explains how Leftovers works.', 'axle-leftovers' ) );
				break;

			case 'lookup':
				$tab    = 'unknown';
				$parts  = explode( ':', isset( $_POST['item'] ) ? sanitize_text_field( wp_unslash( $_POST['item'] ) ) : '', 2 );
				$result = 2 === count( $parts ) ? $this->lookup->for_item( $this->scanner, $parts[0], $parts[1] ) : new \WP_Error( 'axle_leftovers_not_listed', Messages::get( 'nothing_selected' ) );
				if ( is_wp_error( $result ) ) {
					$this->set_flash( 'error', $result->get_error_message() );
				} else {
					$this->set_flash( empty( $result['result']['candidates'] ) ? 'warning' : 'success', $this->lookup_summary( $result['result'], $parts[1] ) );
				}
				break;

			case 'dataset_download':
				$tab    = 'settings';
				$result = $this->updater->download();
				if ( is_wp_error( $result ) ) {
					$this->set_flash( 'error', $result->get_error_message() );
				} else {
					/* translators: 1: number of plugins, 2: date. */
					$this->set_flash( 'success', sprintf( __( 'Downloaded the full list: %1$s plugins, built %2$s.', 'axle-leftovers' ), number_format_i18n( (int) $result['count'] ), $result['built'] ) );
				}
				break;

			case 'dataset_remove':
				$tab = 'settings';
				$this->updater->remove();
				$this->set_flash( 'success', __( 'Deleted the downloaded list. The bundled list is used.', 'axle-leftovers' ) );
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
	 * Restores what the preview form picked, then activates the plugin when the form came from an activation.
	 */
	private function handle_reinstall_restore(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in handle() before this runs.
		$slug   = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$file   = isset( $_POST['activate'] ) ? sanitize_text_field( wp_unslash( $_POST['activate'] ) ) : '';
		$picked = array();
		$raw    = isset( $_POST['choice'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['choice'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		foreach ( $raw as $id => $choice ) {
			if ( in_array( $choice, array( 'restore', 'use_old' ), true ) ) {
				$picked[ (int) $id ] = $choice;
			}
		}

		$result = $this->reinstall->restore( $slug, $picked, 'screen' );
		if ( is_wp_error( $result ) ) {
			$this->set_flash( 'error', $result->get_error_message() );
			return;
		}

		$entry = $result['entry'];
		/* translators: 1: number of items, 2: number of items replaced. */
		$text = sprintf( _n( 'Restored %d item.', 'Restored %d items.', count( $entry['restored'] ), 'axle-leftovers' ), count( $entry['restored'] ) );
		if ( $entry['swapped'] ) {
			/* translators: %d: number of items. */
			$text .= ' ' . sprintf( _n( '%d item the plugin wrote since was moved to the trash first.', '%d items the plugin wrote since were moved to the trash first.', count( $entry['swapped'] ), 'axle-leftovers' ), count( $entry['swapped'] ) );
		}
		foreach ( $result['failed'] as $failed ) {
			$text .= ' ' . $failed['message'];
		}
		$this->set_flash( $result['failed'] ? 'warning' : 'success', $text, 'reinstalled', array( 'id' => $entry['id'] ) );

		if ( '' !== $file && $this->may_activate( $file ) ) {
			wp_safe_redirect( $this->activate_url( $file ) );
			exit;
		}
	}

	/**
	 * Whether the current user may activate a plugin file that is installed.
	 *
	 * @param string $file Plugin file.
	 * @return bool
	 */
	private function may_activate( string $file ): bool {
		return current_user_can( 'activate_plugin', $file ) && 0 === validate_file( $file ) && '.php' === substr( $file, -4 ) && file_exists( WP_PLUGIN_DIR . '/' . $file );
	}

	/**
	 * The signed link that activates a plugin, as the Plugins screen builds it.
	 *
	 * @param string $file Plugin file.
	 * @return string
	 */
	private function activate_url( string $file ): string {
		return add_query_arg(
			array(
				'action'   => 'activate',
				'plugin'   => rawurlencode( $file ),
				'_wpnonce' => wp_create_nonce( 'activate-plugin_' . $file ),
			),
			self_admin_url( 'plugins.php' )
		);
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
				'network' => $this->network,
			),
			MINUTE_IN_SECONDS * 5
		);
	}

	/**
	 * Prints and clears the stored message. After a trash it offers Undo.
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
			'<div class="notice notice-%1$s alo-notice"%2$s data-alo-tone="%3$s" role="%4$s"><p>%5$s',
			esc_attr( $class ),
			'' !== $flash['event'] ? ' data-alo-event="' . esc_attr( $flash['event'] ) . '" data-alo-payload="' . esc_attr( (string) wp_json_encode( $flash['payload'] ) ) . '"' : '',
			esc_attr( $class ),
			'error' === $class ? 'alert' : 'status',
			esc_html( $flash['text'] )
		);
		if ( 'trashed' === $flash['event'] && ! empty( $flash['payload']['batch'] ) ) {
			echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-inline-form alo-undo-form">';
			$this->hidden( 'batch', ! empty( $flash['network'] ) );
			echo '<input type="hidden" name="batch" value="' . esc_attr( (string) $flash['payload']['batch'] ) . '">';
			echo '<button type="submit" class="button-link alo-undo">' . esc_html__( 'Undo', 'axle-leftovers' ) . '</button></form>';
		}
		if ( 'reinstalled' === $flash['event'] && ! empty( $flash['payload']['id'] ) ) {
			$this->undo_form( (string) $flash['payload']['id'] );
		}
		echo '</p></div>';
	}

	/**
	 * A link-style Undo for a reinstall restore.
	 *
	 * @param string $id Log entry ID.
	 */
	private function undo_form( string $id ): void {
		echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="alo-inline-form alo-undo-form">';
		$this->hidden( 'reinstall_undo', false );
		echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
		echo '<button type="submit" class="button-link alo-undo">' . esc_html__( 'Undo', 'axle-leftovers' ) . '</button></form>';
	}
}
