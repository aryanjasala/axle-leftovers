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
				case 'posttype':
					/* translators: %s: number of posts left by a post type nothing registers. */
					$parts[] = sprintf( _n( '%s post of an unregistered post type', '%s posts of unregistered post types', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
					break;
				case 'taxonomy':
					/* translators: %s: number of terms left by a taxonomy nothing registers. */
					$parts[] = sprintf( _n( '%s term of an unregistered taxonomy', '%s terms of unregistered taxonomies', $count, 'axle-leftovers' ), number_format_i18n( $count ) );
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
	 * What a post type or taxonomy holds, so a person can recognise it: counts per
	 * status, the date range, what hangs off the posts, and a few titles.
	 *
	 * @param array $item    The item, with its detail.
	 * @param array $summary From Content::summary().
	 * @return string HTML.
	 */
	public static function content_summary( array $item, array $summary ): string {
		$out   = '<details class="alo-sample"><summary>' . esc_html__( 'What is in it', 'axle-leftovers' ) . '</summary>';
		$parts = array();
		if ( 'posttype' === $item['type'] ) {
			$statuses = $summary['statuses'];
			arsort( $statuses );
			foreach ( $statuses as $status => $count ) {
				/* translators: 1: number of posts, 2: post status such as publish or draft. */
				$parts[] = sprintf( __( '%1$s %2$s', 'axle-leftovers' ), number_format_i18n( (int) $count ), (string) $status );
			}
			if ( $parts ) {
				$out .= '<p class="alo-statuses">' . esc_html( implode( ', ', $parts ) ) . '.</p>';
			}
			if ( '' !== $summary['first'] ) {
				$out .= '<p class="axle-muted alo-range">' . esc_html__( 'Posts dated', 'axle-leftovers' ) . ' ' . self::time( $summary['first'] ) . ' ' . esc_html__( 'to', 'axle-leftovers' ) . ' ' . self::time( $summary['last'] ) . '.</p>';
			}
			$moves = array(
				/* translators: %s: number of meta rows. */
				sprintf( _n( '%s meta row', '%s meta rows', $summary['meta'], 'axle-leftovers' ), number_format_i18n( $summary['meta'] ) ),
				/* translators: %s: number of comments. */
				sprintf( _n( '%s comment', '%s comments', $summary['comments'], 'axle-leftovers' ), number_format_i18n( $summary['comments'] ) ),
				/* translators: %s: number of term relationships. */
				sprintf( _n( '%s term relationship', '%s term relationships', $summary['relations'], 'axle-leftovers' ), number_format_i18n( $summary['relations'] ) ),
			);
			/* translators: 1: list such as "6 meta rows, 1 comment" and the last item. */
			$out .= '<p class="axle-muted alo-attached">' . esc_html( sprintf( __( 'Also moves %1$s, %2$s and %3$s.', 'axle-leftovers' ), $moves[0], $moves[1], $moves[2] ) ) . '</p>';
		} else {
			/* translators: %s: number of posts that use the terms. */
			$out .= '<p class="axle-muted alo-attached">' . esc_html( sprintf( __( 'Used by %s posts. Also moves the term meta and the links to those posts.', 'axle-leftovers' ), number_format_i18n( $summary['objects'] ) ) ) . '</p>';
		}
		if ( $summary['sample'] ) {
			$out .= '<ul class="alo-titles">';
			foreach ( $summary['sample'] as $row ) {
				$out .= '<li>' . esc_html( $row['title'] );
				if ( '' !== $row['date'] ) {
					$out .= ' <span class="axle-muted">(' . esc_html( $row['status'] ) . ', ' . self::time( $row['date'] ) . ')</span>';
				}
				$out .= '</li>';
			}
			$out .= '</ul>';
		}

		return $out . '</details>';
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
