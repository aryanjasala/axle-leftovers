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
 * from public WordPress.org zips, and merges the full list when the site owner
 * downloaded it (DatasetUpdater). It covers plugins deleted before Leftovers was
 * installed. Nothing is fetched here.
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
	 * The downloaded full list.
	 *
	 * @var DatasetUpdater
	 */
	private DatasetUpdater $remote;

	/**
	 * Sets up the dataset.
	 *
	 * @param string              $file   JSON file. Default: data/signatures.json in the plugin.
	 * @param DatasetUpdater|null $remote The downloaded full list. Default: the stored one, if any.
	 */
	public function __construct( string $file = '', ?DatasetUpdater $remote = null ) {
		$this->file   = '' !== $file ? $file : dirname( PLUGIN_FILE ) . '/data/signatures.json';
		$this->remote = $remote ?? new DatasetUpdater();
	}

	/**
	 * The bundled file's JSON, decoded.
	 *
	 * @return array
	 */
	private function bundled(): array {
		if ( ! is_readable( $this->file ) ) {
			return array();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local file that ships with the plugin.
		$data = json_decode( (string) file_get_contents( $this->file ), true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * What the dataset holds, for the screen: the bundled list and the
	 * downloaded one, and the merged count.
	 *
	 * @return array{bundled_built: string, bundled_count: int, downloaded: array|null, count: int}
	 */
	public function info(): array {
		$bundled = $this->bundled();

		return array(
			'bundled_built' => (string) ( $bundled['built'] ?? '' ),
			'bundled_count' => is_array( $bundled['plugins'] ?? null ) ? count( $bundled['plugins'] ) : 0,
			'downloaded'    => DatasetUpdater::meta(),
			'count'         => count( $this->plugins() ),
		);
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

		// The downloaded list wins for a slug in both: it is newer. The bundled one fills the rest.
		$bundled = $this->bundled();
		$plugins = $this->remote->plugins() + ( is_array( $bundled['plugins'] ?? null ) ? $bundled['plugins'] : array() );

		/**
		 * Filters the dataset of plugin signatures: the bundled list, merged with the downloaded full list when there is one.
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
