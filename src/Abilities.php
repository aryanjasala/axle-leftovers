<?php
/**
 * Abilities for AI agents and other Abilities API clients.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Leftovers' abilities on WordPress 6.9 and later. On older
 * WordPress nothing here runs.
 *
 * The trash-items and purge-trash abilities are destructive; restore-items and
 * rescan are not read-only, so Axle Read-Only Mode refuses them during a freeze. The
 * trash itself refuses too.
 */
final class Abilities implements Service {

	public const CATEGORY = 'axle-leftovers';

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
	 * Sets up the abilities.
	 *
	 * @param Scanner $scanner Scanner.
	 * @param Trash   $trash   Trash.
	 */
	public function __construct( Scanner $scanner, Trash $trash ) {
		$this->scanner = $scanner;
		$this->trash   = $trash;
	}

	/**
	 * Hooks into the Abilities API when WordPress has it.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle Leftovers', 'axle-leftovers' ),
				'description' => __( 'Find what deleted plugins left behind, with evidence, and move it to a trash with undo.', 'axle-leftovers' ),
			)
		);
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$permission = static function (): bool {
			return current_user_can( Settings::capability() );
		};
		$types      = array_keys( Settings::item_types() );
		$levels     = array_keys( Attribution::RANK );
		$item       = array(
			'type'       => 'object',
			'properties' => array(
				'id'         => array( 'type' => 'string' ),
				'type'       => array( 'type' => 'string' ),
				'name'       => array( 'type' => 'string' ),
				'scope'      => array( 'type' => 'string' ),
				'owner'      => array( 'type' => 'string' ),
				'owner_name' => array( 'type' => 'string' ),
				'confidence' => array( 'type' => 'string' ),
				'evidence'   => array( 'type' => 'array' ),
				'rows'       => array( 'type' => 'integer' ),
				'bytes'      => array( 'type' => 'integer' ),
				'autoload'   => array( 'type' => 'boolean' ),
				'refs'       => array( 'type' => 'array' ),
			),
		);
		$row        = array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'batch'       => array( 'type' => 'string' ),
				'type'        => array( 'type' => 'string' ),
				'name'        => array( 'type' => 'string' ),
				'owner'       => array( 'type' => 'string' ),
				'confidence'  => array( 'type' => 'string' ),
				'bytes'       => array( 'type' => 'integer' ),
				'export'      => array( 'type' => 'string' ),
				'status'      => array( 'type' => 'string' ),
				'trashed_gmt' => array( 'type' => 'string' ),
			),
		);
		$rows       = array(
			'type'  => 'array',
			'items' => $row,
		);

		$this->ability(
			'list-leftovers',
			__( 'List leftovers', 'axle-leftovers' ),
			__( 'Tables, options, cron events, meta keys, uploads folders, roles and capabilities left by deleted plugins, with the owner, confidence and evidence. Filter by plugin slug (empty string for unknown items), type or confidence.', 'axle-leftovers' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin'     => array( 'type' => 'string' ),
					'type'       => array(
						'type' => 'string',
						'enum' => $types,
					),
					'confidence' => array(
						'type' => 'string',
						'enum' => $levels,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'  => 'array',
				'items' => $item,
			),
			array( $this, 'list_leftovers' ),
			$permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'get-item',
			__( 'Get one leftover', 'axle-leftovers' ),
			__( 'One listed item with its evidence, size and reference check result.', 'axle-leftovers' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'type' => array(
						'type' => 'string',
						'enum' => $types,
					),
					'name' => array( 'type' => 'string' ),
				),
				'required'             => array( 'type', 'name' ),
				'additionalProperties' => false,
			),
			$item,
			array( $this, 'get_item' ),
			$permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'list-trash',
			__( 'List the trash', 'axle-leftovers' ),
			__( 'Items in the Leftovers trash, with their batch, owner, size and export.', 'axle-leftovers' ),
			array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			$rows,
			array( $this, 'list_trash' ),
			$permission,
			array(
				'readonly'   => true,
				'idempotent' => true,
			)
		);

		$this->ability(
			'trash-items',
			__( 'Move leftovers to the trash', 'axle-leftovers' ),
			__( 'Exports, then moves the items to the trash as one batch. Refuses items in use, items active code references, and Guess or Unknown items unless each name is repeated in confirm_names.', 'axle-leftovers' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'items'         => array(
						'type'     => 'array',
						'minItems' => 1,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'type' => array(
									'type' => 'string',
									'enum' => $types,
								),
								'name' => array( 'type' => 'string' ),
							),
							'required'             => array( 'type', 'name' ),
							'additionalProperties' => false,
						),
					),
					'confirm_names' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'             => array( 'items' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'batch' => array( 'type' => 'string' ),
					'items' => $rows,
				),
			),
			array( $this, 'trash_items' ),
			$permission,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			)
		);

		$this->ability(
			'restore-items',
			__( 'Restore from the trash', 'axle-leftovers' ),
			__( 'Puts a trashed batch, or single trashed items, back where they were.', 'axle-leftovers' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'batch' => array( 'type' => 'string' ),
					'items' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'restored' => $rows,
					'failed'   => array( 'type' => 'array' ),
				),
			),
			array( $this, 'restore_items' ),
			$permission,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'purge-trash',
			__( 'Empty the trash', 'axle-leftovers' ),
			__( 'Deletes trashed items for good: all of them, one batch, or those trashed more than older_than days ago.', 'axle-leftovers' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'older_than' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'batch'      => array( 'type' => 'string' ),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array( 'purged' => $rows ),
			),
			array( $this, 'purge_trash' ),
			$permission,
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'rescan',
			__( 'Scan for leftovers', 'axle-leftovers' ),
			__( 'Runs the scan for up to 20 seconds; WP-Cron finishes the rest. rescan_signatures reads every installed plugin\'s code again.', 'axle-leftovers' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'rescan_signatures' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'finished' => array( 'type' => 'boolean' ),
					'items'    => array( 'type' => 'integer' ),
				),
			),
			array( $this, 'rescan' ),
			$permission,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'lookup-item',
			__( 'Look up an Unknown item online', 'axle-leftovers' ),
			__( 'Searches the code of every WordPress.org plugin for the name of a listed leftover with no owner, through veloria.dev, and returns the plugins that name it. Needs the online lookup turned on in Settings. Sends only the item name. Results are cached and requests are spaced out.', 'axle-leftovers' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'type'    => array( 'type' => 'string' ),
					'name'    => array( 'type' => 'string' ),
					'refresh' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'             => array( 'type', 'name' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'confidence' => array( 'type' => 'string' ),
					'total'      => array( 'type' => 'integer' ),
					'candidates' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'slug'     => array( 'type' => 'string' ),
								'name'     => array( 'type' => 'string' ),
								'installs' => array( 'type' => 'integer' ),
								'url'      => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			array( $this, 'lookup_item' ),
			$permission,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'download-dataset',
			__( 'Download the full signature list', 'axle-leftovers' ),
			__( 'Downloads the signatures of about 10,000 WordPress.org plugins from GitHub, checks the size, checksum and shape, and merges them with the bundled list. Needs the download turned on in Settings. Sends nothing from the site.', 'axle-leftovers' ),
			array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'built'       => array( 'type' => 'string' ),
					'count'       => array( 'type' => 'integer' ),
					'fetched_gmt' => array( 'type' => 'string' ),
					'size'        => array( 'type' => 'integer' ),
				),
			),
			array( $this, 'download_dataset' ),
			$permission,
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);
	}

	/**
	 * Looks an item up online.
	 *
	 * @param array $input type, name, refresh.
	 * @return array|WP_Error
	 */
	public function lookup_item( $input ) {
		$input  = (array) $input;
		$result = ( new Lookup() )->for_item( $this->scanner, (string) ( $input['type'] ?? '' ), (string) ( $input['name'] ?? '' ), ! empty( $input['refresh'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'confidence' => (string) $result['result']['confidence'],
			'total'      => (int) $result['result']['total'],
			'candidates' => array_values( (array) $result['result']['candidates'] ),
		);
	}

	/**
	 * Downloads the full signature list.
	 *
	 * @return array|WP_Error
	 */
	public function download_dataset() {
		return ( new DatasetUpdater() )->download();
	}

	/**
	 * Lists leftovers.
	 *
	 * @param array|null $input plugin, type, confidence.
	 * @return array
	 */
	public function list_leftovers( $input = array() ): array {
		$input   = (array) $input;
		$filters = array(
			'type'       => (string) ( $input['type'] ?? '' ),
			'confidence' => (string) ( $input['confidence'] ?? '' ),
		);
		if ( isset( $input['plugin'] ) ) {
			$filters['plugin'] = (string) $input['plugin'];
		}

		return $this->scanner->items( $filters );
	}

	/**
	 * One item.
	 *
	 * @param array $input type, name.
	 * @return array|WP_Error
	 */
	public function get_item( $input ) {
		$input = (array) $input;
		$item  = $this->scanner->item( (string) ( $input['type'] ?? '' ), (string) ( $input['name'] ?? '' ) );

		return $item ? $item : new WP_Error( 'axle_leftovers_not_listed', Messages::get( 'not_listed', (string) ( $input['name'] ?? '' ) ), array( 'status' => 404 ) );
	}

	/**
	 * The trash.
	 *
	 * @return array
	 */
	public function list_trash(): array {
		return array_map( array( Trash::class, 'public_row' ), $this->trash->rows( array( 'status' => 'trashed' ) ) );
	}

	/**
	 * Trashes items.
	 *
	 * @param array $input items, confirm_names.
	 * @return array|WP_Error
	 */
	public function trash_items( $input ) {
		$input = (array) $input;

		return $this->trash->trash( (array) ( $input['items'] ?? array() ), array( 'confirm_names' => (array) ( $input['confirm_names'] ?? array() ) ) );
	}

	/**
	 * Restores items.
	 *
	 * @param array $input batch, items.
	 * @return array|WP_Error
	 */
	public function restore_items( $input ) {
		$input = (array) $input;

		return $this->trash->restore( (string) ( $input['batch'] ?? '' ), (array) ( $input['items'] ?? array() ) );
	}

	/**
	 * Purges the trash.
	 *
	 * @param array|null $input older_than, batch.
	 * @return array|WP_Error
	 */
	public function purge_trash( $input = array() ) {
		$input = (array) $input;
		$args  = array( 'batch' => (string) ( $input['batch'] ?? '' ) );
		if ( isset( $input['older_than'] ) ) {
			$args['older_than'] = (int) $input['older_than'];
		}
		$result = $this->trash->purge( $args );

		return is_wp_error( $result ) ? $result : array( 'purged' => $result );
	}

	/**
	 * Scans.
	 *
	 * @param array|null $input rescan_signatures.
	 * @return array
	 */
	public function rescan( $input = array() ): array {
		$input = (array) $input;
		$this->scanner->start( ! empty( $input['rescan_signatures'] ) );
		$done = $this->scanner->step( 20.0 );

		return array(
			'finished' => $done,
			'items'    => count( $this->scanner->items() ),
		);
	}

	/**
	 * Registers one ability.
	 *
	 * @param string   $name        Name without the category.
	 * @param string   $label       Label.
	 * @param string   $description Description.
	 * @param array    $input       Input schema.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Callback.
	 * @param callable $permission  Permission callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, callable $permission, array $annotations ): void {
		wp_register_ability(
			self::CATEGORY . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => $permission,
				'meta'                => array(
					'annotations'  => $annotations,
					'show_in_rest' => true,
					// Clients such as the MCP adapter only list abilities marked public:
					// `public` from WordPress 7.1, `mcp.public` for the adapter on 6.9 and 7.0.
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}
}
