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
		$tones  = array(
			'certain' => 'good',
			'likely'  => 'info',
			'guess'   => 'warning',
			'unknown' => 'neutral',
		);

		return '<span class="axle-badge axle-badge--' . esc_attr( $tones[ $confidence ] ?? 'neutral' ) . ' alo-badge alo-badge-' . esc_attr( $confidence ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * A GMT date as relative time, with the exact time on hover.
	 *
	 * @param string $gmt MySQL datetime in GMT.
	 * @return string HTML.
	 */
	public static function time( string $gmt ): string {
		$time = '' === $gmt || 0 === strpos( $gmt, '0000' ) ? false : strtotime( $gmt . ' UTC' );

		return $time ? AxleUi::relative_time( (int) $time ) : '';
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
				case 'template':
					/* translators: %s: number of changes made to templates and template parts. */
					$parts[] = sprintf( _n( '%s template change', '%s template changes', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
					break;
				case 'globalstyles':
					/* translators: %s: number of global styles entries. */
					$parts[] = sprintf( _n( '%s global styles change', '%s global styles changes', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
					break;
				case 'customcss':
					/* translators: %s: number of custom CSS entries. */
					$parts[] = sprintf( _n( '%s custom CSS entry', '%s custom CSS entries', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
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
	 * How a plugin or theme was removed.
	 *
	 * @param array|null $entry Ledger entry.
	 * @param string     $kind  plugin or theme.
	 * @return string HTML.
	 */
	public static function removed( ?array $entry, string $kind = 'plugin' ): string {
		if ( ! $entry ) {
			return 'theme' === $kind ? esc_html__( 'Deleted before Leftovers was installed. Found from its name.', 'axle-leftovers' ) : esc_html__( 'Deleted before Leftovers was installed. Known from the bundled dataset.', 'axle-leftovers' );
		}
		$parts = array();
		if ( '' !== (string) $entry['version'] ) {
			/* translators: %s: version. */
			$parts[] = esc_html( sprintf( __( 'Last version %s.', 'axle-leftovers' ), $entry['version'] ) );
		}
		if ( null === $entry['removed_gmt'] ) {
			$parts[] = esc_html__( 'Still installed.', 'axle-leftovers' );
		} else {
			$how = array(
				'admin'   => __( 'from wp-admin', 'axle-leftovers' ),
				'cli'     => __( 'with WP-CLI', 'axle-leftovers' ),
				'outside' => __( 'outside WordPress (the folder disappeared)', 'axle-leftovers' ),
			);
			/* translators: %s: how it was deleted, such as "from wp-admin". */
			$parts[] = esc_html( sprintf( __( 'Deleted %s:', 'axle-leftovers' ), $how[ $entry['removed_how'] ] ?? $entry['removed_how'] ) ) . ' ' . self::time( (string) $entry['removed_gmt'] ) . '.';
		}

		return implode( ' ', $parts );
	}

	/**
	 * The evidence of an item as list items, with the time of each in relative form.
	 *
	 * @param array $evidence Evidence entries: text, and when (GMT) for some.
	 * @return string HTML.
	 */
	public static function evidence( array $evidence ): string {
		$out = '';
		foreach ( $evidence as $line ) {
			$out .= '<li>' . esc_html( (string) $line['text'] );
			if ( ! empty( $line['when'] ) ) {
				$out .= ' (' . self::time( (string) $line['when'] ) . ')';
			}
			$out .= '</li>';
		}

		return $out;
	}
}
