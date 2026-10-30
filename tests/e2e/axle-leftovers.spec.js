// End-to-end tests on the plugin's own Docker site. Every test checks the
// outcome in the database through WP-CLI as well as on screen.
const { test, expect, wp, wpEval, logInAs, appPasswordClient } = require( './utils' );
const seed = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-leftovers';
const READ_ONLY_MODE = 'https://github.com/aryanjasala/axle-read-only-mode/releases/download/v3.0.0/axle-read-only-mode.zip';
const MAKER_ITEMS = [
	'table:wp_lm_items',
	'option:lm_settings',
	'option:lm_cache',
	'option:lm_flags',
	'option:lm_installed_at',
	'option:_transient_lm_remote_data',
	'cron:lm_daily_sync',
	'cron:lm_cleanup',
	'postmeta:_lm_flag',
	'folder:leftover-maker',
	'role:lm_manager',
	'cap:lm_manage',
];

/** Runs WP-CLI and returns its error output instead of throwing. */
function failure( ...args ) {
	try {
		wp( ...args );
		return '';
	} catch ( error ) {
		return String( error.stderr || error.message );
	}
}

/** Selects every item in Leftover Maker's card and confirms the trash. */
async function trashMakerFromScreen( page ) {
	await page.goto( SCREEN );
	const card = page.locator( '.alo-card[data-owner="leftover-maker"]' );
	await card.getByText( 'Review and move to trash' ).click();
	await card.locator( '.alo-check-all' ).check();
	await card.getByRole( 'button', { name: 'Move selected to trash' } ).click();
	const dialog = page.locator( '#alo-confirm' );
	await expect( dialog ).toBeVisible();
	await dialog.getByRole( 'button', { name: 'Move to trash' } ).click();
	await expect( page.locator( '.alo-notice' ) ).toContainText( 'Moved 12 items to the trash' );
}

test.describe( 'Axle Leftovers', () => {
	test.beforeAll( () => {
		seed.installHelpers();
		seed.resetAll();
	} );

	test.afterEach( () => {
		seed.resetAll();
	} );

	test( 'deleting a plugin on the Plugins screen shows what it left, all Certain', async ( { page } ) => {
		seed.installMaker();
		wp( 'plugin', 'deactivate', 'leftover-maker' );

		await page.goto( '/wp-admin/plugins.php' );
		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.locator( 'tr[data-slug="leftover-maker"] .delete a' ).click();
		const notice = page.locator( '.alo-deleted-notice' );
		await expect( notice ).toContainText( 'Leftover Maker left 1 table, 5 options, 2 cron events, 1 meta key, 1 uploads folder, 1 role and 1 capability.' );
		expect( seed.query( "SELECT removed_how FROM wp_axle_leftovers_ledger WHERE slug = 'leftover-maker'" ) ).toEqual( [ [ 'admin' ] ] );
		// Its uninstall.php removed lm_version, and the ledger knows.
		expect( seed.succeeds( 'option', 'get', 'lm_version' ) ).toBe( false );
		expect( seed.query( "SELECT sources FROM wp_axle_leftovers_ledger WHERE slug = 'leftover-maker'" )[ 0 ][ 0 ] ).toContain( 'uninstall-diff' );

		await notice.getByRole( 'link', { name: 'Review' } ).click();
		const card = page.locator( '.alo-card[data-owner="leftover-maker"]' );
		await expect( card.locator( 'h2' ) ).toHaveText( 'Leftover Maker' );
		await expect( card.locator( '.alo-card-head .alo-badge' ) ).toHaveText( 'Certain' );
		await expect( card.locator( '.alo-meta' ) ).toContainText( 'Last version 1.2.0.' );
		await expect( card.locator( '.alo-meta' ) ).toContainText( 'from wp-admin' );
		await expect( card.locator( '.alo-summary' ) ).toContainText( 'They add' );
		await card.getByText( 'Review and move to trash' ).click();
		await expect( card.locator( 'tbody tr' ) ).toHaveCount( MAKER_ITEMS.length );
		for ( const id of MAKER_ITEMS ) {
			await expect( card.locator( `tr[data-item="${ id }"] .alo-badge` ) ).toHaveText( 'Certain' );
		}
		await expect( card.locator( 'tr[data-item="option:lm_version"]' ) ).toHaveCount( 0 );
		await expect( card.locator( 'tr[data-item="table:wp_lm_items"]' ) ).toContainText( 'Created when Leftover Maker was activated on' );
		await expect( card.locator( 'tr[data-item="option:lm_cache"]' ) ).toContainText( 'Autoloaded' );
	} );

	test( 'move everything to the trash, then restore the batch exactly', async ( { page } ) => {
		seed.makeLeftovers();
		const sum = seed.checksum( 'wp_lm_items' );
		const autoload = seed.query( "SELECT option_name, autoload FROM wp_options WHERE option_name LIKE 'lm\\_%' OR option_name LIKE '\\_transient\\_lm\\_%' ORDER BY option_name" );

		await trashMakerFromScreen( page );
		const trashed = seed.tables( 'wp\\_axlt\\_%' );
		expect( trashed ).toHaveLength( 1 );
		expect( seed.tables( 'wp\\_lm\\_items' ) ).toEqual( [] );
		expect( seed.checksum( trashed[ 0 ] ) ).toBe( sum );
		expect( seed.query( "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'lm\\_%'" ) ).toEqual( [ [ '0' ] ] );
		expect( wp( 'cron', 'event', 'list', '--fields=hook', '--format=csv' ) ).not.toContain( 'lm_' );
		expect( seed.query( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_lm_flag'" ) ).toEqual( [ [ '0' ] ] );
		expect( seed.succeeds( 'role', 'exists', 'lm_manager' ) ).toBe( false );
		expect( wpEval( 'echo get_role( "administrator" )->has_cap( "lm_manage" ) ? "yes" : "no";' ) ).toBe( 'no' );
		expect( wpEval( '$b = wp_upload_dir()["basedir"]; echo is_dir( "$b/leftover-maker" ) ? "there" : "moved", " ", count( glob( "$b/axle-leftovers/trash/*/leftover-maker/report.csv" ) );' ) ).toBe( 'moved 1' );
		expect( wpEval( 'echo count( glob( wp_upload_dir()["basedir"] . "/axle-leftovers/exports/*/*" ) );' ) ).toBe( '12' );
		expect( wpEval( 'echo is_file( wp_upload_dir()["basedir"] . "/axle-leftovers/.htaccess" ) ? "yes" : "no";' ) ).toBe( 'yes' );
		expect( seed.query( "SELECT COUNT(*) FROM wp_axle_leftovers_trash WHERE status = 'trashed'" ) ).toEqual( [ [ '12' ] ] );
		await expect( page.locator( '.alo-card[data-owner="leftover-maker"]' ) ).toHaveCount( 0 );

		await page.getByRole( 'link', { name: /^Trash/ } ).click();
		const batch = page.locator( '.alo-batch' );
		await expect( batch.locator( 'tbody tr' ) ).toHaveCount( 12 );
		await batch.getByRole( 'button', { name: 'Restore batch' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Restored 12 items.' );
		await expect( page.locator( '.alo-empty' ) ).toHaveText( 'The trash is empty.' );

		expect( seed.checksum( 'wp_lm_items' ) ).toBe( sum );
		expect( seed.tables( 'wp\\_axlt\\_%' ) ).toEqual( [] );
		expect( seed.query( "SELECT option_name, autoload FROM wp_options WHERE option_name LIKE 'lm\\_%' OR option_name LIKE '\\_transient\\_lm\\_%' ORDER BY option_name" ) ).toEqual( autoload );
		const cron = JSON.parse( wp( 'cron', 'event', 'list', '--fields=hook,next_run_gmt', '--format=json' ) ).filter( ( event ) => event.hook.startsWith( 'lm_' ) );
		expect( cron.map( ( event ) => event.hook ).sort() ).toEqual( [ 'lm_cleanup', 'lm_daily_sync' ] );
		for ( const event of cron ) {
			expect( new Date( event.next_run_gmt.replace( ' ', 'T' ) + 'Z' ).getTime() ).toBeGreaterThan( Date.now() - 60000 );
		}
		expect( seed.query( "SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = '_lm_flag'" ) ).toEqual( [ [ '20' ] ] );
		expect( seed.succeeds( 'role', 'exists', 'lm_manager' ) ).toBe( true );
		expect( wpEval( 'echo get_role( "administrator" )->has_cap( "lm_manage" ) ? "yes" : "no";' ) ).toBe( 'yes' );
		expect( wpEval( 'echo is_file( wp_upload_dir()["basedir"] . "/leftover-maker/cache/data.json" ) ? "yes" : "no";' ) ).toBe( 'yes' );
		expect( seed.query( "SELECT COUNT(*) FROM wp_axle_leftovers_trash WHERE status = 'restored'" ) ).toEqual( [ [ '12' ] ] );
	} );

	test( 'purging with WP-CLI drops the trashed table and deletes the export', async () => {
		seed.makeLeftovers();
		wp( 'axle-leftovers', 'trash', '--plugin=leftover-maker', '--yes' );
		expect( seed.tables( 'wp\\_axlt\\_%' ) ).toHaveLength( 1 );

		wp( 'axle-leftovers', 'trash', 'purge', '--older-than=0', '--yes' );
		expect( seed.tables( 'wp\\_axlt\\_%' ) ).toEqual( [] );
		expect( wpEval( 'echo count( glob( wp_upload_dir()["basedir"] . "/axle-leftovers/exports/*/*" ) ) + count( glob( wp_upload_dir()["basedir"] . "/axle-leftovers/trash/*/*" ) );' ) ).toBe( '0' );
		expect( seed.query( "SELECT DISTINCT status FROM wp_axle_leftovers_trash" ) ).toEqual( [ [ 'purged' ] ] );
		expect( wp( 'axle-leftovers', 'trash', 'list', '--format=count' ) ).toBe( '0' );
	} );

	test( 'an unknown table is listed as Unknown and needs its name typed', async ( { page, rest } ) => {
		seed.makeUnknownTable();
		wp( 'axle-leftovers', 'scan' );

		const refused = await rest( 'POST', '/axle-leftovers/v1/trash', { items: [ { type: 'table', name: 'wp_mystery_log' } ] } );
		expect( refused.status ).toBe( 409 );
		expect( refused.body.message ).toContain( 'Type the name wp_mystery_log to confirm' );
		expect( seed.tables( 'wp\\_mystery\\_log' ) ).toEqual( [ 'wp_mystery_log' ] );

		await page.goto( `${ SCREEN }&tab=unknown` );
		const row = page.locator( 'tr[data-item="table:wp_mystery_log"]' );
		await expect( row.locator( '.alo-badge' ) ).toHaveText( 'Unknown' );
		await row.locator( 'input[type="checkbox"]' ).check();
		await page.getByRole( 'button', { name: 'Move selected to trash' } ).click();
		const dialog = page.locator( '#alo-confirm' );
		await dialog.getByRole( 'button', { name: 'Move to trash' } ).click();
		await expect( dialog.locator( '.alo-confirm-error' ) ).toHaveText( 'The typed name does not match.' );
		await dialog.getByLabel( 'Type wp_mystery_log' ).fill( 'wp_mystery_lo' );
		await dialog.getByRole( 'button', { name: 'Move to trash' } ).click();
		await expect( dialog.locator( '.alo-confirm-error' ) ).toHaveText( 'The typed name does not match.' );
		expect( seed.tables( 'wp\\_mystery\\_log' ) ).toEqual( [ 'wp_mystery_log' ] );

		await dialog.getByLabel( 'Type wp_mystery_log' ).fill( 'wp_mystery_log' );
		await dialog.getByRole( 'button', { name: 'Move to trash' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Moved 1 item to the trash' );
		expect( seed.tables( 'wp\\_mystery\\_log' ) ).toEqual( [] );
		expect( seed.query( "SELECT confidence, status FROM wp_axle_leftovers_trash WHERE name = 'wp_mystery_log'" ) ).toEqual( [ [ 'unknown', 'trashed' ] ] );
	} );

	test( 'the reference check blocks an item active code still uses, and names the file', async ( { page, rest } ) => {
		seed.makeLeftovers();
		seed.installConsumer();
		wp( 'axle-leftovers', 'scan' );

		await page.goto( SCREEN );
		const card = page.locator( '.alo-card[data-owner="leftover-maker"]' );
		await card.getByText( 'Review and move to trash' ).click();
		const row = card.locator( 'tr[data-item="option:lm_settings"]' );
		await expect( row.locator( 'input[type="checkbox"]' ) ).toBeDisabled();
		await expect( row ).toContainText( 'Used by mu-plugins/lm-consumer.php. Cannot be trashed.' );

		const refused = await rest( 'POST', '/axle-leftovers/v1/trash', { items: [ { type: 'option', name: 'lm_settings' } ] } );
		expect( refused.status ).toBe( 409 );
		expect( refused.body.message ).toContain( 'mu-plugins/lm-consumer.php' );
		expect( failure( 'axle-leftovers', 'trash', 'option', 'lm_settings', '--yes' ) ).toContain( 'still used by active code' );
		expect( wp( 'option', 'get', 'lm_settings', '--format=json' ) ).toContain( 'live' );
	} );

	test( 'data of an inactive plugin, core items and its own items are never listed', async ( { page } ) => {
		seed.installMaker();
		wp( 'plugin', 'deactivate', 'leftover-maker' );
		wp( 'axle-leftovers', 'scan' );

		expect( wp( 'axle-leftovers', 'list', '--plugin=leftover-maker', '--format=count' ) ).toBe( '0' );
		const names = JSON.parse( wp( 'axle-leftovers', 'list', '--format=json' ) ).map( ( item ) => item.name );
		for ( const name of [ 'wp_lm_items', 'lm_settings', 'wp_posts', 'wp_options', 'siteurl', 'cron', 'wp_user_roles', 'wp_version_check', 'administrator', 'edit_posts', '_edit_lock', 'wp_capabilities', 'wp_axle_leftovers_trash', 'axle_leftovers_scan', 'axle_leftovers_daily' ] ) {
			expect( names ).not.toContain( name );
		}

		await page.goto( SCREEN );
		await expect( page.locator( '.alo-empty' ) ).toContainText( 'No leftovers from deleted plugins' );
	} );

	test( 'a plugin removed outside WordPress is recorded and its code signature still attributes', async ( { page } ) => {
		seed.installMaker();
		wp( 'plugin', 'deactivate', 'leftover-maker' );
		seed.removeMakerOutside();

		await page.goto( `${ SCREEN }&tab=ledger` );
		await expect( page.locator( 'tr[data-slug="leftover-maker"]' ) ).toContainText( 'outside WordPress' );
		expect( seed.query( "SELECT removed_how FROM wp_axle_leftovers_ledger WHERE slug = 'leftover-maker'" ) ).toEqual( [ [ 'outside' ] ] );

		// Forget the activation record: the code signature alone still names the owner.
		wp( 'db', 'query', "DELETE FROM wp_axle_leftovers_seen WHERE owner_slug = 'leftover-maker'" );
		wp( 'axle-leftovers', 'scan' );
		const item = JSON.parse( wp( 'axle-leftovers', 'show', 'option', 'lm_flags', '--format=json' ) );
		expect( item.owner ).toBe( 'leftover-maker' );
		expect( item.confidence ).toBe( 'likely' );
		expect( item.evidence.map( ( evidence ) => evidence.text ) ).toContain( "Named in Leftover Maker's code" );
	} );

	test( 'editors get 403 on the screen, the REST routes and the abilities', async ( { browser } ) => {
		seed.succeeds( 'user', 'create', 'leftovers-editor', 'leftovers-editor@example.com', '--role=editor', '--user_pass=editor-pass-123' );
		const { context, page } = await logInAs( browser, 'leftovers-editor', 'editor-pass-123' );
		const response = await page.goto( SCREEN );
		expect( response.status() ).toBe( 403 );
		await context.close();

		const client = await appPasswordClient( 'leftovers-editor', 'e2e-editor' );
		try {
			expect( ( await client.call( 'GET', '/axle-leftovers/v1/items' ) ).status ).toBe( 403 );
			expect( ( await client.call( 'POST', '/axle-leftovers/v1/trash', { items: [ { type: 'table', name: 'wp_x' } ] } ) ).status ).toBe( 403 );
			if ( seed.hasAbilities() ) {
				expect( ( await client.call( 'GET', '/wp-abilities/v1/abilities/axle-leftovers/list-leftovers/run' ) ).status ).toBe( 403 );
			}
		} finally {
			await client.dispose();
			seed.succeeds( 'user', 'delete', 'leftovers-editor', '--yes' );
		}
	} );

	test( 'abilities list, trash, restore and purge over REST with the right annotations', async () => {
		test.skip( ! seed.hasAbilities(), 'The Abilities API arrived in WordPress 6.9.' );
		seed.makeLeftovers();
		const client = await appPasswordClient( 'admin', 'e2e-abilities' );
		try {
			const meta = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-leftovers/trash-items' );
			expect( meta.body.meta.annotations ).toMatchObject( { readonly: false, destructive: true } );
			const restoreMeta = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-leftovers/restore-items' );
			expect( restoreMeta.body.meta.annotations ).toMatchObject( { readonly: false, destructive: false, idempotent: true } );

			const list = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-leftovers/list-leftovers/run?input[plugin]=leftover-maker' );
			expect( list.status ).toBe( 200 );
			expect( list.body.map( ( item ) => item.id ).sort() ).toEqual( [ ...MAKER_ITEMS ].sort() );

			const trashed = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-leftovers/trash-items/run', { input: { items: [ { type: 'option', name: 'lm_cache' } ] } } );
			expect( trashed.status ).toBe( 200 );
			expect( seed.succeeds( 'option', 'get', 'lm_cache' ) ).toBe( false );

			const trash = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-leftovers/list-trash/run' );
			expect( trash.body.map( ( row ) => row.name ) ).toEqual( [ 'lm_cache' ] );

			const restored = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-leftovers/restore-items/run', { input: { batch: trashed.body.batch } } );
			expect( restored.status ).toBe( 200 );
			expect( seed.query( "SELECT autoload FROM wp_options WHERE option_name = 'lm_cache'" ) ).toEqual( [ [ 'on' ] ] );

			await client.call( 'POST', '/wp-abilities/v1/abilities/axle-leftovers/trash-items/run', { input: { items: [ { type: 'cron', name: 'lm_cleanup' } ] } } );
			const purged = await client.call( 'DELETE', '/wp-abilities/v1/abilities/axle-leftovers/purge-trash/run?input[older_than]=0' );
			expect( purged.status ).toBe( 200 );
			expect( purged.body.purged.map( ( row ) => row.name ) ).toEqual( [ 'lm_cleanup' ] );
			expect( seed.query( "SELECT status FROM wp_axle_leftovers_trash WHERE name = 'lm_cleanup'" ) ).toEqual( [ [ 'purged' ] ] );
		} finally {
			await client.dispose();
		}
	} );

	test( 'the screen hooks: confirmMessage filter, trashed and restored actions', async ( { page } ) => {
		seed.makeLeftovers();
		await page.addInitScript( () => {
			window.aloEvents = [];
			document.addEventListener( 'DOMContentLoaded', () => {
				window.wp.hooks.addAction( 'axleLeftovers.trashed', 'e2e', ( payload ) => window.aloEvents.push( [ 'trashed', payload.batch, payload.items.length ] ) );
				window.wp.hooks.addAction( 'axleLeftovers.restored', 'e2e', ( payload ) => window.aloEvents.push( [ 'restored', payload.restored.length ] ) );
			} );
		} );

		await page.goto( SCREEN );
		await page.evaluate( () => window.wp.hooks.addFilter( 'axleLeftovers.confirmMessage', 'e2e', ( message, { items } ) => `${ message } Deploy window open? (${ items.length })` ) );
		const card = page.locator( '.alo-card[data-owner="leftover-maker"]' );
		await card.getByText( 'Review and move to trash' ).click();
		await card.locator( 'tr[data-item="option:lm_flags"] input[type="checkbox"]' ).check();
		await card.getByRole( 'button', { name: 'Move selected to trash' } ).click();
		await expect( page.locator( '#alo-confirm-message' ) ).toContainText( 'Deploy window open? (1)' );
		await page.locator( '#alo-confirm' ).getByRole( 'button', { name: 'Move to trash' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Moved 1 item' );
		const batch = seed.query( "SELECT batch_id FROM wp_axle_leftovers_trash WHERE name = 'lm_flags'" )[ 0 ][ 0 ];
		expect( await page.evaluate( () => window.aloEvents ) ).toEqual( [ [ 'trashed', batch, 1 ] ] );

		await page.goto( `${ SCREEN }&tab=trash` );
		await page.getByRole( 'button', { name: 'Restore lm_flags' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Restored 1 item.' );
		expect( await page.evaluate( () => window.aloEvents ) ).toEqual( [ [ 'restored', 1 ] ] );
		expect( seed.succeeds( 'option', 'get', 'lm_flags' ) ).toBe( true );
	} );

	test( 'deactivating with items in the trash warns first and offers Restore all', async ( { page } ) => {
		seed.makeLeftovers();
		wp( 'axle-leftovers', 'trash', 'cron', 'lm_cleanup', '--yes' );

		await page.goto( '/wp-admin/plugins.php' );
		await page.locator( 'tr[data-plugin="axle-leftovers/axle-leftovers.php"] .deactivate a' ).click();
		const dialog = page.locator( '#alo-deactivate' );
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toContainText( 'The trash holds 1 item.' );
		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toBeHidden();
		expect( wp( 'plugin', 'is-active', 'axle-leftovers' ) ).toBe( '' );

		await page.locator( 'tr[data-plugin="axle-leftovers/axle-leftovers.php"] .deactivate a' ).click();
		await dialog.getByRole( 'button', { name: 'Restore all' } ).click();
		await page.waitForURL( /plugins\.php/ );
		expect( seed.query( "SELECT status FROM wp_axle_leftovers_trash WHERE name = 'lm_cleanup'" ) ).toEqual( [ [ 'restored' ] ] );
		expect( wp( 'cron', 'event', 'list', '--fields=hook', '--format=csv' ) ).toContain( 'lm_cleanup' );
		await expect( page.locator( '#alo-deactivate' ) ).toHaveCount( 0 );
	} );

	test( 'exports download only for the capability; the folder is not served', async ( { page, request, browser } ) => {
		seed.makeLeftovers();
		wp( 'axle-leftovers', 'trash', 'table', 'wp_lm_items', '--yes' );

		await page.goto( `${ SCREEN }&tab=trash` );
		const link = page.getByRole( 'link', { name: 'Download the export of wp_lm_items' } );
		const href = await link.getAttribute( 'href' );
		const download = await request.get( href );
		expect( download.status() ).toBe( 200 );
		const sql = await download.text();
		expect( sql ).toContain( 'CREATE TABLE `wp_lm_items`' );
		expect( sql ).toContain( 'person50@example.com' );

		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const anonymous = await visitor.request.get( href, { maxRedirects: 0 } );
		expect( anonymous.status() ).not.toBe( 200 );
		const file = wpEval( 'echo str_replace( ABSPATH, "/", current( glob( wp_upload_dir()["basedir"] . "/axle-leftovers/exports/*/*.sql" ) ) );' );
		expect( ( await visitor.request.get( file ) ).status() ).toBe( 403 );
		await visitor.close();
	} );

	test( 'settings are saved and clamped', async ( { page } ) => {
		await page.goto( `${ SCREEN }&tab=settings` );
		await page.getByLabel( 'Keep trashed items for' ).fill( '7' );
		await page.getByLabel( 'Largest table to export' ).fill( '50' );
		await page.getByLabel( 'Also record new post, user, term and comment meta keys' ).uncheck();
		await page.getByLabel( 'Keep export files when the trash is purged' ).check();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Settings saved.' );
		expect( JSON.parse( wp( 'option', 'get', 'axle_leftovers_settings', '--format=json' ) ) ).toEqual( { retention_days: 7, export_max_mb: 50, keep_exports: true, runtime_ledger: true, track_meta_keys: false } );
		await expect( page.getByLabel( 'Keep trashed items for' ) ).toHaveValue( '7' );
	} );

	test.describe( 'with Axle Read-Only Mode', () => {
		let installed = false;
		let wasInstalled = false;

		test.beforeAll( () => {
			wasInstalled = seed.succeeds( 'plugin', 'is-installed', 'axle-read-only-mode' );
			installed = wasInstalled ? seed.succeeds( 'plugin', 'activate', 'axle-read-only-mode' ) : seed.succeeds( 'plugin', 'install', READ_ONLY_MODE, '--activate' );
		} );

		test.afterAll( () => {
			seed.succeeds( 'axle-read-only-mode', 'off' );
			// Only remove what this suite downloaded; a mounted copy is someone's source.
			if ( ! wasInstalled ) {
				seed.succeeds( 'plugin', 'uninstall', 'axle-read-only-mode', '--deactivate' );
			} else {
				seed.succeeds( 'plugin', 'deactivate', 'axle-read-only-mode' );
			}
		} );

		test( 'a freeze refuses trash, restore and purge', async ( { rest } ) => {
			test.skip( ! installed, 'Axle Read-Only Mode could not be downloaded.' );
			seed.makeLeftovers();
			wp( 'axle-leftovers', 'trash', 'cron', 'lm_cleanup', '--yes' );
			const batch = seed.query( "SELECT batch_id FROM wp_axle_leftovers_trash WHERE name = 'lm_cleanup'" )[ 0 ][ 0 ];

			wp( 'axle-read-only-mode', 'on' );
			try {
				expect( failure( 'axle-leftovers', 'trash', 'option', 'lm_flags', '--yes' ) ).toContain( 'Read-Only Mode has frozen this site' );
				expect( failure( 'axle-leftovers', 'restore', batch ) ).toContain( 'Read-Only Mode has frozen this site' );
				expect( failure( 'axle-leftovers', 'trash', 'purge', '--yes' ) ).toContain( 'Read-Only Mode has frozen this site' );
				const restore = await rest( 'POST', '/axle-leftovers/v1/restore', { batch } );
				expect( [ 423, 503 ] ).toContain( restore.status );
			} finally {
				wp( 'axle-read-only-mode', 'off' );
			}
			expect( seed.succeeds( 'option', 'get', 'lm_flags' ) ).toBe( true );
			expect( seed.query( "SELECT status FROM wp_axle_leftovers_trash WHERE name = 'lm_cleanup'" ) ).toEqual( [ [ 'trashed' ] ] );
		} );
	} );
} );
