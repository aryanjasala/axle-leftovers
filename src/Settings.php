<?php
/**
 * Settings and every filtered default.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `axle_leftovers_settings` and runs each default through its filter,
 * clamping what comes back so a bad filter cannot break the plugin.
 */
final class Settings {

	public const OPTION = 'axle_leftovers_settings';

	public const DEFAULTS = array(
		'retention_days'  => 30,
		'export_max_mb'   => 200,
		'keep_exports'    => false,
		'runtime_ledger'  => true,
		'track_meta_keys' => true,
	);

	/**
	 * The stored settings merged over the defaults.
	 *
	 * @return array{retention_days: int, export_max_mb: int, keep_exports: bool, runtime_ledger: bool, track_meta_keys: bool}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return self::sanitize( $stored + self::DEFAULTS );
	}

	/**
	 * Cleans submitted or stored settings.
	 *
	 * @param array $input Raw values.
	 * @return array{retention_days: int, export_max_mb: int, keep_exports: bool, runtime_ledger: bool, track_meta_keys: bool}
	 */
	public static function sanitize( array $input ): array {
		return array(
			'retention_days'  => max( 1, min( 3650, (int) ( $input['retention_days'] ?? self::DEFAULTS['retention_days'] ) ) ),
			'export_max_mb'   => max( 0, min( 102400, (int) ( $input['export_max_mb'] ?? self::DEFAULTS['export_max_mb'] ) ) ),
			'keep_exports'    => ! empty( $input['keep_exports'] ),
			'runtime_ledger'  => ! empty( $input['runtime_ledger'] ),
			'track_meta_keys' => ! empty( $input['track_meta_keys'] ),
		);
	}

	/**
	 * Saves settings.
	 *
	 * @param array $input Raw values.
	 */
	public static function save( array $input ): void {
		update_option( self::OPTION, self::sanitize( $input ), true );
	}

	/**
	 * The capability for the screen, REST routes, abilities and CLI checks.
	 *
	 * @param bool $network Network-wide items.
	 * @return string
	 */
	public static function capability( bool $network = false ): string {
		$default = $network ? 'manage_network' : 'manage_options';

		/**
		 * Filters the capability needed to see and clean up leftovers.
		 *
		 * @param string $capability Default `manage_options`, or `manage_network` for network items.
		 * @param bool   $network    Whether this is for network-wide items.
		 */
		$capability = (string) apply_filters( 'axle_leftovers_capability', $default, $network );

		return '' !== trim( $capability ) ? $capability : $default;
	}

	/**
	 * Days a batch stays in the trash before the daily purge.
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		/**
		 * Filters how many days trashed items are kept.
		 *
		 * @param int $days Days, 1 to 3650. Default from the settings, 30.
		 */
		return max( 1, min( 3650, (int) apply_filters( 'axle_leftovers_retention_days', self::all()['retention_days'] ) ) );
	}

	/**
	 * Largest table that still gets an SQL export, in bytes.
	 *
	 * @return int
	 */
	public static function export_max_bytes(): int {
		/**
		 * Filters the size above which a table is only renamed, without an SQL export.
		 *
		 * @param int $bytes Bytes. Default 200 MB.
		 */
		return max( 0, (int) apply_filters( 'axle_leftovers_export_max_bytes', self::all()['export_max_mb'] * MB_IN_BYTES ) );
	}

	/**
	 * Whether purging keeps the export files.
	 *
	 * @return bool
	 */
	public static function keep_exports(): bool {
		/**
		 * Filters whether purging the trash keeps the export files.
		 *
		 * @param bool $keep Default from the settings, false.
		 */
		return (bool) apply_filters( 'axle_leftovers_keep_exports', self::all()['keep_exports'] );
	}

	/**
	 * Whether the runtime ledger records who creates things.
	 *
	 * @return bool
	 */
	public static function runtime_ledger(): bool {
		return self::all()['runtime_ledger'];
	}

	/**
	 * Whether new meta keys are recorded with their owner.
	 *
	 * @param string $meta_type post, user, term or comment.
	 * @return bool
	 */
	public static function track_meta_keys( string $meta_type ): bool {
		$all = self::all();

		/**
		 * Filters whether first-seen meta keys of a type are recorded.
		 *
		 * @param bool   $track     Default from the settings, true.
		 * @param string $meta_type post, user, term or comment.
		 */
		return (bool) apply_filters( 'axle_leftovers_track_meta_keys', $all['runtime_ledger'] && $all['track_meta_keys'], $meta_type );
	}

	/**
	 * Rows read per scan step.
	 *
	 * @return int
	 */
	public static function scan_batch_size(): int {
		/**
		 * Filters how many rows one scan step reads from a table (options, meta).
		 *
		 * @param int $size Rows, 100 to 1000000. Default 20000.
		 */
		return max( 100, min( 1000000, (int) apply_filters( 'axle_leftovers_scan_batch_size', 20000 ) ) );
	}

	/**
	 * Meta rows copied, deleted or re-inserted per query.
	 *
	 * @return int
	 */
	public static function meta_batch_size(): int {
		/**
		 * Filters how many meta rows are copied, deleted or restored per query.
		 *
		 * @param int $size Rows, 10 to 10000. Default 1000.
		 */
		return max( 10, min( 10000, (int) apply_filters( 'axle_leftovers_meta_batch_size', 1000 ) ) );
	}

	/**
	 * Whether a confidence level needs the item name typed before trashing.
	 *
	 * @param string $confidence certain, likely, guess or unknown.
	 * @return bool
	 */
	public static function require_typed_confirmation( string $confidence ): bool {
		$default = in_array( $confidence, array( 'guess', 'unknown' ), true );

		/**
		 * Filters whether trashing items of a confidence level needs the name typed.
		 *
		 * Guess and Unknown always need it: the filter can add levels, not remove those.
		 *
		 * @param bool   $required   Default true for guess and unknown.
		 * @param string $confidence certain, likely, guess or unknown.
		 */
		$required = (bool) apply_filters( 'axle_leftovers_require_typed_confirmation', $default, $confidence );

		return $default || $required;
	}

	/**
	 * What uninstall does with items still in the trash.
	 *
	 * @return string purge or restore.
	 */
	public static function uninstall_trash_policy(): string {
		/**
		 * Filters what uninstalling does with the trash: `purge` deletes it, `restore` puts everything back.
		 *
		 * @param string $policy Default `purge`.
		 */
		$policy = apply_filters( 'axle_leftovers_uninstall_trash_policy', 'purge' );

		return 'restore' === $policy ? 'restore' : 'purge';
	}

	/**
	 * Confidence levels and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function confidence_levels(): array {
		$levels = array(
			'certain' => __( 'Certain', 'axle-leftovers' ),
			'likely'  => __( 'Likely', 'axle-leftovers' ),
			'guess'   => __( 'Guess', 'axle-leftovers' ),
			'unknown' => __( 'Unknown', 'axle-leftovers' ),
		);

		/**
		 * Filters the labels of the confidence levels. The four keys are fixed.
		 *
		 * @param array $levels Level => label.
		 */
		$filtered = (array) apply_filters( 'axle_leftovers_confidence_levels', $levels );
		foreach ( array_keys( $levels ) as $key ) {
			if ( isset( $filtered[ $key ] ) && is_string( $filtered[ $key ] ) && '' !== $filtered[ $key ] ) {
				$levels[ $key ] = $filtered[ $key ];
			}
		}

		return $levels;
	}

	/**
	 * Item types and their labels.
	 *
	 * @return array<string, string>
	 */
	public static function item_types(): array {
		$types = array(
			'table'       => __( 'Table', 'axle-leftovers' ),
			'option'      => __( 'Option', 'axle-leftovers' ),
			'cron'        => __( 'Cron event', 'axle-leftovers' ),
			'postmeta'    => __( 'Post meta', 'axle-leftovers' ),
			'usermeta'    => __( 'User meta', 'axle-leftovers' ),
			'termmeta'    => __( 'Term meta', 'axle-leftovers' ),
			'commentmeta' => __( 'Comment meta', 'axle-leftovers' ),
			'folder'      => __( 'Uploads folder', 'axle-leftovers' ),
			'role'        => __( 'Role', 'axle-leftovers' ),
			'cap'         => __( 'Capability', 'axle-leftovers' ),
			'sitemeta'    => __( 'Network option', 'axle-leftovers' ),
		);

		/**
		 * Filters which item types are scanned and listed. Remove a type to skip it.
		 * Only the built-in types can be scanned; unknown keys are dropped.
		 *
		 * @param array $types Type => label.
		 */
		$filtered = (array) apply_filters( 'axle_leftovers_item_types', $types );
		$out      = array();
		foreach ( $filtered as $type => $label ) {
			if ( isset( $types[ $type ] ) ) {
				$out[ $type ] = is_string( $label ) && '' !== $label ? $label : $types[ $type ];
			}
		}

		return $out;
	}
}
