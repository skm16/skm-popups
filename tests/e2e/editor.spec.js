/**
 * The block editor sidebar.
 *
 * Phase 5's exit criteria, driven through a real editor. Every one of them is
 * about something that cannot be observed from PHP or from a unit test:
 *
 * - the five panels actually mount into the document sidebar
 * - a condition registered in PHP renders a working control with no JavaScript
 *   change, which is the registry invariant's whole claim
 * - a stored rule whose type is unregistered survives a save from the editor
 *   *unchanged*, having been shown read-only rather than given a control
 * - the warnings fire on their condition and clear when it is resolved
 *
 * ## Why the sidebar is opened explicitly
 *
 * `PluginDocumentSettingPanel` renders into the document settings sidebar, and
 * that sidebar is closed by default and its state is a stored user preference.
 * A spec that asserted on the panels without opening it would fail on a fresh
 * profile and pass on the author's own machine — and the first reading of that
 * failure is "the sidebar is broken", which is the wrong place to look. This
 * cost real time to diagnose by hand; the `openDocumentSidebar` helper exists so
 * nobody pays it twice.
 *
 * ## Why the unavailable condition is written over REST
 *
 * The rule has to reach the database through the plugin's own sanitizer, because
 * the claim under test is that *the sanitizer preserves it*. Writing it with SQL
 * would prove the editor displays a value that nothing in the plugin ever
 * accepted.
 *
 * ## This file runs in Firefox, and that is not a preference
 *
 * `playwright.config.js` routes it to the `admin` project. Chromium crashes its
 * renderer navigating to `/wp-admin/` on the development machine — not this
 * editor, not this plugin: a bare `chromium.launch()` and one navigation to the
 * plain dashboard, with popkit uninvolved, crashes identically. The crash
 * survives `trace: 'off'`, `--disable-dev-shm-usage` and `--disable-gpu`.
 *
 * Firefox loads the same instance without complaint, so the admin specs run
 * there and the front-end specs stay on Chromium. Re-test Chromium after a
 * Playwright bump; if it recovers, this file can move back and the `admin`
 * project can go.
 *
 * @see docs/build-plan.md -> Phase 5
 * @see tests/e2e/README.md -> The admin is unreachable in Chromium here
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/** REST collection for the popup post type. */
const POPUP_PATH = '/wp/v2/popkit_popup';

/** A condition type no build of this plugin registers. */
const UNKNOWN_TYPE = 'acme_membership_tier';

/** Stored values for that rule, asserted byte for byte after a save. */
const UNKNOWN_VALUES = { tier: 'gold', lapsed: false, min_days: 30 };

/**
 * Titles of the five panels, in the order index.js mounts them.
 */
const PANEL_TITLES = [
	'Popup targeting',
	'Popup triggers',
	'Popup schedule',
	'Popup frequency',
	'Popup appearance',
];

/**
 * Ids created by this file, deleted in `afterEach`.
 *
 * Every popup made here has to be removed again, and not for tidiness.
 * `tests/e2e/README.md` records that a single abandoned popup changes the
 * meaning of every "exactly N popups" assertion in the suite — a popup carrying
 * no *server* rule survives server-side matching on every URL, so it is emitted
 * on pages no spec pointed it at. That is exactly what these fixtures are: most
 * carry an empty rule set.
 *
 * This file learned that the hard way. An early revision created popups and
 * never removed them, and the next full front-end run produced five failures in
 * four other spec files — none of which had changed, and none of which had
 * anything to do with the editor.
 *
 * @type {number[]}
 */
const created = [];

/**
 * Creates a popup and returns its id.
 *
 * Drafts by default: an unpublished popup is not emitted to visitors at all, so
 * a fixture that outlives its test by a moment cannot reach the front end.
 *
 * @param {Object} requestUtils Playwright fixture.
 * @param {Object} overrides    Fields merged into the request body.
 * @return {Promise<number>} New popup id.
 */
const createPopup = async ( requestUtils, overrides = {} ) => {
	const popup = await requestUtils.rest( {
		method: 'POST',
		path: POPUP_PATH,
		data: {
			title: 'Editor spec popup',
			status: 'draft',
			content:
				'<!-- wp:heading --><h2>Join the list</h2><!-- /wp:heading -->',
			...overrides,
		},
	} );

	created.push( popup.id );

	return popup.id;
};

/**
 * Deletes every popup this file created, permanently.
 *
 * `force: true` skips the trash. A trashed popup is still a row, still carries
 * its meta, and still shows up in the harness inventory as something a later run
 * has to reason about.
 *
 * @param {Object} requestUtils Playwright fixture.
 * @return {Promise<void>}
 */
const deleteCreated = async ( requestUtils ) => {
	while ( created.length ) {
		const id = created.pop();

		try {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `${ POPUP_PATH }/${ id }`,
				params: { force: true },
			} );
		} catch {
			// A popup a test already deleted is not a failure worth reporting,
			// and throwing here would mask the assertion that actually failed.
			// Optional catch binding, so there is no unused variable to explain.
		}
	}
};

/**
 * Opens the document settings sidebar, whatever the stored preference says.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<void>}
 */
const openDocumentSidebar = async ( page ) => {
	await page.evaluate( () => {
		window.wp.data
			.dispatch( 'core/interface' )
			.enableComplementaryArea( 'core/edit-post', 'edit-post/document' );
	} );

	await expect(
		page.locator( '.interface-complementary-area' )
	).toBeVisible();
};

/**
 * Expands every popkit panel so its contents are queryable.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<void>}
 */
const expandPopkitPanels = async ( page ) => {
	await expect( page.locator( '.popkit-panel' ).first() ).toBeAttached();

	await page.evaluate( ( titles ) => {
		for ( const button of document.querySelectorAll(
			'.components-panel__body-toggle'
		) ) {
			if (
				titles.includes( button.textContent.trim() ) &&
				'false' === button.getAttribute( 'aria-expanded' )
			) {
				button.click();
			}
		}
	}, PANEL_TITLES );
};

/**
 * Opens a popup in the editor with its sidebar open and panels expanded.
 *
 * @param {Object} admin Playwright fixture.
 * @param {Object} page  Playwright page.
 * @param {number} id    Popup id.
 * @return {Promise<void>}
 */
const openPopupEditor = async ( admin, page, id ) => {
	await admin.editPost( id );
	await openDocumentSidebar( page );
	await expandPopkitPanels( page );
};

/*
 * These specs drive the admin, so the browser needs a logged-in session.
 * `playwright.config.js` deliberately sets no config-level `storageState` — a
 * path that does not exist yet fails every spec in the suite before it runs, and
 * most of this suite is anonymous visitor traffic that must not carry a cookie.
 * Declaring it here scopes the session to the one file that needs it, and
 * `beforeAll` writes the file first so the first context can read it.
 */
test.use( { storageState: process.env.STORAGE_STATE_PATH } );

test.describe( 'popup editor sidebar', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.setupRest();
	} );

	/*
	 * After each test rather than after the file, so a failure part-way through
	 * does not leave the rest of the run to inherit its fixtures.
	 */
	test.afterEach( async ( { requestUtils } ) => {
		await deleteCreated( requestUtils );
	} );

	test( 'mounts all five panels on the popup post type', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const id = await createPopup( requestUtils );

		await openPopupEditor( admin, page, id );

		for ( const title of PANEL_TITLES ) {
			await expect(
				page.getByRole( 'button', { name: title, exact: true } )
			).toBeVisible();
		}

		expect( await page.locator( '.popkit-panel' ).count() ).toBe( 5 );
	} );

	/*
	 * The registry invariant, asserted where it is actually observable. The
	 * condition names below are declared in PHP and reach the browser over
	 * `GET /popkit/v1/registry`; nothing in the bundle mentions any of them. A
	 * regression that hardcoded a list would still pass a PHP test of the
	 * registry and fail here.
	 */
	test( 'renders the condition vocabulary from the REST registry', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const id = await createPopup( requestUtils );

		await openPopupEditor( admin, page, id );

		await page.getByRole( 'button', { name: 'Add group' } ).click();

		const conditionSelect = page
			.locator( '.popkit-conditions select' )
			.first();

		const options = await conditionSelect
			.locator( 'option' )
			.allTextContents();

		// Two server conditions and two client ones, so a regression that lost
		// either context is caught rather than half-caught.
		expect( options ).toEqual(
			expect.arrayContaining( [
				'Post type',
				'Front page',
				'Device width',
				'Login state',
			] )
		);
	} );

	/*
	 * Selecting a condition must render that condition's own fields, from its
	 * own schema. `url_path` is chosen because its two fields use two different
	 * controls — a `select` built from an enum and a `url-match` text field — so
	 * a control map that resolved only one kind would fail.
	 */
	test( 'renders a chosen condition’s fields from its schema', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const id = await createPopup( requestUtils, {
			meta: {
				_popkit_conditions: {
					groups: [
						{
							rules: [
								{
									type: 'url_path',
									negate: false,
									values: {
										match: 'prefix',
										value: '/campaigns/',
									},
								},
							],
						},
					],
				},
			},
		} );

		await openPopupEditor( admin, page, id );

		await expect(
			page.getByLabel( 'How the path is compared' )
		).toHaveValue( 'prefix' );

		await expect(
			page.locator( '.popkit-conditions input[type="text"]' ).first()
		).toHaveValue( '/campaigns/' );
	} );

	test( 'shows an unregistered rule read-only and preserves it through a save', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const id = await createPopup( requestUtils, {
			meta: {
				_popkit_conditions: {
					groups: [
						{
							rules: [
								{
									type: UNKNOWN_TYPE,
									negate: false,
									values: UNKNOWN_VALUES,
								},
							],
						},
					],
				},
			},
		} );

		await openPopupEditor( admin, page, id );

		const unavailable = page.locator( '.popkit-rule--unavailable' );

		await expect( unavailable ).toBeVisible();

		// The stored values are shown exactly as stored.
		const raw = await unavailable.locator( 'pre' ).textContent();
		expect( JSON.parse( raw ) ).toEqual( UNKNOWN_VALUES );

		// And there is no control that could overwrite them. Only the remove
		// button, which is a deliberate act rather than a side effect of saving.
		expect(
			await unavailable.locator( 'input, select, textarea' ).count()
		).toBe( 0 );

		// Change something unrelated, save, and read the rule back off the server.
		await page.evaluate( async () => {
			const meta = window.wp.data
				.select( 'core/editor' )
				.getEditedPostAttribute( 'meta' );

			window.wp.data.dispatch( 'core/editor' ).editPost( {
				meta: {
					...meta,
					_popkit_frequency: {
						...meta._popkit_frequency,
						mode: 'once_per_session',
					},
				},
			} );

			await window.wp.data.dispatch( 'core/editor' ).savePost();
		} );

		const saved = await requestUtils.rest( {
			method: 'GET',
			path: `${ POPUP_PATH }/${ id }`,
		} );

		const rule = saved.meta._popkit_conditions.groups[ 0 ].rules[ 0 ];

		expect( rule.type ).toBe( UNKNOWN_TYPE );
		expect( rule.values ).toEqual( UNKNOWN_VALUES );
		expect( saved.meta._popkit_frequency.mode ).toBe( 'once_per_session' );
	} );

	/*
	 * The accessible-name warning covers a case the server cannot see. The
	 * heading block below is present and empty, so `Renderer::accessible_name()`
	 * finds a heading tag, points `aria-labelledby` at it, and produces an empty
	 * name. Only the editor holds the block tree and can tell the difference.
	 */
	test( 'warns about an empty heading with no title, and clears when fixed', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const id = await createPopup( requestUtils, {
			title: '',
			content: '<!-- wp:heading --><h2></h2><!-- /wp:heading -->',
		} );

		await openPopupEditor( admin, page, id );

		const warning = page.locator(
			'.popkit-warning--missing-accessible-name'
		);

		await expect( warning ).toBeVisible();

		// Giving the popup a title is one of the two documented fixes.
		await page.evaluate( () => {
			window.wp.data
				.dispatch( 'core/editor' )
				.editPost( { title: 'Newsletter promo' } );
		} );

		await expect( warning ).toBeHidden();
	} );

	test( 'warns about a schedule that has already ended, and clears when disabled', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const id = await createPopup( requestUtils, {
			meta: {
				_popkit_schedule: {
					enabled: true,
					timezone: 'site',
					start: '2020-01-01T00:00:00Z',
					end: '2020-02-01T00:00:00Z',
					recurrence: { days: [], windows: [] },
				},
			},
		} );

		await openPopupEditor( admin, page, id );

		const warning = page.locator( '.popkit-warning--schedule-expired' );

		await expect( warning ).toBeVisible();

		await page
			.getByLabel( 'Limit when this popup runs' )
			.click( { force: true } );

		await expect( warning ).toBeHidden();
	} );

	test( 'warns about a group holding a rule and its own negation', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const id = await createPopup( requestUtils, {
			meta: {
				_popkit_conditions: {
					groups: [
						{
							rules: [
								{
									type: 'is_front_page',
									negate: false,
									values: {},
								},
								{
									type: 'is_front_page',
									negate: true,
									values: {},
								},
							],
						},
					],
				},
			},
		} );

		await openPopupEditor( admin, page, id );

		await expect(
			page.locator( '.popkit-warning--impossible-conditions' )
		).toBeVisible();
	} );

	test( 'shows no warnings on a well-formed popup', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const id = await createPopup( requestUtils, {
			title: 'Newsletter promo',
			meta: {
				_popkit_conditions: {
					groups: [
						{
							rules: [
								{
									type: 'url_path',
									negate: false,
									values: {
										match: 'prefix',
										value: '/campaigns/',
									},
								},
							],
						},
					],
				},
			},
		} );

		await openPopupEditor( admin, page, id );

		expect( await page.locator( '.popkit-warning' ).count() ).toBe( 0 );
	} );
} );
