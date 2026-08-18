/**
 * Unit tests for the client half of the evaluation pipeline.
 *
 * `src/frontend/controller.js` owns every fail-closed decision that survives the
 * cache boundary — stages 6 to 10 of `docs/CLAUDE.md` -> Architecture
 * invariants. Each of those decisions is a refusal, and a refusal is the one
 * kind of behaviour an end-to-end test is worst at pinning: a popup that fails
 * to appear because the guard held and a popup that fails to appear because the
 * runtime threw look identical from the outside.
 *
 * So every suppression asserted here is paired with a control that opens. If a
 * guard is deleted the suppression test fails; if the harness stops working the
 * control fails; and neither can pass for the wrong reason while the other is
 * watching.
 *
 * ## The four properties
 *
 * 1. **Stage 6 is conditional.** `needsContext` is the server's answer to "is
 *    this round trip worth making". False means no request — not a speculative
 *    one, not a conditional one. A page whose popups ask nothing of the server
 *    must cost the visitor no network at all.
 * 2. **Context unavailable suppresses what depended on it.** Expressed in the
 *    controller as control flow rather than as a branch, and asserted here
 *    against an enabled schedule, which is the case where the popup's targeting
 *    is otherwise perfectly satisfied.
 * 3. **Stage 7 is a closed stub in this phase.** No client conditions are
 *    registered until Phase 4, so every emitted rule is unresolvable and every
 *    popup carrying one stays shut. `negate` is deliberately not applied to that
 *    denial — negating an unresolvable rule would turn "members only" into
 *    "everyone", which is the worst failure this plugin can have.
 * 4. **Seen is recorded after a successful, non-cancelled open, and never
 *    otherwise.** A cancelled open shows the visitor nothing, so recording it
 *    would spend their single impression on something they never saw — and the
 *    record carries the frequency object, because the mode decides whether there
 *    is anything to write at all.
 * 5. **Stage 10 is armed with the real triggers, and what a fire means is
 *    decided here.** Whether a refused open retires the trigger that asked, and
 *    whether an author's own `popkit.open()` leaves a popup's triggers armed
 *    behind it, are both this file's decisions and neither is visible from
 *    `trigger-controller.js`.
 *
 * And one property that is not a decision at all: a mangled config element must
 * cost the site its own popups and nothing else. `readConfig()` is total.
 *
 * @see docs/CLAUDE.md -> Architecture invariants
 * @see docs/data-model.md -> Evaluation semantics
 * @see docs/build-plan.md -> Phase 2
 */

/* global describe, it, expect, jest, beforeEach, afterEach */

/**
 * `id` of the script element the controller reads its config from.
 *
 * @type {string}
 */
const CONFIG_ELEMENT_ID = 'popkit-config';

/**
 * Post ID of the popup under test, and its frequency storage key.
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
 * Post ID of the second popup, where a test needs two on one page.
 *
 * @type {number}
 */
const OTHER_ID = 8;

/**
 * Slug of the second popup.
 *
 * @type {string}
 */
const OTHER_SLUG = 'other-popup';

/**
 * Storage key `frequency.js` writes a seen record under.
 *
 * @type {string}
 */
const SEEN_KEY = `popkit:seen:${ POPUP_ID }`;

/**
 * Class the trigger tests point a `click_selector` at.
 *
 * @type {string}
 */
const TRIGGER_CLASS = 'donate';

/**
 * A configured `click_selector` trigger, as the emitted config carries one.
 *
 * The real trigger module, armed by the real `armTriggers()`, because what these
 * tests are about is what the controller does with a trigger that fires — and a
 * fake one would prove only that the controller can call a function.
 *
 * @type {Object}
 */
const CLICK_TRIGGER = {
	type: 'click_selector',
	values: { selector: `.${ TRIGGER_CLASS }` },
};

/**
 * Absolute URL of the context route, as the emitted config carries it.
 *
 * @type {string}
 */
const REST_URL = 'https://example.org/wp-json/popkit/v1/context';

/**
 * Site timezone from the emitted config. A site setting, so it is cache-safe.
 *
 * @type {string}
 */
const SITE_ZONE = 'UTC';

/**
 * Server reading a successful context response carries.
 *
 * @type {number}
 */
const SERVER_TIME = 1766000123456;

/**
 * A schedule that is switched on and constrains nothing else.
 *
 * Enough to make `needsContext` true and to reach stage 8, while leaving the
 * campaign open-ended so the assertions are about context availability rather
 * than about date arithmetic — schedule.js has its own file for that.
 *
 * @type {Object}
 */
const OPEN_SCHEDULE = {
	enabled: true,
	start: null,
	end: null,
	timezone: 'site',
};

/**
 * `window.fetch` as the environment left it, restored after every test.
 *
 * @type {Function|undefined}
 */
const ORIGINAL_FETCH = window.fetch;

/**
 * Listeners this test attached to `document`, removed again afterwards.
 *
 * jsdom keeps one document for the whole file, so a listener left behind — the
 * veto in the cancellation test above all — would quietly govern every test that
 * ran after it.
 *
 * @type {Object[]}
 */
let listeners = [];

/**
 * Loads a fresh copy of the module under test.
 *
 * The controller holds one session in module scope and refuses to boot twice, so
 * a shared instance could only ever run one test.
 *
 * @return {Promise<Object>} The module's exports.
 */
async function loadController() {
	jest.resetModules();

	return import( '../../src/frontend/controller.js' );
}

/**
 * Lets the queue drain.
 *
 * The context lane is a promise chain that boot starts and deliberately never
 * awaits — nothing may delay first paint — so a test asserting on what that lane
 * did has to wait for it explicitly.
 *
 * @return {Promise<void>} Resolves once the queue is empty.
 */
async function flush() {
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

/**
 * Empties the document body without going through markup.
 *
 * @return {void}
 */
function clearDocument() {
	window.document.body.replaceChildren();
}

/**
 * Binds a listener on `document` and remembers to remove it after the test.
 *
 * @param {string}   name    Event name.
 * @param {Function} handler Listener.
 * @return {void}
 */
function onDocument( name, handler ) {
	listeners.push( { name, handler } );

	window.document.addEventListener( name, handler );
}

/**
 * Records every popkit event that reaches `document`.
 *
 * Bound on `document` rather than on the popup because that is the contract the
 * events are published under, and because it is the only place from which the
 * absence of an event can honestly be observed.
 *
 * @return {Object[]} The live array the listeners append to.
 */
function recordEvents() {
	const seen = [];

	for ( const name of [
		'popkit:before-open',
		'popkit:open',
		'popkit:close',
	] ) {
		onDocument( name, ( event ) => {
			seen.push( { name, slug: event.detail.slug, id: event.detail.id } );
		} );
	}

	return seen;
}

/**
 * Names of the recorded events, in order.
 *
 * @param {Object[]} events Recorded events.
 * @return {string[]} Event names.
 */
function names( events ) {
	return events.map( ( event ) => event.name );
}

/**
 * Adds popup markup to the document.
 *
 * A `<div>` rather than a `<dialog>`: this file is about whether the controller
 * decides to open a popup, and the banner path exercises that decision without
 * depending on how completely the environment implements `showModal()`. The
 * modal path belongs to `dialog.js` and is covered end to end.
 *
 * @param {number} id   Post ID.
 * @param {string} slug Post slug.
 * @return {Element} The popup root.
 */
function writePopup( id, slug ) {
	const root = window.document.createElement( 'div' );

	root.setAttribute( 'data-popkit-id', String( id ) );
	root.setAttribute( 'data-popkit-slug', slug );
	root.hidden = true;

	window.document.body.appendChild( root );

	return root;
}

/**
 * Adds a config element carrying the given text.
 *
 * Text rather than an object, so the malformed cases can put something in it
 * that `JSON.parse()` will refuse.
 *
 * @param {string} text Contents of the script element.
 * @return {void}
 */
function writeConfigElement( text ) {
	const script = window.document.createElement( 'script' );

	script.type = 'application/json';
	script.id = CONFIG_ELEMENT_ID;
	script.textContent = text;

	window.document.body.appendChild( script );
}

/**
 * Adds a button for a `click_selector` trigger to match.
 *
 * @return {Element} The button, now in the document.
 */
function writeButton() {
	const button = window.document.createElement( 'button' );

	// Never a submit button: jsdom implements no form submission, and the
	// attempt would reach the console as an unimplemented-navigation error.
	button.type = 'button';
	button.className = TRIGGER_CLASS;

	window.document.body.appendChild( button );

	return button;
}

/**
 * Dispatches a bubbling, cancellable click, as a visitor's would be.
 *
 * @param {Element} target Element to click.
 * @return {void}
 */
function clickOn( target ) {
	target.dispatchEvent(
		new window.MouseEvent( 'click', { bubbles: true, cancelable: true } )
	);
}

/**
 * Builds a `pagehide` or `pageshow` event carrying a `persisted` flag.
 *
 * `persisted` is the browser saying whether this document is being frozen into
 * the back/forward cache rather than discarded. jsdom does not implement
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
 * Builds one emitted popup entry.
 *
 * @param {Object} [overrides] Fields to override on the entry.
 * @return {Object} Emitted popup entry.
 */
function popupEntry( overrides ) {
	return {
		id: POPUP_ID,
		slug: POPUP_SLUG,
		conditions: { groups: [] },
		triggers: [],
		schedule: {},
		frequency: {},
		display: {},
		...overrides,
	};
}

/**
 * Builds an emitted config carrying the given popup entries.
 *
 * @param {Object[]} popups      Emitted popup entries.
 * @param {Object}   [overrides] Fields to override on the config itself.
 * @return {Object} Emitted config object.
 */
function configOf( popups, overrides ) {
	return {
		restUrl: REST_URL,
		siteTimezone: SITE_ZONE,
		needsContext: false,
		popups,
		...overrides,
	};
}

/**
 * Builds an emitted config carrying exactly one popup.
 *
 * @param {Object} [popup]     Fields to override on the popup entry.
 * @param {Object} [overrides] Fields to override on the config itself.
 * @return {Object} Emitted config object.
 */
function configFor( popup, overrides ) {
	return configOf( [ popupEntry( popup ) ], overrides );
}

/**
 * Makes the context request resolve with a well-formed payload.
 *
 * @param {Object} payload Decoded response body.
 * @return {void}
 */
function respondWith( payload ) {
	window.fetch.mockResolvedValue( {
		status: 200,
		json: async () => payload,
	} );
}

/**
 * Reads the popup's frequency records out of both storage areas.
 *
 * Both, because `recordSeen()` mirrors into both and a test asserting the
 * absence of a record has to prove neither copy was written.
 *
 * @return {Object} `{ local, session }`, either of which may be null.
 */
function seenRecords() {
	return {
		local: window.localStorage.getItem( SEEN_KEY ),
		session: window.sessionStorage.getItem( SEEN_KEY ),
	};
}

beforeEach( () => {
	clearDocument();

	window.localStorage.clear();
	window.sessionStorage.clear();

	delete window.popkit;

	window.fetch = jest.fn();
} );

afterEach( () => {
	/*
	 * Anything a test left armed is torn down here rather than carried into the
	 * next one. jsdom keeps one window and one document for the whole file, so a
	 * delegated `click` listener from an armed `click_selector` would outlive the
	 * module instance that bound it and go on matching buttons for the rest of
	 * the run. Not `persisted`, because this page really is going away.
	 */
	window.dispatchEvent( pageTransition( 'pagehide', false ) );

	for ( const { name, handler } of listeners ) {
		window.document.removeEventListener( name, handler );
	}

	listeners = [];

	jest.restoreAllMocks();

	window.fetch = ORIGINAL_FETCH;

	delete window.popkit;

	clearDocument();
} );

describe( 'boot -> stage 6, the context request', () => {
	it( 'issues no request when the emitted config says none is needed', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const events = recordEvents();

		boot( configFor() );
		await flush();

		// The popup opened, so boot really ran and really reached stage 10.
		expect( names( events ) ).toEqual( [
			'popkit:before-open',
			'popkit:open',
		] );
		expect( element.hidden ).toBe( false );

		/*
		 * And it cost the visitor nothing. A page whose popups ask the server no
		 * question must make no request — not a speculative one warming the
		 * route, not a conditional one nobody awaits.
		 */
		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	it( 'issues no request when nothing on the page has a question to ask', async () => {
		const { boot } = await loadController();

		writePopup( POPUP_ID, POPUP_SLUG );

		// The flag says yes and the popups say nothing. An empty field list makes
		// the response `{}`, which is a request with nothing to read in it.
		boot( configFor( {}, { needsContext: true } ) );
		await flush();

		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	it( 'issues exactly one request when a popup does have one', async () => {
		const { boot } = await loadController();

		writePopup( POPUP_ID, POPUP_SLUG );
		respondWith( { time: SERVER_TIME } );

		boot(
			configFor( { schedule: OPEN_SCHEDULE }, { needsContext: true } )
		);
		await flush();

		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		expect( window.fetch.mock.calls[ 0 ][ 0 ] ).toBe(
			`${ REST_URL }?fields=time`
		);
	} );
} );

describe( 'boot -> context unavailable', () => {
	it( 'suppresses a scheduled popup when the context fetch fails', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const events = recordEvents();

		window.fetch.mockRejectedValue( new TypeError( 'Failed to fetch' ) );

		boot(
			configFor( { schedule: OPEN_SCHEDULE }, { needsContext: true } )
		);
		await flush();

		/*
		 * Nothing was offered for veto, which is the point: the popup was never
		 * considered at all. An enabled schedule with no calibrated clock cannot
		 * be evaluated, and an expired campaign running again is worse than a
		 * live one missing a few impressions during an outage.
		 */
		expect( names( events ) ).toEqual( [] );
		expect( element.hidden ).toBe( true );
		expect( seenRecords() ).toEqual( { local: null, session: null } );
	} );

	it.each( [
		[ 'a non-200', { status: 503, json: async () => ( {} ) } ],
		[
			'a body that will not parse',
			{
				status: 200,
				json: async () => {
					throw new SyntaxError( 'Unexpected token <' );
				},
			},
		],
		[
			'a payload missing the key that was asked for',
			{ status: 200, json: async () => ( {} ) },
		],
	] )( 'suppresses it for %s as well', async ( label, response ) => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		window.fetch.mockResolvedValue( response );

		boot(
			configFor( { schedule: OPEN_SCHEDULE }, { needsContext: true } )
		);
		await flush();

		expect( element.hidden ).toBe( true );
	} );

	it( 'opens the same popup once context is available', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const events = recordEvents();

		respondWith( { time: SERVER_TIME } );

		boot(
			configFor( { schedule: OPEN_SCHEDULE }, { needsContext: true } )
		);
		await flush();

		// The control for every suppression above. Without it they would all
		// pass against a runtime that had simply stopped opening anything.
		expect( names( events ) ).toEqual( [
			'popkit:before-open',
			'popkit:open',
		] );
		expect( element.hidden ).toBe( false );
	} );
} );

describe( 'boot -> stage 7, the closed client-condition stub', () => {
	it( 'refuses a rule no evaluator is registered for', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const events = recordEvents();

		boot(
			configFor( {
				conditions: {
					groups: [
						{
							rules: [
								{ type: 'nobody_registered_this', values: {} },
							],
						},
					],
				},
			} )
		);
		await flush();

		/*
		 * CLIENT_RULES is empty until Phase 4, so this rule is unresolvable.
		 * Stubbing the stage to "pass" would have been the same number of
		 * characters and would have shown every targeted popup to everybody for
		 * the length of a phase.
		 */
		expect( names( events ) ).toEqual( [] );
		expect( element.hidden ).toBe( true );
		expect( seenRecords() ).toEqual( { local: null, session: null } );
	} );

	it( 'does not let negate resurrect an unresolvable rule', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot(
			configFor( {
				conditions: {
					groups: [
						{
							rules: [
								{
									type: 'nobody_registered_this',
									values: {},
									negate: true,
								},
							],
						},
					],
				},
			} )
		);
		await flush();

		/*
		 * The single most important line in the controller. `negate` inverts a
		 * decision; an unresolvable rule produced no decision to invert, and
		 * inverting the denial would turn an unavailable condition into one that
		 * passes for everybody — a deactivated extension silently turning
		 * "members only" into "everyone".
		 */
		expect( element.hidden ).toBe( true );
	} );

	it( 'refuses a rule the server tagged unknown', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot(
			configFor( {
				conditions: {
					groups: [
						{
							rules: [
								{
									type: 'url_path',
									values: {},
									unknown: true,
								},
							],
						},
					],
				},
			} )
		);
		await flush();

		// The tag records that nothing had registered the condition when the
		// config was built. A JavaScript evaluator turning up without its PHP
		// declaration is not evidence the author's targeting is understood.
		expect( element.hidden ).toBe( true );
	} );

	it.each( [
		[ 'no groups at all, which means always match', { groups: [] } ],
		[
			'a group whose rules were all stripped as server rules',
			{ groups: [ { rules: [] } ] },
		],
	] )( 'still opens a popup with %s', async ( label, conditions ) => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot( configFor( { conditions } ) );
		await flush();

		// Both empty cases are load bearing and they are not the same case. If
		// either started failing closed, popups with no targeting at all would
		// stop showing and the refusals above would pass for the wrong reason.
		expect( element.hidden ).toBe( false );
	} );
} );

describe( 'readConfig -> a mangled config costs the site its popups and nothing else', () => {
	it.each( [
		[ 'truncated JSON', '{ "popups": [ ' ],
		[ 'an empty element', '' ],
		[ 'JSON that is not an object', '"popkit"' ],
		[ 'HTML injected by an optimiser', '<!-- optimised -->' ],
	] )( 'returns null rather than throwing for %s', async ( label, text ) => {
		const { boot, readConfig } = await loadController();

		writeConfigElement( text );
		writePopup( POPUP_ID, POPUP_SLUG );

		let config;

		// A plugin whose config was mangled by an over-eager optimiser must cost
		// the site nothing but its own popups. An exception here escapes into
		// whatever else the page was doing.
		expect( () => {
			config = readConfig();
		} ).not.toThrow();

		expect( config ).toBeNull();
		expect( () => boot( config ) ).not.toThrow();

		await flush();

		expect( window.popkit ).toBeUndefined();
		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	it( 'returns null when the page carries no config element at all', async () => {
		const { readConfig } = await loadController();

		expect( readConfig() ).toBeNull();
	} );

	it( 'parses a well-formed config, so the cases above mean something', async () => {
		const { boot, readConfig } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		writeConfigElement( JSON.stringify( configFor() ) );

		boot( readConfig() );
		await flush();

		expect( element.hidden ).toBe( false );
		expect( window.popkit ).toBeDefined();
	} );
} );

describe( 'openRecord -> when a popup counts as seen', () => {
	it( 'writes no record when popkit:before-open is cancelled', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const events = recordEvents();

		onDocument( 'popkit:before-open', ( event ) => event.preventDefault() );

		boot( configFor() );
		await flush();

		// The veto was offered and taken.
		expect( names( events ) ).toEqual( [ 'popkit:before-open' ] );
		expect( element.hidden ).toBe( true );

		/*
		 * And nothing was recorded. This is the part that is easy to get wrong:
		 * an aborted open is invisible to the visitor, so recording it spends
		 * their single impression — under `once_ever`, their only one — on a
		 * popup they never saw, and the mistake stays invisible until somebody
		 * asks why the popup never appears again.
		 */
		expect( seenRecords() ).toEqual( { local: null, session: null } );
	} );

	it( 'writes the record when the open is not cancelled', async () => {
		const { boot } = await loadController();

		writePopup( POPUP_ID, POPUP_SLUG );

		boot( configFor() );
		await flush();

		const records = seenRecords();

		expect( records.local ).not.toBeNull();
		expect( records.session ).not.toBeNull();

		const stored = JSON.parse( records.local );

		expect( stored.converted ).toBe( false );
		expect( Number.isFinite( stored.at ) ).toBe( true );
	} );

	it( 'writes no record for an uncapped popup, whose storage is "none"', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot( configFor( { frequency: { mode: 'always' } } ) );
		await flush();

		// It opened, so the write was reached and declined rather than missed.
		expect( element.hidden ).toBe( false );

		/*
		 * `data-model.md` gives `always` a storage of "none", and the write can
		 * only honour that if the open tells it the mode — the storage key
		 * carries no copy of one. A record written here is one nothing ever
		 * reads, and it outlives the setting that produced it: an author
		 * switching this popup to `once_ever` would find it already suppressed
		 * for every visitor who saw it while it was uncapped, by impressions the
		 * cap was never meant to count.
		 */
		expect( seenRecords() ).toEqual( { local: null, session: null } );
	} );

	it( 'writes no record when the markup for the popup is absent', async () => {
		const { boot } = await loadController();
		const events = recordEvents();

		// The server emitted the config but not the markup. Nothing was shown,
		// so nothing may be counted — and nothing may throw.
		expect( () => boot( configFor() ) ).not.toThrow();

		await flush();

		expect( names( events ) ).toEqual( [] );
		expect( seenRecords() ).toEqual( { local: null, session: null } );
	} );

	it( 'writes no record for a popup its frequency cap already suppressed', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const events = recordEvents();

		window.localStorage.setItem(
			SEEN_KEY,
			JSON.stringify( { at: Date.now(), converted: false } )
		);

		boot( configFor( { frequency: { mode: 'once_ever' } } ) );
		await flush();

		// Stage 9 refuses before stage 10 runs, so the veto is never offered and
		// the stored timestamp is not refreshed by a pageview nobody saw.
		expect( names( events ) ).toEqual( [] );
		expect( element.hidden ).toBe( true );
	} );
} );

describe( 'boot -> stage 10, the triggers the controller armed', () => {
	it( 'opens the popup when a configured trigger fires', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const button = writeButton();

		boot( configFor( { triggers: [ CLICK_TRIGGER ] } ) );
		await flush();

		// Armed, and nothing has fired: a configured trigger list is honoured as
		// written, so this popup does not open on load.
		expect( element.hidden ).toBe( true );

		clickOn( button );

		// The control for every refusal below. Without it each of them would
		// pass against a runtime that had stopped arming triggers at all.
		expect( element.hidden ).toBe( false );
	} );

	it( 'disarms a popup its own triggers armed when the author opens it', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const button = writeButton();
		const events = recordEvents();

		boot( configFor( { triggers: [ CLICK_TRIGGER ] } ) );
		await flush();

		expect( window.popkit.open( POPUP_SLUG ) ).toBe( true );
		expect( element.hidden ).toBe( false );
		expect( window.popkit.close( POPUP_SLUG ) ).toBe( true );
		expect( element.hidden ).toBe( true );

		clickOn( button );

		/*
		 * `trigger-controller.js` tears a popup's triggers down when one of them
		 * fires, and a programmatic open is the one open it cannot see. Leaving
		 * them armed lets a click after the visitor dismissed an author-opened
		 * popup put it straight back on screen — and the frequency cap does not
		 * catch it, because stage 9 ran once, at arming time, and a fire does not
		 * re-run it.
		 */
		expect( element.hidden ).toBe( true );
		expect( names( events ) ).toEqual( [
			'popkit:before-open',
			'popkit:open',
			'popkit:close',
		] );
	} );

	it( 'keeps a trigger armed when another popup was on screen', async () => {
		const { boot } = await loadController();
		const first = writePopup( POPUP_ID, POPUP_SLUG );
		const second = writePopup( OTHER_ID, OTHER_SLUG );
		const button = writeButton();

		boot(
			configOf( [
				popupEntry(),
				popupEntry( {
					id: OTHER_ID,
					slug: OTHER_SLUG,
					triggers: [ CLICK_TRIGGER ],
				} ),
			] )
		);
		await flush();

		// The untriggered popup opened on load, and now holds the screen.
		expect( first.hidden ).toBe( false );

		clickOn( button );

		// One popup at a time. An automatic open is skipped while anything is
		// already showing, and it is not queued for later either.
		expect( second.hidden ).toBe( true );

		window.popkit.close( POPUP_SLUG );
		clickOn( button );

		/*
		 * But the trigger is still armed. "Another popup was on screen" is a
		 * refusal that may not hold next time, and treating it as the popup's one
		 * chance retires a trigger the visitor is still able to use — the button
		 * stays on the page and stops doing anything, with nothing to explain it.
		 */
		expect( second.hidden ).toBe( false );
	} );

	it( 'does not offer the veto twice for a trigger it already refused', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const button = writeButton();
		const events = recordEvents();

		onDocument( 'popkit:before-open', ( event ) => event.preventDefault() );

		boot( configFor( { triggers: [ CLICK_TRIGGER ] } ) );
		await flush();

		clickOn( button );
		clickOn( button );

		/*
		 * A cancelled open settles the popup. Re-arming on a veto would ask a
		 * consent integration the same question on every click, every scroll
		 * event, and every fire of every sibling trigger — which is the load the
		 * first-fire-wins latch exists to prevent, and the reason it cannot
		 * simply be "did the popup open".
		 */
		expect( names( events ) ).toEqual( [ 'popkit:before-open' ] );
		expect( element.hidden ).toBe( true );
	} );

	it( 'keeps a popup working across a back/forward cache round trip', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const button = writeButton();

		boot( configFor( { triggers: [ CLICK_TRIGGER ] } ) );
		await flush();

		/*
		 * The visitor followed a link and pressed Back. The same document comes
		 * out of the back/forward cache with the same DOM and no script re-run,
		 * so a `pagehide` treated as final leaves every popup on the restored
		 * page inert — and nothing on screen says why the button stopped working.
		 */
		window.dispatchEvent( pageTransition( 'pagehide', true ) );
		window.dispatchEvent( pageTransition( 'pageshow', true ) );

		clickOn( button );

		expect( element.hidden ).toBe( false );
	} );
} );
