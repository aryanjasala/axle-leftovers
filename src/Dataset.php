<?php
/**
 * The bundled signatures of popular WordPress.org plugins.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads data/signatures.json, built at release time by bin/build-signatures.php
 * from public WordPress.org zips. It covers plugins deleted before Leftovers was
 * installed. Nothing is fetched at runtime.
 */
class Dataset {

	/**
	 * Slug => signature with a `name`, loaded once.
	 *
	 * @var array<string, array>|null
	 */
	private ?array $plugins = null;

	/**
	 * The dataset file.
	 *
	 * @var string
	 */
	private string $file;

	/**
	 * Sets up the dataset.
	 *
	 * @param string $file JSON file. Default: data/signatures.json in the plugin.
	 */
	public function __construct( string $file = '' ) {
		$this->file = '' !== $file ? $file : dirname( PLUGIN_FILE ) . '/data/signatures.json';
	}

	/**
	 * Every plugin in the dataset.
	 *
	 * @return array<string, array> Slug => array( 'name' => string ) + signature.
	 */
	public function plugins(): array {
		if ( null !== $this->plugins ) {
			return $this->plugins;
		}

		$plugins = array();
		if ( is_readable( $this->file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local file that ships with the plugin.
			$data    = json_decode( (string) file_get_contents( $this->file ), true );
			$plugins = is_array( $data ) && isset( $data['plugins'] ) && is_array( $data['plugins'] ) ? $data['plugins'] : array();
		}

		/**
		 * Filters the bundled dataset of plugin signatures.
		 *
		 * @param array $plugins Slug => array( 'name' => 'Plugin Name', 't' => tables, 'o' => options, ... ).
		 */
		$plugins = (array) apply_filters( 'axle_leftovers_dataset', $plugins );

		$this->plugins = array();
		foreach ( $plugins as $slug => $signature ) {
			if ( is_string( $slug ) && is_array( $signature ) ) {
				$this->plugins[ $slug ] = array( 'name' => (string) ( $signature['name'] ?? $slug ) ) + Signature::normalize( $signature );
			}
		}

		return $this->plugins;
	}

	/**
	 * One plugin's name, or ''.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public function name( string $slug ): string {
		$plugins = $this->plugins();

		return isset( $plugins[ $slug ] ) ? (string) $plugins[ $slug ]['name'] : '';
	}
}
