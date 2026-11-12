// The screen's shared behaviour: Get started, Help tabs, in-place confirms, notices with Undo,
// busy and error states, relative times, the Plugins screen link and the narrow layout.
const { test, expect, wp, wpEval } = require( './utils' );
const seed = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-leftovers';
const PLUGIN_ROW = 'tr[data-plugin="axle-leftovers/axle-leftovers.php"]';

test.describe( 'Leftovers screen behaviour', () => {
	test.beforeAll( () => {
		seed.installHelpers();
	} );

	test.beforeEach( () => {
		seed.resetAll();
		seed.succeeds( 'user', 'meta', 'delete', '1', 'axle_leftovers_steps_dismissed' );
	} );

	test.afterEach( () => {
		seed.resetAll();
	} );

	test( 'the first visit shows a Get started card with steps read from real data, and it can be hidden for good', async ( { page } ) => {
		await page.goto( SCREEN );
		const card = page.locator( '.alo-steps' );
		await expect( card.getByRole( 'heading', { name: 'Get started' } ) ).toBeVisible();
		const steps = card.locator( '.axle-step' );
		await expect( steps ).toHaveCount( 3 );
		// The first scan ran when the screen opened.
		await expect( steps.nth( 0 ).locator( '.axle-badge' ) ).toHaveText( 'Done' );
		await expect( steps.nth( 1 ).getByRole( 'link', { name: 'Open Plugins' } ) ).toBeVisible();
		await expect( steps.nth( 2 ).getByRole( 'link', { name: 'Review leftovers' } ) ).toBeVisible();

		// Deleting a plugin and trashing what it left ticks the other two.
		seed.makeLeftovers();
		wp( 'axle-leftovers', 'trash', '--plugin=leftover-maker', '--yes' );
		await page.goto( SCREEN );
		await expect( page.locator( '.alo-steps' ) ).toHaveCount( 0 );

		seed.succeeds( 'user', 'meta', 'delete', '1', 'axle_leftovers_steps_dismissed' );
		seed.resetAll();
		await page.goto( SCREEN );
		await page.getByRole( 'button', { name: 'Hide this card' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Hid the Get started card.' );
		await expect( page.locator( '.alo-steps' ) ).toHaveCount( 0 );
		expect( wp( 'user', 'meta', 'get', '1', 'axle_leftovers_steps_dismissed' ) ).toBe( '1' );
		await page.goto( SCREEN );
		await expect( page.locator( '.alo-steps' ) ).toHaveCount( 0 );
	} );

	test( 'empty lists say what will show up and what to do next', async ( { page } ) => {
		await page.goto( SCREEN );
		const empty = page.locator( '.alo-empty' );
		await expect( empty ).toContainText( 'When you delete one, what it left behind shows up here' );
		await expect( empty.getByRole( 'link', { name: 'Open Plugins' } ) ).toBeVisible();
		await expect( empty.getByRole( 'link', { name: 'Open Themes' } ) ).toBeVisible();

		await page.goto( `${ SCREEN }&tab=unknown` );
		await expect( page.locator( '.alo-empty' ) ).toContainText( 'you type a name to move one to the trash' );
		await expect( page.getByRole( 'link', { name: 'Open settings to turn on the online lookup' } ) ).toBeVisible();

		await page.goto( `${ SCREEN }&tab=trash` );
		await expect( page.locator( '.alo-empty' ) ).toContainText( 'The trash is empty' );

		await page.goto( `${ SCREEN }&tab=ledger` );
		await expect( page.locator( '.alo-ledger, .alo-empty' ).first() ).toBeVisible();
	} );

	test( 'the Help tab has an Overview and a tab for each job', async ( { page } ) => {
		await page.goto( SCREEN );
		await page.locator( '#contextual-help-link' ).click();
		const tabs = page.locator( '#contextual-help-wrap .contextual-help-tabs a' );
		await expect( tabs ).toHaveText( [ 'Overview', 'Owners and evidence', 'Trash and restore', 'Themes', 'Settings and lookups' ] );
		await expect( page.locator( '#tab-panel-axle-leftovers-overview' ) ).toContainText( 'Nothing is deleted on the first step' );
		await tabs.filter( { hasText: 'Themes' } ).click();
		await expect( page.locator( '#tab-panel-axle-leftovers-themes' ) ).toContainText( 'Widgets that a theme switch left inactive are only reported' );
		await expect( page.locator( '#contextual-help-wrap .contextual-help-sidebar' ) ).toContainText( 'Documentation' );
	} );

	test( 'deleting a batch for good asks in place first, with no browser dialog', async ( { page } ) => {
		page.on( 'dialog', ( dialog ) => {
			throw new Error( `A browser dialog opened: ${ dialog.message() }` );
		} );
		seed.makeLeftovers();
		wp( 'axle-leftovers', 'trash', 'cron', 'lm_cleanup', '--yes' );

		await page.goto( `${ SCREEN }&tab=trash` );
		await page.getByRole( 'button', { name: 'Delete batch for good' } ).click();
		const panel = page.locator( '#alo-confirm' );
		await expect( panel ).toBeVisible();
		await expect( panel ).toHaveClass( /axle-confirm--danger/ );
		await expect( panel ).toContainText( 'This cannot be undone.' );
		await expect( panel.locator( '#alo-confirm-title' ) ).toHaveText( 'Delete this batch for good?' );
		await panel.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( panel ).toBeHidden();
		expect( seed.query( "SELECT status FROM wp_axle_leftovers_trash WHERE name = 'lm_cleanup'" ) ).toEqual( [ [ 'trashed' ] ] );

		await page.getByRole( 'button', { name: 'Delete batch for good' } ).click();
		await panel.getByRole( 'button', { name: 'Delete for good' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Deleted 1 item for good. This cannot be undone.' );
		expect( seed.query( "SELECT status FROM wp_axle_leftovers_trash WHERE name = 'lm_cleanup'" ) ).toEqual( [ [ 'purged' ] ] );
	} );

	test( 'emptying the trash asks in place too, and results are spoken to screen readers', async ( { page } ) => {
		seed.makeLeftovers();
		wp( 'axle-leftovers', 'trash', 'cron', 'lm_cleanup', '--yes' );

		await page.goto( `${ SCREEN }&tab=trash` );
		await page.getByRole( 'button', { name: 'Empty trash' } ).first().click();
		await expect( page.locator( '#alo-confirm-title' ) ).toHaveText( 'Empty the trash?' );
		await page.locator( '#alo-confirm' ).getByRole( 'button', { name: 'Empty trash' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Deleted 1 item for good.' );
		await expect( page.locator( '#a11y-speak-polite' ) ).toContainText( 'Deleted 1 item for good.' );
	} );

	test( 'a button that takes a while shows a spinner, sets aria-busy and cannot be pressed twice', async ( { page } ) => {
		await page.goto( SCREEN );
		let posts = 0;
		await page.route( '**/admin-post.php', async ( route ) => {
			posts++;
			await new Promise( ( resolve ) => setTimeout( resolve, 2500 ) );
			await route.continue();
		} );
		// The click would wait for the slow navigation, so read the page while it is still the old one.
		await page.getByRole( 'button', { name: 'Scan again' } ).click( { noWaitAfter: true } );
		await page.waitForFunction( () => document.querySelector( '.alo-scan-form button[aria-busy="true"]' ) && document.querySelector( '.alo-scan-form + .alo-spinner.is-active, .alo-scan-form .alo-spinner.is-active' ) );
		// A second press does nothing while the first runs.
		await page.evaluate( () => document.querySelector( '.alo-scan-form button' ).click() );
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Scan started' );
		expect( posts ).toBe( 1 );
	} );

	test( 'when looking up a deleted plugin\'s leftovers fails, the Plugins screen says what to do', async ( { page } ) => {
		await page.route( '**/axle-leftovers/v1/notice*', ( route ) => route.abort() );
		await page.route( '**rest_route=%2Faxle-leftovers%2Fv1%2Fnotice*', ( route ) => route.abort() );
		await page.goto( '/wp-admin/plugins.php' );
		await page.evaluate( () => window.jQuery( document ).trigger( 'wp-plugin-delete-success' ) );
		const notice = page.locator( '.alo-deleted-notice.notice-error' );
		await expect( notice ).toContainText( 'Could not check what the deleted plugin or theme left behind. Open Tools > Leftovers to review it.' );
	} );

	test( 'the Plugins screen links to the screen, and warns about the trash before a delete', async ( { page } ) => {
		await page.goto( '/wp-admin/plugins.php' );
		const link = page.locator( `${ PLUGIN_ROW } .row-actions a`, { hasText: 'Leftovers' } );
		await expect( link ).toHaveAttribute( 'href', /page=axle-leftovers/ );
		await expect( page.locator( '.alo-trash-warning' ) ).toHaveCount( 0 );

		seed.makeLeftovers();
		wp( 'axle-leftovers', 'trash', 'cron', 'lm_cleanup', '--yes' );
		await page.goto( '/wp-admin/plugins.php' );
		await expect( page.locator( '.alo-trash-warning' ) ).toContainText( 'Deleting this plugin deletes it for good, and that cannot be undone.' );
		await page.locator( '.alo-trash-warning' ).getByRole( 'link', { name: 'Open the trash' } ).click();
		await expect( page.locator( '.alo-batch' ) ).toBeVisible();
	} );

	test( 'dates are relative with the exact time on hover', async ( { page } ) => {
		seed.makeLeftovers();
		wp( 'axle-leftovers', 'trash', 'cron', 'lm_cleanup', '--yes' );
		for ( const url of [ SCREEN, `${ SCREEN }&tab=trash`, `${ SCREEN }&tab=ledger` ] ) {
			await page.goto( url );
			const times = page.locator( '.alo time.axle-time' );
			expect( await times.count() ).toBeGreaterThan( 0 );
			for ( const time of await times.all() ) {
				await expect( time ).toHaveAttribute( 'datetime', /^\d{4}-\d\d-\d\dT/ );
				await expect( time ).toHaveAttribute( 'title', /\d/ );
			}
		}
	} );

	test( 'wide lists scroll inside their own region at 360 px and the page does not', async ( { page } ) => {
		seed.makeLeftovers();
		await page.setViewportSize( { width: 360, height: 740 } );
		await page.goto( SCREEN );
		await page.locator( '.alo-review summary' ).first().click();
		const region = page.locator( '.axle-scroll' ).first();
		await expect( region ).toHaveAttribute( 'role', 'region' );
		await expect( region ).toHaveAttribute( 'tabindex', '0' );
		expect( await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth ) ).toBe( true );

		wp( 'axle-leftovers', 'trash', 'cron', 'lm_cleanup', '--yes' );
		await page.goto( `${ SCREEN }&tab=trash` );
		expect( await page.evaluate( () => document.documentElement.scrollWidth <= window.innerWidth ) ).toBe( true );
		await page.keyboard.press( 'Tab' );
		await region.focus();
		await expect( region ).toBeFocused();
	} );

	test( 'the settings can be read and changed from WP-CLI', async () => {
		expect( JSON.parse( wp( 'axle-leftovers', 'settings', 'list', '--format=json' ) ).find( ( row ) => 'retention_days' === row.key ).value ).toBe( '30' );
		wp( 'axle-leftovers', 'settings', 'set', 'retention_days', '14' );
		wp( 'axle-leftovers', 'settings', 'set', 'keep_exports', 'true' );
		expect( wp( 'axle-leftovers', 'settings', 'get', 'retention_days' ) ).toBe( '14' );
		expect( JSON.parse( wp( 'option', 'get', 'axle_leftovers_settings', '--format=json' ) ) ).toMatchObject( { retention_days: 14, keep_exports: true } );
		let message = '';
		try {
			wp( 'axle-leftovers', 'settings', 'set', 'retention_days', 'soon' );
		} catch ( error ) {
			message = String( error.stderr || error.message );
		}
		expect( message ).toContain( 'takes a whole number' );
		expect( wpEval( 'echo get_option( "axle_leftovers_settings" )["retention_days"];' ) ).toBe( '14' );
	} );

	test( 'the settings abilities read and change settings with the right annotations', async () => {
		test.skip( ! seed.hasAbilities(), 'The Abilities API arrived in WordPress 6.9.' );
		const { appPasswordClient } = require( './utils' );
		const client = await appPasswordClient( 'admin', 'e2e-settings' );
		try {
			const meta = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-leftovers/update-settings' );
			expect( meta.body.meta.annotations ).toMatchObject( { readonly: false, destructive: false, idempotent: true } );
			const changed = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-leftovers/update-settings/run', { input: { retention_days: 21 } } );
			expect( changed.status ).toBe( 200 );
			expect( changed.body.retention_days ).toBe( 21 );
			const read = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-leftovers/get-settings/run' );
			expect( read.body ).toMatchObject( { retention_days: 21, online_lookup: false } );
		} finally {
			await client.dispose();
		}
	} );
} );
