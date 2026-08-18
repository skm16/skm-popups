/**
 * popkit — the `page_load` trigger.
 *
 * Opens a popup once the visitor has been on the page for `delay_ms`
 * milliseconds. Armed only after pipeline stages 7 to 9 have passed, like every
 * trigger, so no timer is ever started for a popup that could never show.
 *
 * ## Zero is a delay, not a special case
 *
 * `delay_ms: 0` goes through the same `setTimeout()` as every other value
 * rather than calling `api.fire()` from inside `setup()`. Firing synchronously
 * is the one thing the trigger contract permits here and it is still the worse
 * of the two, on three counts:
 *
 * - **One code path, therefore one teardown.** The classic leak in this trigger
 *   is a popup that opens after the visitor has navigated away, and it is
 *   closed by a single `clearTimeout()` that runs for every configured delay.
 *   A branch that fired immediately would be a branch with no timer to clear,
 *   and the next person to touch it would have two shapes to keep correct.
 * - **Arming finishes first.** `trigger-controller.js` stops arming the moment
 *   anything fires, so a synchronous fire leaves every sibling trigger after it
 *   in the list unarmed. Nothing leaks — that module handles the case — but
 *   `delay_ms: 0` would then differ from `delay_ms: 1` in kind rather than in
 *   time, which is not what a number meaning "milliseconds" should do.
 * - **Scripts further down the page get to attach their listeners.** A task
 *   boundary lets everything queued after the bundle finish executing before a
 *   popup appears, so an inline consent snippet or a theme's own
 *   `popkit:before-open` listener sitting a few lines below the enqueue is
 *   attached in time to veto. This is an improvement, not a guarantee: a
 *   listener registered from `DOMContentLoaded` may still be later than the
 *   first task, and nothing in this module can change that.
 *
 * ## What this guards, and what it leaves to PHP
 *
 * `Popkit\Builtin_Triggers` declares `delay_ms` as an integer with a maximum,
 * and sanitization enforces it. That bound is deliberately **not** repeated
 * here: a field schema is declared in PHP only (`docs/CLAUDE.md` -> Registry
 * invariants), and a second copy in the bundle would be a second source of
 * truth that goes stale the first time the authoring cap moves.
 *
 * What the runtime does guard is the browser's own arithmetic, which the PHP
 * bound has no opinion about. `setTimeout()` stores its delay as a signed
 * 32-bit integer: a value above that overflows and the timer fires
 * **immediately**, so a hand-edited `delay_ms: 3000000000` would open the popup
 * at once, which is the opposite of what the number says. Clamping keeps a
 * nonsensical delay merely long instead of instant.
 *
 * An unreadable delay — absent, negative, `NaN`, a value of a type this never
 * expected — is treated as no delay rather than as a reason to stay silent.
 * `page_load` means "fire on load" and the delay only refines *when*; refusing
 * to fire would turn a typo into a popup that never appears, with nothing on
 * screen to explain it. Nothing about the visitor is at stake either way:
 * stages 7 to 9 already decided who sees this popup, and only its timing is in
 * question here.
 *
 * @see docs/CLAUDE.md
 * @see docs/data-model.md -> Triggers
 */

/**
 * Longest delay a browser timer can express, in milliseconds.
 *
 * 2^31 - 1. Above this the delay overflows its signed 32-bit slot and the
 * callback runs on the next task, so this is the ceiling at which "very late"
 * silently becomes "immediately".
 *
 * @type {number}
 */
const MAX_DELAY_MS = 2147483647;

/**
 * Turns a stored `delay_ms` into a delay a timer can be trusted with.
 *
 * Coerced with `Number()` rather than type-checked, so a config carrying
 * `"3000"` waits three seconds instead of firing at once. Everything that
 * cannot become a finite positive number — `undefined`, `null`, `NaN`, a
 * negative, an object — becomes zero, which is this trigger's own default and
 * not a refusal to fire.
 *
 * @param {*} value Stored `delay_ms` value.
 * @return {number} Milliseconds to wait, between 0 and {@link MAX_DELAY_MS}.
 */
function toDelay( value ) {
	const delay = Number( value );

	return Number.isFinite( delay ) && 0 < delay
		? Math.min( delay, MAX_DELAY_MS )
		: 0;
}

export default {
	key: 'page_load',

	/**
	 * Starts the timer that opens this popup.
	 *
	 * `api.fire()` is called with no argument. Its optional parameter is the
	 * element the visitor interacted with, and a timer is not an interaction —
	 * so focus returns to wherever it already was rather than to something the
	 * visitor never touched.
	 *
	 * @param {Object}   api               Trigger API: `fire()`, `abort()`, `popup`, `element`.
	 * @param {Function} api.fire          Opens the popup; idempotent, first-wins.
	 * @param {Object}   [values]          Stored trigger values.
	 * @param {number}   [values.delay_ms] Milliseconds to wait before opening.
	 * @return {Function} Teardown, which clears the timer.
	 */
	setup( api, values ) {
		const timer = window.setTimeout(
			() => api.fire(),
			toDelay( values?.delay_ms )
		);

		return () => window.clearTimeout( timer );
	},
};
