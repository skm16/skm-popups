/**
 * Unit tests for the fail-closed context fetch.
 *
 * `src/frontend/context.js` is the only thing popkit asks the server on a
 * front-end pageview, and every popup that depends on authoritative time or on
 * login state is gated on what comes back. Beyond transport the module has one
 * job: collapse every failure mode to `null`.
 *
 * ## Why the failures are enumerated rather than sampled
 *
 * They do not share a code path. A non-200, a connection that never completes, a
 * body that is not JSON, and a well-formed body missing a key the client asked
 * for are four separate branches, and each one is a separate opportunity to
 * return a partial object that a caller would treat as an answer.
 *
 * The partial answer is the failure that matters. `{ time: … }` returned for a
 * request that also asked for `user_state` looks exactly like success, passes
 * every truthiness check a caller might make, and shows a members-only popup to
 * the public. So the assertions below are `toBeNull()` rather than "falsy", and
 * every failing case also asserts the clock was left uncalibrated — a response
 * too broken to act on is too broken to move the clock with.
 *
 * ## The other two properties
 *
 * One request per pageview, because several popups await context independently
 * and the route is uncached by construction; and calibration is handed the two
 * monotonic readings that bracket the round trip, in that order. clock.js
 * anchors on their midpoint, so a caller passing them the wrong way round would
 * still calibrate — just wrongly, by the length of the trip, with nothing
 * anywhere reporting an error.
 *
 * @see docs/CLAUDE.md -> The context endpoint
 * @see docs/data-model.md -> Context endpoint payload
 */

/* global describe, it, expect, jest, beforeEach, afterEach */

/**
 * Absolute URL of the context route, as the emitted config carries it.
 *
 * @type {string}
 */
const REST_URL = 'https://example.org/wp-json/popkit/v1/context';

/**
 * The same route on a site with plain permalinks, where a query string exists.
 *
 * @type {string}
 */
const REST_URL_PLAIN = 'https://example.org/?rest_route=/popkit/v1/context';

/**
 * Simulated `performance.timeOrigin` — document creation, epoch milliseconds.
 *
 * @type {number}
 */
const TIME_ORIGIN = 1766000000000;

/**
 * Milliseconds the mocked monotonic clock advances on every read.
 *
 * A fixed tick rather than the real clock. `sentAt` and `receivedAt` are taken
 * two reads apart, and a real pair can come back equal on a coarse timer — the
 * assertion that one strictly precedes the other would then pass or fail by
 * luck rather than by behavior.
 *
 * @type {number}
 */
const TICK = 10;

/**
 * Server reading carried by a well-formed payload.
 *
 * @type {number}
 */
const SERVER_TIME = 1766000123456;

/**
 * A payload that satisfies every validator, for the cases about success.
 *
 * @type {Object}
 */
const GOOD_PAYLOAD = {
	time: SERVER_TIME,
	user: { state: 'logged_in' },
};

/**
 * `window.fetch` as the environment left it, restored after every test.
 *
 * @type {Function|undefined}
 */
const ORIGINAL_FETCH = window.fetch;

/**
 * Reading the next call to the mocked `performance.now()` will report.
 *
 * @type {number}
 */
let elapsedMs = 0;

/**
 * Loads a fresh copy of the module under test.
 *
 * `context.js` memoizes its single request in module scope. That is the
 * behavior under test in one case and unwanted carry-over in every other, so
 * each test gets its own instance rather than a shared one somebody has to
 * remember to reset.
 *
 * @return {Promise<Object>} The module's exports.
 */
async function loadContext() {
	jest.resetModules();

	return import( '../../src/frontend/context.js' );
}

/**
 * Builds a clock whose every method is a spy.
 *
 * @return {Object} Stand-in for the object `createClock()` returns.
 */
function spyClock() {
	return {
		calibrate: jest.fn(),
		now: jest.fn( () => null ),
		isCalibrated: jest.fn( () => false ),
	};
}

/**
 * Makes the next request resolve with a 200 carrying a decoded payload.
 *
 * @param {*} payload Value `response.json()` resolves to.
 * @return {void}
 */
function respondWith( payload ) {
	window.fetch.mockResolvedValue( {
		status: 200,
		json: async () => payload,
	} );
}

/**
 * Reads the URL the module requested.
 *
 * @return {string} Request URL from the first — and normally only — call.
 */
function requestedUrl() {
	return window.fetch.mock.calls[ 0 ][ 0 ];
}

beforeEach( () => {
	elapsedMs = 0;

	// `timeOrigin` is a prototype getter in jsdom, so an own property is defined
	// to shadow it and deleted again afterwards.
	Object.defineProperty( performance, 'timeOrigin', {
		configurable: true,
		get: () => TIME_ORIGIN,
	} );

	jest.spyOn( performance, 'now' ).mockImplementation( () => {
		elapsedMs += TICK;

		return elapsedMs;
	} );

	window.fetch = jest.fn();
} );

afterEach( () => {
	jest.restoreAllMocks();

	window.fetch = ORIGINAL_FETCH;

	delete performance.timeOrigin;
} );

describe( 'fetchContext -> unavailable', () => {
	it( 'reports unavailable for a status other than 200', async () => {
		const { fetchContext } = await loadContext();
		const clock = spyClock();

		window.fetch.mockResolvedValue( {
			status: 503,
			json: async () => GOOD_PAYLOAD,
		} );

		expect(
			await fetchContext( REST_URL, [ 'time', 'user_state' ], clock )
		).toBeNull();

		// A response that was never accepted must not move the clock.
		expect( clock.calibrate ).not.toHaveBeenCalled();
	} );

	it( 'reports unavailable when the request itself throws', async () => {
		const { fetchContext } = await loadContext();
		const clock = spyClock();

		window.fetch.mockRejectedValue( new TypeError( 'Failed to fetch' ) );

		expect(
			await fetchContext( REST_URL, [ 'time', 'user_state' ], clock )
		).toBeNull();
		expect( clock.calibrate ).not.toHaveBeenCalled();
	} );

	it( 'reports unavailable when the body is not JSON', async () => {
		const { fetchContext } = await loadContext();
		const clock = spyClock();

		// What a captive portal, an HTML error page, or an over-eager optimizer
		// produces: a 200 whose body will not parse.
		window.fetch.mockResolvedValue( {
			status: 200,
			json: async () => {
				throw new SyntaxError( 'Unexpected token < in JSON' );
			},
		} );

		expect(
			await fetchContext( REST_URL, [ 'time', 'user_state' ], clock )
		).toBeNull();
		expect( clock.calibrate ).not.toHaveBeenCalled();
	} );

	/*
	 * A requested key that did not arrive discards the whole response. Returning
	 * the half that did arrive looks like success to every caller — and the
	 * half that arrives first is `time`, so the popup that shows is the one
	 * whose audience was never checked.
	 */
	it.each( [
		[
			'time is missing from a response asked for both',
			[ 'time', 'user_state' ],
			{ user: { state: 'logged_in' } },
		],
		[
			'user is missing from a response asked for both',
			[ 'time', 'user_state' ],
			{ time: SERVER_TIME },
		],
		[
			'user_state was asked for and user carries no state',
			[ 'user_state' ],
			{ user: {} },
		],
		[
			'user.state is a value outside the documented pair',
			[ 'user_state' ],
			{ user: { state: 'maybe' } },
		],
		[
			'time came back as something other than a finite number',
			[ 'time' ],
			{ time: 'soon' },
		],
		[ 'the payload is empty', [ 'time', 'user_state' ], {} ],
		[ 'the payload is not an object at all', [ 'time' ], 'nope' ],
	] )(
		'reports unavailable, not a partial answer, when %s',
		async ( label, fields, payload ) => {
			const { fetchContext } = await loadContext();
			const clock = spyClock();

			respondWith( payload );

			expect( await fetchContext( REST_URL, fields, clock ) ).toBeNull();
			expect( clock.calibrate ).not.toHaveBeenCalled();
		}
	);

	/*
	 * A request whose response could not be validated against anything is not
	 * worth making. Every name this client sends has a validator below it; a
	 * name with none would be "requested" and then never checked, which is the
	 * quiet way a missing key turns into a popup shown to everyone.
	 */
	it.each( [
		[ 'an empty field list', [] ],
		[ 'a list of names this client cannot validate', [ 'nonsense' ] ],
		[ 'no field list at all', undefined ],
	] )( 'makes no request at all given %s', async ( label, fields ) => {
		const { fetchContext } = await loadContext();
		const clock = spyClock();

		expect( await fetchContext( REST_URL, fields, clock ) ).toBeNull();
		expect( window.fetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'fetchContext -> one request per pageview', () => {
	it( 'answers a second caller without issuing a second request', async () => {
		const { fetchContext } = await loadContext();
		const clock = spyClock();

		respondWith( GOOD_PAYLOAD );

		const first = await fetchContext(
			REST_URL,
			[ 'time', 'user_state' ],
			clock
		);
		const second = await fetchContext(
			REST_URL,
			[ 'time', 'user_state' ],
			clock
		);

		expect( first ).toEqual( {
			time: SERVER_TIME,
			user: { state: 'logged_in' },
		} );

		// The same answer, not a second one that happens to match.
		expect( second ).toBe( first );

		// The route is uncached and several popups await it independently, so
		// one pageview has to be one round trip.
		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not retry after a failure either', async () => {
		const { fetchContext } = await loadContext();
		const clock = spyClock();

		window.fetch.mockRejectedValue( new TypeError( 'Failed to fetch' ) );

		expect( await fetchContext( REST_URL, [ 'time' ], clock ) ).toBeNull();
		expect( await fetchContext( REST_URL, [ 'time' ], clock ) ).toBeNull();

		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'fetchContext -> the request', () => {
	it( 'asks for exactly the fields it was given', async () => {
		const { fetchContext } = await loadContext();

		respondWith( { user: { state: 'logged_out' } } );

		expect(
			await fetchContext( REST_URL, [ 'user_state' ], spyClock() )
		).toEqual( { user: { state: 'logged_out' } } );

		// Asking for less than the page needs is the whole of the privacy story.
		expect( requestedUrl() ).toBe( `${ REST_URL }?fields=user_state` );
	} );

	it( 'appends to a URL that already carries a query string', async () => {
		const { fetchContext } = await loadContext();

		respondWith( GOOD_PAYLOAD );

		await fetchContext(
			REST_URL_PLAIN,
			[ 'time', 'user_state' ],
			spyClock()
		);

		// `rest_url()` carries `?rest_route=` on a site with plain permalinks,
		// so the separator cannot be assumed.
		expect( requestedUrl() ).toBe(
			`${ REST_URL_PLAIN }&fields=time,user_state`
		);
	} );

	it( 'sends the cookie and refuses a cached answer', async () => {
		const { fetchContext } = await loadContext();

		respondWith( GOOD_PAYLOAD );

		await fetchContext( REST_URL, [ 'time', 'user_state' ], spyClock() );

		const options = window.fetch.mock.calls[ 0 ][ 1 ];

		// The logged-in cookie is the only way the route can derive login state
		// without a per-user REST nonce.
		expect( options.credentials ).toBe( 'same-origin' );

		// A stale intermediary must not be able to hand back a frozen timestamp
		// or another visitor's login state.
		expect( options.cache ).toBe( 'no-store' );
	} );
} );

describe( 'fetchContext -> calibration', () => {
	it( 'calibrates with the readings that bracket the round trip', async () => {
		const { fetchContext } = await loadContext();
		const clock = spyClock();

		respondWith( GOOD_PAYLOAD );

		await fetchContext( REST_URL, [ 'time', 'user_state' ], clock );

		expect( clock.calibrate ).toHaveBeenCalledTimes( 1 );

		const [ serverTime, sentAt, receivedAt ] =
			clock.calibrate.mock.calls[ 0 ];

		expect( serverTime ).toBe( SERVER_TIME );
		expect( Number.isFinite( sentAt ) ).toBe( true );
		expect( Number.isFinite( receivedAt ) ).toBe( true );

		/*
		 * The request was sent before the response arrived. clock.js anchors on
		 * the midpoint of these two, so handing them over the wrong way round
		 * still calibrates — just wrongly, by the length of the round trip, with
		 * nothing anywhere reporting an error.
		 */
		expect( sentAt ).toBeLessThan( receivedAt );
	} );

	it( 'does not calibrate when time was not requested', async () => {
		const { fetchContext } = await loadContext();
		const clock = spyClock();

		// The server answers only what it was asked for, so a `time` the client
		// never requested must not be acted on if one turns up anyway.
		respondWith( GOOD_PAYLOAD );

		expect(
			await fetchContext( REST_URL, [ 'user_state' ], clock )
		).toEqual( { user: { state: 'logged_in' } } );
		expect( clock.calibrate ).not.toHaveBeenCalled();
	} );
} );
