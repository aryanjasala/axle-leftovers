<?php
/**
 * The full signature list: download, verify, store.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads signatures.json.gz (the top ~10,000 WordPress.org plugins, built
 * monthly by a GitHub Actions workflow onto the `signatures` branch) from
 * raw.githubusercontent.com, checks it, and keeps it in
 * uploads/axle-leftovers/dataset. Opt-in, and only from an admin action,
 * WP-CLI or an ability. Dataset merges it with the bundled list, which stays
 * the fallback.
 */
class DatasetUpdater {

	public const OPTION = 'axle_leftovers_dataset_meta';
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- A data file (JSON), not code, images or styles. Documented under External services in readme.txt.
	public const URL  = 'https://raw.githubusercontent.com/aryanjasala/axle-leftovers/signatures/signatures.json.gz';
	public const FILE = 'signatures.json.gz';

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	private Storage $storage;

	/**
	 * Sets up the updater.
	 *
	 * @param Storage|null $storage Storage. Default: uploads/axle-leftovers.
	 */
	public function __construct( ?Storage $storage = null ) {
		$this->storage = $storage ?? new Storage();
	}

	/**
	 * The file URL.
	 *
	 * @return string
	 */
	public static function url(): string {
		/**
		 * Filters where the full signature list is downloaded from. A checksum
		 * file must sit next to it, at the same URL plus `.sha256`.
		 *
		 * @param string $url HTTPS URL of the gzip file.
		 */
		$url = esc_url_raw( (string) apply_filters( 'axle_leftovers_dataset_url', self::URL ), array( 'https' ) );

		return '' !== $url ? $url : self::URL;
	}

	/**
	 * Largest download accepted.
	 *
	 * @return int Bytes.
	 */
	public static function max_bytes(): int {
		/**
		 * Filters the largest download accepted, and so the largest unpacked
		 * list (ten times this).
		 *
		 * @param int $bytes 100 KB to 100 MB. Default 15 MB.
		 */
		return max( 102400, min( 104857600, (int) apply_filters( 'axle_leftovers_dataset_max_bytes', 15 * MB_IN_BYTES ) ) );
	}

	/**
	 * What is known about the stored list.
	 *
	 * @return array|null built, count, fetched_gmt, size, sha256.
	 */
	public static function meta(): ?array {
		$meta = get_option( self::OPTION, array() );

		return is_array( $meta ) && ! empty( $meta['built'] ) ? $meta : null;
	}

	/**
	 * Where the stored list is.
	 *
	 * @return string
	 */
	public function file(): string {
		return $this->storage->dir( 'dataset' ) . '/' . self::FILE;
	}

	/**
	 * The stored list's plugins, or an empty array.
	 *
	 * @return array<string, array>
	 */
	public function plugins(): array {
		if ( null === self::meta() || ! function_exists( 'gzdecode' ) ) {
			return array();
		}
		$raw  = $this->storage->get( $this->file() );
		$json = '' !== $raw ? @gzdecode( $raw, self::max_bytes() * 10 ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gzdecode() warns on corrupt data; false is handled.
		$data = false !== $json ? json_decode( $json, true ) : null;

		return is_array( $data ) && isset( $data['plugins'] ) && is_array( $data['plugins'] ) ? $data['plugins'] : array();
	}

	/**
	 * Downloads, checks and stores the list.
	 *
	 * @return array|WP_Error The new meta.
	 */
	public function download() {
		if ( ! Settings::remote_dataset() ) {
			return new WP_Error( 'axle_leftovers_dataset_off', Messages::get( 'dataset_off' ) );
		}
		if ( ! function_exists( 'gzdecode' ) ) {
			return new WP_Error( 'axle_leftovers_dataset_unpack', Messages::get( 'dataset_unpack' ) );
		}

		$url = self::url();
		$max = self::max_bytes();

		$sum = wp_safe_remote_get(
			$url . '.sha256',
			array(
				'timeout'             => 15, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Admin action or CLI only, never a page load.
				'limit_response_size' => 1024,
			)
		);
		if ( is_wp_error( $sum ) || 200 !== (int) wp_remote_retrieve_response_code( $sum ) ) {
			return new WP_Error( 'axle_leftovers_dataset_http', Messages::get( 'dataset_http', is_wp_error( $sum ) ? $sum->get_error_message() : (string) wp_remote_retrieve_response_code( $sum ) ) );
		}
		// The checksum file is `<sha256>  <name>` like sha256sum prints it.
		if ( 1 !== preg_match( '/^\s*([a-f0-9]{64})\b/i', (string) wp_remote_retrieve_body( $sum ), $found ) ) {
			return new WP_Error( 'axle_leftovers_dataset_checksum', Messages::get( 'dataset_checksum' ) );
		}
		$expected = strtolower( $found[1] );

		$file = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 60, // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Admin action or CLI only, never a page load.
				'limit_response_size' => $max + 1,
			)
		);
		if ( is_wp_error( $file ) || 200 !== (int) wp_remote_retrieve_response_code( $file ) ) {
			return new WP_Error( 'axle_leftovers_dataset_http', Messages::get( 'dataset_http', is_wp_error( $file ) ? $file->get_error_message() : (string) wp_remote_retrieve_response_code( $file ) ) );
		}
		$body = (string) wp_remote_retrieve_body( $file );
		if ( strlen( $body ) > $max ) {
			return new WP_Error( 'axle_leftovers_dataset_large', Messages::get( 'dataset_large' ) );
		}
		if ( ! hash_equals( $expected, hash( 'sha256', $body ) ) ) {
			return new WP_Error( 'axle_leftovers_dataset_checksum', Messages::get( 'dataset_checksum' ) );
		}

		$json = @gzdecode( $body, $max * 10 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gzdecode() warns on corrupt data; false is handled.
		if ( false === $json ) {
			return new WP_Error( 'axle_leftovers_dataset_unpack', Messages::get( 'dataset_unpack' ) );
		}
		$data = json_decode( $json, true );
		if ( ! self::valid( $data ) ) {
			return new WP_Error( 'axle_leftovers_dataset_shape', Messages::get( 'dataset_shape' ) );
		}

		if ( ! $this->storage->put( $this->file(), $body ) ) {
			return new WP_Error( 'axle_leftovers_dataset_save', Messages::get( 'dataset_save' ) );
		}

		$meta = array(
			'built'       => (string) $data['built'],
			'count'       => count( $data['plugins'] ),
			'fetched_gmt' => current_time( 'mysql', true ),
			'size'        => strlen( $body ),
			'sha256'      => $expected,
		);
		update_option( self::OPTION, $meta, false );
		Scanner::mark_stale();

		/**
		 * Fires after the full signature list was downloaded and stored.
		 *
		 * @param array $meta built, count, fetched_gmt, size, sha256.
		 */
		do_action( 'axle_leftovers_dataset_downloaded', $meta );

		return $meta;
	}

	/**
	 * Deletes the stored list. The bundled list stays.
	 */
	public function remove(): void {
		$this->storage->delete( $this->file() );
		delete_option( self::OPTION );
		Scanner::mark_stale();

		/**
		 * Fires after the downloaded signature list was removed.
		 */
		do_action( 'axle_leftovers_dataset_removed' );
	}

	/**
	 * Whether decoded JSON has the shape of a signature list: `version` 1, a
	 * `built` date, and a non-empty `plugins` map of slug => object with a name
	 * and only the known signature keys.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return bool
	 */
	public static function valid( $data ): bool {
		if ( ! is_array( $data ) || 1 !== ( $data['version'] ?? null ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $data['built'] ?? '' ) ) ) {
			return false;
		}
		if ( ! isset( $data['plugins'] ) || ! is_array( $data['plugins'] ) || ! $data['plugins'] ) {
			return false;
		}
		$allowed = array_merge( array( 'name' ), Signature::KEYS );
		foreach ( $data['plugins'] as $slug => $signature ) {
			if ( ! is_string( $slug ) || 1 !== preg_match( '/^[a-z0-9._-]{1,200}$/', $slug ) || ! is_array( $signature ) || ! isset( $signature['name'] ) || ! is_string( $signature['name'] ) ) {
				return false;
			}
			foreach ( $signature as $key => $value ) {
				if ( ! in_array( $key, $allowed, true ) || ( 'name' !== $key && ! is_array( $value ) ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
