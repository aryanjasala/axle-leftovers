<?php
/**
 * User-facing messages from the engine.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Every refusal and error the engine can give, as sprintf templates, after
 * the `axle_leftovers_messages` filter.
 */
final class Messages {

	/**
	 * The templates.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$messages = array(
			/* translators: %s: item name. */
			'protected'          => __( '%s belongs to WordPress or is protected, so it is never trashed.', 'axle-leftovers' ),
			/* translators: %s: item name. */
			'not_listed'         => __( '%s is not in the scan results. Scan again and pick it from the list.', 'axle-leftovers' ),
			/* translators: %s: item name. */
			'missing'            => __( '%s does not exist any more.', 'axle-leftovers' ),
			/* translators: 1: item name, 2: plugin or theme name. */
			'in_use'             => __( '%1$s is in use: %2$s is installed.', 'axle-leftovers' ),
			/* translators: %s: item name. */
			'network'            => __( '%s belongs to the whole network. Only network admins can trash it.', 'axle-leftovers' ),
			/* translators: %s: item name. */
			'confirm'            => __( 'Type the name %s to confirm. The owner is not certain.', 'axle-leftovers' ),
			/* translators: 1: role, 2: user logins. */
			'role_users'         => __( 'Users still have the role %1$s: %2$s. Change their role first.', 'axle-leftovers' ),
			/* translators: 1: item name, 2: file paths. */
			'referenced'         => __( '%1$s is still used by active code: %2$s.', 'axle-leftovers' ),
			/* translators: %s: item name. */
			'filtered'           => __( 'A filter on this site does not allow trashing %s.', 'axle-leftovers' ),
			/* translators: %s: item type. */
			'bad_type'           => __( 'Unknown item type: %s.', 'axle-leftovers' ),
			/* translators: %s: item name. */
			'failed'             => __( 'Could not move %s to the trash. Nothing in this batch was changed.', 'axle-leftovers' ),
			/* translators: %s: item name. */
			'export_failed'      => __( 'Could not write the export of %s, so it was not trashed. Check that the uploads folder is writable.', 'axle-leftovers' ),
			/* translators: %s: item name. */
			'taken'              => __( 'Something named %s exists now. Rename or remove it, then restore again.', 'axle-leftovers' ),
			/* translators: %s: item name. */
			'gone'               => __( 'The trashed copy of %s is missing, so it cannot be restored.', 'axle-leftovers' ),
			/* translators: %s: folder name. */
			'unsafe'             => __( 'The folder %s is a link or outside uploads, so it is left alone.', 'axle-leftovers' ),
			'frozen'             => __( 'Axle Read-Only Mode has frozen this site. Trash, restore and purge are off until the freeze ends.', 'axle-leftovers' ),
			'nothing_selected'   => __( 'Pick at least one item.', 'axle-leftovers' ),
			'lookup_off'         => __( 'The online lookup is off. Turn it on in Settings first.', 'axle-leftovers' ),
			/* translators: %s: item name. */
			'lookup_owned'       => __( '%s already has an owner with better evidence than a guess, so it is not looked up.', 'axle-leftovers' ),
			/* translators: 1: item name, 2: number of characters. */
			'lookup_short'       => __( '%1$s is too short to look up: names under %2$s characters match too much code.', 'axle-leftovers' ),
			/* translators: %s: seconds. */
			'lookup_wait'        => __( 'The lookup service allows few requests. Try again in %s seconds.', 'axle-leftovers' ),
			/* translators: %s: reason. */
			'lookup_http'        => __( 'The lookup service did not answer as expected (%s).', 'axle-leftovers' ),
			'lookup_limited'     => __( 'The lookup service says too many requests were sent. Try again in a minute.', 'axle-leftovers' ),
			'lookup_bad'         => __( 'The lookup service sent an answer this plugin cannot read.', 'axle-leftovers' ),
			'lookup_failed'      => __( 'The lookup service could not run the search.', 'axle-leftovers' ),
			'lookup_pending'     => __( 'The search did not finish in time. Try again in a minute.', 'axle-leftovers' ),
			'dataset_off'        => __( 'Downloading the full list is off. Turn it on in Settings first.', 'axle-leftovers' ),
			/* translators: %s: reason. */
			'dataset_http'       => __( 'Could not download the full list (%s).', 'axle-leftovers' ),
			'dataset_large'      => __( 'The download is larger than the size limit, so it was discarded.', 'axle-leftovers' ),
			'dataset_checksum'   => __( 'The download does not match its checksum, so it was discarded.', 'axle-leftovers' ),
			'dataset_shape'      => __( 'The download is not a valid signature list, so it was discarded.', 'axle-leftovers' ),
			'dataset_unpack'     => __( 'The download could not be unpacked, so it was discarded.', 'axle-leftovers' ),
			'dataset_save'       => __( 'Could not save the list. Check that the uploads folder is writable.', 'axle-leftovers' ),
			'nothing_to_restore' => __( 'Nothing in the trash matches.', 'axle-leftovers' ),
		);

		/**
		 * Filters the messages of refusals and errors. Keep the placeholders.
		 *
		 * @param array $messages Key => sprintf template.
		 */
		$filtered = (array) apply_filters( 'axle_leftovers_messages', $messages );
		foreach ( $filtered as $key => $text ) {
			// A template must keep the placeholders of the one it replaces.
			if ( isset( $messages[ $key ] ) && is_string( $text ) && self::placeholders( $text ) === self::placeholders( $messages[ $key ] ) ) {
				$messages[ $key ] = $text;
			}
		}

		return $messages;
	}

	/**
	 * One message with its values filled in.
	 *
	 * @param string $key       Key.
	 * @param string ...$values Values.
	 * @return string
	 */
	public static function get( string $key, string ...$values ): string {
		$messages = self::all();
		$template = $messages[ $key ] ?? $key;
		if ( self::placeholders( $template ) > count( $values ) ) {
			return $template;
		}

		return vsprintf( $template, $values );
	}

	/**
	 * How many values a template takes.
	 *
	 * @param string $template Template.
	 * @return int
	 */
	private static function placeholders( string $template ): int {
		$count = 0;
		if ( preg_match_all( '/%(?:(\d+)\$)?[sd]/', $template, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$count = max( $count, ! empty( $match[1] ) ? (int) $match[1] : $count + 1 );
			}
		}

		return $count;
	}
}
