/**
 * Unit tests for the four triggers v1 ships.
 *
 * One describe block per trigger, and every block asserts the same three things
 * in the trigger's own terms:
 *
 * 1. **It fires when it should.** The control for everything else in the block.
 * 2. **It does not fire when it should not.** A trigger that fires early is an
 *    interruption the visitor did not earn, and it is the failure mode that
 *    gives popups their reputation.
 * 3. **Its teardown undoes what its `setup()` did.** Not "returns a function" —
 *    that the timer is cleared, the listener is gone, and the pending frame is
 *    cancelled, each asserted by trying to make the trigger fire afterwards.
 *
 * ## Why these are unit tests rather than only end-to-end ones
 *
 * Every guard here is an *absence*: a popup that does not open. From the outside
 * a guard that held and a runtime that threw look identical, so Playwright can
 * confirm the four triggers work and cannot confirm the reasons they sometimes
 * do not. The two that matter most are unobservable end to end at all — a timer
 * still pending after teardown, and a scroll listener registered without
 * `{ passive: true }`.
 *
 * @see docs/CLAUDE.md -> Architecture invariants
 * @see docs/data-model.md -> Triggers
 * @see docs/build-plan.md -> Phase 3
 */

/* global describe, it, expect, jest, beforeEach, afterEach */

import clickSelector from '../../src/frontend/triggers/click-selector.js';
import deepLink from '../../src/frontend/triggers/deep-link.js';
import pageLoad from '../../src/frontend/triggers/page-load.js';
import scrollDepth from '../../src/frontend/triggers/scroll-depth.js';

/**
 * Post ID of the popup under test.
 *
 * @type {number}
 */
const POPUP_ID = 7;

/**
 * Slug of the popup under test.
 *
 * @type {string}
 */
const POPUP_SLUG = 'unit-popup';

/**
 * Element properties the scroll-depth reading is derived from.
 *
 * @type {string[]}
 */
const SCROLL_METRICS = [ 'scrollHeight', 'clientHeight', 'scrollTop' ];

/**
 * Spies installed by the current test, restored one at a time afterwards.
 *
 * Explicitly, rather than through `jest.restoreAllMocks()`, because
 * `@wordpress/jest-console` installs its own spy on `console.warn` once per
 * file and restoring every mock would take that one with it.
 *
 * @type {Object[]}
 */
let spies = [];

/**
 * Teardowns of every trigger armed by the current test.
 *
 * jsdom keeps one window and one document for the whole file, so a trigger left
 * armed governs every test that runs after it — a leaked `scroll` listener goes
 * on asking for animation frames, and a leaked delegated `click` listener goes
 * on matching. Running the teardowns is also the closest this file gets to the
 * controller's own contract, which calls them unconditionally.
 *
 * @type {Function[]}
 */
let teardowns = [];

/**
 * Animation frames requested and not yet painted or cancelled.
 *
 * @type {Object[]}
 */
let frames = [];

/**
 * Last animation-frame handle handed out. Never zero, as in a real browser.
 *
 * @type {number}
 */
let lastFrame = 0;

/**
 * Replaces a method with a spy, remembering to restore it after the test.
 *
 * @param {Object}   target           Object owning the method.
 * @param {string}   name             Method name.
 * @param {Function} [implementation] Replacement body. Omitted, the spy calls
 *                                    through to the original.
 * @return {Object} The spy.
 */
function watch( target, name, implementation ) {
	const spy = jest.spyOn( target, name );

	if ( implementation ) {
		spy.mockImplementation( implementation );
	}

	spies.push( spy );

	return spy;
}

/**
 * Normalizes a listener options argument to its capture flag.
 *
 * Listener identity in the DOM is target, type, handler and *capture* — nothing
 * else. `{ passive: true }` and `{ passive: false }` name the same listener,
 * while `{ capture: true }` and no options at all name two different ones.
 *
 * @param {boolean|Object} [options] Third argument, as it was passed.
 * @return {boolean} Whether the listener is a capturing one.
 */
function capturing( options ) {
	if ( null !== options && 'object' === typeof options ) {
		return Boolean( options.capture );
	}

	return Boolean( options );
}

/**
 * Handlers of one type the DOM is still holding, read off the spy call logs.
 *
 * A registration is live until a `removeEventListener()` call names the same
 * handler with the same capture flag. Counting removal *calls* instead would
 * report a teardown that removed its listener with mismatched options as clean,
 * which is exactly the leak that stays invisible from the outside.
 *
 * @param {Object} add    Spy on the target's `addEventListener`.
 * @param {Object} remove Spy on the target's `removeEventListener`.
 * @param {string} type   Event type to look for.
 * @return {Function[]} Handlers still registered for that type.
 */
function liveListeners( add, remove, type ) {
	const removed = remove.mock.calls.filter( ( args ) => type === args[ 0 ] );

	return add.mock.calls
		.filter(
			( args ) =>
				type === args[ 0 ] &&
				! removed.some(
					( call ) =>
						call[ 1 ] === args[ 1 ] &&
						capturing( call[ 2 ] ) === capturing( args[ 2 ] )
				)
		)
		.map( ( args ) => args[ 1 ] );
}

/**
 * Builds the trigger API a `setup()` is handed.
 *
 * `fire` and `abort` are spies rather than working implementations: this file
 * tests what each trigger *decides*, and what firing means to the popup belongs
 * to `trigger-controller.test.js`.
 *
 * @param {string} [slug] Popup slug, which is the whole of `deep_link`'s config.
 * @return {Object} A `PopkitTriggerApi`.
 */
function fakeApi( slug = POPUP_SLUG ) {
	return {
		fire: jest.fn(),
		abort: jest.fn(),
		popup: { id: POPUP_ID, slug },
		element: null,
	};
}

/**
 * The element whose scroll position `scroll_depth` reads.
 *
 * The same expression the module uses, so the tests cannot measure something
 * the trigger does not.
 *
 * @return {Element} Scrolling element.
 */
function scrollRoot() {
	return window.document.scrollingElement || window.document.documentElement;
}

/**
 * Gives the scrolling element a layout jsdom has no way to produce.
 *
 * `scrollHeight` and `clientHeight` are prototype getters that return zero
 * without a layout engine, so they are shadowed by own properties for the
 * length of one test and deleted again afterwards.
 *
 * @param {Object} metrics Any of `scrollHeight`, `clientHeight`, `scrollTop`.
 * @return {void}
 */
function setScroll( metrics ) {
	const root = scrollRoot();

	for ( const [ name, value ] of Object.entries( metrics ) ) {
		Object.defineProperty( root, name, {
			configurable: true,
			writable: true,
			value,
		} );
	}
}

/**
 * Removes the shadowing properties, restoring jsdom's own getters.
 *
 * @return {void}
 */
function restoreScroll() {
	const root = scrollRoot();

	for ( const name of SCROLL_METRICS ) {
		delete root[ name ];
	}
}

/**
 * Puts animation frames under the test's control.
 *
 * jsdom paints nothing, so a frame is only ever requested and never run. The
 * stub hands out non-zero handles like a browser does — the trigger uses zero
 * as its "no frame pending" sentinel — and honours cancellation, which is what
 * makes the teardown assertion meaningful.
 *
 * @return {void}
 */
function stubFrames() {
	frames = [];
	lastFrame = 0;

	watch( window, 'requestAnimationFrame', ( callback ) => {
		lastFrame += 1;
		frames.push( { handle: lastFrame, callback } );

		return lastFrame;
	} );

	watch( window, 'cancelAnimationFrame', ( handle ) => {
		frames = frames.filter( ( frame ) => frame.handle !== handle );
	} );
}

/**
 * Runs every pending animation frame, as a paint would.
 *
 * @return {void}
 */
function paint() {
	const due = frames;

	frames = [];

	for ( const frame of due ) {
		frame.callback();
	}
}

/**
 * Dispatches a scroll event on the window.
 *
 * @return {void}
 */
function scroll() {
	window.dispatchEvent( new window.Event( 'scroll' ) );
}

/**
 * Adds an element to the page.
 *
 * @param {string}  tag       Tag name.
 * @param {string}  className Class attribute, which is what the selectors match.
 * @param {Element} [parent]  Where to put it. Defaults to the document body.
 * @return {Element} The element, now in the document.
 */
function render( tag, className, parent ) {
	const element = window.document.createElement( tag );

	element.className = className;

	if ( 'button' === tag ) {
		// Never a submit button: jsdom implements no form submission, and the
		// attempt would reach the console as an unimplemented-navigation error.
		element.type = 'button';
	}

	( parent || window.document.body ).appendChild( element );

	return element;
}

/**
 * Dispatches a bubbling, cancellable click, as a visitor's would be.
 *
 * @param {Element} target Element to click.
 * @return {Object} The dispatched event, for inspecting `defaultPrevented`.
 */
function click( target ) {
	const event = new window.MouseEvent( 'click', {
		bubbles: true,
		cancelable: true,
	} );

	target.dispatchEvent( event );

	return event;
}

/**
 * Arms a trigger, remembering its teardown for the end of the test.
 *
 * @param {Object} trigger  Trigger module under test.
 * @param {Object} api      Trigger API, from {@link fakeApi}.
 * @param {Object} [values] Stored trigger values.
 * @return {Function} The teardown the trigger returned.
 */
function arm( trigger, api, values ) {
	const teardown = trigger.setup( api, values );

	teardowns.push( teardown );

	return teardown;
}

/**
 * Points the document at a URL without navigating to it.
 *
 * jsdom implements no navigation, and none is wanted: both deep-link forms are
 * read off `window.location`, which `replaceState()` updates in place.
 *
 * @param {string} url URL relative to the document.
 * @return {void}
 */
function visit( url ) {
	window.history.replaceState( null, '', url );
}

afterEach( () => {
	/*
	 * Before the spies are restored, so that a teardown removing a listener or
	 * cancelling a frame still goes through the same stubs its setup() did.
	 * Every teardown here is called twice in the tests that also call it
	 * themselves, which is the contract: teardown is idempotent.
	 */
	for ( const teardown of teardowns ) {
		teardown();
	}

	for ( const spy of spies ) {
		spy.mockRestore();
	}

	teardowns = [];
	spies = [];
	frames = [];

	jest.useRealTimers();
	restoreScroll();
	visit( '/' );

	window.document.body.replaceChildren();
} );

describe( 'page_load', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	it( 'fires on the first task when delay_ms is 0', () => {
		const api = fakeApi();

		arm( pageLoad, api, { delay_ms: 0 } );

		/*
		 * Not from inside `setup()`, even at zero. Arming is still running: a
		 * synchronous fire would leave every trigger listed after this one
		 * unarmed, and would open the popup before an inline consent snippet
		 * further down the page had attached its `popkit:before-open` listener.
		 */
		expect( api.fire ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 0 );

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'waits for the configured delay before firing', () => {
		const api = fakeApi();

		arm( pageLoad, api, { delay_ms: 3000 } );
		jest.advanceTimersByTime( 2999 );

		expect( api.fire ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 1 );

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'clears the timer on teardown, so it can never fire afterwards', () => {
		const api = fakeApi();
		const teardown = arm( pageLoad, api, { delay_ms: 3000 } );

		// There is a timer to clear, so the assertion after the teardown is
		// about the teardown rather than about whether timers are faked at all.
		expect( jest.getTimerCount() ).toBe( 1 );

		teardown();

		// The visitor navigated away two seconds in. A popup opening now opens
		// over whatever they went to instead.
		expect( jest.getTimerCount() ).toBe( 0 );

		jest.advanceTimersByTime( 600000 );

		expect( api.fire ).not.toHaveBeenCalled();
	} );

	it( 'does not throw when torn down twice', () => {
		const api = fakeApi();
		const teardown = arm( pageLoad, api, { delay_ms: 3000 } );

		expect( () => {
			teardown();
			teardown();
		} ).not.toThrow();

		jest.advanceTimersByTime( 600000 );

		expect( api.fire ).not.toHaveBeenCalled();
	} );

	it( 'reads a delay stored as a numeric string', () => {
		const api = fakeApi();

		// Config values travel through JSON and through the editor, and "50"
		// meaning "fire at once" would be a silent misreading of the author.
		arm( pageLoad, api, { delay_ms: '50' } );
		jest.advanceTimersByTime( 49 );

		expect( api.fire ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 1 );

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it.each( [
		[ 'a missing value', {} ],
		[ 'no values object at all', undefined ],
		[ 'null', { delay_ms: null } ],
		[ 'a negative number', { delay_ms: -5 } ],
		[ 'something that is not a number', { delay_ms: 'soon' } ],
	] )(
		'treats %s as no delay rather than as a refusal',
		( label, values ) => {
			const api = fakeApi();

			arm( pageLoad, api, values );
			jest.advanceTimersByTime( 0 );

			// `page_load` means "fire on load"; the delay only refines when. A typo
			// must not turn into a popup that never appears with nothing on screen
			// to explain it.
			expect( api.fire ).toHaveBeenCalledTimes( 1 );
		}
	);

	it( 'clamps a delay past the timer ceiling instead of firing at once', () => {
		const api = fakeApi();

		/*
		 * `setTimeout()` keeps its delay in a signed 32-bit slot. Above that the
		 * value overflows and the callback runs on the next task, so a
		 * hand-edited delay of 34 days would open the popup immediately — the
		 * exact opposite of what the number says.
		 */
		arm( pageLoad, api, { delay_ms: 3000000000 } );
		jest.advanceTimersByTime( 0 );

		expect( api.fire ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 2147483647 );

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'scroll_depth', () => {
	beforeEach( () => {
		stubFrames();
		setScroll( { scrollHeight: 3000, clientHeight: 1000, scrollTop: 0 } );
	} );

	it( 'fires once the visitor reaches the configured depth', () => {
		const api = fakeApi();

		arm( scrollDepth, api, { percent: 50 } );
		paint();

		// The reading taken at arming: nothing has been scrolled yet.
		expect( api.fire ).not.toHaveBeenCalled();

		// 1000 of the 2000 scrollable pixels.
		setScroll( { scrollTop: 1000 } );
		scroll();
		paint();

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not fire below the configured depth', () => {
		const api = fakeApi();

		arm( scrollDepth, api, { percent: 50 } );
		paint();

		// 800 of 2000 is 40%, and 40 is not 50. Without this the test above
		// would pass against a trigger that fired on any scroll at all.
		setScroll( { scrollTop: 800 } );
		scroll();
		paint();

		expect( api.fire ).not.toHaveBeenCalled();
	} );

	it( 'fires at arming when the visitor is already past the threshold', () => {
		const api = fakeApi();

		// Arrived on `#section-4`, or came back to a page whose scroll position
		// the browser restored. There is no reason for them to scroll again.
		setScroll( { scrollTop: 1800 } );
		arm( scrollDepth, api, { percent: 50 } );

		expect( api.fire ).not.toHaveBeenCalled();

		paint();

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'treats a document that cannot scroll as fully read', () => {
		const api = fakeApi();

		// The whole page fits on screen, so the visitor has seen all of it.
		// Never firing would make one popup work on a phone and be silently
		// dead on a desktop with a tall window.
		setScroll( { scrollHeight: 800, clientHeight: 800, scrollTop: 0 } );
		arm( scrollDepth, api, { percent: 50 } );
		paint();

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'registers the scroll listener as passive', () => {
		const add = watch( window, 'addEventListener' );

		arm( scrollDepth, fakeApi(), { percent: 50 } );

		const call = add.mock.calls.find( ( args ) => 'scroll' === args[ 0 ] );

		expect( call ).toBeDefined();

		/*
		 * Without the promise, every touch scroll on every page carrying an
		 * eligible popup blocks on this handler while the browser waits to find
		 * out whether it will cancel the gesture — a measurable regression on
		 * exactly the devices least able to absorb one. Nothing here cancels
		 * anything, so the promise is free.
		 */
		expect( call[ 2 ] ).toEqual( { passive: true } );
	} );

	it( 'removes the listener with the handler and options it added', () => {
		const add = watch( window, 'addEventListener' );
		const remove = watch( window, 'removeEventListener' );
		const teardown = arm( scrollDepth, fakeApi(), { percent: 50 } );

		teardown();

		const added = add.mock.calls.find( ( args ) => 'scroll' === args[ 0 ] );
		const removed = remove.mock.calls.find(
			( args ) => 'scroll' === args[ 0 ]
		);

		expect( removed ).toBeDefined();

		/*
		 * Listener identity is type, handler and capture flag. A teardown that
		 * removed the same handler with a different options object would remove
		 * nothing, and the listener-count test in `trigger-controller.test.js`
		 * is what would notice.
		 */
		expect( removed[ 1 ] ).toBe( added[ 1 ] );
		expect( removed[ 2 ] ).toBe( added[ 2 ] );
	} );

	it( 'cancels the pending frame and stops listening on teardown', () => {
		const api = fakeApi();
		const add = watch( window, 'addEventListener' );
		const remove = watch( window, 'removeEventListener' );
		const teardown = arm( scrollDepth, api, { percent: 50 } );

		// Arming bound one listener and asked for a frame nothing has painted.
		expect( liveListeners( add, remove, 'scroll' ) ).toHaveLength( 1 );
		expect( frames ).toHaveLength( 1 );

		teardown();

		expect( window.cancelAnimationFrame ).toHaveBeenCalledWith( 1 );
		expect( frames ).toHaveLength( 0 );

		/*
		 * And the listener is gone from the DOM, which is the half nothing below
		 * can see. The module's pending-frame handle is not reset by the
		 * teardown, so a listener left bound asks for no further frames: every
		 * behavioural assertion in this file would go on passing while a `scroll`
		 * handler outlived the popup on every page carrying one.
		 */
		expect( liveListeners( add, remove, 'scroll' ) ).toHaveLength( 0 );

		// The frame does not run late, and a later scroll asks for no new one.
		setScroll( { scrollTop: 2000 } );
		paint();
		scroll();
		paint();

		expect( api.fire ).not.toHaveBeenCalled();
	} );

	it( 'coalesces a burst of scroll events into one frame', () => {
		arm( scrollDepth, fakeApi(), { percent: 50 } );
		paint();

		expect( window.requestAnimationFrame ).toHaveBeenCalledTimes( 1 );

		scroll();
		scroll();
		scroll();

		// `scroll` fires far more often than anything can usefully be measured.
		// One frame per burst is what keeps this listener off the critical path.
		expect( window.requestAnimationFrame ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'falls back to the bottom of the document for an unreadable percent', () => {
		const api = fakeApi();

		arm( scrollDepth, api, { percent: 'halfway' } );
		setScroll( { scrollTop: 1999 } );
		scroll();
		paint();

		// Erring towards firing later than the author asked, never earlier: a
		// missed impression is recoverable, an unasked-for interruption is not.
		expect( api.fire ).not.toHaveBeenCalled();

		setScroll( { scrollTop: 2000 } );
		scroll();
		paint();

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'click_selector', () => {
	it( 'fires on a click on a matching element', () => {
		const api = fakeApi();
		const button = render( 'button', 'donate' );

		arm( clickSelector, api, { selector: '.donate' } );
		click( button );

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'fires on a click on a child, and reports the matched ancestor', () => {
		const api = fakeApi();
		const button = render( 'button', 'donate' );
		const label = render( 'span', 'label', button );

		arm( clickSelector, api, { selector: '.donate' } );
		click( label );

		expect( api.fire ).toHaveBeenCalledTimes( 1 );

		/*
		 * The matched element, not `event.target`. Focus returns here when the
		 * popup closes, and a decorative `<span>` is something the visitor
		 * cannot tab back to — an accessibility failure rather than a detail.
		 */
		expect( api.fire ).toHaveBeenCalledWith( button );
	} );

	it( 'does not fire on a click on anything else', () => {
		const api = fakeApi();

		render( 'button', 'donate' );

		const other = render( 'button', 'share' );

		arm( clickSelector, api, { selector: '.donate' } );
		click( other );
		click( window.document.body );

		expect( api.fire ).not.toHaveBeenCalled();
	} );

	it( 'fires on an element added to the page after arming', () => {
		const api = fakeApi();

		arm( clickSelector, api, { selector: '.donate' } );

		/*
		 * The reason delegation is not optional. A listener bound to each
		 * `querySelectorAll()` match at arming time passes every other
		 * assertion in this block and fails this one — and fails it silently on
		 * a real site, where the trigger still looks armed and the console says
		 * nothing about the block another script rendered a second later.
		 */
		const late = render( 'button', 'donate' );

		click( late );

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not cancel the click it fires on', () => {
		const api = fakeApi();
		const button = render( 'button', 'donate' );

		arm( clickSelector, api, { selector: '.donate' } );

		const event = click( button );

		// The visitor clicked something they chose to click. A popup that
		// swallows navigation breaks the site; one that merely appears does not.
		expect( api.fire ).toHaveBeenCalledTimes( 1 );
		expect( event.defaultPrevented ).toBe( false );
	} );

	it( 'registers the delegated listener as capturing and passive', () => {
		const add = watch( window.document, 'addEventListener' );

		arm( clickSelector, fakeApi(), { selector: '.donate' } );

		const call = add.mock.calls.find( ( args ) => 'click' === args[ 0 ] );

		expect( call ).toBeDefined();

		/*
		 * Both flags are load bearing, and replacing them with `{}` changes
		 * nothing else in this block: `capture` is what keeps a theme's own
		 * `stopPropagation()` from silently disarming the author's trigger — the
		 * test below — and `passive` is what makes "this trigger never cancels
		 * the click" a promise the browser enforces rather than one a comment
		 * asserts. The second cannot be observed from a handler that never calls
		 * `preventDefault()`, so it is asserted where it is declared.
		 */
		expect( call[ 2 ] ).toEqual( { capture: true, passive: true } );
	} );

	it( 'fires even when the page stops the click propagating', () => {
		const api = fakeApi();
		const button = render( 'button', 'donate' );

		// A theme or another plugin handling its own button, as one does.
		button.addEventListener( 'click', ( event ) =>
			event.stopPropagation()
		);

		arm( clickSelector, api, { selector: '.donate' } );
		click( button );

		// The delegated listener runs in the capture phase, so it has already
		// seen the click by the time anything on the way down can stop it. A
		// bubble-phase listener would leave the popup dead on exactly the buttons
		// an author is most likely to point a trigger at.
		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops listening on teardown', () => {
		const api = fakeApi();
		const button = render( 'button', 'donate' );
		const teardown = arm( clickSelector, api, { selector: '.donate' } );

		teardown();
		click( button );

		expect( api.fire ).not.toHaveBeenCalled();
	} );

	it( 'warns and arms nothing for a selector the browser cannot parse', () => {
		const api = fakeApi();
		const button = render( 'button', 'donate' );
		let teardown;

		/*
		 * The throw would otherwise land inside a delegated document-level click
		 * handler — the most expensive place in the page for an exception,
		 * because it surfaces on every click anywhere.
		 */
		expect( () => {
			teardown = arm( clickSelector, api, { selector: ':::' } );
		} ).not.toThrow();

		expect( console ).toHaveWarned();
		expect( typeof teardown ).toBe( 'function' );
		expect( () => teardown() ).not.toThrow();

		click( button );

		expect( api.fire ).not.toHaveBeenCalled();
	} );

	it.each( [
		[ 'no selector at all', {} ],
		[ 'an empty selector', { selector: '' } ],
		[ 'whitespace', { selector: '   ' } ],
		[ 'a selector of the wrong type', { selector: 42 } ],
	] )( 'arms nothing and stays quiet for %s', ( label, values ) => {
		const api = fakeApi();
		const button = render( 'button', 'donate' );
		const teardown = arm( clickSelector, api, values );

		click( button );

		expect( typeof teardown ).toBe( 'function' );
		expect( api.fire ).not.toHaveBeenCalled();

		// An unfinished configuration is the editor's to flag. A console warning
		// on every pageview would be noise about something the author is still
		// in the middle of.
		expect( console ).not.toHaveWarned();
	} );
} );

describe( 'deep_link', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	it( 'fires for ?popkit={slug}', () => {
		const api = fakeApi();

		visit( `/appeal/?popkit=${ POPUP_SLUG }` );
		arm( deepLink, api );

		// Deferred by a task, so that the open runs after stage 10 rather than
		// in the middle of the arming loop.
		expect( api.fire ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 0 );

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'fires for #popkit-{slug}', () => {
		const api = fakeApi();

		visit( `/appeal/#popkit-${ POPUP_SLUG }` );
		arm( deepLink, api );
		jest.advanceTimersByTime( 0 );

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'fires when the fragment arrives after arming', () => {
		const api = fakeApi();

		visit( '/appeal/' );
		arm( deepLink, api );
		jest.advanceTimersByTime( 0 );

		expect( api.fire ).not.toHaveBeenCalled();

		// An in-page anchor pointing at `#popkit-{slug}` changes the URL without
		// reloading, so nothing would re-run this bundle.
		visit( `/appeal/#popkit-${ POPUP_SLUG }` );
		window.dispatchEvent( new window.Event( 'hashchange' ) );

		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it.each( [
		[ 'a different slug in the query', '/appeal/?popkit=other-popup' ],
		[ 'a different slug in the fragment', '/appeal/#popkit-other-popup' ],
		[ 'the parameter with no value', '/appeal/?popkit=' ],
		[ 'the prefix with no slug', '/appeal/#popkit-' ],
		[ 'an unrelated fragment', '/appeal/#section-4' ],
		[ 'no deep link at all', '/appeal/' ],
	] )( 'does not fire for %s', ( label, url ) => {
		const api = fakeApi();

		visit( url );
		arm( deepLink, api );
		jest.advanceTimersByTime( 0 );

		expect( api.fire ).not.toHaveBeenCalled();
	} );

	it.each( [
		[ 'a query value that is a prefix of the slug', '/?popkit=giving' ],
		[ 'a fragment that is a prefix of the slug', '/#popkit-giving' ],
		[
			'a query value the slug is a prefix of',
			'/?popkit=giving-tuesday-2',
		],
		[ 'a fragment the slug is a prefix of', '/#popkit-giving-tuesday-2' ],
	] )( 'matches the whole slug, not %s', ( label, url ) => {
		const api = fakeApi( 'giving-tuesday' );

		visit( url );
		arm( deepLink, api );
		jest.advanceTimersByTime( 0 );

		// Both comparisons are equality against the whole value. A substring
		// test would hand every visitor a link that opens somebody else's popup.
		expect( api.fire ).not.toHaveBeenCalled();
	} );

	it( 'fires for the slug those near misses were compared against', () => {
		const api = fakeApi( 'giving-tuesday' );

		visit( '/?popkit=giving-tuesday' );
		arm( deepLink, api );
		jest.advanceTimersByTime( 0 );

		// The control. Without it every assertion above would pass against a
		// trigger that had stopped matching anything at all.
		expect( api.fire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'clears the pending check and stops listening on teardown', () => {
		const api = fakeApi();

		visit( `/appeal/?popkit=${ POPUP_SLUG }` );

		const teardown = arm( deepLink, api );

		expect( jest.getTimerCount() ).toBe( 1 );

		teardown();
		jest.advanceTimersByTime( 0 );

		expect( jest.getTimerCount() ).toBe( 0 );

		visit( `/appeal/#popkit-${ POPUP_SLUG }` );
		window.dispatchEvent( new window.Event( 'hashchange' ) );

		// A popup aborted in the same task never opens, and a fragment change
		// after the visitor navigated away reaches nobody.
		expect( api.fire ).not.toHaveBeenCalled();
	} );

	it.each( [
		[ 'an empty slug', '' ],
		[ 'a missing slug', undefined ],
	] )( 'arms nothing for a popup with %s', ( label, slug ) => {
		const api = fakeApi( slug );

		visit( '/#popkit-' );

		const teardown = arm( deepLink, api );

		jest.advanceTimersByTime( 0 );

		expect( typeof teardown ).toBe( 'function' );
		expect( api.fire ).not.toHaveBeenCalled();
	} );
} );
