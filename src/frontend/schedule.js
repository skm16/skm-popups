/**
 * popkit — schedule evaluation.
 *
 * Pipeline stage 8 (docs/CLAUDE.md -> Architecture invariants): given an
 * authoritative timestamp, decide whether a popup's campaign window and weekly
 * recurrence permit it to show.
 *
 * Two properties of this module matter more than anything it computes.
 *
 * 1. **It never reads the device wall clock.** `nowMs` is supplied by the
 *    caller from the offset-corrected monotonic clock in clock.js, calibrated
 *    once against the context endpoint. Reading the browser's own wall-clock
 *    API here would reintroduce precisely the skew that offset exists to
 *    remove: a visitor whose clock runs eight hours fast, or who changes it
 *    after the page has loaded, would evaluate a different schedule from
 *    everybody else. A repository grep asserts that API's absence from this
 *    file — see docs/build-plan.md -> Phase 2, "Clock correctness" — so it must
 *    not appear even in a comment.
 *
 * 2. **It is called once, at page load, never on an interval.** A popup that
 *    disappears mid-interaction because its window just closed is a worse
 *    failure than one that outstays its schedule by a few minutes.
 *
 * Everything here is a pure function of its arguments, save for one console
 * diagnostic described at {@link warnUnusableZone}. There is no state, no DOM
 * access, and no storage access.
 *
 * @see docs/data-model.md -> Schedule
 * @see docs/CLAUDE.md -> Cache safety
 */

/**
 * Minutes in an hour, named so the arithmetic below reads as arithmetic.
 *
 * @type {number}
 */
const MINUTES_PER_HOUR = 60;

/**
 * Highest permitted hour in a recurrence window.
 *
 * @type {number}
 */
const MAX_HOUR = 23;

/**
 * Highest permitted minute in a recurrence window.
 *
 * @type {number}
 */
const MAX_MINUTE = 59;

/**
 * Number of days in a week, used to step back one weekday.
 *
 * @type {number}
 */
const DAYS_PER_WEEK = 7;

/**
 * Milliseconds in a minute, for shifting an instant by a fixed UTC offset.
 *
 * @type {number}
 */
const MS_PER_MINUTE = 60000;

/**
 * Adds a UTC designator to an ISO 8601 timestamp that omits one.
 *
 * ECMAScript parses a date-time carrying no `Z` and no numeric offset as
 * *local* time, so such a value would resolve to a different instant for every
 * visitor — exactly the visitor-varying behavior the constitution exists to
 * prevent. `data-model.md` declares `start` and `end` to be UTC and the PHP
 * side stores them as `Y-m-d\TH:i:s\Z`, so a missing designator can only be an
 * emitter slip; normalizing is preferable to rejecting, because silently
 * killing a live campaign over a punctuation difference is the worse failure.
 *
 * A date-only value such as `2026-11-30` already parses as UTC and is returned
 * untouched.
 *
 * @param {string} value ISO 8601 timestamp.
 * @return {string} The timestamp, guaranteed to carry a zone designator.
 */
function normalizeUtc( value ) {
	const timeAt = value.indexOf( 'T' );

	if ( 0 > timeAt ) {
		return value;
	}

	// Only search for a sign after the `T`, so the hyphens in the date part are
	// not mistaken for a negative offset.
	if (
		value.endsWith( 'Z' ) ||
		value.includes( '+', timeAt ) ||
		value.includes( '-', timeAt )
	) {
		return value;
	}

	return value + 'Z';
}

/**
 * Parses one end of the campaign range.
 *
 * @param {*} value ISO 8601 UTC timestamp, or null for an open-ended boundary.
 * @return {number|null} Epoch milliseconds; null when the boundary is
 *                       open-ended; NaN when a value is present but
 *                       unparseable.
 */
function boundary( value ) {
	if ( null === value || undefined === value || '' === value ) {
		return null;
	}

	if ( 'string' !== typeof value ) {
		return NaN;
	}

	return Date.parse( normalizeUtc( value ) );
}

/**
 * Tests whether the moment falls inside the campaign range.
 *
 * `start` and `end` are both inclusive, and either may be null for an
 * open-ended campaign.
 *
 * @param {Object} schedule The popup's schedule object.
 * @param {number} nowMs    Authoritative epoch milliseconds.
 * @return {boolean} True when the moment is within the range.
 */
function withinCampaign( schedule, nowMs ) {
	const start = boundary( schedule.start );
	const end = boundary( schedule.end );

	// An unparseable boundary leaves the campaign's extent unknown. Failing
	// closed is the same call the constitution makes for a failed context
	// fetch: showing an expired campaign is worse than showing nothing.
	if ( Number.isNaN( start ) || Number.isNaN( end ) ) {
		return false;
	}

	if ( null !== start && nowMs < start ) {
		return false;
	}

	return null === end || nowMs <= end;
}

/**
 * Converts a local `HH:MM` window boundary to minutes past local midnight.
 *
 * Parsed by splitting rather than by pattern match. The grammar is five
 * characters wide, a split is linear and obviously bounded, and
 * `docs/CLAUDE.md` -> Security rules out matching author-supplied values with
 * regular expressions.
 *
 * An unreadable boundary yields null, and a window with a null boundary never
 * matches — which can only narrow the schedule, never widen it.
 *
 * @param {*} value Window boundary as stored.
 * @return {number|null} Minutes past midnight, or null when unparseable.
 */
function toMinutes( value ) {
	if ( 'string' !== typeof value ) {
		return null;
	}

	const parts = value.split( ':' );

	if ( 2 !== parts.length || '' === parts[ 0 ] || '' === parts[ 1 ] ) {
		return null;
	}

	const hours = Number( parts[ 0 ] );
	const minutes = Number( parts[ 1 ] );

	if (
		! Number.isInteger( hours ) ||
		! Number.isInteger( minutes ) ||
		0 > hours ||
		MAX_HOUR < hours ||
		0 > minutes ||
		MAX_MINUTE < minutes
	) {
		return null;
	}

	return hours * MINUTES_PER_HOUR + minutes;
}

/**
 * Reads a site timezone written as a fixed UTC offset, such as `+05:30`.
 *
 * **A site timezone is not always an IANA name.** WordPress -> Settings ->
 * General offers "UTC+5.5" alongside the named zones, and `wp_timezone_string()`
 * answers a manual selection with the offset itself, formatted `±HH:MM` — so the
 * emitted `siteTimezone` is legitimately either shape. `Intl.DateTimeFormat`
 * accepts offset identifiers only on engines new enough to have shipped the
 * Temporal-era timezone grammar; on the rest it throws a RangeError, and this
 * module's fail-closed answer to an unusable zone would then suppress every
 * scheduled popup on the site, on every browser that was slightly behind.
 * Resolving the offset here removes the engine from the question entirely.
 *
 * An offset is discriminated by its leading sign, which no IANA name carries.
 * `toMinutes` does the parse, because `HH:MM` past midnight and `HH:MM` past UTC
 * are the same five characters and the same arithmetic; that also keeps the
 * split-not-match rule of `docs/CLAUDE.md` -> Security in one place. Its 23-hour
 * ceiling is wider than any offset in use — the widest real one is `+14:00` —
 * but the value is a site setting written by WordPress rather than by a visitor,
 * and a nonsensical one can only shift that site's own schedule.
 *
 * @param {string} value Site timezone as emitted.
 * @return {number|null} Minutes east of UTC, or null when this is not an offset.
 */
function offsetMinutes( value ) {
	const sign = value.charAt( 0 );

	if ( '+' !== sign && '-' !== sign ) {
		return null;
	}

	const magnitude = toMinutes( value.slice( 1 ) );

	if ( null === magnitude ) {
		return null;
	}

	return '-' === sign ? -magnitude : magnitude;
}

/**
 * Resolves the weekday and local time of a moment at a fixed UTC offset.
 *
 * No daylight saving is applied, and that is the correct reading rather than a
 * limitation: an administrator who set a manual UTC offset instead of choosing a
 * city declined to tell WordPress which DST rules apply, and there is no zone to
 * infer them from. `wp_timezone()` takes the same position on the PHP side.
 *
 * @param {number} nowMs  Authoritative epoch milliseconds.
 * @param {number} offset Minutes east of UTC.
 * @return {Object} `{ weekday, minutes }` where weekday is 1 (Monday) to 7.
 */
function offsetParts( nowMs, offset ) {
	const shifted = new Date( nowMs + offset * MS_PER_MINUTE );

	return {
		// getUTCDay() counts 0 for Sunday; ISO 8601 counts 1 for Monday.
		weekday: ( ( shifted.getUTCDay() + 6 ) % DAYS_PER_WEEK ) + 1,
		minutes:
			shifted.getUTCHours() * MINUTES_PER_HOUR + shifted.getUTCMinutes(),
	};
}

/**
 * Warns that a site timezone cannot be resolved, and says what it costs.
 *
 * The only impurity in this module, and it earns its place. An unresolvable zone
 * makes every scheduled popup on the site fail closed forever: no popup appears,
 * no error is raised, and the markup is emitted correctly, so the site owner
 * sees a plugin that simply does not work and has nothing to search for. A
 * fail-closed decision that nobody can observe is indistinguishable from a bug,
 * and this is the one place in the schedule where the cause is a site setting an
 * administrator can actually fix.
 *
 * Warned on every unresolvable evaluation rather than once, so the module stays
 * free of module-level state and the count matches the number of popups
 * suppressed. Evaluation runs once per popup per pageview and never on an
 * interval, so the volume is bounded by the page.
 *
 * Untranslated for the reason given in `api.js`: `@wordpress/i18n` is not
 * available to this bundle, and this is a console diagnostic naming a stored
 * setting value.
 *
 * @param {*} value Site timezone as emitted.
 * @return {void}
 */
function warnUnusableZone( value ) {
	// eslint-disable-next-line no-console -- A silent fail-closed here suppresses every scheduled popup on the site with no symptom an owner could search for.
	console.warn(
		`popkit: the site timezone ${ JSON.stringify(
			value
		) } is neither an IANA zone name nor a UTC offset such as "+05:30", so every popup with a schedule is suppressed. Check Settings -> General -> Timezone.`
	);
}

/**
 * Resolves the ISO weekday and minutes past midnight of a moment, in a zone.
 *
 * `Intl.DateTimeFormat` is given an explicit `timeZone` rather than offsets
 * being computed by hand: it is the only thing in the platform that knows when
 * a zone's offset changed, so daylight saving is handled by formatting the
 * actual instant. During a spring-forward gap the skipped local hour simply
 * never occurs and a window inside it does not match that day; during a
 * fall-back repeat the hour occurs twice and matches on both passes. Both are
 * the correct readings of "09:00 local".
 *
 * The weekday is derived from the formatted calendar date rather than from a
 * localized weekday name, so no assumption is made about how any locale spells
 * `Mon`. `hourCycle: 'h23'` keeps midnight at hour 0; the modulo is a cheap
 * guard against older engines that report hour 24 instead.
 *
 * A `±HH:MM` site setting is resolved arithmetically before the formatter is
 * consulted, so the two zone shapes behave identically on every engine. See
 * {@link offsetMinutes}.
 *
 * @param {number}           nowMs    Authoritative epoch milliseconds.
 * @param {string|undefined} timeZone IANA zone or `±HH:MM` UTC offset, or
 *                                    undefined for the browser's own resolved
 *                                    zone.
 * @return {Object|null} `{ weekday, minutes }` where weekday is 1 (Monday)
 *                       through 7 (Sunday), or null when the zone is unusable.
 */
function localParts( nowMs, timeZone ) {
	if ( 'string' === typeof timeZone ) {
		const offset = offsetMinutes( timeZone );

		if ( null !== offset ) {
			return offsetParts( nowMs, offset );
		}
	}

	let parts;

	try {
		parts = new Intl.DateTimeFormat( 'en-US', {
			timeZone,
			hourCycle: 'h23',
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: '2-digit',
			minute: '2-digit',
		} ).formatToParts( new Date( nowMs ) );
	} catch {
		// An unrecognized zone identifier throws a RangeError. There is no
		// honest fallback: guessing UTC would run the campaign at the wrong
		// hour, so the schedule fails closed with the caller — loudly, because
		// nothing else on the page would show that it had.
		warnUnusableZone( timeZone );

		return null;
	}

	const values = {};

	for ( const part of parts ) {
		values[ part.type ] = part.value;
	}

	const year = Number( values.year );
	const month = Number( values.month );
	const day = Number( values.day );
	const hour = Number( values.hour ) % 24;
	const minute = Number( values.minute );

	if ( ! Number.isFinite( year + month + day + hour + minute ) ) {
		warnUnusableZone( timeZone );

		return null;
	}

	// getUTCDay() counts 0 for Sunday; ISO 8601 counts 1 for Monday.
	const weekday =
		( ( new Date( Date.UTC( year, month - 1, day ) ).getUTCDay() + 6 ) %
			DAYS_PER_WEEK ) +
		1;

	return {
		weekday,
		minutes: hour * MINUTES_PER_HOUR + minute,
	};
}

/**
 * Tests whether a weekday is selected.
 *
 * An empty list means every day, per `data-model.md` -> Schedule.
 *
 * @param {Array}  days    Selected ISO weekdays.
 * @param {number} weekday ISO weekday to test, 1 (Monday) through 7 (Sunday).
 * @return {boolean} True when the weekday is selected.
 */
function isListed( days, weekday ) {
	if ( 0 === days.length ) {
		return true;
	}

	return days.some( ( day ) => Number( day ) === weekday );
}

/**
 * Tests one recurrence window against the local weekday and time.
 *
 * **A window belongs to its starting day.** `from` is inclusive and `to` is
 * exclusive, and the three cases are exactly as specified:
 *
 * - `from < to` — an ordinary window. The weekday must be selected and the
 *   time must satisfy `from <= time < to`.
 * - `to < from` — the window crosses midnight. It matches either the evening
 *   portion, when the weekday is selected and `time >= from`, or the morning
 *   portion, when *the previous day* is selected and `time < to`.
 * - `from === to` — zero length. Never matches; the editor warns about it.
 *
 * The alternative reading — that a crossing window applies to both calendar
 * days it touches — would make "Friday night, 22:00–02:00" also cover Friday
 * 00:00–02:00, showing the popup roughly a day before the author intended.
 *
 * @param {Object} entry Window with `from` and `to` as local `HH:MM`.
 * @param {Object} local Resolved `{ weekday, minutes }` for the moment.
 * @param {Array}  days  Selected ISO weekdays.
 * @return {boolean} True when the window is active.
 */
function windowMatches( entry, local, days ) {
	const from = toMinutes( entry?.from );
	const to = toMinutes( entry?.to );

	if ( null === from || null === to || from === to ) {
		return false;
	}

	if ( from < to ) {
		return (
			isListed( days, local.weekday ) &&
			local.minutes >= from &&
			local.minutes < to
		);
	}

	const previous = 1 === local.weekday ? DAYS_PER_WEEK : local.weekday - 1;

	return (
		( isListed( days, local.weekday ) && local.minutes >= from ) ||
		( isListed( days, previous ) && local.minutes < to )
	);
}

/**
 * Decides whether a popup's schedule permits it to show at a given moment.
 *
 * Visible when the moment falls within `[start, end]` **and** the recurrence is
 * absent **or** the weekday is selected **and** the time falls within a window.
 *
 * Two deliberate asymmetries:
 *
 * - A schedule that is absent or disabled imposes no restriction and returns
 *   true without consulting `nowMs`, so a popup with no schedule never depends
 *   on the context endpoint.
 * - An enabled schedule with no usable `nowMs` returns **false**. That is the
 *   fail-closed path for a context fetch that failed, timed out, or returned a
 *   malformed payload: a live campaign missing a few impressions during an
 *   outage is recoverable, an expired one running again is not.
 *
 * ## A recurrence with no windows means any time of day
 *
 * `windows: []` is the *default stored shape* — it is what `default_schedule()`
 * emits and what every author who sets only a start and end date leaves behind.
 * Reading "the time must fall within a window" literally against an empty list
 * would make that vacuously false and silently suppress every plain date-range
 * campaign on every site, which is the single most common way this feature is
 * used. Days and windows are independent narrowings of a recurrence, and an
 * empty list of either imposes no narrowing: `days: []` already means every day
 * by the same reading, as `data-model.md` -> Schedule states in as many words.
 *
 * The PHP sanitizer agrees and is built around it — it preserves a zero-length
 * window rather than dropping it, precisely so that "no windows" cannot be
 * reached by accident from "one window the author has not finished typing".
 *
 * @param {Object|null|undefined} schedule     Schedule object from the emitted
 *                                             config.
 * @param {number|null}           nowMs        Authoritative epoch
 *                                             milliseconds from the corrected
 *                                             clock, or null when context is
 *                                             unavailable.
 * @param {string}                siteTimezone `wp_timezone_string()` from the
 *                                             emitted config: an IANA zone name
 *                                             or a `±HH:MM` UTC offset, both
 *                                             supported. A site setting, so it
 *                                             is identical for every visitor
 *                                             and cache-safe.
 * @return {boolean} True when the schedule allows the popup to show.
 */
export function scheduleAllows( schedule, nowMs, siteTimezone ) {
	if ( ! schedule || ! schedule.enabled ) {
		return true;
	}

	if ( ! Number.isFinite( nowMs ) ) {
		return false;
	}

	if ( ! withinCampaign( schedule, nowMs ) ) {
		return false;
	}

	const recurrence = schedule.recurrence;

	if ( ! recurrence || 'object' !== typeof recurrence ) {
		return true;
	}

	const useVisitorZone = 'visitor' === schedule.timezone;
	const timeZone = useVisitorZone ? undefined : siteTimezone;

	// `undefined` makes the formatter use the browser's resolved zone, which is
	// what `timezone: "visitor"` asks for. It must never be reached by way of a
	// missing site zone, or a site-scheduled campaign would silently run on
	// visitor time. An absent zone kills the same campaigns an unreadable one
	// does, so it is reported the same way.
	if (
		! useVisitorZone &&
		( 'string' !== typeof timeZone || '' === timeZone )
	) {
		warnUnusableZone( timeZone );

		return false;
	}

	const local = localParts( nowMs, timeZone );

	if ( null === local ) {
		return false;
	}

	const days = Array.isArray( recurrence.days ) ? recurrence.days : [];
	const windows = Array.isArray( recurrence.windows )
		? recurrence.windows
		: [];

	if ( 0 === windows.length ) {
		return isListed( days, local.weekday );
	}

	return windows.some( ( entry ) => windowMatches( entry, local, days ) );
}
