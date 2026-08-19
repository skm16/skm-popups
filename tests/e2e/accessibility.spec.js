/**
 * Accessibility assertions for the popkit runtime.
 *
 * Every test in this file is an exit criterion in `docs/build-plan.md` -> Phase 2
 * and an invariant in `docs/CLAUDE.md` -> Accessibility. None of them is a polish
 * item: accessibility is the product differentiator this plugin is positioned on,
 * so a failure here is a release blocker rather than a bug report.
 *
 * ## Why these specs author their own popup
 *
 * The seeded `e2e-modal` fixture is emitted on every page and cannot open, and
 * that is correct rather than broken. Its stored `url_path` rule belongs to a
 * condition nothing registers until Phase 4, so the sanitizer marked it
 * `"unknown": true`; the server treats an unknown rule as *indeterminate* and
 * emits the popup, and the client then fails that rule **closed** — a rule whose
 * evaluator is absent evaluates false and its group fails, without `negate` being
 * applied. `tests/e2e/README.md` describes the server half of this and is silent
 * on the client half, which is the half that decides whether anything appears.
 *
 * So the subject of these tests is a popup authored here, through
 * `requestUtils`, with an empty rule set — `{ "groups": [] }`, which
 * `docs/data-model.md` defines as "always match". It resolves identically in
 * Phase 2 and after Phase 4 registers the built-in conditions, so these
 * assertions do not quietly change meaning under a later phase.
 *
 * The fixture *page* is still used, for its background content: a trigger
 * button, a background link and a second button are what focus return and the
 * background-inert assertions need.
 *
 * ## Why the seeded popup is suppressed anyway
 *
 * Phase 2's stage 10 is a stub — there are no triggers yet, so every eligible
 * popup opens on load and `openRecord()` refuses an automatic open while anything
 * else is on screen. `e2e-modal` has the lowest post ID and is considered first.
 * It cannot win that race today, but it will the moment Phase 4 registers
 * `url_path` and its rule starts passing on `/popkit-e2e/`. Canceling its
 * `popkit:before-open` from an init script settles the question now, using the
 * plugin's own public veto, and changes nothing about what the server emitted.
 *
 * Selectors come from the `data-popkit-*` DOM contract documented on
 * `Popkit\Renderer`, never from CSS structure. `data-popkit-close` in particular
 * is the runtime's own hook for the close button and survives a theme rewriting
 * the class list.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/** Fixture page: background content, a trigger button, a background link. */
const FIXTURE_PAGE = '/popkit-e2e/';

/** Slug of the seeded fixture popup, which is emitted everywhere. */
const FIXTURE_SLUG = 'e2e-modal';

/** The modal these specs author and assert against. */
const SPEC_SLUG = 'a11y-modal';

/** Any popup root on the page, whoever authored it. */
const ANY_ROOT = '[data-popkit-id]';

/** Any modal that is on screen right now. `showModal()` sets the attribute. */
const ANY_OPEN_MODAL = 'dialog[data-popkit-id][open]';

/** The close button, matched by the runtime's own hook rather than by class. */
const CLOSE_BUTTON = '[data-popkit-close]';

/**
 * Everything the browser puts in the tab order inside a popup.
 *
 * `tabindex="-1"` is excluded, which is what keeps the popup root itself — which
 * carries exactly that — out of the cycle the containment test walks.
 */
const FOCUSABLE = [
	'a[href]',
	'button:not([disabled])',
	'input:not([disabled])',
	'select:not([disabled])',
	'textarea:not([disabled])',
	'[tabindex]:not([tabindex="-1"])',
].join( ',' );

/** The four themes `docs/data-model.md` -> Display declares. */
const THEMES = [ 'inherit', 'light', 'dark', 'bordered' ];

/** WCAG 2.2 target size, in CSS pixels. */
const MIN_TARGET = 44;

/** REST collection for the popup post type. */
const POPUP_PATH = '/wp/v2/popkit_popup';

/**
 * Content the authored popup carries.
 *
 * Deliberately shaped for these assertions: a heading for `aria-labelledby` to
 * resolve to, several focusable elements for the containment walk, and — the
 * part that makes one test mean anything — an `<input>` inside the content.
 * `showModal()`'s own default is to focus the first focusable descendant, so a
 * runtime that did not override it would eventually land on a form field, which
 * is precisely what the constitution forbids.
 */
const POPUP_CONTENT = [
	'<!-- wp:heading --><h2 class="wp-block-heading">Authored fixture popup</h2><!-- /wp:heading -->',
	'<!-- wp:paragraph --><p><a href="#one" data-testid="popkit-inside-one">First link inside</a> and <a href="#two" data-testid="popkit-inside-two">second link inside</a>.</p><!-- /wp:paragraph -->',
	'<!-- wp:html --><p><label for="popkit-spec-email">Email</label> <input id="popkit-spec-email" data-testid="popkit-spec-email" type="email" /></p><!-- /wp:html -->',
	'<!-- wp:html --><p><button type="button" data-testid="popkit-spec-convert">Convert</button></p><!-- /wp:html -->',
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
 * Cancels the automatic open of named popups, before any plugin script runs.
 *
 * Uses `popkit:before-open`, which is public API and cancelable by design. A
 * canceled open shows nothing and writes no seen record.
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
 * Counts listeners per element, so a leak across open/close cycles is visible.
 *
 * `dialog.js` deliberately uses neither `{ once: true }` nor an `AbortSignal`,
 * precisely so that every listener it adds has a matching `removeEventListener`
 * call this instrumentation can see.
 */
const countListeners = async ( page ) => {
	await page.addInitScript( () => {
		const counts = new WeakMap();
		const add = EventTarget.prototype.addEventListener;
		const remove = EventTarget.prototype.removeEventListener;

		EventTarget.prototype.addEventListener = function ( ...args ) {
			counts.set( this, ( counts.get( this ) || 0 ) + 1 );

			return add.apply( this, args );
		};

		EventTarget.prototype.removeEventListener = function ( ...args ) {
			counts.set( this, ( counts.get( this ) || 0 ) - 1 );

			return remove.apply( this, args );
		};

		window.popkitListenerCount = ( element ) =>
			element ? counts.get( element ) || 0 : 0;

		/*
		 * A close is only finished once `popkit:close` has fired. `dialog.js`
		 * runs its teardown, restores focus and *then* calls `onClose`, and the
		 * native `close` event that starts all three is a queued task rather
		 * than a synchronous one — so the `open` attribute is gone, and focus is
		 * already back on the trigger courtesy of the browser, several
		 * milliseconds before the listeners are actually removed. Counting
		 * before this fires reads a teardown that has not happened yet and
		 * reports a leak that does not exist.
		 */
		window.popkitCloseCount = 0;

		document.addEventListener( 'popkit:close', () => {
			window.popkitCloseCount += 1;
		} );
	} );
};

test.describe( 'popkit accessibility', () => {
	/** The authored popup, shared by every test and never mutated by one. */
	let subject = null;

	test.beforeAll( async ( { requestUtils } ) => {
		subject = await createPopup( requestUtils, SPEC_SLUG, {
			_popkit_display: { layout: 'modal', animation: 'fade' },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await deletePopup( requestUtils, subject?.id );
		subject = null;
	} );

	test.beforeEach( async ( { page } ) => {
		await suppressPopups( page, [ FIXTURE_SLUG ] );
	} );

	test( 'opens, Escape closes, and focus returns to the element it opened from', async ( {
		page,
	} ) => {
		await page.goto( FIXTURE_PAGE );

		const modal = page.locator( openModalFor( SPEC_SLUG ) );

		// Phase 2 has no triggers, so an eligible popup opens on load.
		await expect( modal, 'the popup opened' ).toHaveCount( 1 );

		// Escape is native `<dialog>` behavior and is never preventDefault()ed.
		await page.keyboard.press( 'Escape' );
		await expect( modal, 'Escape closed it' ).toHaveCount( 0 );

		/*
		 * The automatic open had no triggering element, so focus returned to
		 * where it was before. Re-open it from a real control to assert the other
		 * half of the rule.
		 */
		const trigger = page.getByTestId( 'popkit-e2e-trigger' );

		await trigger.focus();
		await page.evaluate(
			( slug ) => window.popkit.open( slug ),
			SPEC_SLUG
		);
		await expect( modal, 'it reopened' ).toHaveCount( 1 );

		await page.keyboard.press( 'Escape' );
		await expect( modal, 'Escape closed it again' ).toHaveCount( 0 );

		await expect(
			trigger,
			'focus returned to the element that opened it'
		).toBeFocused();
	} );

	test( 'initial focus lands on the dialog or its heading, never a form field', async ( {
		page,
	} ) => {
		await page.goto( FIXTURE_PAGE );
		await expect( page.locator( openModalFor( SPEC_SLUG ) ) ).toHaveCount(
			1
		);

		const focus = await page.evaluate( ( selector ) => {
			const dialog = document.querySelector( selector );
			const active = dialog.ownerDocument.activeElement;

			return {
				isDialog: active === dialog,
				isHeading:
					dialog.contains( active ) &&
					[ 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' ].includes(
						active.tagName
					),
				tagName: active.tagName,
				inside: dialog.contains( active ) || active === dialog,
				// The popup content carries one, and focus must not be on it.
				hasFormField: null !== dialog.querySelector( 'input' ),
			};
		}, openModalFor( SPEC_SLUG ) );

		expect(
			focus.hasFormField,
			'the popup really does contain a form field to get this wrong with'
		).toBe( true );
		expect( focus.inside, 'focus is inside the dialog' ).toBe( true );
		expect(
			focus.isDialog || focus.isHeading,
			`focus is the dialog or its heading, not ${ focus.tagName }`
		).toBe( true );

		/*
		 * Stated separately as well as implied above, because this is the rule
		 * with a name: an autofocused input drops a screen reader user inside a
		 * control before they have heard what the popup is for, and drops a
		 * mobile user behind a keyboard that covers it.
		 */
		expect(
			[ 'INPUT', 'TEXTAREA', 'SELECT', 'BUTTON' ],
			'focus is not on a form field or a button'
		).not.toContain( focus.tagName );
	} );

	test( 'aria-labelledby resolves to a real element carrying text', async ( {
		page,
	} ) => {
		await page.goto( FIXTURE_PAGE );

		await expect( page.locator( openModalFor( SPEC_SLUG ) ) ).toHaveCount(
			1
		);

		const label = await page.evaluate( ( selector ) => {
			const dialog = document.querySelector( selector );
			const id = dialog.getAttribute( 'aria-labelledby' );
			const target = id ? document.getElementById( id ) : null;

			return {
				id,
				found: null !== target,
				inside: null !== target && dialog.contains( target ),
				text: target ? target.textContent.trim() : '',
				ariaLabel: dialog.getAttribute( 'aria-label' ),
			};
		}, openModalFor( SPEC_SLUG ) );

		expect( label.id, 'aria-labelledby is present' ).toBeTruthy();
		expect( label.found, 'it points at an element that exists' ).toBe(
			true
		);
		expect( label.inside, 'that element is inside the popup' ).toBe( true );
		expect(
			label.text.length,
			'and that element has text'
		).toBeGreaterThan( 0 );

		// Exactly one naming mechanism, never both — two names is a name nobody
		// can predict the announcement of.
		expect(
			label.ariaLabel,
			'aria-label is absent when labelledby is used'
		).toBeNull();

		// And the accessibility tree agrees with the attribute.
		await expect(
			page.getByRole( 'dialog', { name: label.text } )
		).toHaveCount( 1 );
	} );

	test( 'focus is contained: Tab wraps from the last focusable to the first', async ( {
		page,
	} ) => {
		await page.goto( FIXTURE_PAGE );
		await expect( page.locator( openModalFor( SPEC_SLUG ) ) ).toHaveCount(
			1
		);

		const total = await page.evaluate(
			( { modal, focusable } ) =>
				document.querySelector( modal ).querySelectorAll( focusable )
					.length,
			{ modal: openModalFor( SPEC_SLUG ), focusable: FOCUSABLE }
		);

		expect(
			total,
			'the popup has several focusable elements to cycle through'
		).toBeGreaterThan( 1 );

		/**
		 * Focuses the first or last focusable element inside the popup.
		 */
		const focusEdge = async ( edge ) =>
			page.evaluate(
				( { modal, focusable, which } ) => {
					const items = document
						.querySelector( modal )
						.querySelectorAll( focusable );

					items[ 'first' === which ? 0 : items.length - 1 ].focus();
				},
				{
					modal: openModalFor( SPEC_SLUG ),
					focusable: FOCUSABLE,
					which: edge,
				}
			);

		/** Where focus is now, relative to the popup and to the page behind it. */
		const where = async () =>
			page.evaluate(
				( { modal, focusable } ) => {
					const dialog = document.querySelector( modal );
					const items = dialog.querySelectorAll( focusable );
					const doc = dialog.ownerDocument;
					const active = doc.activeElement;

					return {
						inside: dialog.contains( active ),
						atFirst: active === items[ 0 ],
						atLast: active === items[ items.length - 1 ],
						// Chromium parks focus on the document between the last
						// element of a modal and the first — focus has left the
						// page rather than entered the content behind it.
						onDocument: null === active || active === doc.body,
						label: active ? active.tagName : 'null',
					};
				},
				{ modal: openModalFor( SPEC_SLUG ), focusable: FOCUSABLE }
			);

		/**
		 * Presses a key until focus reaches an edge, reporting every step.
		 *
		 * Two presses are allowed rather than one because a modal's tab cycle is
		 * the browser's, not popkit's, and Chromium hands focus to the browser
		 * itself on the way round: from the last element of the dialog, one Tab
		 * leaves the page entirely and a second returns to the first element.
		 * Insisting on a single press would be asserting a Chromium
		 * implementation detail; what the constitution requires is that the cycle
		 * closes and that it never passes through the page behind the modal.
		 */
		const cycleTo = async ( key, reached ) => {
			const steps = [];

			for ( let press = 0; press < 3; press++ ) {
				await page.keyboard.press( key );

				const state = await where();

				steps.push( state );

				if ( reached( state ) ) {
					return steps;
				}
			}

			return steps;
		};

		// Forwards, from the last focusable.
		await focusEdge( 'last' );

		const forwards = await cycleTo( 'Tab', ( state ) => state.atFirst );

		for ( const step of forwards ) {
			expect(
				step.inside || step.onDocument,
				`Tab never reached the page behind the modal, landed on ${ step.label }`
			).toBe( true );
		}

		expect(
			forwards[ forwards.length - 1 ].atFirst,
			'Tab from the last focusable came back to the first'
		).toBe( true );

		// Backwards, from the first focusable.
		await focusEdge( 'first' );

		const backwards = await cycleTo(
			'Shift+Tab',
			( state ) => state.atLast
		);

		for ( const step of backwards ) {
			expect(
				step.inside || step.onDocument,
				`Shift+Tab never reached the page behind the modal, landed on ${ step.label }`
			).toBe( true );
		}

		expect(
			backwards[ backwards.length - 1 ].atLast,
			'Shift+Tab from the first focusable wrapped to the last'
		).toBe( true );
	} );

	test( 'background content is inert while a modal is open', async ( {
		page,
	} ) => {
		await page.goto( FIXTURE_PAGE );
		await expect( page.locator( openModalFor( SPEC_SLUG ) ) ).toHaveCount(
			1
		);

		const attempts = await page.evaluate( ( selector ) => {
			const dialog = document.querySelector( selector );
			const results = [];

			for ( const id of [
				'popkit-e2e-trigger',
				'popkit-e2e-background-link',
				'popkit-e2e-second-trigger',
			] ) {
				const node = document.getElementById( id );

				node.focus();

				const active = dialog.ownerDocument.activeElement;

				results.push( {
					id,
					tookFocus: active === node,
					stillInside: dialog.contains( active ),
				} );
			}

			return results;
		}, openModalFor( SPEC_SLUG ) );

		for ( const attempt of attempts ) {
			expect(
				attempt.tookFocus,
				`${ attempt.id } refused focus while the modal was open`
			).toBe( false );
			expect(
				attempt.stillInside,
				`focus stayed inside the modal after touching ${ attempt.id }`
			).toBe( true );
		}
	} );

	test( 'ten open/close cycles leak no listeners and restore focus each time', async ( {
		page,
	} ) => {
		await countListeners( page );
		await page.goto( FIXTURE_PAGE );

		const modal = page.locator( openModalFor( SPEC_SLUG ) );

		/** How many closes the runtime has finished announcing. */
		let closes = 0;

		/** Blocks until `popkit:close` has fired for every close so far. */
		const closed = async () => {
			closes += 1;

			await page.waitForFunction(
				( count ) => window.popkitCloseCount >= count,
				closes
			);
		};

		await expect( modal ).toHaveCount( 1 );
		await page.keyboard.press( 'Escape' );
		await expect( modal ).toHaveCount( 0 );
		await closed();

		const trigger = page.getByTestId( 'popkit-e2e-trigger' );

		/**
		 * Runs one open/close cycle from a real trigger and reports the popup's
		 * net listener count once it has closed again.
		 */
		const cycle = async () => {
			await trigger.focus();
			await page.evaluate(
				( slug ) => window.popkit.open( slug ),
				SPEC_SLUG
			);
			await expect( modal ).toHaveCount( 1 );

			await page.keyboard.press( 'Escape' );
			await expect( modal ).toHaveCount( 0 );
			await closed();
			await expect( trigger ).toBeFocused();

			return page.evaluate(
				( selector ) =>
					window.popkitListenerCount(
						document.querySelector( selector )
					),
				rootFor( SPEC_SLUG )
			);
		};

		/*
		 * The baseline is taken after a full cycle rather than before one. The
		 * controller binds a `popkit:convert` listener on the popup's first open
		 * and deliberately never removes it — it is bound once for the life of
		 * the pageview — so counting from zero would report that single
		 * intentional listener as a leak on every subsequent cycle.
		 */
		const baseline = await cycle();

		for ( let index = 0; index < 10; index++ ) {
			// The cycles are sequential by definition; running them
			// concurrently would not be ten open/close cycles.
			const count = await cycle();

			expect(
				count,
				`cycle ${ index + 1 } left the listener count unchanged`
			).toBe( baseline );
		}
	} );

	test( 'removing the dialog from the document while open restores focus', async ( {
		page,
	} ) => {
		await page.goto( FIXTURE_PAGE );
		await expect( page.locator( openModalFor( SPEC_SLUG ) ) ).toHaveCount(
			1
		);

		// The automatic open had no trigger, so there is nothing to return to
		// and the document body is the correct landing place.
		await page.evaluate( ( selector ) => {
			document.querySelector( selector ).remove();
		}, rootFor( SPEC_SLUG ) );

		await expect( page.locator( rootFor( SPEC_SLUG ) ) ).toHaveCount( 0 );

		await page.waitForFunction(
			() =>
				window.document.activeElement === window.document.body ||
				null === window.document.activeElement
		);

		const focus = await page.evaluate( () => ( {
			isBody: window.document.activeElement === window.document.body,
			connected:
				null !== window.document.activeElement &&
				window.document.activeElement.isConnected,
		} ) );

		expect( focus.isBody, 'focus landed on the document body' ).toBe(
			true
		);
		expect(
			focus.connected,
			'focus is not stranded on a detached node'
		).toBe( true );
	} );

	test( 'two eligible popups never produce two open modals', async ( {
		page,
		requestUtils,
	} ) => {
		let second = null;

		try {
			second = await createPopup( requestUtils, 'a11y-second-modal', {
				_popkit_display: { layout: 'modal' },
			} );

			await page.goto( FIXTURE_PAGE );

			// Three popups are emitted — the seeded fixture and both of these —
			// and only one may ever be on screen.
			await expect( page.locator( ANY_ROOT ) ).toHaveCount( 3 );
			await expect(
				page.locator( ANY_OPEN_MODAL ),
				'exactly one modal opened automatically'
			).toHaveCount( 1 );
			await expect(
				page.locator( ANY_OPEN_MODAL ),
				'and it is the first eligible one'
			).toHaveAttribute( 'data-popkit-slug', SPEC_SLUG );

			/*
			 * A programmatic open is an explicit author action and is not
			 * skipped — but it supersedes rather than stacks, so the count holds.
			 */
			await page.evaluate( () =>
				window.popkit.open( 'a11y-second-modal' )
			);

			await expect(
				page.locator( ANY_OPEN_MODAL ),
				'still exactly one modal after a programmatic open'
			).toHaveCount( 1 );
			await expect( page.locator( ANY_OPEN_MODAL ) ).toHaveAttribute(
				'data-popkit-slug',
				'a11y-second-modal'
			);
		} finally {
			await deletePopup( requestUtils, second?.id );
		}
	} );

	test( 'the close button has an accessible name and a 44x44 hit area in every theme', async ( {
		page,
	} ) => {
		await page.goto( FIXTURE_PAGE );

		const modal = page.locator( openModalFor( SPEC_SLUG ) );

		await expect( modal ).toHaveCount( 1 );

		const close = modal.locator( CLOSE_BUTTON );

		await expect( close, 'there is exactly one close button' ).toHaveCount(
			1
		);
		await expect( close, 'it is a real button element' ).toHaveJSProperty(
			'tagName',
			'BUTTON'
		);

		// Named by its own text, reachable by role, and announced.
		await expect(
			modal.getByRole( 'button', { name: 'Close' } ),
			'the close button exposes an accessible name'
		).toHaveCount( 1 );

		const name = await page.evaluate(
			( { modalSelector, buttonSelector } ) => {
				const button = document
					.querySelector( modalSelector )
					.querySelector( buttonSelector );
				const text = button.querySelector(
					'.popkit-popup__close-text'
				);
				const styles = text ? window.getComputedStyle( text ) : null;

				return {
					text: button.textContent.trim(),
					display: styles ? styles.display : '',
					visibility: styles ? styles.visibility : '',
				};
			},
			{
				modalSelector: openModalFor( SPEC_SLUG ),
				buttonSelector: CLOSE_BUTTON,
			}
		);

		expect( name.text.length, 'its name is not empty' ).toBeGreaterThan(
			0
		);

		/*
		 * The label may be clipped the way `.screen-reader-text` is, but never
		 * removed from the accessibility tree — either of these would leave the
		 * button with no name at all.
		 */
		expect( name.display, 'the label is not display:none' ).not.toBe(
			'none'
		);
		expect(
			name.visibility,
			'the label is not visibility:hidden'
		).not.toBe( 'hidden' );

		for ( const theme of THEMES ) {
			// One theme is applied at a time and each is measured after its own
			// swap; there is nothing here to run concurrently.
			await page.evaluate(
				( { selector, themes, active } ) => {
					const dialog = document.querySelector( selector );

					themes.forEach( ( candidate ) =>
						dialog.classList.remove(
							`popkit-popup--theme-${ candidate }`
						)
					);
					dialog.classList.add( `popkit-popup--theme-${ active }` );
				},
				{
					selector: openModalFor( SPEC_SLUG ),
					themes: THEMES,
					active: theme,
				}
			);

			const box = await close.boundingBox();

			expect(
				box,
				`the close button is rendered in ${ theme }`
			).not.toBeNull();
			expect(
				box.width,
				`the close button is at least ${ MIN_TARGET }px wide in ${ theme }`
			).toBeGreaterThanOrEqual( MIN_TARGET );
			expect(
				box.height,
				`the close button is at least ${ MIN_TARGET }px tall in ${ theme }`
			).toBeGreaterThanOrEqual( MIN_TARGET );
		}
	} );

	test( 'a banner is a named landmark, not a dialog', async ( {
		page,
		requestUtils,
	} ) => {
		let banner = null;

		try {
			banner = await createPopup( requestUtils, 'a11y-banner', {
				_popkit_display: {
					layout: 'banner',
					position: 'bottom',
					animation: 'none',
				},
			} );

			// The authored modal would otherwise take the single automatic open.
			await suppressPopups( page, [ FIXTURE_SLUG, SPEC_SLUG ] );
			await page.goto( FIXTURE_PAGE );

			const element = page.locator( rootFor( 'a11y-banner' ) );

			await expect( element ).toHaveCount( 1 );
			await expect( element, 'the banner was shown' ).toBeVisible();

			const semantics = await page.evaluate( ( selector ) => {
				const node = document.querySelector( selector );
				const labelledBy = node.getAttribute( 'aria-labelledby' );
				const target = labelledBy
					? document.getElementById( labelledBy )
					: null;
				const background = document.getElementById(
					'popkit-e2e-background-link'
				);

				background.focus();

				return {
					tagName: node.tagName,
					role: node.getAttribute( 'role' ),
					ariaModal: node.getAttribute( 'aria-modal' ),
					layout: node.getAttribute( 'data-popkit-layout' ),
					hasOpenAttribute: node.hasAttribute( 'open' ),
					name: target ? target.textContent.trim() : '',
					backgroundTakesFocus:
						node.ownerDocument.activeElement === background,
				};
			}, rootFor( 'a11y-banner' ) );

			expect( semantics.layout ).toBe( 'banner' );
			expect(
				semantics.tagName,
				'a banner is not a dialog element'
			).toBe( 'DIV' );
			expect( semantics.role, 'a banner is a generic landmark' ).toBe(
				'region'
			);
			expect(
				semantics.ariaModal,
				'a banner claims no modality'
			).toBeNull();
			expect( semantics.hasOpenAttribute ).toBe( false );
			expect(
				semantics.name.length,
				'the landmark is named'
			).toBeGreaterThan( 0 );

			// Nothing about a banner is modal: the page behind stays usable.
			expect(
				semantics.backgroundTakesFocus,
				'background content is still reachable behind a banner'
			).toBe( true );

			// And it is not announced as a dialog by the accessibility tree.
			await expect(
				page.getByRole( 'dialog', { name: semantics.name } )
			).toHaveCount( 0 );
			await expect(
				page.getByRole( 'region', { name: semantics.name } )
			).toHaveCount( 1 );

			// The close button is unconditional — there is no layout without it.
			await expect( element.locator( CLOSE_BUTTON ) ).toHaveCount( 1 );
			await expect(
				element.getByRole( 'button', { name: 'Close' } )
			).toHaveCount( 1 );
		} finally {
			await deletePopup( requestUtils, banner?.id );
		}
	} );

	test( 'prefers-reduced-motion makes transitions instant, not merely faster', async ( {
		page,
	} ) => {
		/**
		 * Reads the animation-related computed styles of the popup container.
		 */
		const motion = async () =>
			page.evaluate( ( selector ) => {
				const container = document
					.querySelector( selector )
					.querySelector( '.popkit-popup__container' );
				const styles = window.getComputedStyle( container );

				return {
					transition: styles.transitionDuration,
					animation: styles.animationDuration,
					token: window
						.getComputedStyle( document.documentElement )
						.getPropertyValue( '--popkit-duration' )
						.trim(),
				};
			}, openModalFor( SPEC_SLUG ) );

		/** Whether every comma-separated duration in a value is zero. */
		const instant = ( value ) =>
			value
				.split( ',' )
				.every( ( part ) => [ '0s', '0ms' ].includes( part.trim() ) );

		// The un-emulated state first, so "instant" is measured against
		// something rather than asserted about a stylesheet that never animates.
		await page.emulateMedia( { reducedMotion: 'no-preference' } );
		await page.goto( FIXTURE_PAGE );
		await expect( page.locator( openModalFor( SPEC_SLUG ) ) ).toHaveCount(
			1
		);

		const animated = await motion();

		expect(
			animated.token,
			'the duration token is non-zero without the preference'
		).not.toBe( '0s' );

		await page.emulateMedia( { reducedMotion: 'reduce' } );
		await page.goto( FIXTURE_PAGE );
		await expect( page.locator( openModalFor( SPEC_SLUG ) ) ).toHaveCount(
			1
		);

		const reduced = await motion();

		expect(
			reduced.token,
			'the duration token is zeroed under the preference'
		).toBe( '0s' );
		expect(
			instant( reduced.transition ),
			`every transition is instant, got ${ reduced.transition }`
		).toBe( true );
		expect(
			instant( reduced.animation ),
			`every animation is instant, got ${ reduced.animation }`
		).toBe( true );
	} );
} );
