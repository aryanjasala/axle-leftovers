// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const seed = require( './seed' );

test( 'demo: a deleted plugin\'s leftovers are reviewed, trashed and restored', async ( { browser } ) => {
	test.setTimeout( 240_000 );
	prepareSite( 'Bean There Coffee' );
	seed.installHelpers();
	seed.resetAll();
	seed.makeDatasetLeftovers();
	seed.installMaker();
	wp( 'plugin', 'deactivate', 'leftover-maker' );

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/plugins.php' );
			await demo.start();
			await demo.pause( 1000 );

			// Delete a plugin the usual way.
			await demo.click( page.locator( 'tr[data-slug="leftover-maker"] .delete a' ), 300 );
			await expect( page.locator( '.alo-deleted-notice' ) ).toBeVisible( { timeout: 30_000 } );
			await demo.pause( 2500 );

			// Review what it left.
			await demo.click( page.locator( '.alo-deleted-notice a' ), 300 );
			const card = page.locator( '.alo-card[data-owner="leftover-maker"]' );
			await expect( card ).toBeVisible();
			await demo.pause( 1500 );
			await demo.click( card.locator( '.alo-review summary' ), 600 );
			await demo.moveTo( card.locator( '.alo-evidence' ).first() );
			await demo.pause( 2000 );

			// Move it all to the trash.
			await demo.click( card.locator( '.alo-check-all' ), 400 );
			await demo.click( card.locator( '.alo-trash-button' ), 600 );
			await demo.pause( 1500 );
			await demo.click( page.locator( '#alo-confirm .alo-confirm-ok' ), 300 );
			await expect( page.locator( '.alo-notice' ) ).toContainText( 'Moved 12 items' );
			await demo.pause( 2000 );

			// And back.
			await demo.click( page.getByRole( 'link', { name: /^Trash/ } ), 300 );
			await demo.pause( 1500 );
			await demo.click( page.getByRole( 'button', { name: 'Restore batch' } ), 300 );
			await expect( page.locator( '.alo-notice' ) ).toContainText( 'Restored 12 items.' );
			await demo.pause( 2500 );
		} );
		expect( wp( 'db', 'query', 'SELECT COUNT(*) FROM wp_lm_items', '--skip-column-names' ) ).toBe( '50' );
	} finally {
		seed.resetAll();
	}
} );
