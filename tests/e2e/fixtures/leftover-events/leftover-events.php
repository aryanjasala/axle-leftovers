<?php
/**
 * Plugin Name: Leftover Events
 * Description: Test fixture for Axle Leftovers. Registers an event post type and a genre taxonomy, with no uninstall.php, so deleting it leaves its content behind.
 * Version: 1.0.0
 * Author: Aryan Jasala
 * License: GPL-2.0-or-later
 * Text Domain: leftover-events
 *
 * @package LeftoverEvents
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		register_post_type(
			'le_event',
			array(
				'label'  => 'Events',
				'public' => true,
			)
		);
		register_taxonomy(
			'le_genre',
			'le_event',
			array(
				'label'  => 'Genres',
				'public' => true,
			)
		);
	}
);
