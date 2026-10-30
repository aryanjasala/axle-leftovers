<?php
/**
 * WP-CLI: wp axle-leftovers.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Cli;

use AryanX\Axle\Leftovers\Ledger;
use AryanX\Axle\Leftovers\Scanner;
use AryanX\Axle\Leftovers\Signature;
use AryanX\Axle\Leftovers\Trash;
use WP_CLI;
use WP_CLI\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Find what deleted plugins left behind and clean it up safely, with undo.
 */
final class Command {

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
	 * Sets up the command.
	 *
	 * @param Scanner $scanner Scanner.
	 * @param Trash   $trash   Trash.
	 * @param Ledger  $ledger  Ledger.
	 */
	public function __construct( Scanner $scanner, Trash $trash, Ledger $ledger ) {
		$this->scanner = $scanner;
		$this->trash   = $trash;
		$this->ledger  = $ledger;
	}

	/**
	 * Lists leftovers from the last scan.
	 *
	 * ## OPTIONS
	 *
	 * [--plugin=<slug>]
	 * : Only items of this owner. Use --plugin= (empty) for items with no owner.
	 *
	 * [--type=<type>]
	 * : table, option, cron, postmeta, usermeta, termmeta, commentmeta, folder, role, cap or sitemeta.
	 *
	 * [--confidence=<level>]
	 * : certain, likely, guess or unknown.
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-leftovers list --plugin=contact-form-7
	 *     wp axle-leftovers list --confidence=unknown --format=json
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function list_( $args, $assoc_args ): void {
		$this->ensure_scanned();
		$filters = array(
			'type'       => (string) ( $assoc_args['type'] ?? '' ),
			'confidence' => (string) ( $assoc_args['confidence'] ?? '' ),
		);
		if ( array_key_exists( 'plugin', $assoc_args ) ) {
			$filters['plugin'] = true === $assoc_args['plugin'] ? '' : (string) $assoc_args['plugin'];
		}

		$rows = array();
		foreach ( $this->scanner->items( $filters ) as $item ) {
			$rows[] = array(
				'id'         => $item['id'],
				'type'       => $item['type'],
				'name'       => $item['name'],
				'owner'      => $item['owner'],
				'confidence' => $item['confidence'],
				'rows'       => $item['rows'],
				'bytes'      => $item['bytes'],
				'autoload'   => $item['autoload'] ? 'yes' : 'no',
				'used_by'    => implode( ', ', $item['refs'] ),
			);
		}

		Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'type', 'name', 'owner', 'confidence', 'rows', 'bytes', 'autoload', 'used_by' ) );
	}

	/**
	 * Shows one item with its evidence.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Item type.
	 *
	 * <name>
	 * : Item name, tables with the prefix.
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function show( $args, $assoc_args ): void {
		$this->ensure_scanned();
		$item = $this->scanner->item( (string) $args[0], (string) $args[1] );
		if ( ! $item ) {
			WP_CLI::error( sprintf( '%s %s is not a listed leftover. Run `wp axle-leftovers scan` first, or it is core, protected or in use.', $args[0], $args[1] ) );
			return;
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' !== $format ) {
			WP_CLI::print_value( $item, array( 'format' => $format ) );
			return;
		}

		$item['evidence'] = implode( "\n", array_column( $item['evidence'], 'text' ) );
		$item['refs']     = implode( ', ', $item['refs'] );
		$item['autoload'] = $item['autoload'] ? 'yes' : 'no';
		$rows             = array();
		foreach ( $item as $field => $value ) {
			$rows[] = array(
				'field' => $field,
				'value' => is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ),
			);
		}
		Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}

	/**
	 * Scans for leftovers now.
	 *
	 * ## OPTIONS
	 *
	 * [--rescan-signatures]
	 * : Read every installed plugin's code again.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function scan( $args, $assoc_args ): void {
		$this->scanner->run_all( ! empty( $assoc_args['rescan-signatures'] ) );
		$items = $this->scanner->items();
		WP_CLI::success( sprintf( 'Found %d leftovers from %d owners.', count( $items ), count( array_unique( array_filter( array_column( $items, 'owner' ) ) ) ) ) );
	}

	/**
	 * Exports items without trashing them.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Item type.
	 *
	 * <name>...
	 * : One or more names.
	 *
	 * [--output=<folder>]
	 * : Folder for the files. Default: a new folder in the plugin's exports folder.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function export( $args, $assoc_args ): void {
		$this->ensure_scanned();
		$type   = (string) array_shift( $args );
		$output = isset( $assoc_args['output'] ) ? untrailingslashit( (string) $assoc_args['output'] ) : '';
		$files  = $this->trash->export_only(
			array_map(
				static function ( $name ) use ( $type ): array {
					return array(
						'type' => $type,
						'name' => (string) $name,
					);
				},
				$args
			),
			$output
		);
		if ( is_wp_error( $files ) ) {
			WP_CLI::error( $files->get_error_message() );
			return;
		}
		foreach ( $files as $file ) {
			WP_CLI::log( $file );
		}
		WP_CLI::success( sprintf( 'Exported %d items.', count( $files ) ) );
	}

	/**
	 * Moves items to the trash, lists the trash, or purges it.
	 *
	 * ## OPTIONS
	 *
	 * [<type-or-action>]
	 * : An item type, or `list` or `purge`.
	 *
	 * [<name>...]
	 * : Item names. Typing a name confirms Guess and Unknown items.
	 *
	 * [--plugin=<slug>]
	 * : Trash every listed item of this owner (of the given type, if any). Guess and Unknown items still need their names typed.
	 *
	 * [--older-than=<days>]
	 * : With `purge`: only items trashed more than this many days ago. 0 purges everything.
	 *
	 * [--yes]
	 * : Do not ask.
	 *
	 * [--format=<format>]
	 * : With `list`: table, csv, json, yaml, ids or count.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp axle-leftovers trash table wp_cf7_entries option cf7_settings --yes
	 *     wp axle-leftovers trash --plugin=contact-form-7 --yes
	 *     wp axle-leftovers trash list
	 *     wp axle-leftovers trash purge --older-than=30 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function trash( $args, $assoc_args ): void {
		$first = (string) ( $args[0] ?? '' );
		if ( 'list' === $first ) {
			$this->trash_list( $assoc_args );
			return;
		}
		if ( 'purge' === $first ) {
			$this->trash_purge( $assoc_args );
			return;
		}

		$this->ensure_scanned();
		$items   = array();
		$confirm = array();
		if ( isset( $assoc_args['plugin'] ) ) {
			$filters = array( 'plugin' => (string) $assoc_args['plugin'] );
			if ( '' !== $first ) {
				$filters['type'] = $first;
			}
			foreach ( $this->scanner->items( $filters ) as $item ) {
				$items[] = array(
					'type' => $item['type'],
					'name' => $item['name'],
				);
			}
			$confirm = array_slice( $args, 1 );
		} else {
			// Pairs or a type followed by names: `table a b option c`.
			$type = '';
			foreach ( $args as $arg ) {
				if ( in_array( $arg, array( 'table', 'option', 'cron', 'postmeta', 'usermeta', 'termmeta', 'commentmeta', 'folder', 'role', 'cap', 'sitemeta' ), true ) ) {
					$type = (string) $arg;
					continue;
				}
				if ( '' === $type ) {
					WP_CLI::error( 'Give an item type before the names, such as `table wp_acme_log`.' );
					return;
				}
				$items[]   = array(
					'type' => $type,
					'name' => (string) $arg,
				);
				$confirm[] = (string) $arg;
			}
		}
		if ( ! $items ) {
			WP_CLI::error( 'Nothing to trash.' );
			return;
		}

		WP_CLI::confirm( sprintf( 'Move %d items to the trash?', count( $items ) ), $assoc_args );
		$result = $this->trash->trash(
			$items,
			array(
				'confirm_names' => $confirm,
				'check_caps'    => false,
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}
		WP_CLI::success( sprintf( 'Moved %d items to the trash as batch %s. Undo: wp axle-leftovers restore %s', count( $result['items'] ), $result['batch'], $result['batch'] ) );
	}

	/**
	 * Restores a batch or one trashed item.
	 *
	 * ## OPTIONS
	 *
	 * <batch-or-item>
	 * : A batch ID, or a trash item ID (a number).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function restore( $args, $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI passes both.
		$target = (string) $args[0];
		$result = ctype_digit( $target ) ? $this->trash->restore( '', array( (int) $target ) ) : $this->trash->restore( $target );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}
		foreach ( $result['failed'] as $failed ) {
			WP_CLI::warning( $failed['message'] );
		}
		if ( ! $result['restored'] ) {
			WP_CLI::error( 'Nothing was restored.' );
			return;
		}
		WP_CLI::success( sprintf( 'Restored %d items.', count( $result['restored'] ) ) );
	}

	/**
	 * Shows the plugins Leftovers has seen.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list or show.
	 *
	 * [<slug>]
	 * : With `show`: the plugin.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function ledger( $args, $assoc_args ): void {
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'show' === ( $args[0] ?? '' ) ) {
			$entry = $this->ledger->get( (string) ( $args[1] ?? '' ) );
			if ( ! $entry ) {
				WP_CLI::error( 'No ledger entry for that slug.' );
				return;
			}
			WP_CLI::print_value( $entry, array( 'format' => 'table' === $format ? 'yaml' : $format ) );
			return;
		}

		$rows = array();
		foreach ( $this->ledger->all() as $entry ) {
			$signature = is_array( $entry['signature'] ) ? $entry['signature'] : Signature::blank();
			$rows[]    = array(
				'slug'       => $entry['slug'],
				'name'       => $entry['name'],
				'version'    => $entry['version'],
				'first_seen' => $entry['first_seen_gmt'],
				'removed'    => (string) $entry['removed_gmt'],
				'how'        => $entry['removed_how'],
				'sources'    => implode( ',', $entry['sources'] ),
				'names'      => array_sum( array_map( 'count', $signature ) ),
			);
		}
		Utils\format_items( $format, $rows, array( 'slug', 'name', 'version', 'first_seen', 'removed', 'how', 'sources', 'names' ) );
	}

	/**
	 * `trash list`.
	 *
	 * @param array $assoc_args Options.
	 */
	private function trash_list( array $assoc_args ): void {
		$rows = array_map( array( Trash::class, 'public_row' ), $this->trash->rows( array( 'status' => 'trashed' ) ) );
		Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'id', 'batch', 'type', 'name', 'owner', 'bytes', 'trashed_gmt' ) );
	}

	/**
	 * `trash purge`.
	 *
	 * @param array $assoc_args Options.
	 */
	private function trash_purge( array $assoc_args ): void {
		WP_CLI::confirm( 'Delete trashed items for good?', $assoc_args );
		$args = array();
		if ( isset( $assoc_args['older-than'] ) ) {
			$args['older_than'] = max( 0, (int) $assoc_args['older-than'] );
		}
		$result = $this->trash->purge( $args );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}
		WP_CLI::success( sprintf( 'Purged %d items.', count( $result ) ) );
	}

	/**
	 * Runs a scan first when there are no results yet, or they are out of date.
	 */
	private function ensure_scanned(): void {
		$this->scanner->detect_removed_outside();
		if ( $this->scanner->needs_scan() || $this->scanner->is_running() ) {
			$this->scanner->run_all();
		}
	}
}
