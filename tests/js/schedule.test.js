/**
 * Unit tests for schedule evaluation.
 *
 * The midnight-crossing table is the reason this file exists. A window whose
 * `to` is earlier than its `from` **belongs to its starting day**: Friday
 * 22:00–02:00 runs from Friday evening into Saturday morning, and does *not*
 * cover Friday's own 00:00–02:00. The alternative reading — that the window
 * applies to both calendar days it touches — shows the popup roughly a day
 * before the author intended, and looks perfectly reasonable in code review.
 *
 * Instants are written as local wall-clock times with an explicit UTC offset, so
 * every assertion states the local time it is asserting about while remaining
 * independent of the machine the suite runs on.
 *
 * @see docs/data-model.md -> Schedule
 * @see docs/build-plan.md -> Phase 2 -> Clock correctness
 */

/* global describe, it, expect */

import { scheduleAllows } from '../../src/frontend/schedule.js';

/**
 * Site timezone used throughout. It observes daylight saving, which the
 * transition tests at the bottom of this file depend on.
 *
 * @type {string}
 */
const SITE_ZONE = 'America/New_York';

/**
 * Eastern Daylight Time, in force from March to November.
 *
 * @type {string}
 */
const EDT = '-04:00';

/**
 * Eastern Standard Time, in force from November to March.
 *
 * @type {string}
 */
const EST = '-05:00';

/**
 * Zulu designator, for instants written directly in UTC.
 *
 * @type {string}
 */
const UTC = 'Z';

/**
 * Converts a wall-clock moment written with its UTC offset to epoch ms.
 *
 * `Date.parse` is used only to *build* fixtures. The module under test is given
 * a plain number, exactly as the controller would hand it one from the
 * offset-corrected monotonic clock.
 *
 * @param {string} local  Local time as `YYYY-MM-DDTHH:MM:SS`.
 * @param {string} offset UTC offset such as `-04:00`, or `Z`.
 * @return {number} Epoch milliseconds.
 */
function at( local, offset ) {
	return Date.parse( local + offset );
}

/**
 * Builds an enabled, open-ended schedule constrained only by recurrence.
 *
 * @param {Array}  days     Selected ISO weekdays, 1 (Monday) to 7 (Sunday).
 * @param {Array}  windows  Local `HH:MM` windows.
 * @param {string} timezone `site` or `visitor`.
 * @return {Object} Schedule object in the emitted-config shape.
 */
function recurring( days, windows, timezone ) {
	return {
		enabled: true,
		timezone: timezone || 'site',
		start: null,
		end: null,
		recurrence: { days, windows },
	};
}

/**
 * Builds an enabled schedule constrained only by its campaign range.
 *
 * @param {*} start ISO 8601 UTC timestamp, or null for open-ended.
 * @param {*} end   ISO 8601 UTC timestamp, or null for open-ended.
 * @return {Object} Schedule object in the emitted-config shape.
 */
function campaign( start, end ) {
	return {
		enabled: true,
		timezone: 'site',
		start,
		end,
	};
}

/**
 * Friday nights, 22:00 through 02:00 — the canonical crossing window.
 *
 * @type {Object}
 */
const FRIDAY_NIGHT = recurring( [ 5 ], [ { from: '22:00', to: '02:00' } ] );

/**
 * Office hours on every day of the week.
 *
 * @type {Object}
 */
const OFFICE_HOURS = recurring( [], [ { from: '09:00', to: '17:00' } ] );

describe( 'scheduleAllows -> no schedule to enforce', () => {
	it.each( [
		[ 'a null schedule', null ],
		[ 'an undefined schedule', undefined ],
		[ 'a disabled schedule', { ...FRIDAY_NIGHT, enabled: false } ],
		[ 'a schedule with no enabled flag', { recurrence: { days: [ 5 ] } } ],
	] )( 'imposes no restriction for %s', ( label, schedule ) => {
		// Passing null for `nowMs` proves the answer never needed the clock: a
		// popup without a schedule must not depend on the context endpoint.
		expect( scheduleAllows( schedule, null, SITE_ZONE ) ).toBe( true );
	} );
} );

describe( 'scheduleAllows -> fail closed without an authoritative clock', () => {
	it.each( [
		[ 'null', null ],
		[ 'undefined', undefined ],
		[ 'NaN', NaN ],
		[ 'Infinity', Infinity ],
		[ 'a string', '1766000000000' ],
	] )( 'refuses an enabled schedule when nowMs is %s', ( label, nowMs ) => {
		expect( scheduleAllows( FRIDAY_NIGHT, nowMs, SITE_ZONE ) ).toBe(
			false
		);
	} );
} );

describe( 'scheduleAllows -> campaign range', () => {
	/**
	 * Start of the worked campaign from the data model.
	 *
	 * @type {number}
	 */
	const START = at( '2026-11-30T00:00:00', UTC );

	/**
	 * End of the worked campaign from the data model.
	 *
	 * @type {number}
	 */
	const END = at( '2026-12-03T04:59:59', UTC );

	it( 'treats both boundaries as inclusive', () => {
		const schedule = campaign(
			'2026-11-30T00:00:00Z',
			'2026-12-03T04:59:59Z'
		);

		expect( scheduleAllows( schedule, START - 1, SITE_ZONE ) ).toBe(
			false
		);
		expect( scheduleAllows( schedule, START, SITE_ZONE ) ).toBe( true );
		expect( scheduleAllows( schedule, END, SITE_ZONE ) ).toBe( true );
		expect( scheduleAllows( schedule, END + 1, SITE_ZONE ) ).toBe( false );
	} );

	it( 'accepts an open-ended start', () => {
		const schedule = campaign( null, '2026-12-03T04:59:59Z' );

		expect(
			scheduleAllows(
				schedule,
				at( '1999-01-01T00:00:00', UTC ),
				SITE_ZONE
			)
		).toBe( true );
		expect( scheduleAllows( schedule, END + 1, SITE_ZONE ) ).toBe( false );
	} );

	it( 'accepts an open-ended end', () => {
		const schedule = campaign( '2026-11-30T00:00:00Z', null );

		expect( scheduleAllows( schedule, START - 1, SITE_ZONE ) ).toBe(
			false
		);
		expect(
			scheduleAllows(
				schedule,
				at( '2099-01-01T00:00:00', UTC ),
				SITE_ZONE
			)
		).toBe( true );
	} );

	it( 'accepts a campaign that is open-ended at both ends', () => {
		expect(
			scheduleAllows( campaign( null, null ), START, SITE_ZONE )
		).toBe( true );
	} );

	it( 'reads a boundary with no zone designator as UTC', () => {
		// A local reading would resolve to a different instant for every
		// visitor, which is the visitor-varying behavior the plugin forbids.
		const schedule = campaign( '2026-11-30T00:00:00', null );

		expect( scheduleAllows( schedule, START - 1, SITE_ZONE ) ).toBe(
			false
		);
		expect( scheduleAllows( schedule, START, SITE_ZONE ) ).toBe( true );
	} );

	it.each( [
		[ 'an unparseable start', campaign( 'next Tuesday', null ) ],
		[ 'an unparseable end', campaign( null, 'soon' ) ],
		[ 'a numeric start', campaign( 1766000000000, null ) ],
		[ 'a numeric end', campaign( null, 1766000000000 ) ],
	] )( 'fails closed on %s', ( label, schedule ) => {
		expect( scheduleAllows( schedule, START, SITE_ZONE ) ).toBe( false );
	} );
} );

describe( 'scheduleAllows -> window crossing midnight', () => {
	// The worked example from docs/data-model.md, extended at both ends.
	// 2026-08-21 is a Friday; 2026-08-22 a Saturday; 2026-08-23 a Sunday.
	it.each( [
		[ 'Friday 00:00', at( '2026-08-21T00:00:00', EDT ), false ],
		[ 'Friday 01:00', at( '2026-08-21T01:00:00', EDT ), false ],
		[ 'Friday 01:59', at( '2026-08-21T01:59:00', EDT ), false ],
		[ 'Friday 02:00', at( '2026-08-21T02:00:00', EDT ), false ],
		[ 'Friday 21:59', at( '2026-08-21T21:59:00', EDT ), false ],
		[ 'Friday 22:00', at( '2026-08-21T22:00:00', EDT ), true ],
		[ 'Friday 23:59', at( '2026-08-21T23:59:00', EDT ), true ],
		[ 'Saturday 00:00', at( '2026-08-22T00:00:00', EDT ), true ],
		[ 'Saturday 01:59', at( '2026-08-22T01:59:00', EDT ), true ],
		[ 'Saturday 02:00', at( '2026-08-22T02:00:00', EDT ), false ],
		[ 'Saturday 22:00', at( '2026-08-22T22:00:00', EDT ), false ],
		[ 'Sunday 01:00', at( '2026-08-23T01:00:00', EDT ), false ],
	] )( 'Friday 22:00-02:00 at %s', ( label, nowMs, expected ) => {
		expect( scheduleAllows( FRIDAY_NIGHT, nowMs, SITE_ZONE ) ).toBe(
			expected
		);
	} );

	it( 'covers Friday 01:00 only once Thursday is listed too', () => {
		// The Friday 01:00 row above is false because that hour belongs to
		// Thursday's window. Listing Thursday is what turns it on — proof the
		// module keys the morning portion off the *previous* day.
		const withThursday = recurring(
			[ 4, 5 ],
			[ { from: '22:00', to: '02:00' } ]
		);

		expect(
			scheduleAllows(
				withThursday,
				at( '2026-08-21T01:00:00', EDT ),
				SITE_ZONE
			)
		).toBe( true );
	} );

	it( 'behaves identically under standard time', () => {
		// The same table in December, when New York is on EST. A crossing
		// window is a property of local wall-clock time, not of the UTC offset
		// that happens to be in force. 2026-12-04 is a Friday.
		expect(
			scheduleAllows(
				FRIDAY_NIGHT,
				at( '2026-12-04T21:59:00', EST ),
				SITE_ZONE
			)
		).toBe( false );
		expect(
			scheduleAllows(
				FRIDAY_NIGHT,
				at( '2026-12-04T22:00:00', EST ),
				SITE_ZONE
			)
		).toBe( true );
		expect(
			scheduleAllows(
				FRIDAY_NIGHT,
				at( '2026-12-05T01:59:00', EST ),
				SITE_ZONE
			)
		).toBe( true );
		expect(
			scheduleAllows(
				FRIDAY_NIGHT,
				at( '2026-12-05T02:00:00', EST ),
				SITE_ZONE
			)
		).toBe( false );
		expect(
			scheduleAllows(
				FRIDAY_NIGHT,
				at( '2026-12-04T01:00:00', EST ),
				SITE_ZONE
			)
		).toBe( false );
	} );

	it( 'wraps from Sunday into Monday', () => {
		// Exercises the weekday-1 wrap, where the previous day of Monday is
		// Sunday rather than day zero.
		const sundayNight = recurring(
			[ 7 ],
			[ { from: '23:00', to: '01:00' } ]
		);

		expect(
			scheduleAllows(
				sundayNight,
				at( '2026-08-23T23:30:00', EDT ),
				SITE_ZONE
			)
		).toBe( true );
		expect(
			scheduleAllows(
				sundayNight,
				at( '2026-08-24T00:30:00', EDT ),
				SITE_ZONE
			)
		).toBe( true );
		expect(
			scheduleAllows(
				sundayNight,
				at( '2026-08-23T00:30:00', EDT ),
				SITE_ZONE
			)
		).toBe( false );
		expect(
			scheduleAllows(
				sundayNight,
				at( '2026-08-24T23:30:00', EDT ),
				SITE_ZONE
			)
		).toBe( false );
	} );
} );

describe( 'scheduleAllows -> window boundaries', () => {
	// 2026-08-19 is a Wednesday.
	it.each( [
		[ '08:59, before the inclusive start', '08:59:00', false ],
		[ '09:00, the inclusive start', '09:00:00', true ],
		[ '09:00:00.001, just inside', '09:00:00.001', true ],
		[ '16:59:59.999, just inside the exclusive end', '16:59:59.999', true ],
		[ '17:00, the exclusive end', '17:00:00', false ],
		[ '17:01, past the end', '17:01:00', false ],
	] )( '09:00-17:00 at %s', ( label, time, expected ) => {
		const nowMs = at( `2026-08-19T${ time }`, EDT );

		expect( scheduleAllows( OFFICE_HOURS, nowMs, SITE_ZONE ) ).toBe(
			expected
		);
	} );

	it.each( [
		[ 'before it', '08:59:00' ],
		[ 'exactly on it', '09:00:00' ],
		[ 'just after it', '09:01:00' ],
		[ 'at midnight', '00:00:00' ],
	] )( 'never matches a zero-length window %s', ( label, time ) => {
		const zeroLength = recurring( [], [ { from: '09:00', to: '09:00' } ] );

		expect(
			scheduleAllows(
				zeroLength,
				at( `2026-08-19T${ time }`, EDT ),
				SITE_ZONE
			)
		).toBe( false );
	} );

	it.each( [
		[ 'an unparseable from', { from: 'x:00', to: '17:00' } ],
		[ 'an unparseable to', { from: '09:00', to: 'noon' } ],
		[ 'an out-of-range hour', { from: '24:00', to: '25:00' } ],
		[ 'an out-of-range minute', { from: '09:60', to: '17:00' } ],
		[ 'a fractional hour', { from: '9.5:00', to: '17:00' } ],
		[ 'a missing to', { from: '09:00' } ],
		[ 'a missing from', { to: '17:00' } ],
		[ 'a null entry', null ],
		[ 'a numeric from', { from: 900, to: 1700 } ],
	] )( 'never matches a window with %s', ( label, entry ) => {
		const broken = recurring( [], [ entry ] );

		expect(
			scheduleAllows(
				broken,
				at( '2026-08-19T12:00:00', EDT ),
				SITE_ZONE
			)
		).toBe( false );
	} );
} );

describe( 'scheduleAllows -> weekday selection', () => {
	it.each( [
		[ 'Wednesday', '2026-08-19' ],
		[ 'Saturday', '2026-08-22' ],
		[ 'Sunday', '2026-08-23' ],
	] )(
		'treats an empty days list as every day, including %s',
		( label, date ) => {
			expect(
				scheduleAllows(
					OFFICE_HOURS,
					at( `${ date }T10:00:00`, EDT ),
					SITE_ZONE
				)
			).toBe( true );
		}
	);

	it( 'excludes a weekday that is not listed', () => {
		const weekdays = recurring(
			[ 1, 2, 3, 4, 5 ],
			[ { from: '09:00', to: '17:00' } ]
		);

		expect(
			scheduleAllows(
				weekdays,
				at( '2026-08-19T10:00:00', EDT ),
				SITE_ZONE
			)
		).toBe( true );
		expect(
			scheduleAllows(
				weekdays,
				at( '2026-08-22T10:00:00', EDT ),
				SITE_ZONE
			)
		).toBe( false );
	} );

	it( 'accepts weekdays emitted as numeric strings', () => {
		const friday = recurring( [ '5' ], [ { from: '22:00', to: '23:00' } ] );

		expect(
			scheduleAllows(
				friday,
				at( '2026-08-21T22:30:00', EDT ),
				SITE_ZONE
			)
		).toBe( true );
	} );

	it( 'allows any time of day when there are no windows', () => {
		const wednesdays = recurring( [ 3 ], [] );

		expect(
			scheduleAllows(
				wednesdays,
				at( '2026-08-19T03:00:00', EDT ),
				SITE_ZONE
			)
		).toBe( true );
		expect(
			scheduleAllows(
				wednesdays,
				at( '2026-08-20T03:00:00', EDT ),
				SITE_ZONE
			)
		).toBe( false );
	} );

	it.each( [
		[ 'no recurrence key', campaign( null, null ) ],
		[
			'a null recurrence',
			{ ...campaign( null, null ), recurrence: null },
		],
		[ 'empty days and windows', recurring( [], [] ) ],
		[ 'non-array days', recurring( 'weekdays', [] ) ],
		[ 'non-array windows', recurring( [], 'always' ) ],
	] )( 'imposes no recurrence constraint given %s', ( label, schedule ) => {
		expect(
			scheduleAllows(
				schedule,
				at( '2026-08-22T03:17:00', EDT ),
				SITE_ZONE
			)
		).toBe( true );
	} );
} );

describe( 'scheduleAllows -> timezone handling', () => {
	it( 'evaluates the same instant differently in different site zones', () => {
		// 13:00 UTC is 09:00 in New York, 14:00 in London, 13:00 in UTC itself.
		const nowMs = at( '2026-08-19T13:00:00', UTC );
		const morning = recurring( [], [ { from: '09:00', to: '10:00' } ] );

		expect( scheduleAllows( morning, nowMs, SITE_ZONE ) ).toBe( true );
		expect( scheduleAllows( morning, nowMs, 'Europe/London' ) ).toBe(
			false
		);
		expect( scheduleAllows( morning, nowMs, 'UTC' ) ).toBe( false );
	} );

	it.each( [
		[ 'an unrecognized zone', 'Not/AZone' ],
		[ 'an empty string', '' ],
		[ 'undefined', undefined ],
		[ 'null', null ],
		[ 'a non-string', 42 ],
	] )( 'fails a site-scheduled popup closed given %s', ( label, zone ) => {
		// Falling back to the visitor's own zone here would silently run a
		// site-scheduled campaign on visitor time.
		expect(
			scheduleAllows(
				OFFICE_HOURS,
				at( '2026-08-19T13:00:00', UTC ),
				zone
			)
		).toBe( false );

		// And it says so. An unresolvable zone suppresses every scheduled popup
		// on the site while the markup is still emitted correctly, so without
		// this the owner sees a plugin that does nothing and has nothing to
		// search for.
		expect( console ).toHaveWarned();
	} );

	it( 'ignores the site zone entirely when the schedule follows the visitor', () => {
		// Two windows that between them cover every minute of the day, so the
		// assertion holds whatever zone the test runner resolves to.
		const allDay = recurring(
			[],
			[
				{ from: '00:00', to: '12:00' },
				{ from: '12:00', to: '00:00' },
			],
			'visitor'
		);

		expect(
			scheduleAllows(
				allDay,
				at( '2026-08-19T13:00:00', UTC ),
				'Not/AZone'
			)
		).toBe( true );
	} );
} );

describe( 'scheduleAllows -> a site timezone given as a UTC offset', () => {
	// WordPress -> Settings -> General offers "UTC+5.5" beside the named zones,
	// and `wp_timezone_string()` answers that choice with the offset itself. A
	// site set that way is not misconfigured, and every one of its scheduled
	// popups would never show if the offset were treated as an unusable zone.
	it( 'reads a whole-hour offset as the zone it stands for', () => {
		// 13:00 UTC is 09:00 at -04:00, which is New York in August.
		const nowMs = at( '2026-08-19T13:00:00', UTC );

		expect( scheduleAllows( OFFICE_HOURS, nowMs, '-04:00' ) ).toBe( true );
		expect( scheduleAllows( OFFICE_HOURS, nowMs, SITE_ZONE ) ).toBe( true );
		expect( console ).not.toHaveWarned();
	} );

	it( 'reads a half-hour offset to the minute', () => {
		// 13:00 UTC is 18:30 at +05:30 and 18:00 at +05:00. The window is narrow
		// enough that dropping the half hour would be visible.
		const nowMs = at( '2026-08-19T13:00:00', UTC );
		const evening = recurring( [], [ { from: '18:30', to: '18:45' } ] );

		expect( scheduleAllows( evening, nowMs, '+05:30' ) ).toBe( true );
		expect( scheduleAllows( evening, nowMs, '+05:00' ) ).toBe( false );
	} );

	it( 'treats +00:00 as UTC', () => {
		const nowMs = at( '2026-08-19T13:00:00', UTC );
		const afternoon = recurring( [], [ { from: '13:00', to: '14:00' } ] );

		expect( scheduleAllows( afternoon, nowMs, '+00:00' ) ).toBe( true );
	} );

	it( 'derives the weekday from the shifted date, not from UTC', () => {
		// 02:00 UTC on Wednesday is 22:00 on Tuesday at -04:00.
		const nowMs = at( '2026-08-19T02:00:00', UTC );
		const lateTuesday = recurring(
			[ 2 ],
			[ { from: '22:00', to: '23:00' } ]
		);
		const lateWednesday = recurring(
			[ 3 ],
			[ { from: '22:00', to: '23:00' } ]
		);

		expect( scheduleAllows( lateTuesday, nowMs, '-04:00' ) ).toBe( true );
		expect( scheduleAllows( lateWednesday, nowMs, '-04:00' ) ).toBe(
			false
		);
	} );

	it( 'applies no daylight saving, because an offset carries no rules', () => {
		// A site that picked "UTC-5" rather than "New York" gets 08:00 here all
		// year, where the named zone gets 09:00 in August. Following the named
		// zone's DST would be inventing a rule the setting does not contain.
		const nowMs = at( '2026-08-19T13:00:00', UTC );

		expect( scheduleAllows( OFFICE_HOURS, nowMs, '-05:00' ) ).toBe( false );
		expect( scheduleAllows( OFFICE_HOURS, nowMs, SITE_ZONE ) ).toBe( true );
	} );

	// `+05` and other shorter forms are deliberately absent: engines new enough
	// to have the Temporal timezone grammar accept them and older ones do not,
	// and `wp_timezone_string()` never emits one, so pinning either answer would
	// assert a property of the test runner.
	it.each( [
		[ 'a bare sign', '+' ],
		[ 'an offset-shaped name', '-hello:00' ],
		[ 'an out-of-range hour', '+99:00' ],
	] )( 'still fails closed, and warns, given %s', ( label, zone ) => {
		expect(
			scheduleAllows(
				OFFICE_HOURS,
				at( '2026-08-19T13:00:00', UTC ),
				zone
			)
		).toBe( false );
		expect( console ).toHaveWarned();
	} );
} );

describe( 'scheduleAllows -> daylight saving transitions', () => {
	// New York springs forward on 2026-03-08: 02:00 EST becomes 03:00 EDT, so
	// the local hour 02:00-02:59 does not occur at all that day.
	it( 'never matches a window inside the hour that spring-forward skips', () => {
		const skipped = recurring( [ 7 ], [ { from: '02:00', to: '03:00' } ] );

		expect(
			scheduleAllows(
				skipped,
				at( '2026-03-08T06:59:00', UTC ),
				SITE_ZONE
			)
		).toBe( false );
		expect(
			scheduleAllows(
				skipped,
				at( '2026-03-08T07:00:00', UTC ),
				SITE_ZONE
			)
		).toBe( false );
		expect(
			scheduleAllows(
				skipped,
				at( '2026-03-08T07:30:00', UTC ),
				SITE_ZONE
			)
		).toBe( false );
	} );

	it( 'matches either side of the spring-forward gap', () => {
		const before = recurring( [ 7 ], [ { from: '01:00', to: '02:00' } ] );
		const after = recurring( [ 7 ], [ { from: '03:00', to: '04:00' } ] );

		// 06:30 UTC is 01:30 EST, the last half hour before the jump.
		expect(
			scheduleAllows(
				before,
				at( '2026-03-08T06:30:00', UTC ),
				SITE_ZONE
			)
		).toBe( true );

		// 07:30 UTC is 03:30 EDT, immediately after it.
		expect(
			scheduleAllows( after, at( '2026-03-08T07:30:00', UTC ), SITE_ZONE )
		).toBe( true );
	} );

	// New York falls back on 2026-11-01: 02:00 EDT becomes 01:00 EST, so the
	// local hour 01:00-01:59 occurs twice.
	it( 'matches on both passes through a repeated fall-back hour', () => {
		const repeated = recurring( [ 7 ], [ { from: '01:00', to: '02:00' } ] );

		// 05:30 UTC is 01:30 EDT — the first pass.
		expect(
			scheduleAllows(
				repeated,
				at( '2026-11-01T05:30:00', UTC ),
				SITE_ZONE
			)
		).toBe( true );

		// 06:30 UTC is 01:30 EST — the second.
		expect(
			scheduleAllows(
				repeated,
				at( '2026-11-01T06:30:00', UTC ),
				SITE_ZONE
			)
		).toBe( true );

		// 07:30 UTC is 02:30 EST, past the window on both readings.
		expect(
			scheduleAllows(
				repeated,
				at( '2026-11-01T07:30:00', UTC ),
				SITE_ZONE
			)
		).toBe( false );
	} );

	it( 'tracks local time across the offset change, not the UTC offset', () => {
		// The same local window sits an hour apart in UTC either side of the
		// transition. A hand-rolled fixed offset would get one of these wrong.
		expect(
			scheduleAllows(
				OFFICE_HOURS,
				at( '2026-03-07T13:59:00', UTC ),
				SITE_ZONE
			)
		).toBe( false );
		expect(
			scheduleAllows(
				OFFICE_HOURS,
				at( '2026-03-07T14:00:00', UTC ),
				SITE_ZONE
			)
		).toBe( true );
		expect(
			scheduleAllows(
				OFFICE_HOURS,
				at( '2026-03-09T12:59:00', UTC ),
				SITE_ZONE
			)
		).toBe( false );
		expect(
			scheduleAllows(
				OFFICE_HOURS,
				at( '2026-03-09T13:00:00', UTC ),
				SITE_ZONE
			)
		).toBe( true );
	} );

	it( 'applies the campaign range and the recurrence together', () => {
		// Both stages must pass: a Friday night inside the campaign shows, the
		// same weekday and hour outside it does not.
		const schedule = {
			...FRIDAY_NIGHT,
			start: '2026-08-21T00:00:00Z',
			end: '2026-08-23T00:00:00Z',
		};

		expect(
			scheduleAllows(
				schedule,
				at( '2026-08-21T22:30:00', EDT ),
				SITE_ZONE
			)
		).toBe( true );
		expect(
			scheduleAllows(
				schedule,
				at( '2026-08-28T22:30:00', EDT ),
				SITE_ZONE
			)
		).toBe( false );
	} );
} );
