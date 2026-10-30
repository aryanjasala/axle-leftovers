<?php
/**
 * Builds the signature datasets: the names and prefixes the most installed
 * WordPress.org plugins use, from their public zips, with the same scanner the
 * plugin runs on installed plugins (src/Signature.php).
 *
 * Two uses, never on a site:
 *
 * 1. The bundled list, at release time (plain JSON, under a size budget):
 *
 *     php bin/build-signatures.php [--count=500] [--out=data/signatures.json] [--cache=/tmp/axle-leftovers-zips] [--parallel=8]
 *
 * 2. The full list, monthly on GitHub Actions (.github/workflows/signatures.yml),
 *    incrementally, published to the orphan `signatures` branch:
 *
 *     php bin/build-signatures.php --count=10000 --publish-dir=signatures-branch [--max-minutes=300]
 *
 *    The folder holds state.json (slug => version, plus the plugins whose code
 *    names nothing), signatures.json.gz and signatures.json.gz.sha256. A run reads
 *    the previous ones and downloads only plugins whose version changed or that
 *    are new. Each zip is scanned and deleted at once, so disk use stays small.
 *    Plugins that fail to download keep their previous data and are retried next
 *    run. `--max-minutes` stops starting downloads after that long: the rest
 *    keeps its old data and the next run carries on. `--offset=N` skips the N most
 *    installed plugins, for sampling runs.
 *
 * Needs the curl, zip and zlib extensions.
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

$options  = getopt( '', array( 'count::', 'out::', 'cache::', 'parallel::', 'budget::', 'publish-dir::', 'max-minutes::', 'offset::' ) );
$count    = max( 1, (int) ( $options['count'] ?? 500 ) );
$offset   = max( 0, (int) ( $options['offset'] ?? 0 ) ); // Skips the most installed plugins: for sampling runs.
$publish  = isset( $options['publish-dir'] ) ? rtrim( (string) $options['publish-dir'], '/' ) : '';
$out      = (string) ( $options['out'] ?? ( '' === $publish ? dirname( __DIR__ ) . '/data/signatures.json' : '' ) );
$parallel = max( 1, (int) ( $options['parallel'] ?? 8 ) );
$budget   = (int) ( $options['budget'] ?? ( '' === $publish ? 1048576 : 0 ) );
$budget   = $budget > 0 ? max( 65536, $budget ) : 0;
$deadline = isset( $options['max-minutes'] ) && (float) $options['max-minutes'] > 0 ? microtime( true ) + 60 * (float) $options['max-minutes'] : 0.0;
$keep     = isset( $options['cache'] );
$cache    = rtrim( (string) ( $options['cache'] ?? sys_get_temp_dir() . '/axle-leftovers-zips-' . getmypid() ), '/' );
$started  = microtime( true );

foreach ( array( $cache, $publish ) as $dir ) {
	if ( '' !== $dir && ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) ) {
		fwrite( STDERR, "Cannot create $dir\n" );
		exit( 1 );
	}
}

/**
 * GETs a URL, retrying twice.
 */
function axle_leftovers_get( string $url ): string {
	for ( $try = 0; $try < 3; $try++ ) {
		$curl = curl_init( $url );
		curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'axle-leftovers-build' ) );
		$body = (string) curl_exec( $curl );
		$code = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		curl_close( $curl );
		if ( 200 === $code && '' !== $body ) {
			return $body;
		}
		sleep( 2 * ( $try + 1 ) );
	}
	return '';
}

/**
 * The compact signature of a plugin zip, or null when the zip cannot be read.
 */
function axle_leftovers_scan_zip( string $file ): ?array {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $file ) ) {
		return null;
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
	return Signature::compact( $signature );
}

/**
 * Reads a gzip or plain JSON file into an array, or an empty array.
 */
function axle_leftovers_read_json( string $file ): array {
	if ( ! is_file( $file ) ) {
		return array();
	}
	$raw  = (string) file_get_contents( $file );
	$json = '.gz' === substr( $file, -3 ) ? gzdecode( $raw ) : $raw;
	$data = false !== $json ? json_decode( $json, true ) : null;
	return is_array( $data ) ? $data : array();
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
	foreach ( $data['plugins'] as $position => $plugin ) {
		if ( ( $page - 1 ) * 250 + $position < $offset ) {
			continue;
		}
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
if ( count( $plugins ) < (int) ( min( $count, 5000 ) * 0.9 ) ) {
	// An API outage must never publish a list with most plugins gone.
	fwrite( STDERR, "The WordPress.org API listed too few plugins. Stopping.\n" );
	exit( 1 );
}

$listed_in = (int) ( microtime( true ) - $started );
$started   = microtime( true );
fwrite( STDERR, "Listing took $listed_in s\n" );

// 1b. What the last run knew: slug => version, and the signatures it found.
$state    = array();
$previous = array();
if ( '' !== $publish ) {
	$state    = (array) ( axle_leftovers_read_json( "$publish/state.json" )['plugins'] ?? array() );
	$previous = (array) ( axle_leftovers_read_json( "$publish/signatures.json.gz" )['plugins'] ?? array() );
	fwrite( STDERR, sprintf( "Previous run: %d plugins in the state, %d with signatures\n", count( $state ), count( $previous ) ) );
}

// 2. Download only what changed, a few at a time; scan each zip at once and delete it.
$queue = array();
$reuse = 0;
foreach ( $plugins as $slug => $plugin ) {
	// Unchanged: the state has this version. A plugin with no signature is in the state but not in the output.
	if ( isset( $state[ $slug ] ) && (string) $state[ $slug ] === $plugin['version'] ) {
		++$reuse;
		continue;
	}
	$queue[ $slug ] = 0;
}
fwrite( STDERR, sprintf( "%d unchanged, %d to download\n", $reuse, count( $queue ) ) );

$dataset  = array();
$new      = array();
$failed   = array();
$deferred = 0;
$done     = 0;
$total    = count( $queue );
$multi    = curl_multi_init();
$running  = array();
while ( $queue || $running ) {
	while ( $queue && count( $running ) < $parallel ) {
		if ( $deadline && microtime( true ) > $deadline ) {
			$deferred = count( $queue );
			$queue    = array();
			break;
		}
		$slug     = array_key_first( $queue );
		$attempts = $queue[ $slug ];
		unset( $queue[ $slug ] );
		$file = "$cache/$slug.{$plugins[ $slug ]['version']}.zip";
		if ( $keep && is_file( $file ) && filesize( $file ) > 100 ) {
			$sig = axle_leftovers_scan_zip( $file );
			if ( null !== $sig ) {
				$new[ $slug ] = $sig;
				++$done;
				continue;
			}
		}
		$handle = fopen( "$file.part", 'wb' );
		$curl   = curl_init( $plugins[ $slug ]['url'] );
		curl_setopt_array( $curl, array( CURLOPT_FILE => $handle, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300, CURLOPT_USERAGENT => 'axle-leftovers-build' ) );
		curl_multi_add_handle( $multi, $curl );
		$running[ (int) $curl ] = array( $curl, $handle, $file, $slug, $attempts );
	}
	if ( ! $running ) {
		continue;
	}
	curl_multi_exec( $multi, $active );
	curl_multi_select( $multi, 1.0 );
	while ( $info = curl_multi_info_read( $multi ) ) {
		list( $curl, $handle, $file, $slug, $attempts ) = $running[ (int) $info['handle'] ];
		unset( $running[ (int) $info['handle'] ] );
		fclose( $handle );
		$code = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$ok   = CURLE_OK === $info['result'] && 200 === $code;
		curl_multi_remove_handle( $multi, $curl );
		curl_close( $curl );
		$sig = null;
		if ( $ok ) {
			rename( "$file.part", $file );
			$sig = axle_leftovers_scan_zip( $file );
			if ( ! $keep ) {
				unlink( $file );
			}
		} else {
			@unlink( "$file.part" );
		}
		if ( null !== $sig ) {
			$new[ $slug ] = $sig;
			++$done;
		} elseif ( $attempts < 2 && ( ! $ok && ( 429 === $code || $code >= 500 || 0 === $code ) ) ) {
			$queue[ $slug ] = $attempts + 1;
		} else {
			$failed[] = $slug;
			fwrite( STDERR, "  failed ($code): $slug\n" );
			++$done;
		}
		if ( 0 === $done % 250 ) {
			fwrite( STDERR, sprintf( "  %d/%d scanned, %d s\n", $done, $total, (int) ( microtime( true ) - $started ) ) );
		}
	}
}
curl_multi_close( $multi );
if ( ! $keep && is_dir( $cache ) ) {
	@rmdir( $cache );
}

// 3. Merge: new scans, then the previous signatures of unchanged (or failed, or deferred) plugins.
$next_state = array();
foreach ( $plugins as $slug => $plugin ) {
	if ( array_key_exists( $slug, $new ) ) {
		$next_state[ $slug ] = $plugin['version'];
		if ( $new[ $slug ] ) {
			$dataset[ $slug ] = array( 'name' => $plugin['name'] ) + $new[ $slug ];
		}
	} elseif ( isset( $state[ $slug ] ) ) {
		// Unchanged, failed or deferred: keep what the last run found, at the version it found it.
		$next_state[ $slug ] = $state[ $slug ];
		if ( isset( $previous[ $slug ] ) ) {
			$dataset[ $slug ] = array( 'name' => $plugin['name'] ) + array_diff_key( $previous[ $slug ], array( 'name' => 1 ) );
		}
	}
}
fwrite( STDERR, sprintf( "%d scanned, %d failed, %d deferred, %d reused, %d s\n", count( $new ), count( $failed ), $deferred, $reuse, (int) ( microtime( true ) - $started ) ) );

// 4. Keep it under the size budget, if there is one: drop meta keys and uninstall names first, then the least installed plugins.
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
if ( $budget > 0 ) {
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
}

if ( '' !== $out ) {
	if ( ! is_dir( dirname( $out ) ) ) {
		mkdir( dirname( $out ), 0777, true );
	}
	file_put_contents( $out, $json . "\n" );
	fwrite( STDERR, sprintf( "Wrote %s: %d plugins, %d KB\n", $out, count( $dataset ), (int) ( strlen( $json ) / 1024 ) ) );
}

if ( '' !== $publish ) {
	$gz = gzencode( $json . "\n", 9 );
	file_put_contents( "$publish/signatures.json.gz", $gz );
	file_put_contents( "$publish/signatures.json.gz.sha256", hash( 'sha256', $gz ) . "  signatures.json.gz\n" );
	ksort( $next_state );
	file_put_contents( "$publish/state.json", json_encode( array( 'built' => gmdate( 'Y-m-d' ), 'plugins' => $next_state ), JSON_UNESCAPED_SLASHES ) . "\n" );
	fwrite( STDERR, sprintf( "Wrote %s: %d plugins with signatures, %d tracked, %d KB json, %d KB gzip\n", $publish, count( $dataset ), count( $next_state ), (int) ( strlen( $json ) / 1024 ), (int) ( strlen( $gz ) / 1024 ) ) );
}

if ( count( $failed ) > max( 5, (int) ( $count * 0.05 ) ) ) {
	fwrite( STDERR, "Too many downloads failed.\n" );
	exit( 1 );
}
