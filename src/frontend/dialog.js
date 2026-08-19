/**
 * popkit — accessible open and close for modal and banner popups.
 *
 * This module owns the DOM half of showing a popup: putting it on screen,
 * placing focus, giving focus back, and removing every listener it added. It
 * originates no popkit events — the public event surface is `events.js`, driven
 * by the controller, which passes an `onClose` callback so that close paths this
 * module never initiated (Escape, the overlay, the close button, the popup being
 * removed from the document) are still observable. The single exception relays
 * an event `events.js` already produced, and is documented on
 * `relayDetachedClose()`.
 *
 * **The DOM is the source of truth for whether a popup is open.** Nothing here
 * mirrors openness in a variable: `HTMLDialogElement` fires `close` as a queued
 * task, so for one whole task after Escape a mirror still says "open" about a
 * dialog the visitor has already watched disappear. `isShowing()` reads the
 * element instead, and `flushClosed()` completes the bookkeeping for a close the
 * DOM has already performed. See both for what a mirror got wrong.
 *
 * The accessibility rules below come from `docs/CLAUDE.md` -> Accessibility.
 * Each has a Playwright assertion behind it; none is a preference.
 *
 * - Modals are native `<dialog>` shown with `showModal()`. The platform already
 *   provides the focus trap, the backdrop, `aria-modal`, inertness of the rest
 *   of the page, and the top-layer stacking. **No focus trap is hand-rolled on
 *   top of it.** A second implementation of a trap the browser already runs is
 *   how Tab ends up escaping in exactly one browser.
 * - Escape is native `<dialog>` behavior and is never `preventDefault()`ed. The
 *   `cancel` listener here observes Escape to label the close reason; it does
 *   not intercept it.
 * - Initial focus goes to the dialog container, or failing that its heading.
 *   **Never a form field** — an autofocused input drops a screen reader user
 *   inside a control before they have heard what the popup is for, and drops a
 *   mobile user into a keyboard that covers the popup.
 * - Focus returns on close to whatever triggered the popup, or — when the open
 *   was not a user interaction — to whatever had focus beforehand.
 * - A popup can be removed from the document while open. A `MutationObserver`
 *   catches that and runs the same close path, so focus is restored rather than
 *   stranded on a detached node.
 * - Every listener added at open is removed at close, by an explicit
 *   `removeEventListener()`. Neither `{ once: true }` nor an `AbortSignal` is
 *   used: both drop a listener without a matching removal call, which is
 *   invisible to the exit-criterion test that counts add and remove pairs
 *   across ten open/close cycles.
 * - Two modals are never open at once.
 * - `prefers-reduced-motion` is not read here. Motion belongs to the stylesheet
 *   (`docs/CLAUDE.md` -> Accessibility: animations become instant, not merely
 *   faster), and this module never waits on an animation or transition before
 *   completing a close, so honoring the preference stays a pure CSS media
 *   query with no JavaScript branch to get wrong.
 *
 * Banners are not dialogs. They are shown and hidden with the `hidden`
 * attribute, take no focus on open, and never make the rest of the page inert.
 * Focus is only pulled back on close if it was inside the banner at the time,
 * so dismissing a banner cannot yank a visitor out of whatever they were doing.
 *
 * @see docs/CLAUDE.md
 * @see docs/data-model.md
 */

/**
 * Matches any element that closes the popup containing it.
 *
 * Every popup renders a real `<button>` close control with an accessible name
 * and a 44x44 hit area; there is no configuration that removes it. The rendered
 * markup carries both hooks — `Renderer::CLOSE_ATTRIBUTE` and the BEM class —
 * and either alone is enough, so a themed or hand-written popup that keeps only
 * one still dismisses. `closest()` does the matching, so an icon inside the
 * button counts as the button.
 *
 * @type {string}
 */
const CLOSE_SELECTOR = '[data-popkit-close],.popkit-popup__close';

/**
 * Name of the public close event, for the detached relay only.
 *
 * Spelled here as well as in `events.js` because `relayDetachedClose()` has to
 * name the event it forwards. It forwards one rather than composing one, so
 * `events.js` remains the only place that decides what a popkit event contains.
 *
 * @type {string}
 */
const CLOSE_EVENT = 'popkit:close';

/**
 * Bookkeeping for each popup this module has opened and not yet finished.
 *
 * Not a record of what is on screen — `isShowing()` answers that from the
 * element. An entry can outlive the popup being visible by one task, which is
 * exactly the window the queued native `close` event occupies.
 *
 * Weakly keyed so that a popup removed from the document is collectable even if
 * a caller still holds a reference to this module.
 *
 * @type {WeakMap<Element, Object>}
 */
const openState = new WeakMap();

/**
 * The elements with open state, iterable for the removal observer.
 *
 * Held strongly, which is safe because finishing a close removes the entry —
 * including the close triggered by the element leaving the document.
 *
 * @type {Set<Element>}
 */
const openElements = new Set();

/**
 * The last modal this module showed, or `null`.
 *
 * A pointer, not an answer: `isModalOpen()` asks the element whether it is
 * still showing rather than trusting that this was cleared in time.
 *
 * @type {Element|null}
 */
let openModal = null;

/**
 * Watches for open popups leaving the document. Runs only while one is open.
 *
 * @type {MutationObserver|null}
 */
let removalObserver = null;

/**
 * Adds a listener and records how to remove it again.
 *
 * @param {Element}  element Element to bind on.
 * @param {Object}   state   Open state carrying the teardown list.
 * @param {string}   type    Event type.
 * @param {Function} handler Listener.
 */
function listen( element, state, type, handler ) {
	element.addEventListener( type, handler );
	state.teardown.push( () => element.removeEventListener( type, handler ) );
}

/**
 * Whether the DOM is showing this popup right now.
 *
 * The one authority on open state, and synchronous by construction: `open` and
 * `isConnected` are properties of the element, so neither can lag behind what
 * the visitor sees.
 *
 * A mirrored flag can, and did. `HTMLDialogElement` fires `close` as a queued
 * task, so a mirror cleared in that handler is stale for a whole task after
 * Escape — long enough for `openPopup()` to refuse an open because the popup it
 * was asked for was "already open" while nothing was on screen, and long enough
 * for the late `close` event to tear down a popup that had been reopened in the
 * meantime.
 *
 * `isConnected` is the half of the answer `open` does not give: removing a
 * `<dialog>` from the document does not close it, so a detached dialog still
 * reports `open === true` while showing nothing.
 *
 * @param {Element} element Popup element.
 * @param {Object}  state   Open state captured at open time.
 *
 * @return {boolean} `true` while the popup is on screen.
 */
function isShowing( element, state ) {
	if ( ! element.isConnected ) {
		return false;
	}

	return state.isModal ? true === element.open : ! element.hidden;
}

/**
 * Completes a close the DOM has already performed, if there is one.
 *
 * Every path that changes what is on screen calls this first, so that a close
 * the platform did on its own — Escape, a `<dialog>` closed by other code, a
 * popup removed from the document — is finished before the next thing happens
 * rather than a task later. Doing nothing when the popup is still showing is
 * what makes the late `close` event harmless after a reopen.
 *
 * Reading it is free of side effects only when there is nothing to flush; that
 * is why the queries below derive from `isShowing()` and do not call this.
 *
 * @param {Element} element Popup element.
 */
function flushClosed( element ) {
	const state = openState.get( element );

	if ( ! state || isShowing( element, state ) ) {
		return;
	}

	// The popup left the document and no path labeled the close, so leaving
	// the document is what closed it. An existing label wins: a dialog closed
	// by Escape and removed afterwards was closed by Escape.
	if ( ! element.isConnected && 'api' === state.reason ) {
		state.reason = 'removed';
	}

	finish( element );
}

/**
 * Starts watching for the popup being removed from the document.
 *
 * There is no event for "you have been detached", so this is a document-wide
 * `childList` observer. It exists only while at least one popup is open, and
 * its callback is an `isConnected` check per open popup — of which there is
 * almost always exactly one.
 *
 * @param {Element} element Popup element.
 */
function watchForRemoval( element ) {
	openElements.add( element );

	if ( removalObserver ) {
		return;
	}

	removalObserver = new window.MutationObserver( () => {
		openElements.forEach( ( openElement ) => {
			if ( ! openElement.isConnected ) {
				// Leaving the document is a close the DOM has already
				// performed, and `flushClosed()` is what completes one — down
				// to labeling the reason `removed`.
				flushClosed( openElement );
			}
		} );
	} );

	removalObserver.observe( element.ownerDocument.documentElement, {
		childList: true,
		subtree: true,
	} );
}

/**
 * Focuses a node that is not focusable by default, without scrolling to it.
 *
 * @param {Element} node Node to focus.
 */
function focusQuietly( node ) {
	if ( ! node.hasAttribute( 'tabindex' ) ) {
		node.setAttribute( 'tabindex', '-1' );
	}

	node.focus( { preventScroll: true } );
}

/**
 * Places initial focus for a modal.
 *
 * The container is preferred: focusing it announces the dialog role and its
 * accessible name, then leaves the visitor at the top of the content. The
 * labeled heading is the fallback. A form field is never a candidate, and
 * `showModal()`'s own default — the first focusable descendant, which is
 * routinely an email input — is deliberately overridden here.
 *
 * @param {Element} element Dialog element.
 */
function focusDialog( element ) {
	const doc = element.ownerDocument;

	focusQuietly( element );

	if ( doc.activeElement === element ) {
		return;
	}

	const labelledBy = element.getAttribute( 'aria-labelledby' );
	const labelId = labelledBy ? labelledBy.split( ' ' )[ 0 ] : '';
	const heading =
		( labelId && doc.getElementById( labelId ) ) ||
		element.querySelector( 'h1,h2,h3,h4,h5,h6' );

	if ( heading && element.contains( heading ) ) {
		focusQuietly( heading );
	}
}

/**
 * Gives focus back after a close.
 *
 * Focus is only moved when the closing popup still holds it, or when it has
 * been stranded. If something else has taken focus in the meantime — a
 * conversion redirect, another widget — moving it again would be the rude
 * thing, so this returns without touching it.
 *
 * @param {Element} element Popup element.
 * @param {Object}  state   Open state captured at open time.
 */
function restoreFocus( element, state ) {
	const doc = element.ownerDocument;
	const active = doc.activeElement;
	const holdsFocus =
		! active ||
		active === doc.body ||
		! active.isConnected ||
		element.contains( active );

	if ( ! holdsFocus ) {
		return;
	}

	const target = state.returnTo;

	if ( target && target.isConnected && 'function' === typeof target.focus ) {
		target.focus();
		return;
	}

	// Nothing to return to: the open was programmatic and nothing had focus.
	// Landing on the body is correct — the visitor resumes at the top of the
	// document rather than on a node that no longer exists.
	if ( doc.body && active !== doc.body ) {
		doc.body.focus();
	}
}

/**
 * Runs the close path exactly once for a popup.
 *
 * Re-entrant by design: the state is deleted first, so a queued `close` event
 * arriving after an explicit close finds nothing to do.
 *
 * @param {Element} element Popup element.
 */
function finish( element ) {
	const state = openState.get( element );

	if ( ! state ) {
		return;
	}

	openState.delete( element );
	openElements.delete( element );

	if ( openModal === element ) {
		openModal = null;
	}

	if ( 0 === openElements.size && removalObserver ) {
		removalObserver.disconnect();
		removalObserver = null;
	}

	state.teardown.forEach( ( remove ) => remove() );

	if ( ! state.isModal ) {
		element.hidden = true;
	} else if ( element.open ) {
		// Closing the element is part of finishing, not part of the caller's
		// job, so every path leaves the DOM in the state this module reports.
		// Reached by an explicit close and by a popup removed while open —
		// removal leaves a `<dialog>` open, and it would show again as a
		// non-modal dialog if the markup were re-attached. `close()` only
		// queues its event, so there is no re-entry here.
		element.close();
	}

	// After the dialog has left the top layer: the rest of the document is
	// inert while it is up, and focus cannot be moved into inert content.
	restoreFocus( element, state );

	if ( ! state.onClose ) {
		return;
	}

	if ( element.isConnected ) {
		state.onClose( element, state.reason );
		return;
	}

	relayDetachedClose( element, state );
}

/**
 * Runs `onClose` for a detached popup and relays the event somewhere it lands.
 *
 * `events.js` dispatches `popkit:close` on the popup element. On one close path
 * — the popup removed from the document while open — that element is detached,
 * so the event bubbles up a fragment no listener is bound to and never reaches
 * `document`. The documented contract names that exact path ("fires for every
 * close path, including the popup being removed from the document"), so it is
 * relayed rather than lost.
 *
 * The relay keeps the name and the `detail` object it was given. It cannot also
 * keep the popup as `event.target`: reaching `document` means bubbling from
 * something still attached to it. It is dispatched on the parent the popup had
 * when it opened, while that is still connected, and on the popup's document
 * otherwise — so a container-scoped listener sees it as well as a `document`
 * one. Listeners bound to the popup element itself receive the original once,
 * from `events.js`; nothing sees both.
 *
 * The listener that catches the original is added and removed inside this call,
 * in a `finally` so that a throwing integration cannot leave it behind.
 *
 * @param {Element} element Popup element, already detached.
 * @param {Object}  state   Open state captured at open time.
 */
function relayDetachedClose( element, state ) {
	let relayed = null;
	const capture = ( event ) => {
		relayed = event;
	};

	element.addEventListener( CLOSE_EVENT, capture );

	try {
		state.onClose( element, state.reason );
	} finally {
		element.removeEventListener( CLOSE_EVENT, capture );
	}

	const parent = state.parent;
	const target =
		parent && parent.isConnected ? parent : element.ownerDocument;

	if ( ! relayed || ! target ) {
		return;
	}

	target.dispatchEvent(
		new window.CustomEvent( CLOSE_EVENT, {
			bubbles: true,
			detail: relayed.detail,
		} )
	);
}

/**
 * Options accepted by `openPopup()`.
 *
 * @typedef {Object} PopkitOpenOptions
 *
 * @property {string}   [layout]              `'modal'` or `'banner'`. Defaults
 *                                            to modal for a `<dialog>` element
 *                                            and banner for anything else.
 * @property {Element}  [trigger]             The element the visitor interacted
 *                                            with. Focus returns here on close.
 * @property {boolean}  [closeOnOverlayClick] Modal only. Whether a click on the
 *                                            backdrop closes the popup. The
 *                                            close button is unconditional and
 *                                            is never the thing this replaces.
 * @property {Function} [onClose]             Called once per close as
 *                                            `onClose( element, reason )`,
 *                                            where reason is one of `api`,
 *                                            `close-button`, `overlay`,
 *                                            `escape`, `removed`, or
 *                                            `superseded`. Every close path
 *                                            calls it, including
 *                                            `closePopup()` itself — so emit
 *                                            `popkit:close` from here and
 *                                            nowhere else, or it fires twice.
 */

/**
 * Opens a popup.
 *
 * Does nothing if the popup is still on screen. "Still on screen" is asked of
 * the element, not of a flag, so a popup dismissed with Escape reopens in the
 * same task; the close it had pending is finished first, which means its
 * `onClose` — and so `popkit:close` — runs before this open shows anything.
 *
 * Opening a modal while another modal is open closes the other one first, so
 * two are never open at once.
 *
 * @param {Element}           element   Popup element: a `<dialog>` for a modal,
 *                                      any element for a banner.
 * @param {PopkitOpenOptions} [options] Open options.
 */
export function openPopup( element, options ) {
	if ( ! element ) {
		return;
	}

	// A dialog dismissed with Escape is off the screen before its `close` event
	// is delivered. Finishing that close here is what lets the same popup be
	// opened again in the same task, instead of the open being refused for a
	// popup nobody can see.
	flushClosed( element );

	if ( openState.has( element ) ) {
		return;
	}

	const settings = options || {};
	const doc = element.ownerDocument;
	const isModal =
		'banner' !== settings.layout && 'function' === typeof element.showModal;

	if ( isModal && openModal && openModal !== element ) {
		// A modal the DOM has already closed is finished by this under the
		// reason its own path recorded, rather than being relabeled as
		// superseded by the popup that merely came next.
		closePopup( openModal, 'superseded' );
	}

	// Captured before the popup is shown, because showing a modal moves focus.
	const active = doc.activeElement;
	const returnTo =
		settings.trigger ||
		( active && active !== doc.body && ! element.contains( active )
			? active
			: null );

	if ( isModal ) {
		try {
			element.showModal();
		} catch {
			// A dialog that is already open, or one that is not in the
			// document, throws. Nothing was shown and nothing was registered,
			// so there is nothing to unwind.
			return;
		}
	} else {
		element.hidden = false;
	}

	const state = {
		isModal,
		returnTo,
		reason: 'api',
		onClose: settings.onClose,
		teardown: [],

		// Where to relay `popkit:close` if the popup is closed by being taken
		// out of the document. Read now because by the time that is observed
		// the element has no parent left to ask.
		parent: element.parentNode,
	};

	openState.set( element, state );

	if ( isModal ) {
		openModal = element;
	}

	listen( element, state, 'click', ( event ) => {
		if ( event.target.closest( CLOSE_SELECTOR ) ) {
			closePopup( element, 'close-button' );
			return;
		}

		if (
			isModal &&
			settings.closeOnOverlayClick &&
			event.target === element &&
			isOutsideBox( element, event )
		) {
			closePopup( element, 'overlay' );
		}
	} );

	if ( isModal ) {
		// Observing `cancel` labels the close reason for integrations. It must
		// not call preventDefault(): Escape closing a dialog is a platform
		// guarantee this plugin does not get to withdraw.
		listen( element, state, 'cancel', () => {
			state.reason = 'escape';
		} );

		// The ordinary arrival of a dialog close, one task late. `flushClosed()`
		// rather than `finish()`, because this event can also be the one queued
		// by a close that has already been finished — and if the popup was
		// reopened in between, the element is showing again and this stale
		// event must not take it back down.
		listen( element, state, 'close', () => flushClosed( element ) );
	}

	watchForRemoval( element );

	if ( isModal ) {
		focusDialog( element );
	}
}

/**
 * Whether a click landed outside the element's own box.
 *
 * A backdrop click reports the `<dialog>` itself as its target, and so does a
 * click on the dialog's padding. Comparing against the box keeps padding from
 * behaving like the backdrop whatever the stylesheet does with spacing.
 *
 * @param {Element}    element Dialog element.
 * @param {MouseEvent} event   Click event.
 *
 * @return {boolean} `true` when the pointer was outside the element.
 */
function isOutsideBox( element, event ) {
	const box = element.getBoundingClientRect();

	return (
		event.clientX < box.left ||
		event.clientX > box.right ||
		event.clientY < box.top ||
		event.clientY > box.bottom
	);
}

/**
 * Closes a popup.
 *
 * Safe to call on a popup that is not open, and safe to call twice. The
 * `onClose` callback supplied at open time runs exactly once, whichever path
 * got here.
 *
 * @param {Element} element  Popup element.
 * @param {string}  [reason] Why it closed. Defaults to `api`. See
 *                           `PopkitOpenOptions.onClose`.
 */
export function closePopup( element, reason ) {
	if ( ! element ) {
		return;
	}

	// A close the DOM performed on its own is completed under its own reason,
	// and leaves nothing here to close.
	flushClosed( element );

	const state = openState.get( element );

	if ( ! state ) {
		return;
	}

	if ( reason ) {
		state.reason = reason;
	}

	// `finish()` closes the element. The `close` event that produces is queued,
	// not synchronous, and by the time it is delivered there is no state left
	// and the listener that would have read it has been removed.
	finish( element );
}

/**
 * Whether a modal is open right now.
 *
 * Exposed so the controller can decide what a second eligible popup should do
 * rather than having that decided for it here.
 *
 * @return {boolean} `true` while a modal is open.
 */
export function isModalOpen() {
	return null !== openModal && isPopupOpen( openModal );
}

/**
 * Whether a specific popup is open right now.
 *
 * Answered from the element, so the answer changes the instant the popup leaves
 * the screen — including during the task between Escape and the `close` event
 * it queues. Both queries are free of side effects on purpose: a caller asking
 * whether a popup is open should not find focus moved or `popkit:close`
 * dispatched from inside the question. The close that is pending in that window
 * is finished by whichever comes first, the queued `close` event or the next
 * call to `openPopup()` or `closePopup()`.
 *
 * @param {Element} element Popup element.
 *
 * @return {boolean} `true` while that popup is on screen.
 */
export function isPopupOpen( element ) {
	const state = element ? openState.get( element ) : null;

	return Boolean( state ) && isShowing( element, state );
}
