/**
 * Lifecycle of a popup: the event surface, the public API, the frequency cap,
 * and the promise that a page matching nothing costs nothing.
 *
 * Each test here is an exit criterion in `docs/build-plan.md` -> Phase 2.
 *
 * ## The event contract is public API
 *
 * `popkit:before-open`, `popkit:open`, `popkit:close` and `popkit:convert` are
 * the extension surface the deferred form and donation integrations are expected
 * to be built entirely against. The assertions below therefore pin the parts an
 * integration would depend on — the names, that every event is dispatched on the
 * popup and bubbles to `document`, and that `detail` carries `{ id, slug }` —
 * rather than only that something fired.
 *
 * ## Seen is recorded on a successful open and on nothing else
 *
 * `docs/data-model.md` -> When a popup counts as seen is unusually specific about
 * this, because the mistake is invisible: a canceled open shows the visitor
 * nothing, so recording it spends their single impression on something they never
 * saw. The suppression test therefore asserts the *absence* of a storage record,
 * not merely the absence of a dialog.
 *
 * ## These specs author their own popups
 *
 * The seeded `e2e-modal` fixture is emitted everywhere and cannot open in Phase
 * 2 — its stored `url_path` rule belongs to a condition nothing registers until
 * Phase 4, so it is marked `"unknown": true` and the client fails it closed. That
 * is correct behavior and not a subject to test lifecycle against, so every
 * popup asserted here is created through `requestUtils` with an empty rule set
 * (`{ "groups": [] }`, which `docs/data-model.md` defines as "always match") and
 * deleted afterwards. The fixture is additionally suppressed through
 * `popkit:before-open`, so these specs keep their meaning once Phase 4 makes its
 * rule start passing.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/** Fixture page carrying background content and the seeded popup. */
const FIXTURE_PAGE = '/popkit-e2e/';

/** Prose-only fixture page. */
const CLEAN_PAGE = '/popkit-e2e-clean/';

/** Slug of the seeded fixture popup, emitted on every page. */
const FIXTURE_SLUG = 'e2e-modal';

/** The popup these specs author and drive. */
const SPEC_SLUG = 'lifecycle-modal';

/** Any popup root on the page. */
const ANY_ROOT = '[data-popkit-id]';

/** REST collection for the popup post type. */
const POPUP_PATH = '/wp/v2/popkit_popup';

/**
 * Matches a URL that is popkit's own footprint.
 *
 * Deliberately narrower than `/popkit/`: the fixture pages carry the word in
 * their slugs, so a bare match would report the page under test, and the oEmbed
 * discovery links core writes for it, as plugin assets. The two things that are
 * genuinely popkit's are files under its plugin directory and its REST namespace.
 */
const POPKIT_ASSET = /\/plugins\/popkit\/|\/popkit\/v1\//i;

/** Content every authored popup carries, so it always has a real heading. */
const POPUP_CONTENT = [
	'<!-- wp:heading --><h2 class="wp-block-heading">Authored fixture popup</h2><!-- /wp:heading -->',
	'<!-- wp:paragraph --><p>Created by a lifecycle spec and deleted again.</p><!-- /wp:paragraph -->',
].join( '\n\n' );

/** Selector for one popup's root, whatever state it is in. */
const rootFor = ( slug ) => `[data-popkit-slug="${ slug }"]`;

/** Selector for one popup's dialog while it is on screen. */
const openModalFor = ( slug ) => `dialog[data-popkit-slug="${ slug }"][open]`;

/**
 * Creates a published popup whose rule set is empty, so it always matches.
 */
const createPopup = async ( requestUtils, slug, meta ) =>
	requestUtils.rest( {
		method: 'POST',
		path: POPUP_PATH,
		data: {
			title: slug,
			slug,
			status: 'publish',
			content: POPUP_CONTENT,
			meta: { _popkit_conditions: { groups: [] }, ...meta },
		},
	} );

/**
 * Deletes a popup created by a test, bypassing the trash.
 */
const deletePopup = async ( requestUtils, id ) => {
	if ( ! id ) {
		return;
	}

	await requestUtils.rest( {
		method: 'DELETE',
		path: `${ POPUP_PATH }/${ id }`,
		params: { force: true },
	} );
};

/**
 * Drafts every published popup and reports which ones were changed.
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

/**
 * Cancels the automatic open of named popups, before any plugin script runs.
 */
const suppressPopups = async ( page, slugs ) => {
	await page.addInitScript( ( suppressed ) => {
		document.addEventListener( 'popkit:before-open', ( event ) => {
			if ( suppressed.includes( event.detail.slug ) ) {
				event.preventDefault();
			}
		} );
	}, slugs );
};

/**
 * Records every popkit event on `document`, from before any plugin script runs.
 *
 * Listening on `document` rather than on the popup is deliberate: the contract
 * says the events bubble, and an integration that binds one listener for the
 * whole page is the case worth protecting.
 */
const recordEvents = async ( page ) => {
	await page.addInitScript( () => {
		window.popkitEvents = [];

		for ( const name of [
			'popkit:before-open',
			'popkit:open',
			'popkit:close',
			'popkit:convert',
		] ) {
			document.addEventListener( name, ( event ) => {
				window.popkitEvents.push( {
					name,
					detail: event.detail,
					onPopup: event.target !== document,
					targetSlug:
						event.target && event.target.getAttribute
							? event.target.getAttribute( 'data-popkit-slug' )
							: null,
					cancelable: event.cancelable,
				} );
			} );
		}
	} );
};

/**
 * Reads a popup's frequency records out of both storage areas.
 */
const seenRecords = async ( page, popupId ) =>
	page.evaluate( ( id ) => {
		const key = `popkit:seen:${ id }`;

		return {
			local: window.localStorage.getItem( key ),
			session: window.sessionStorage.getItem( key ),
		};
	}, popupId );

/**
 * Waits long enough for the runtime to have decided, then lets an assertion run.
 *
 * Asserting that something did *not* happen needs a settling point, and the
 * runtime offers a good one: `window.popkit` is installed during boot, before any
 * popup is considered. Waiting for it and then giving the synchronous lane a
 * further beat is the smallest honest wait — an immediate `toHaveCount( 0 )`
 * would pass against a runtime that had not started yet.
 */
const settle = async ( page ) => {
	await page.waitForFunction( () => undefined !== window.popkit );
	await page.waitForTimeout( 500 );
};

test.describe( 'popkit popup lifecycle', () => {
	/** The authored popup, shared by the tests that only read it. */
	let subject = null;

	test.beforeAll( async ( { requestUtils } ) => {
		subject = await createPopup( requestUtils, SPEC_SLUG );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deletePopup( requestUtils, subject?.id );
		subject = null;
	} );

	test( 'canceling popkit:before-open suppresses the popup and records nothing', async ( {
		page,
	} ) => {
		await recordEvents( page );
		await suppressPopups( page, [ FIXTURE_SLUG, SPEC_SLUG ] );
		await page.goto( FIXTURE_PAGE );
		await settle( page );

		// The markup is emitted — the server decided this popup belongs on this
		// page — but nothing was ever shown.
		await expect( page.locator( rootFor( SPEC_SLUG ) ) ).toHaveCount( 1 );
		await expect(
			page.locator( openModalFor( SPEC_SLUG ) ),
			'a canceled open shows nothing'
		).toHaveCount( 0 );

		const events = await page.evaluate( () => window.popkitEvents );
		const names = events
			.filter( ( event ) => SPEC_SLUG === event.detail.slug )
			.map( ( event ) => event.name );

		expect( names, 'the veto was offered' ).toContain(
			'popkit:before-open'
		);
		expect( names, 'and nothing opened' ).not.toContain( 'popkit:open' );

		const records = await seenRecords( page, subject.id );

		expect(
			records.local,
			'no localStorage record was written for a popup nobody saw'
		).toBeNull();
		expect( records.session, 'no sessionStorage record either' ).toBeNull();
	} );

	test( 'popkit:open and popkit:close carry { id, slug } and bubble to document', async ( {
		page,
	} ) => {
		await recordEvents( page );
		await suppressPopups( page, [ FIXTURE_SLUG ] );
		await page.goto( FIXTURE_PAGE );

		const modal = page.locator( openModalFor( SPEC_SLUG ) );

		await expect( modal ).toHaveCount( 1 );

		await page.keyboard.press( 'Escape' );
		await expect( modal ).toHaveCount( 0 );

		/*
		 * Polled, not sampled once.
		 *
		 * Escape removes the `open` attribute synchronously, so the assertion
		 * above can pass while `popkit:close` has not been dispatched yet: the
		 * native `close` event a `<dialog>` fires is a queued task, and it is
		 * that event `dialog.js` listens for, that runs `onClose`, and that
		 * therefore produces `popkit:close`. Reading `window.popkitEvents` the
		 * instant the dialog is gone races the queue — the spec passes in
		 * isolation and fails under a loaded full-suite run, which is the least
		 * useful way for a test to fail.
		 *
		 * Retrying the whole block waits for the event without softening a
		 * single assertion inside it, including the one that pins `popkit:close`
		 * to exactly one dispatch. `toPass` is bounded rather than left to the
		 * default so that a genuinely missing event still fails inside the test
		 * budget instead of consuming it.
		 */
		await expect( async () => {
			const events = await page.evaluate( () => window.popkitEvents );
			const mine = events.filter(
				( event ) => SPEC_SLUG === event.detail.slug
			);
			const beforeOpen = mine.find(
				( event ) => 'popkit:before-open' === event.name
			);
			const opened = mine.find(
				( event ) => 'popkit:open' === event.name
			);
			const closed = mine.find(
				( event ) => 'popkit:close' === event.name
			);

			expect( beforeOpen, 'popkit:before-open fired' ).toBeTruthy();
			expect(
				beforeOpen.cancelable,
				'popkit:before-open is the cancelable one'
			).toBe( true );

			expect( opened, 'popkit:open fired' ).toBeTruthy();
			expect( opened.detail ).toEqual( {
				id: subject.id,
				slug: SPEC_SLUG,
			} );
			expect( opened.onPopup, 'it was dispatched on the popup' ).toBe(
				true
			);
			expect( opened.targetSlug ).toBe( SPEC_SLUG );
			expect( opened.cancelable, 'popkit:open is not cancelable' ).toBe(
				false
			);

			expect(
				closed,
				'popkit:close fired for the Escape key'
			).toBeTruthy();
			expect( closed.detail ).toEqual( {
				id: subject.id,
				slug: SPEC_SLUG,
			} );
			expect( closed.onPopup ).toBe( true );

			// Exactly once. `dialog.js` calls `onClose` for every close path, so
			// an extra dispatch in the controller would double-count precisely
			// the closes an integration is most likely to be measuring.
			expect(
				mine.filter( ( event ) => 'popkit:close' === event.name )
					.length,
				'popkit:close fired once, not twice'
			).toBe( 1 );
		} ).toPass( { timeout: 10 * 1000 } );
	} );

	test( 'window.popkit.open() and .close() drive a popup, and an unknown slug only warns', async ( {
		page,
	} ) => {
		/** @type {string[]} */
		const warnings = [];

		page.on( 'console', ( message ) => {
			if ( 'warning' === message.type() ) {
				warnings.push( message.text() );
			}
		} );

		await suppressPopups( page, [ FIXTURE_SLUG ] );
		await page.goto( FIXTURE_PAGE );

		const modal = page.locator( openModalFor( SPEC_SLUG ) );

		await expect( modal ).toHaveCount( 1 );

		// The published surface is three members wide and no wider.
		expect(
			await page.evaluate( () => Object.keys( window.popkit ).sort() )
		).toEqual( [ 'close', 'open', 'version' ] );
		expect(
			await page.evaluate( () => window.popkit.version )
		).toBeGreaterThan( 0 );

		expect(
			await page.evaluate(
				( slug ) => window.popkit.close( slug ),
				SPEC_SLUG
			),
			'close() reports that it closed an open popup'
		).toBe( true );
		await expect( modal ).toHaveCount( 0 );

		expect(
			await page.evaluate(
				( slug ) => window.popkit.open( slug ),
				SPEC_SLUG
			),
			'open() reports that it opened one'
		).toBe( true );
		await expect( modal ).toHaveCount( 1 );

		/*
		 * A slug nothing on the page carries is a developer error, not a crash:
		 * this is called from a click handler in somebody else's theme, and an
		 * exception there takes the rest of that handler down with it.
		 */
		const unknown = await page.evaluate( () => {
			const result = { threw: false, open: null, close: null };

			try {
				result.open = window.popkit.open( 'no-such-popup' );
				result.close = window.popkit.close( 'no-such-popup' );
			} catch {
				result.threw = true;
			}

			return result;
		} );

		expect( unknown.threw, 'an unknown slug does not throw' ).toBe( false );
		expect( unknown.open ).toBe( false );
		expect( unknown.close ).toBe( false );

		await expect
			.poll(
				() =>
					warnings.filter( ( text ) =>
						text.includes( 'no-such-popup' )
					).length,
				{ message: 'both calls warned about the unknown slug' }
			)
			.toBe( 2 );
	} );

	test( 'a capped popup does not reopen on reload, and clearing storage restores it', async ( {
		page,
		requestUtils,
	} ) => {
		let capped = null;

		try {
			capped = await createPopup( requestUtils, 'lifecycle-capped', {
				_popkit_frequency: {
					mode: 'once_ever',
					days: 7,
					on_convert: 'suppress_forever',
				},
			} );

			// The shared popup has the lower ID and would otherwise take the
			// single automatic open.
			await suppressPopups( page, [ FIXTURE_SLUG, SPEC_SLUG ] );

			const modal = page.locator( openModalFor( 'lifecycle-capped' ) );

			await page.goto( FIXTURE_PAGE );
			await expect( modal, 'it opened the first time' ).toHaveCount( 1 );

			const records = await seenRecords( page, capped.id );

			expect(
				records.local,
				'a seen record was written after a successful open'
			).not.toBeNull();

			const stored = JSON.parse( records.local );

			expect( stored.converted ).toBe( false );
			expect(
				Number.isFinite( stored.at ),
				'the record carries an epoch timestamp'
			).toBe( true );

			await page.reload();
			await settle( page );

			await expect(
				page.locator( rootFor( 'lifecycle-capped' ) ),
				'the markup is still emitted — the cap is a client decision'
			).toHaveCount( 1 );
			await expect(
				modal,
				'once_ever suppressed the second pageview'
			).toHaveCount( 0 );

			await page.evaluate( () => {
				window.localStorage.clear();
				window.sessionStorage.clear();
			} );

			await page.reload();
			await expect(
				modal,
				'clearing browser storage restores it'
			).toHaveCount( 1 );
		} finally {
			await deletePopup( requestUtils, capped?.id );
		}
	} );

	test( 'no assets are enqueued on a page where zero popups match', async ( {
		page,
		requestUtils,
	} ) => {
		/*
		 * Until Phase 4 registers the built-in server conditions every stored
		 * rule is indeterminate, so a *published* popup survives matching on
		 * every URL — including a page it targets away from. "Zero popups match"
		 * is therefore created here rather than assumed of a URL, exactly as
		 * `tests/e2e/README.md` requires. Restored in `finally`, because leaving
		 * the fixtures drafted would break every other file in the suite.
		 */
		const drafted = await draftPublishedPopups( requestUtils );

		try {
			/** @type {string[]} */
			const requests = [];

			page.on( 'request', ( request ) => {
				if ( POPKIT_ASSET.test( request.url() ) ) {
					requests.push( request.url() );
				}
			} );

			const response = await page.goto( CLEAN_PAGE );

			expect( response.status() ).toBe( 200 );

			await expect(
				page.locator( '#popkit-config' ),
				'no config element'
			).toHaveCount( 0 );
			await expect(
				page.locator( ANY_ROOT ),
				'no popup markup'
			).toHaveCount( 0 );

			const assets = await page.evaluate( () => {
				const sources = [];

				document
					.querySelectorAll( 'script[src], link[href]' )
					.forEach( ( node ) =>
						sources.push(
							node.getAttribute( 'src' ) ||
								node.getAttribute( 'href' )
						)
					);

				return sources;
			} );

			expect(
				assets.filter( ( source ) => POPKIT_ASSET.test( source ) ),
				'neither dist/frontend.js nor dist/frontend.css was enqueued'
			).toEqual( [] );

			expect(
				requests,
				'and nothing was requested over the network'
			).toEqual( [] );
		} finally {
			await publishPopups( requestUtils, drafted );
		}
	} );
} );
