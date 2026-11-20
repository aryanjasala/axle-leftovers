// A plugin whose leftovers are in the trash is installed again: its data comes back
// before activation creates empty tables and options, nothing it wrote is replaced
// without a choice, and every restore has Undo. Leftover Maker is the fixture plugin:
// it creates a table and options on activation, and its uninstall.php removes one option.
const { test, expect, wp, appPasswordClient } = require( './utils' );
const seed = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-leftovers';
const CUSTOM = { mode: 'custom', items: 99 };

/** Runs WP-CLI and returns its error output instead of throwing. */
function failure( ...args ) {
	try {
		wp( ...args );
		return '';
	} catch ( error ) {
		return String( error.stderr || error.message );
	}
}

/** The option's JSON value, or null when it does not exist. */
function option( name ) {
	try {
		return JSON.parse( wp( 'option', 'get', name, '--format=json' ) );
	} catch ( error ) {
		return null;
	}
}

const reinstallSetup = seed.makeReinstall;

/** How many rows Leftover Maker's table holds. */
function itemRows() {
	return Number( seed.query( 'SELECT COUNT(*) FROM wp_lm_items' )[ 0 ][ 0 ] );
}

test.describe( 'A reinstalled plugin gets its data back', () => {
	test.beforeAll( () => {
		seed.installHelpers();
	} );

	test.afterAll( () => {
		seed.resetAll();
	} );

	test( 'activating from the Plugins screen stops at a preview, restores first, then activates, and Undo puts it back', async ( { page } ) => {
		reinstallSetup();
		expect( seed.tables( 'wp\\_lm\\_items' ) ).toEqual( [] );

		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( '.alo-reinstall-notice' ) ).toContainText( 'Leftover Maker is installed and the Leftovers trash holds what it left before' );
		await page.locator( 'tr[data-slug="leftover-maker"] .activate a' ).click();

		// Stopped: not active, no empty table created yet.
		await expect( page ).toHaveURL( /reinstall=leftover-maker/ );
		await expect( page.locator( '.alo-reinstall-stop' ) ).toContainText( 'Activating it first would create empty tables and options' );
		expect( wp( 'plugin', 'list', '--name=leftover-maker', '--field=status' ) ).toBe( 'inactive' );
		expect( seed.tables( 'wp\\_lm\\_items' ) ).toEqual( [] );
		await expect( page.locator( '.alo-reinstall-items tr[data-state="free"]' ) ).toHaveCount( 12 );
		await expect( page.locator( 'tr[data-trash-id]', { hasText: 'wp_lm_items' } ) ).toContainText( '51 rows' );

		await page.getByRole( 'button', { name: 'Restore selected and activate' } ).click();
		await expect( page ).toHaveURL( /plugins\.php/ );
		await expect( page.locator( 'tr[data-slug="leftover-maker"].active' ) ).toBeVisible();

		// The old rows were there when the plugin activated: its 50 new rows came on top.
		expect( seed.query( "SELECT COUNT(*) FROM wp_lm_items WHERE label = 'Item custom'" )[ 0 ][ 0 ] ).toBe( '1' );
		expect( itemRows() ).toBe( 101 );
		expect( option( 'lm_settings' ) ).toEqual( CUSTOM );
		expect( seed.query( "SELECT status, COUNT(*) FROM wp_axle_leftovers_trash WHERE owner_slug = 'leftover-maker' GROUP BY status" ) ).toEqual( [ [ 'restored', '12' ] ] );

		// Undo, from the Plugins screen: what came back is in the trash again.
		await expect( page.locator( '.alo-restored-notice' ) ).toContainText( 'Brought back 12 items for leftover-maker' );
		await page.locator( '.alo-restored-notice' ).getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.alo-restored-notice' ) ).toHaveCount( 0 );
		expect( seed.tables( 'wp\\_lm\\_items' ) ).toEqual( [] );
		expect( option( 'lm_settings' ) ).toBeNull();
		expect( seed.query( "SELECT status, COUNT(*) FROM wp_axle_leftovers_trash WHERE owner_slug = 'leftover-maker' GROUP BY status ORDER BY status" ) ).toEqual( [ [ 'restored', '12' ], [ 'trashed', '12' ] ] );
	} );

	test( 'activating without restoring starts fresh, and the old data can still be chosen item by item', async ( { page } ) => {
		reinstallSetup();
		await page.goto( '/wp-admin/plugins.php' );
		await page.locator( 'tr[data-slug="leftover-maker"] .activate a' ).click();
		await page.getByRole( 'button', { name: 'Activate without restoring' } ).click();
		await expect( page.locator( 'tr[data-slug="leftover-maker"].active' ) ).toBeVisible();
		expect( itemRows() ).toBe( 50 );
		expect( option( 'lm_settings' ) ).toEqual( { mode: 'live', items: 50 } );

		// The offer stays on By owner, and the clashes show both sides.
		await page.goto( SCREEN );
		const card = page.locator( '.alo-reinstall-card[data-plugin="leftover-maker"]' );
		await expect( card ).toContainText( 'Leftover Maker is installed again' );
		await card.getByRole( 'link', { name: 'Preview and restore' } ).click();
		const table = page.locator( 'tr[data-trash-id]', { hasText: 'wp_lm_items' } );
		await expect( table ).toHaveAttribute( 'data-state', 'conflict' );
		await expect( table.locator( 'del' ) ).toContainText( '51 rows' );
		await expect( table.locator( 'ins' ) ).toContainText( '50 rows' );
		const settings = page.locator( 'tr[data-trash-id]', { hasText: 'lm_settings' } );
		await expect( settings.locator( 'del' ) ).toContainText( 'custom' );
		await expect( settings.locator( 'ins' ) ).toContainText( 'live' );
		await expect( page.locator( 'tr[data-state="same"]' ) ).toHaveCount( 4 );
		await expect( page.locator( 'tr[data-state="taken"]' ) ).toHaveCount( 6 );

		// The table keeps the default (what the plugin wrote), the option takes the old value.
		await settings.getByLabel( /Use the old data/ ).check();
		await page.getByRole( 'button', { name: 'Restore selected' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Restored 1 item. 1 item the plugin wrote since was moved to the trash first.' );
		expect( option( 'lm_settings' ) ).toEqual( CUSTOM );
		expect( itemRows() ).toBe( 50 );
		expect( seed.query( "SELECT COUNT(*) FROM wp_axle_leftovers_trash WHERE name = 'lm_settings' AND status = 'trashed'" )[ 0 ][ 0 ] ).toBe( '1' );

		// Undo brings back what the plugin wrote.
		await page.locator( '.alo-notice' ).getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Undone' );
		expect( option( 'lm_settings' ) ).toEqual( { mode: 'live', items: 50 } );
		expect( itemRows() ).toBe( 50 );
	} );

	test( 'WP-CLI stops the activation with the commands to run, and restore then activate works', async () => {
		reinstallSetup();
		const stopped = failure( 'plugin', 'activate', 'leftover-maker' );
		expect( stopped ).toContain( 'wp axle-leftovers reinstall preview leftover-maker' );
		expect( wp( 'plugin', 'list', '--name=leftover-maker', '--field=status' ) ).toBe( 'inactive' );
		expect( seed.tables( 'wp\\_lm\\_items' ) ).toEqual( [] );

		expect( wp( 'axle-leftovers', 'reinstall', 'preview', 'leftover-maker', '--format=csv' ) ).toContain( 'wp_lm_items,free' );
		expect( wp( 'axle-leftovers', 'reinstall', 'list', '--format=csv' ) ).toContain( 'leftover-maker' );
		const out = wp( 'axle-leftovers', 'reinstall', 'restore', 'leftover-maker' );
		expect( out ).toContain( 'Restored 12 items' );
		wp( 'plugin', 'activate', 'leftover-maker' );
		expect( option( 'lm_settings' ) ).toEqual( CUSTOM );
		expect( itemRows() ).toBe( 101 );

		const id = /undo (r\w+)/.exec( out )[ 1 ];
		wp( 'axle-leftovers', 'reinstall', 'undo', id );
		expect( option( 'lm_settings' ) ).toBeNull();
		expect( failure( 'axle-leftovers', 'reinstall', 'undo', id ) ).toContain( 'cannot be undone any more' );
	} );

	test( 'the mode filter restores what is free without a question, and off does nothing', async () => {
		reinstallSetup();
		wp( 'option', 'update', 'axle_leftovers_settings', JSON.stringify( { reinstall_offer: false } ), '--format=json' );
		wp( 'plugin', 'activate', 'leftover-maker' );
		expect( option( 'lm_settings' ) ).toEqual( { mode: 'live', items: 50 } );
		expect( itemRows() ).toBe( 50 );
		wp( 'option', 'delete', 'axle_leftovers_settings' );
	} );

	test( 'REST and abilities preview, restore and undo, for administrators only', async ( { rest } ) => {
		reinstallSetup();
		const list = await rest( 'GET', '/axle-leftovers/v1/reinstall' );
		expect( list.status ).toBe( 200 );
		expect( list.body.plugins.map( ( entry ) => entry.slug ) ).toEqual( [ 'leftover-maker' ] );
		const plan = await rest( 'GET', '/axle-leftovers/v1/reinstall?plugin=leftover-maker' );
		expect( plan.body.plan ).toHaveLength( 12 );
		const table = plan.body.plan.find( ( entry ) => 'wp_lm_items' === entry.name );

		seed.succeeds( 'user', 'create', 'leftovers-editor', 'leftovers-editor@example.com', '--role=editor', '--user_pass=editor-pass-123' );
		const client = await appPasswordClient( 'leftovers-editor', 'e2e-editor' );
		try {
			expect( ( await client.call( 'GET', '/axle-leftovers/v1/reinstall' ) ).status ).toBe( 403 );
			expect( ( await client.call( 'POST', '/axle-leftovers/v1/reinstall/restore', { plugin: 'leftover-maker', items: { [ table.id ]: 'restore' } } ) ).status ).toBe( 403 );
		} finally {
			await client.dispose();
			seed.succeeds( 'user', 'delete', 'leftovers-editor', '--yes' );
		}

		const restored = await rest( 'POST', '/axle-leftovers/v1/reinstall/restore', { plugin: 'leftover-maker', items: { [ table.id ]: 'restore' } } );
		expect( restored.status ).toBe( 200 );
		expect( restored.body.entry.restored ).toHaveLength( 1 );
		expect( itemRows() ).toBe( 51 );
		const undone = await rest( 'POST', '/axle-leftovers/v1/reinstall/undo', { id: restored.body.entry.id } );
		expect( undone.status ).toBe( 200 );
		expect( seed.tables( 'wp\\_lm\\_items' ) ).toEqual( [] );
		expect( ( await rest( 'POST', '/axle-leftovers/v1/reinstall/undo', { id: restored.body.entry.id } ) ).status ).toBe( 404 );
	} );
} );
