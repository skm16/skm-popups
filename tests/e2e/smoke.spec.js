/**
 * Zero-footprint smoke test.
 *
 * Asserts the harness is wired up end to end and that popkit costs a visitor
 * nothing on a page where no popup matches: no enqueued script, no enqueued
 * stylesheet, no config JSON, no markup, no network request. That was a Phase 0
 * exit criterion and it stays one — a plugin that prints bytes into every
 * response it has nothing to say on is not the plugin `docs/CLAUDE.md` describes.
 *
 * ## Why this spec drafts the fixtures
 *
 * The version of this file written in Phase 0 asserted against `/` on the
 * assumption that a popup targeted at `/popkit-e2e/` is absent everywhere else.
 * That assumption is not true yet and `tests/e2e/README.md` says so: the
 * built-in server conditions are not registered until Phase 4, so every stored
 * rule is *indeterminate*, every published popup survives server-side matching
 * on every URL, and the seeded `e2e-modal` fixture is therefore emitted on the
 * front page too. The moment Phase 2 wired `Frontend::init()` into
 * `Plugin::boot()` the old assertion began failing for an entirely correct
 * reason.
 *
 * Of the two honest ways out the README offers, this takes the first: the spec
 * creates the state it asserts against. Every published popup is drafted for the
 * duration of the test and restored afterwards, so "zero popups match" is a fact
 * about the site rather than a hope about the URL. It is restored in a `finally`
 * and again in `afterAll`, because a spec that leaves the fixtures drafted
 * breaks every other file in the suite.
 *
 * When Phase 4 lands, the drafting becomes unnecessary and this file can assert
 * against `/popkit-e2e-clean/` with the fixtures left published. Deleting the
 * drafting then is a one-line simplification; deleting the fixture instead never
 * is.
 *
 * The page visit itself stays deliberately anonymous — no storage state, no
 * admin session. `requestUtils` runs over its own authenticated request context
 * and never touches the browser, so what the browser sees is the ordinary
 * visitor's view of the site, which is exactly what has to stay clean.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Matches a URL that is popkit's own footprint.
 *
 * Deliberately narrower than `/popkit/`: the fixture pages carry the word in
 * their slugs, so a bare match would report the page under test, and the oEmbed
 * discovery links core writes for it, as plugin assets. The two things that are
 * genuinely popkit's are files under its plugin directory and its REST namespace.
 */
const POPKIT_ASSET = /\/plugins\/popkit\/|\/popkit\/v1\//i;

/** REST collection for the popup post type. `rest_base` defaults to the name. */
const POPUP_PATH = '/wp/v2/popkit_popup';

/**
 * Drafts every published popup and reports which ones were changed.
 *
 * Only the popups that were actually published are collected, so the restore
 * cannot publish something an author had deliberately left in draft.
 */
const draftPublishedPopups = async ( requestUtils ) => {
	const published = await requestUtils.rest( {
		method: 'GET',
		path: POPUP_PATH,
		params: { status: 'publish', per_page: 100, _fields: 'id' },
	} );

	const ids = ( published || [] ).map( ( popup ) => popup.id );

	for ( const id of ids ) {
		await requestUtils.rest( {
			method: 'POST',
			path: `${ POPUP_PATH }/${ id }`,
			data: { status: 'draft' },
		} );
	}

	return ids;
};

/**
 * Publishes the popups a previous call drafted. Safe to run twice.
 */
const publishPopups = async ( requestUtils, ids ) => {
	for ( const id of ids ) {
		await requestUtils.rest( {
			method: 'POST',
			path: `${ POPUP_PATH }/${ id }`,
			data: { status: 'publish' },
		} );
	}
};

test.describe( 'popkit smoke', () => {
	/** Popups drafted by the test, restored even when it fails. */
	let drafted = [];

	test.afterAll( async ( { requestUtils } ) => {
		await publishPopups( requestUtils, drafted );
		drafted = [];
	} );

	test( 'front page loads and popkit emits nothing when no popup matches', async ( {
		page,
		requestUtils,
	} ) => {
		drafted = await draftPublishedPopups( requestUtils );

		try {
			/** @type {string[]} */
			const popkitRequests = [];

			page.on( 'request', ( request ) => {
				if ( POPKIT_ASSET.test( request.url() ) ) {
					popkitRequests.push( request.url() );
				}
			} );

			const response = await page.goto( '/' );

			expect( response, 'navigation returned a response' ).toBeTruthy();
			expect( response.status(), 'front page responds 200' ).toBe( 200 );

			// Every script src and stylesheet href in the rendered document.
			const assets = await page.evaluate( () => {
				const sources = [];

				document
					.querySelectorAll( 'script[src]' )
					.forEach( ( node ) =>
						sources.push( node.getAttribute( 'src' ) )
					);
				document
					.querySelectorAll( 'link[href]' )
					.forEach( ( node ) =>
						sources.push( node.getAttribute( 'href' ) )
					);

				return sources;
			} );

			const popkitAssets = assets.filter( ( source ) =>
				POPKIT_ASSET.test( source )
			);

			expect(
				popkitAssets,
				'no script or stylesheet references popkit'
			).toEqual( [] );

			// No emitted config, no inline markup, no orphaned container.
			await expect( page.locator( '#popkit-config' ) ).toHaveCount( 0 );
			await expect( page.locator( '[data-popkit-id]' ) ).toHaveCount( 0 );
			await expect( page.locator( '[class*="popkit-"]' ) ).toHaveCount(
				0
			);

			// Nothing was fetched either — in particular not the context route,
			// which must never be called when no popup needs it.
			expect(
				popkitRequests,
				'no network request touches popkit'
			).toEqual( [] );

			// And no global was installed, which is the client half of the same
			// claim: the bundle never ran because it was never sent.
			expect(
				await page.evaluate( () => undefined !== window.popkit ),
				'window.popkit is absent'
			).toBe( false );
		} finally {
			await publishPopups( requestUtils, drafted );
			drafted = [];
		}
	} );

	test( 'the fixture page serves a popup once the fixtures are published', async ( {
		page,
	} ) => {
		// The counterpart to the assertion above: proof that "nothing was
		// emitted" was a consequence of there being nothing to emit, rather than
		// of the runtime being broken or the harness serving a stale tree.
		const response = await page.goto( '/popkit-e2e/' );

		expect( response.status(), 'fixture page responds 200' ).toBe( 200 );

		await expect( page.locator( '#popkit-config' ) ).toHaveCount( 1 );
		await expect(
			page.locator( '[data-popkit-slug="e2e-modal"]' )
		).toHaveCount( 1 );
	} );
} );
