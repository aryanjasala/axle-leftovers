<?php
/**
 * Small HTML helpers shared by the screens.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Admin;

use AryanX\Axle\Leftovers\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Escaped HTML fragments: confidence badges, counts, sizes and dates.
 */
final class Display {

	/**
	 * A confidence badge.
	 *
	 * @param string $confidence Level.
	 * @return string HTML.
	 */
	public static function badge( string $confidence ): string {
		$levels = Settings::confidence_levels();
		$label  = $levels[ $confidence ] ?? $confidence;

		return '<span class="alo-badge alo-badge-' . esc_attr( $confidence ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * "2 tables, 12 options and 3 cron events".
	 *
	 * @param array<string, int> $counts Type => count.
	 * @return string Plain text.
	 */
	public static function count_list( array $counts ): string {
		$parts = array();
		foreach ( $counts as $type => $count ) {
			$count = (int) $count;
			switch ( $type ) {
				case 'table':
					/* translators: %s: number of tables. */
					$parts[] = sprintf( _n( '%s table', '%s tables', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
					break;
				case 'option':
					/* translators: %s: number of options. */
					$parts[] = sprintf( _n( '%s option', '%s options', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
					break;
				case 'sitemeta':
					/* translators: %s: number of network options. */
					$parts[] = sprintf( _n( '%s network option', '%s network options', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
					break;
				case 'cron':
					/* translators: %s: number of cron events. */
					$parts[] = sprintf( _n( '%s cron event', '%s cron events', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
					break;
				case 'folder':
					/* translators: %s: number of folders. */
					$parts[] = sprintf( _n( '%s uploads folder', '%s uploads folders', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
					break;
				case 'role':
					/* translators: %s: number of roles. */
					$parts[] = sprintf( _n( '%s role', '%s roles', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
					break;
				case 'cap':
					/* translators: %s: number of capabilities. */
					$parts[] = sprintf( _n( '%s capability', '%s capabilities', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
					break;
				default:
					/* translators: %s: number of meta keys. */
					$parts[] = sprintf( _n( '%s meta key', '%s meta keys', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
			}
		}

		if ( count( $parts ) < 2 ) {
			return implode( '', $parts );
		}
		$last = array_pop( $parts );

		/* translators: 1: comma-separated list, 2: last item. */
		return sprintf( __( '%1$s and %2$s', 'axle-leftovers' ), implode( ', ', $parts ), $last );
	}

	/**
	 * A size, or a dash for zero.
	 *
	 * @param int $bytes Bytes.
	 * @return string Plain text.
	 */
	public static function size( int $bytes ): string {
		return $bytes > 0 ? (string) size_format( $bytes, $bytes >= MB_IN_BYTES ? 1 : 0 ) : '0 B';
	}

	/**
	 * A GMT date in the site's format and time zone.
	 *
	 * @param string $gmt MySQL datetime in GMT.
	 * @return string Plain text.
	 */
	public static function date( string $gmt ): string {
		if ( '' === $gmt || 0 === strpos( $gmt, '0000' ) ) {
			return '';
		}
		$time = strtotime( $gmt . ' UTC' );

		return false === $time ? $gmt : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Translated type label.
	 *
	 * @param string $type Type.
	 * @return string Plain text.
	 */
	public static function type( string $type ): string {
		$types = Settings::item_types();

		return $types[ $type ] ?? $type;
	}

	/**
	 * How a plugin was removed.
	 *
	 * @param array|null $entry Ledger entry.
	 * @return string Plain text.
	 */
	public static function removed( ?array $entry ): string {
		if ( ! $entry ) {
			return __( 'Deleted before Leftovers was installed. Known from the bundled dataset.', 'axle-leftovers' );
		}
		$parts = array();
		if ( '' !== (string) $entry['version'] ) {
			/* translators: %s: version. */
			$parts[] = sprintf( __( 'Last version %s.', 'axle-leftovers' ), $entry['version'] );
		}
		if ( null === $entry['removed_gmt'] ) {
			$parts[] = __( 'Still installed.', 'axle-leftovers' );
		} else {
			$how = array(
				'admin'   => __( 'from wp-admin', 'axle-leftovers' ),
				'cli'     => __( 'with WP-CLI', 'axle-leftovers' ),
				'outside' => __( 'outside WordPress (the folder disappeared)', 'axle-leftovers' ),
			);
			/* translators: 1: date, 2: how, such as "from wp-admin". */
			$parts[] = sprintf( __( 'Deleted %1$s, %2$s.', 'axle-leftovers' ), self::date( (string) $entry['removed_gmt'] ), $how[ $entry['removed_how'] ] ?? $entry['removed_how'] );
		}

		return implode( ' ', $parts );
	}
}
