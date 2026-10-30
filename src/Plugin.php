<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

use AryanX\Axle\Leftovers\Admin\Notices;
use AryanX\Axle\Leftovers\Admin\Page;
use AryanX\Axle\Leftovers\Cli\Command;
use AryanX\Axle\Leftovers\Health\SiteHealth;
use AryanX\Axle\Leftovers\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shared objects once and registers every service.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

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
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$inventory      = new Inventory();
		$this->ledger   = new Ledger();
		$this->recorder = new Recorder( $this->ledger, $inventory );
		$this->scanner  = new Scanner( $this->ledger, $inventory, new Attribution( $this->ledger, new Dataset() ), new References(), $this->recorder );
		$this->trash    = new Trash( $this->scanner, $inventory, new Storage() );
	}

	/**
	 * The ledger.
	 *
	 * @return Ledger
	 */
	public function ledger(): Ledger {
		return $this->ledger;
	}

	/**
	 * The recorder.
	 *
	 * @return Recorder
	 */
	public function recorder(): Recorder {
		return $this->recorder;
	}

	/**
	 * The scanner.
	 *
	 * @return Scanner
	 */
	public function scanner(): Scanner {
		return $this->scanner;
	}

	/**
	 * The trash.
	 *
	 * @return Trash
	 */
	public function trash(): Trash {
		return $this->trash;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		Schema::maybe_upgrade();

		$services = array(
			$this->recorder,
			new Cron( $this->scanner, $this->trash ),
			new Page( $this->scanner, $this->trash, $this->ledger, $this->recorder ),
			new Notices( $this->scanner, $this->trash ),
			new Controller( $this->scanner, $this->trash ),
			new Abilities( $this->scanner, $this->trash ),
			new SiteHealth( $this->scanner ),
			new Privacy( $this->trash ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-leftovers', new Command( $this->scanner, $this->trash, $this->ledger ) );
		}
	}
}
