<?php
/**
 * The static code scanner: reads a plugin's PHP and lists the names it uses.
 *
 * @package AryanX\Axle\Leftovers
 */

namespace AryanX\Axle\Leftovers;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the tables, options, transients, cron hooks, meta keys, roles,
 * capabilities and uploads folders a plugin's code names as string literals.
 *
 * Plain PHP with no WordPress calls, so bin/build-signatures.php runs the same
 * scanner outside WordPress to build the bundled dataset.
 *
 * A signature is an array with these keys, each a sorted list of strings:
 *
 * - t: tables, without the site prefix
 * - o: options and site options; transients as `_transient_<name>` / `_site_transient_<name>`
 * - c: cron hooks
 * - m: meta keys (post, user, term, comment)
 * - r: roles and capabilities
 * - u: top-level uploads folders
 * - p: literal prefixes of dynamic names (`'acme_' . $key` gives `acme_`)
 * - n: identifiers named in the plugin's uninstall.php
 */
final class Signature {

	public const KEYS = array( 't', 'o', 'c', 'm', 'r', 'u', 'p', 'n' );

	/**
	 * Most names kept per key, so one huge plugin cannot bloat the ledger.
	 */
	public const MAX_PER_KEY = 400;

	/**
	 * Core tables: never part of a plugin's signature.
	 */
	private const CORE_TABLES = array( 'posts', 'postmeta', 'options', 'users', 'usermeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'comments', 'commentmeta', 'links', 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log', 'blog_versions' );

	/**
	 * Prefixes too common to say anything about an owner.
	 */
	private const GENERIC_PREFIXES = array( 'wp_', '_wp_', 'wp-', 'the_', 'get_', 'set_', 'my_', 'is_', 'has_', 'add_', 'new_', 'old_', 'tmp_', 'temp_', 'test_', 'user_', 'post_', 'term_', 'site_', 'page_', 'theme_', 'widget_', 'theme_mods_', '_transient_', '_site_transient_', '_transient_timeout_', 'default_', 'custom_', 'plugin_', 'option_', 'options_', 'settings_', 'data_', 'cache_', 'meta_', 'field_', '_field_' );

	/**
	 * Calls whose arguments name an item: function => array( argument index, key, name prefix ).
	 */
	private const CALLS = array(
		'get_option'               => array( 0, 'o', '' ),
		'add_option'               => array( 0, 'o', '' ),
		'update_option'            => array( 0, 'o', '' ),
		'delete_option'            => array( 0, 'o', '' ),
		'get_site_option'          => array( 0, 'o', '' ),
		'add_site_option'          => array( 0, 'o', '' ),
		'update_site_option'       => array( 0, 'o', '' ),
		'delete_site_option'       => array( 0, 'o', '' ),
		'register_setting'         => array( 1, 'o', '' ),
		'get_transient'            => array( 0, 'o', '_transient_' ),
		'set_transient'            => array( 0, 'o', '_transient_' ),
		'delete_transient'         => array( 0, 'o', '_transient_' ),
		'get_site_transient'       => array( 0, 'o', '_site_transient_' ),
		'set_site_transient'       => array( 0, 'o', '_site_transient_' ),
		'delete_site_transient'    => array( 0, 'o', '_site_transient_' ),
		'wp_schedule_event'        => array( 2, 'c', '' ),
		'wp_schedule_single_event' => array( 1, 'c', '' ),
		'wp_next_scheduled'        => array( 0, 'c', '' ),
		'wp_clear_scheduled_hook'  => array( 0, 'c', '' ),
		'wp_unschedule_hook'       => array( 0, 'c', '' ),
		'get_post_meta'            => array( 1, 'm', '' ),
		'add_post_meta'            => array( 1, 'm', '' ),
		'update_post_meta'         => array( 1, 'm', '' ),
		'delete_post_meta'         => array( 1, 'm', '' ),
		'delete_post_meta_by_key'  => array( 0, 'm', '' ),
		'get_user_meta'            => array( 1, 'm', '' ),
		'add_user_meta'            => array( 1, 'm', '' ),
		'update_user_meta'         => array( 1, 'm', '' ),
		'delete_user_meta'         => array( 1, 'm', '' ),
		'get_user_option'          => array( 0, 'm', '' ),
		'update_user_option'       => array( 1, 'm', '' ),
		'get_term_meta'            => array( 1, 'm', '' ),
		'add_term_meta'            => array( 1, 'm', '' ),
		'update_term_meta'         => array( 1, 'm', '' ),
		'delete_term_meta'         => array( 1, 'm', '' ),
		'get_comment_meta'         => array( 1, 'm', '' ),
		'add_comment_meta'         => array( 1, 'm', '' ),
		'update_comment_meta'      => array( 1, 'm', '' ),
		'delete_comment_meta'      => array( 1, 'm', '' ),
		'get_metadata'             => array( 2, 'm', '' ),
		'add_metadata'             => array( 2, 'm', '' ),
		'update_metadata'          => array( 2, 'm', '' ),
		'delete_metadata'          => array( 2, 'm', '' ),
		'register_meta'            => array( 1, 'm', '' ),
		'register_post_meta'       => array( 1, 'm', '' ),
		'register_term_meta'       => array( 1, 'm', '' ),
		'add_role'                 => array( 0, 'r', '' ),
		'remove_role'              => array( 0, 'r', '' ),
		'add_cap'                  => array( 0, 'r', '' ),
		'remove_cap'               => array( 0, 'r', '' ),
	);

	/**
	 * An empty signature.
	 *
	 * @return array<string, string[]>
	 */
	public static function blank(): array {
		return array_fill_keys( self::KEYS, array() );
	}

	/**
	 * Scans every PHP file in a plugin folder (or one single-file plugin).
	 *
	 * @param string $path      Plugin folder or file.
	 * @param int    $max_files Most files read.
	 * @param int    $max_bytes Largest file read, in bytes.
	 * @return array<string, string[]>
	 */
	public static function scan_path( string $path, int $max_files = 3000, int $max_bytes = 2097152 ): array {
		$signature = self::blank();
		$files     = array();

		if ( is_file( $path ) ) {
			$files[] = $path;
		} elseif ( is_dir( $path ) ) {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveCallbackFilterIterator(
					new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
					static function ( \SplFileInfo $file ): bool {
						if ( $file->isLink() ) {
							return false;
						}
						if ( $file->isDir() ) {
							return ! in_array( strtolower( $file->getFilename() ), array( 'node_modules', 'tests', 'test', '.git', 'languages' ), true );
						}
						return 'php' === strtolower( $file->getExtension() );
					}
				)
			);
			foreach ( $iterator as $file ) {
				$files[] = $file->getPathname();
				if ( count( $files ) >= $max_files ) {
					break;
				}
			}
		}

		sort( $files );
		$root = rtrim( $path, '/' );
		foreach ( $files as $file ) {
			$size = (int) filesize( $file );
			if ( $size <= 0 || $size > $max_bytes ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- A local PHP file of the plugin being scanned.
			$source       = (string) file_get_contents( $file );
			$is_uninstall = 'uninstall.php' === basename( $file ) && dirname( $file ) === $root;
			$signature    = self::merge( $signature, self::scan_source( $source, $is_uninstall ) );
		}

		return self::normalize( $signature );
	}

	/**
	 * Scans one PHP source.
	 *
	 * @param string $source       PHP code.
	 * @param bool   $is_uninstall Whether this is the plugin's uninstall.php.
	 * @return array<string, string[]>
	 */
	public static function scan_source( string $source, bool $is_uninstall = false ): array {
		$signature = self::blank();

		// Function and method calls with a name in a known argument.
		$names = implode( '|', array_map( 'preg_quote', array_keys( self::CALLS ) ) );
		if ( preg_match_all( '/(?<![\w$])(' . $names . ')\s*\(/i', $source, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[1] as $index => $match ) {
				$function = strtolower( $match[0] );
				$before   = substr( $source, max( 0, (int) $match[1] - 2 ), 2 );
				if ( ( '->' === $before || '::' === $before ) && ! in_array( $function, array( 'add_role', 'remove_role', 'add_cap', 'remove_cap' ), true ) ) {
					// A class's own get_option() and friends, not WordPress's.
					continue;
				}
				list( $position, $key, $prefix ) = self::CALLS[ $function ];
				$args                            = self::call_args( $source, (int) $matches[0][ $index ][1] + strlen( $matches[0][ $index ][0] ) );
				if ( ! isset( $args[ $position ] ) ) {
					continue;
				}
				self::add_literal( $signature, $key, $args[ $position ], $prefix );
			}
		}

		// Tables: $wpdb->prefix . 'name' and "{$wpdb->prefix}name".
		if ( preg_match_all( '/\$wpdb->(?:base_)?prefix\s*\.\s*(?:\'([A-Za-z0-9_]+)\'|"([A-Za-z0-9_]+)")(\s*\.)?/', $source, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = '' !== $match[1] ? $match[1] : $match[2];
				self::add( $signature, empty( $match[3] ) ? 't' : 'p', $name );
			}
		}
		if ( preg_match_all( '/\$wpdb->(?:base_)?prefix\}?([A-Za-z][A-Za-z0-9_]*)/', $source, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				self::add( $signature, 't', $name );
			}
		}

		// Uploads folders: $uploads['basedir'] . '/name'.
		if ( preg_match_all( '/[\'"]basedir[\'"]\s*\]\s*\)?\s*\.\s*[\'"]\/?([A-Za-z0-9_\-]{3,64})[\/\'"]/', $source, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				self::add( $signature, 'u', $name );
			}
		}

		if ( $is_uninstall && preg_match_all( '/\'([A-Za-z0-9_\-]{4,64}%?)\'|"([A-Za-z0-9_\-]{4,64}%?)"/', $source, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$name = ! empty( $match[2] ) ? $match[2] : $match[1];
				if ( '%' === substr( $name, -1 ) ) {
					self::add( $signature, 'p', substr( $name, 0, -1 ) );
				} else {
					self::add( $signature, 'n', $name );
				}
			}
		}

		return $signature;
	}

	/**
	 * Splits the arguments of a call into raw strings, from just after the "(".
	 *
	 * @param string $source PHP code.
	 * @param int    $start  Offset after the opening parenthesis.
	 * @return string[]
	 */
	public static function call_args( string $source, int $start ): array {
		$args   = array();
		$depth  = 0;
		$quote  = '';
		$buffer = '';
		$length = min( strlen( $source ), $start + 2000 );

		for ( $i = $start; $i < $length; $i++ ) {
			$char = $source[ $i ];
			if ( '' !== $quote ) {
				$buffer .= $char;
				if ( '\\' === $char && $i + 1 < $length ) {
					$buffer .= $source[ ++$i ];
				} elseif ( $char === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '\'' === $char || '"' === $char ) {
				$quote   = $char;
				$buffer .= $char;
				continue;
			}
			if ( '(' === $char || '[' === $char || '{' === $char ) {
				++$depth;
			} elseif ( ')' === $char || ']' === $char || '}' === $char ) {
				if ( 0 === $depth ) {
					$args[] = trim( $buffer );
					return $args;
				}
				--$depth;
			} elseif ( ',' === $char && 0 === $depth ) {
				$args[] = trim( $buffer );
				$buffer = '';
				continue;
			} elseif ( ';' === $char && 0 === $depth ) {
				break;
			}
			$buffer .= $char;
		}

		return $args;
	}

	/**
	 * Adds a quoted literal argument as a name, or its literal start as a prefix.
	 *
	 * @param array<string, string[]> $signature Signature.
	 * @param string                  $key       Key.
	 * @param string                  $arg       Raw argument.
	 * @param string                  $prefix    Prefix for the stored name.
	 */
	private static function add_literal( array &$signature, string $key, string $arg, string $prefix ): void {
		if ( ! preg_match( '/^(?:\'([^\'\\\\$]+)\'|"([^"\\\\${}]+)")\s*(\.)?/', $arg, $match ) ) {
			return;
		}
		$name = isset( $match[2] ) && '' !== $match[2] ? $match[2] : $match[1];
		if ( ! empty( $match[3] ) ) {
			self::add( $signature, 'p', $prefix . $name );
			return;
		}
		if ( strlen( $arg ) !== strlen( $match[0] ) ) {
			// Something follows the literal that is not a concatenation, such as a ternary.
			return;
		}
		self::add( $signature, $key, $prefix . $name );
	}

	/**
	 * Adds one name.
	 *
	 * @param array<string, string[]> $signature Signature.
	 * @param string                  $key       Key.
	 * @param string                  $name      Name.
	 */
	private static function add( array &$signature, string $key, string $name ): void {
		$name = trim( $name );
		if ( '' === $name || strlen( $name ) > 191 || ! preg_match( '/^[A-Za-z0-9_\-\.:]+$/', $name ) ) {
			return;
		}
		if ( 't' === $key && in_array( strtolower( $name ), self::CORE_TABLES, true ) ) {
			return;
		}
		if ( 'p' === $key && ! self::useful_prefix( $name ) ) {
			return;
		}
		$signature[ $key ][] = $name;
	}

	/**
	 * Whether a prefix is specific enough to point at one plugin.
	 *
	 * @param string $prefix Prefix.
	 * @return bool
	 */
	public static function useful_prefix( string $prefix ): bool {
		$prefix = strtolower( $prefix );
		if ( strlen( $prefix ) < 4 || in_array( $prefix, self::GENERIC_PREFIXES, true ) ) {
			return false;
		}
		// A prefix ends at a separator; "acme" alone would match "acmeboard".
		return in_array( substr( $prefix, -1 ), array( '_', '-' ), true );
	}

	/**
	 * Removes names a list says are not a plugin's own, such as core options.
	 *
	 * @param array $signature Signature.
	 * @param array $remove    Key => names to remove.
	 * @return array<string, string[]>
	 */
	public static function strip( array $signature, array $remove ): array {
		$signature = self::normalize( $signature );
		foreach ( $remove as $key => $names ) {
			if ( isset( $signature[ $key ] ) ) {
				$signature[ $key ] = array_values( array_diff( $signature[ $key ], (array) $names ) );
			}
		}

		return $signature;
	}

	/**
	 * Merges two signatures.
	 *
	 * @param array $a First.
	 * @param array $b Second.
	 * @return array<string, string[]>
	 */
	public static function merge( array $a, array $b ): array {
		$out = self::blank();
		foreach ( self::KEYS as $key ) {
			$out[ $key ] = array_merge( isset( $a[ $key ] ) ? (array) $a[ $key ] : array(), isset( $b[ $key ] ) ? (array) $b[ $key ] : array() );
		}

		return $out;
	}

	/**
	 * Sorts, removes duplicates, keeps only known keys and caps each list.
	 *
	 * @param array $signature Signature.
	 * @return array<string, string[]>
	 */
	public static function normalize( array $signature ): array {
		$out = self::blank();
		foreach ( self::KEYS as $key ) {
			$names = array_values( array_unique( array_map( 'strval', isset( $signature[ $key ] ) ? (array) $signature[ $key ] : array() ) ) );
			sort( $names, SORT_STRING );
			$out[ $key ] = array_slice( $names, 0, self::MAX_PER_KEY );
		}

		return $out;
	}

	/**
	 * Drops empty keys, for compact storage.
	 *
	 * @param array $signature Signature.
	 * @return array<string, string[]>
	 */
	public static function compact( array $signature ): array {
		return array_filter(
			self::normalize( $signature ),
			static function ( $names ): bool {
				return array() !== $names;
			}
		);
	}

	/**
	 * Matches an item against a signature.
	 *
	 * @param array  $signature Signature.
	 * @param string $key       Signature key for the item type.
	 * @param string $name      Item name, tables without the site prefix.
	 * @return string Evidence code: `name`, `uninstall`, `prefix`, or '' for no match.
	 */
	public static function match( array $signature, string $key, string $name ): string {
		if ( in_array( $name, isset( $signature[ $key ] ) ? (array) $signature[ $key ] : array(), true ) ) {
			return 'name';
		}
		if ( 'o' === $key && 0 === strpos( $name, '_transient_' ) && in_array( '_site' . $name, (array) ( $signature['o'] ?? array() ), true ) ) {
			return 'name';
		}
		if ( in_array( $name, isset( $signature['n'] ) ? (array) $signature['n'] : array(), true ) ) {
			return 'uninstall';
		}
		$bare = preg_replace( '/^_(site_)?transient_/', '', $name );
		foreach ( isset( $signature['p'] ) ? (array) $signature['p'] : array() as $prefix ) {
			$prefix = (string) $prefix;
			if ( self::useful_prefix( $prefix ) && ( 0 === strpos( $name, $prefix ) || 0 === strpos( (string) $bare, $prefix ) ) ) {
				return 'prefix';
			}
		}

		return '';
	}
}
