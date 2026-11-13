// Content of post types and taxonomies nothing registers any more: observation, listing, typed
// confirmation, exact trash and restore, and the checks on who may do it. Every test checks the
// outcome in the database through WP-CLI as well as on screen.
const { test, expect, wp, wpEval, appPasswordClient, logInAs } = require( './utils' );
const seed = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-leftovers';

/** Every row the two items stand for, as comparable strings. */
function contentState( ids ) {
	const posts = [ ...ids.events, ...ids.revisions ].join( ',' );
	const comments = ids.comments.join( ',' );
	const terms = ids.terms.join( ',' );
	return [
		seed.query( `SELECT * FROM wp_posts WHERE ID IN (${ posts }) ORDER BY ID` ),
		seed.query( `SELECT * FROM wp_postmeta WHERE post_id IN (${ posts }) ORDER BY meta_id` ),
		seed.query( `SELECT * FROM wp_comments WHERE comment_ID IN (${ comments }) ORDER BY comment_ID` ),
		seed.query( `SELECT * FROM wp_commentmeta WHERE comment_id IN (${ comments }) ORDER BY meta_id` ),
		seed.query( `SELECT * FROM wp_terms WHERE term_id IN (${ terms }) ORDER BY term_id` ),
		seed.query( `SELECT * FROM wp_term_taxonomy WHERE term_id IN (${ terms }) ORDER BY term_taxonomy_id` ),
		seed.query( `SELECT * FROM wp_termmeta WHERE term_id IN (${ terms }) ORDER BY meta_id` ),
		seed.query( `SELECT * FROM wp_term_relationships WHERE term_taxonomy_id IN (SELECT term_taxonomy_id FROM wp_term_taxonomy WHERE term_id IN (${ terms })) ORDER BY object_id, term_taxonomy_id` ),
	];
}

/** Runs a WP-CLI command that is expected to fail and returns what it said. */
function failure( ...args ) {
	try {
		wp( ...args );
	} catch ( error ) {
		return String( error.stderr || error.message );
	}
	return '';
}

function items() {
	return JSON.parse( wp( 'axle-leftovers', 'list', '--format=json' ) )
		.filter( ( item ) => [ 'posttype', 'taxonomy' ].includes( item.type ) )
		.map( ( item ) => ( { ...item, id: `${ item.type }:${ item.name }` } ) );
}

test.describe( 'Content of unregistered post types and taxonomies', () => {
	test.beforeAll( () => {
		seed.installHelpers();
		seed.resetAll();
		seed.resetEvents();
	} );

	test.afterEach( () => {
		seed.resetAll();
		seed.resetEvents();
	} );

	test( 'a type is not listed until it was watched for the whole period, and the screen says when it can be', async ( { page } ) => {
		seed.makeEventLeftovers();

		expect( items() ).toEqual( [] );
		const status = JSON.parse( wp( 'axle-leftovers', 'observe', 'posttype', 'le_event', '--format=json' ) )[ 0 ];
		expect( status.status ).toBe( 'recent' );
		expect( status.days ).toBe( 7 );

		expect( failure( 'axle-leftovers', 'trash', 'posttype', 'le_event', '--yes' ) ).toContain( 'is not in the scan results' ); // Refused before anything moves.
		expect( seed.query( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'le_event'" ) ).toEqual( [ [ '3' ] ] );

		await page.goto( SCREEN );
		const note = page.locator( '.alo-watching' );
		await expect( note ).toContainText( 'le_event' );
		await expect( note ).toContainText( 'has not watched for 7 days yet' );
		await expect( page.locator( '.alo-card[data-owner="leftover-events"]' ) ).toHaveCount( 0 );
	} );

	test( 'after the period, owners come from what was seen at runtime, with the evidence and a sample', async ( { page } ) => {
		const ids = seed.makeEventLeftovers();
		seed.fastForwardObserver();

		const found = items();
		expect( found.map( ( item ) => item.id ).sort() ).toEqual( [ 'posttype:le_event', 'taxonomy:le_genre' ] );
		for ( const item of found ) {
			expect( item.owner ).toBe( 'leftover-events' );
			expect( item.confidence ).toBe( 'certain' );
			expect( item.kind ).toBe( 'plugin' );
		}
		const byId = Object.fromEntries( found.map( ( item ) => [ item.id, item ] ) );
		expect( Number( byId[ 'posttype:le_event' ].rows ) ).toBe( 3 );
		expect( Number( byId[ 'taxonomy:le_genre' ].rows ) ).toBe( 2 );

		const shown = JSON.parse( wp( 'axle-leftovers', 'show', 'posttype', 'le_event', '--format=json' ) );
		const texts = shown.evidence.map( ( line ) => line.text );
		expect( texts.some( ( text ) => text.startsWith( 'Seen being created by' ) ) ).toBe( true );
		expect( texts.some( ( text ) => text.startsWith( 'Not registered by any code in the last 7 days' ) ) ).toBe( true );
		expect( shown.summary.statuses ).toEqual( { publish: 2, draft: 1 } );
		expect( shown.summary.meta ).toBe( 6 );
		expect( shown.summary.comments ).toBe( 1 );
		expect( shown.summary.sample.map( ( row ) => row.title ) ).toEqual( [ 'Draft Meetup', 'Summer Fest', 'Spring Gala' ] );
		expect( ids.events ).toHaveLength( 3 );

		await page.goto( SCREEN );
		const card = page.locator( '.alo-card[data-owner="leftover-events"]' );
		await expect( card.locator( 'h2' ) ).toHaveText( 'Leftover Events' );
		await expect( card.locator( '.alo-summary' ) ).toContainText( '3 posts of unregistered post types' );
		await expect( card.locator( '.alo-summary' ) ).toContainText( '2 terms of unregistered taxonomies' );
		await card.getByText( 'Review and move to trash' ).click();
		const row = card.locator( 'tr[data-item="posttype:le_event"]' );
		await expect( row ).toContainText( 'Post type content' );
		await row.getByText( 'What is in it' ).click();
		await expect( row.locator( '.alo-statuses' ) ).toContainText( '2 publish, 1 draft' );
		await expect( row.locator( '.alo-attached' ) ).toContainText( 'Also moves 6 meta rows, 1 comment and 3 term relationships' );
		await expect( row.locator( '.alo-titles li' ).first() ).toContainText( 'Draft Meetup' );
		await expect( row.locator( '.alo-titles time' ).first() ).toHaveAttribute( 'title', /.+/ );
		await expect( card.locator( 'tr[data-item="taxonomy:le_genre"]' ) ).toContainText( 'Taxonomy terms' );
	} );

	test( 'trashing from the screen needs both names typed, and Undo brings every row back with its ID', async ( { page } ) => {
		const ids = seed.makeEventLeftovers();
		seed.fastForwardObserver();
		const before = contentState( ids );
		expect( before[ 0 ] ).toHaveLength( 4 );
		expect( before[ 3 ] ).toHaveLength( 1 );

		await page.goto( SCREEN );
		const card = page.locator( '.alo-card[data-owner="leftover-events"]' );
		await card.getByText( 'Review and move to trash' ).click();
		await card.locator( '.alo-check-all' ).check();
		await card.getByRole( 'button', { name: 'Move selected to trash' } ).click();
		const panel = page.locator( '#alo-confirm' );
		await expect( panel ).toBeVisible();
		await expect( panel ).toContainText( 'Type each name to confirm' );
		await expect( panel.locator( '.alo-typed-name' ) ).toHaveCount( 2 );

		await panel.getByRole( 'button', { name: 'Move to trash' } ).click();
		await expect( panel.locator( '.alo-confirm-error' ) ).toContainText( 'does not match' );
		expect( seed.query( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'le_event'" ) ).toEqual( [ [ '3' ] ] );

		for ( const input of await panel.locator( '.alo-typed-name' ).all() ) {
			await input.fill( await input.getAttribute( 'data-expected' ) );
		}
		await panel.getByRole( 'button', { name: 'Move to trash' } ).click();
		const notice = page.locator( '.alo-notice' );
		await expect( notice ).toContainText( 'Moved 2 items to the trash' );

		expect( seed.query( `SELECT COUNT(*) FROM wp_posts WHERE ID IN (${ [ ...ids.events, ...ids.revisions ].join( ',' ) })` ) ).toEqual( [ [ '0' ] ] );
		expect( seed.query( `SELECT COUNT(*) FROM wp_postmeta WHERE post_id IN (${ ids.events.join( ',' ) })` ) ).toEqual( [ [ '0' ] ] );
		expect( seed.query( `SELECT COUNT(*) FROM wp_comments WHERE comment_ID IN (${ ids.comments.join( ',' ) })` ) ).toEqual( [ [ '0' ] ] );
		expect( seed.query( `SELECT COUNT(*) FROM wp_commentmeta WHERE comment_id IN (${ ids.comments.join( ',' ) })` ) ).toEqual( [ [ '0' ] ] );
		expect( seed.query( `SELECT COUNT(*) FROM wp_terms WHERE term_id IN (${ ids.terms.join( ',' ) })` ) ).toEqual( [ [ '0' ] ] );
		expect( seed.query( `SELECT COUNT(*) FROM wp_termmeta WHERE term_id IN (${ ids.terms.join( ',' ) })` ) ).toEqual( [ [ '0' ] ] );
		expect( seed.query( `SELECT COUNT(*) FROM wp_posts WHERE ID = ${ ids.post }` ) ).toEqual( [ [ '1' ] ] ); // The ordinary post stays; only its genre link goes.
		expect( seed.query( `SELECT COUNT(*) FROM wp_term_relationships WHERE object_id = ${ ids.post } AND term_taxonomy_id IN (${ before[ 5 ].map( ( row ) => row[ 0 ] ).join( ',' ) })` ) ).toEqual( [ [ '0' ] ] );
		expect( items() ).toEqual( [] );
		expect( seed.query( "SELECT COUNT(*) FROM wp_axle_leftovers_trash WHERE status = 'trashed'" ) ).toEqual( [ [ '2' ] ] );

		await notice.getByRole( 'button', { name: 'Undo' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Restored 2 items.' );
		expect( contentState( ids ) ).toEqual( before );
		expect( seed.query( "SELECT COUNT(*) FROM wp_axle_leftovers_trash WHERE status = 'restored'" ) ).toEqual( [ [ '2' ] ] );
	} );

	test( 'WP-CLI trashes and restores the same way, and content always needs the name typed through REST and abilities', async () => {
		const ids = seed.makeEventLeftovers();
		seed.fastForwardObserver();
		const before = contentState( ids );

		const client = await appPasswordClient( 'admin', 'e2e-content' );
		try {
			const untyped = await client.call( 'POST', '/axle-leftovers/v1/trash', { items: [ { type: 'posttype', name: 'le_event' } ] } );
			expect( untyped.status ).toBe( 409 );
			expect( untyped.body.message ).toContain( 'Type the name le_event to confirm' );
			expect( seed.query( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'le_event'" ) ).toEqual( [ [ '3' ] ] );

			const detail = await client.call( 'GET', '/axle-leftovers/v1/content?type=posttype&name=le_event' );
			expect( detail.status ).toBe( 200 );
			expect( detail.body.summary.statuses ).toEqual( { publish: 2, draft: 1 } );
			const observed = await client.call( 'GET', '/axle-leftovers/v1/observation' );
			expect( observed.body.days ).toBe( 7 );
			expect( observed.body.kinds.map( ( kind ) => kind.kind ) ).toEqual( [ 'front', 'admin', 'rest', 'cron', 'ajax', 'cli' ] );

			if ( seed.hasAbilities() ) {
				const read = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-leftovers/get-item/run?input[type]=taxonomy&input[name]=le_genre' );
				expect( read.status ).toBe( 200 );
				expect( read.body.summary.sample.map( ( row ) => row.title ) ).toEqual( [ 'Jazz', 'Rock' ] );
				const watching = await client.call( 'GET', '/wp-abilities/v1/abilities/axle-leftovers/get-observation/run' );
				expect( watching.body.days ).toBe( 7 );
				const typed = await client.call( 'POST', '/wp-abilities/v1/abilities/axle-leftovers/trash-items/run', { input: { items: [ { type: 'taxonomy', name: 'le_genre' } ] } } );
				expect( typed.status ).toBe( 409 );
			}
		} finally {
			await client.dispose();
		}

		wp( 'axle-leftovers', 'trash', 'posttype', 'le_event', 'taxonomy', 'le_genre', '--yes' );
		expect( seed.query( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'le_event'" ) ).toEqual( [ [ '0' ] ] );
		const batch = seed.query( 'SELECT batch_id FROM wp_axle_leftovers_trash LIMIT 1' )[ 0 ][ 0 ];
		expect( wpEval( 'echo count( glob( wp_upload_dir()["basedir"] . "/axle-leftovers/exports/*/*" ) );' ) ).toBe( '2' );
		wp( 'axle-leftovers', 'restore', batch );
		expect( contentState( ids ) ).toEqual( before );
	} );

	test( 'a type that registers again is refused even when the scan is old', async () => {
		seed.makeEventLeftovers();
		seed.fastForwardObserver();
		expect( items().map( ( item ) => item.id ) ).toContain( 'posttype:le_event' );

		seed.installEvents();
		const client = await appPasswordClient( 'admin', 'e2e-registered' );
		try {
			const refused = await client.call( 'POST', '/axle-leftovers/v1/trash', { items: [ { type: 'posttype', name: 'le_event' } ], confirm_names: [ 'le_event' ] } );
			expect( refused.status ).toBe( 409 );
			expect( refused.body.message ).toMatch( /registered by active code now|is in use/ );
		} finally {
			await client.dispose();
		}
		expect( seed.query( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'le_event'" ) ).toEqual( [ [ '3' ] ] );
	} );

	test( 'a user who can manage options but not delete others\' posts cannot see or trash content', async () => {
		seed.makeEventLeftovers();
		seed.fastForwardObserver();
		seed.succeeds( 'role', 'create', 'alo_limited', 'Limited admin' );
		for ( const cap of [ 'read', 'manage_options' ] ) {
			wp( 'cap', 'add', 'alo_limited', cap );
		}
		seed.succeeds( 'user', 'create', 'alo-limited', 'alo-limited@example.com', '--role=alo_limited', '--user_pass=limited-pass-123' );
		const client = await appPasswordClient( 'alo-limited', 'e2e-limited-content' );
		try {
			const list = await client.call( 'GET', '/axle-leftovers/v1/items' );
			expect( list.status ).toBe( 200 );
			const types = list.body.map( ( item ) => item.type );
			expect( types ).not.toContain( 'posttype' );
			expect( types ).not.toContain( 'taxonomy' );

			const refused = await client.call( 'POST', '/axle-leftovers/v1/trash', { items: [ { type: 'posttype', name: 'le_event' } ], confirm_names: [ 'le_event' ] } );
			expect( refused.status ).toBe( 409 );
			expect( refused.body.message ).toContain( 'You are not allowed to change le_event' );
			expect( seed.query( "SELECT COUNT(*) FROM wp_posts WHERE post_type = 'le_event'" ) ).toEqual( [ [ '3' ] ] );
		} finally {
			await client.dispose();
			seed.succeeds( 'user', 'delete', 'alo-limited', '--yes' );
			seed.succeeds( 'role', 'delete', 'alo_limited' );
		}
	} );

	test( 'the observer sees the front end, wp-admin and REST, and the Settings tab sets the period', async ( { browser } ) => {
		const { context, page } = await logInAs( browser, 'admin' );
		await page.goto( '/' );
		await page.goto( '/wp-admin/' );
		await page.request.get( '/index.php?rest_route=/' );
		const kinds = JSON.parse( wpEval( 'echo wp_json_encode( array_keys( get_option( "axle_leftovers_observed" )["kinds"] ) );' ) );
		expect( kinds ).toEqual( expect.arrayContaining( [ 'front', 'admin', 'rest' ] ) );

		await page.goto( `${ SCREEN }&tab=settings` );
		await page.getByLabel( 'Watch post types for' ).fill( '14' );
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.alo-notice' ) ).toContainText( 'Settings saved' );
		expect( JSON.parse( wp( 'axle-leftovers', 'settings', 'get', 'observe_days' ) ) ).toBe( 14 );
		await context.close();
	} );
} );
