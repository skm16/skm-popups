/**
 * Phase 0 smoke test.
 *
 * Asserts the harness is wired up end to end and that popkit has *zero
 * footprint* on a page where no popup matches. Phase 0 exit criteria require an
 * empty plugin to be indistinguishable from no plugin: no enqueued script, no
 * enqueued stylesheet, no config JSON, no markup, no network request. The
 * assertions below stay meaningful for the whole life of the project — a fresh
 * wp-env install has no published popups, so the front page must never carry
 * popkit assets.
 *
 * Deliberately anonymous: no admin fixtures, no storage state. This is the
 * ordinary visitor's view of the site, which is exactly what must stay clean.
 *
 */

const { test, expect } = require( '@playwright/test' );

/** Matches any asset URL or attribute belonging to the plugin. */
const POPKIT = /popkit/i;

test.describe( 'popkit smoke', () => {
	test( 'front page loads and popkit emits nothing when no popup matches', async ( {
		page,
	} ) => {
		/** @type {string[]} */
		const popkitRequests = [];

		page.on( 'request', ( request ) => {
			if ( POPKIT.test( request.url() ) ) {
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
			POPKIT.test( source )
		);

		expect(
			popkitAssets,
			'no script or stylesheet references popkit'
		).toEqual( [] );

		// No emitted config, no inline markup, no orphaned container.
		await expect( page.locator( '#popkit-config' ) ).toHaveCount( 0 );
		await expect( page.locator( '[class*="popkit-"]' ) ).toHaveCount( 0 );
		await expect( page.locator( '[id^="popkit-"]' ) ).toHaveCount( 0 );

		// Nothing was fetched either — in particular not the context route,
		// which must never be called when no popup needs it.
		expect( popkitRequests, 'no network request touches popkit' ).toEqual(
			[]
		);
	} );
} );
