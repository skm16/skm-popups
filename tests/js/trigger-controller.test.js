/**
 * Unit tests for pipeline stage 10 — arming, first fire wins, and teardown.
 *
 * `src/frontend/trigger-controller.js` owns three promises that nothing else in
 * the bundle can keep, and all three are about what happens *after* a popup is
 * eligible:
 *
 * 1. **First fire wins, and firing ends the popup's arming.** Multiple triggers
 *    on one popup race. The winner opens the popup once and every sibling is
 *    torn down — not merely ignored. A sibling left armed is a listener with
 *    nothing to open, and on a `click_selector` trigger it is a listener sitting
 *    on `document` for the rest of the pageview.
 * 2. **Teardown is required, and it runs on fire, on abort and on the `pagehide`
 *    that means the document is going away.** The listener-count test below is
 *    the whole reason the contract says `setup()` must return a function, and
 *    the round-trip test beside it is why `persisted` has to be read: a document
 *    frozen into the back/forward cache is replayed rather than rebuilt, so a
 *    teardown treated as final there is a page of inert popups.
 * 3. **A defective trigger costs its own popup and nothing else.** An
 *    unregistered type, a `setup()` that returns nothing, a `setup()` that
 *    throws: each is reported loudly and none of them may strand the siblings.
 *
 * ## Why the assertions are on the teardown spies rather than on the popup
 *
 * "The popup opened once" is the cheap assertion and it is the one that passes
 * against a broken implementation. A controller that opened once and left three
 * listeners bound would satisfy it exactly as well as a correct one. So every
 * test here asserts on the teardown functions themselves — that they were
 * called, how many times, and in the leak test, that the listeners they were
 * supposed to remove are actually gone from the DOM.
 *
 * @see docs/CLAUDE.md -> Architecture invariants
 * @see docs/build-plan.md -> Phase 3
 * @see src/frontend/trigger-controller.js
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
 * Live listener records, one per `addEventListener()` the DOM actually kept.
 *
 * @type {Object[]}
 */
let live = [];

/**
 * Targets whose listener methods are wrapped right now, with their originals.
 *
 * @type {Object[]}
 */
let wrapped = [];

/**
 * The popup's root element, replaced for every test.
 *
 * @type {Element|null}
 */
let element = null;

/**
 * Loads a fresh copy of the module under test.
 *
 * `trigger-controller.js` keeps the set of armed popups in module scope and
 * shares one `pagehide` listener between them. A copy carried over from an
 * earlier test would start with somebody else's bookkeeping in it, and the
 * listener-count assertions would be measuring two tests at once.
 *
 * @return {Promise<Object>} The module's exports.
 */
async function loadTriggerController() {
	jest.resetModules();

	return import( '../../src/frontend/trigger-controller.js' );
}

/**
 * Normalizes the third argument of add/removeEventListener to a capture flag.
 *
 * Listener identity in the DOM is target, type, handler and *capture* — nothing
 * else. `{ passive: true }` and `{ passive: false }` name the same listener,
 * while `{ capture: true }` and no options at all name two different ones.
 *
 * This is the difference between a counter that means something and one that
 * does not. A naive counter that decremented on every `removeEventListener()`
 * call would report a teardown removing its listener with mismatched options as
 * clean, which is precisely the leak these tests exist to catch.
 *
 * @param {boolean|Object} [options] Third argument, as it was passed.
 * @return {boolean} Whether the listener is a capturing one.
 */
function isCapturing( options ) {
	if ( null !== options && 'object' === typeof options ) {
		return Boolean( options.capture );
	}

	return Boolean( options );
}

/**
 * Finds a live listener record.
 *
 * @param {Object}   target  Event target.
 * @param {string}   type    Event type.
 * @param {Function} handler Listener.
 * @param {boolean}  capture Capture flag.
 * @return {Object|null} The record, or null when no such listener is bound.
 */
function findListener( target, type, handler, capture ) {
	return (
		live.find(
			( record ) =>
				record.target === target &&
				record.type === type &&
				record.handler === handler &&
				record.capture === capture
		) || null
	);
}

/**
 * Wraps `addEventListener`/`removeEventListener` on the given targets.
 *
 * The wrappers model the DOM's own bookkeeping rather than counting calls: an
 * identical listener added twice is one listener, and a removal that names a
 * listener nobody bound removes nothing.
 *
 * @param {Object[]} targets Event targets to watch.
 * @return {void}
 */
function trackListeners( targets ) {
	for ( const target of targets ) {
		const add = target.addEventListener;
		const remove = target.removeEventListener;

		wrapped.push( {
			target,
			add: Object.getOwnPropertyDescriptor( target, 'addEventListener' ),
			remove: Object.getOwnPropertyDescriptor(
				target,
				'removeEventListener'
			),
		} );

		target.addEventListener = function ( type, handler, options ) {
			const capture = isCapturing( options );

			if ( ! findListener( target, type, handler, capture ) ) {
				live.push( { target, type, handler, capture } );
			}

			return add.call( this, type, handler, options );
		};

		target.removeEventListener = function ( type, handler, options ) {
			const record = findListener(
				target,
				type,
				handler,
				isCapturing( options )
			);

			if ( record ) {
				live.splice( live.indexOf( record ), 1 );
			}

			return remove.call( this, type, handler, options );
		};
	}
}

/**
 * Restores one wrapped method to whatever the environment had there before.
 *
 * `addEventListener` is inherited from `EventTarget.prototype`, so wrapping it
 * created an own property and deleting that one is the restoration.
 *
 * @param {Object}      target     Event target.
 * @param {string}      name       Method name.
 * @param {Object|void} descriptor Own property descriptor, if there was one.
 * @return {void}
 */
function restoreMethod( target, name, descriptor ) {
	if ( descriptor ) {
		Object.defineProperty( target, name, descriptor );
	} else {
		delete target[ name ];
	}
}

/**
 * Unwraps every tracked target and forgets what was bound.
 *
 * @return {void}
 */
function restoreListeners() {
	for ( const record of wrapped ) {
		restoreMethod( record.target, 'addEventListener', record.add );
		restoreMethod( record.target, 'removeEventListener', record.remove );
	}

	wrapped = [];
	live = [];
}

/**
 * How many listeners are bound right now, across every tracked target.
 *
 * @return {number} Live listener count.
 */
function listenerCount() {
	return live.length;
}

/**
 * Event types currently bound, sorted and deduplicated.
 *
 * @return {string[]} Bound event types.
 */
function listenerTypes() {
	return [ ...new Set( live.map( ( record ) => record.type ) ) ].sort();
}

/**
 * Builds a `pagehide` or `pageshow` event carrying a `persisted` flag.
 *
 * `persisted` is the browser saying whether this document is being frozen into
 * the back/forward cache rather than thrown away, and it is the whole of the
 * difference between the two teardowns. jsdom does not implement
 * `PageTransitionEvent` everywhere, so the flag is defined on a plain event when
 * the constructor is missing — what is under test is the property the runtime
 * reads, not the interface it arrived on.
 *
 * @param {string}  name      `pagehide` or `pageshow`.
 * @param {boolean} persisted Whether the document is going into, or coming out
 *                            of, the back/forward cache.
 * @return {Object} The event, ready to dispatch.
 */
function pageTransition( name, persisted ) {
	if ( 'function' === typeof window.PageTransitionEvent ) {
		return new window.PageTransitionEvent( name, { persisted } );
	}

	const event = new window.Event( name );

	Object.defineProperty( event, 'persisted', { value: persisted } );

	return event;
}

/**
 * Dispatches a bubbling, cancellable click, as a visitor's would be.
 *
 * @param {Element} target Element to click.
 * @return {void}
 */
function click( target ) {
	target.dispatchEvent(
		new window.MouseEvent( 'click', { bubbles: true, cancelable: true } )
	);
}

/**
 * Adds a button to the page for a `click_selector` trigger to match.
 *
 * @param {string} className Class attribute the selector matches.
 * @return {Element} The button, now in the document.
 */
function renderButton( className ) {
	const button = window.document.createElement( 'button' );

	// Never a submit button: jsdom implements no form submission, and the
	// attempt would reach the console as an unimplemented-navigation error.
	button.type = 'button';
	button.className = className;

	window.document.body.appendChild( button );

	return button;
}

/**
 * Builds an emitted popup record carrying the given trigger configs.
 *
 * @param {...(string|Object)} configs Trigger type, or a whole `{ type, values }`.
 * @return {Object} Popup config object, as `controller.js` would hand it over.
 */
function popupWith( ...configs ) {
	return {
		id: POPUP_ID,
		slug: POPUP_SLUG,
		triggers: configs.map( ( config ) =>
			'string' === typeof config ? { type: config } : config
		),
	};
}

/**
 * Builds a trigger module that arms nothing and records what it was handed.
 *
 * @param {Function} [setup] Implementation of `setup()`. Defaults to one that
 *                           returns a teardown spy.
 * @return {Object} `{ module, setup, teardown }`.
 */
function fakeTrigger( setup ) {
	const teardown = jest.fn();
	const spy = jest.fn( setup || ( () => teardown ) );

	return { module: { key: 'fake', setup: spy }, setup: spy, teardown };
}

/**
 * The trigger API a fake trigger's `setup()` was called with.
 *
 * @param {Object} trigger Fake trigger, as returned by {@link fakeTrigger}.
 * @return {Object} The `PopkitTriggerApi` object.
 */
function apiOf( trigger ) {
	return trigger.setup.mock.calls[ 0 ][ 0 ];
}

/**
 * Messages `console.warn()` has been called with so far.
 *
 * `@wordpress/jest-console` replaces `console.warn` with a spy and fails any
 * test that warns without asserting it, so the matcher call is mandatory
 * wherever a warning is expected. What the matcher cannot say is *which* popup
 * or trigger the warning was about, and a diagnostic naming the wrong one is a
 * diagnostic that sends a developer to the wrong file.
 *
 * @return {string[]} First argument of each call, as a string.
 */
function warnings() {
	// eslint-disable-next-line no-console -- Reading the spy `@wordpress/jest-console` installed, not writing to the console.
	const spy = console.warn;

	return spy.mock
		? spy.mock.calls.map( ( call ) => String( call[ 0 ] ) )
		: [];
}

beforeEach( () => {
	element = window.document.createElement( 'div' );
	window.document.body.appendChild( element );

	/*
	 * jsdom's selector engine binds `mouseover` and `mouseout` on the document
	 * the first time anything calls `querySelector()`, so that it can answer
	 * `:hover`. `click_selector` parses its selector through a fragment's
	 * `querySelector()`, so without this warm-up the baseline would be taken
	 * before two listeners that appear during arming, belong to the environment,
	 * and are not popkit's to remove. Filtering them out afterwards would have
	 * hidden a real leak of the same two types.
	 */
	window.document.querySelector( 'html' );

	trackListeners( [ window, window.document ] );
} );

afterEach( () => {
	/*
	 * Anything a test left armed is torn down here rather than carried into the
	 * next one. jsdom keeps one window for the whole file, so a stranded
	 * `pagehide` listener would belong to a module instance that no longer
	 * exists and would answer for a popup that no longer exists either.
	 */
	window.dispatchEvent( new window.Event( 'pagehide' ) );

	restoreListeners();

	jest.useRealTimers();

	/*
	 * Deliberately no `jest.restoreAllMocks()`. `@wordpress/jest-console`
	 * installs its own spy on `console.warn` once per file, and restoring every
	 * mock would take that one with it — leaving `expect( console )
	 * .toHaveWarned()` reading a function with no `mock` on it from the next
	 * test onwards. Nothing in this file spies on anything global; the listener
	 * wrappers are plain properties and `restoreListeners()` puts them back.
	 */

	window.document.body.replaceChildren();
	element = null;
} );

describe( 'armTriggers -> first fire wins', () => {
	it( 'tears the sibling down when the first trigger fires', async () => {
		const { armTriggers } = await loadTriggerController();
		const first = fakeTrigger();
		const second = fakeTrigger();
		const onFire = jest.fn();

		armTriggers(
			popupWith( 'first', 'second' ),
			element,
			{ first: first.module, second: second.module },
			onFire
		);

		// Both armed, and neither has been torn down by arming itself.
		expect( first.setup ).toHaveBeenCalledTimes( 1 );
		expect( second.setup ).toHaveBeenCalledTimes( 1 );
		expect( second.teardown ).not.toHaveBeenCalled();

		apiOf( first ).fire();

		/*
		 * The assertion that matters is this one, not "the popup opened once".
		 * A controller that opened once and left the sibling listening would
		 * pass an open-count assertion and leave a `click_selector` listener on
		 * `document` for the rest of the pageview, waiting to open a popup that
		 * is already on screen.
		 */
		expect( second.teardown ).toHaveBeenCalledTimes( 1 );

		// Including the trigger that fired. It is not exempt from its own rule.
		expect( first.teardown ).toHaveBeenCalledTimes( 1 );
		expect( onFire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'opens once however many times fire() is called', async () => {
		const { armTriggers } = await loadTriggerController();
		const trigger = fakeTrigger();
		const onFire = jest.fn();

		armTriggers(
			popupWith( 'fake' ),
			element,
			{ fake: trigger.module },
			onFire
		);

		const api = apiOf( trigger );

		api.fire();
		api.fire();
		api.fire();

		/*
		 * Idempotence is a flag rather than a "did the popup open" check, so the
		 * second and third calls are no-ops even though the first may have been
		 * vetoed by `popkit:before-open`. Re-arming on a veto would ask a
		 * consent integration the same question on every scroll event.
		 */
		expect( onFire ).toHaveBeenCalledTimes( 1 );
		expect( trigger.teardown ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'forwards the element the visitor interacted with', async () => {
		const { armTriggers } = await loadTriggerController();
		const trigger = fakeTrigger();
		const onFire = jest.fn();
		const button = window.document.createElement( 'button' );

		armTriggers(
			popupWith( 'fake' ),
			element,
			{ fake: trigger.module },
			onFire
		);
		apiOf( trigger ).fire( button );

		// Focus returns to this element when the popup closes, so losing it
		// between the trigger and the controller is an accessibility failure
		// rather than a cosmetic one.
		expect( onFire ).toHaveBeenCalledWith( button );
	} );

	it( 'hands every trigger the same api, carrying the popup and element', async () => {
		const { armTriggers } = await loadTriggerController();
		const first = fakeTrigger();
		const second = fakeTrigger();
		const popup = popupWith( 'first', 'second' );

		armTriggers(
			popup,
			element,
			{ first: first.module, second: second.module },
			jest.fn()
		);

		expect( apiOf( first ) ).toBe( apiOf( second ) );
		expect( apiOf( first ).popup ).toBe( popup );
		expect( apiOf( first ).element ).toBe( element );
	} );

	it( 'passes stored values through, and {} when there are none', async () => {
		const { armTriggers } = await loadTriggerController();
		const configured = fakeTrigger();
		const bare = fakeTrigger();
		const values = { delay_ms: 3000 };

		armTriggers(
			popupWith( { type: 'configured', values }, 'bare' ),
			element,
			{ configured: configured.module, bare: bare.module },
			jest.fn()
		);

		expect( configured.setup.mock.calls[ 0 ][ 1 ] ).toBe( values );
		expect( bare.setup.mock.calls[ 0 ][ 1 ] ).toEqual( {} );
	} );
} );

describe( 'armTriggers -> abort', () => {
	it( 'tears every trigger down without opening', async () => {
		const { armTriggers } = await loadTriggerController();
		const first = fakeTrigger();
		const second = fakeTrigger();
		const onFire = jest.fn();

		armTriggers(
			popupWith( 'first', 'second' ),
			element,
			{ first: first.module, second: second.module },
			onFire
		);
		apiOf( first ).abort();

		expect( first.teardown ).toHaveBeenCalledTimes( 1 );
		expect( second.teardown ).toHaveBeenCalledTimes( 1 );
		expect( onFire ).not.toHaveBeenCalled();
	} );

	it( 'ignores a trigger that fires after the abort', async () => {
		const { armTriggers } = await loadTriggerController();
		const trigger = fakeTrigger();
		const onFire = jest.fn();

		armTriggers(
			popupWith( 'fake' ),
			element,
			{ fake: trigger.module },
			onFire
		);

		const api = apiOf( trigger );

		api.abort();
		api.fire();

		// A listener the browser had already queued when the abort ran must not
		// resurrect the popup the abort just retired.
		expect( onFire ).not.toHaveBeenCalled();
		expect( trigger.teardown ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'armTriggers -> the returned teardown', () => {
	it( 'is idempotent and does not throw when called twice', async () => {
		const { armTriggers } = await loadTriggerController();
		const trigger = fakeTrigger();
		const disarm = armTriggers(
			popupWith( 'fake' ),
			element,
			{ fake: trigger.module },
			jest.fn()
		);

		expect( () => {
			disarm();
			disarm();
		} ).not.toThrow();

		// Not merely "did not throw": a second pass over the list would call
		// `removeEventListener` twice, which is harmless, and `clearTimeout` on
		// a handle the browser may have reissued, which is not.
		expect( trigger.teardown ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'runs the remaining teardowns when one of them throws', async () => {
		const { armTriggers } = await loadTriggerController();
		const thrower = fakeTrigger( () => () => {
			throw new Error( 'teardown exploded' );
		} );
		const survivor = fakeTrigger();
		const disarm = armTriggers(
			popupWith( 'thrower', 'survivor' ),
			element,
			{ thrower: thrower.module, survivor: survivor.module },
			jest.fn()
		);

		expect( () => disarm() ).not.toThrow();

		// The listeners the loop exists to remove are the reason it cannot be
		// allowed to stop halfway.
		expect( survivor.teardown ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'is returned even for a popup with no triggers at all', async () => {
		const { armTriggers } = await loadTriggerController();
		const before = listenerCount();
		const disarm = armTriggers( popupWith(), element, {}, jest.fn() );

		expect( typeof disarm ).toBe( 'function' );
		expect( () => disarm() ).not.toThrow();

		// Nothing was armed, so nothing — including the shared `pagehide`
		// listener — may have been bound on the popup's behalf.
		expect( listenerCount() ).toBe( before );
	} );
} );

describe( 'armTriggers -> pagehide', () => {
	it( 'removes every listener and timer the built-in triggers created', async () => {
		jest.useFakeTimers();

		const { armTriggers } = await loadTriggerController();
		const onFire = jest.fn();

		/*
		 * The real four, through a `Map` keyed exactly as `controller.js` builds
		 * it. A fake trigger would prove the controller calls a teardown; only
		 * the shipped modules prove the teardowns they return actually undo
		 * what their `setup()` did.
		 */
		const registry = new Map(
			[ pageLoad, scrollDepth, clickSelector, deepLink ].map(
				( trigger ) => [ trigger.key, trigger ]
			)
		);

		const baseline = listenerCount();

		armTriggers(
			popupWith(
				// Long enough that the timer cannot fire during the test.
				{ type: 'page_load', values: { delay_ms: 600000 } },
				{ type: 'scroll_depth', values: { percent: 50 } },
				{
					type: 'click_selector',
					values: { selector: '.popkit-open' },
				},
				{ type: 'deep_link' }
			),
			element,
			registry,
			onFire
		);

		/*
		 * The premise. Nothing fired, so the teardown under test is the one
		 * `pagehide` runs rather than the one firing runs — and the listener
		 * count below cannot return to baseline for the wrong reason.
		 */
		expect( onFire ).not.toHaveBeenCalled();
		expect( listenerCount() ).toBeGreaterThan( baseline );

		// Named, so a trigger that quietly stopped binding anything cannot make
		// this test pass by having nothing left to leak.
		expect( listenerTypes() ).toEqual( [
			'click',
			'hashchange',
			'pagehide',
			'scroll',
		] );
		expect( jest.getTimerCount() ).toBeGreaterThan( 0 );

		window.dispatchEvent( new window.Event( 'pagehide' ) );

		/*
		 * Back to where the page started. This is the exit criterion in
		 * `docs/build-plan.md` -> Phase 3, and it covers the shared `pagehide`
		 * listener itself: the last popup to disarm removes it.
		 */
		expect( listenerCount() ).toBe( baseline );
		expect( jest.getTimerCount() ).toBe( 0 );
	} );

	it( 'tears down every armed popup, not just the first', async () => {
		const { armTriggers } = await loadTriggerController();
		const first = fakeTrigger();
		const second = fakeTrigger();

		armTriggers(
			popupWith( 'fake' ),
			element,
			{ fake: first.module },
			jest.fn()
		);
		armTriggers(
			popupWith( 'fake' ),
			element,
			{ fake: second.module },
			jest.fn()
		);

		window.dispatchEvent( new window.Event( 'pagehide' ) );

		// One shared listener serves every armed popup, so a loop that stopped
		// at the first entry would strand every popup after it.
		expect( first.teardown ).toHaveBeenCalledTimes( 1 );
		expect( second.teardown ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'leaves a popup working after a back/forward cache round trip', async () => {
		const { armTriggers } = await loadTriggerController();
		const onFire = jest.fn();
		const button = renderButton( 'popkit-open' );

		armTriggers(
			popupWith( {
				type: 'click_selector',
				values: { selector: '.popkit-open' },
			} ),
			element,
			new Map( [ [ clickSelector.key, clickSelector ] ] ),
			onFire
		);

		/*
		 * `pagehide` with `persisted: true` is the browser freezing this document
		 * into the back/forward cache, not discarding it. The same document is
		 * replayed on the way back — no script re-runs, so nothing re-arms — and
		 * a teardown treated as final leaves the visitor on a page whose popups
		 * are inert with nothing on screen to say why.
		 */
		window.dispatchEvent( pageTransition( 'pagehide', true ) );
		window.dispatchEvent( pageTransition( 'pageshow', true ) );

		click( button );

		// Asserted through a shipped trigger rather than through the module's
		// bookkeeping, because whether the restore re-arms or never disarmed is
		// the runtime's business; that the popup still opens is the contract.
		expect( onFire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops listening for pagehide once a popup has fired', async () => {
		const { armTriggers } = await loadTriggerController();
		const trigger = fakeTrigger();
		const before = listenerCount();

		armTriggers(
			popupWith( 'fake' ),
			element,
			{ fake: trigger.module },
			jest.fn()
		);

		expect( listenerCount() ).toBeGreaterThan( before );

		apiOf( trigger ).fire();

		// Firing is a teardown like any other, so an opened popup leaves no
		// more behind than an aborted one.
		expect( listenerCount() ).toBe( before );
	} );
} );

describe( 'armTriggers -> a trigger that cannot be armed', () => {
	it( 'skips an unregistered type, warns, and leaves the sibling armed', async () => {
		const { armTriggers } = await loadTriggerController();
		const registered = fakeTrigger();
		const onFire = jest.fn();

		armTriggers(
			popupWith( 'no_such_trigger', 'registered' ),
			element,
			{ registered: registered.module },
			onFire
		);

		expect( console ).toHaveWarned();

		// Naming both, because a diagnostic that says only "a trigger was not
		// registered" sends the developer to the wrong popup on a site with
		// twenty of them.
		expect( warnings() ).toHaveLength( 1 );
		expect( warnings()[ 0 ] ).toContain( 'no_such_trigger' );
		expect( warnings()[ 0 ] ).toContain( POPUP_SLUG );

		// And the popup is not written off: its other trigger still opens it.
		expect( registered.setup ).toHaveBeenCalledTimes( 1 );

		apiOf( registered ).fire();

		expect( onFire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'warns and keeps the siblings tearable when setup() returns nothing', async () => {
		const { armTriggers } = await loadTriggerController();
		const first = fakeTrigger();
		const silent = fakeTrigger( () => undefined );
		const last = fakeTrigger();
		const disarm = armTriggers(
			popupWith( 'first', 'silent', 'last' ),
			element,
			{
				first: first.module,
				silent: silent.module,
				last: last.module,
			},
			jest.fn()
		);

		expect( console ).toHaveWarned();
		expect( warnings()[ 0 ] ).toContain( 'silent' );

		expect( () => disarm() ).not.toThrow();

		/*
		 * A missing teardown is a defect in one module, and it must cost that
		 * module's trigger and nothing else.
		 *
		 * The defective trigger is in the middle deliberately. The list is
		 * drained from the end, so `first` is torn down *after* the place the
		 * defective one would have taken in it — and a drain that stopped there,
		 * because something unusable had been recorded as a teardown, would
		 * strand exactly the listeners the drain exists to remove. Ordering
		 * `silent` first would leave both assertions passing whatever happened to
		 * its slot.
		 */
		expect( last.teardown ).toHaveBeenCalledTimes( 1 );
		expect( first.teardown ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'binds nothing at all for a popup whose only trigger returns nothing', async () => {
		const { armTriggers } = await loadTriggerController();
		const silent = fakeTrigger( () => undefined );
		const before = listenerCount();
		const disarm = armTriggers(
			popupWith( 'silent' ),
			element,
			{ silent: silent.module },
			jest.fn()
		);

		expect( console ).toHaveWarned();

		/*
		 * Not even the shared `pagehide` listener. A `setup()` that returned no
		 * teardown armed nothing this module can account for, so a popup left
		 * with only that one is the same page as a popup whose every trigger was
		 * unregistered: no listeners, no popup. A stand-in teardown recorded on
		 * its behalf would bind a listener on every page carrying a popup with a
		 * broken trigger, in order to run a function that does nothing.
		 */
		expect( listenerCount() ).toBe( before );
		expect( () => disarm() ).not.toThrow();
	} );

	it( 'warns and keeps the siblings armed when setup() throws', async () => {
		const { armTriggers } = await loadTriggerController();
		const thrower = fakeTrigger( () => {
			throw new TypeError( 'selector is not a function' );
		} );
		const correct = fakeTrigger();
		const onFire = jest.fn();

		expect( () =>
			armTriggers(
				popupWith( 'thrower', 'correct' ),
				element,
				{ thrower: thrower.module, correct: correct.module },
				onFire
			)
		).not.toThrow();

		expect( console ).toHaveWarned();
		expect( warnings()[ 0 ] ).toContain( 'thrower' );

		// Arming continued past the exception, so the popup can still open.
		expect( correct.setup ).toHaveBeenCalledTimes( 1 );

		apiOf( correct ).fire();

		expect( onFire ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'arms nothing at all for a popup whose every trigger is unregistered', async () => {
		const { armTriggers } = await loadTriggerController();
		const before = listenerCount();
		const onFire = jest.fn();

		armTriggers( popupWith( 'gone', 'also_gone' ), element, {}, onFire );

		expect( console ).toHaveWarned();
		expect( warnings() ).toHaveLength( 2 );

		/*
		 * Not even the shared `pagehide` listener. A popup with nothing armed
		 * has nothing to tear down, and binding on its behalf would put a
		 * listener on every page carrying a popup whose extension is missing.
		 */
		expect( listenerCount() ).toBe( before );
		expect( onFire ).not.toHaveBeenCalled();
	} );

	it( 'tears down a trigger that fired from inside its own setup()', async () => {
		const { armTriggers } = await loadTriggerController();
		const teardown = jest.fn();
		const eager = {
			key: 'eager',
			setup: jest.fn( ( api ) => {
				api.fire();

				return teardown;
			} ),
		};
		const late = fakeTrigger();
		const onFire = jest.fn();

		armTriggers(
			popupWith( 'eager', 'late' ),
			element,
			{ eager, late: late.module },
			onFire
		);

		/*
		 * A teardown returned by a `setup()` that already fired is run at once —
		 * the popup had been disarmed before there was a teardown to record, so
		 * the list it would have joined was already drained.
		 */
		expect( onFire ).toHaveBeenCalledTimes( 1 );
		expect( teardown ).toHaveBeenCalledTimes( 1 );

		/*
		 * And arming stopped there. `page_load` with `delay_ms: 0` is the one
		 * trigger the contract lets fire from inside `setup()`, and even it
		 * defers by a task rather than accept this: the trigger after a
		 * synchronous fire is never armed rather than armed and stranded.
		 */
		expect( late.setup ).not.toHaveBeenCalled();
	} );
} );
