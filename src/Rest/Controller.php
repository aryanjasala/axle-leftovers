<?php
/**
 * REST routes under axle-leftovers/v1.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Rest;

use AryanX\Axle\Leftovers\Admin\Notices;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Service;
use AryanX\Axle\Leftovers\Settings;
use AryanX\Axle\Leftovers\Trash;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lists leftovers and the trash, and runs trash, restore, purge and scan,
 * with the same capability and checks as the screen.
 */
final class Controller implements Service {

	public const NAMESPACE = 'axle-leftovers/v1';

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
	 * Sets up the routes.
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
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Whether the current user may use the routes.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return current_user_can( Settings::capability() );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		$items = array(
			'type'     => 'array',
			'required' => true,
			'items'    => array(
				'type'       => 'object',
				'properties' => array(
					'type' => array( 'type' => 'string' ),
					'name' => array( 'type' => 'string' ),
				),
				'required'   => array( 'type', 'name' ),
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_items' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'plugin'     => array( 'type' => 'string' ),
					'type'       => array( 'type' => 'string' ),
					'confidence' => array(
						'type' => 'string',
						'enum' => array( 'certain', 'likely', 'guess', 'unknown' ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/trash',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_trash' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'trash_items' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'items'         => $items,
						'confirm_names' => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'purge' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'older_than' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'batch'      => array( 'type' => 'string' ),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'batch' => array(
						'type'    => 'string',
						'default' => '',
					),
					'items' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'integer' ),
						'default' => array(),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/notice',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'notice' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	/**
	 * GET /items.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_items( WP_REST_Request $request ): WP_REST_Response {
		$filters = array(
			'type'       => (string) $request->get_param( 'type' ),
			'confidence' => (string) $request->get_param( 'confidence' ),
		);
		if ( null !== $request->get_param( 'plugin' ) ) {
			$filters['plugin'] = (string) $request->get_param( 'plugin' );
		}

		return new WP_REST_Response( $this->scanner->items( $filters ) );
	}

	/**
	 * GET /trash.
	 *
	 * @return WP_REST_Response
	 */
	public function list_trash(): WP_REST_Response {
		$out = array();
		foreach ( $this->trash->batches( 'site' ) + $this->trash->batches( 'network' ) as $batch ) {
			$batch['items'] = array_map( array( Trash::class, 'public_row' ), $batch['items'] );
			$out[]          = $batch;
		}

		return new WP_REST_Response( $out );
	}

	/**
	 * POST /trash.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function trash_items( WP_REST_Request $request ) {
		$result = $this->trash->trash( (array) $request->get_param( 'items' ), array( 'confirm_names' => (array) $request->get_param( 'confirm_names' ) ) );

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/**
	 * POST /restore.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore( WP_REST_Request $request ) {
		$result = $this->trash->restore( (string) $request->get_param( 'batch' ), (array) $request->get_param( 'items' ) );

		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result );
	}

	/**
	 * DELETE /trash.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function purge( WP_REST_Request $request ) {
		$args = array( 'batch' => (string) $request->get_param( 'batch' ) );
		if ( null !== $request->get_param( 'older_than' ) ) {
			$args['older_than'] = (int) $request->get_param( 'older_than' );
		}
		$result = $this->trash->purge( $args );

		return is_wp_error( $result ) ? $result : new WP_REST_Response( array( 'purged' => $result ) );
	}

	/**
	 * POST /scan: runs the scan for up to 20 seconds; WP-Cron finishes the rest.
	 *
	 * @return WP_REST_Response
	 */
	public function scan(): WP_REST_Response {
		$this->scanner->start();
		$done = $this->scanner->step( 20.0 );

		return new WP_REST_Response(
			array(
				'finished' => $done,
				'items'    => count( $this->scanner->items() ),
			)
		);
	}

	/**
	 * GET /notice: what a plugin deleted over AJAX left behind.
	 *
	 * @return WP_REST_Response
	 */
	public function notice(): WP_REST_Response {
		$notices = new Notices( $this->scanner, $this->trash );

		return new WP_REST_Response( array( 'html' => array_map( array( Notices::class, 'notice_html' ), $notices->pending_messages() ) ) );
	}
}
