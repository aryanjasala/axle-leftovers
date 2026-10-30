<?php
/**
 * The reference check: is any running code still using this name?
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Searches the PHP of active plugins, the active theme and its parent,
 * must-use plugins and drop-ins for an item's name as a string literal, and
 * for its literal prefix. A hit blocks the trash and names the file.
 */
class References {

	/**
	 * Most files read in one check.
	 */
	private const MAX_FILES = 20000;

	/**
	 * Largest file read, in bytes.
	 */
	private const MAX_BYTES = 2097152;

	/**
	 * Files and folders to search.
	 *
	 * @return string[] Absolute paths.
	 */
	public function paths(): array {
		$paths  = array();
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$self = plugin_basename( PLUGIN_FILE );
		foreach ( array_unique( array_map( 'strval', $active ) ) as $plugin ) {
			if ( $plugin === $self ) {
				continue;
			}
			$paths[] = false !== strpos( $plugin, '/' ) ? WP_PLUGIN_DIR . '/' . dirname( $plugin ) : WP_PLUGIN_DIR . '/' . $plugin;
		}

		$paths[] = get_stylesheet_directory();
		$paths[] = get_template_directory();
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$paths[] = WPMU_PLUGIN_DIR;
		}
		foreach ( array( 'advanced-cache.php', 'object-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'php-error.php', 'fatal-error-handler.php', 'sunrise.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php' ) as $dropin ) {
			$paths[] = WP_CONTENT_DIR . '/' . $dropin;
		}

		/**
		 * Filters the files and folders the reference check searches.
		 *
		 * @param string[] $paths Absolute paths: active plugins, the theme and its parent, mu-plugins, drop-ins.
		 */
		$filtered = (array) apply_filters( 'axle_leftovers_reference_check_paths', array_values( array_unique( $paths ) ) );

		return array_values(
			array_filter(
				array_map( 'strval', $filtered ),
				static function ( string $path ): bool {
					return '' !== $path && file_exists( $path );
				}
			)
		);
	}

	/**
	 * The strings that count as a use of an item's name.
	 *
	 * @param string $type Item type.
	 * @param string $name Item name.
	 * @return string[]
	 */
	public static function needles( string $type, string $name ): array {
		$bare  = Attribution::bare_name( $type, $name );
		$plain = (string) preg_replace( '/^_(site_)?transient_/', '', $bare );
		$out   = array( "'" . $plain . "'", '"' . $plain . '"' );
		if ( 'table' === $type ) {
			$out[] = '}' . $bare;
		}
		// The literal prefix, as code that builds names with it would write it.
		if ( preg_match( '/^([A-Za-z0-9]+[_-])/', $plain, $match ) && Signature::useful_prefix( $match[1] ) && $match[1] !== $plain ) {
			$out[] = "'" . $match[1] . "'";
			$out[] = '"' . $match[1] . '"';
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Finds code that uses any of the items.
	 *
	 * @param array<int, array{type: string, name: string}> $items Items.
	 * @return array<string, string[]> "type:name" => files (relative to wp-content) that use it.
	 */
	public function check( array $items ): array {
		$needles = array();
		$hits    = array();
		foreach ( $items as $item ) {
			$id             = $item['type'] . ':' . $item['name'];
			$needles[ $id ] = self::needles( (string) $item['type'], (string) $item['name'] );
			$hits[ $id ]    = array();
		}
		if ( ! $needles ) {
			return $hits;
		}

		$content = wp_normalize_path( WP_CONTENT_DIR ) . '/';
		$count   = 0;
		foreach ( $this->files() as $file ) {
			if ( ++$count > self::MAX_FILES ) {
				break;
			}
			$size = (int) filesize( $file );
			if ( $size <= 0 || $size > self::MAX_BYTES ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local PHP file of an active plugin or theme.
			$source = (string) file_get_contents( $file );
			foreach ( $needles as $id => $list ) {
				if ( count( $hits[ $id ] ) >= 3 ) {
					continue;
				}
				foreach ( $list as $needle ) {
					if ( false !== strpos( $source, $needle ) ) {
						$path          = wp_normalize_path( $file );
						$hits[ $id ][] = 0 === strpos( $path, $content ) ? substr( $path, strlen( $content ) ) : $path;
						break;
					}
				}
			}
		}

		return $hits;
	}

	/**
	 * PHP files under the searched paths.
	 *
	 * @return \Generator<int, string>
	 */
	private function files(): \Generator {
		foreach ( $this->paths() as $path ) {
			if ( is_file( $path ) ) {
				yield $path;
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
					static function ( \SplFileInfo $file ): bool {
						if ( $file->isDir() ) {
							return ! in_array( strtolower( $file->getFilename() ), array( 'node_modules', '.git' ), true );
						}
						return 'php' === strtolower( $file->getExtension() );
					}
				)
			);
			foreach ( $iterator as $file ) {
				yield $file->getPathname();
			}
		}
	}
}
