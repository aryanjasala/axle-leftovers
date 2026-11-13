// Shared set-up for the specs, the accessibility screens, the screenshots and the demo.
const { execFileSync } = require( 'child_process' );
const { wp, wpEval } = require( './utils' );
const { COMPOSE_DIR } = require( './utils/config' );

const FIXTURES = 'WP_PLUGIN_DIR . "/axle-leftovers/tests/e2e/fixtures"';
const MAKER_OPTIONS = [ 'lm_settings', 'lm_version', 'lm_cache', 'lm_flags', 'lm_installed_at', '_transient_lm_remote_data', '_transient_timeout_lm_remote_data' ];

/** Whether a WP-CLI command succeeds. */
function succeeds( ...args ) {
	try {
		wp( ...args );
		return true;
	} catch ( error ) {
		return false;
	}
}

/** Runs a shell command in the cli container, as WordPress's file owner. */
function shell( ...args ) {
	return execFileSync( 'docker', [ 'compose', 'exec', '-T', 'cli', ...args ], { cwd: COMPOSE_DIR, encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } ).trim();
}

/** Copies the helper must-use plugin (application passwords on plain HTTP). */
function installHelpers() {
	wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( ${ FIXTURES } . "/axle-leftovers-e2e.php", WPMU_PLUGIN_DIR . "/axle-leftovers-e2e.php" );` );
}

/** Copies the must-use plugin that answers the online lookup and the list download, so no test calls a real service. */
function installMockHttp() {
	wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( ${ FIXTURES } . "/axle-leftovers-mock-http.php", WPMU_PLUGIN_DIR . "/axle-leftovers-mock-http.php" );` );
}

/** Removes the HTTP mock and its log. */
function removeMockHttp() {
	wpEval( '@unlink( WPMU_PLUGIN_DIR . "/axle-leftovers-mock-http.php" );' );
	for ( const option of [ 'alo_e2e_http_log', 'alo_e2e_last_term', 'alo_e2e_interval', 'alo_e2e_bad_checksum' ] ) {
		succeeds( 'option', 'delete', option );
	}
}

/** Turns on the online lookup and the list download (both are off by default). */
function enableOnline() {
	wp( 'option', 'update', 'axle_leftovers_settings', JSON.stringify( { online_lookup: true, remote_dataset: true } ), '--format=json' );
}

/** Three unknown options for the lookup specs, with the mock and the settings on. */
function makeLookupItems() {
	installMockHttp();
	enableOnline();
	for ( const name of [ 'lmx_unique_setting', 'lmx_shared_setting', 'lmx_nobody_setting', 'lmx_dataset_setting' ] ) {
		wp( 'option', 'update', name, 'value', '--autoload=no' );
	}
	wp( 'axle-leftovers', 'scan' );
}

/** The HTTP requests the mock saw. */
function httpLog() {
	try {
		return JSON.parse( wp( 'option', 'get', 'alo_e2e_http_log', '--format=json' ) );
	} catch ( error ) {
		return []; // The option does not exist: nothing was requested.
	}
}

/** Copies the consumer must-use plugin that still reads lm_settings. */
function installConsumer() {
	wpEval( `wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( ${ FIXTURES } . "/lm-consumer.php", WPMU_PLUGIN_DIR . "/lm-consumer.php" );` );
}

/** Removes the consumer. */
function removeConsumer() {
	wpEval( '@unlink( WPMU_PLUGIN_DIR . "/lm-consumer.php" );' );
}

/** Makes sure there are 20 posts for the fixture's post meta. */
function ensurePosts() {
	const count = Number( wp( 'post', 'list', '--post_type=post', '--format=count' ) );
	if ( count < 20 ) {
		wp( 'post', 'generate', `--count=${ 20 - count }`, '--post_status=publish' );
	}
}

/** Installs and activates Leftover Maker, which creates one of every item type. */
function installMaker() {
	ensurePosts();
	wpEval(
		`$copy = function ( $from, $to ) use ( &$copy ) { wp_mkdir_p( $to ); foreach ( scandir( $from ) as $f ) { if ( "." === $f[0] ) { continue; } is_dir( "$from/$f" ) ? $copy( "$from/$f", "$to/$f" ) : copy( "$from/$f", "$to/$f" ); } }; $copy( ${ FIXTURES } . "/leftover-maker", WP_PLUGIN_DIR . "/leftover-maker" );`
	);
	wp( 'plugin', 'activate', 'leftover-maker' );
}

/** Deletes Leftover Maker the way WordPress does: deactivate, uninstall.php, delete. */
function uninstallMaker() {
	wp( 'plugin', 'deactivate', 'leftover-maker' );
	wp( 'plugin', 'uninstall', 'leftover-maker' );
}

/** Installs Leftover Maker and deletes it, so its leftovers are on the site. */
function makeLeftovers() {
	installMaker();
	uninstallMaker();
	wp( 'axle-leftovers', 'scan' );
}

/** Removes Leftover Maker's folder from outside WordPress. */
function removeMakerOutside() {
	shell( 'rm', '-rf', '/var/www/html/wp-content/plugins/leftover-maker' );
}

/** Creates a table nothing knows about. */
function makeUnknownTable() {
	wp( 'db', 'query', 'CREATE TABLE IF NOT EXISTS wp_mystery_log ( id int unsigned NOT NULL AUTO_INCREMENT, note varchar(50), PRIMARY KEY (id) )' );
	wp( 'db', 'query', "INSERT INTO wp_mystery_log (note) VALUES ('a'), ('b'), ('c')" );
}

/** Tables whose names match a LIKE pattern. */
function tables( like ) {
	return wp( 'db', 'query', `SHOW TABLES LIKE '${ like }'`, '--skip-column-names' ).split( '\n' ).filter( Boolean );
}

/** A table's checksum. */
function checksum( table ) {
	return wp( 'db', 'query', `CHECKSUM TABLE ${ table }`, '--skip-column-names' ).split( '\t' )[ 1 ];
}

/** Rows of an SQL query as arrays of columns. */
function query( sql ) {
	return wp( 'db', 'query', sql, '--skip-column-names' )
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => line.split( '\t' ) );
}

/**
 * Puts the site back: no fixture plugin, no leftovers, empty trash and ledger.
 *
 * Every WP-CLI call starts a container command, so this does its work in one PHP run.
 */
function resetAll() {
	succeeds( 'plugin', 'deactivate', 'leftover-maker' );
	removeMakerOutside();
	const options = [ ...MAKER_OPTIONS, 'redirection_options', 'axle_leftovers_scan', 'axle_leftovers_settings', 'alo_e2e_http_log', 'alo_e2e_last_term', 'alo_e2e_interval', 'alo_e2e_bad_checksum', 'axle_leftovers_lookups', 'axle_leftovers_dataset_meta', 'lmx_unique_setting', 'lmx_shared_setting', 'lmx_nobody_setting', 'lmx_dataset_setting' ];
	wpEval(
		'global $wpdb; @unlink( WPMU_PLUGIN_DIR . "/lm-consumer.php" ); @unlink( WPMU_PLUGIN_DIR . "/axle-leftovers-mock-http.php" );' +
			'foreach ( $wpdb->get_col( "SHOW TABLES" ) as $t ) { if ( preg_match( "/^wp_(lm|axlt|mystery|redirection)_/", $t ) ) { $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); } }' +
			`foreach ( json_decode( '${ JSON.stringify( options ) }' ) as $o ) { delete_option( $o ); }` +
			'foreach ( array( "lm_daily_sync", "lm_cleanup" ) as $h ) { wp_unschedule_hook( $h ); } remove_role( "lm_manager" ); foreach ( wp_roles()->role_objects as $r ) { $r->remove_cap( "lm_manage" ); } delete_post_meta_by_key( "_lm_flag" );' +
			'$base = wp_upload_dir()["basedir"]; foreach ( array( "leftover-maker", "axle-leftovers" ) as $d ) { if ( is_dir( "$base/$d" ) ) { $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$base/$d", FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); } rmdir( "$base/$d" ); } }' +
			'foreach ( array( "wp_axle_leftovers_trash", "wp_axle_leftovers_seen", "wp_axle_leftovers_ledger" ) as $t ) { $wpdb->query( "TRUNCATE TABLE `$t`" ); }' +
			'foreach ( array( "_transient_axle_leftovers_", "_transient_timeout_axle_leftovers_" ) as $p ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $p ) . "%" ) ); }' +
			'wp_clear_scheduled_hook( "axle_leftovers_scan_step" );'
	);
}

/** Leftovers of Redirection, deleted before Leftovers was installed: known only from the dataset. */
function makeDatasetLeftovers() {
	wp( 'db', 'query', 'CREATE TABLE IF NOT EXISTS wp_redirection_items ( id int unsigned NOT NULL AUTO_INCREMENT, url varchar(200), action_data varchar(200), PRIMARY KEY (id) )' );
	wp( 'db', 'query', 'CREATE TABLE IF NOT EXISTS wp_redirection_logs ( id int unsigned NOT NULL AUTO_INCREMENT, url varchar(200), PRIMARY KEY (id) )' );
	const rows = Array.from( { length: 40 }, ( _, i ) => `('/old-page-${ i }', '/new-page-${ i }')` ).join( ', ' );
	wp( 'db', 'query', `INSERT INTO wp_redirection_items (url, action_data) VALUES ${ rows }` );
	wp( 'option', 'update', 'redirection_options', JSON.stringify( { support: false, monitor_post: 0, expire_redirect: 7 } ), '--format=json', '--autoload=yes' );
}

/** The theme the site runs with now. */
function activeTheme() {
	return wp( 'theme', 'list', '--status=active', '--field=name' ).split( '\n' )[ 0 ];
}

/** Copies Leftover Theme into the themes folder. */
function installTheme() {
	wpEval(
		`$copy = function ( $from, $to ) use ( &$copy ) { wp_mkdir_p( $to ); foreach ( scandir( $from ) as $f ) { if ( "." === $f[0] ) { continue; } is_dir( "$from/$f" ) ? $copy( "$from/$f", "$to/$f" ) : copy( "$from/$f", "$to/$f" ); } }; $copy( ${ FIXTURES } . "/leftover-theme", get_theme_root() . "/leftover-theme" );`
	);
}

/**
 * Block theme content for a theme folder: two templates and a template part with meta, a revision,
 * a global styles entry, all tagged with the theme. Returns the IDs by kind.
 */
function makeThemeContent( stylesheet, withCss = false ) {
	return JSON.parse(
		wpEval(
			`$t = ${ JSON.stringify( stylesheet ) }; $ids = array( "template" => array(), "globalstyles" => array(), "revisions" => array() );
			foreach ( array( array( "wp_template", "single", "Single" ), array( "wp_template", "page", "Page" ), array( "wp_template_part", "header", "Header" ) ) as $d ) {
				$id = wp_insert_post( array( "post_type" => $d[0], "post_name" => $d[1], "post_title" => $d[2], "post_status" => "publish", "post_content" => "<!-- wp:paragraph --><p>" . $d[2] . "</p><!-- /wp:paragraph -->" ) );
				wp_set_object_terms( $id, $t, "wp_theme" );
				if ( "wp_template_part" === $d[0] ) { wp_set_object_terms( $id, "header", "wp_template_part_area" ); }
				update_post_meta( $id, "origin", "theme" );
				$ids["template"][] = $id;
			}
			$rev = wp_insert_post( array( "post_type" => "revision", "post_status" => "inherit", "post_parent" => $ids["template"][0], "post_name" => $ids["template"][0] . "-revision-v1", "post_title" => "Single", "post_content" => "older" ) );
			$ids["revisions"][] = $rev;
			$g = wp_insert_post( array( "post_type" => "wp_global_styles", "post_name" => "wp-global-styles-" . $t, "post_title" => "Custom Styles", "post_status" => "publish", "post_content" => "{\\"version\\":3,\\"isGlobalStylesUserThemeJSON\\":true}" ) );
			wp_set_object_terms( $g, $t, "wp_theme" );
			$ids["globalstyles"][] = $g;
			echo wp_json_encode( $ids );`
		)
	);
}

/** Installs Leftover Theme, lets it create its data, switches back and deletes it. Returns the block content IDs. */
function makeThemeLeftovers() {
	const original = activeTheme();
	ensurePosts();
	installTheme();
	wp( 'theme', 'activate', 'leftover-theme' );
	wp( 'option', 'get', 'lt_version' ); // Any request runs the theme's first-run code.
	const ids = makeThemeContent( 'leftover-theme' );
	wp( 'theme', 'activate', original );
	wp( 'theme', 'delete', 'leftover-theme' );
	wp( 'axle-leftovers', 'scan' );
	return ids;
}

/** Puts the site back after the theme specs: the original theme, no fixture theme, none of its data. */
function resetThemes() {
	const names = wp( 'theme', 'list', '--field=name' ).split( '\n' );
	if ( names.includes( 'leftover-theme' ) ) {
		const active = activeTheme();
		if ( 'leftover-theme' === active ) {
			const other = names.find( ( name ) => 'leftover-theme' !== name );
			wp( 'theme', 'activate', other );
		}
		succeeds( 'theme', 'delete', 'leftover-theme' );
	}
	wpEval( '$b = get_theme_root() . "/leftover-theme"; if ( is_dir( $b ) ) { $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $b, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); } rmdir( $b ); }' );
	for ( const option of [ 'lt_settings', 'lt_version', 'lt_cache', '_transient_lt_feed', '_transient_timeout_lt_feed', 'theme_mods_leftover-theme', 'theme_mods_ghost-theme' ] ) {
		succeeds( 'option', 'delete', option );
	}
	succeeds( 'cron', 'event', 'unschedule', 'lt_refresh' );
	wpEval(
		'wp_unschedule_hook( "lt_refresh" ); delete_post_meta_by_key( "_lt_layout" ); global $wpdb;' +
			'$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( \'wp_template\', \'wp_template_part\', \'wp_global_styles\', \'custom_css\' ) OR ( post_type = \'revision\' AND post_name LIKE \'%-revision-v1\' AND post_content = \'older\' )" );' +
			'foreach ( $ids as $id ) { $wpdb->delete( $wpdb->postmeta, array( "post_id" => $id ) ); $wpdb->delete( $wpdb->term_relationships, array( "object_id" => $id ) ); $wpdb->delete( $wpdb->posts, array( "ID" => $id ) ); }' +
			'foreach ( array( "wp_theme" ) as $tax ) { foreach ( get_terms( array( "taxonomy" => $tax, "hide_empty" => false ) ) as $term ) { if ( in_array( $term->name, array( "leftover-theme", "ghost-theme" ), true ) ) { wp_delete_term( $term->term_id, $tax ); } } }'
	);
}

/** Everything the screenshots and the demo show. */
function seedScreens() {
	resetAll();
	makeLeftovers();
	makeDatasetLeftovers();
	makeUnknownTable();
	wp( 'axle-leftovers', 'scan' );
}

/** Whether this WordPress has the Abilities API. */
function hasAbilities() {
	return 'yes' === wpEval( 'echo function_exists( "wp_register_ability" ) ? "yes" : "no";' );
}

module.exports = {
	succeeds,
	shell,
	installHelpers,
	installConsumer,
	removeConsumer,
	installMockHttp,
	removeMockHttp,
	enableOnline,
	makeLookupItems,
	httpLog,
	installMaker,
	uninstallMaker,
	makeLeftovers,
	removeMakerOutside,
	makeUnknownTable,
	makeDatasetLeftovers,
	seedScreens,
	activeTheme,
	installTheme,
	makeThemeContent,
	makeThemeLeftovers,
	resetThemes,
	tables,
	checksum,
	query,
	resetAll,
	hasAbilities,
};
