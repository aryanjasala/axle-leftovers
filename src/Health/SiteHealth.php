<?php
/**
 * Site Health test.
 *
 * @package AryanX\Axle\Leftovers\Health
 */

namespace AryanX\Axle\Leftovers\Health;

use AryanX\Axle\Leftovers\Admin\Display;
use AryanX\Axle\Leftovers\Admin\Page;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Service;

defined( 'ABSPATH' ) || exit;

/**
 * One test under Tools > Site Health: leftovers of deleted plugins that are
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
	 * Sets up the test.
	 *
	 * @param Scanner $scanner Scanner.
	 */
	public function __construct( Scanner $scanner ) {
		$this->scanner = $scanner;
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
			'label' => __( 'Deleted plugins leave little autoloaded data', 'axle-leftovers' ),
			'test'  => array( $this, 'test_autoload' ),
		);

		return $tests;
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
			'label'       => __( 'Deleted plugins leave little autoloaded data', 'axle-leftovers' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'axle-leftovers' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Options left by deleted plugins add little or nothing to every page load.', 'axle-leftovers' ) . '</p>',
			'actions'     => '',
			'test'        => 'axle_leftovers_autoload',
		);

		if ( $bytes > self::THRESHOLD ) {
			/* translators: 1: number of options, 2: size. */
			$result['label']          = sprintf( _n( '%1$d option left by a deleted plugin adds %2$s to every page load', '%1$d options left by deleted plugins add %2$s to every page load', $count, 'axle-leftovers' ), $count, Display::size( $bytes ) );
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			$result['description']    = '<p>' . esc_html__( 'WordPress loads every autoloaded option on every request. Options from plugins that are gone only slow the site down.', 'axle-leftovers' ) . '</p>';
			$result['actions']        = '<p><a href="' . esc_url( Page::url() ) . '">' . esc_html__( 'Review them in Tools > Leftovers', 'axle-leftovers' ) . '</a></p>';
		}

		return $result;
	}
}
