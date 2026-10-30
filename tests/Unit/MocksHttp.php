<?php
/**
 * Mocks WordPress HTTP through the pre_http_request filter. No test calls a real service.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers\Tests\Unit;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;

/**
 * Routes wp_safe_remote_get() and wp_safe_remote_post() into the
 * `pre_http_request` filter, like WordPress does, so a test answers requests
 * with a callback.
 */
trait MocksHttp {

	/**
	 * Requests seen, as array( method, url, args ).
	 *
	 * @var array<int, array>
	 */
	protected $requests = array();

	/**
	 * Transients, keyed by name.
	 *
	 * @var array<string, mixed>
	 */
	protected $transients = array();

	/**
	 * The current answer callback, and whether the filter expectation exists.
	 *
	 * @var callable|null
	 */
	private $http_handler = null;

	/**
	 * Whether the pre_http_request expectation is registered.
	 *
	 * @var bool
	 */
	private $http_ready = false;

	/**
	 * Answers every request with the callback's return value.
	 *
	 * @param callable $handler function ( string $method, string $url, array $args ): array|\WP_Error, a response with `body` and `response.code`.
	 */
	protected function mock_http( callable $handler ): void {
		$this->requests     = array();
		$this->http_handler = $handler;
		foreach ( array( 'post' => 'POST', 'get' => 'GET' ) as $verb => $method ) {
			Functions\when( 'wp_safe_remote_' . $verb )->alias(
				static function ( $url, $args = array() ) use ( $method ) {
					return apply_filters( 'pre_http_request', false, $args + array( 'method' => $method ), $url );
				}
			);
		}
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0;
			}
		);
		if ( ! $this->http_ready ) {
			$this->http_ready = true;
			Filters\expectApplied( 'pre_http_request' )->andReturnUsing(
				function ( $pre, $args, $url ) {
					$this->requests[] = array( $args['method'], $url, $args );

					return ( $this->http_handler )( $args['method'], $url, $args );
				}
			);
		}

		$this->transients = array();
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url, $protocols = null ) {
				return 0 === strpos( (string) $url, 'https://' ) ? (string) $url : '';
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return strtolower( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $text ) {
				return trim( strip_tags( (string) $text ) );
			}
		);
	}

	/**
	 * A JSON response.
	 *
	 * @param mixed $body Body, encoded.
	 * @param int   $code Status.
	 * @return array
	 */
	protected function json_response( $body, int $code = 200 ): array {
		return array(
			'body'     => (string) json_encode( $body ),
			'response' => array( 'code' => $code ),
		);
	}
}
