<?php
/**
 * Personal data export, erase and the privacy policy text.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Trash batches and exports can hold personal data (form entry tables, user
 * meta). The exporter lists the batches a user trashed. The eraser purges
 * every trashed batch that holds the user's meta, since the core tools cannot
 * read inside an export, and removes the user from the trash records.
 */
final class Privacy implements Service {

	public const ID = 'axle-leftovers';

	/**
	 * The trash.
	 *
	 * @var Trash
	 */
	private Trash $trash;

	/**
	 * Sets up the privacy tools.
	 *
	 * @param Trash $trash Trash.
	 */
	public function __construct( Trash $trash ) {
		$this->trash = $trash;
	}

	/**
	 * Adds the hooks.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'policy_text' ) );
	}

	/**
	 * Registers the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public function add_exporter( $exporters ): array {
		$exporters             = (array) $exporters;
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Leftovers trash', 'axle-leftovers' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Registers the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public function add_eraser( $erasers ): array {
		$erasers             = (array) $erasers;
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Leftovers trash', 'axle-leftovers' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * The trash items a user moved to the trash.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$user = get_user_by( 'email', (string) $email );
		$data = array();

		if ( $user ) {
			foreach ( $this->trash->rows( array( 'trashed_by' => (int) $user->ID ) ) as $row ) {
				$data[] = array(
					'group_id'    => 'axle-leftovers-trash',
					'group_label' => __( 'Leftovers trash', 'axle-leftovers' ),
					'item_id'     => 'axle-leftovers-trash-' . $row['id'],
					'data'        => array(
						array(
							'name'  => __( 'Item', 'axle-leftovers' ),
							'value' => $row['item_type'] . ': ' . $row['name'],
						),
						array(
							'name'  => __( 'Trashed (UTC)', 'axle-leftovers' ),
							'value' => (string) $row['trashed_gmt'],
						),
						array(
							'name'  => __( 'Status', 'axle-leftovers' ),
							'value' => (string) $row['status'],
						),
					),
				);
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Purges trashed batches with the user's meta, and removes the user from trash records.
	 *
	 * @param string $email Email address.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email ): array {
		global $wpdb;

		$user = get_user_by( 'email', (string) $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$id      = (int) $user->ID;
		$batches = array();
		foreach ( $this->trash->rows(
			array(
				'status'    => 'trashed',
				'item_type' => 'usermeta',
			)
		) as $row ) {
			if ( in_array( $id, array_map( 'intval', (array) ( $row['payload']['user_ids'] ?? array() ) ), true ) ) {
				$batches[ (string) $row['batch_id'] ] = true;
			}
		}

		$purged = 0;
		foreach ( array_keys( $batches ) as $batch ) {
			$result  = $this->trash->purge( array( 'batch' => $batch ) );
			$purged += is_array( $result ) ? count( $result ) : 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$anonymized = (int) $wpdb->update( Schema::table( 'trash' ), array( 'trashed_by' => 0 ), array( 'trashed_by' => $id ) );

		$messages = array();
		if ( $purged > 0 ) {
			/* translators: %d: number of trashed items. */
			$messages[] = sprintf( _n( 'Deleted %d trashed item that held the user\'s meta.', 'Deleted %d trashed items that held the user\'s meta.', $purged, 'axle-leftovers' ), $purged );
		}

		return array(
			'items_removed'  => $purged > 0 || $anonymized > 0,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Suggested text for Settings > Privacy.
	 */
	public function policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$text = sprintf(
			/* translators: %d: number of days. */
			__( 'When an administrator cleans up data left by deleted plugins with Axle Leftovers, the data is exported and kept in a trash so it can be restored. It can include personal data, such as form entries or user settings. Trashed data and exports are not public and are deleted after %d days. The site records which administrator moved each item to the trash. Nothing is sent to other sites, unless an administrator turns on the online lookup (it sends the name of one item to veloria.dev when someone clicks Look it up) or the full list download (it fetches a file from GitHub and sends nothing from this site). Both are off by default.', 'axle-leftovers' ),
			Settings::retention_days()
		);

		wp_add_privacy_policy_content( __( 'Axle Leftovers', 'axle-leftovers' ), '<p>' . esc_html( $text ) . '</p>' );
	}
}
