/**
 * popkit — the `utm` client condition.
 *
 * Matches when a named query parameter on the current URL holds a given value.
 * Named for the campaign parameters it exists to read — `utm_source`,
 * `utm_campaign`, `utm_medium` — but `param` is any parameter name, because the
 * targeting question ("did this visitor arrive from the appeal email") is the
 * same whatever the sending tool called its parameter.
 *
 * ## Why this is a client condition
 *
 * The query string is part of the URL, so evaluating it in PHP would be
 * cache-safe in principle. It is here anyway, and the reason is practical
 * rather than architectural: `?utm_source=newsletter` is a *variant* of the same
 * page, and page caches routinely strip or ignore campaign parameters when
 * deciding which entry to serve. A popup that appeared or vanished depending on
 * whether the cache happened to key on the parameter would be indistinguishable
 * from a broken plugin. Reading it in the browser reads the URL the visitor
 * actually has.
 *
 * ## Comparison is exact and case-sensitive
 *
 * A UTM value is an identifier, not prose: the author pastes the same string
 * into their mail tool's link builder and into this field, and the two either
 * agree or the campaign was tagged differently from how it was targeted.
 * Folding case would make `Spring` and `spring` the same campaign, which is a
 * distinction some senders genuinely make, and no amount of matching cleverness
 * turns a mistyped tag into the right one — it only hides which of the two is
 * wrong. `referrer` folds case because a host name is genuinely
 * case-insensitive; a parameter value is not.
 *
 * For the same reason there is no substring or prefix mode here. `utm` answers
 * one question exactly; an author who needs pattern matching against the URL has
 * `url_path`, which is the condition that owns the match language.
 *
 * ## A missing parameter is false, not an error
 *
 * `URLSearchParams.get()` returns `null` for an absent parameter, and `null`
 * never equals a string, so a visitor who arrived without the campaign tag fails
 * the rule and its group. That is a decision, so `negate` applies to it
 * normally: "did not arrive from this campaign" is a rule an author can write
 * and this is the answer it wants.
 *
 * @see docs/data-model.md -> Built-in conditions
 */

export default {
	key: 'utm',
	needsContext: false,

	/**
	 * Compares one query parameter against the stored value.
	 *
	 * `URLSearchParams` performs exactly one percent-decode, so a value stored as
	 * `spring appeal` matches `?utm_campaign=spring%20appeal` and
	 * `?utm_campaign=spring+appeal` alike, and `%2520` reads as `%20` rather than
	 * as a space. Repeated parameters resolve to the first occurrence, which is
	 * what the URL means by them.
	 *
	 * An empty or non-string `param` names nothing, so there is no question to
	 * answer: this returns `undefined` and stage 7 fails the rule closed without
	 * applying `negate`. A `value` that is not a string is left to the comparison
	 * — `get()` only ever returns a string or `null`, so it simply does not match
	 * — because an author who cleared the field is asking about a parameter whose
	 * value is empty, which is a real thing to ask.
	 *
	 * @param {Object} [values]       Stored rule values.
	 * @param {string} [values.param] Query parameter name.
	 * @param {string} [values.value] Value the parameter must hold, exactly.
	 * @return {boolean|undefined} Whether the parameter matches, or undefined when
	 *                             no parameter was named.
	 */
	evaluate( values ) {
		const param = values?.param;

		return 'string' === typeof param && '' !== param
			? new window.URLSearchParams( window.location.search ).get(
					param
			  ) === values.value
			: undefined;
	},
};
