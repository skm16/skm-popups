/**
 * popkit — the `click_selector` trigger.
 *
 * Opens a popup when the visitor clicks anything matching a selector the author
 * supplied. Armed only after pipeline stages 7 to 9 have passed, like every
 * trigger, so nothing here touches the DOM for a popup that could never show.
 *
 * ## One delegated listener, never one per element
 *
 * The obvious implementation — `querySelectorAll( selector )` at arming time
 * and a listener on each result — is wrong the moment the page changes. A block
 * rendered by another script, a region loaded over AJAX, a menu cloned into a
 * mobile drawer: each produces elements that match the author's selector and
 * that nothing is listening to. The failure is silent, because the trigger
 * still looks armed and the console says nothing. One delegated listener on the
 * document sees clicks on elements that did not exist when the popup became
 * eligible, and costs one listener instead of one per match.
 *
 * `closest()` from `event.target` decides the match, so a click on a descendant
 * counts. A visitor who clicked the `<span>` inside a themed `<button>` clicked
 * the button, and an author who wrote `.donate-button` meant the button.
 *
 * ## The click is never cancelled
 *
 * This trigger does not call `preventDefault()`, and the listener is registered
 * `passive` so that it could not if it tried. The visitor clicked something
 * they chose to click: if it was a link it must still navigate, if it was a
 * form control it must still do whatever the page does with it. A popup that
 * swallows navigation is a far worse outcome than a popup that does not appear
 * — the first breaks the site, the second only fails to add to it.
 *
 * The consequence is that a click on an ordinary link both opens the popup and
 * starts a navigation, and the navigation wins. That is the author's call to
 * make when they choose the selector, and it is a visible, obvious result
 * rather than a hijacked one.
 *
 * ## A malformed selector unarms the trigger rather than throwing
 *
 * `querySelector()` and `closest()` throw `SyntaxError` on a selector the
 * parser rejects, and the throw would land inside a delegated document-level
 * click handler — the one place an exception is most expensive, because it
 * surfaces on every click anywhere on the page. So the selector is parsed once
 * at arming, and a rejected one leaves the trigger unarmed with a single
 * console warning naming the popup. The popup then simply never opens from a
 * click, which is the fail-closed direction.
 *
 * @see docs/CLAUDE.md
 * @see docs/data-model.md
 */

/**
 * Options for the delegated click listener.
 *
 * `capture: true` puts the listener ahead of the page's own handlers, so a
 * theme or plugin calling `stopPropagation()` on its button cannot silently
 * disarm the author's trigger. Nothing here depends on running first for any
 * other reason — the click is not modified — so the ordering costs the page
 * nothing.
 *
 * `passive: true` is a promise rather than an optimisation. `click` is not a
 * scroll-blocking event, so the flag buys no scrolling performance; what it
 * buys is that "this trigger never cancels the click" becomes something the
 * browser enforces instead of something a comment asserts.
 *
 * The same object is handed to `removeEventListener()`. Listener identity
 * includes the capture flag, so removing with a different one removes nothing
 * and leaves exactly the leak the `pagehide` listener-count test looks for.
 *
 * @type {{capture: boolean, passive: boolean}}
 */
const LISTENER_OPTIONS = { capture: true, passive: true };

/**
 * Teardown for a trigger that armed nothing.
 *
 * `setup()` returns a function on every path, including the paths where it
 * decided there was nothing to listen to. The controller calls the teardown
 * unconditionally — on fire, on abort and on `pagehide` — so a branch returning
 * `undefined` would throw from the cleanup loop and take the teardown of every
 * sibling trigger down with it.
 *
 * @return {void}
 */
function noop() {}

/**
 * Reports whether the browser's selector parser accepts a selector.
 *
 * Tested against an empty document fragment rather than against the document:
 * the selector is the thing under test and matching nothing is the cheapest way
 * to learn whether it parses. An invalid selector throws here exactly as it
 * would later from `closest()`, which is the throw this function exists to keep
 * out of somebody else's click handler.
 *
 * Warns exactly once, because arming happens once per popup per pageview. There
 * is deliberately no warning for an absent or empty selector: that is an
 * unfinished configuration for the editor to flag, not a runtime fault.
 *
 * @param {string} selector Author-supplied selector.
 * @param {string} slug     Popup slug, for the diagnostic.
 * @return {boolean} True when the selector can be used.
 */
function isParsable( selector, slug ) {
	try {
		window.document.createDocumentFragment().querySelector( selector );

		return true;
	} catch {
		/*
		 * Not translated, for the reason given in `api.js`: `@wordpress/i18n` is
		 * not available to this bundle, and this is a developer-facing diagnostic
		 * naming an untranslated selector and an untranslated popup slug.
		 */
		// eslint-disable-next-line no-console -- Developer-facing diagnostic for a misconfigured trigger. Failing silently would leave a typo'd selector looking like a broken popup.
		console.warn(
			`popkit: the click_selector trigger on the popup ${ JSON.stringify(
				slug
			) } was given a selector the browser cannot parse: ${ JSON.stringify(
				selector
			) }. That popup will not open from a click.`
		);

		return false;
	}
}

export default {
	key: 'click_selector',

	/**
	 * Arms one delegated click listener for this popup.
	 *
	 * `api.fire()` is idempotent and first-wins, so the handler does not track
	 * whether it has already fired: the controller tears this listener down as
	 * part of firing, and a click the browser had already queued is a no-op
	 * rather than a second open.
	 *
	 * The **matched** element is handed to `fire()`, not `event.target`. Its
	 * optional argument is the element the visitor interacted with, and focus
	 * returns there when the popup closes — so it has to be the thing the author
	 * named, the `<button>` rather than the `<span>` inside it that happened to
	 * receive the click. Returning focus to a decorative descendant would land
	 * the visitor on something they cannot tab back to.
	 *
	 * @param {Object}   api               Trigger API: `fire()`, `abort()`, `popup`, `element`.
	 * @param {Function} api.fire          Opens the popup; idempotent, first-wins.
	 * @param {Object}   api.popup         Popup config, used here only for the slug.
	 * @param {Object}   [values]          Stored trigger values.
	 * @param {string}   [values.selector] Selector the visitor must click.
	 * @return {Function} Teardown, which removes the delegated listener.
	 */
	setup( api, values ) {
		const selector =
			'string' === typeof values?.selector ? values.selector.trim() : '';

		if ( '' === selector || ! isParsable( selector, api.popup?.slug ) ) {
			return noop;
		}

		const doc = window.document;

		const onClick = ( event ) => {
			const target = event.target;

			/*
			 * `event.target` is an element for every click that matters, but it is
			 * the document itself for a click on nothing, and a click on nothing
			 * matches no selector. Asking for `closest` rather than for a node type
			 * keeps this one comparison instead of several.
			 */
			if ( ! target || 'function' !== typeof target.closest ) {
				return;
			}

			const matched = target.closest( selector );

			if ( matched ) {
				api.fire( matched );
			}
		};

		doc.addEventListener( 'click', onClick, LISTENER_OPTIONS );

		return () => {
			doc.removeEventListener( 'click', onClick, LISTENER_OPTIONS );
		};
	},
};
