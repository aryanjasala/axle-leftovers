<?php
/**
 * A stand-in for WP_Term with only the field the trash reads.
 *
 * @package AryanX\Axle\Leftovers
 */

// phpcs:ignoreFile

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public $term_taxonomy_id = 0;

		public function __construct( $term_taxonomy_id = 0 ) {
			$this->term_taxonomy_id = $term_taxonomy_id;
		}
	}
}
