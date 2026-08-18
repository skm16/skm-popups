/**
 * Conditions: what the server decides, and what the browser decides.
 *
 * Every test here is a Phase 4 exit criterion in `docs/build-plan.md`, or the
 * invariant in `docs/CLAUDE.md` -> Cache safety those criteria exist to protect:
 * **a server condition may depend only on the URL, never on the visitor.** The
 * split is the whole feature, so the two halves are asserted in deliberately
 * different ways:
 *
 * - A **server** rule that fails means the popup is absent from the response
 *   *entirely*. That is asserted against the raw HTML — the slug appears nowhere,
 *   not in the markup and not in the emitted config — because "the dialog is not
 *   on screen" is equally true of a popup the client decided against, which is the
 *   other half of the pipeline and a different claim.
 * - A **client** rule that fails means the markup is present and the popup never
 *   opens. That is asserted against the dialog, with the markup asserted first so
 *   the negative cannot pass by the popup having never been emitted. Neither form
 *   distinguishes a rule the client evaluated and refused from one it refused
 *   unread because the server tagged it `unknown`, so that tag is asserted on its
 *   own, against the emitted config rather than against the outcome.
 *
 * ## One popup opens per pageview, so controls take their own navigation
 *
 * `controller.js` refuses a non-programmatic open while another popup is on
 * screen — the invariant `accessibility.spec.js` states as "two eligible popups
 * never produce two simultaneously open modals". A test that put two *eligible*
 * popups on one page would therefore be asserting arming order rather than
 * targeting, and would read differently on the two projects. So every navigation
 * below has at most one popup that its conditions allow, and a test that needs a
 * positive control takes it on a second navigation, after the first popup has been
 * deleted or the URL has changed.
 *
 * ## The referrer test, and what it honestly cannot cover
 *
 * `document.referrer` is not settable from a spec; it is a consequence of how the
 * navigation happened. This file therefore produces a real one — it authors a
 * second page carrying a link, clicks the link, and **asserts the resulting
 * `document.referrer` before drawing any conclusion from the popup**. A test that
 * skipped that assertion would pass just as happily against a runtime that ignored
 * the referrer entirely, since the empty-referrer answer for a `contains` rule is
 * also "does not match".
 *
 * What that leaves untested end to end is a **cross-origin** referrer, and the
 * limitation is worth stating precisely rather than papering over:
 *
 * - The harness serves exactly one origin (`tests/e2e/README.md` -> The instance),
 *   so there is no second site to arrive from.
 * - Chromium's default referrer policy is `strict-origin-when-cross-origin`, which
 *   reduces a cross-origin referrer to its origin. Even with a second origin, a
 *   rule matching a referrer *path* could not be exercised — the browser would
 *   have removed the path before `document.referrer` was read.
 *
 * So the modes are exercised here against a same-origin referrer, which carries
 * host and path in full, and the match language itself is covered mode by mode by
 * the `url_path` test below and by the matcher's own unit tests. Nothing here
 * pretends to cover a cross-origin path match.
 *
 * ## Fixtures
 *
 * Two pages and one post, authored over REST in `beforeAll` and deleted in
 * `afterAll`; every popup is created inside the test that asserts on it and
 * force-deleted in a `finally`. **Nothing new is required from the harness seed.**
 * The seeded `e2e-modal` fixture is suppressed through `popkit:before-open` as in
 * every other spec, and its own `url_path` rule — inert until this phase, per
 * `tests/e2e/README.md` -> Fixtures — is asserted to narrow it to its page now.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/** Seeded fixture page. The `e2e-modal` fixture targets exactly this path. */
const FIXTURE_PAGE = '/popkit-e2e/';

/** Seeded prose-only page, one path segment longer than the fixture page. */
const CLEAN_PAGE = '/popkit-e2e-clean/';

/** Slug of the seeded fixture popup, suppressed throughout this file. */
const FIXTURE_SLUG = 'e2e-modal';

/** Slug of the page the client-condition tests are asserted on. */
const HOST_SLUG = 'popkit-e2e-conditions';

/** Path of that page. */
const HOST_PAGE = `/${ HOST_SLUG }/`;

/** Slug of the page whose only job is to link to the host page. */
const SOURCE_SLUG = 'popkit-e2e-referrer';

/** Path of that page, which is what a referrer rule matches against. */
const SOURCE_PAGE = `/${ SOURCE_SLUG }/`;

/** Slug of the authored post, for the conditions that read the queried object. */
const POST_SLUG = 'popkit-e2e-conditions-post';

/** Path of that post. */
const POST_PAGE = `/${ POST_SLUG }/`;

/** A path nothing resolves to, for `is_404`. */
const MISSING_PAGE = '/popkit-e2e-nothing-is-here/';

/** Test id of the link the referrer test clicks. */
const LINK_TESTID = 'popkit-e2e-conditions-link';

/** REST collection for the popup post type. */
const POPUP_PATH = '/wp/v2/popkit_popup';

/** REST collection for pages. */
const PAGE_PATH = '/wp/v2/pages';

/** REST collection for posts. */
const POST_PATH = '/wp/v2/posts';

/** Path fragment identifying the context route under any permalink structure. */
const CONTEXT_ROUTE = 'popkit/v1/context';

/** Absolute path of the context route, for an out-of-band read. */
const CONTEXT_URL = '/wp-json/popkit/v1/context';

/** Attribute the emitted config script is found by. */
const CONFIG_ID = 'id="popkit-config"';

/**
 * A condition key nothing registers, in this phase or any later one.
 *
 * Stored rules of this type are preserved by the sanitizer, emitted tagged
 * `"unknown": true`, and denied on the client before their type is even looked
 * up — which is what the `negate` test below is about.
 */
const UNKNOWN_CONDITION = 'popkit_e2e_unregistered';

/** Viewport width the `device` tests target, in CSS pixels. */
const NARROW_MAX_WIDTH = 480;

/** A `max_width` no viewport in either project fails. */
const WIDE_MAX_WIDTH = 4000;

/** A `max_width` no viewport can satisfy, for a rule that must evaluate false. */
const IMPOSSIBLE_MAX_WIDTH = 1;

/** Campaign parameter and value the `utm` test uses. */
const UTM_PARAM = 'utm_source';
const UTM_VALUE = 'spring-appeal';

/** Harness credentials. Documented in `tests/e2e/README.md` -> The instance. */
const ADMIN_USER = process.env.WP_USERNAME || 'admin';
const ADMIN_PASSWORD = process.env.WP_PASSWORD || 'password';

/** Content every authored popup carries, so it always has a real heading. */
const POPUP_CONTENT = [
	'<!-- wp:heading --><h2 class="wp-block-heading">Condition fixture popup</h2><!-- /wp:heading -->',
	'<!-- wp:paragraph --><p>Created by a conditions spec and deleted again.</p><!-- /wp:paragraph -->',
].join( '\n\n' );

/** Content of the page the client-condition tests run on. */
const HOST_PAGE_CONTENT = [
	'<!-- wp:heading --><h2 class="wp-block-heading">popkit conditions fixture</h2><!-- /wp:heading -->',
	'<!-- wp:paragraph --><p>Client-context conditions are asserted against this page.</p><!-- /wp:paragraph -->',
].join( '\n\n' );

/** Content of the page that exists to be navigated away from. */
const SOURCE_PAGE_CONTENT = [
	'<!-- wp:heading --><h2 class="wp-block-heading">popkit referrer fixture</h2><!-- /wp:heading -->',
	`<!-- wp:html --><p><a href="${ HOST_PAGE }" data-testid="${ LINK_TESTID }">Go to the conditions fixture</a></p><!-- /wp:html -->`,
].join( '\n\n' );

/** Content of the authored post. */
const POST_CONTENT =
	'<!-- wp:paragraph --><p>A post, so that post_type and taxonomy_term have something that is not a page.</p><!-- /wp:paragraph -->';

/** Selector for one popup's root, whatever state it is in. */
const rootFor = ( slug ) => `[data-popkit-slug="${ slug }"]`;

/** Selector for one popup's dialog while it is on screen. */
const openModalFor = ( slug ) => `dialog[data-popkit-slug="${ slug }"][open]`;

/** Whether a URL is the context route, under pretty or plain permalinks. */
const isContextUrl = ( url ) => String( url ).includes( CONTEXT_ROUTE );

/** A copy of a list, sorted, so two sets compare without ordering noise. */
const sorted = ( list ) => list.slice().sort();

/** A rule set of one group, which is the AND of the rules handed to it. */
const oneGroup = ( rules ) => ( { groups: [ { rules } ] } );

/**
 * Creates a published popup. Its rule set is empty — "always match" — unless the
 * caller supplies one, and it has no triggers, so it opens on page load.
 */
const createPopup = async ( requestUtils, slug, meta ) => {
	const popup = await requestUtils.rest( {
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

	/*
	 * Every assertion in this file addresses popups by slug, and WordPress hands
	 * a replacement a `-2` suffix when the name is taken. A popup left behind by
	 * a run that was killed part way through would therefore make a later run
	 * fail on selectors that match nothing, several assertions away from the
	 * cause. Checking here names the cause.
	 */
	expect(
		popup.slug,
		'the popup kept the slug this test addresses it by'
	).toBe( slug );

	return popup;
};

/** Creates a published popup carrying one rule. */
const createRuledPopup = async ( requestUtils, slug, rule ) =>
	createPopup( requestUtils, slug, {
		_popkit_conditions: oneGroup( [ rule ] ),
	} );

/**
 * Deletes a popup created by a test, bypassing the trash.
 *
 * `force: true` matters: a trashed popup is invisible to `post_status => 'any'`
 * and is exactly the leftover that survives the harness's seeding.
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

/** Deletes every popup a test created, in the order they were made. */
const deletePopups = async ( requestUtils, created ) => {
	for ( const popup of created ) {
		// Sequential by necessity: one WordPress install, one PHP process.
		await deletePopup( requestUtils, popup?.id );
	}
};

/**
 * Force-deletes everything in a collection carrying a slug.
 *
 * A run killed part way through leaves its pages behind, and WordPress would hand
 * the replacement a `-2` suffix — every URL in this file would 404 and every
 * assertion would fail for a reason that is not the plugin.
 */
const deleteExisting = async ( requestUtils, path, slug ) => {
	const existing = await requestUtils.rest( {
		method: 'GET',
		path,
		params: {
			slug,
			status: 'publish,draft,pending,private,future',
			per_page: 100,
			_fields: 'id',
		},
	} );

	for ( const item of existing || [] ) {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `${ path }/${ item.id }`,
			params: { force: true },
		} );
	}
};

/** Fetches a URL anonymously and returns its status and body. */
const fetchPage = async ( request, path ) => {
	const response = await request.get( path );

	return { status: response.status(), html: await response.text() };
};

/**
 * Extracts the emitted config from a response body.
 *
 * Sliced rather than matched: the config is a JSON script element with a known
 * id, and finding it by index keeps this readable without a pattern that would
 * have to know how the attributes are ordered.
 */
const configFrom = ( html ) => {
	const anchor = html.indexOf( CONFIG_ID );

	if ( -1 === anchor ) {
		return null;
	}

	const opens = html.indexOf( '>', anchor );
	const closes = html.indexOf( '</script>', opens );

	if ( -1 === opens || -1 === closes ) {
		return null;
	}

	return JSON.parse( html.slice( opens + 1, closes ) );
};

/** One popup's emitted config entry, or undefined when it was not emitted. */
const popupConfig = ( html, slug ) =>
	( configFrom( html )?.popups || [] ).find(
		( popup ) => slug === popup.slug
	);

/**
 * The one emitted rule of a popup created with a single-rule rule set.
 *
 * The shape is asserted on the way through rather than reached into blindly, so
 * a popup the server declined to emit — or emitted with its rule stripped, which
 * is what a *server* decision looks like — fails here naming what was missing,
 * instead of further down as a property read on `undefined`.
 */
const soleRule = ( html, slug ) => {
	const entry = popupConfig( html, slug );

	expect( entry, `${ slug } was emitted` ).toBeTruthy();
	expect(
		entry.conditions?.groups,
		`${ slug } was emitted carrying its one group`
	).toHaveLength( 1 );
	expect(
		entry.conditions.groups[ 0 ]?.rules,
		`${ slug } was emitted carrying its one rule, undecided by the server`
	).toHaveLength( 1 );

	return entry.conditions.groups[ 0 ].rules[ 0 ];
};

/**
 * Which of the named popups a response actually emitted.
 *
 * Both halves of the emission are read and asserted to agree. They are written by
 * different code paths — the config JSON and the inline markup — and a popup
 * present in one and absent from the other is a bug neither a markup assertion
 * nor a config assertion would catch on its own.
 */
const emitted = ( html, candidates ) => {
	const inMarkup = candidates.filter( ( slug ) =>
		html.includes( `data-popkit-slug="${ slug }"` )
	);

	const inConfig = ( configFrom( html )?.popups || [] )
		.map( ( popup ) => popup.slug )
		.filter( ( slug ) => candidates.includes( slug ) );

	expect(
		sorted( inConfig ),
		'the emitted config and the emitted markup name the same popups'
	).toEqual( sorted( inMarkup ) );

	return inMarkup;
};

/** Reads the emitted config out of a loaded page. */
const readConfig = async ( page ) =>
	page.evaluate( () => {
		const element = document.getElementById( 'popkit-config' );

		return element ? JSON.parse( element.textContent ) : null;
	} );

/** The emitted config exactly as it was printed, for a byte comparison. */
const configText = async ( page ) =>
	page.locator( '#popkit-config' ).textContent();

/**
 * Asks the context route what state it sees for this browser's session.
 *
 * Out of band and deliberately not through the page: this is the test
 * establishing what the endpoint thinks, before anything is concluded from what
 * the runtime did with it. `page.request` shares the browser context's cookie
 * jar, so it asks as the same visitor.
 */
const routeState = async ( page ) => {
	const response = await page.request.get( CONTEXT_URL, {
		params: { fields: 'user_state' },
	} );

	expect( response.status(), 'the context route answered' ).toBe( 200 );

	return ( await response.json() )?.user?.state;
};

/**
 * Logs the browser in through the real login form, landing on the host page.
 *
 * A real form login rather than an injected cookie, because the thing under test
 * is whether the context route recognises an ordinary WordPress session — the
 * failure `docs/CLAUDE.md` describes, where `is_user_logged_in()` reports
 * `logged_out` for everybody, is invisible to a fabricated one.
 */
const logIn = async ( page ) => {
	await page.goto(
		`/wp-login.php?redirect_to=${ encodeURIComponent( HOST_PAGE ) }`
	);

	await page.locator( '#user_login' ).fill( ADMIN_USER );
	await page.locator( '#user_pass' ).fill( ADMIN_PASSWORD );
	await page.locator( '#wp-submit' ).click();

	await page.waitForURL( ( url ) => HOST_PAGE === url.pathname );
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
 * Waits long enough for the runtime to have decided, then lets an assertion run.
 *
 * A negative assertion needs a settling point. `window.popkit` is installed during
 * boot, before any popup is considered, so waiting for it and then giving the
 * context lane a beat is the smallest honest wait — an immediate `toHaveCount( 0 )`
 * would pass against a runtime that had not started yet.
 */
const settle = async ( page, extraMs ) => {
	await page.waitForFunction( () => undefined !== window.popkit );
	await page.waitForTimeout( extraMs || 750 );
};

test.describe( 'popkit server conditions', () => {
	/** The authored post, whose ID and category the content rules are built from. */
	let post = null;

	/** ID of the seeded fixture page, which `post_ids` names. */
	let fixturePageId = null;

	/** ID of the category WordPress filed the authored post under. */
	let categoryId = null;

	test.beforeAll( async ( { requestUtils } ) => {
		await deleteExisting( requestUtils, POST_PATH, POST_SLUG );

		post = await requestUtils.rest( {
			method: 'POST',
			path: POST_PATH,
			data: {
				title: 'popkit E2E Conditions Post',
				slug: POST_SLUG,
				status: 'publish',
				content: POST_CONTENT,
			},
		} );

		expect(
			post.slug,
			'the authored post kept the slug its URL is built from'
		).toBe( POST_SLUG );

		/*
		 * Read back rather than assumed. WordPress files a new post under the
		 * site's default category, and reading which one that turned out to be
		 * keeps the taxonomy rule true on a site where the default was renamed or
		 * replaced.
		 */
		categoryId = ( post.categories || [] )[ 0 ];

		expect(
			categoryId,
			'the authored post was filed under a category to target'
		).toBeTruthy();

		const pages = await requestUtils.rest( {
			method: 'GET',
			path: PAGE_PATH,
			params: { slug: 'popkit-e2e', status: 'publish', _fields: 'id' },
		} );

		fixturePageId = ( pages || [] )[ 0 ]?.id;

		expect(
			fixturePageId,
			'the seeded fixture page is present, so post_ids has an ID to name'
		).toBeTruthy();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		if ( post?.id ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `${ POST_PATH }/${ post.id }`,
				params: { force: true },
			} );
		}

		post = null;
	} );

	test( 'a failing server rule removes the popup from the response entirely', async ( {
		request,
		requestUtils,
	} ) => {
		const created = [];

		try {
			created.push(
				await createRuledPopup( requestUtils, 'cond-server-hit', {
					type: 'url_path',
					negate: false,
					values: { match: 'exact', value: FIXTURE_PAGE },
				} )
			);
			created.push(
				await createRuledPopup( requestUtils, 'cond-server-miss', {
					type: 'url_path',
					negate: false,
					values: { match: 'exact', value: CLEAN_PAGE },
				} )
			);

			const tracked = [ 'cond-server-hit', 'cond-server-miss' ];
			const fixture = await fetchPage( request, FIXTURE_PAGE );

			expect( fixture.status ).toBe( 200 );
			expect(
				sorted( emitted( fixture.html, tracked ) ),
				'only the popup whose path rule matched was emitted'
			).toEqual( [ 'cond-server-hit' ] );

			/*
			 * The criterion, stated as strongly as it can be: not "the dialog did
			 * not open", not even "the markup is absent", but that the popup's slug
			 * occurs nowhere in the bytes the visitor received. A client decision
			 * cannot produce this; only a server one can.
			 */
			expect(
				fixture.html.includes( 'cond-server-miss' ),
				'a popup whose server rule failed is absent from the response — markup, config, and all'
			).toBe( false );

			/*
			 * And the rule that decided it is not shipped either. It has already
			 * been applied, and emitting it would leak the author's targeting into
			 * the page source for a client that has nothing left to do with it.
			 */
			const survivor = popupConfig( fixture.html, 'cond-server-hit' );

			expect(
				survivor.conditions.groups,
				'the surviving group is emitted, so the client knows it passed'
			).toHaveLength( 1 );
			expect(
				survivor.conditions.groups[ 0 ].rules,
				'the server rule was stripped once it had been decided'
			).toEqual( [] );

			const clean = await fetchPage( request, CLEAN_PAGE );

			expect( clean.status ).toBe( 200 );
			expect(
				sorted( emitted( clean.html, tracked ) ),
				'and the mirror holds on the other page'
			).toEqual( [ 'cond-server-miss' ] );
			expect(
				clean.html.includes( 'cond-server-hit' ),
				'the popup that matched the fixture page is absent from this one'
			).toBe( false );
		} finally {
			await deletePopups( requestUtils, created );
		}
	} );

	test( 'url_path decides on the normalised path alone, in all four modes', async ( {
		request,
		requestUtils,
	} ) => {
		// Seven popups over REST against a single-threaded server, then two page
		// fetches. Slow, and slow for a reason that is not a hang.
		test.setTimeout( 60000 );

		const created = [];

		try {
			const rules = [
				[ 'cond-url-exact', { match: 'exact', value: FIXTURE_PAGE } ],
				[
					'cond-url-case',
					{ match: 'exact', value: FIXTURE_PAGE.toUpperCase() },
				],
				[
					// The path differs from the request only in its query string,
					// which is not part of the path and therefore never matches.
					'cond-url-query',
					{
						match: 'exact',
						value: `${ FIXTURE_PAGE }?${ UTM_PARAM }=${ UTM_VALUE }`,
					},
				],
				[
					'cond-url-prefix',
					{ match: 'prefix', value: '/popkit-e2e' },
				],
				[
					'cond-url-contains',
					{ match: 'contains', value: 'e2e-clean' },
				],
				[
					'cond-url-glob-star',
					{ match: 'glob', value: '/popkit-e2e-*' },
				],
				[
					// `?` matches exactly one character, so this reaches the
					// fixture page and stops short of the longer one.
					'cond-url-glob-one',
					{ match: 'glob', value: '/popkit-e2?/' },
				],
			];

			for ( const [ slug, values ] of rules ) {
				created.push(
					await createRuledPopup( requestUtils, slug, {
						type: 'url_path',
						negate: false,
						values,
					} )
				);
			}

			const tracked = rules.map( ( [ slug ] ) => slug );

			// Query string and all: the request carries one, and no rule may see it.
			const fixture = await fetchPage(
				request,
				`${ FIXTURE_PAGE }?${ UTM_PARAM }=${ UTM_VALUE }&x=1`
			);

			expect( fixture.status ).toBe( 200 );
			expect(
				sorted( emitted( fixture.html, tracked ) ),
				'exact, case-folded exact, prefix and the single-character glob matched this path'
			).toEqual(
				sorted( [
					'cond-url-exact',
					'cond-url-case',
					'cond-url-prefix',
					'cond-url-glob-one',
				] )
			);

			expect(
				fixture.html.includes( 'cond-url-query' ),
				'a value carrying a query string matches no path, even the one whose query string it copied'
			).toBe( false );

			/*
			 * The seeded fixture's own rule, inert until this phase and now
			 * deciding. `tests/e2e/README.md` -> Fixtures predicted exactly this.
			 */
			expect(
				emitted( fixture.html, [ FIXTURE_SLUG ] ),
				'the seeded fixture narrowed to the page it targets'
			).toEqual( [ FIXTURE_SLUG ] );

			const clean = await fetchPage( request, CLEAN_PAGE );

			expect( clean.status ).toBe( 200 );
			expect(
				sorted( emitted( clean.html, tracked ) ),
				'prefix, contains and the trailing-star glob reach the longer path, and the exact modes do not'
			).toEqual(
				sorted( [
					'cond-url-prefix',
					'cond-url-contains',
					'cond-url-glob-star',
				] )
			);

			expect(
				emitted( clean.html, [ FIXTURE_SLUG ] ),
				'and the seeded fixture is not emitted on a page it does not target'
			).toEqual( [] );
		} finally {
			await deletePopups( requestUtils, created );
		}
	} );

	test( 'the content conditions decide against the queried object', async ( {
		request,
		requestUtils,
	} ) => {
		test.setTimeout( 60000 );

		const created = [];

		try {
			created.push(
				await createRuledPopup( requestUtils, 'cond-post-type', {
					type: 'post_type',
					negate: false,
					values: { types: [ 'post' ] },
				} )
			);
			created.push(
				await createRuledPopup( requestUtils, 'cond-post-ids', {
					type: 'post_ids',
					negate: false,
					values: { ids: [ fixturePageId ] },
				} )
			);
			created.push(
				await createRuledPopup( requestUtils, 'cond-taxonomy', {
					type: 'taxonomy_term',
					negate: false,
					values: { taxonomy: 'category', terms: [ categoryId ] },
				} )
			);
			created.push(
				await createRuledPopup( requestUtils, 'cond-template', {
					type: 'template',
					negate: false,
					values: { templates: [ 'default' ] },
				} )
			);
			created.push(
				await createRuledPopup( requestUtils, 'cond-template-miss', {
					type: 'template',
					negate: false,
					values: { templates: [ 'popkit-no-such-template.php' ] },
				} )
			);

			const tracked = [
				'cond-post-type',
				'cond-post-ids',
				'cond-taxonomy',
				'cond-template',
				'cond-template-miss',
			];

			const fixture = await fetchPage( request, FIXTURE_PAGE );

			expect( fixture.status ).toBe( 200 );
			expect(
				sorted( emitted( fixture.html, tracked ) ),
				'a page matches its own ID and its unassigned template, and neither the post type nor the category of the post'
			).toEqual( sorted( [ 'cond-post-ids', 'cond-template' ] ) );

			const article = await fetchPage( request, POST_PAGE );

			expect( article.status ).toBe( 200 );
			expect(
				sorted( emitted( article.html, tracked ) ),
				'the post matches its type and its category, and is not the page named by ID'
			).toEqual(
				sorted( [ 'cond-post-type', 'cond-taxonomy', 'cond-template' ] )
			);

			expect(
				fixture.html.includes( 'cond-template-miss' ) ||
					article.html.includes( 'cond-template-miss' ),
				'a template no post carries matches nothing'
			).toBe( false );
		} finally {
			await deletePopups( requestUtils, created );
		}
	} );

	test( 'is_front_page and is_404 decide against the request', async ( {
		request,
		requestUtils,
	} ) => {
		const created = [];

		try {
			created.push(
				await createRuledPopup( requestUtils, 'cond-front-page', {
					type: 'is_front_page',
					negate: false,
					values: {},
				} )
			);
			created.push(
				await createRuledPopup( requestUtils, 'cond-not-found', {
					type: 'is_404',
					negate: false,
					values: {},
				} )
			);
			created.push(
				await createRuledPopup( requestUtils, 'cond-any-template', {
					type: 'template',
					negate: false,
					values: { templates: [ 'default' ] },
				} )
			);

			const tracked = [
				'cond-front-page',
				'cond-not-found',
				'cond-any-template',
			];

			const front = await fetchPage( request, '/' );

			expect( front.status ).toBe( 200 );
			expect(
				sorted( emitted( front.html, tracked ) ),
				'the front page is the front page, is not a 404, and queries no post to carry a template'
			).toEqual( [ 'cond-front-page' ] );

			const missing = await fetchPage( request, MISSING_PAGE );

			expect( missing.status, 'the missing path really did 404' ).toBe(
				404
			);
			expect(
				sorted( emitted( missing.html, tracked ) ),
				'a 404 is not the front page and carries no template either'
			).toEqual( [ 'cond-not-found' ] );

			const fixture = await fetchPage( request, FIXTURE_PAGE );

			expect(
				sorted( emitted( fixture.html, tracked ) ),
				'and an ordinary page is neither, but does carry a template'
			).toEqual( [ 'cond-any-template' ] );
		} finally {
			await deletePopups( requestUtils, created );
		}
	} );
} );

test.describe( 'popkit client conditions', () => {
	/** The page every test in this block asserts on. */
	let hostPage = null;

	/** The page the referrer test navigates away from. */
	let sourcePage = null;

	test.beforeAll( async ( { requestUtils } ) => {
		await deleteExisting( requestUtils, PAGE_PATH, HOST_SLUG );
		await deleteExisting( requestUtils, PAGE_PATH, SOURCE_SLUG );

		hostPage = await requestUtils.rest( {
			method: 'POST',
			path: PAGE_PATH,
			data: {
				title: 'popkit E2E Conditions',
				slug: HOST_SLUG,
				status: 'publish',
				content: HOST_PAGE_CONTENT,
			},
		} );

		sourcePage = await requestUtils.rest( {
			method: 'POST',
			path: PAGE_PATH,
			data: {
				title: 'popkit E2E Referrer',
				slug: SOURCE_SLUG,
				status: 'publish',
				content: SOURCE_PAGE_CONTENT,
			},
		} );

		expect(
			[ hostPage.slug, sourcePage.slug ],
			'both authored pages kept the slugs every URL in this block is built from'
		).toEqual( [ HOST_SLUG, SOURCE_SLUG ] );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		for ( const authored of [ hostPage, sourcePage ] ) {
			if ( authored?.id ) {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `${ PAGE_PATH }/${ authored.id }`,
					params: { force: true },
				} );
			}
		}

		hostPage = null;
		sourcePage = null;
	} );

	test.beforeEach( async ( { page } ) => {
		await suppressPopups( page, [ FIXTURE_SLUG ] );
	} );

	test( 'a failing client rule leaves the markup in place and never opens', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			created = await createRuledPopup(
				requestUtils,
				'cond-client-miss',
				{
					type: 'device',
					negate: false,
					values: { max_width: IMPOSSIBLE_MAX_WIDTH },
				}
			);

			await page.goto( HOST_PAGE );
			await settle( page );

			/*
			 * The whole distinction between this test and the server ones above.
			 * The server could not judge a device rule, so it deferred: the markup
			 * and the rule are both on the page, and the browser is what said no.
			 */
			await expect(
				page.locator( rootFor( 'cond-client-miss' ) ),
				'the markup was emitted — a client rule is not a server decision'
			).toHaveCount( 1 );

			const config = await readConfig( page );
			const entry = config.popups.find(
				( popup ) => 'cond-client-miss' === popup.slug
			);
			const rule = entry.conditions.groups[ 0 ].rules[ 0 ];

			expect(
				rule.type,
				'and the rule travelled with it, for the client to decide'
			).toBe( 'device' );

			/*
			 * Reading the type is not enough on its own, and the phase this file
			 * covers is the proof: a `device` rule the server has no registered
			 * condition for is emitted with its type intact and tagged `unknown`,
			 * which stage 7 denies before the type is ever looked up. The popup
			 * would then stay shut for the wrong reason and every assertion below
			 * would still pass. The tag gets its own test next, with both cases in
			 * one response; this line is here because this is the test that reads
			 * an emitted client rule and it must not read only half of it.
			 */
			expect(
				Object.keys( rule ),
				'untagged, so what shut the popup was the width and not an unregistered condition'
			).not.toContain( 'unknown' );

			await expect(
				page.locator( openModalFor( 'cond-client-miss' ) ),
				'no viewport is one CSS pixel wide, so it stayed shut'
			).toHaveCount( 0 );

			expect(
				await page.evaluate( () => undefined !== window.popkit ),
				'the runtime ran to completion — this is a decision, not a crash'
			).toBe( true );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	/**
	 * The registration check, stated against the bytes rather than the outcome.
	 *
	 * Every other client test in this block asserts what the browser did, and each
	 * of them has the same blind spot: "the popup did not open" is equally true of
	 * a rule the client evaluated and refused, and of a rule the client never
	 * reached because the server shipped it tagged `unknown`. A built-in client
	 * condition that PHP forgot to register produces the second, on every popup
	 * targeted by device, login state, referrer, UTM or visit history at once, and
	 * every negative assertion in this file passes exactly as before.
	 *
	 * So the tag is asserted directly, and both ways round in a single response:
	 * a registered type must carry no `unknown` key, an unregistered one must carry
	 * it. Without the second half the first would hold against a server that never
	 * tagged anything at all, which is the mirror-image bug.
	 */
	test( 'a built-in client rule is emitted untagged, and an unregistered one tagged', async ( {
		request,
		requestUtils,
	} ) => {
		const created = [];

		try {
			created.push(
				await createRuledPopup( requestUtils, 'cond-tag-builtin', {
					type: 'device',
					negate: false,
					values: { max_width: IMPOSSIBLE_MAX_WIDTH },
				} )
			);
			created.push(
				await createRuledPopup( requestUtils, 'cond-tag-unknown', {
					type: UNKNOWN_CONDITION,
					negate: false,
					values: {},
				} )
			);

			// One response, so the two halves cannot be describing different
			// registries — the tag is computed per request, from the registry as it
			// stands at emission.
			const host = await fetchPage( request, HOST_PAGE );

			expect( host.status ).toBe( 200 );

			const builtIn = soleRule( host.html, 'cond-tag-builtin' );
			const unregistered = soleRule( host.html, 'cond-tag-unknown' );

			expect(
				builtIn.type,
				'the device rule was deferred to the client rather than decided or dropped'
			).toBe( 'device' );

			expect(
				Object.keys( builtIn ),
				'`device` is a registered built-in condition, so its emitted rule carries no unknown tag'
			).not.toContain( 'unknown' );

			expect(
				Object.keys( unregistered ),
				'and a type nothing registers does carry one, so the absence above is a fact about device'
			).toContain( 'unknown' );
			expect(
				unregistered.unknown,
				'tagged with the value stage 7 denies on'
			).toBe( true );
		} finally {
			await deletePopups( requestUtils, created );
		}
	} );

	test( 'device matches the viewport the project actually runs at', async ( {
		page,
		requestUtils,
	}, testInfo ) => {
		test.setTimeout( 60000 );

		let narrow = null;
		let wide = null;

		try {
			narrow = await createRuledPopup( requestUtils, 'cond-device', {
				type: 'device',
				negate: false,
				values: { max_width: NARROW_MAX_WIDTH },
			} );

			await page.goto( HOST_PAGE );
			await settle( page );

			const matches = await page.evaluate(
				( width ) =>
					window.matchMedia( `(max-width:${ width }px)` ).matches,
				NARROW_MAX_WIDTH
			);

			/*
			 * The cross-check that makes one branch of an if-statement worth
			 * writing. `playwright.config.js` defines a 1280px desktop project and
			 * a Pixel project, so the two runs of this file genuinely disagree
			 * about this media query — and if they ever stop disagreeing, this
			 * fails here rather than quietly asserting the same branch twice.
			 */
			expect(
				matches,
				`the ${ testInfo.project.name } project is on the side of the ${ NARROW_MAX_WIDTH }px breakpoint its name implies`
			).toBe( 'mobile' === testInfo.project.name );

			await expect(
				page.locator( rootFor( 'cond-device' ) ),
				'the markup was emitted either way'
			).toHaveCount( 1 );

			await expect(
				page.locator( openModalFor( 'cond-device' ) ),
				`a max_width of ${ NARROW_MAX_WIDTH } ${
					matches ? 'opened' : 'did not open'
				} on this viewport`
			).toHaveCount( matches ? 1 : 0 );

			/*
			 * The control, taken on its own navigation because two eligible popups
			 * on one page would be a test about arming order. Without it, the
			 * desktop half of the assertion above would also pass against a
			 * `device` condition that never matched anything.
			 */
			await deletePopup( requestUtils, narrow.id );
			narrow = null;

			wide = await createRuledPopup( requestUtils, 'cond-device-wide', {
				type: 'device',
				negate: false,
				values: { max_width: WIDE_MAX_WIDTH },
			} );

			await page.goto( HOST_PAGE );

			await expect(
				page.locator( openModalFor( 'cond-device-wide' ) ),
				'a generous max_width opens on both projects, so the negative above was about the width'
			).toHaveCount( 1 );
		} finally {
			await deletePopup( requestUtils, narrow?.id );
			await deletePopup( requestUtils, wide?.id );
		}
	} );

	test( 'utm opens only for the campaign parameter it names', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			created = await createRuledPopup( requestUtils, 'cond-utm', {
				type: 'utm',
				negate: false,
				values: { param: UTM_PARAM, value: UTM_VALUE },
			} );

			const modal = page.locator( openModalFor( 'cond-utm' ) );

			await page.goto( HOST_PAGE );
			await settle( page );

			await expect(
				page.locator( rootFor( 'cond-utm' ) ),
				'the markup is emitted whatever the query string says'
			).toHaveCount( 1 );
			await expect(
				modal,
				'a visitor who arrived without the campaign tag is not in the campaign'
			).toHaveCount( 0 );

			await page.goto( `${ HOST_PAGE }?${ UTM_PARAM }=${ UTM_VALUE }` );

			await expect(
				modal,
				'the same popup, the same page, and the parameter the author named'
			).toHaveCount( 1 );

			// Documented in `src/frontend/conditions/utm.js`: a campaign tag is an
			// identifier, and folding its case would merge two real campaigns.
			await page.goto(
				`${ HOST_PAGE }?${ UTM_PARAM }=${ UTM_VALUE.toUpperCase() }`
			);
			await settle( page );

			await expect(
				modal,
				'the comparison is exact, so a differently cased value is a different campaign'
			).toHaveCount( 0 );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'referrer matches the page the visitor actually came from', async ( {
		page,
		requestUtils,
	} ) => {
		test.setTimeout( 60000 );

		const created = [];

		try {
			created.push(
				await createRuledPopup( requestUtils, 'cond-referrer-hit', {
					type: 'referrer',
					negate: false,
					values: { match: 'contains', value: SOURCE_PAGE },
				} )
			);
			created.push(
				await createRuledPopup( requestUtils, 'cond-referrer-miss', {
					type: 'referrer',
					negate: false,
					values: {
						match: 'exact',
						value: 'newsletter.example.org/appeal',
					},
				} )
			);

			const hit = page.locator( openModalFor( 'cond-referrer-hit' ) );
			const miss = page.locator( openModalFor( 'cond-referrer-miss' ) );

			// A real navigation, because `document.referrer` is a consequence of
			// how the page was reached and cannot be set from outside.
			await page.goto( SOURCE_PAGE );
			await settle( page );
			await page.getByTestId( LINK_TESTID ).click();
			await page.waitForURL( ( url ) => HOST_PAGE === url.pathname );

			const referrer = await page.evaluate( () => document.referrer );

			/*
			 * Asserted before anything is concluded. Same-origin navigations carry
			 * the full URL under the default referrer policy; if that ever stops
			 * being true here, this fails rather than the two assertions below
			 * passing for the wrong reason.
			 */
			expect(
				referrer,
				'the click produced a referrer naming the page it came from'
			).toContain( SOURCE_PAGE );

			await expect(
				hit,
				'a rule matching the page the visitor came from opened'
			).toHaveCount( 1 );

			await expect(
				page.locator( rootFor( 'cond-referrer-miss' ) ),
				'and the popup targeting a different referrer was still emitted'
			).toHaveCount( 1 );
			await expect(
				miss,
				'but a referrer this visitor does not have opened nothing'
			).toHaveCount( 0 );

			// The same page reached directly. `document.referrer` is empty, which
			// no `contains` value can be found inside.
			await page.goto( HOST_PAGE );
			await settle( page );

			expect(
				await page.evaluate( () => document.referrer ),
				'a typed-in URL carries no referrer'
			).toBe( '' );
			await expect(
				hit,
				'and with no referrer, the rule that matched a moment ago does not'
			).toHaveCount( 0 );
		} finally {
			await deletePopups( requestUtils, created );
		}
	} );

	test( 'user_state opens for the state the visitor is in, on both sides of a login', async ( {
		page,
		requestUtils,
	} ) => {
		test.setTimeout( 90000 );

		const created = [];

		try {
			created.push(
				await createRuledPopup( requestUtils, 'cond-user-out', {
					type: 'user_state',
					negate: false,
					values: { state: 'logged_out' },
				} )
			);
			created.push(
				await createRuledPopup( requestUtils, 'cond-user-in', {
					type: 'user_state',
					negate: false,
					values: { state: 'logged_in' },
				} )
			);

			expect(
				await routeState( page ),
				'the browser starts with no session'
			).toBe( 'logged_out' );

			/*
			 * Attached after that read, not before it. The counter below is a claim
			 * about what the *runtime* asked for, and starting it once the test's
			 * own out-of-band read is done keeps the two from being confused for
			 * one another whatever Playwright decides an API request is.
			 */
			/** @type {string[]} */
			const contextRequests = [];

			page.on( 'request', ( request ) => {
				if ( isContextUrl( request.url() ) ) {
					contextRequests.push( request.url() );
				}
			} );

			await page.goto( HOST_PAGE );

			await expect(
				page.locator( openModalFor( 'cond-user-out' ) ),
				'the logged-out popup opened for a visitor who is logged out'
			).toHaveCount( 1 );

			await settle( page );

			await expect(
				page.locator( rootFor( 'cond-user-in' ) ),
				'the members-only popup was emitted — the server cannot judge it'
			).toHaveCount( 1 );
			await expect(
				page.locator( openModalFor( 'cond-user-in' ) ),
				'and did not open for the public'
			).toHaveCount( 0 );

			const config = await readConfig( page );

			expect(
				config.needsContext,
				'a user_state rule is what sets needsContext'
			).toBe( true );
			expect(
				contextRequests.length,
				'context is fetched exactly once per pageview'
			).toBe( 1 );
			expect(
				new URL( contextRequests[ 0 ] ).searchParams.get( 'fields' ),
				'and asks for the one field these popups need'
			).toBe( 'user_state' );

			const anonymousConfig = await configText( page );

			await logIn( page );

			/*
			 * The endpoint's own answer, checked before the popup's. A route that
			 * reported `logged_out` for a logged-in visitor — the
			 * `rest_cookie_check_errors()` trap in `docs/CLAUDE.md` — would fail
			 * here, where the cause is legible, rather than as a popup that did not
			 * open for a reason nobody can see.
			 */
			expect(
				await routeState( page ),
				'the context route recognises an ordinary WordPress session'
			).toBe( 'logged_in' );

			await expect(
				page.locator( 'body.logged-in' ),
				'and the browser really is carrying that session'
			).toHaveCount( 1 );

			await expect(
				page.locator( openModalFor( 'cond-user-in' ) ),
				'the members-only popup opened for a member'
			).toHaveCount( 1 );

			await settle( page );

			await expect(
				page.locator( openModalFor( 'cond-user-out' ) ),
				'and the logged-out popup stayed shut for one'
			).toHaveCount( 0 );

			/*
			 * Cache safety, from the client's side: two visitors, one URL, two
			 * different sessions, and the same emitted config to the byte. The
			 * whole-document comparison lives in the PHPUnit integration suite,
			 * where core's own `logged-in` body class can be normalised out.
			 */
			expect(
				await configText( page ),
				'the emitted config did not vary by visitor'
			).toBe( anonymousConfig );
		} finally {
			await deletePopups( requestUtils, created );
		}
	} );

	const CONTEXT_FAILURES = [
		{
			name: 'a 500 response',
			handle: ( route ) =>
				route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: '{"code":"internal_server_error"}',
				} ),
		},
		{
			name: 'malformed JSON',
			handle: ( route ) =>
				route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: 'this is not JSON',
				} ),
		},
	];

	for ( const failure of CONTEXT_FAILURES ) {
		test( `${ failure.name } from the context route fails a user_state rule closed`, async ( {
			page,
			requestUtils,
		} ) => {
			test.setTimeout( 60000 );

			let created = null;

			try {
				/*
				 * Targeted at logged-out visitors, which this browser is. Without
				 * the interception it opens — that is the control at the end — so
				 * the only thing suppressing it here is the missing context.
				 */
				created = await createRuledPopup(
					requestUtils,
					'cond-user-failclosed',
					{
						type: 'user_state',
						negate: false,
						values: { state: 'logged_out' },
					}
				);

				await page.route(
					( url ) => isContextUrl( url.href ),
					failure.handle
				);

				await page.goto( HOST_PAGE );
				await settle( page );

				await expect(
					page.locator( rootFor( 'cond-user-failclosed' ) ),
					'the markup was emitted'
				).toHaveCount( 1 );

				const config = await readConfig( page );

				expect(
					config.needsContext,
					'the page did need context, so there was something to fail'
				).toBe( true );

				await expect(
					page.locator( openModalFor( 'cond-user-failclosed' ) ),
					'a rule needing context it could not obtain fails, and its group with it'
				).toHaveCount( 0 );

				expect(
					await page.evaluate( () => undefined !== window.popkit ),
					'the runtime survived the failure'
				).toBe( true );

				// The control: the same popup, the same visitor, the same page,
				// with the route left alone.
				await page.unrouteAll( { behavior: 'ignoreErrors' } );
				await page.reload();

				await expect(
					page.locator( openModalFor( 'cond-user-failclosed' ) ),
					'and it opens once the route answers, so the suppression was the outage'
				).toHaveCount( 1 );
			} finally {
				await page.unrouteAll( { behavior: 'ignoreErrors' } );
				await deletePopup( requestUtils, created?.id );
			}
		} );
	}

	test( 'visit_history separates the first pageview from the second', async ( {
		page,
		requestUtils,
	} ) => {
		const created = [];

		try {
			created.push(
				await createRuledPopup( requestUtils, 'cond-visit-first', {
					type: 'visit_history',
					negate: false,
					values: { state: 'first_time' },
				} )
			);
			created.push(
				await createRuledPopup( requestUtils, 'cond-visit-return', {
					type: 'visit_history',
					negate: false,
					values: { state: 'returning' },
				} )
			);

			const first = page.locator( openModalFor( 'cond-visit-first' ) );
			const returning = page.locator(
				openModalFor( 'cond-visit-return' )
			);

			// A Playwright context starts with empty storage, so this browser has
			// genuinely never been here.
			await page.goto( HOST_PAGE );

			await expect(
				first,
				'a browser with no record greeted as a newcomer'
			).toHaveCount( 1 );

			await settle( page );

			await expect(
				returning,
				'and was not also treated as a returning visitor'
			).toHaveCount( 0 );

			await page.reload();

			await expect(
				returning,
				'the second pageview found the mark the first one wrote'
			).toHaveCount( 1 );

			await settle( page );

			await expect(
				first,
				'and the newcomer popup did not greet them twice'
			).toHaveCount( 0 );
		} finally {
			await deletePopups( requestUtils, created );
		}
	} );

	test( 'negate inverts a decision and never resurrects an unknown rule', async ( {
		page,
		requestUtils,
	} ) => {
		const created = [];

		try {
			/*
			 * A rule nothing can evaluate, negated. `docs/data-model.md` ->
			 * Evaluation semantics: the rule fails closed and `negate` is *not*
			 * applied, because negating an unknown would turn a condition whose
			 * plugin was deactivated into a rule that passes for everybody.
			 */
			created.push(
				await createRuledPopup( requestUtils, 'cond-negate-unknown', {
					type: UNKNOWN_CONDITION,
					negate: true,
					values: {},
				} )
			);

			/*
			 * The control, and the reason the assertion above means anything: a
			 * registered condition that evaluates false, negated, does pass. Both
			 * rules are negated and both evaluate to something other than true;
			 * only one of them produced a decision to invert.
			 */
			created.push(
				await createRuledPopup( requestUtils, 'cond-negate-device', {
					type: 'device',
					negate: true,
					values: { max_width: IMPOSSIBLE_MAX_WIDTH },
				} )
			);

			await page.goto( HOST_PAGE );

			await expect(
				page.locator( openModalFor( 'cond-negate-device' ) ),
				'negating a rule that evaluated false opened the popup'
			).toHaveCount( 1 );

			await settle( page );

			const config = await readConfig( page );
			const entry = config.popups.find(
				( popup ) => 'cond-negate-unknown' === popup.slug
			);

			expect(
				entry.conditions.groups[ 0 ].rules[ 0 ].unknown,
				'the server emitted the unregistered rule tagged as unknown'
			).toBe( true );

			await expect(
				page.locator( rootFor( 'cond-negate-unknown' ) ),
				'and its popup was emitted, so the client is what refused it'
			).toHaveCount( 1 );
			await expect(
				page.locator( openModalFor( 'cond-negate-unknown' ) ),
				'negating a rule nothing could evaluate did not open it'
			).toHaveCount( 0 );
		} finally {
			await deletePopups( requestUtils, created );
		}
	} );
} );
