/**
 * The context endpoint and the corrected clock, from the browser's side.
 *
 * These are the two halves of the one thing cached HTML cannot answer, so this
 * file asserts the two properties that make them safe:
 *
 * 1. **The round trip is conditional.** `needsContext` is `false` on a page whose
 *    popups have no enabled schedule and no `user_state` rule, and the client
 *    then makes *no request at all* — not a speculative one, not a cheap one.
 * 2. **Every failure fails closed.** A 500, a hang, malformed JSON, and a payload
 *    missing a requested key all suppress every popup that depended on context. A
 *    network blip hiding a popup is correct; showing an expired campaign, or a
 *    members-only popup to the public, is not.
 *
 * ## Why the clock tests look like this
 *
 * `docs/data-model.md` -> Schedule bans `Date.now()` from the schedule path in
 * both the offset and the comparison, and the reason is a failure a unit test
 * cannot see: a device clock that disagrees with the server, or that changes
 * after the page loaded, must not move a campaign. The two scenarios need
 * different simulations and both are reproduced faithfully here.
 *
 * - **Wrong at load.** A machine whose OS clock is eight hours fast reports a
 *   fast `Date.now()` *and* a fast `performance.timeOrigin`, because the origin is
 *   the wall-clock reading taken when the document was created. Both are shifted,
 *   from an init script, before any plugin code runs. The monotonic offset then
 *   absorbs the whole eight hours and the schedule resolves to server time — so a
 *   window around the server's current hour matches and a window around the
 *   device's apparent hour does not.
 * - **Changed after calibration.** `performance.timeOrigin` is fixed at document
 *   creation and physically cannot move, so this shifts `Date` alone, *after* the
 *   runtime has issued its context request and *before* the response is released.
 *   Evaluation therefore runs on the far side of a six-hour jump. If a
 *   `Date.now()` ever creeps back into the offset or the comparison, the popup
 *   scheduled six hours ahead opens and the one scheduled for now does not —
 *   which is exactly what these tests assert cannot happen.
 *
 * The `Date` shim is written out twice rather than shared, because the two
 * injection points are genuinely different: one is serialized into an init script
 * that runs before the document has any scripts, the other is evaluated in a page
 * that is already running. Passing one implementation between them would mean
 * shipping source as a string and evaluating it, which is a worse trade than
 * fourteen duplicated lines.
 *
 * Windows are computed from the server's own reported time and the site's own
 * timezone rather than hardcoded, so the suite has no hour of the day at which it
 * starts failing.
 *
 * ## These specs author their own popups
 *
 * The seeded `e2e-modal` fixture carries a `url_path` rule belonging to a
 * condition nothing registers until Phase 4, so the client fails it closed and it
 * never opens. Every popup asserted here is created through `requestUtils` with
 * an empty rule set — `{ "groups": [] }`, "always match" — so the only thing
 * standing between it and the screen is its schedule, which is the subject.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/** Fixture page carrying background content and the seeded popup. */
const FIXTURE_PAGE = '/popkit-e2e/';

/** Slug of the seeded fixture popup, suppressed everywhere in this file. */
const FIXTURE_SLUG = 'e2e-modal';

/** Any popup root on the page. */
const ANY_ROOT = '[data-popkit-id]';

/** Any modal that is on screen right now. */
const ANY_OPEN_MODAL = 'dialog[data-popkit-id][open]';

/** REST collection for the popup post type. */
const POPUP_PATH = '/wp/v2/popkit_popup';

/** Path fragment identifying the context route under any permalink structure. */
const CONTEXT_ROUTE = 'popkit/v1/context';

/** Milliseconds in an hour. */
const HOUR = 3600000;

/** Minutes in a day, for wrapping a local time around midnight. */
const MINUTES_PER_DAY = 1440;

/** Half-width of a generated recurrence window, in minutes. */
const HALF_WINDOW = 45;

/** Content every authored popup carries, so it always has a real heading. */
const POPUP_CONTENT = [
	'<!-- wp:heading --><h2 class="wp-block-heading">Scheduled fixture popup</h2><!-- /wp:heading -->',
	'<!-- wp:paragraph --><p>Created by a context spec and deleted again.</p><!-- /wp:paragraph -->',
].join( '\n\n' );

/** Selector for one popup's root, whatever state it is in. */
const rootFor = ( slug ) => `[data-popkit-slug="${ slug }"]`;

/** Selector for one popup's dialog while it is on screen. */
const openModalFor = ( slug ) => `dialog[data-popkit-slug="${ slug }"][open]`;

/** Whether a URL is the context route, under pretty or plain permalinks. */
const isContextUrl = ( url ) => String( url ).includes( CONTEXT_ROUTE );

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
 * Reads the emitted config out of the page.
 */
const readConfig = async ( page ) =>
	page.evaluate( () => {
		const element = document.getElementById( 'popkit-config' );

		return element ? JSON.parse( element.textContent ) : null;
	} );

/**
 * Asks the context route for the server's own clock reading.
 *
 * Anonymous and out of band: this is the test establishing ground truth, not the
 * runtime doing its job, so it deliberately does not go through the page.
 */
const serverNow = async ( page ) => {
	const response = await page.request.get( '/wp-json/popkit/v1/context', {
		params: { fields: 'time' },
	} );

	expect( response.status(), 'the context route answers' ).toBe( 200 );

	const payload = await response.json();

	expect(
		Number.isFinite( payload.time ),
		'and reports an epoch millisecond time'
	).toBe( true );

	return payload.time;
};

/**
 * Reads the site's IANA timezone, which is what `timezone: "site"` compares in.
 */
const siteTimezone = async ( requestUtils ) => {
	const settings = await requestUtils.rest( {
		method: 'GET',
		path: '/wp/v2/settings',
	} );

	expect(
		settings.timezone,
		'the site has an IANA timezone rather than a raw offset'
	).toBeTruthy();

	return settings.timezone;
};

/**
 * Converts a moment to minutes past local midnight in a named zone.
 */
const localMinutes = ( epochMs, timeZone ) => {
	const parts = new Intl.DateTimeFormat( 'en-US', {
		timeZone,
		hourCycle: 'h23',
		hour: '2-digit',
		minute: '2-digit',
	} ).formatToParts( new Date( epochMs ) );

	const values = {};

	for ( const part of parts ) {
		values[ part.type ] = part.value;
	}

	return ( Number( values.hour ) % 24 ) * 60 + Number( values.minute );
};

/**
 * Formats minutes past midnight as the stored `HH:MM`, wrapping around the day.
 */
const toHhMm = ( minutes ) => {
	const wrapped =
		( ( minutes % MINUTES_PER_DAY ) + MINUTES_PER_DAY ) % MINUTES_PER_DAY;

	return [
		String( Math.floor( wrapped / 60 ) ).padStart( 2, '0' ),
		String( wrapped % 60 ).padStart( 2, '0' ),
	].join( ':' );
};

/**
 * Builds an enabled schedule whose only window is centered on a local time.
 *
 * `days` is empty, which `docs/data-model.md` -> Schedule defines as every day,
 * so a window that happens to cross midnight still resolves the same way however
 * late in the evening the suite is run.
 */
const scheduleAround = ( minutes ) => ( {
	enabled: true,
	timezone: 'site',
	start: null,
	end: null,
	recurrence: {
		days: [],
		windows: [
			{
				from: toHhMm( minutes - HALF_WINDOW ),
				to: toHhMm( minutes + HALF_WINDOW ),
			},
		],
	},
} );

/**
 * An enabled schedule with no recurrence constraint at all.
 *
 * Used by the fail-closed tests, where the popup must be suppressed *only*
 * because context was unavailable and never because the window happened to miss.
 */
const alwaysSchedule = () => ( {
	enabled: true,
	timezone: 'site',
	start: null,
	end: null,
	recurrence: { days: [], windows: [] },
} );

/**
 * Shifts the device wall clock before the document runs any script.
 *
 * Both wall-clock sources move, which is what an OS clock that is wrong at page
 * load actually looks like: `performance.timeOrigin` is the wall-clock reading
 * taken when the document was created, so a fast machine reports a fast origin
 * too. `performance.now()` is untouched, because it is monotonic and a clock
 * change does not move it.
 */
const fastDeviceClockAtLoad = async ( page, offsetMs ) => {
	await page.addInitScript( ( shift ) => {
		const RealDate = window.Date;

		const ShiftedDate = function ( ...args ) {
			return 0 === args.length
				? new RealDate( RealDate.now() + shift )
				: new RealDate( ...args );
		};

		ShiftedDate.prototype = RealDate.prototype;
		ShiftedDate.now = () => RealDate.now() + shift;
		ShiftedDate.parse = RealDate.parse;
		ShiftedDate.UTC = RealDate.UTC;

		window.Date = ShiftedDate;

		const origin = performance.timeOrigin;

		Object.defineProperty( performance, 'timeOrigin', {
			configurable: true,
			get: () => origin + shift,
		} );
	}, offsetMs );
};

/**
 * Shifts the device wall clock on a page that is already loaded.
 *
 * `performance.timeOrigin` is deliberately left alone: it is fixed at document
 * creation and cannot change, which is precisely why the corrected clock is
 * anchored to it. Only `Date` moves, so anything still reading the wall clock is
 * six hours out and anything on the monotonic base is unaffected.
 */
const advanceDeviceClock = async ( page, offsetMs ) => {
	await page.evaluate( ( shift ) => {
		const RealDate = window.Date;

		const ShiftedDate = function ( ...args ) {
			return 0 === args.length
				? new RealDate( RealDate.now() + shift )
				: new RealDate( ...args );
		};

		ShiftedDate.prototype = RealDate.prototype;
		ShiftedDate.now = () => RealDate.now() + shift;
		ShiftedDate.parse = RealDate.parse;
		ShiftedDate.UTC = RealDate.UTC;

		window.Date = ShiftedDate;
	}, offsetMs );
};

/**
 * Waits long enough for the runtime to have decided, then lets an assertion run.
 *
 * A negative assertion needs a settling point. `window.popkit` is installed
 * during boot, before any popup is considered, so waiting for it and then giving
 * the context lane a beat is the smallest honest wait — an immediate
 * `toHaveCount( 0 )` would pass against a runtime that had not started yet.
 */
const settle = async ( page, extraMs ) => {
	await page.waitForFunction( () => undefined !== window.popkit );
	await page.waitForTimeout( extraMs || 750 );
};

test.describe( 'popkit context endpoint', () => {
	test.beforeEach( async ( { page } ) => {
		await suppressPopups( page, [ FIXTURE_SLUG ] );
	} );

	test( 'needsContext false produces zero requests to the context route', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			// No schedule and no user_state rule — nothing to ask anybody about.
			created = await createPopup( requestUtils, 'context-none' );

			/** @type {string[]} */
			const contextRequests = [];

			page.on( 'request', ( request ) => {
				if ( isContextUrl( request.url() ) ) {
					contextRequests.push( request.url() );
				}
			} );

			await page.goto( FIXTURE_PAGE );

			// The runtime ran to completion — this is not a page where nothing
			// happened, it is a page where nothing needed asking.
			await expect(
				page.locator( openModalFor( 'context-none' ) )
			).toHaveCount( 1 );

			const config = await readConfig( page );

			expect(
				config.needsContext,
				'no emitted popup has a schedule or a user_state rule'
			).toBe( false );

			await settle( page );

			expect(
				contextRequests,
				'a page that needs no context costs no round trip'
			).toEqual( [] );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'an enabled schedule sets needsContext and asks for exactly the field it needs', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			created = await createPopup( requestUtils, 'context-scheduled', {
				_popkit_schedule: alwaysSchedule(),
			} );

			/** @type {string[]} */
			const contextRequests = [];

			page.on( 'request', ( request ) => {
				if ( isContextUrl( request.url() ) ) {
					contextRequests.push( request.url() );
				}
			} );

			await page.goto( FIXTURE_PAGE );

			await expect(
				page.locator( openModalFor( 'context-scheduled' ) ),
				'a scheduled popup inside its window opens'
			).toHaveCount( 1 );

			const config = await readConfig( page );

			expect( config.needsContext ).toBe( true );
			expect( config.restUrl ).toContain( CONTEXT_ROUTE );

			/*
			 * There is no serverTime in the emitted config and there never will
			 * be: a timestamp printed into a cached response is frozen at
			 * cache-fill time and stale for every later hit.
			 */
			expect( config.serverTime ).toBeUndefined();

			await settle( page );

			expect(
				contextRequests.length,
				'context is fetched exactly once per pageview'
			).toBe( 1 );

			const requested = new URL( contextRequests[ 0 ] ).searchParams.get(
				'fields'
			);

			expect(
				requested,
				'a schedule needs time and nothing else — user_state is not asked for'
			).toBe( 'time' );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	const FAILURES = [
		{
			name: 'a 500 response',
			handle: ( route ) =>
				route.fulfill( {
					status: 500,
					contentType: 'application/json',
					body: '{"code":"internal_server_error"}',
				} ),
			wait: 750,
		},
		{
			name: 'malformed JSON',
			handle: ( route ) =>
				route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: 'this is not JSON',
				} ),
			wait: 750,
		},
		{
			name: 'a payload missing the requested key',
			handle: ( route ) =>
				route.fulfill( {
					status: 200,
					contentType: 'application/json',
					body: '{}',
				} ),
			wait: 750,
		},
		{
			// Never answered. The client aborts its own request after five
			// seconds, so the assertion waits past that.
			name: 'a request that never answers',
			handle: () => new Promise( () => {} ),
			wait: 6500,
		},
	];

	for ( const failure of FAILURES ) {
		test( `${ failure.name } suppresses every popup that depends on context`, async ( {
			page,
			requestUtils,
		} ) => {
			let created = null;

			try {
				created = await createPopup(
					requestUtils,
					'context-failclosed',
					{ _popkit_schedule: alwaysSchedule() }
				);

				await page.route(
					( url ) => isContextUrl( url.href ),
					failure.handle
				);

				await page.goto( FIXTURE_PAGE );
				await settle( page, failure.wait );

				/*
				 * The server emitted it. Asserting that first is what makes the
				 * next assertion mean "it refused to open" rather than "it was
				 * never on the page".
				 */
				await expect(
					page.locator( rootFor( 'context-failclosed' ) ),
					'the markup was emitted'
				).toHaveCount( 1 );

				await expect(
					page.locator( ANY_OPEN_MODAL ),
					'nothing opened without authoritative time'
				).toHaveCount( 0 );

				// And no exception reached the page: failing closed is a
				// decision, not a crash.
				expect(
					await page.evaluate( () => undefined !== window.popkit ),
					'the runtime survived the failure'
				).toBe( true );
			} finally {
				await page.unrouteAll( { behavior: 'ignoreErrors' } );
				await deletePopup( requestUtils, created?.id );
			}
		} );
	}
} );

test.describe( 'popkit corrected clock', () => {
	test.beforeEach( async ( { page } ) => {
		await suppressPopups( page, [ FIXTURE_SLUG ] );
	} );

	test( 'a device clock eight hours fast still shows a popup scheduled for the server hour', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			const timezone = await siteTimezone( requestUtils );
			const now = await serverNow( page );

			created = await createPopup( requestUtils, 'clock-server-hour', {
				_popkit_schedule: scheduleAround(
					localMinutes( now, timezone )
				),
			} );

			await fastDeviceClockAtLoad( page, 8 * HOUR );
			await page.goto( FIXTURE_PAGE );

			await expect(
				page.locator( openModalFor( 'clock-server-hour' ) ),
				'the schedule resolved against server time, not the device clock'
			).toHaveCount( 1 );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'a device clock eight hours fast does not show a popup scheduled for the device hour', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			const timezone = await siteTimezone( requestUtils );
			const now = await serverNow( page );

			created = await createPopup( requestUtils, 'clock-device-hour', {
				_popkit_schedule: scheduleAround(
					localMinutes( now + 8 * HOUR, timezone )
				),
			} );

			/** @type {string[]} */
			const contextRequests = [];

			page.on( 'request', ( request ) => {
				if ( isContextUrl( request.url() ) ) {
					contextRequests.push( request.url() );
				}
			} );

			await fastDeviceClockAtLoad( page, 8 * HOUR );
			await page.goto( FIXTURE_PAGE );
			await settle( page );

			// Everything that would make the assertion vacuous is ruled out
			// first: the popup is on the page, and the clock was calibrated.
			await expect(
				page.locator( rootFor( 'clock-device-hour' ) ),
				'the markup was emitted'
			).toHaveCount( 1 );
			expect(
				contextRequests.length,
				'the clock was calibrated against the server'
			).toBe( 1 );

			await expect(
				page.locator( ANY_OPEN_MODAL ),
				'a window eight hours away stayed shut'
			).toHaveCount( 0 );
		} finally {
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'advancing the device clock after calibration does not close a live window', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			const timezone = await siteTimezone( requestUtils );
			const now = await serverNow( page );

			created = await createPopup( requestUtils, 'clock-after-server', {
				_popkit_schedule: scheduleAround(
					localMinutes( now, timezone )
				),
			} );

			let release = null;
			let requested = false;
			const held = new Promise( ( resolve ) => {
				release = resolve;
			} );

			page.on( 'request', ( request ) => {
				if ( isContextUrl( request.url() ) ) {
					requested = true;
				}
			} );

			await page.route(
				( url ) => isContextUrl( url.href ),
				async ( route ) => {
					await held;

					const response = await route.fetch();

					await route.fulfill( { response } );
				}
			);

			await page.goto( FIXTURE_PAGE );
			await expect
				.poll( () => requested, {
					message: 'the runtime issued its context request',
				} )
				.toBe( true );

			// Six hours, applied between the request going out and the response
			// coming back, so the schedule is evaluated on the far side of it.
			await advanceDeviceClock( page, 6 * HOUR );
			release();

			await expect(
				page.locator( openModalFor( 'clock-after-server' ) ),
				'a live window survived a six-hour device clock jump'
			).toHaveCount( 1 );
		} finally {
			await page.unrouteAll( { behavior: 'ignoreErrors' } );
			await deletePopup( requestUtils, created?.id );
		}
	} );

	test( 'advancing the device clock after calibration does not open a future window', async ( {
		page,
		requestUtils,
	} ) => {
		let created = null;

		try {
			const timezone = await siteTimezone( requestUtils );
			const now = await serverNow( page );

			created = await createPopup( requestUtils, 'clock-after-future', {
				_popkit_schedule: scheduleAround(
					localMinutes( now + 6 * HOUR, timezone )
				),
			} );

			let release = null;
			let requested = false;
			const held = new Promise( ( resolve ) => {
				release = resolve;
			} );

			page.on( 'request', ( request ) => {
				if ( isContextUrl( request.url() ) ) {
					requested = true;
				}
			} );

			await page.route(
				( url ) => isContextUrl( url.href ),
				async ( route ) => {
					await held;

					const response = await route.fetch();

					await route.fulfill( { response } );
				}
			);

			await page.goto( FIXTURE_PAGE );
			await expect
				.poll( () => requested, {
					message: 'the runtime issued its context request',
				} )
				.toBe( true );

			await advanceDeviceClock( page, 6 * HOUR );
			release();

			await settle( page );

			await expect(
				page.locator( rootFor( 'clock-after-future' ) ),
				'the markup was emitted'
			).toHaveCount( 1 );
			await expect(
				page.locator( ANY_OPEN_MODAL ),
				'a window six hours ahead did not open early'
			).toHaveCount( 0 );

			// Two popups are on the page — the seeded fixture and this one — so
			// "nothing opened" is a statement about a popup that was there.
			await expect( page.locator( ANY_ROOT ) ).toHaveCount( 2 );
		} finally {
			await page.unrouteAll( { behavior: 'ignoreErrors' } );
			await deletePopup( requestUtils, created?.id );
		}
	} );
} );
