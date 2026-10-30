<?php
/**
 * Builds data/signatures.json: the names and prefixes the most installed
 * WordPress.org plugins use, from their public zips, with the same scanner the
 * plugin runs on installed plugins (src/Signature.php).
 *
 * Run at release time, never on a site:
 *
 *     php bin/build-signatures.php [--count=500] [--out=data/signatures.json] [--cache=/tmp/axle-leftovers-zips] [--parallel=8]
 *
 * It downloads each plugin's latest stable zip once into the cache folder, so
 * a second run only fetches what changed. Needs the curl and zip extensions.
 *
 * @package AryanX\Axle\Leftovers
 */

// phpcs:ignoreFile -- A command-line build tool. It is not part of the plugin zip and never runs inside WordPress.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/src/Signature.php';
require dirname( __DIR__ ) . '/src/Core.php';

use AryanX\Axle\Leftovers\Core;
use AryanX\Axle\Leftovers\Signature;

$options  = getopt( '', array( 'count::', 'out::', 'cache::', 'parallel::', 'budget::' ) );
$count    = max( 1, (int) ( $options['count'] ?? 500 ) );
$out      = (string) ( $options['out'] ?? dirname( __DIR__ ) . '/data/signatures.json' );
$cache    = rtrim( (string) ( $options['cache'] ?? sys_get_temp_dir() . '/axle-leftovers-zips' ), '/' );
$parallel = max( 1, (int) ( $options['parallel'] ?? 8 ) );
$budget   = max( 65536, (int) ( $options['budget'] ?? 1048576 ) );

if ( ! is_dir( $cache ) && ! mkdir( $cache, 0777, true ) ) {
	fwrite( STDERR, "Cannot create $cache\n" );
	exit( 1 );
}

/**
 * GETs a URL.
 */
function axle_leftovers_get( string $url ): string {
	$curl = curl_init( $url );
	curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'axle-leftovers-build' ) );
	$body = (string) curl_exec( $curl );
	curl_close( $curl );
	return $body;
}

// 1. The most installed plugins, from the WordPress.org API.
$plugins = array();
for ( $page = 1; count( $plugins ) < $count; $page++ ) {
	$url  = 'https://api.wordpress.org/plugins/info/1.2/?' . http_build_query(
		array(
			'action'  => 'query_plugins',
			'request' => array(
				'browse'   => 'popular',
				'per_page' => 250,
				'page'     => $page,
				'fields'   => array( 'short_description' => false, 'description' => false, 'sections' => false, 'icons' => false, 'banners' => false, 'tags' => false, 'ratings' => false, 'screenshots' => false, 'versions' => false, 'contributors' => false ),
			),
		)
	);
	$data = json_decode( axle_leftovers_get( $url ), true );
	if ( empty( $data['plugins'] ) ) {
		break;
	}
	foreach ( $data['plugins'] as $plugin ) {
		$plugins[ $plugin['slug'] ] = array(
			// The name without its tagline, and with no long dashes.
			'name'    => trim( str_replace( array( "\u{2013}", "\u{2014}" ), '-', (string) preg_split( '/\s+[\x{2013}\x{2014}|:-]\s+/u', html_entity_decode( (string) $plugin['name'], ENT_QUOTES ) )[0] ) ),
			'version' => (string) $plugin['version'],
			'url'     => (string) $plugin['download_link'],
		);
		if ( count( $plugins ) >= $count ) {
			break;
		}
	}
}
fwrite( STDERR, sprintf( "%d plugins listed\n", count( $plugins ) ) );

// 2. Download the zips that are not cached yet, a few at a time.
$queue = array();
foreach ( $plugins as $slug => $plugin ) {
	$file = "$cache/$slug.{$plugin['version']}.zip";
	if ( ! is_file( $file ) || filesize( $file ) < 100 ) {
		$queue[ $slug ] = $file;
	}
}
fwrite( STDERR, sprintf( "%d zips to download\n", count( $queue ) ) );
$multi   = curl_multi_init();
$running = array();
while ( $queue || $running ) {
	while ( $queue && count( $running ) < $parallel ) {
		$slug   = array_key_first( $queue );
		$file   = $queue[ $slug ];
		unset( $queue[ $slug ] );
		$handle = fopen( "$file.part", 'wb' );
		$curl   = curl_init( $plugins[ $slug ]['url'] );
		curl_setopt_array( $curl, array( CURLOPT_FILE => $handle, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_USERAGENT => 'axle-leftovers-build' ) );
		curl_multi_add_handle( $multi, $curl );
		$running[ (int) $curl ] = array( $curl, $handle, $file, $slug );
	}
	curl_multi_exec( $multi, $active );
	curl_multi_select( $multi, 1.0 );
	while ( $info = curl_multi_info_read( $multi ) ) {
		list( $curl, $handle, $file, $slug ) = $running[ (int) $info['handle'] ];
		unset( $running[ (int) $info['handle'] ] );
		fclose( $handle );
		$ok = CURLE_OK === $info['result'] && 200 === curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		curl_multi_remove_handle( $multi, $curl );
		curl_close( $curl );
		if ( $ok ) {
			rename( "$file.part", $file );
		} else {
			@unlink( "$file.part" );
			fwrite( STDERR, "  download failed: $slug\n" );
		}
	}
}
curl_multi_close( $multi );

// 3. Scan each zip with the plugin's own scanner.
$dataset = array();
$scratch = "$cache/unpacked";
foreach ( $plugins as $slug => $plugin ) {
	$file = "$cache/$slug.{$plugin['version']}.zip";
	if ( ! is_file( $file ) ) {
		continue;
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $file ) ) {
		fwrite( STDERR, "  bad zip: $slug\n" );
		continue;
	}
	$signature = Signature::blank();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = (string) $zip->getNameIndex( $i );
		if ( '.php' !== strtolower( substr( $name, -4 ) ) || preg_match( '#/(node_modules|tests?|languages)/#i', $name ) ) {
			continue;
		}
		$stat = $zip->statIndex( $i );
		if ( ! $stat || $stat['size'] > 2097152 ) {
			continue;
		}
		$source       = (string) $zip->getFromIndex( $i );
		$is_uninstall = (bool) preg_match( '#^[^/]+/uninstall\.php$#', $name );
		$signature    = Signature::merge( $signature, Signature::scan_source( $source, $is_uninstall ) );
	}
	$zip->close();
	// Core names say nothing about the plugin.
	$signature      = Signature::strip(
		$signature,
		array(
			'o' => Core::OPTIONS,
			'c' => Core::CRON,
			'r' => array_merge( Core::ROLES, Core::CAPS ),
			'm' => array_merge( Core::META['post'], Core::META['user'] ),
		)
	);
	$signature['o'] = array_values(
		array_filter(
			$signature['o'],
			static function ( string $name ): bool {
				foreach ( Core::OPTION_PATTERNS as $pattern ) {
					if ( preg_match( $pattern, $name ) ) {
						return false;
					}
				}
				return true;
			}
		)
	);
	$signature = Signature::compact( $signature );
	if ( $signature ) {
		$dataset[ $slug ] = array( 'name' => $plugin['name'] ) + $signature;
	}
}

// 4. Keep it under the size budget: drop meta keys and uninstall names first, then the least installed plugins.
ksort( $dataset );
$encode = static function ( array $dataset ): string {
	return json_encode(
		array(
			'version' => 1,
			'built'   => gmdate( 'Y-m-d' ),
			'source'  => 'Top WordPress.org plugins by installs, scanned with src/Signature.php by bin/build-signatures.php.',
			'plugins' => $dataset,
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	);
};
$json = $encode( $dataset );
foreach ( array( 'n', 'm' ) as $drop ) {
	if ( strlen( $json ) <= $budget ) {
		break;
	}
	foreach ( $dataset as $slug => $signature ) {
		unset( $dataset[ $slug ][ $drop ] );
	}
	$json = $encode( $dataset );
}
$order = array_keys( $plugins );
while ( strlen( $json ) > $budget && $dataset ) {
	$last = array_pop( $order );
	unset( $dataset[ $last ] );
	$json = $encode( $dataset );
}

if ( ! is_dir( dirname( $out ) ) ) {
	mkdir( dirname( $out ), 0777, true );
}
file_put_contents( $out, $json . "\n" );
fwrite( STDERR, sprintf( "Wrote %s: %d plugins, %d KB\n", $out, count( $dataset ), (int) ( strlen( $json ) / 1024 ) ) );
