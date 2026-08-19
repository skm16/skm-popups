/**
 * popkit — the public DOM event surface.
 *
 * These four events are a supported extension point. Other plugins bind to
 * them, and the deferred form and donation integrations in `docs/build-plan.md`
 * are expected to be built entirely against this surface rather than against
 * popkit internals. Treat the names, the target, the bubbling behavior, and
 * the `detail` shape as a public contract: adding a field is safe, renaming or
 * removing one is a breaking change.
 *
 * | Event                | Cancelable | Meaning                                |
 * |----------------------|------------|----------------------------------------|
 * | `popkit:before-open` | yes        | About to open. Cancel to suppress.     |
 * | `popkit:open`        | no         | Opened and visible.                    |
 * | `popkit:close`       | no         | Closed, by any path including Escape.  |
 * | `popkit:convert`     | no         | The popup achieved what it existed for.|
 *
 * Every event is dispatched **on the popup element** and bubbles, so a single
 * listener on `document` sees every popup on the page:
 *
 *     document.addEventListener( 'popkit:open', ( event ) => {
 *         // event.target is the popup element.
 *         // event.detail is { id, slug }.
 *     } );
 *
 * Canceling an open is `preventDefault()` on `popkit:before-open`:
 *
 *     document.addEventListener( 'popkit:before-open', ( event ) => {
 *         if ( 'newsletter' === event.detail.slug ) {
 *             event.preventDefault();
 *         }
 *     } );
 *
 * A canceled open shows nothing and — because the popup was never seen —
 * writes no frequency record. `docs/data-model.md` -> When a popup counts as
 * seen is the rule; `emitBeforeOpen()` returning `false` is how the controller
 * learns of it.
 *
 * Third-party code signals a conversion by dispatching `popkit:convert` itself.
 * The controller listens on `document`, so an integration needs nothing from
 * this module:
 *
 *     popupElement.dispatchEvent(
 *         new CustomEvent( 'popkit:convert', { bubbles: true } )
 *     );
 *
 * Note that a listener cannot cancel by returning `false`. That works only for
 * inline handler attributes, which this plugin never emits. `preventDefault()`
 * is the mechanism; `emitBeforeOpen()` is what returns `false`.
 *
 * @see docs/CLAUDE.md
 * @see docs/data-model.md
 */

/**
 * Fired before a popup opens. The only cancelable event of the four.
 *
 * @type {string}
 */
const BEFORE_OPEN = 'popkit:before-open';

/**
 * Fired once a popup is open and visible.
 *
 * @type {string}
 */
const OPEN = 'popkit:open';

/**
 * Fired once a popup has closed, whatever closed it.
 *
 * @type {string}
 */
const CLOSE = 'popkit:close';

/**
 * Fired when a popup converts — a form submitted, a donation completed.
 *
 * @type {string}
 */
const CONVERT = 'popkit:convert';

/**
 * A popup, as the controller holds it.
 *
 * Both the element and the identifying pair are accepted so that callers with
 * only one of them still produce a usable event. Passing the element on its own
 * works too: `id` and `slug` are then read from its data attributes.
 *
 * @typedef {Object} PopkitPopup
 *
 * @property {Element} [element] The popup's root element, and the event target.
 * @property {number}  [id]      The popup's post ID.
 * @property {string}  [slug]    The popup's slug, as used by `popkit.open()`.
 */

/**
 * Resolves the element an event should be dispatched on.
 *
 * Falls back to `document` so that a malformed popup record produces an event
 * a `document` listener still receives, rather than throwing inside the
 * controller and taking the rest of the pageview down with it.
 *
 * @param {PopkitPopup|Element} popup Popup record, or the popup element.
 *
 * @return {Element|Document} Dispatch target.
 */
function resolveTarget( popup ) {
	if ( ! popup ) {
		return window.document;
	}

	if ( popup.element ) {
		return popup.element;
	}

	// Duck-typed rather than `popup instanceof Element`, because `Element` is
	// not among the globals this project's ESLint configuration declares and
	// the check gains nothing over asking whether the value can be dispatched
	// on.
	if ( 'function' === typeof popup.dispatchEvent ) {
		return popup;
	}

	return window.document;
}

/**
 * Dispatches one popkit event.
 *
 * @param {string}              name       Event name.
 * @param {PopkitPopup|Element} popup      Popup record, or the popup element.
 * @param {boolean}             cancelable Whether listeners may suppress it.
 * @param {Object}              [data]     Extra payload merged in as
 *                                         `detail.data`.
 *
 * @return {boolean} `false` when a listener called `preventDefault()`.
 */
function dispatch( name, popup, cancelable, data ) {
	const target = resolveTarget( popup );

	// When the caller passed the element itself, there is no record to read
	// from — and reading one would be actively wrong, because `element.id` is
	// the HTML id attribute rather than the popup's post ID.
	const record = popup === target ? {} : popup || {};
	const dataset = target.dataset || {};
	const fallbackId = dataset.popkitId ? Number( dataset.popkitId ) : null;

	const detail = {
		id: record.id ?? fallbackId,
		slug: record.slug || dataset.popkitSlug || '',
	};

	if ( data ) {
		detail.data = data;
	}

	return target.dispatchEvent(
		new window.CustomEvent( name, { bubbles: true, cancelable, detail } )
	);
}

/**
 * Announces that a popup is about to open, and reports whether it may.
 *
 * The controller must honor a `false` return by opening nothing **and**
 * recording nothing: a suppressed popup was never seen, so consuming the
 * visitor's single impression on it would be wrong.
 *
 * @param {PopkitPopup|Element} popup Popup record, or the popup element.
 *
 * @return {boolean} `false` when a listener canceled the open.
 */
export function emitBeforeOpen( popup ) {
	return dispatch( BEFORE_OPEN, popup, true );
}

/**
 * Announces that a popup is open and visible.
 *
 * @param {PopkitPopup|Element} popup Popup record, or the popup element.
 */
export function emitOpen( popup ) {
	dispatch( OPEN, popup, false );
}

/**
 * Announces that a popup has closed.
 *
 * Fires for every close path — the close button, the overlay, Escape,
 * `popkit.close()`, and the popup being removed from the document while open.
 *
 * @param {PopkitPopup|Element} popup Popup record, or the popup element.
 */
export function emitClose( popup ) {
	dispatch( CLOSE, popup, false );
}

/**
 * Announces that a popup converted.
 *
 * popkit itself never detects a conversion in v1; integrations dispatch this
 * event, and the controller reacts to it by applying the popup's `on_convert`
 * frequency behavior.
 *
 * @param {PopkitPopup|Element} popup  Popup record, or the popup element.
 * @param {Object}              [data] Integration-supplied payload, exposed to
 *                                     listeners as `event.detail.data`.
 */
export function emitConvert( popup, data ) {
	dispatch( CONVERT, popup, false, data );
}
