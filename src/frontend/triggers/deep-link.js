/**
 * popkit — the `deep_link` trigger.
 *
 * Opens a popup when the current URL names it, in either of two forms:
 *
 *   https://example.org/appeal/?popkit=giving-tuesday
 *   https://example.org/appeal/#popkit-giving-tuesday
 *
 * The query form survives being pasted into an email; the fragment form works
 * as an ordinary in-page anchor and never reaches the server, which is why both
 * exist. Neither is configurable — this trigger has no fields, and the slug it
 * matches is the popup's own.
 *
 * ## A deep link is a trigger, not a bypass
 *
 * This is the exit criterion in `docs/build-plan.md` -> Phase 3, in as many
 * words: a deep link to a popup whose conditions fail must **not** open it.
 *
 * Two things make that true, and only one of them is structural. The
 * structural half is arming: the controller arms triggers at stage 10, after
 * client conditions, the schedule and the frequency cap have all passed, so a
 * popup that is not eligible never gets as far as this file. The half that had
 * to be decided is which door this trigger knocks on. `window.popkit.open()`
 * and the runtime `open` entry point behind it **deliberately bypass stages 7
 * to 9** — `api.js` documents that bypass as its purpose — so routing a deep
 * link through either would inherit the bypass and break the criterion the
 * first time somebody deep-linked a popup they were not targeted by.
 *
 * That difference is not pedantry about call sites. `open()` is an author
 * writing a line of their own code, which answers "is this visitor meant to see
 * this" by being written at all. A URL is supplied by whoever sent the link,
 * and treating one as an author's instruction would let any visitor hand any
 * other visitor a link that skips the targeting the author configured. So this
 * trigger calls `api.fire()`, exactly like `page_load` and `scroll_depth`, and
 * reaches the popup through the pipeline rather than around it.
 *
 * ## `hashchange`, and nothing else
 *
 * The fragment form has to work without a page load: an anchor pointing at
 * `#popkit-{slug}` elsewhere in the content, or a single-page navigation that
 * only moves the fragment, both change the URL in place and neither re-runs
 * this bundle. So `hashchange` is listened for, and the teardown removes it.
 *
 * `popstate` is not listened for and `pushState` is not touched.
 * `docs/CLAUDE.md` -> Ask before doing rejects history manipulation for trigger
 * purposes permanently, and a query string that changes without a page load is
 * an application-router case this plugin has no business inferring intent from.
 *
 * ## Which side is normalized, and why it is the slug
 *
 * The two sides of the comparison do not arrive in the same alphabet. WordPress
 * stores a slug that is not plain ASCII percent-encoded in `post_name`, so a
 * popup titled in Greek, Hebrew or Japanese is held as `%ce%b4%cf%8e%cf%81%ce%bf`
 * rather than as `δώρο`. The URL arrives the other way round: whether the sender
 * typed the readable characters or pasted the stored form, the bytes on the wire
 * are percent-encoded and the platform hands them back decoded — once, by
 * `URLSearchParams.get()` for the query form and by {@link decodeOnce} for the
 * fragment. Comparing those two directly can never match, in either form, for
 * any popup whose slug is not already ASCII.
 *
 * So the **slug** is normalized to the URL's alphabet rather than the reverse:
 * it is decoded exactly once, at arming, and every later comparison is made
 * against that. Each side is then one decode from its own storage — the URL from
 * the wire, the slug from `post_name` — which is the single normalized form.
 *
 * Normalizing the other way was the alternative: re-encode the URL value and
 * compare the encoded strings. It was rejected because `encodeURIComponent()`
 * does not reproduce what `sanitize_title()` writes — it emits uppercase hex
 * where WordPress emits lowercase, and it leaves `.` and `~` alone where
 * WordPress does not — so the comparison would need a second normalizing pass
 * over its own output to be trusted, and would still be re-deriving a server
 * encoding in the client.
 *
 * Neither side is ever decoded twice. Double-decoding is a filter-bypass
 * pattern: it would let `%252e` arrive as `.`, and one encoded string
 * impersonate a different one. `%252e` reads as `%2e` here and stops there.
 *
 * @see docs/CLAUDE.md
 * @see docs/build-plan.md
 * @see src/frontend/api.js
 */

/**
 * Query parameter naming a popup.
 *
 * @type {string}
 */
const QUERY_PARAM = 'popkit';

/**
 * Fragment prefix naming a popup, leading `#` included.
 *
 * The `#` is kept so the comparison can be made against `location.hash`
 * unmodified. `hash` is empty rather than `'#'` when there is no fragment, and
 * an empty string never equals this prefix plus a non-empty slug, so the two
 * cases need no separate handling.
 *
 * @type {string}
 */
const HASH_PREFIX = '#popkit-';

/**
 * Teardown for a trigger that armed nothing.
 *
 * `setup()` returns a function on every path. The controller calls it
 * unconditionally — on fire, on abort and on `pagehide` — so a branch returning
 * `undefined` would throw from the cleanup loop and take the teardown of every
 * sibling trigger with it.
 *
 * @return {void}
 */
function noop() {}

/**
 * Decodes a percent-encoded value once.
 *
 * Exactly one decode, deliberately, on whichever side is handed to it. Both
 * `location.hash` and `post_name` are held encoded, and both are compared
 * against values the platform has already decoded once — but decoding either a
 * second time would make `#popkit-a%2525b` match the slug `a%b`, letting an
 * encoded string impersonate a different one. `URLSearchParams` performs the
 * same single decode for the query form, so all three paths agree.
 *
 * A malformed escape — `#popkit-%zz` — throws `URIError`. The raw value is
 * returned instead of rethrowing: it cannot equal the prefix plus this popup's
 * slug, so the match simply fails, and a fragment somebody else's script wrote
 * is not this plugin's error to raise. A slug that will not decode fails the
 * same way, which fails closed: no URL opens that popup.
 *
 * @param {string} value Percent-encoded value: `location.hash`, or a slug.
 * @return {string} The value decoded once, or the raw one when it will not decode.
 */
function decodeOnce( value ) {
	try {
		return decodeURIComponent( value );
	} catch {
		return value;
	}
}

/**
 * Reports whether the current URL names this popup.
 *
 * Takes the slug already decoded once — see the file header for which side is
 * normalized and why — because both URL forms are read decoded and a slug that
 * is not plain ASCII is stored encoded.
 *
 * Both comparisons are equality against the whole value, never a prefix or a
 * substring test: `?popkit=giving` must not open a popup slugged
 * `giving-tuesday`, and `#popkit-giving-tuesday` must not open one slugged
 * `giving`. Decoding changes the alphabet the two sides are compared in, never
 * the comparison. `URLSearchParams.get()` returns the value already decoded once
 * and `null` when the parameter is absent, and `null` never equals a string.
 *
 * @param {string} slug Popup slug, decoded once.
 * @return {boolean} True when the URL names this popup in either form.
 */
function urlNames( slug ) {
	const { search, hash } = window.location;

	if ( slug === new window.URLSearchParams( search ).get( QUERY_PARAM ) ) {
		return true;
	}

	return HASH_PREFIX + slug === decodeOnce( hash );
}

export default {
	key: 'deep_link',

	/**
	 * Arms the deep-link match.
	 *
	 * The URL is already whatever it is by the time this runs, so the first
	 * check asks a question whose answer cannot change — and it is still
	 * deferred by one task rather than answered inline. Firing from inside
	 * `setup()` re-enters the arming loop that is still running: arming stops
	 * there, so the author's remaining triggers are never bound, and the open —
	 * with its `popkit:before-open`, its `showModal()` and its focus move — runs
	 * in the middle of stage 10 instead of after it. Whether the popup ends up
	 * on screen would then depend on the order the triggers happen to be listed
	 * in. `page_load` with `delay_ms: 0` is the one trigger for which firing
	 * during `setup()` is the point; this is not that trigger, and one task is
	 * imperceptible.
	 *
	 * The timer is cleared by the teardown, so a popup aborted in the same task
	 * never opens.
	 *
	 * `fire()` is called with no argument. Its optional parameter is the element
	 * the visitor interacted with, and a URL names no element — so focus returns
	 * to wherever it already was rather than to a link somewhere on the page
	 * that may not be the one that was followed.
	 *
	 * @param {Object}   api       Trigger API: `fire()`, `abort()`, `popup`, `element`.
	 * @param {Function} api.fire  Opens the popup; idempotent, first-wins. Not
	 *                             `window.popkit.open()` — see the file header.
	 * @param {Object}   api.popup Popup config; the slug is the whole of this
	 *                             trigger's configuration.
	 * @return {Function} Teardown, which clears the timer and removes the listener.
	 */
	setup( api ) {
		const slug = api.popup?.slug;

		if ( 'string' !== typeof slug || '' === slug ) {
			return noop;
		}

		// Normalized once, here, rather than on every check: the slug cannot
		// change, and `hashchange` can run this many times on one page view.
		const target = decodeOnce( slug );

		const check = () => {
			if ( urlNames( target ) ) {
				api.fire();
			}
		};

		const timer = window.setTimeout( check, 0 );

		window.addEventListener( 'hashchange', check );

		return () => {
			window.clearTimeout( timer );
			window.removeEventListener( 'hashchange', check );
		};
	},
};
