/**
 * Fail-closed fetch of the popkit context endpoint.
 *
 * Two things genuinely cannot be answered from cached HTML: authoritative time
 * and login state. Both come from one uncached same-origin route, requested at
 * most once per pageview and only when the emitted config says `needsContext`.
 *
 * Every failure mode collapses to the same answer — `null`, meaning
 * unavailable — and callers suppress every popup that depended on it. A network
 * blip hiding a popup is correct; showing a members-only popup to the public is
 * not, and a schedule evaluated against an uncalibrated clock would show an
 * expired campaign. There is no partial success: if any requested key is
 * missing or malformed, the whole response is discarded.
 *
 * @see docs/CLAUDE.md -> The context endpoint
 * @see docs/data-model.md -> Context endpoint payload
 */

import { monotonicNow } from './clock.js';

/**
 * Field names this client understands, in canonical request order.
 *
 * The server allowlists the same two names and ignores anything else, so
 * filtering here is not a security boundary — it guarantees that every
 * requested name has a validator below. A name with no validator would be
 * "requested" and then never checked, which is the quiet way a missing key
 * turns into a popup shown to everyone.
 *
 * @type {string[]}
 */
const FIELD_NAMES = [ 'time', 'user_state' ];

/**
 * Upper bound on the round trip, in milliseconds.
 *
 * Plain `fetch` has no timeout, so a hung connection would otherwise leave
 * context-dependent popups waiting for the life of the page. An abort is
 * treated exactly like any other failure.
 *
 * @type {number}
 */
const REQUEST_TIMEOUT_MS = 5000;

/**
 * The one request per pageview, or `null` before the first call.
 *
 * @type {Promise<Object|null>|null}
 */
let pending = null;

/**
 * Performs the round trip and reports when it happened.
 *
 * Transport only: it decides nothing about the payload beyond "this is what
 * came back, if anything". `payload` is `null` for a network failure, an abort,
 * a status other than 200, or a body that is not JSON — the caller cannot tell
 * those apart because it must treat them identically.
 *
 * The two timings bracket the request so the clock offset can be anchored to
 * the midpoint of the round trip rather than to the parse moment. Both come
 * from the monotonic base; see clock.js for why the wall clock is banned.
 *
 * @param {string} url Fully built request URL.
 * @return {Promise<{payload: *, sentAt: number, receivedAt: number}>} Result.
 */
async function requestPayload( url ) {
	const aborter = new AbortController();
	const timer = setTimeout( () => aborter.abort(), REQUEST_TIMEOUT_MS );
	const sentAt = monotonicNow();
	let payload = null;

	try {
		const response = await fetch( url, {
			// Sends the logged-in cookie, which is the only way the route can
			// derive login state without a per-user REST nonce.
			credentials: 'same-origin',
			// The route sends `no-store` itself; asking again means a stale
			// intermediary cannot hand back a frozen timestamp or another
			// visitor's login state.
			cache: 'no-store',
			signal: aborter.signal,
		} );

		if ( 200 === response.status ) {
			payload = await response.json();
		}
	} catch {
		payload = null;
	}

	clearTimeout( timer );

	return { payload, sentAt, receivedAt: monotonicNow() };
}

/**
 * Requests the named fields and validates every one of them.
 *
 * @param {string}   restUrl Absolute URL of the context route.
 * @param {string[]} fields  Field names to request.
 * @param {Object}   clock   Clock from `createClock()`.
 * @return {Promise<Object|null>} Context object, or `null` when unavailable.
 */
async function requestContext( restUrl, fields, clock ) {
	const requested = Array.isArray( fields )
		? FIELD_NAMES.filter( ( name ) => fields.includes( name ) )
		: [];

	// Nothing answerable was asked for. Fail closed rather than issue a request
	// whose response could not be validated against anything.
	if ( 0 === requested.length ) {
		return null;
	}

	// `rest_url()` already carries a query string when the site has plain
	// permalinks (`?rest_route=…`), so the separator cannot be assumed. The
	// names come from FIELD_NAMES and need no encoding — no caller-supplied
	// text reaches the query string.
	const separator = restUrl.includes( '?' ) ? '&' : '?';
	const { payload, sentAt, receivedAt } = await requestPayload(
		`${ restUrl }${ separator }fields=${ requested.join( ',' ) }`
	);

	if ( null === payload || 'object' !== typeof payload ) {
		return null;
	}

	const context = {};

	if ( requested.includes( 'time' ) ) {
		if ( ! Number.isFinite( payload.time ) ) {
			return null;
		}

		context.time = payload.time;
	}

	if ( requested.includes( 'user_state' ) ) {
		const state = payload.user?.state;

		if ( 'logged_in' !== state && 'logged_out' !== state ) {
			return null;
		}

		context.user = { state };
	}

	// Calibrated only once the whole payload has been accepted, so a response
	// good enough to move the clock is also good enough to act on.
	if ( undefined !== context.time ) {
		clock.calibrate( context.time, sentAt, receivedAt );
	}

	return context;
}

/**
 * Fetches context, at most once per pageview.
 *
 * Repeat calls return the in-flight or already-resolved promise rather than
 * issuing a second request, so several popups may await context independently
 * without multiplying round trips. Arguments after the first call are ignored,
 * which is correct because the controller derives one field list from one
 * emitted config.
 *
 * The trailing `catch` is the last fail-closed net: anything thrown anywhere
 * below resolves to `null` rather than surfacing as an unhandled rejection that
 * would leave awaiting popups pending forever.
 *
 * @param {string}   restUrl Absolute URL of the context route.
 * @param {string[]} fields  Field names to request: `time`, `user_state`.
 * @param {Object}   clock   Clock from `createClock()`, calibrated on success.
 * @return {Promise<Object|null>} `{ time?, user? }`, or `null` when unavailable.
 */
export async function fetchContext( restUrl, fields, clock ) {
	if ( null === pending ) {
		pending = requestContext( restUrl, fields, clock ).catch( () => null );
	}

	return pending;
}
