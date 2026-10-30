// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp } = require( './utils' );
const { STORAGE_STATE } = require( './utils/config' );
const { prepareSite, shot } = require( './utils/media' );
const seed = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-leftovers';

test.describe( 'Screenshots', () => {
	let context;
	let page;

	test.beforeAll( async ( { browser } ) => {
		test.setTimeout( 180_000 );
		prepareSite( 'Bean There Coffee' );
		seed.installHelpers();
		seed.seedScreens();

		context = await browser.newContext( { storageState: STORAGE_STATE, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		page = await context.newPage();
	} );

	test.afterAll( async () => {
		await context.close();
		seed.resetAll();
	} );

	test( '1: by plugin', async () => {
		await page.goto( SCREEN );
		const card = page.locator( '.alo-card[data-owner="leftover-maker"]' );
		await card.locator( '.alo-review summary' ).click();
		await expect( card.locator( '.alo-items' ) ).toBeVisible();
		await shot( page, 1, { fullPage: true } );
	} );

	test( '2: the confirmation for an unknown item', async () => {
		await page.goto( `${ SCREEN }&tab=unknown` );
		await page.locator( 'tr[data-item="table:wp_mystery_log"] input[type="checkbox"]' ).check();
		await page.getByRole( 'button', { name: 'Move selected to trash' } ).click();
		await page.getByLabel( 'Type wp_mystery_log' ).fill( 'wp_mystery_log' );
		await shot( page, 2 );
		await page.keyboard.press( 'Escape' );
	} );

	test( '3: trash', async () => {
		wp( 'axle-leftovers', 'trash', '--plugin=leftover-maker', '--yes' );
		await page.goto( `${ SCREEN }&tab=trash` );
		await expect( page.locator( '.alo-batch' ) ).toBeVisible();
		await shot( page, 3, { fullPage: true } );
	} );

	test( '4: ledger', async () => {
		await page.goto( `${ SCREEN }&tab=ledger` );
		await expect( page.locator( '.alo-ledger' ) ).toBeVisible();
		await shot( page, 4 );
	} );

	test( '5: the notice after a delete', async () => {
		wp( 'axle-leftovers', 'restore', seed.query( 'SELECT batch_id FROM wp_axle_leftovers_trash LIMIT 1' )[ 0 ][ 0 ] );
		wp( 'eval', "set_transient( 'axle_leftovers_deleted_1', array( 'leftover-maker' ), 3600 );" );
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( '.alo-deleted-notice' ) ).toBeVisible();
		await shot( page, 5 );
	} );
} );
