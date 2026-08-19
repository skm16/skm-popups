/**
 * popkit — the `scroll_depth` trigger.
 *
 * Opens a popup once the visitor has scrolled at least `percent` of the way
 * down the document. Armed only after pipeline stages 7 to 9 have passed, like
 * every trigger, so no listener is bound for a popup that could never show.
 *
 * ## What the percentage is measured against
 *
 * The *scrollable* distance: `scrollHeight - clientHeight` on the scrolling
 * element, which is the document's full height less the one viewport that is
 * always on screen. Progress is `scrollTop` as a percentage of that, so 100
 * means the bottom of the document has been reached — not that the visitor has
 * traveled the document's whole height, which no visitor can do.
 *
 * ## A page that does not scroll counts as fully read
 *
 * When the scrollable distance is zero or negative the document already fits in
 * the viewport, and this module reports 100. Every threshold from 1 to 100 is
 * therefore met and the popup opens.
 *
 * The alternative — never firing — was rejected. An author choosing "50%" is
 * asking whether the visitor has got halfway through the content; on a page
 * with nothing below the fold they have, because all of it is on screen. Never
 * firing would make one popup, on one page, with one setting, work on a phone
 * and be silently dead on a desktop with a tall window, and would make the
 * outcome depend on the height of the visitor's browser chrome — something no
 * author can reason about and nothing on screen would explain. The editor can
 * warn about a short page; a runtime that quietly does nothing cannot.
 *
 * The honest cost of that choice is that on such a page `scroll_depth` is
 * indistinguishable from `page_load` with no delay. That is accurate: on a page
 * with no scrolling, it is the same event.
 *
 * ## "Does not scroll yet" is not "does not scroll"
 *
 * That reading is only safe once the layout it was taken from is the layout the
 * visitor will get. A document measured too early reads zero scrollable distance
 * for a reason that has nothing to do with its height — images and embeds still
 * arriving, a stylesheet still being applied — and answering 100 to that is a
 * popup opening at every threshold on a page the visitor has not seen a line of.
 *
 * So zero scrollable distance is not an answer until `document.readyState` is
 * `complete`. Until then it is *unknown*: {@link progress} returns null, nothing
 * fires, and the check asks for another animation frame. The chain ends the
 * moment either half of the question resolves — the document gains height, or it
 * finishes loading — which on a genuinely short page is the load event, one
 * event later than before and still without the visitor doing anything.
 *
 * Chaining frames, rather than listening for `load`, is what keeps this trigger
 * to the one listener its teardown contract is written around; the work per
 * frame is three property reads on an element the frame has already laid out,
 * and only on a page currently measuring as unscrollable. A page that never
 * finishes loading *and* never gains height keeps that going, which is the one
 * honest cost, and it is a page the visitor is already staring at half-built.
 *
 * `readyState` is the settling signal because it is the only one the platform
 * offers for free. It is not a promise that nothing will grow afterwards —
 * `loading="lazy"` content below the fold blocks nothing — and the paragraph
 * below on late layout changes is what covers the rest.
 *
 * ## Throttled by frames, not by a timer
 *
 * `scroll` fires far more often than anything can usefully be measured, so the
 * handler does no work: it asks for an animation frame if one is not already
 * pending, and the frame does the measuring. That bounds the work at once per
 * *painted* frame, skips the frames the browser itself skipped, and reads
 * layout at the point in the frame where it is already settled — so a scroll
 * cannot be made to thrash layout by this listener.
 *
 * A timer would have kept measuring in a background tab, where nothing can have
 * scrolled. Animation frames simply stop there, and the pending one runs when
 * the visitor comes back, which is also when the popup should be shown to them.
 *
 * ## The listener is passive, and that is not decoration
 *
 * `{ passive: true }` promises the browser that the handler will never call
 * `preventDefault()`, which lets a touch scroll start without waiting to find
 * out. Without it, every scroll gesture on a touch device blocks on this
 * listener — a measurable regression on exactly the devices least able to
 * absorb one, on every page carrying an eligible popup. Nothing here cancels
 * anything, so the promise is free.
 *
 * The same options object is handed to `removeEventListener()`. Listener
 * identity is type, handler and capture flag, so the teardown matches, and the
 * `pagehide` listener-count test finds nothing left behind.
 *
 * ## Checked once at arming, too
 *
 * A visitor who arrives on `#section-4`, or who returns to a page whose scroll
 * position the browser restored, is already past the threshold and has no
 * reason to scroll again. So the same check runs once at arming — through the
 * same animation frame rather than directly, because `setup()` must not fire
 * synchronously and because a measurement taken before the browser's first
 * layout would read a position that is not yet the visitor's.
 *
 * A layout change that adds height without a scroll — lazy-loaded content below
 * the fold, an embed resolving late — lowers the progress figure and is not
 * re-measured until the next scroll. It can therefore only delay a fire, never
 * cause a spurious one, and the next scroll event corrects it. That holds
 * because the reading it lowers is a real one: it is the zero-distance reading,
 * where there is nothing to be lowered from, that the section above defers.
 *
 * @see docs/CLAUDE.md
 * @see docs/data-model.md -> Triggers
 */

/**
 * Options for the scroll listener.
 *
 * @type {{passive: boolean}}
 */
const LISTENER_OPTIONS = { passive: true };

/**
 * The whole of the scrollable distance, as a percentage.
 *
 * Both the upper clamp on a configured threshold and the reading returned for a
 * document that does not scroll, which are the same statement: everything there
 * is to scroll has been scrolled.
 *
 * @type {number}
 */
const FULL_PERCENT = 100;

/**
 * Shallowest threshold this trigger will act on, as a percentage.
 *
 * Matches `Popkit\Builtin_Triggers::MIN_SCROLL_PERCENT`. Zero is excluded there
 * because a depth of zero is satisfied before the visitor has done anything,
 * which is `page_load` with no delay wearing another name.
 *
 * @type {number}
 */
const MIN_PERCENT = 1;

/**
 * Measures how far down the document the visitor has scrolled.
 *
 * `document.scrollingElement` rather than `documentElement`, because it is the
 * element that actually scrolls in both standards and quirks mode. It is null
 * only in a quirks-mode document whose body cannot scroll, which is a document
 * that does not scroll — the same answer the zero-distance branch gives, by the
 * same reasoning.
 *
 * The result can exceed 100 where a browser allows overscroll past the end.
 * Nothing here clamps it: the comparison is "at least", and a reading beyond
 * the bottom is still beyond every threshold.
 *
 * Zero or negative scrollable distance is only {@link FULL_PERCENT} once the
 * document has finished loading. Before that it is null, meaning "not yet
 * known": see the file header. Null is returned rather than zero so that "the
 * visitor is at the top" and "there is nothing to measure" stay different
 * answers to the caller, since only one of them should ask again.
 *
 * @return {?number} Percentage of the scrollable distance covered, or null while
 *                   a document that measures as unscrollable is still loading.
 */
function progress() {
	// Aliased because it is read three times, and `window.document` minifies to
	// nothing shorter. See `docs/CLAUDE.md` -> Weight.
	const doc = window.document;
	const root = doc.scrollingElement || doc.documentElement;
	const distance = root.scrollHeight - root.clientHeight;

	if ( 0 < distance ) {
		return ( root.scrollTop / distance ) * FULL_PERCENT;
	}

	return 'complete' === doc.readyState ? FULL_PERCENT : null;
}

/**
 * Turns a stored `percent` into a threshold that can be compared against.
 *
 * Coerced with `Number()`, so a config carrying `"50"` means half way. A value
 * that cannot become a finite number falls back to {@link FULL_PERCENT}: the
 * trigger stays functional — it fires when the visitor reaches the bottom —
 * while erring towards firing later than the author asked rather than earlier.
 * A missed impression is recoverable; an unasked-for interruption is what gives
 * popups their reputation.
 *
 * The 1-to-100 range is clamped rather than validated. It is declared and
 * enforced in PHP (`docs/CLAUDE.md` -> Registry invariants); this is the
 * runtime refusing to misbehave on a hand-edited config, not a second copy of
 * the schema.
 *
 * @param {*} value Stored `percent` value.
 * @return {number} Threshold between {@link MIN_PERCENT} and {@link FULL_PERCENT}.
 */
function toThreshold( value ) {
	const percent = Number( value );

	return Number.isFinite( percent )
		? Math.min( Math.max( percent, MIN_PERCENT ), FULL_PERCENT )
		: FULL_PERCENT;
}

export default {
	key: 'scroll_depth',

	/**
	 * Arms one passive scroll listener for this popup.
	 *
	 * `api.fire()` is idempotent and first-wins, so the frame callback does not
	 * track whether it already fired: firing tears this listener down, and a
	 * scroll event the browser had already queued is a no-op rather than a
	 * second open.
	 *
	 * `fire()` is called with no argument. Its optional parameter is the element
	 * the visitor interacted with, and scrolling is not an interaction with any
	 * one element — so focus returns to wherever it already was.
	 *
	 * @param {Object}   api              Trigger API: `fire()`, `abort()`, `popup`, `element`.
	 * @param {Function} api.fire         Opens the popup; idempotent, first-wins.
	 * @param {Object}   [values]         Stored trigger values.
	 * @param {number}   [values.percent] Depth to reach, as a percentage.
	 * @return {Function} Teardown, which removes the listener and cancels any pending frame.
	 */
	setup( api, values ) {
		const threshold = toThreshold( values?.percent );

		/*
		 * Handle of the pending animation frame, or 0 for none. Requested handles
		 * are always non-zero, so 0 is a sentinel the browser can never return,
		 * and `cancelAnimationFrame( 0 )` is a defined no-op — the teardown needs
		 * no branch of its own.
		 */
		let frame = 0;

		const check = () => {
			frame = 0;

			const percent = progress();

			if ( null === percent ) {
				/*
				 * The document measures as unscrollable and is still loading, so
				 * there is no reading yet — only a layout that has not settled.
				 * Ask the next frame rather than answering, through the same
				 * `schedule()` a scroll goes through: one place requests frames,
				 * so one handle is live at a time and the teardown cancels this
				 * one like any other.
				 */
				schedule();
			} else if ( threshold <= percent ) {
				api.fire();
			}
		};

		const schedule = () => {
			if ( 0 === frame ) {
				frame = window.requestAnimationFrame( check );
			}
		};

		window.addEventListener( 'scroll', schedule, LISTENER_OPTIONS );

		// The visitor may already be past the threshold. See the file header.
		schedule();

		return () => {
			window.removeEventListener( 'scroll', schedule, LISTENER_OPTIONS );
			window.cancelAnimationFrame( frame );
		};
	},
};
