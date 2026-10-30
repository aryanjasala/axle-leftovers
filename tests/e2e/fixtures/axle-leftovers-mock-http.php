<?php
/**
 * Plugin Name: Axle Leftovers mock HTTP
 * Description: Test-only: answers the online lookup (Veloria) and the full list download (raw.githubusercontent.com) through pre_http_request, so no test calls a real service. Every request it sees is logged in the option alo_e2e_http_log.
 *
 * @package AryanX\Axle\Leftovers
 */

// phpcs:ignoreFile

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$veloria = 0 === strpos( $url, 'https://veloria.dev/api/v1/search/' );
		$github  = 0 === strpos( $url, 'https://raw.githubusercontent.com/aryanjasala/axle-leftovers/signatures/' );
		if ( ! $veloria && ! $github ) {
			return $pre;
		}

		$log   = (array) get_option( 'alo_e2e_http_log', array() );
		$log[] = ( $args['method'] ?? 'GET' ) . ' ' . $url . ( ! empty( $args['body'] ) ? ' ' . $args['body'] : '' );
		update_option( 'alo_e2e_http_log', $log, false );

		$json = static function ( $body, int $code = 200 ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => $code, 'message' => '' ),
				'cookies'  => array(),
			);
		};

		if ( $veloria ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				$term = (string) ( json_decode( (string) $args['body'], true )['term'] ?? '' );
				update_option( 'alo_e2e_last_term', $term, false );
				return $json( array( 'id' => 'e2e00000-0000', 'status' => 'queued', 'term' => $term ) );
			}
			$term    = (string) get_option( 'alo_e2e_last_term' );
			$plugins = array();
			if ( false !== strpos( $term, 'lmx_unique_setting' ) ) {
				$plugins = array( array( 'slug' => 'acme-widgets', 'name' => 'Acme Widgets', 'active_installs' => 20000 ) );
			} elseif ( false !== strpos( $term, 'lmx_shared_setting' ) ) {
				$plugins = array(
					array( 'slug' => 'small-widgets', 'name' => 'Small Widgets', 'active_installs' => 300 ),
					array( 'slug' => 'big-widgets', 'name' => 'Big Widgets', 'active_installs' => 90000 ),
				);
			}
			return $json( array( 'id' => 'e2e00000-0000', 'status' => 'completed', 'term' => $term, 'results' => array( 'results' => $plugins, 'total' => 80000 ) ) );
		}

		// The full list: one plugin that names lmx_dataset_setting.
		$list = gzencode(
			wp_json_encode(
				array(
					'version' => 1,
					'built'   => '2026-10-01',
					'plugins' => array(
						'dataset-widgets' => array( 'name' => 'Dataset Widgets', 'o' => array( 'lmx_dataset_setting' ) ),
						'other-widgets'   => array( 'name' => 'Other Widgets', 't' => array( 'other_widgets_log' ) ),
						'third-widgets'   => array( 'name' => 'Third Widgets', 'c' => array( 'third_widgets_cron' ) ),
					),
				)
			)
		);
		if ( '.sha256' === substr( $url, -7 ) ) {
			$sum = get_option( 'alo_e2e_bad_checksum' ) ? str_repeat( '0', 64 ) : hash( 'sha256', $list );
			return array( 'headers' => array(), 'body' => $sum . "  signatures.json.gz\n", 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array() );
		}
		return array( 'headers' => array(), 'body' => $list, 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array() );
	},
	10,
	3
);

// Fast lookups in tests; the spacing test raises the interval through this option.
add_filter(
	'axle_leftovers_lookup_min_interval',
	static function () {
		return (int) get_option( 'alo_e2e_interval', 1 );
	}
);
add_filter( 'axle_leftovers_lookup_poll_interval', '__return_zero' );
