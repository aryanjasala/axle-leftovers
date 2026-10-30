<?php
/**
 * The daily refresh and purge, and background scan steps.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the scan in steps from WP-Cron, and once a day purges trashed items
 * past their retention period and starts a fresh scan.
 */
final class Cron implements Service {

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
	 * Sets up the schedules.
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
		add_action( Schema::CRON_STEP, array( $this->scanner, 'cron_step' ) );
		add_action( Schema::CRON_DAILY, array( $this, 'daily' ) );
	}

	/**
	 * Purges what is due and refreshes the scan.
	 */
	public function daily(): void {
		$this->trash->purge( array( 'due_only' => true ) );
		Scanner::mark_stale();
	}
}
