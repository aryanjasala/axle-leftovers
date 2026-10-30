<?php
/**
 * The plugin's folder in uploads: exports and the folder trash.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * `uploads/axle-leftovers/` with `exports/` (JSON and SQL exports) and
 * `trash/` (moved uploads folders and trashed meta rows). Both get deny
 * rules for Apache and IIS and an empty index.php; files are only served
 * through the capability-checked download in wp-admin.
 */
class Storage {

	/**
	 * File system access.
	 *
	 * @var \WP_Filesystem_Direct|null
	 */
	private ?\WP_Filesystem_Direct $fs = null;

	/**
	 * The uploads base folder.
	 *
	 * @return string
	 */
	public function uploads_base(): string {
		$uploads = wp_upload_dir( null, false );

		return untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
	}

	/**
	 * The plugin's folder, or a subfolder of it, created with deny rules.
	 *
	 * @param string $sub exports, trash, or ''.
	 * @return string
	 */
	public function dir( string $sub = '' ): string {
		$root = $this->uploads_base() . '/axle-leftovers';
		$dir  = '' === $sub ? $root : $root . '/' . $sub;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$this->protect( $root );
		if ( '' !== $sub ) {
			$this->protect( $dir );
		}

		return $dir;
	}

	/**
	 * Writes deny rules and an empty index.php.
	 *
	 * @param string $dir Folder.
	 */
	private function protect( string $dir ): void {
		$files = array(
			'.htaccess'  => "# Axle Leftovers: exports and trashed data. Served only through wp-admin.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				$this->fs()->put_contents( $dir . '/' . $name, $content, self::mode() );
			}
		}
	}

	/**
	 * Writes a file.
	 *
	 * @param string $path    Path.
	 * @param string $content Content.
	 * @return bool
	 */
	public function put( string $path, string $content ): bool {
		wp_mkdir_p( dirname( $path ) );

		return (bool) $this->fs()->put_contents( $path, $content, self::mode() );
	}

	/**
	 * Opens a file for appending, for exports written in chunks.
	 *
	 * @param string $path Path.
	 * @return resource|false
	 */
	public function open_append( string $path ) {
		wp_mkdir_p( dirname( $path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Large exports are streamed in chunks; WP_Filesystem cannot append.
		return fopen( $path, 'ab' );
	}

	/**
	 * Appends to a file opened with open_append().
	 *
	 * @param resource $handle Handle.
	 * @param string   $text   Text.
	 * @return bool
	 */
	public function write( $handle, string $text ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- See open_append(); the file is in uploads.
		return false !== fwrite( $handle, $text );
	}

	/**
	 * Closes a file opened with open_append().
	 *
	 * @param resource $handle Handle.
	 */
	public function close( $handle ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See open_append().
		fclose( $handle );
	}

	/**
	 * Reads a file.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public function get( string $path ): string {
		return is_file( $path ) ? (string) $this->fs()->get_contents( $path ) : '';
	}

	/**
	 * Moves a file or folder. Same file system only: no copy fallback.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @return bool
	 */
	public function move( string $from, string $to ): bool {
		if ( file_exists( $to ) ) {
			return false;
		}
		wp_mkdir_p( dirname( $to ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- Inside uploads. A same-filesystem move must stay atomic; WP_Filesystem falls back to copy and delete.
		return rename( $from, $to );
	}

	/**
	 * Deletes a file or folder, recursively.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function delete( string $path ): bool {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return true;
		}

		return (bool) $this->fs()->delete( $path, true );
	}

	/**
	 * Whether a path is inside a folder, after resolving links, without following
	 * a link out of it.
	 *
	 * @param string $path   Path.
	 * @param string $folder Folder.
	 * @return bool
	 */
	public static function is_inside( string $path, string $folder ): bool {
		$real_folder = realpath( $folder );
		$real_path   = realpath( $path );
		if ( false === $real_folder || false === $real_path || is_link( $path ) ) {
			return false;
		}

		$real_folder = trailingslashit( wp_normalize_path( $real_folder ) );

		return 0 === strpos( trailingslashit( wp_normalize_path( $real_path ) ), $real_folder ) && trailingslashit( wp_normalize_path( $real_path ) ) !== $real_folder;
	}

	/**
	 * File permissions for new files.
	 *
	 * @return int
	 */
	private static function mode(): int {
		return defined( 'FS_CHMOD_FILE' ) ? (int) FS_CHMOD_FILE : 0644;
	}

	/**
	 * Direct file system access, without asking for FTP credentials.
	 *
	 * @return \WP_Filesystem_Direct
	 */
	private function fs(): \WP_Filesystem_Direct {
		if ( null === $this->fs ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$this->fs = new \WP_Filesystem_Direct( null );
		}

		return $this->fs;
	}
}
