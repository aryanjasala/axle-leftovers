// The opt-in online lookup and the full signature list. A must-use plugin
// answers pre_http_request for veloria.dev and raw.githubusercontent.com, so no
// test calls a real service. Every test checks the database through WP-CLI too.
const { test, expect, wp, wpEval, appPasswordClient } = require( './utils' );
const seed = require( './seed' );

const UNKNOWN = '/wp-admin/tools.php?page=axle-leftovers&tab=unknown';
const SETTINGS = '/wp-admin/tools.php?page=axle-leftovers&tab=settings';
const LIST_FILE = 'wp_upload_dir()["basedir"] . "/axle-leftovers/dataset/signatures.json.gz"';

/** The row of an item on the Unknown tab. */
const row = ( page, name ) => page.locator( `tr[data-item="option:${ name }"]` );

/** The mock's POST requests. */
const posts = () => seed.httpLog().filter( ( line ) => line.startsWith( 'POST ' ) );

/** The item as JSON from WP-CLI. */
const show = ( name ) => JSON.parse( wp( 'axle-leftovers', 'show', 'option', name, '--format=json' ) );

test.describe( 'Online lookup and the full list', () => {
	test.beforeAll( () => {
		seed.installHelpers();
		seed.resetAll();
	} );

	test.afterEach( () => {
		seed.resetAll();
	} );

	test( 'both are off by default: no button, no download, no request', async ( { page } ) => {
		seed.installMockHttp();
		wp( 'option', 'update', 'lmx_unique_setting', 'value', '--autoload=no' );
		wp( 'axle-leftovers', 'scan' );

		await page.goto( UNKNOWN );
		await expect( row( page, 'lmx_unique_setting' ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: /Look it up/ } ) ).toHaveCount( 0 );

		await page.goto( SETTINGS );
		await expect( page.getByLabel( /Show a Look it up button/ ) ).not.toBeChecked();
		await expect( page.getByLabel( /Allow downloading the full signature list/ ) ).not.toBeChecked();
		await expect( page.getByRole( 'button', { name: 'Download the full list' } ) ).toHaveCount( 0 );
		await expect( page.locator( '.alo-dataset-bundled' ) ).toContainText( /Bundled list: \d+ plugins, built 20/ );
		await expect( page.locator( '.alo-dataset-full' ) ).toHaveText( 'Full list: not downloaded.' );

		expect( seed.httpLog() ).toEqual( [] );
	} );

	test( 'the settings turn them on, and the screen then offers both', async ( { page } ) => {
		await page.goto( SETTINGS );
		await page.getByLabel( /Show a Look it up button/ ).check();
		await page.getByLabel( /Allow downloading the full signature list/ ).check();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Settings saved.' );
		expect( JSON.parse( wp( 'option', 'get', 'axle_leftovers_settings', '--format=json' ) ) ).toMatchObject( { online_lookup: true, remote_dataset: true } );
		await expect( page.getByRole( 'button', { name: 'Download the full list' } ) ).toBeVisible();
	} );

	test( 'a name only one plugin uses is Likely, shown with its evidence, and cached', async ( { page } ) => {
		seed.makeLookupItems();
		await page.goto( UNKNOWN );
		await row( page, 'lmx_unique_setting' ).getByRole( 'button', { name: 'Look up lmx_unique_setting on WordPress.org' } ).click();

		await expect( page.locator( '.alo-notice' ) ).toContainText( 'only Acme Widgets names it in its code (Likely)' );
		expect( posts() ).toHaveLength( 1 );
		expect( posts()[ 0 ] ).toContain( '"term":"lmx_unique_setting"' );
		expect( posts()[ 0 ] ).toContain( '"public":false' );
		const cached = JSON.parse( wp( 'option', 'get', 'axle_leftovers_lookups', '--format=json' ) );
		expect( Object.values( cached ) ).toHaveLength( 1 );
		expect( Object.values( cached )[ 0 ] ).toMatchObject( { confidence: 'likely', total: 1 } );

		// The item left the Unknown tab and sits on Acme Widgets' card.
		await expect( row( page, 'lmx_unique_setting' ) ).toHaveCount( 0 );
		await page.getByRole( 'link', { name: /By plugin/ } ).click();
		const card = page.locator( '.alo-card[data-owner="acme-widgets"]' );
		await expect( card ).toContainText( 'Acme Widgets' );
		await card.getByText( 'Review and move to trash' ).click();
		await expect( card.locator( '.alo-evidence' ) ).toContainText( 'Online lookup: only Acme Widgets on WordPress.org names it in its code' );
		await expect( card.getByRole( 'link', { name: 'Acme Widgets' } ) ).toHaveAttribute( 'href', 'https://wordpress.org/plugins/acme-widgets/' );
		const item = show( 'lmx_unique_setting' );
		expect( item ).toMatchObject( { owner: 'acme-widgets', confidence: 'likely' } );
		expect( seed.query( "SELECT COUNT(*) FROM wp_options WHERE option_name = 'lmx_unique_setting'" ) ).toEqual( [ [ '1' ] ] );

		// A scan keeps the cached evidence and sends nothing new.
		wp( 'axle-leftovers', 'scan' );
		expect( show( 'lmx_unique_setting' ).owner ).toBe( 'acme-widgets' );
		expect( posts() ).toHaveLength( 1 );
	} );

	test( 'several plugins is a Guess, the most installed first, and still needs the typed name', async ( { page } ) => {
		seed.makeLookupItems();
		await page.goto( UNKNOWN );
		await row( page, 'lmx_shared_setting' ).getByRole( 'button', { name: /Look up lmx_shared_setting/ } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( '2 plugins name it. The most installed is Big Widgets (Guess)' );

		await page.getByRole( 'link', { name: /By plugin/ } ).click();
		const card = page.locator( '.alo-card[data-owner="big-widgets"]' );
		await card.getByText( 'Review and move to trash' ).click();
		const candidates = card.locator( '.alo-candidates li' );
		await expect( candidates ).toHaveCount( 2 );
		await expect( candidates.first() ).toContainText( 'Big Widgets, 90,000 active installs' );
		await expect( candidates.last() ).toContainText( 'Small Widgets, 300 active installs' );
		expect( show( 'lmx_shared_setting' ) ).toMatchObject( { owner: 'big-widgets', confidence: 'guess' } );
		expect( wpEval( 'echo \\AryanX\\Axle\\Leftovers\\Settings::require_typed_confirmation( "guess" ) ? "typed" : "plain";' ) ).toBe( 'typed' );
	} );

	test( 'a name nobody uses stays Unknown with a note, and is not asked about again', async ( { page } ) => {
		seed.makeLookupItems();
		await page.goto( UNKNOWN );
		await row( page, 'lmx_nobody_setting' ).getByRole( 'button', { name: /Look up lmx_nobody_setting/ } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'no plugin on WordPress.org names it in its code' );

		await expect( row( page, 'lmx_nobody_setting' ) ).toBeVisible();
		await expect( row( page, 'lmx_nobody_setting' ).locator( '.alo-lookup-note' ) ).toContainText( 'no plugin on WordPress.org names it' );
		await expect( row( page, 'lmx_nobody_setting' ).getByRole( 'button', { name: /Look up/ } ) ).toHaveCount( 0 );
		expect( show( 'lmx_nobody_setting' ).confidence ).toBe( 'unknown' );
		expect( posts() ).toHaveLength( 1 );
	} );

	test( 'lookups are spaced out: a second one right after the first is refused without a request', async ( { page } ) => {
		seed.makeLookupItems();
		wp( 'option', 'update', 'alo_e2e_interval', '600' );
		await page.goto( UNKNOWN );
		await row( page, 'lmx_nobody_setting' ).getByRole( 'button', { name: /Look up/ } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'no plugin on WordPress.org names it' );

		await row( page, 'lmx_shared_setting' ).getByRole( 'button', { name: /Look up/ } ).click();
		await expect( page.locator( '.alo-notice.notice-error' ) ).toContainText( /Try again in \d+ seconds/ );
		expect( posts() ).toHaveLength( 1 );
	} );

	test( 'WP-CLI: lookup prints candidates, refuses core names, and respects the setting', async () => {
		seed.makeLookupItems();
		const rows = JSON.parse( wp( 'axle-leftovers', 'lookup', 'option', 'lmx_shared_setting', '--format=json' ) );
		expect( rows.map( ( r ) => r.slug ) ).toEqual( [ 'big-widgets', 'small-widgets' ] );
		expect( rows[ 0 ] ).toMatchObject( { confidence: 'guess', url: 'https://wordpress.org/plugins/big-widgets/' } );

		let error = '';
		try {
			wp( 'axle-leftovers', 'lookup', 'option', 'siteurl' );
		} catch ( e ) {
			error = String( e.stderr || e.message );
		}
		expect( error ).toContain( 'is not in the scan results' );
		expect( posts() ).toHaveLength( 1 );

		wp( 'option', 'update', 'axle_leftovers_settings', '{}', '--format=json' );
		try {
			wp( 'axle-leftovers', 'lookup', 'option', 'lmx_nobody_setting' );
		} catch ( e ) {
			error = String( e.stderr || e.message );
		}
		expect( error ).toContain( 'The online lookup is off' );
	} );

	test( 'downloading the full list shows its date and count, and attributes a dataset item', async ( { page } ) => {
		seed.makeLookupItems();
		expect( show( 'lmx_dataset_setting' ).confidence ).toBe( 'unknown' );

		await page.goto( SETTINGS );
		await page.getByRole( 'button', { name: 'Download the full list' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Downloaded the full list: 3 plugins, built 2026-10-01.' );
		await expect( page.locator( '.alo-dataset-full' ) ).toContainText( 'Full list: 3 plugins, built 2026-10-01, downloaded' );
		await expect( page.locator( '.alo-dataset-count' ) ).toContainText( /In use: \d+ plugins/ );
		expect( wpEval( `echo file_exists( ${ LIST_FILE } ) ? "yes" : "no";` ) ).toBe( 'yes' );
		const meta = JSON.parse( wp( 'option', 'get', 'axle_leftovers_dataset_meta', '--format=json' ) );
		expect( meta ).toMatchObject( { built: '2026-10-01', count: 3 } );
		expect( wpEval( 'global $wpdb; echo $wpdb->get_var( "SELECT autoload FROM $wpdb->options WHERE option_name = \'axle_leftovers_dataset_meta\'" );' ) ).toMatch( /^(off|no)$/ );
		expect( seed.httpLog().filter( ( line ) => line.includes( 'raw.githubusercontent.com' ) ) ).toHaveLength( 2 );

		// The background rescan the download asked for, run now.
		wp( 'axle-leftovers', 'scan' );
		const item = show( 'lmx_dataset_setting' );
		expect( item ).toMatchObject( { owner: 'dataset-widgets', confidence: 'likely' } );
		expect( item.evidence.map( ( e ) => e.text ).join( ' ' ) ).toContain( 'Dataset match' );

		// The bundled list stays: its plugins are still known.
		const status = wp( 'axle-leftovers', 'dataset', 'status' );
		expect( status ).toContain( 'Full list: 3 plugins, built 2026-10-01' );

		await page.reload();
		await page.getByRole( 'button', { name: 'Remove the full list' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Removed the full list' );
		await expect( page.locator( '.alo-dataset-full' ) ).toHaveText( 'Full list: not downloaded.' );
		expect( wpEval( `echo file_exists( ${ LIST_FILE } ) ? "yes" : "no";` ) ).toBe( 'no' );
		expect( seed.succeeds( 'option', 'get', 'axle_leftovers_dataset_meta' ) ).toBe( false );
	} );

	test( 'a download that fails its checksum is discarded', async ( { page } ) => {
		seed.makeLookupItems();
		wp( 'option', 'update', 'alo_e2e_bad_checksum', '1' );
		await page.goto( SETTINGS );
		await page.getByRole( 'button', { name: 'Download the full list' } ).click();

		await expect( page.locator( '.alo-notice.notice-error' ) ).toContainText( 'does not match its checksum, so it was discarded' );
		await expect( page.locator( '.alo-dataset-full' ) ).toHaveText( 'Full list: not downloaded.' );
		expect( wpEval( `echo file_exists( ${ LIST_FILE } ) ? "yes" : "no";` ) ).toBe( 'no' );
		expect( seed.succeeds( 'option', 'get', 'axle_leftovers_dataset_meta' ) ).toBe( false );
	} );

	test( 'WP-CLI: dataset download, status and remove', async () => {
		seed.makeLookupItems();
		expect( wp( 'axle-leftovers', 'dataset', 'download' ) ).toContain( 'Downloaded the full list: 3 plugins, built 2026-10-01.' );
		expect( wp( 'axle-leftovers', 'dataset', 'status' ) ).toContain( 'Full list: 3 plugins' );
		expect( wp( 'axle-leftovers', 'dataset', 'remove' ) ).toContain( 'Removed the full list' );
		expect( wp( 'axle-leftovers', 'dataset', 'status' ) ).toContain( 'Full list: not downloaded.' );
	} );

	test( 'the abilities look an item up and download the list, with the right annotations', async () => {
		test.skip( ! seed.hasAbilities(), 'The Abilities API arrived in WordPress 6.9.' );
		seed.makeLookupItems();
		const client = await appPasswordClient( 'admin', 'e2e-lookup' );
		try {
			for ( const name of [ 'lookup-item', 'download-dataset' ] ) {
				const meta = await client.call( 'GET', `/wp-abilities/v1/abilities/axle-leftovers/${ name }` );
				expect( meta.body.meta.annotations ).toMatchObject( { readonly: false, destructive: false, idempotent: true } );
			}

			const looked = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-leftovers/lookup-item/run', { input: { type: 'option', name: 'lmx_unique_setting' } } );
			expect( looked.status ).toBe( 200 );
			expect( looked.body ).toMatchObject( { confidence: 'likely', total: 1 } );
			expect( looked.body.candidates[ 0 ].slug ).toBe( 'acme-widgets' );

			const downloaded = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-leftovers/download-dataset/run', { input: {} } );
			expect( downloaded.status ).toBe( 200 );
			expect( downloaded.body ).toMatchObject( { built: '2026-10-01', count: 3 } );
		} finally {
			await client.dispose();
		}
	} );

	test( 'the endpoint and the disable filters', async () => {
		seed.makeLookupItems();
		wpEval( 'wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . "/alo-e2e-off.php", "<?php add_filter( \'axle_leftovers_lookup_enabled\', \'__return_false\' ); add_filter( \'axle_leftovers_dataset_download_enabled\', \'__return_false\' );" );' );
		try {
			let error = '';
			try {
				wp( 'axle-leftovers', 'lookup', 'option', 'lmx_unique_setting' );
			} catch ( e ) {
				error = String( e.stderr || e.message );
			}
			expect( error ).toContain( 'The online lookup is off' );
			try {
				wp( 'axle-leftovers', 'dataset', 'download' );
			} catch ( e ) {
				error = String( e.stderr || e.message );
			}
			expect( error ).toContain( 'Downloading the full list is off' );
			expect( seed.httpLog() ).toEqual( [] );
		} finally {
			wpEval( '@unlink( WPMU_PLUGIN_DIR . "/alo-e2e-off.php" );' );
		}
		expect( wpEval( 'echo \\AryanX\\Axle\\Leftovers\\Lookup::endpoint();' ) ).toBe( 'https://veloria.dev/api/v1/search/' );
	} );
} );
