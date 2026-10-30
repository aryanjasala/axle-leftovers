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

/** Puts the site back: no fixture plugin, no leftovers, empty trash and ledger. */
function resetAll() {
	succeeds( 'plugin', 'deactivate', 'leftover-maker' );
	removeMakerOutside();
	removeConsumer();
	for ( const table of [ ...tables( 'wp\\_lm\\_%' ), ...tables( 'wp\\_axlt\\_%' ), ...tables( 'wp\\_mystery\\_%' ), ...tables( 'wp\\_redirection\\_%' ) ] ) {
		wp( 'db', 'query', `DROP TABLE IF EXISTS ${ table }` );
	}
	for ( const option of [ ...MAKER_OPTIONS, 'redirection_options' ] ) {
		succeeds( 'option', 'delete', option );
	}
	wpEval(
		'foreach ( array( "lm_daily_sync", "lm_cleanup" ) as $h ) { wp_unschedule_hook( $h ); } remove_role( "lm_manager" ); foreach ( wp_roles()->role_objects as $r ) { $r->remove_cap( "lm_manage" ); } delete_post_meta_by_key( "_lm_flag" );' +
			'$base = wp_upload_dir()["basedir"]; foreach ( array( "leftover-maker", "axle-leftovers" ) as $d ) { if ( is_dir( "$base/$d" ) ) { $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$base/$d", FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ); foreach ( $it as $f ) { $f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); } rmdir( "$base/$d" ); } }'
	);
	for ( const table of [ 'wp_axle_leftovers_trash', 'wp_axle_leftovers_seen', 'wp_axle_leftovers_ledger' ] ) {
		wp( 'db', 'query', `TRUNCATE TABLE ${ table }` );
	}
	for ( const option of [ 'axle_leftovers_scan', 'axle_leftovers_settings' ] ) {
		succeeds( 'option', 'delete', option );
	}
	wp( 'db', 'query', "DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_axle\\_leftovers\\_%' OR option_name LIKE '\\_transient\\_timeout\\_axle\\_leftovers\\_%'" );
	succeeds( 'cron', 'event', 'unschedule', 'axle_leftovers_scan_step' );

	removeMockHttp();
	for ( const option of [ 'axle_leftovers_lookups', 'axle_leftovers_dataset_meta', 'lmx_unique_setting', 'lmx_shared_setting', 'lmx_nobody_setting', 'lmx_dataset_setting' ] ) {
		succeeds( 'option', 'delete', option );
	}
}

/** Leftovers of Redirection, deleted before Leftovers was installed: known only from the dataset. */
function makeDatasetLeftovers() {
	wp( 'db', 'query', 'CREATE TABLE IF NOT EXISTS wp_redirection_items ( id int unsigned NOT NULL AUTO_INCREMENT, url varchar(200), action_data varchar(200), PRIMARY KEY (id) )' );
	wp( 'db', 'query', 'CREATE TABLE IF NOT EXISTS wp_redirection_logs ( id int unsigned NOT NULL AUTO_INCREMENT, url varchar(200), PRIMARY KEY (id) )' );
	const rows = Array.from( { length: 40 }, ( _, i ) => `('/old-page-${ i }', '/new-page-${ i }')` ).join( ', ' );
	wp( 'db', 'query', `INSERT INTO wp_redirection_items (url, action_data) VALUES ${ rows }` );
	wp( 'option', 'update', 'redirection_options', JSON.stringify( { support: false, monitor_post: 0, expire_redirect: 7 } ), '--format=json', '--autoload=yes' );
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
	tables,
	checksum,
	query,
	resetAll,
	hasAbilities,
};
