// Theme leftovers: settings, options, cron events, meta, templates, global styles and custom CSS of
// deleted themes. Every test checks the outcome in the database through WP-CLI as well as on screen.
const { test, expect, wp, wpEval, logInAs, appPasswordClient } = require( './utils' );
const seed = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-leftovers';
const THEME_ITEMS = [
	'option:theme_mods_leftover-theme',
	'option:lt_settings',
	'option:lt_version',
	'option:lt_cache',
	'option:_transient_lt_feed',
	'cron:lt_refresh',
	'postmeta:_lt_layout',
	'template:leftover-theme',
	'globalstyles:leftover-theme',
	'customcss:leftover-theme',
];

/** Every list item of a theme, with the `type:name` id the screen uses. */
function themeItems( theme = 'leftover-theme' ) {
	return JSON.parse( wp( 'axle-leftovers', 'list', `--theme=${ theme }`, '--format=json' ) ).map( ( item ) => ( { ...item, id: `${ item.type }:${ item.name }` } ) );
}

/** Rows of the tables a theme's block content lives in, as one comparable string. */
function contentState( ids ) {
	const all = [ ...ids.template, ...ids.globalstyles, ...ids.revisions ].join( ',' );
	return [
		seed.query( `SELECT * FROM wp_posts WHERE ID IN (${ all }) ORDER BY ID` ),
		seed.query( `SELECT * FROM wp_postmeta WHERE post_id IN (${ all }) ORDER BY meta_id` ),
		seed.query( `SELECT * FROM wp_term_relationships WHERE object_id IN (${ all }) ORDER BY object_id, term_taxonomy_id` ),
	];
}

test.describe( 'Theme leftovers', () => {
	test.beforeAll( () => {
		seed.installHelpers();
		seed.resetAll();
		seed.resetThemes();
	} );

	test.afterEach( () => {
		seed.resetAll();
		seed.resetThemes();
	} );

	test( 'a deleted theme leaves settings, options, cron, meta, templates, styles and custom CSS, all listed under the theme', async ( { page } ) => {
		seed.makeThemeLeftovers();

		const items = themeItems();
		expect( items.map( ( item ) => item.id ).sort() ).toEqual( [ ...THEME_ITEMS ].sort() );
		for ( const item of items ) {
			expect( item.owner ).toBe( 'theme:leftover-theme' );
			expect( item.kind ).toBe( 'theme' );
			expect( item.confidence ).toBe( 'certain' );
		}
		const byId = Object.fromEntries( items.map( ( item ) => [ item.id, item ] ) );
		expect( Number( byId[ 'template:leftover-theme' ].rows ) ).toBe( 3 );
		expect( Number( byId[ 'globalstyles:leftover-theme' ].rows ) ).toBe( 1 );
		expect( seed.query( "SELECT removed_how FROM wp_axle_leftovers_ledger WHERE slug = 'theme:leftover-theme'" ) ).toEqual( [ [ 'cli' ] ] );
		expect( seed.query( "SELECT name, version FROM wp_axle_leftovers_ledger WHERE slug = 'theme:leftover-theme'" ) ).toEqual( [ [ 'Leftover Theme', '2.0.0' ] ] );

		await page.goto( SCREEN );
		const card = page.locator( '.alo-card[data-owner="theme:leftover-theme"]' );
		await expect( card.locator( 'h2' ) ).toHaveText( 'Leftover Theme' );
		await expect( card.locator( '.alo-kind' ) ).toHaveText( 'Theme' );
		await expect( card.locator( '.alo-card-head .alo-badge' ) ).toHaveText( 'Certain' );
		await expect( card.locator( '.alo-summary' ) ).toContainText( '3 template changes' );
		await expect( card.locator( '.alo-summary' ) ).toContainText( '1 global styles change' );
		await expect( card.locator( '.alo-summary' ) ).toContainText( '1 custom CSS entry' );
		await expect( card.locator( '.alo-meta' ) ).toContainText( 'Last version 2.0.0.' );
		await card.getByText( 'Review and move to trash' ).click();
		await expect( card.locator( 'tbody tr' ) ).toHaveCount( THEME_ITEMS.length );
		await expect( card.locator( 'tr[data-item="template:leftover-theme"]' ) ).toContainText( 'Theme templates' );
		await expect( card.locator( 'tr[data-item="option:theme_mods_leftover-theme"] .alo-evidence time' ) ).toHaveAttribute( 'title', /.+/ );
	} );

	test( 'nothing of an installed theme is listed, and a theme that was never installed here is found by its name', async () => {
		const active = seed.activeTheme();
		seed.makeThemeContent( active );
		wp( 'option', 'update', `theme_mods_${ active }`, JSON.stringify( { header_text: 'on' } ), '--format=json' );
		wpEval( 'wp_update_custom_css_post( "p { margin: 0; }", array( "stylesheet" => get_stylesheet() ) );' );
		const ghost = seed.makeThemeContent( 'ghost-theme' );
		wp( 'option', 'update', 'theme_mods_ghost-theme', JSON.stringify( { header_text: 'off' } ), '--format=json' );
		wp( 'axle-leftovers', 'scan' );

		const all = JSON.parse( wp( 'axle-leftovers', 'list', '--format=json' ) );
		const names = all.map( ( item ) => item.name );
		for ( const name of [ active, `theme_mods_${ active }` ] ) {
			expect( names ).not.toContain( name );
		}
		const ghosts = themeItems( 'ghost-theme' );
		expect( ghosts.map( ( item ) => item.id ).sort() ).toEqual( [ 'globalstyles:ghost-theme', 'option:theme_mods_ghost-theme', 'template:ghost-theme' ] );
		for ( const item of ghosts ) {
			expect( item.confidence ).toBe( 'likely' );
		}
		const shown = JSON.parse( wp( 'axle-leftovers', 'show', 'option', 'theme_mods_ghost-theme', '--format=json' ) );
		expect( shown.evidence.map( ( line ) => line.text ) ).toContain( 'Named after the theme ghost-theme, which is not installed' );
		expect( ghost.template ).toHaveLength( 3 );
	} );

	test( 'trashing a theme\'s templates and styles and restoring them gives back the same rows, IDs and relationships', async () => {
		const ids = seed.makeThemeLeftovers();
		const before = contentState( ids );
		expect( before[ 0 ] ).toHaveLength( 5 );
		expect( before[ 1 ] ).toHaveLength( 3 );

		wp( 'axle-leftovers', 'trash', '--theme=leftover-theme', '--yes' );
		expect( seed.query( `SELECT COUNT(*) FROM wp_posts WHERE ID IN (${ [ ...ids.template, ...ids.globalstyles, ...ids.revisions ].join( ',' ) })` ) ).toEqual( [ [ '0' ] ] );
		expect( seed.query( `SELECT COUNT(*) FROM wp_postmeta WHERE post_id IN (${ ids.template.join( ',' ) })` ) ).toEqual( [ [ '0' ] ] );
		expect( seed.query( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'custom_css'" ) ).toEqual( [ [ '0' ] ] );
		expect( seed.query( "SELECT COUNT(*) FROM wp_terms WHERE name = 'leftover-theme'" ) ).toEqual( [ [ '1' ] ] );
		expect( seed.query( "SELECT COUNT(*) FROM wp_axle_leftovers_trash WHERE status = 'trashed'" ) ).toEqual( [ [ '10' ] ] );
		expect( wpEval( 'echo count( glob( wp_upload_dir()["basedir"] . "/axle-leftovers/exports/*/*" ) );' ) ).toBe( '10' );

		const batch = seed.query( 'SELECT batch_id FROM wp_axle_leftovers_trash LIMIT 1' )[ 0 ][ 0 ];
		wp( 'axle-leftovers', 'restore', batch );
		expect( contentState( ids ) ).toEqual( before );
		expect( seed.query( "SELECT COUNT(*) FROM wp_axle_leftovers_trash WHERE status = 'restored'" ) ).toEqual( [ [ '10' ] ] );
		expect( wp( 'option', 'get', 'lt_settings', '--format=json' ) ).toContain( 'wide' );
	} );

	test( 'the Themes screen says what a deleted theme left, right after the delete', async ( { page } ) => {
		const original = seed.activeTheme();
		seed.installTheme();
		wp( 'theme', 'activate', 'leftover-theme' );
		wp( 'option', 'get', 'lt_version' );
		seed.makeThemeContent( 'leftover-theme' );
		wp( 'theme', 'activate', original );
		wpEval( 'wp_set_current_user( 1 ); require_once ABSPATH . "wp-admin/includes/theme.php"; delete_theme( "leftover-theme" );' );

		await page.goto( '/wp-admin/themes.php' );
		const notice = page.locator( '.alo-deleted-notice' );
		await expect( notice ).toContainText( 'Leftover Theme left' );
		await expect( notice ).toContainText( '3 template changes' );
		await expect( notice ).toContainText( '1 global styles change' );
		await notice.getByRole( 'link', { name: 'Review' } ).click();
		await expect( page.locator( '.alo-card[data-owner="theme:leftover-theme"]' ) ).toBeVisible();
		// The test deletes the theme from a WP-CLI process; a delete from the Themes screen records "admin".
		expect( seed.query( "SELECT removed_how FROM wp_axle_leftovers_ledger WHERE slug = 'theme:leftover-theme'" ) ).toEqual( [ [ 'cli' ] ] );
	} );

	test( 'widgets a theme switch left inactive are reported, not touched', async ( { page } ) => {
		const saved = wp( 'option', 'get', 'sidebars_widgets', '--format=json' );
		try {
			wp( 'option', 'update', 'sidebars_widgets', JSON.stringify( { wp_inactive_widgets: [ 'search-2', 'meta-2' ], array_version: 3 } ), '--format=json' );
			await page.goto( SCREEN );
			await expect( page.locator( '.alo-widgets-note' ) ).toContainText( '2 widgets are inactive' );
			expect( JSON.parse( wp( 'option', 'get', 'sidebars_widgets', '--format=json' ) ).wp_inactive_widgets ).toEqual( [ 'search-2', 'meta-2' ] );
			expect( wp( 'axle-leftovers', 'list', '--format=count' ) ).toBe( '0' );
		} finally {
			wp( 'option', 'update', 'sidebars_widgets', saved, '--format=json' );
		}
	} );

	test( 'REST, abilities and WP-CLI filter by theme and kind', async () => {
		seed.makeLeftovers();
		seed.makeThemeLeftovers();
		const client = await appPasswordClient( 'admin', 'e2e-themes' );
		try {
			const rest = await client.call( 'GET', '/axle-leftovers/v1/items?theme=leftover-theme' );
			expect( rest.body.map( ( item ) => item.id ).sort() ).toEqual( [ ...THEME_ITEMS ].sort() );
			const plugins = await client.call( 'GET', '/axle-leftovers/v1/items?kind=plugin' );
			expect( plugins.body.length ).toBeGreaterThan( 0 );
			expect( plugins.body.every( ( item ) => 'plugin' === item.owner_kind ) ).toBe( true );

			if ( seed.hasAbilities() ) {
				const list = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-leftovers/list-leftovers/run?input[theme]=leftover-theme&input[type]=template' );
				expect( list.status ).toBe( 200 );
				expect( list.body.map( ( item ) => item.id ) ).toEqual( [ 'template:leftover-theme' ] );
				const kinds = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-leftovers/list-leftovers/run?input[kind]=theme' );
				expect( kinds.body.every( ( item ) => 'theme' === item.owner_kind ) ).toBe( true );
			}
		} finally {
			await client.dispose();
		}
		expect( JSON.parse( wp( 'axle-leftovers', 'list', '--kind=theme', '--type=template', '--format=json' ) ).map( ( item ) => item.name ) ).toEqual( [ 'leftover-theme' ] );
	} );

	test( 'a user who can manage options but not edit theme options cannot see or trash theme content', async () => {
		seed.makeThemeLeftovers();
		seed.succeeds( 'role', 'create', 'alo_limited', 'Limited admin' );
		for ( const cap of [ 'read', 'manage_options' ] ) {
			wp( 'cap', 'add', 'alo_limited', cap );
		}
		seed.succeeds( 'user', 'create', 'alo-limited', 'alo-limited@example.com', '--role=alo_limited', '--user_pass=limited-pass-123' );
		const client = await appPasswordClient( 'alo-limited', 'e2e-limited' );
		try {
			const list = await client.call( 'GET', '/axle-leftovers/v1/items' );
			expect( list.status ).toBe( 200 );
			const types = list.body.map( ( item ) => item.type );
			expect( types ).not.toContain( 'template' );
			expect( types ).not.toContain( 'globalstyles' );
			expect( types ).not.toContain( 'customcss' );
			expect( types ).toContain( 'option' );

			const refused = await client.call( 'POST', '/axle-leftovers/v1/trash', { items: [ { type: 'template', name: 'leftover-theme' } ] } );
			expect( refused.status ).toBe( 409 );
			expect( refused.body.message ).toContain( 'You are not allowed to change leftover-theme' );
			expect( seed.query( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'wp_template'" ) ).toEqual( [ [ '2' ] ] );
		} finally {
			await client.dispose();
			seed.succeeds( 'user', 'delete', 'alo-limited', '--yes' );
			seed.succeeds( 'role', 'delete', 'alo_limited' );
		}
	} );

	test( 'trashing theme leftovers from the screen asks in place, lists Undo, and Undo restores the batch', async ( { page } ) => {
		const ids = seed.makeThemeLeftovers();
		const before = contentState( ids );

		await page.goto( SCREEN );
		const card = page.locator( '.alo-card[data-owner="theme:leftover-theme"]' );
		await card.getByText( 'Review and move to trash' ).click();
		await card.locator( '.alo-check-all' ).check();
		await card.getByRole( 'button', { name: 'Move selected to trash' } ).click();
		const panel = page.locator( '#alo-confirm' );
		await expect( panel ).toBeVisible();
		await expect( card.locator( '#alo-confirm' ) ).toHaveCount( 1 );
		await expect( panel ).toContainText( 'Move 10 items to the trash?' );
		await expect( panel ).toContainText( 'you can restore the batch from the Trash tab' );
		// Escape cancels and puts focus back on the button.
		await page.keyboard.press( 'Escape' );
		await expect( panel ).toBeHidden();
		await expect( card.getByRole( 'button', { name: 'Move selected to trash' } ) ).toBeFocused();
		expect( seed.query( `SELECT COUNT(*) FROM wp_posts WHERE ID IN (${ ids.template.join( ',' ) })` ) ).toEqual( [ [ '3' ] ] );

		await card.getByRole( 'button', { name: 'Move selected to trash' } ).click();
		await panel.getByRole( 'button', { name: 'Move to trash' } ).click();
		const notice = page.locator( '.alo-notice' );
		await expect( notice ).toContainText( 'Moved 10 items to the trash' );
		expect( seed.query( `SELECT COUNT(*) FROM wp_posts WHERE ID IN (${ ids.template.join( ',' ) })` ) ).toEqual( [ [ '0' ] ] );
		await notice.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Restored 10 items.' );
		expect( contentState( ids ) ).toEqual( before );
	} );
} );
