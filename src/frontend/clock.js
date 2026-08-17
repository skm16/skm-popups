/**
 * Monotonic clock for popkit, calibrated once against the context endpoint.
 *
 * Schedule evaluation cannot trust the visitor's device clock — a machine
 * running eight hours fast would open a campaign eight hours early — and it
 * cannot trust a timestamp printed into the page either, because the page is
 * cached: a value frozen at cache-fill time is stale for every later hit, which
 * is why there is no `serverTime` field in the emitted config. Authoritative
 * time therefore arrives from `GET /wp-json/popkit/v1/context?fields=time`, and
 * this module turns that single reading into a reusable "now".
 *
 * `Date.now()` appears nowhere in this file and must never be added to it, no
 * matter how convenient it looks. `performance.now()` is monotonic and
 * unaffected by device clock adjustments, and `performance.timeOrigin` is fixed
 * at document creation; reading one side of the calculation from the wall clock
 * and the other from the monotonic clock reintroduces exactly the skew the
 * offset exists to remove. A visitor who changes their clock — or whose OS
 * applies an NTP correction — after calibration would then see every schedule
 * jump by the same amount. Both sides stay on the monotonic base.
 *
 * The offset is calibrated for schedules whose windows are minutes or hours
 * wide. It absorbs latency asymmetry and is not suitable for anything needing
 * sub-second precision. Do not repurpose it.
 *
 * @see docs/data-model.md -> Schedule -> Where `now` comes from
 * @see docs/CLAUDE.md -> The context endpoint
 */

/**
 * Reads the monotonic clock as an epoch-milliseconds value.
 *
 * Exported so the context fetch can stamp its request on the same time base
 * this module calibrates against. Keeping the single permitted time source in
 * one place is what makes the ban above greppable.
 *
 * @return {number} Milliseconds since the Unix epoch, monotonic base.
 */
export function monotonicNow() {
	return performance.timeOrigin + performance.now();
}

/**
 * Creates an uncalibrated clock.
 *
 * @return {{calibrate: Function, now: Function, isCalibrated: Function}} Clock.
 */
export function createClock() {
	/**
	 * Milliseconds to add to the monotonic reading, or `null` while
	 * uncalibrated. `null` rather than `0` so "no answer yet" and "the device
	 * agrees with the server exactly" stay distinguishable.
	 *
	 * @type {number|null}
	 */
	let offset = null;

	return {
		/**
		 * Calibrates against one context response.
		 *
		 * The server's reading corresponds to the midpoint of the round trip,
		 * not to the moment the response was parsed — latency is assumed
		 * symmetric. Anchoring on the parse moment instead would leave the
		 * corrected clock running late by the whole response leg.
		 *
		 * A non-finite result leaves the clock uncalibrated, so a malformed
		 * payload that slipped through fails closed rather than poisoning every
		 * later reading with `NaN`. Calibrating twice is not expected — context
		 * is fetched once per pageview — but the last call wins if it happens.
		 *
		 * @param {number} serverTimeMs Server epoch milliseconds from the response.
		 * @param {number} sentAtMs     Monotonic reading taken before the request.
		 * @param {number} receivedAtMs Monotonic reading taken after the parse.
		 */
		calibrate( serverTimeMs, sentAtMs, receivedAtMs ) {
			const clientAtResponse = ( sentAtMs + receivedAtMs ) / 2;
			const candidate = serverTimeMs - clientAtResponse;

			if ( Number.isFinite( candidate ) ) {
				offset = candidate;
			}
		},

		/**
		 * Returns authoritative epoch milliseconds, or `null` when uncalibrated.
		 *
		 * There is deliberately no fallback to the device clock. An uncalibrated
		 * reading has no authority, and silently substituting one would show
		 * expired campaigns to anyone whose clock is wrong — the exact failure
		 * this module exists to prevent. Callers treat `null` as
		 * context-unavailable and fail the popup closed.
		 *
		 * @return {number|null} Epoch milliseconds, or `null` if uncalibrated.
		 */
		now() {
			return null === offset ? null : monotonicNow() + offset;
		},

		/**
		 * Reports whether a calibration has been applied.
		 *
		 * @return {boolean} True once `calibrate()` has produced a finite offset.
		 */
		isCalibrated() {
			return null !== offset;
		},
	};
}
