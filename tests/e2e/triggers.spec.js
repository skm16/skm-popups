/**
 * Triggers: arming, firing, first-fire-wins, and teardown.
 *
 * Every test here is an exit criterion in `docs/build-plan.md` -> Phase 3, or the
 * architecture invariant in `docs/CLAUDE.md` those criteria exist to protect:
 * **nothing binds to the DOM for a popup that could never show**, every `setup()`
 * returns a teardown, and the first trigger to fire tears down its siblings.
 *
 * ## Listeners are counted, not inferred
 *
 * Several criteria here are about listeners that must be *absent* — a sibling
 * torn down by a fire, everything removed on `pagehide`, nothing bound at all for
 * a popup the pipeline rejected. "The popup did not reopen" is weak evidence for
 * any of them: `api.fire()` is idempotent, so a leaked scroll listener that fires
 * a second time produces exactly the same screen as one that was removed.
 *
 * So `trackPopkitListeners()` wraps `EventTarget.prototype.addEventListener` and
 * `removeEventListener` before any page script runs and keeps a net count per
 * target and event type — but only for calls whose stack names popkit's own
 * bundle. Attribution matters because WordPress core and the theme bind their own
 * `click` and `scroll` handlers to the same two targets and never remove them; an
 * unattributed count would be a number about the whole page rather than about
 * this plugin. Every test that asserts a zero first asserts the matching non-zero
 * on the same instrumentation, so attribution silently failing fails a test
 * instead of passing one.
 *
 * `pagehide` needs one more trick: the document is gone by the time the next page
 * loads, so the probe listener is registered *after* the page has loaded, which
 * puts it after popkit's own in registration order and therefore after its
 * teardown has run. It writes the counts to `sessionStorage`, which survives a
 * same-origin navigation, and the next document reads them back.
 *
 * ## Why this file authors a page as well as popups
 *
 * `scroll_depth` reports 100 on a document that fits in the viewport — a
 * deliberate choice documented in `src/frontend/triggers/scroll-depth.js`, on the
 * grounds that a visitor on a page with nothing below the fold *has* read all of
 * it. The seeded `/popkit-e2e/` fixture page is short, so every scroll assertion
 * against it would be vacuous: the trigger would fire on the first frame and a
 * threshold test would pass without anything ever scrolling. This file therefore
 * creates a tall page in `beforeAll` and deletes it in `afterAll`, and asserts
 * that it really does scroll before drawing any conclusion from it.
 *
 * The page also carries a `<button>` with a `<span>` inside it. The click trigger
 * matches with `closest()`, so a click on the span is a click on the button, and
 * focus must return to the button — the element the author's selector named —
 * rather than to the decorative span the visitor's pointer happened to land on.
 *
 * ## These specs author their own popups
 *
 * The seeded `e2e-modal` fixture carries a `url_path` rule belonging to a
 * condition nothing registers until Phase 4, so the client fails it closed and it
 * cannot open. It is suppressed through `popkit:before-open` anyway, exactly as
 * the other spec files do, so this file keeps its meaning once that rule starts
 * passing. Everything asserted here is created through `requestUtils` with an
 * empty rule set — `{ "groups": [] }`, which `docs/data-model.md` defines as
 * "always match" — so the only thing standing between a popup and the screen is
 * its trigger, which is the subject.
 *
 * No new harness fixture is required: this file creates and deletes everything it
 * needs over REST.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/** Seeded fixture page. Short, and used only where scrolling is irrelevant. */
const FIXTURE_PAGE = '/popkit-e2e/';

/** Slug of the seeded fixture popup, emitted on every page. */
const FIXTURE_SLUG = 'e2e-modal';

/** Slug of the tall page this file authors. */
const TALL_SLUG = 'popkit-e2e-triggers';

/** Path of the tall page this file authors. */
const TALL_PAGE = `/${ TALL_SLUG }/`;

/** `id` of the button `click_selector` is pointed at. */
const CLICK_TARGET_ID = 'popkit-e2e-click-target';

/** Test id of the span *inside* that button, which is what gets clicked. */
const CLICK_LABEL_TESTID = 'popkit-e2e-click-label';

/** REST collection for the popup post type. */
const POPUP_PATH = '/wp/v2/popkit_popup';

/** REST collection for pages. */
const PAGE_PATH = '/wp/v2/pages';

/** Path fragment identifying the context route under any permalink structure. */
const CONTEXT_ROUTE = 'popkit/v1/context';

/**
 * A condition key nothing registers, in this phase or any later one.
 *
 * Stored rules of this type are preserved and tagged `"unknown": true` by the
 * sanitizer, survive server matching as indeterminate, and then fail closed on
 * the client. That is a real gate — the popup is emitted and refuses to open —
 * and it is the gate available today, because Phase 4 has not registered the
 * built-in client conditions yet.
 */
const UNKNOWN_CONDITION = 'popkit_e2e_unregistered';

/** Where the `pagehide` probe leaves its listener counts for the next document. */
const SNAPSHOT_KEY = 'popkit-e2e-pagehide-listeners';

/** Delay asserted by the `page_load` test, in milliseconds. */
const DELAY_MS = 3000;

/** Scroll threshold used throughout, as a percentage. */
const SCROLL_PERCENT = 50;

/** Paragraphs of filler that make the authored page taller than any viewport. */
const FILLER_PARAGRAPHS = 60;

/** Net-count keys produced by {@link trackPopkitListeners}. */
const SCROLL_LISTENER = 'window:scroll';
const CLICK_LISTENER = 'document:click';
const HASH_LISTENER = 'window:hashchange';
const PAGEHIDE_LISTENER = 'window:pagehide';

/**
 * The three triggers that bind a listener, so a teardown has something to prove.
 *
 * None of them can fire on the tall page with no interaction: the document is
 * taller than the viewport so the scroll threshold is not met, nothing is
 * clicked, and the URL names no popup.
 */
const LISTENING_TRIGGERS = [
	{ type: 'scroll_depth', values: { percent: SCROLL_PERCENT } },
	{ type: 'click_selector', values: { selector: `#${ CLICK_TARGET_ID }` } },
	{ type: 'deep_link' },
];

/** A campaign that ended years ago, for the schedule gate. */
const EXPIRED_SCHEDULE = {
	enabled: true,
	timezone: 'site',
	start: null,
	end: '2020-01-01T00:00:00Z',
	recurrence: { days: [], windows: [] },
};

/** One impression, ever. */
const ONCE_EVER = {
	mode: 'once_ever',
	days: 7,
	on_convert: 'suppress_forever',
};

/** Content every authored popup carries, so it always has a real heading. */
const POPUP_CONTENT = [
	'<!-- wp:heading --><h2 class="wp-block-heading">Trigger fixture popup</h2><!-- /wp:heading -->',
	'<!-- wp:paragraph --><p>Created by a trigger spec and deleted again.</p><!-- /wp:paragraph -->',
].join( '\n\n' );

/**
 * Content of the tall page: a nested click target, then enough prose to scroll.
 */
const TALL_PAGE_CONTENT = [
	'<!-- wp:heading --><h2 class="wp-block-heading">popkit trigger fixture</h2><!-- /wp:heading -->',
	`<!-- wp:html --><p><button type="button" id="${ CLICK_TARGET_ID }"><span data-testid="${ CLICK_LABEL_TESTID }">Open the popup</span></button></p><!-- /wp:html -->`,
	...Array.from(
		{ length: FILLER_PARAGRAPHS },
		( value, index ) =>
			`<!-- wp:paragraph --><p>Filler paragraph ${
				index + 1
			}. This page exists to be taller than the viewport, so that a scroll depth threshold means something on it.</p><!-- /wp:paragraph -->`
	),
].join( '\n\n' );

/** Selector for one popup's root, whatever state it is in. */
const rootFor = ( slug ) => `[data-popkit-slug="${ slug }"]`;

/** Selector for one popup's dialog while it is on screen. */
const openModalFor = ( slug ) => `dialog[data-popkit-slug="${ slug }"][open]`;

/** Whether a URL is the context route, under pretty or plain permalinks. */
const isContextUrl = ( url ) => String( url ).includes( CONTEXT_ROUTE );

/** Reads one net listener count, treating "never seen" as zero. */
const listenerCount = ( counts, key ) => counts[ key ] || 0;

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
 * Force-deletes every page carrying a slug, so a re-created one keeps it.
 */
const deleteExistingPages = async ( requestUtils, slug ) => {
	const existing = await requestUtils.rest( {
		method: 'GET',
		path: PAGE_PATH,
		params: {
			slug,
			status: 'publish,draft,pending,private,future',
			per_page: 100,
			_fields: 'id',
		},
	} );

	for ( const page of existing || [] ) {
		// Sequential by necessity: these are writes against one WordPress
		// install served by a single-threaded PHP server.
		await requestUtils.rest( {
			method: 'DELETE',
			path: `${ PAGE_PATH }/${ page.id }`,
			params: { force: true },
		} );
	}
};

/**
 * A rule set carrying one rule nothing can satisfy, for the gate tests.
 *
 * **Phase 4 strengthens this.** Once the built-in client conditions are
 * registered, the honest subject of "a deep link to a popup whose conditions
 * fail" is a *registered* condition that evaluates false — `device` with a
 * `max_width` below the viewport is the cheapest — because that exercises
 * targeting rather than the fail-closed path for an unresolvable rule. Both are
 * worth having; today only this one exists, since no client condition has an
 * evaluator yet and every rule therefore fails closed regardless of its type.
 */
const failingConditions = () => ( {
	groups: [ { rules: [ { type: UNKNOWN_CONDITION, values: {} } ] } ],
} );

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
 * Records every popup open, with the moment it happened.
 *
 * The moment is `performance.now()`, which counts from the document's creation
 * and is unaffected by the wall clock. A trigger's timer cannot start before the
 * document exists, so a reading below a configured delay is proof the delay was
 * not honoured — and one above it is not proof of much on its own, which is why
 * the `page_load` test also measures a popup with no delay at all.
 */
const watchOpens = async ( page ) => {
	await page.addInitScript( () => {
		window.popkitOpens = [];

		document.addEventListener( 'popkit:open', ( event ) => {
			window.popkitOpens.push( {
				slug: event.detail.slug,
				at: window.performance.now(),
			} );
		} );
	} );
};

/** How many times one popup has opened during this pageview. */
const openCount = async ( page, slug ) =>
	page.evaluate(
		( wanted ) =>
			( window.popkitOpens || [] ).filter(
				( entry ) => wanted === entry.slug
			).length,
		slug
	);

/** When one popup first opened, in milliseconds since the document was created. */
const openedAt = async ( page, slug ) =>
	page.evaluate( ( wanted ) => {
		const entry = ( window.popkitOpens || [] ).find(
			( item ) => wanted === item.slug
		);

		return entry ? entry.at : null;
	}, slug );

/**
 * Counts the listeners popkit itself binds to `window` and `document`.
 *
 * Attribution is by stack frame: the wrapper asks whether the call came from
 * popkit's own bundle, which is the only way to separate its listeners from the
 * theme's and core's on the same two targets. `dist/frontend.js` is enqueued as a
 * real file, so it appears by name in the stack of anything it calls.
 *
 * Only `window` and `document` are counted, because those are the only shared
 * targets popkit's triggers use: `scroll` and `hashchange` on the window, a
 * delegated `click` on the document, and one shared `pagehide` on the window for
 * every armed popup on the page. Listeners bound to a popup's own element are
 * `dialog.js`'s business and are counted by `accessibility.spec.js` instead.
 */
const trackPopkitListeners = async ( page ) => {
	await page.addInitScript( () => {
		const add = EventTarget.prototype.addEventListener;
		const remove = EventTarget.prototype.removeEventListener;
		const counts = {};

		/** popkit's bundle, as it appears in a stack frame. */
		const BUNDLE = '/popkit/dist/frontend.js';

		const label = ( target ) => {
			if ( target === window ) {
				return 'window';
			}

			return target === window.document ? 'document' : '';
		};

		const fromPopkit = () =>
			String( new Error().stack || '' ).includes( BUNDLE );

		const tally = ( target, type, delta ) => {
			const where = label( target );

			if ( '' === where || 'string' !== typeof type || ! fromPopkit() ) {
				return;
			}

			const key = `${ where }:${ type }`;

			counts[ key ] = ( counts[ key ] || 0 ) + delta;
		};

		EventTarget.prototype.addEventListener = function ( ...args ) {
			tally( this, args[ 0 ], 1 );

			return add.apply( this, args );
		};

		EventTarget.prototype.removeEventListener = function ( ...args ) {
			tally( this, args[ 0 ], -1 );

			return remove.apply( this, args );
		};

		window.popkitListeners = () => Object.assign( {}, counts );
	} );
};

/** Current net listener counts, as a plain object. */
const popkitListeners = async ( page ) =>
	page.evaluate( () => window.popkitListeners() );

/** The document's scrollable distance, in CSS pixels. */
const scrollableDistance = async ( page ) =>
	page.evaluate( () => {
		const root = document.scrollingElement || document.documentElement;

		return root.scrollHeight - root.clientHeight;
	} );

/**
 * Scrolls to a share of the scrollable distance, instantly.
 *
 * `behavior: 'instant'` rather than the default, which a theme may have set to
 * `smooth`: a smooth scroll arrives over several frames, and a test that measured
 * before it landed would be measuring the animation rather than the trigger.
 */
const scrollToPercent = async ( page, percent ) => {
	await page.evaluate( ( share ) => {
		const root = document.scrollingElement || document.documentElement;
		const distance = root.scrollHeight - root.clientHeight;

		window.scrollTo( {
			top: Math.round( ( distance * share ) / 100 ),
			behavior: 'instant',
		} );
	}, percent );
};

/**
 * Waits long enough for the runtime to have decided, then lets an assertion run.
 *
 * A negative assertion needs a settling point. `window.popkit` is installed
 * during boot, before any popup is considered, so waiting for it and then giving
 * the runtime a further beat is the smallest honest wait — an immediate
 * `toHaveCount( 0 )` would pass against a runtime that had not started yet.
 */
const settle = async ( page, extraMs ) => {
	await page.waitForFunction( () => undefined !== window.popkit );
	await page.waitForTimeout( extraMs || 500 );
};

test.describe( 'popkit triggers', () => {
	/** The tall page this file authors, shared by every test and never mutated. */
	let tallPage = null;

	test.beforeAll( async ( { requestUtils } ) => {
		/*
		 * A run killed part way through leaves the page behind, and WordPress
		 * would then hand the replacement a `-2` suffix — every URL in this file
		 * would 404 and every assertion would fail for a reason that is not the
		 * plugin. Deleting first makes the file self-healing; asserting the slug
		 * afterwards means a surprise still fails here rather than eleven times
		 * over.
		 */
		await deleteExistingPages( requestUtils, TALL_SLUG );

		tallPage = await requestUtils.rest( {
			method: 'POST',
			path: PAGE_PATH,
			data: {
				title: 'popkit E2E Triggers',
				slug: TALL_SLUG,
				status: 'publish',
				content: TALL_PAGE_CONTENT,
			},
		} );

		expect(
			tallPage.slug,
			'the authored page kept the slug every URL in this file is built from'
		).toBe( TALL_SLUG );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		if ( tallPage?.id ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `${ PAGE_PATH }/${ tallPage.id }`,
				params: { force: true },
			} );
		}

		tallPage = null;
	} );

	test.beforeEach( async ( { page } ) => {
		await suppressPopups( page, [ FIXTURE_SLUG ] );
	} );

	test( 'page_load waits for its delay, and a delay of zero does not', async ( {
		page,
		requestUtils,
	} ) => {
		let delayed = null;
		let immediate = null;

		try {
			delayed = await createPopup( requestUtils, 'trigger-delayed', {
				_popkit_triggers: [
					{ type: 'page_load', values: { delay_ms: DELAY_MS } },
				],
			} );

			await watchOpens( page );
			await page.goto( TALL_PAGE );

			await expect(
				page.locator( openModalFor( 'trigger-delayed' ) ),
				'the delayed popup did eventually open'
			).toHaveCount( 1 );

			/*
			 * Measured against the document's own timeline rather than sampled at
			 * a wall-clock moment. "Assert it is closed, wait, assert it is open"
			 * looks equivalent and is not: arming happens somewhere between
			 * `DOMContentLoaded` and `load`, so on a slow response the first
			 * sample can land after the timer has already fired, and the test
			 * fails for a reason that is not the plugin. The timer cannot start
			 * before the document exists, so this reading is a floor no
			 * unimplemented delay could reach.
			 */
			const delayedAt = await openedAt( page, 'trigger-delayed' );

			expect(
				delayedAt,
				`it opened ${ delayedAt } ms into the document, which is at least the ${ DELAY_MS } ms delay`
			).toBeGreaterThanOrEqual( DELAY_MS );

			await deletePopup( requestUtils, delayed.id );
			delayed = null;

			/*
			 * The control. Without it, "at least 3000 ms" would also be satisfied
			 * by a runtime that ignored `delay_ms` entirely and simply took three
			 * seconds to boot.
			 */
			immediate = await createPopup( requestUtils, 'trigger-immediate', {
				_popkit_triggers: [
					{ type: 'page_load', values: { delay_ms: 0 } },
				],
			} );

			await page.goto( TALL_PAGE );

			await expect(
				page.locator( openModalFor( 'trigger-immediate' ) ),
				'a delay of zero still opens the popup'
			).toHaveCount( 1 );

			const immediateAt = await openedAt( page, 'trigger-immediate' );

			expect(
				immediateAt,
				`delay_ms 0 opened at ${ immediateAt } ms, nowhere near the ${ DELAY_MS } ms the other one waited`
			).toBeLessThan( DELAY_MS );
		} finally {
			await deletePopup( requestUtils, delayed?.id );
			await deletePopup( requestUtils, immediate?.id );
		}
	} );

	test( 'scroll_depth fires at the configured depth and not before it', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			created = await createPopup( requestUtils, 'trigger-scroll', {
				_popkit_triggers: [
					{
						type: 'scroll_depth',
						values: { percent: SCROLL_PERCENT },
					},
				],
			} );

			const modal = page.locator( openModalFor( 'trigger-scroll' ) );

			await page.goto( TALL_PAGE );

			/*
			 * The trigger reports 100 on a document that fits in the viewport, so
			 * every assertion below would pass vacuously on a short page.
			 */
			expect(
				await scrollableDistance( page ),
				'the authored page is taller than the viewport'
			).toBeGreaterThan( 0 );

			await settle( page );
			await expect(
				modal,
				'nothing opened at the top of the page'
			).toHaveCount( 0 );

			await scrollToPercent( page, 25 );
			await settle( page );

			await expect(
				modal,
				'a quarter of the way down is not half way down'
			).toHaveCount( 0 );

			await scrollToPercent( page, 90 );

			await expect(
				modal,
				'past the configured depth, it opened'
			).toHaveCount( 1 );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'click_selector fires on a descendant and returns focus to the matched element', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			created = await createPopup( requestUtils, 'trigger-click', {
				_popkit_triggers: [
					{
						type: 'click_selector',
						values: { selector: `#${ CLICK_TARGET_ID }` },
					},
				],
			} );

			const modal = page.locator( openModalFor( 'trigger-click' ) );

			await page.goto( TALL_PAGE );
			await settle( page );

			await expect(
				modal,
				'a click trigger does not fire on page load'
			).toHaveCount( 0 );

			// The span inside the button, not the button. `closest()` decides the
			// match, so a click on a descendant is a click on the element the
			// author named.
			await page.getByTestId( CLICK_LABEL_TESTID ).click();

			await expect(
				modal,
				'clicking a descendant of the matched element opened it'
			).toHaveCount( 1 );

			await page.keyboard.press( 'Escape' );
			await expect( modal ).toHaveCount( 0 );

			await expect(
				page.locator( `#${ CLICK_TARGET_ID }` ),
				'focus returned to the matched element, not to the span inside it'
			).toBeFocused();
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'a deep link opens the popup it names, in the query and the fragment form', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			created = await createPopup( requestUtils, 'trigger-deep-link', {
				_popkit_triggers: [ { type: 'deep_link' } ],
			} );

			const modal = page.locator( openModalFor( 'trigger-deep-link' ) );

			// Nothing names it yet. Asserting this first is what makes the two
			// URLs below the cause of anything.
			await page.goto( TALL_PAGE );
			await settle( page );
			await expect(
				modal,
				'a deep-link popup stays shut on a plain URL'
			).toHaveCount( 0 );

			// The fragment arriving after load, which is an ordinary in-page
			// anchor and never reaches the server.
			await page.evaluate( ( slug ) => {
				window.location.hash = `#popkit-${ slug }`;
			}, 'trigger-deep-link' );

			await expect(
				modal,
				'a hashchange naming the popup opened it'
			).toHaveCount( 1 );

			/*
			 * Each navigation below differs from the last by more than its
			 * fragment. A goto that changed only the fragment would be a
			 * same-document navigation, the runtime would not reboot, and the
			 * assertion would be reading the previous pageview's dialog.
			 */
			await page.goto( `${ TALL_PAGE }?popkit=trigger-deep-link` );

			await expect(
				modal,
				'?popkit={slug} opened it on load'
			).toHaveCount( 1 );

			await page.goto( `${ TALL_PAGE }#popkit-trigger-deep-link` );

			await expect(
				modal,
				'#popkit-{slug} opened it on load'
			).toHaveCount( 1 );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'a deep link naming a different popup opens nothing', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			created = await createPopup( requestUtils, 'trigger-deep-strict', {
				_popkit_triggers: [ { type: 'deep_link' } ],
			} );

			const root = page.locator( rootFor( 'trigger-deep-strict' ) );
			const modal = page.locator( openModalFor( 'trigger-deep-strict' ) );

			// A prefix of the slug. Both forms compare the whole value, so
			// `?popkit=giving` must not open `giving-tuesday`.
			await page.goto( `${ TALL_PAGE }?popkit=trigger-deep` );
			await settle( page );

			await expect( root, 'the markup was emitted' ).toHaveCount( 1 );
			await expect(
				modal,
				'the query form matches the whole slug, never a prefix of it'
			).toHaveCount( 0 );

			await page.goto( `${ TALL_PAGE }#popkit-trigger-deep` );
			await settle( page );

			await expect(
				modal,
				'and neither does the fragment form'
			).toHaveCount( 0 );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'the first trigger to fire tears its siblings down', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			created = await createPopup( requestUtils, 'trigger-race', {
				_popkit_triggers: [
					{
						type: 'scroll_depth',
						values: { percent: SCROLL_PERCENT },
					},
					{
						type: 'click_selector',
						values: { selector: `#${ CLICK_TARGET_ID }` },
					},
				],
			} );

			const modal = page.locator( openModalFor( 'trigger-race' ) );

			await watchOpens( page );
			await trackPopkitListeners( page );
			await page.goto( TALL_PAGE );
			await settle( page );

			const armed = await popkitListeners( page );

			expect(
				listenerCount( armed, SCROLL_LISTENER ),
				'the scroll trigger armed'
			).toBe( 1 );
			expect(
				listenerCount( armed, CLICK_LISTENER ),
				'and so did the click trigger'
			).toBe( 1 );
			await expect( modal, 'neither has fired yet' ).toHaveCount( 0 );

			await page.getByTestId( CLICK_LABEL_TESTID ).click();
			await expect( modal, 'the click won the race' ).toHaveCount( 1 );

			/*
			 * The assertion that distinguishes a teardown from an idempotent
			 * `fire()`. Both produce one popup on screen; only one of them leaves
			 * the page without a scroll listener.
			 */
			const afterFire = await popkitListeners( page );

			expect(
				listenerCount( afterFire, SCROLL_LISTENER ),
				'firing tore the sibling scroll trigger down'
			).toBe( 0 );
			expect(
				listenerCount( afterFire, CLICK_LISTENER ),
				'and the trigger that fired removed its own listener'
			).toBe( 0 );

			await page.keyboard.press( 'Escape' );
			await expect( modal ).toHaveCount( 0 );

			// The second trigger's condition, met after the first one won.
			await scrollToPercent( page, 100 );
			await settle( page );

			await expect(
				modal,
				'the torn-down scroll trigger did not put it back on screen'
			).toHaveCount( 0 );

			await expect
				.poll( () => openCount( page, 'trigger-race' ), {
					message: 'the popup opened exactly once',
				} )
				.toBe( 1 );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'nothing binds to the DOM for a popup that could never show', async ( {
		page,
		requestUtils,
	} ) => {
		let gated = null;
		let armable = null;

		try {
			gated = await createPopup( requestUtils, 'trigger-never-shows', {
				_popkit_conditions: failingConditions(),
				_popkit_triggers: LISTENING_TRIGGERS,
			} );

			await trackPopkitListeners( page );
			await page.goto( TALL_PAGE );
			await settle( page );

			// Neither of the two ways this could pass without meaning anything:
			// the popup is on the page, and the runtime ran.
			await expect(
				page.locator( rootFor( 'trigger-never-shows' ) ),
				'the server emitted the popup'
			).toHaveCount( 1 );
			expect(
				await page.evaluate( () => undefined !== window.popkit ),
				'and the runtime booted'
			).toBe( true );

			const rejected = await popkitListeners( page );

			expect(
				listenerCount( rejected, SCROLL_LISTENER ),
				'no scroll listener for a popup the pipeline refused'
			).toBe( 0 );
			expect(
				listenerCount( rejected, CLICK_LISTENER ),
				'no delegated click listener either'
			).toBe( 0 );
			expect(
				listenerCount( rejected, HASH_LISTENER ),
				'and no hashchange listener'
			).toBe( 0 );
			expect(
				listenerCount( rejected, PAGEHIDE_LISTENER ),
				'not even the shared pagehide listener, which is only added when something arms'
			).toBe( 0 );

			/*
			 * The control, and the reason the four zeros above are evidence: the
			 * same three triggers on a popup nothing gates produce four non-zero
			 * counts on the same instrumentation, in the same document.
			 */
			armable = await createPopup( requestUtils, 'trigger-arms', {
				_popkit_triggers: LISTENING_TRIGGERS,
			} );

			await page.reload();
			await settle( page );

			const armed = await popkitListeners( page );

			expect(
				listenerCount( armed, SCROLL_LISTENER ),
				'an eligible popup does arm its scroll trigger'
			).toBe( 1 );
			expect(
				listenerCount( armed, CLICK_LISTENER ),
				'and its click trigger'
			).toBe( 1 );
			expect(
				listenerCount( armed, HASH_LISTENER ),
				'and its deep-link trigger'
			).toBe( 1 );
			expect(
				listenerCount( armed, PAGEHIDE_LISTENER ),
				'behind one shared pagehide listener'
			).toBe( 1 );
		} finally {
			await deletePopup( requestUtils, gated?.id );
			await deletePopup( requestUtils, armable?.id );
		}
	} );

	test( 'pagehide tears every trigger down and leaves no listener behind', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			created = await createPopup( requestUtils, 'trigger-pagehide', {
				_popkit_triggers: LISTENING_TRIGGERS,
			} );

			await trackPopkitListeners( page );
			await page.goto( TALL_PAGE );
			await settle( page );

			const armed = await popkitListeners( page );

			expect(
				listenerCount( armed, SCROLL_LISTENER ),
				'the scroll trigger is armed, so there is something to leak'
			).toBe( 1 );
			expect( listenerCount( armed, CLICK_LISTENER ) ).toBe( 1 );
			expect( listenerCount( armed, HASH_LISTENER ) ).toBe( 1 );
			expect(
				listenerCount( armed, PAGEHIDE_LISTENER ),
				'one shared pagehide listener, not one per trigger'
			).toBe( 1 );

			/*
			 * Registered now rather than from an init script, and that is the
			 * whole trick: listeners run in registration order, popkit bound its
			 * `pagehide` handler while arming, and this one is therefore invoked
			 * after its teardown has already run. `sessionStorage` survives a
			 * same-origin navigation, so the next document can read what the last
			 * one saw on its way out.
			 */
			await page.evaluate( ( key ) => {
				window.addEventListener( 'pagehide', () => {
					window.sessionStorage.setItem(
						key,
						JSON.stringify( window.popkitListeners() )
					);
				} );
			}, SNAPSHOT_KEY );

			await page.goto( FIXTURE_PAGE );

			const recorded = await page.evaluate(
				( key ) => window.sessionStorage.getItem( key ),
				SNAPSHOT_KEY
			);

			expect( recorded, 'the pagehide probe ran' ).not.toBeNull();

			const counts = JSON.parse( recorded );

			expect(
				listenerCount( counts, SCROLL_LISTENER ),
				'the scroll listener was removed on pagehide'
			).toBe( 0 );
			expect(
				listenerCount( counts, CLICK_LISTENER ),
				'and the delegated click listener'
			).toBe( 0 );
			expect(
				listenerCount( counts, HASH_LISTENER ),
				'and the hashchange listener'
			).toBe( 0 );
			expect(
				listenerCount( counts, PAGEHIDE_LISTENER ),
				'and the shared pagehide listener removed itself once the last popup disarmed'
			).toBe( 0 );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'a deep link does not open a popup whose conditions fail', async ( {
		page,
		requestUtils,
	} ) => {
		let gated = null;
		let control = null;

		try {
			/*
			 * Deep linking is a trigger, not a bypass — `docs/build-plan.md` ->
			 * Phase 3. The trigger calls `api.fire()`, which is reached only after
			 * stages 7 to 9 have passed, rather than `window.popkit.open()`, which
			 * deliberately does skip them.
			 *
			 * **What strengthens in Phase 4:** the gate exercised here is stage 7
			 * failing closed on a rule nothing can evaluate. Once the built-in
			 * client conditions are registered, add a second case using a
			 * registered condition that evaluates false — `device` with a
			 * `max_width` below the viewport — so this criterion covers targeting
			 * that ran and said no, as well as targeting that could not run.
			 */
			gated = await createPopup( requestUtils, 'trigger-deep-gated', {
				_popkit_conditions: failingConditions(),
				_popkit_triggers: [ { type: 'deep_link' } ],
			} );

			const root = page.locator( rootFor( 'trigger-deep-gated' ) );
			const modal = page.locator( openModalFor( 'trigger-deep-gated' ) );

			await page.goto( `${ TALL_PAGE }?popkit=trigger-deep-gated` );
			await settle( page );

			await expect(
				root,
				'the markup was emitted — this is a client decision'
			).toHaveCount( 1 );
			await expect(
				modal,
				'the query form did not open a popup this visitor is not targeted by'
			).toHaveCount( 0 );

			await page.goto( `${ TALL_PAGE }#popkit-trigger-deep-gated` );
			await settle( page );

			await expect(
				modal,
				'and the fragment form is not a second door into it'
			).toHaveCount( 0 );

			/*
			 * The control. Without it, both assertions above would also pass if
			 * deep links were broken on this page, in this browser, at this
			 * moment — which is a different bug wearing the same result.
			 */
			control = await createPopup( requestUtils, 'trigger-deep-open', {
				_popkit_triggers: [ { type: 'deep_link' } ],
			} );

			await page.goto( `${ TALL_PAGE }?popkit=trigger-deep-open` );

			await expect(
				page.locator( openModalFor( 'trigger-deep-open' ) ),
				'the same link form opens an identical popup nothing gates'
			).toHaveCount( 1 );
		} finally {
			await deletePopup( requestUtils, gated?.id );
			await deletePopup( requestUtils, control?.id );
		}
	} );

	test( 'a deep link does not open a popup outside its schedule', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			created = await createPopup( requestUtils, 'trigger-deep-expired', {
				_popkit_schedule: EXPIRED_SCHEDULE,
				_popkit_triggers: [ { type: 'deep_link' } ],
			} );

			/** @type {string[]} */
			const contextRequests = [];

			page.on( 'request', ( request ) => {
				if ( isContextUrl( request.url() ) ) {
					contextRequests.push( request.url() );
				}
			} );

			await page.goto( `${ TALL_PAGE }?popkit=trigger-deep-expired` );
			await settle( page );

			await expect(
				page.locator( rootFor( 'trigger-deep-expired' ) ),
				'the markup was emitted'
			).toHaveCount( 1 );

			/*
			 * The schedule was genuinely evaluated against server time, rather
			 * than the popup being suppressed because context never arrived —
			 * which would make this a fail-closed test wearing a schedule's name.
			 */
			expect(
				contextRequests.length,
				'authoritative time was fetched exactly once'
			).toBe( 1 );

			await expect(
				page.locator( openModalFor( 'trigger-deep-expired' ) ),
				'a campaign that ended in 2020 does not reopen for a link'
			).toHaveCount( 0 );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'a deep link does not reopen a popup that has hit its frequency cap', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			created = await createPopup( requestUtils, 'trigger-deep-capped', {
				_popkit_frequency: ONCE_EVER,
				_popkit_triggers: [ { type: 'deep_link' } ],
			} );

			const modal = page.locator( openModalFor( 'trigger-deep-capped' ) );

			await page.goto( `${ TALL_PAGE }?popkit=trigger-deep-capped` );

			// The first visit is the control: the link works, and the impression
			// it spends is the one the cap allows.
			await expect( modal, 'the first deep link opened it' ).toHaveCount(
				1
			);

			await page.keyboard.press( 'Escape' );
			await expect( modal ).toHaveCount( 0 );

			// Same URL, same link, same visitor.
			await page.reload();
			await settle( page );

			await expect(
				page.locator( rootFor( 'trigger-deep-capped' ) ),
				'the markup is still emitted — the cap is a client decision'
			).toHaveCount( 1 );
			await expect(
				modal,
				'once_ever suppressed the second visit, deep link and all'
			).toHaveCount( 0 );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );
} );
