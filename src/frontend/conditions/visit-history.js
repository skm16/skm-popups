/**
 * popkit — the `visit_history` client condition.
 *
 * Separates a visitor's first pageview from every one after it, so a welcome
 * popup can greet newcomers and a membership prompt can skip them.
 *
 * ## Browser storage, never a cookie
 *
 * `localStorage`, under a `popkit:`-namespaced key. A cookie would be sent with
 * every request, which varies the cached response and drags the plugin into
 * consent-banner scope in the EU — `docs/CLAUDE.md` -> Cache safety rules it out
 * for frequency capping and the same reasoning applies here, with the extra
 * point that a cookie would also be readable by the server and therefore
 * tempting to evaluate in PHP.
 *
 * Nothing is recorded but the fact that a visit happened. No count, no
 * timestamp, no page, no identifier.
 *
 * ## The ordering, which is the whole trick
 *
 * Read first, then mark. Marking first makes every visitor returning, including
 * the one the condition exists to find, and the bug is invisible in
 * manual testing because the second pageview looks correct.
 *
 * The reading is taken once per pageview and memoized in {@link visited}, so the
 * answer cannot change underneath a second `visit_history` rule, or between the
 * two lanes `controller.js` evaluates popups in — one synchronously at boot, one
 * after the context response arrives. Two popups on one page must agree about
 * whether this visitor is new.
 *
 * The mark is written the first time this condition is evaluated, which makes
 * "returning" mean *has previously loaded a page carrying a `visit_history`
 * rule*, not "has previously loaded any page of the site". That is the honest
 * description and it is deliberate: writing on every pageview would mean a
 * storage write on pages where no author asked for anything, and the alternative
 * reading is not available without one. In practice the two coincide, because a
 * `visit_history` rule that is not on the entry page has nothing to distinguish
 * anyway.
 *
 * ## Unavailable storage fails closed, unlike frequency.js
 *
 * Private browsing and storage-disabled browsers throw on the property access
 * itself, before any method is called, which is why the getter is inside the
 * try. When that happens this condition returns `undefined` and stage 7 fails
 * the rule closed — without applying `negate`, since there was no decision to
 * invert.
 *
 * `frequency.js` makes the opposite call on the same failure, and the two are
 * consistent rather than contradictory. It documents itself as **the one
 * deliberate fail-open in the system**, on the grounds that a frequency cap is a
 * courtesy: getting it wrong shows somebody a popup once more than intended, and
 * failing closed there would suppress every popup for every privacy-mode visitor
 * site-wide.
 *
 * Nothing of the sort is at stake here, because this is not a courtesy — it is a
 * targeting boundary, sitting in the condition registry with `user_state` and
 * `device`, and `docs/data-model.md` -> Evaluation semantics is unambiguous
 * about rules that need something the client could not obtain. The cost of
 * failing closed is that a private-browsing visitor sees neither the
 * `first_time` popup nor the `returning` one. The cost of failing open is
 * choosing which of those two to show them by guessing, and a guess that says
 * "first time" would be reasonable and wrong for every returning visitor whose
 * browser simply forgets.
 *
 * @see docs/data-model.md -> Built-in conditions
 * @see src/frontend/frequency.js
 */

/**
 * Storage key recording that this browser has been here before.
 *
 * Namespaced like every other key popkit writes, so a site owner clearing the
 * plugin's traces can find them all under one prefix.
 *
 * @type {string}
 */
const KEY = 'popkit:visited';

/**
 * Whether this browser had been seen before this pageview.
 *
 * Three-valued and read exactly once: `undefined` before the first evaluation,
 * `null` when storage refused, and a boolean otherwise.
 *
 * @type {boolean|null|undefined}
 */
let visited;

/**
 * Reads the visit record, then marks this visit, memoizing the reading.
 *
 * Both accesses sit in one try. A browser that permits the read and refuses the
 * write is reported as unresolvable rather than as first-time: the mark is what
 * makes the answer true on the *next* pageview, so a reading nothing can follow
 * up would tell every visit that it was the first one.
 *
 * The stored value is never parsed. Presence is the whole record, so anything
 * another script on the origin writes under this key still reads as "has been
 * here", and there is no shape to be malformed.
 *
 * @return {boolean|null} True when this browser has been seen before, false when
 *                        it has not, null when storage is unavailable.
 */
function isReturning() {
	if ( undefined === visited ) {
		try {
			const store = window.localStorage;

			visited = null !== store.getItem( KEY );
			store.setItem( KEY, '1' );
		} catch {
			visited = null;
		}
	}

	return visited;
}

export default {
	key: 'visit_history',
	needsContext: false,

	/**
	 * Reports whether the visitor's history matches the configured state.
	 *
	 * The two states are compared by name rather than being derived from one
	 * boolean, so a stored `state` that is neither of them is unreadable rather
	 * than silently meaning `first_time`. It returns `undefined`, and stage 7
	 * fails the rule closed without applying `negate`.
	 *
	 * @param {Object} [values]       Stored rule values.
	 * @param {string} [values.state] Either `first_time` or `returning`.
	 * @return {boolean|undefined} Whether the visitor matches, or undefined when
	 *                             storage is unavailable or the state is unreadable.
	 */
	evaluate( values ) {
		const returning = isReturning();

		if ( null === returning ) {
			return undefined;
		}

		if ( 'returning' === values?.state ) {
			return returning;
		}

		return 'first_time' === values?.state ? ! returning : undefined;
	},
};
