/**
 * popkit — the `user_state` client condition.
 *
 * The only condition in v1 that requires the context route, and the reason the
 * route exists. Login state cannot be decided in PHP during emission: a page
 * cache serves one HTML response to many visitors, so a rendering decision made
 * from a cookie is a decision made on behalf of whoever happened to fill the
 * cache. `docs/CLAUDE.md` -> Cache safety states it as an absolute.
 *
 * ## `document.body.classList` is not an acceptable source
 *
 * WordPress prints `logged-in` on the body, and reading it here would cost
 * nothing and would be wrong in exactly the way this whole architecture exists
 * to prevent. Those classes are part of the cached HTML. A page cached while an
 * editor was signed in reports `logged-in` to every anonymous visitor
 * afterwards, and a "members only" popup goes site-wide with no error anywhere
 * to explain it. `docs/data-model.md` names the temptation and rules it out.
 *
 * ## Fail closed, and without negate
 *
 * The payload arrives as `null` whenever the fetch failed, timed out, returned
 * a non-200, parsed to something malformed, or simply did not carry the
 * requested key. Each of those is "the client could not obtain the context",
 * which `docs/data-model.md` -> Evaluation semantics answers the same way:
 * the rule evaluates false, its group fails, and `negate` is **not** applied.
 * This module signals that by returning `undefined` rather than `false` — see
 * `conditions/index.js` for why the third value is load bearing. Returning
 * `false` would let `negate: true` turn one failed request into "show the
 * logged-out popup to every member on the site".
 *
 * The rule's own `state` is held to the same standard as the payload's. Both
 * sides of the comparison have to be readable before there is a comparison to
 * make, and a rule storing no state, or a state outside the two the schema
 * declares, is as unanswerable as a payload that never arrived. Deciding it
 * `false` would be the same failure from the other direction: a members-only
 * rule is `state: logged_in` with `negate: true`, so one unreadable stored
 * value would take it site-wide — and unlike a failed fetch, it would do so on
 * every pageview, permanently, with the popup's targeting still looking correct
 * in the editor.
 *
 * The payload carries a boolean's worth of information and nothing else: no
 * user ID, name, email, capability or role, in v1 or after.
 *
 * @see docs/CLAUDE.md -> The context endpoint
 * @see docs/data-model.md -> Context endpoint payload
 */

export default {
	key: 'user_state',
	needsContext: true,

	/**
	 * Compares the visitor's login state against the configured one.
	 *
	 * Neither guard is defensive decoration. Without the payload guard, a
	 * payload with no `user.state` and a rule with no `state` would compare
	 * `undefined` with `undefined` and pass; without the enum guard, that same
	 * stored rule would compare `undefined` with a real login state, answer
	 * `false`, and hand `negate: true` every visitor on the site. A missing
	 * answer must never satisfy a rule, and a missing question must never be
	 * answered at all.
	 *
	 * The enum is spelled out rather than tested for non-emptiness, so a stored
	 * `logged-in`, `loggedIn` or `member` is unreadable rather than being
	 * quietly treated as "not logged_out". The two members are also the only
	 * two `Popkit\Rest_Context` emits, which is what makes a genuine comparison
	 * possible in the first place.
	 *
	 * @param {Object}      [values]       Stored rule values.
	 * @param {string}      [values.state] Either `logged_in` or `logged_out`.
	 * @param {Object|null} [ctx]          Context payload, or null when unavailable.
	 * @return {boolean|undefined} Whether the states match, or undefined when the
	 *                             rule names no valid state or the payload carried
	 *                             no login state.
	 */
	evaluate( values, ctx ) {
		const wanted = values?.state;
		const state = ctx?.user?.state;

		const readable =
			( 'logged_in' === wanted || 'logged_out' === wanted ) &&
			'string' === typeof state;

		return readable ? state === wanted : undefined;
	},
};
