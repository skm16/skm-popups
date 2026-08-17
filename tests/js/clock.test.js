/**
 * Unit tests for the calibrated monotonic clock.
 *
 * Two properties of `src/frontend/clock.js` are load-bearing, and everything in
 * this file exists to pin one of them down.
 *
 * 1. **Calibration anchors on the round-trip midpoint.** The server's reading
 *    describes the middle of the trip, not the moment the response finished
 *    parsing. Anchoring on the parse moment leaves the corrected clock running
 *    late by the whole response leg, which is why the assertions below check the
 *    derived offset against 1200 rather than 1400.
 *
 * 2. **The device wall clock is never consulted.** `performance.now()` is
 *    monotonic and unaffected by clock adjustments; the wall clock is not.
 *    Reading one side of the calculation from each base reintroduces exactly the
 *    skew the offset exists to remove, and the failure is invisible until a
 *    visitor's machine takes an NTP correction mid-session. The two clocks are
 *    therefore mocked *separately* here, and the wall clock is moved after
 *    calibration to prove the corrected clock does not follow it.
 *
 * @see docs/data-model.md -> Schedule -> Where `now` comes from
 * @see docs/build-plan.md -> Phase 2 -> Clock correctness
 */

/* global describe, it, expect, jest, beforeEach, afterEach */

import { createClock, monotonicNow } from '../../src/frontend/clock.js';

/**
 * Simulated `performance.timeOrigin` — document creation, epoch milliseconds.
 *
 * @type {number}
 */
const TIME_ORIGIN = 1766000000000;

/**
 * Simulated device wall-clock reading at the start of each test.
 *
 * Deliberately nowhere near `TIME_ORIGIN`: this visitor's machine is a little
 * over an hour behind, which is the situation the corrected clock exists for.
 *
 * @type {number}
 */
const DEVICE_START = TIME_ORIGIN - 5000000;

/**
 * How far the corrected clock must sit ahead of the monotonic base.
 *
 * @type {number}
 */
const TRUE_OFFSET = 5000000;

/**
 * Monotonic reading taken immediately before the context request.
 *
 * The three round-trip constants are the values named in the build plan, used
 * literally so the arithmetic under test is legible in the assertions.
 *
 * @type {number}
 */
const SENT_AT = 1000;

/**
 * Monotonic reading taken immediately after the response was parsed.
 *
 * @type {number}
 */
const RECEIVED_AT = 1400;

/**
 * Midpoint of the round trip — the instant the server's reading describes.
 *
 * @type {number}
 */
const MIDPOINT = 1200;

/**
 * Duration of the simulated round trip.
 *
 * @type {number}
 */
const ROUND_TRIP = RECEIVED_AT - SENT_AT;

/**
 * Half the round trip, which bounds the calibration error.
 *
 * @type {number}
 */
const HALF_TRIP = ROUND_TRIP / 2;

/**
 * Six hours in milliseconds, the wall-clock jump applied after calibration.
 *
 * @type {number}
 */
const SIX_HOURS = 21600000;

/**
 * Current value reported by the mocked `performance.timeOrigin`.
 *
 * @type {number}
 */
let timeOriginMs = TIME_ORIGIN;

/**
 * Current value reported by the mocked `performance.now()`.
 *
 * @type {number}
 */
let elapsedMs = 0;

/**
 * Current value reported by the mocked `Date.now()`.
 *
 * @type {number}
 */
let deviceClockMs = DEVICE_START;

/**
 * Spy standing in for the device wall clock, so its reads can be counted.
 *
 * @type {Object}
 */
let dateNowSpy;

/**
 * Advances the monotonic clock, as elapsed time on the page would.
 *
 * @param {number} ms Milliseconds to advance.
 * @return {void}
 */
function advanceMonotonic( ms ) {
	elapsedMs += ms;
}

/**
 * Moves the simulated device wall clock, as an NTP correction or a user would.
 *
 * @param {number} ms Milliseconds to move; may be negative.
 * @return {void}
 */
function moveDeviceClock( ms ) {
	deviceClockMs += ms;
}

/**
 * Counts how many times the device wall clock is read during a call.
 *
 * The count is taken as a delta rather than from a cleared spy, so a read made
 * by the test runner between hooks cannot be mistaken for one made by the code
 * under test.
 *
 * @param {Function} run Function to execute.
 * @return {number} Number of `Date.now()` calls made while it ran.
 */
function countDeviceClockReads( run ) {
	const before = dateNowSpy.mock.calls.length;

	run();

	return dateNowSpy.mock.calls.length - before;
}

/**
 * Calibrates a fresh clock against one simulated round trip.
 *
 * The truth being simulated is that the corrected clock should read
 * `monotonic + TRUE_OFFSET` at every instant. The server read its own clock at
 * `stampedAt` on the monotonic timeline, so the payload it returned was
 * `stampedAt + TRUE_OFFSET`.
 *
 * @param {number} stampedAt Monotonic reading at which the server read its clock.
 * @return {number} The offset the clock derived, in milliseconds.
 */
function offsetFor( stampedAt ) {
	const clock = createClock();

	clock.calibrate( stampedAt + TRUE_OFFSET, SENT_AT, RECEIVED_AT );

	return clock.now() - monotonicNow();
}

beforeEach( () => {
	timeOriginMs = TIME_ORIGIN;
	elapsedMs = 0;
	deviceClockMs = DEVICE_START;

	// `timeOrigin` is a prototype getter in jsdom, so an own property is defined
	// to shadow it and deleted again afterwards.
	Object.defineProperty( performance, 'timeOrigin', {
		configurable: true,
		get: () => timeOriginMs,
	} );

	jest.spyOn( performance, 'now' ).mockImplementation( () => elapsedMs );

	dateNowSpy = jest
		.spyOn( Date, 'now' )
		.mockImplementation( () => deviceClockMs );
} );

afterEach( () => {
	jest.restoreAllMocks();

	delete performance.timeOrigin;
} );

describe( 'monotonicNow', () => {
	it( 'reports the monotonic base as an epoch timestamp', () => {
		advanceMonotonic( 250 );

		expect( monotonicNow() ).toBe( TIME_ORIGIN + 250 );
	} );

	it( 'does not read the device wall clock', () => {
		expect( countDeviceClockReads( () => monotonicNow() ) ).toBe( 0 );
	} );
} );

describe( 'createClock -> calibrate', () => {
	it( 'anchors on the round-trip midpoint, not on the parse moment', () => {
		// Symmetric latency: the server read its clock halfway through the trip,
		// at monotonic 1200. A midpoint anchor recovers the truth exactly.
		expect( offsetFor( MIDPOINT ) ).toBe( TRUE_OFFSET );

		// Anchoring on `receivedAt` — monotonic 1400 — would have produced this
		// instead, leaving the corrected clock running half a round trip late.
		expect( offsetFor( MIDPOINT ) ).not.toBe( TRUE_OFFSET - HALF_TRIP );
	} );

	it( 'keeps the error within half the round trip, never the whole trip', () => {
		const errors = [];

		// Sweep every instant the server could plausibly have stamped, from the
		// request leaving the browser to the response finishing parsing.
		for (
			let stampedAt = SENT_AT;
			stampedAt <= RECEIVED_AT;
			stampedAt += 10
		) {
			errors.push( offsetFor( stampedAt ) - TRUE_OFFSET );
		}

		const worst = Math.max(
			...errors.map( ( error ) => Math.abs( error ) )
		);

		expect( worst ).toBe( HALF_TRIP );
		expect( worst ).toBeLessThan( ROUND_TRIP );
	} );

	it( 'errs by half a trip at worst when latency is fully asymmetric', () => {
		// Request arrives instantly, response crawls back: the reading is stale
		// by the whole return leg, and the midpoint credits back half of it.
		expect( offsetFor( SENT_AT ) ).toBe( TRUE_OFFSET - HALF_TRIP );

		// The mirror image, where the request leg carries all the latency.
		expect( offsetFor( RECEIVED_AT ) ).toBe( TRUE_OFFSET + HALF_TRIP );
	} );

	it( 'derives the offset without reading the device wall clock', () => {
		const clock = createClock();

		const reads = countDeviceClockReads( () => {
			clock.calibrate( MIDPOINT + TRUE_OFFSET, SENT_AT, RECEIVED_AT );
			clock.now();
		} );

		expect( reads ).toBe( 0 );
	} );

	it( 'ignores a device wall clock that is wildly wrong at calibration', () => {
		moveDeviceClock( -SIX_HOURS * 100 );

		expect( offsetFor( MIDPOINT ) ).toBe( TRUE_OFFSET );
	} );

	it( 'lets the last calibration win', () => {
		const clock = createClock();

		clock.calibrate( MIDPOINT + TRUE_OFFSET, SENT_AT, RECEIVED_AT );
		clock.calibrate( MIDPOINT + TRUE_OFFSET + 9000, SENT_AT, RECEIVED_AT );

		expect( clock.now() - monotonicNow() ).toBe( TRUE_OFFSET + 9000 );
	} );

	it.each( [
		[ 'a NaN server time', NaN, SENT_AT, RECEIVED_AT ],
		[ 'an infinite server time', Infinity, SENT_AT, RECEIVED_AT ],
		[ 'a non-numeric server time', 'soon', SENT_AT, RECEIVED_AT ],
		[ 'a missing sent-at reading', MIDPOINT, undefined, RECEIVED_AT ],
		[ 'a missing received-at reading', MIDPOINT, SENT_AT, undefined ],
	] )(
		'stays uncalibrated given %s',
		( label, serverTime, sentAt, receivedAt ) => {
			const clock = createClock();

			clock.calibrate( serverTime, sentAt, receivedAt );

			expect( clock.isCalibrated() ).toBe( false );
			expect( clock.now() ).toBeNull();
		}
	);
} );

describe( 'createClock -> now', () => {
	it( 'does not silently return device time before calibration', () => {
		const clock = createClock();

		expect( clock.isCalibrated() ).toBe( false );
		expect( clock.now() ).toBeNull();
		expect( clock.now() ).not.toBe( deviceClockMs );
		expect( clock.now() ).not.toBe( monotonicNow() );
		expect( countDeviceClockReads( () => clock.now() ) ).toBe( 0 );
	} );

	it( 'reports itself calibrated only once an offset has been applied', () => {
		const clock = createClock();

		expect( clock.isCalibrated() ).toBe( false );

		clock.calibrate( MIDPOINT + TRUE_OFFSET, SENT_AT, RECEIVED_AT );

		expect( clock.isCalibrated() ).toBe( true );
	} );

	it( 'does not move when the device clock jumps forward afterwards', () => {
		const clock = createClock();

		clock.calibrate( MIDPOINT + TRUE_OFFSET, SENT_AT, RECEIVED_AT );

		const before = clock.now();

		// The visitor's machine takes a six hour correction. Nothing about the
		// authoritative time has changed, so nothing about `now()` may change.
		const reads = countDeviceClockReads( () => {
			moveDeviceClock( SIX_HOURS );

			expect( clock.now() ).toBe( before );
		} );

		expect( reads ).toBe( 0 );
	} );

	it( 'does not move when the device clock jumps backward afterwards', () => {
		const clock = createClock();

		clock.calibrate( MIDPOINT + TRUE_OFFSET, SENT_AT, RECEIVED_AT );

		const before = clock.now();

		moveDeviceClock( -SIX_HOURS );

		expect( clock.now() ).toBe( before );
	} );

	it( 'advances exactly with the monotonic clock', () => {
		const clock = createClock();

		clock.calibrate( MIDPOINT + TRUE_OFFSET, SENT_AT, RECEIVED_AT );

		const before = clock.now();

		// Real elapsed time on the page, and a wall clock lurching around
		// underneath it. Only the first may show up in the reading.
		advanceMonotonic( 5000 );
		moveDeviceClock( SIX_HOURS );

		expect( clock.now() ).toBe( before + 5000 );
	} );

	it( 'stays independent of every other clock instance', () => {
		const first = createClock();
		const second = createClock();

		first.calibrate( MIDPOINT + TRUE_OFFSET, SENT_AT, RECEIVED_AT );

		expect( second.isCalibrated() ).toBe( false );
		expect( second.now() ).toBeNull();
	} );
} );
