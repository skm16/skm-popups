/**
 * popkit — the `device` client condition.
 *
 * Matches when the viewport is **at most** `max_width` CSS pixels wide. One
 * field, one comparison, no context round trip.
 *
 * ## Why matchMedia and not innerWidth
 *
 * `window.matchMedia( '(max-width: 782px)' )` asks the same question the theme's
 * stylesheet asks, in the same units, through the same evaluator. `innerWidth`
 * does not: it is affected by pinch zoom and by whether a classic scrollbar is
 * counted, so a visitor sitting exactly on a breakpoint can get the theme's
 * mobile layout and the desktop popup at the same time. An author who set 782
 * because that is where their theme collapses the menu means the breakpoint,
 * not a number that is usually near it.
 *
 * ## Read once, at decision time
 *
 * The `MediaQueryList` is queried and discarded. Nothing subscribes to `change`,
 * because a popup that vanished when the visitor rotated their phone or dragged
 * a window edge would take its focus trap and its close button with it, mid
 * interaction. `docs/CLAUDE.md` -> Architecture invariants settles eligibility
 * once per pageview and this condition is part of that settlement.
 *
 * @see docs/data-model.md -> Built-in conditions
 */

export default {
	key: 'device',
	needsContext: false,

	/**
	 * Reports whether the viewport is no wider than the configured maximum.
	 *
	 * Readability is decided before coercion rather than from its result, and
	 * that ordering is the whole guard. `Number()` maps `null`, `''` and `[]`
	 * to `0` and `true` to `1`, so testing `Number.isFinite()` on its output
	 * calls four unreadable values readable and lets two of them spell a
	 * breakpoint. Only a number or a string is a candidate, which still lets a
	 * config carrying `"782"` behave like `782` — the reason the coercion is
	 * here at all — while keeping anything that merely coerces without meaning
	 * a width out of the media query text.
	 *
	 * What survives is exactly what the field schema declares: an integer of 1
	 * or more. Everything else — a fraction, a negative, `NaN`, `Infinity`, and
	 * any value of another type — is neither a narrower rule nor a wider one but
	 * an unanswerable one, so this returns `undefined` and stage 7 fails the
	 * rule closed without applying `negate`. Answering `false` instead is the
	 * failure worth naming: `negate: true` would then turn an unreadable width
	 * into a rule matching every visitor on the site.
	 *
	 * A stored `0` is unreadable too, and the boundary is drawn where the schema
	 * draws it rather than where CSS stops parsing. `(max-width:0px)` is valid
	 * CSS that no viewport satisfies, so the browser would happily answer it —
	 * but the editor cannot produce a `0`, so its only provenance is a
	 * hand-edited row or a forged REST write, which is the provenance of `-1`
	 * and of `"wide"`. Reading it as "a rule nobody matches" gives that
	 * provenance a decision, and `negate: true` turns that decision into a
	 * site-wide match. Nothing is clamped: an out-of-range width is refused, not
	 * repaired into a neighbouring rule the author never wrote.
	 *
	 * @param {Object} [values]           Stored rule values.
	 * @param {number} [values.max_width] Widest viewport that matches, in CSS pixels.
	 * @return {boolean|undefined} Whether the viewport matches, or undefined when
	 *                             `max_width` is not an integer of 1 or more.
	 */
	evaluate( values ) {
		const stored = values?.max_width;

		const maxWidth =
			'number' === typeof stored || 'string' === typeof stored
				? Number( stored )
				: NaN;

		return Number.isInteger( maxWidth ) && 0 < maxWidth
			? window.matchMedia( `(max-width:${ maxWidth }px)` ).matches
			: undefined;
	},
};
