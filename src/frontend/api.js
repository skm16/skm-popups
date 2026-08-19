/**
 * popkit — the public `window.popkit` object.
 *
 * The whole of popkit's programmatic surface, and deliberately three members
 * wide. Everything else an integration needs is an event: `popkit:before-open`
 * to veto, `popkit:open`, `popkit:close` and `popkit:convert` to observe.
 *
 * ## What `open()` bypasses, and what it does not
 *
 * `popkit.open( slug )` **bypasses targeting**: pipeline stages 7, 8 and 9 —
 * client conditions, schedule and the frequency cap — are all skipped.
 * Targeting answers "should this visitor be shown this popup unprompted", and a
 * line of site code calling `open()` has already answered it. A donation popup
 * opened from a theme's own "support us" button that refused because the visitor
 * saw a different popup last Tuesday would be a bug in popkit, not a courtesy to
 * the visitor.
 *
 * That is the whole of the bypass, and it is deliberate rather than incidental.
 * `open()` **does not bypass accessibility, and it does not bypass the veto**:
 *
 * - `popkit:before-open` is dispatched and honored. Canceling it cancels a
 *   programmatic open exactly as it cancels a triggered one, so consent
 *   integrations keep working against code they did not write, and a canceled
 *   programmatic open records no impression.
 * - Focus management, the close button, Escape, focus return and the
 *   one-modal-at-a-time rule are unchanged. Anything already open is closed
 *   first, so an author cannot stack two dialogs in the top layer by accident.
 * - The popup is recorded as seen, because the visitor saw it. The cap is not
 *   consulted on the way in; it is still fed on the way out, so a programmatic
 *   open still spends the impression an automatic one would later have used.
 *
 * The honest summary is that `open()` is an explicit author action that skips
 * the question "who is this for?" and skips nothing else.
 *
 * ## This is not the deep-link path
 *
 * `?popkit={slug}` and `#popkit-{slug}` are the `deep_link` **trigger**, and a
 * trigger is stage 10 — it is armed only after stages 7 to 9 have passed, like
 * every other trigger. `docs/build-plan.md` -> Phase 3 makes it an exit
 * criterion in as many words: a deep link to a popup whose conditions fail must
 * **not** open it, because deep linking is a trigger and not a bypass. A URL is
 * supplied by whoever sent the link, and treating one as an author's explicit
 * instruction would let any visitor hand any other visitor a link that skips
 * targeting the author configured.
 *
 * The two paths are therefore separate by construction. Nothing in the deep-link
 * trigger may call `window.popkit.open()` or the runtime `open` entry point this
 * module is handed; that entry point exists to serve this bypass and carries it
 * to every caller. The trigger reaches the same popup through the controller,
 * after the pipeline, exactly as `page_load` and `scroll_depth` do.
 *
 * ## Errors
 *
 * An unknown slug warns on the console and returns false. It never throws:
 * this is called from a click handler in somebody else's theme, and an
 * exception there takes the rest of that handler down with it.
 *
 * @see docs/CLAUDE.md
 */

/**
 * Version of this public JavaScript API.
 *
 * Not the plugin version, and deliberately so: the bundle has no cache-safe way
 * to learn the plugin version — a value printed into the page would be frozen
 * at cache-fill time — and what an integration needs to branch on is the shape
 * of the contract it is calling, which is what this number describes. It
 * increments when a member of `window.popkit` is added, removed or changed.
 *
 * @type {number}
 */
export const API_VERSION = 1;

/**
 * Installs `window.popkit`.
 *
 * The runtime's methods return `null` for a slug no popup on this page carries,
 * which separates "there is nothing to open" from "it did not open" — the
 * second is an ordinary outcome of a canceled `popkit:before-open` and is not
 * worth a warning. The public methods flatten both to a boolean, because a
 * caller should not have to distinguish three states to know whether a popup is
 * on screen.
 *
 * @param {{open: Function, close: Function}} runtime Controller entry points.
 */
export function installApi( runtime ) {
	window.popkit = {
		/**
		 * Opens a popup, bypassing targeting but not `popkit:before-open`.
		 *
		 * Stages 7 to 9 are skipped; the veto, accessibility and the seen record
		 * are not. Not the route a `deep_link` trigger takes — see the file
		 * header.
		 *
		 * @param {string} slug Popup slug, as stored in the post's URL slug.
		 * @return {boolean} True when the popup is now open.
		 */
		open( slug ) {
			return resolve( runtime.open( slug ), slug, 'open' );
		},

		/**
		 * Closes a popup.
		 *
		 * @param {string} slug Popup slug.
		 * @return {boolean} True when the popup was open and is now closed.
		 */
		close( slug ) {
			return resolve( runtime.close( slug ), slug, 'close' );
		},

		version: API_VERSION,
	};
}

/**
 * Turns a runtime result into a boolean, warning when the slug matched nothing.
 *
 * The message is not translated. `@wordpress/i18n` is not available to this
 * bundle and would not be worth its bytes if it were: this is a diagnostic for
 * the developer who wrote the call, printed to a console, and it names a slug
 * and a method that are themselves untranslated.
 *
 * @param {boolean|null} result Runtime result; null means no such popup.
 * @param {*}            slug   Slug that was asked for.
 * @param {string}       method Method that was called, for the message.
 * @return {boolean} The runtime's answer, or false when there was nothing to ask.
 */
function resolve( result, slug, method ) {
	if ( null !== result ) {
		return result;
	}

	// eslint-disable-next-line no-console -- Developer-facing diagnostic for a misused public API. Failing silently would leave a typo'd slug looking like a broken popup.
	console.warn(
		`popkit: ${ method }() found no popup with the slug ${ JSON.stringify(
			slug
		) } on this page.`
	);

	return false;
}
