<?php
/**
 * Site Health test.
 *
 * @package AryanX\Axle\Leftovers\Health
 */

namespace AryanX\Axle\Leftovers\Health;

use AryanX\Axle\Leftovers\Admin\Display;
use AryanX\Axle\Leftovers\Admin\Page;
use AryanX\Axle\Leftovers\Reinstall;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Service;

defined( 'ABSPATH' ) || exit;

/**
 * One test under Tools > Site Health: leftovers of deleted plugins and themes that are
 * autoloaded on every request, from the last scan's cached results.
 */
final class SiteHealth implements Service {

	/**
	 * Autoloaded leftovers above this many bytes make the test "recommended".
	 */
	public const THRESHOLD = 102400;

	/**
	 * The scanner.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * The reinstall restore.
	 *
	 * @var Reinstall|null
	 */
	private ?Reinstall $reinstall;

	/**
	 * Sets up the test.
	 *
	 * @param Scanner        $scanner   Scanner.
	 * @param Reinstall|null $reinstall Reinstall restore, to find plugins that came back.
	 */
	public function __construct( Scanner $scanner, ?Reinstall $reinstall = null ) {
		$this->scanner   = $scanner;
		$this->reinstall = $reinstall;
	}

	/**
	 * Registers the test.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * Adds the test.
	 *
	 * @param array $tests Tests.
	 * @return array
	 */
	public function add_tests( $tests ): array {
		$tests                                      = (array) $tests;
		$tests['direct']['axle_leftovers_autoload'] = array(
			'label' => __( 'Deleted plugins and themes leave little autoloaded data', 'axle-leftovers' ),
			'test'  => array( $this, 'test_autoload' ),
		);

		$tests['direct']['axle_leftovers_content'] = array(
			'label' => __( 'No posts are left from removed post types', 'axle-leftovers' ),
			'test'  => array( $this, 'test_content' ),
		);

		if ( $this->reinstall ) {
			$tests['direct']['axle_leftovers_reinstall'] = array(
				'label' => __( 'Plugins that came back did not lose their old data', 'axle-leftovers' ),
				'test'  => array( $this, 'test_reinstall' ),
			);
		}

		return $tests;
	}

	/**
	 * Installed plugins whose old data is still in the trash and was never looked at.
	 *
	 * @return array
	 */
	public function test_reinstall(): array {
		$waiting = array();
		foreach ( $this->reinstall ? $this->reinstall->pending() : array() as $entry ) {
			if ( '' === $entry['decision'] ) {
				$waiting[] = $entry['name'];
			}
		}

		$result = array(
			'label'       => __( 'Plugins that came back did not lose their old data', 'axle-leftovers' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'axle-leftovers' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'No installed plugin has data waiting in the Leftovers trash.', 'axle-leftovers' ) . '</p>',
			'actions'     => '',
			'test'        => 'axle_leftovers_reinstall',
		);
		if ( $waiting ) {
			/* translators: %s: plugin names. */
			$result['label']          = sprintf( __( 'The trash holds settings and tables of plugins that are installed again: %s', 'axle-leftovers' ), implode( ', ', $waiting ) );
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['description']    = '<p>' . esc_html__( 'These plugins were deleted, their leftovers were moved to the trash, and now they are installed again. They may have started from scratch. Leftovers can show what is in the trash and bring it back, and nothing the plugin wrote since is replaced unless you choose it.', 'axle-leftovers' ) . '</p>';
			$result['actions']        = '<p><a href="' . esc_url( Page::url() ) . '">' . esc_html__( 'Review them in Tools > Leftovers', 'axle-leftovers' ) . '</a></p>';
		}

		return $result;
	}

	/**
	 * Posts and terms of post types and taxonomies nothing registers any more.
	 *
	 * @return array
	 */
	public function test_content(): array {
		$posts = 0;
		$terms = 0;
		foreach ( $this->scanner->items( array( 'scope' => 'site' ) ) as $item ) {
			if ( 'posttype' === $item['type'] ) {
				$posts += (int) $item['rows'];
			} elseif ( 'taxonomy' === $item['type'] ) {
				$terms += (int) $item['rows'];
			}
		}

		$result = array(
			'label'       => __( 'No posts are left from removed post types', 'axle-leftovers' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'axle-leftovers' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Every post type and taxonomy in the database is registered by something installed.', 'axle-leftovers' ) . '</p>',
			'actions'     => '',
			'test'        => 'axle_leftovers_content',
		);

		/**
		 * Filters how many posts or terms of unregistered post types and taxonomies make the Site Health test "recommended".
		 *
		 * @param int $threshold Rows. Default 1.
		 */
		$threshold = max( 1, (int) apply_filters( 'axle_leftovers_content_health_threshold', 1 ) );
		if ( $posts + $terms >= $threshold ) {
			/* translators: 1: number of posts, 2: number of terms. */
			$result['label']          = sprintf( __( '%1$s posts and %2$s terms belong to post types and taxonomies nothing registers any more', 'axle-leftovers' ), number_format_i18n( $posts ), number_format_i18n( $terms ) );
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['description']    = '<p>' . esc_html__( 'They sit in the database, invisible in wp-admin, and slow down exports, backups and some queries. Leftovers shows what is in them and moves them to a trash you can restore from.', 'axle-leftovers' ) . '</p>';
			$result['actions']        = '<p><a href="' . esc_url( Page::url() ) . '">' . esc_html__( 'Review them in Tools > Leftovers', 'axle-leftovers' ) . '</a></p>';
		}

		return $result;
	}

	/**
	 * Autoloaded bytes from deleted plugins' options.
	 *
	 * @return array
	 */
	public function test_autoload(): array {
		$bytes = 0;
		$count = 0;
		foreach ( $this->scanner->items(
			array(
				'type'  => 'option',
				'scope' => 'site',
			)
		) as $item ) {
			if ( ! empty( $item['autoload'] ) ) {
				$bytes += (int) $item['bytes'];
				++$count;
			}
		}

		$result = array(
			'label'       => __( 'Deleted plugins and themes leave little autoloaded data', 'axle-leftovers' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'axle-leftovers' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Options left by deleted plugins and themes add little or nothing to every page load.', 'axle-leftovers' ) . '</p>',
			'actions'     => '',
			'test'        => 'axle_leftovers_autoload',
		);

		if ( $bytes > self::THRESHOLD ) {
			/* translators: 1: number of options, 2: size. */
			$result['label']          = sprintf( _n( '%1$d option left by a deleted plugin or theme adds %2$s to every page load', '%1$d options left by deleted plugins and themes add %2$s to every page load', $count, 'axle-leftovers' ), $count, Display::size( $bytes ) );
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['description']    = '<p>' . esc_html__( 'WordPress loads every autoloaded option on every request. Options from plugins and themes that are gone only slow the site down.', 'axle-leftovers' ) . '</p>';
			$result['actions']        = '<p><a href="' . esc_url( Page::url() ) . '">' . esc_html__( 'Review them in Tools > Leftovers', 'axle-leftovers' ) . '</a></p>';
		}

		return $result;
	}
}
