/**
 * Unit tests for pipeline stage 7 — client-context rule evaluation.
 *
 * Stage 7 is the browser half of the cache boundary. The server already judged
 * every `Context::Server` rule and stripped it; what reaches `boot()` is the
 * rules PHP genuinely could not decide, and this stage decides them or refuses
 * to.
 *
 * ## The asymmetry this file exists to pin
 *
 * `docs/CLAUDE.md` -> Architecture invariants gives the two halves opposite
 * defaults, on purpose:
 *
 * | Where | A rule it cannot judge |
 * |---|---|
 * | Server | **indeterminate** — the group survives and the rule is emitted |
 * | Client | **denied** — the rule evaluates false and its group fails |
 *
 * The server defers because it will be asked again in a moment by something
 * that can answer. The client is the last thing that will ever look at the rule,
 * so "I do not know" has to mean "no". Skipping it would widen the audience: a
 * deactivated extension would turn "members only" into "everyone", and
 * `docs/data-model.md` -> Evaluation semantics calls a popup targeted at one
 * segment silently going site-wide the worst failure this plugin can have.
 *
 * ## And the part of it that is easy to get wrong
 *
 * **`negate` is not applied to a denial.** `negate` inverts a *decision*, and an
 * unresolvable rule produced none. Inverting the denial instead would resurrect
 * exactly the rules that were denied for safety — the deactivated extension's
 * rule, the condition whose context never arrived, the evaluator that threw —
 * and hand each of them a pass for every visitor.
 *
 * That is one conditional in `controller.js`, it has no visible effect on any
 * popup that works, and every test of a *passing* popup stays green if it is
 * deleted. So it is asserted here as a matrix rather than as a case: every way
 * of being unresolvable, crossed with `negate` both ways, has to stay shut.
 *
 * The grid is not padding. Each cell was checked against a deliberately broken
 * copy of the controller, and different breakages light up different cells —
 * which ones is not predictable from the breakage:
 *
 * - Dropping the `unknown` tag check reddens the *`negate: false`* cell of that
 *   row alone. With `negate: true` the resurrected rule evaluates true and is
 *   then inverted back to false, so the popup stays shut and the cell passes.
 * - Dropping the non-boolean guard reddens the *`negate: true`* cell of the
 *   could-not-answer row alone, for the mirror-image reason: `! undefined` is
 *   `true`.
 * - Applying `negate` to the denial reddens every `negate: true` cell at once.
 *
 * A single representative case would have missed two of those three.
 *
 * The rows also have to cover every *deny path* separately, not merely every
 * cause. `ruleAllows()` reaches the same `return false` from an `unknown` tag,
 * from a type nothing registered, from a registered condition whose `evaluate`
 * is not callable, from an absent context payload, from a throw, and from a
 * non-boolean return. Those are six bare returns that happen to be spelled
 * identically today and each exits before the one line `negate` is applied on. A
 * refactor that merged them — or that moved the negation upward — would only have
 * to get one of them wrong to resurrect an unknown rule, so each is crossed with
 * `negate` here rather than trusted to keep the shape it currently has.
 *
 * ## Fixtures are the shape PHP actually emits, not a convenient subset
 *
 * Every rule below is built the way `Popkit\Rule_Evaluator::strip_server_rules()`
 * builds one, because a fixture the server cannot produce tests nothing:
 *
 * | Emitted rule | Shape |
 * |---|---|
 * | A type registered in PHP | `{ type, negate, values }` |
 * | A type nothing registered | `{ type, negate, values, unknown: true }` |
 * | A stored entry that was not a rule | `{ type: 'popkit_unsatisfiable', negate: false, values: [], unknown: true }` |
 *
 * `negate` is present on every one of them — sanitization stores a real boolean
 * — and `unknown` is recomputed against the registry at emission time, so it is
 * on an unregistered rule and absent from a registered one. Never assumed:
 * `retag_unknown()` was read, and the table above is the output of the real
 * emitter over a mixed rule set.
 *
 * This is not bookkeeping. Phase 4 shipped five client conditions whose PHP
 * registrations were missing, so the server tagged every one of their rules
 * `unknown` and the client denied all five before looking anything up. No popup
 * targeted by device, login state, referrer, UTM or visit history could open on
 * any page — and this file stayed green throughout, because its fixtures omitted
 * the one key the server sets unconditionally. Hence
 * {@link BUILT_IN_CASES}, which puts each of the five through `boot()` twice: once
 * as the server emits it for a registered type, and once carrying the tag.
 *
 * ## Read through boot(), not through the internals
 *
 * `conditionsAllow()` and `ruleAllows()` are private to `controller.js` and
 * should stay that way — exporting them to be testable would publish stage 7's
 * shape as an API and invite a second caller. So every assertion here goes
 * through `boot()` and observes what an author observes: whether the popup
 * opened, and whether `popkit:before-open` was ever offered.
 *
 * Every suppression is paired with a control that opens. A refusal and a
 * runtime that crashed look identical from outside, and without the controls
 * this whole file would pass against a `boot()` that had stopped doing anything.
 *
 * @see docs/CLAUDE.md -> Architecture invariants
 * @see docs/data-model.md -> Evaluation semantics
 * @see docs/build-plan.md -> Phase 4
 * @see tests/js/conditions.test.js
 */

/* global describe, it, expect, jest, beforeEach, afterEach */

/**
 * Post ID of the popup under test, and its frequency storage key.
 *
 * @type {number}
 */
const POPUP_ID = 11;

/**
 * Slug of the popup under test.
 *
 * @type {string}
 */
const POPUP_SLUG = 'stage7-popup';

/**
 * Post ID of the second popup, where a test needs two on one page.
 *
 * @type {number}
 */
const OTHER_ID = 12;

/**
 * Slug of the second popup.
 *
 * @type {string}
 */
const OTHER_SLUG = 'stage7-other';

/**
 * Class the click trigger in this file points at.
 *
 * @type {string}
 */
const TRIGGER_CLASS = 'stage7-open';

/**
 * A configured `click_selector` trigger, as the emitted config carries one.
 *
 * Used where a popup has to survive stage 7 without immediately taking the
 * screen — one popup opens at a time, so an eligible popup that opened on load
 * would mask whether a second one was suppressed by its targeting or merely
 * queued behind the first.
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
 * Viewport width every test reports, unless it installs another.
 *
 * @type {number}
 */
const VIEWPORT = 1024;

/**
 * A `device` rule the viewport above satisfies.
 *
 * @type {Object}
 */
const DEVICE_PASSES = emittedRule( 'device', { max_width: 9999 } );

/**
 * A `device` rule the viewport above does not satisfy.
 *
 * @type {Object}
 */
const DEVICE_FAILS = emittedRule( 'device', { max_width: 320 } );

/**
 * A `user_state` rule, the only v1 rule that needs the context route.
 *
 * @type {Object}
 */
const USER_STATE_RULE = emittedRule( 'user_state', { state: 'logged_in' } );

/**
 * The rule PHP emits in place of a stored entry it could not read.
 *
 * `Popkit\Rule_Evaluator::UNSATISFIABLE_RULE_TYPE`, carrying an empty `values`
 * *array* rather than an object because that is what the emitter builds and what
 * `wp_json_encode()` then writes. Nothing registers the type, so the client is
 * expected to deny it through the ordinary unknown path.
 *
 * It is also the whole of the emitted rule set when every group failed
 * server-side: an empty `groups` array means "always match", so a fully rejected
 * popup is emitted as one group carrying this one rule. If the client ever
 * resolved it, a popup whose targeting the server rejected outright would show
 * on every page.
 *
 * `Popkit\Sanitizer::REJECTED_RULE_TYPE` — `popkit_rejected` — is the same shape
 * under a second name, written at save time for a rule that could not be stored.
 * It is not given a row of its own: the denial is decided by the tag and by the
 * type being unregistered, neither of which reads the string, so a second row
 * would assert the same branch twice.
 *
 * @type {Object}
 */
const UNSATISFIABLE_RULE = {
	type: 'popkit_unsatisfiable',
	negate: false,
	values: [],
	unknown: true,
};

/**
 * A context payload reporting a signed-in visitor.
 *
 * @type {Object}
 */
const LOGGED_IN = { user: { state: 'logged_in' } };

/**
 * `window.fetch` and `window.matchMedia` as the environment left them.
 *
 * `matchMedia` is a stub the jest preset assigns, and it answers `false` to
 * every query — which would quietly turn every `device` rule in this file into a
 * non-match and make the suppressions pass for the wrong reason.
 *
 * @type {Object}
 */
const ORIGINAL = {
	fetch: window.fetch,
	matchMedia: Object.getOwnPropertyDescriptor( window, 'matchMedia' ),
};

/**
 * Listeners this test attached to `document`, removed again afterwards.
 *
 * @type {Object[]}
 */
let listeners = [];

/**
 * Builds a rule as the server emits one for a registered condition.
 *
 * `type`, `negate` and `values`, and no `unknown` key at all — sanitization
 * stores the boolean, and `Rule_Evaluator::retag_unknown()` removes any stored
 * tag and only puts one back for a type the registry does not currently hold.
 * Omitting `negate` here would let the fail-closed assertions pass against a
 * controller that read the flag off a key the server always sets.
 *
 * @param {string} type   Condition key, as stored.
 * @param {Object} values Stored rule values, emitted verbatim.
 * @return {Object} Emitted rule.
 */
function emittedRule( type, values ) {
	return { type, negate: false, values };
}

/**
 * Builds a rule as the server emits one for a type nothing has registered.
 *
 * The same three keys plus the tag, in the emitter's order. The tag is a
 * statement about the PHP registry at emission time and says nothing about what
 * this bundle can evaluate — which is exactly why the client must deny the rule
 * before it looks the type up.
 *
 * @param {string} type   Condition key, as stored.
 * @param {Object} values Stored rule values, preserved within the unknown bounds.
 * @return {Object} Emitted rule, tagged.
 */
function unknownRule( type, values ) {
	return { type, negate: false, values, unknown: true };
}

/**
 * Loads a fresh copy of the controller.
 *
 * It holds one session in module scope and refuses to boot twice, and the
 * context module it imports remembers its one request per pageview, so a shared
 * instance could only ever run one test.
 *
 * @return {Promise<Object>} The module's exports.
 */
async function loadController() {
	jest.resetModules();

	return import( '../../src/frontend/controller.js' );
}

/**
 * Loads the controller over a registry entry whose `evaluate` is not callable.
 *
 * One deny path in `ruleAllows()` cannot be reached from a config at all: a rule
 * whose `type` resolves to a module that does not implement the contract. It is
 * a real state — a module refactored to export a factory, a bundler that
 * tree-shook a method off, a condition added to `conditions/index.js` before its
 * `evaluate` was written — and a rule reaching it is not one the client
 * understands, so it must be denied with `negate` left unapplied like every other
 * unresolvable rule.
 *
 * The registry is reached rather than mocked because `controller.js` imports the
 * live `Map` and both imports resolve to the same module instance inside one
 * jest registry. Nothing is restored afterwards: `loadController()` resets the
 * module registry, so the next test builds the map again from the real modules.
 *
 * @param {string} key Condition key to replace with a module missing `evaluate`.
 * @return {Promise<Object>} The controller's exports.
 */
async function loadControllerWithoutEvaluator( key ) {
	const controller = await loadController();
	const { CLIENT_CONDITIONS } = await import(
		'../../src/frontend/conditions/index.js'
	);

	CLIENT_CONDITIONS.set( key, { key, needsContext: false } );

	return controller;
}

/**
 * Lets the queue drain.
 *
 * The context lane is a promise chain `boot()` starts and deliberately never
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
 * Installs a `matchMedia` answering for a viewport of the given width.
 *
 * @param {number} width Viewport width to report, in CSS pixels.
 * @return {void}
 */
function useViewport( width ) {
	window.matchMedia = jest.fn( ( query ) => ( {
		matches:
			width <= Number.parseInt( String( query ).split( ':' )[ 1 ], 10 ),
		addEventListener: () => {},
		removeEventListener: () => {},
	} ) );
}

/**
 * Installs a `matchMedia` that throws, so a registered evaluator raises.
 *
 * @return {void}
 */
function useThrowingViewport() {
	window.matchMedia = jest.fn( () => {
		throw new Error( 'NotSupportedError' );
	} );
}

/**
 * Points the document at a URL without navigating to it.
 *
 * jsdom implements no navigation and none is wanted: the `utm` condition reads
 * `window.location.search`, which `replaceState()` updates in place.
 *
 * @param {string} url URL relative to the document.
 * @return {void}
 */
function visit( url ) {
	window.history.replaceState( null, '', url );
}

/**
 * Adds popup markup to the document.
 *
 * A `<div>` rather than a `<dialog>`: this file is about whether the controller
 * decides to open a popup, and the banner path exercises that decision without
 * depending on how completely the environment implements `showModal()`.
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
 * Adds a button for the click trigger to match.
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
 * Builds a `pagehide` event carrying a `persisted` flag.
 *
 * jsdom does not implement `PageTransitionEvent` everywhere, so the flag is
 * defined on a plain event when the constructor is missing — what matters is the
 * property the runtime reads, not the interface it arrived on.
 *
 * @param {boolean} persisted Whether the document is going into the
 *                            back/forward cache rather than being discarded.
 * @return {Object} The event, ready to dispatch.
 */
function pageHide( persisted ) {
	if ( 'function' === typeof window.PageTransitionEvent ) {
		return new window.PageTransitionEvent( 'pagehide', { persisted } );
	}

	const event = new window.Event( 'pagehide' );

	Object.defineProperty( event, 'persisted', { value: persisted } );

	return event;
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
 * Records every popkit lifecycle event that reaches `document`.
 *
 * Bound on `document` because that is the contract the events are published
 * under, and because it is the only place from which the *absence* of an event
 * can honestly be observed — a popup suppressed at stage 7 is never offered for
 * veto at all, which is a stronger statement than "it did not open".
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
			seen.push( `${ name }:${ event.detail.slug }` );
		} );
	}

	return seen;
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
		siteTimezone: 'UTC',
		needsContext: false,
		popups,
		...overrides,
	};
}

/**
 * Builds an emitted config carrying one popup with the given rule groups.
 *
 * @param {Array}  groups      Emitted rule groups.
 * @param {Object} [overrides] Fields to override on the config itself.
 * @return {Object} Emitted config object.
 */
function configWith( groups, overrides ) {
	return configOf( [ popupEntry( { conditions: { groups } } ) ], overrides );
}

/**
 * Copies a rule, setting `negate` on it.
 *
 * @param {Object}  rule   Emitted rule.
 * @param {boolean} negate Whether the rule inverts its result.
 * @return {Object} The rule, with `negate` applied.
 */
function negated( rule, negate ) {
	return { ...rule, negate };
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
 * Makes the context request fail, as a network blip or an outage would.
 *
 * @return {void}
 */
function failContext() {
	window.fetch.mockRejectedValue( new TypeError( 'Failed to fetch' ) );
}

/**
 * Every way a client rule can be unresolvable.
 *
 * Each entry is crossed with `negate` both ways below. `needsContext` says
 * whether the emitted config claims the round trip is needed, and `prepare`
 * runs before `boot()` for the cases that need a hostile environment.
 *
 * @type {Object[]}
 */
const UNRESOLVABLE_CASES = [
	{
		/*
		 * The tag the server puts on a rule it had no registration for, over a
		 * type this bundle happens to evaluate. That combination is not
		 * hypothetical — it is precisely what a missing PHP registration
		 * produces, and it is what Phase 4 shipped for all five client
		 * conditions. The values would pass if they were evaluated, so this cell
		 * fails if the tag is ever checked after the lookup instead of before it:
		 * a JavaScript evaluator turning up without its PHP declaration is not
		 * evidence that the author's targeting is understood.
		 */
		label: 'a rule the server tagged unknown',
		rule: unknownRule( 'device', { max_width: 9999 } ),
	},
	{
		// A rule written against a version of popkit that has `user_role` —
		// which v1 deliberately does not — or one saved while the extension
		// declaring it was active and emitted after it was deactivated.
		label: 'an unregistered type, tagged as the server tags it',
		rule: unknownRule( 'user_role', { roles: [ 'member' ] } ),
	},
	{
		/*
		 * Untagged, and reachable: a PHP registration is all it takes to declare
		 * a `Context::Client` condition, and that gets an extension editor UI,
		 * REST validation and sanitization but no `evaluate` into this bundle.
		 * The server then emits an ordinary-looking rule with nothing here to
		 * judge it, and the tag is absent because the type genuinely is
		 * registered — so the type lookup is the only thing standing between it
		 * and a pass.
		 */
		label: 'a client condition registered in PHP with no module in this bundle',
		rule: emittedRule( 'user_role', { roles: [ 'member' ] } ),
	},
	{
		// Server rules are stripped before emission once they are decided, so
		// this is the shape of one the server could not decide either. The client
		// has no matcher for it and must not guess.
		label: 'a server-context rule that reached the client by mistake',
		rule: emittedRule( 'url_path', { match: 'prefix', value: '/' } ),
	},
	{
		// Emitted for a stored entry that was not a rule at all, and for a rule
		// set whose every group failed. Denying it is what stops a popup the
		// server rejected outright from showing on every page.
		label: 'the unsatisfiable rule the server emits for a corrupt entry',
		rule: UNSATISFIABLE_RULE,
	},
	{
		label: 'a rule needing context the client could not obtain',
		rule: USER_STATE_RULE,
		needsContext: true,
		prepare: failContext,
	},
	{
		// A hand-edited row or a forged REST write: the field schema is declared
		// in PHP and validated at save time, so an unreadable value never comes
		// from an author using the editor.
		label: 'a rule whose evaluator could not answer',
		rule: emittedRule( 'device', { max_width: 'wide' } ),
	},
	{
		label: 'a rule whose evaluator threw',
		rule: DEVICE_PASSES,
		prepare: useThrowingViewport,
	},
];

/**
 * Values for which `device` must answer that it cannot read the rule.
 *
 * Every one of them survives `Number()` as something, and that is the hazard:
 * `null`, `''` and `[]` all become `0`, which is a legitimate stored width
 * meaning "a viewport nobody has", and `true` becomes `1`. A module that coerced
 * first and asked questions afterwards would answer `false` for all four — a
 * decision, which `negate` inverts — and "not narrower than 0px" is every visitor
 * on the site.
 *
 * `-1` is the other direction: a finite number that makes a media query no
 * browser accepts, so the answer would come from the query's invalidity rather
 * than from the rule.
 *
 * `NaN` and `Infinity` are absent because they cannot survive JSON, and this file
 * boots from a parsed config; `conditions.test.js` asserts them against the
 * module directly.
 *
 * @type {Array[]}
 */
const UNREADABLE_WIDTHS = [
	[ 'null', null ],
	[ 'an empty string', '' ],
	[ 'an empty array', [] ],
	[ 'true', true ],
	[ 'a negative width', -1 ],
];

/**
 * Values for which `user_state` must answer that it cannot read the rule.
 *
 * The rule's own `state`, not the payload's. The payload is the server's and
 * `context.js` has already discarded anything that is not one of the two
 * enumerated states, so a payload reaching the evaluator is well formed; the
 * rule's `values` are author data that a hand-edited row or a forged REST write
 * can put anything into.
 *
 * The consequence of reading one of these as a decision is the plugin's worst
 * case in its most ordinary spelling. "Logged-out visitors only" is
 * `state: logged_in` with `negate: true`; if a `state` the module could not read
 * compared as `false`, that rule would pass for every visitor, members included,
 * and the popup that was for anonymous visitors would go site-wide.
 *
 * @type {Array[]}
 */
const UNREADABLE_STATES = [
	[ 'no state at all', {} ],
	[ 'a state outside the enum', { state: 'administrator' } ],
	[ 'an empty state', { state: '' } ],
	[ 'a numeric state', { state: 1 } ],
	[ 'a null state', { state: null } ],
];

/**
 * The five built-in client conditions, each with values that resolve true.
 *
 * `prepare` puts the visitor in the state the rule asks about, so every entry
 * opens the popup when it arrives as the server emits it for a registered type.
 * Paired with the same rule carrying `unknown: true`, that is the assertion this
 * file did not previously make: the tag denies a rule the bundle can evaluate
 * perfectly well, so a condition missing from the PHP registry is a condition
 * that never matches — visibly, here, rather than silently in production.
 *
 * All five go through `boot()` rather than three of them being taken on trust
 * from `conditions.test.js`. A module missing from `CLIENT_CONDITIONS` is
 * indistinguishable from a module that does not exist, and unit tests that import
 * a module directly cannot see the registry it was left out of.
 *
 * @type {Object[]}
 */
const BUILT_IN_CASES = [
	{
		label: 'device',
		rule: emittedRule( 'device', { max_width: 9999 } ),
	},
	{
		label: 'referrer',
		// jsdom reports no referrer, which the module reads as the empty
		// subject — an answer, and the one a direct visit produces.
		rule: emittedRule( 'referrer', { match: 'exact', value: '' } ),
	},
	{
		label: 'utm',
		rule: emittedRule( 'utm', {
			param: 'utm_source',
			value: 'newsletter',
		} ),
		prepare: () => visit( '/?utm_source=newsletter' ),
	},
	{
		label: 'visit_history',
		// Storage is cleared before each test, so this pageview is the first.
		rule: emittedRule( 'visit_history', { state: 'first_time' } ),
	},
	{
		label: 'user_state',
		rule: USER_STATE_RULE,
		needsContext: true,
		prepare: () => respondWith( LOGGED_IN ),
	},
];

/**
 * The unresolvable cases crossed with `negate`, as `it.each` rows.
 *
 * @type {Array[]}
 */
const NEGATE_MATRIX = UNRESOLVABLE_CASES.flatMap( ( unresolvable ) =>
	[ false, true ].map( ( negate ) => [
		`${ unresolvable.label }, negate: ${ negate }`,
		unresolvable,
		negate,
	] )
);

beforeEach( () => {
	window.document.body.replaceChildren();

	window.localStorage.clear();
	window.sessionStorage.clear();

	delete window.popkit;

	window.fetch = jest.fn();

	useViewport( VIEWPORT );
	visit( '/' );
} );

afterEach( () => {
	/*
	 * Anything a test left armed is torn down here rather than carried into the
	 * next one. jsdom keeps one window and one document for the whole file, so a
	 * delegated `click` listener from an armed `click_selector` would outlive the
	 * module instance that bound it. Not `persisted`: this page really is going.
	 */
	window.dispatchEvent( pageHide( false ) );

	for ( const { name, handler } of listeners ) {
		window.document.removeEventListener( name, handler );
	}

	listeners = [];

	jest.restoreAllMocks();

	window.fetch = ORIGINAL.fetch;

	if ( ORIGINAL.matchMedia ) {
		Object.defineProperty( window, 'matchMedia', ORIGINAL.matchMedia );
	} else {
		delete window.matchMedia;
	}

	delete window.popkit;

	window.document.body.replaceChildren();

	visit( '/' );
} );

describe( 'stage 7 -> the fail-closed matrix', () => {
	it.each( NEGATE_MATRIX )(
		'keeps the popup shut given %s',
		async ( label, unresolvable, negate ) => {
			const { boot } = await loadController();
			const element = writePopup( POPUP_ID, POPUP_SLUG );
			const events = recordEvents();

			unresolvable.prepare?.();

			expect( () =>
				boot(
					configWith(
						[
							{
								rules: [ negated( unresolvable.rule, negate ) ],
							},
						],
						{ needsContext: true === unresolvable.needsContext }
					)
				)
			).not.toThrow();

			await flush();

			/*
			 * Not merely "did not open": the veto was never offered, so the
			 * popup was refused at stage 7 rather than at stage 8, at stage 9,
			 * or by a listener. And with `negate: true` the same cell has to
			 * stay shut, which is the assertion the matrix exists for —
			 * inverting a denial hands a pass to precisely the rules that were
			 * denied for safety.
			 */
			expect( events ).toEqual( [] );
			expect( element.hidden ).toBe( true );
			expect(
				window.localStorage.getItem( `popkit:seen:${ POPUP_ID }` )
			).toBeNull();
		}
	);

	it.each( [ false, true ] )(
		'keeps the popup shut when a registered condition has no evaluate, negate: %s',
		async ( negate ) => {
			const { boot } = await loadControllerWithoutEvaluator( 'device' );
			const element = writePopup( POPUP_ID, POPUP_SLUG );
			const events = recordEvents();

			/*
			 * The one deny path no config can reach, and the only cell of the
			 * matrix that needs the registry interfered with. `DEVICE_PASSES`
			 * resolves true against this viewport through the real module — the
			 * control for that is in the next describe — so the popup stays shut
			 * here because the module was unusable and for no other reason.
			 *
			 * `negate: true` matters more here than anywhere else in the file. A
			 * module that lost its `evaluate` is the shape a refactor produces,
			 * and it is the deny path most likely to be rewritten as "evaluate if
			 * you can, otherwise fall through" — at which point the negation would
			 * be applied to a denial and every negated rule of that type would
			 * pass for every visitor.
			 */
			boot(
				configWith( [
					{ rules: [ negated( DEVICE_PASSES, negate ) ] },
				] )
			);
			await flush();

			expect( events ).toEqual( [] );
			expect( element.hidden ).toBe( true );
		}
	);

	it.each(
		UNREADABLE_WIDTHS.flatMap( ( [ label, maxWidth ] ) =>
			[ false, true ].map( ( negate ) => [
				`${ label }, negate: ${ negate }`,
				maxWidth,
				negate,
			] )
		)
	)(
		'keeps the popup shut given a device rule whose max_width is %s',
		async ( label, maxWidth, negate ) => {
			const { boot } = await loadController();
			const element = writePopup( POPUP_ID, POPUP_SLUG );
			const events = recordEvents();

			boot(
				configWith( [
					{
						rules: [
							negated(
								emittedRule( 'device', {
									max_width: maxWidth,
								} ),
								negate
							),
						],
					},
				] )
			);
			await flush();

			/*
			 * The viewport is 1024px wide and every width in this table coerces
			 * to a number a media query would accept, so a module that coerced
			 * before deciding whether it could read the value would answer
			 * `false` — a decision. The `negate: true` half of each row is where
			 * that shows: inverted, it would open the popup for every visitor.
			 */
			expect( events ).toEqual( [] );
			expect( element.hidden ).toBe( true );
		}
	);

	it.each(
		UNREADABLE_STATES.flatMap( ( [ label, values ] ) =>
			[ false, true ].map( ( negate ) => [
				`${ label }, negate: ${ negate }`,
				values,
				negate,
			] )
		)
	)(
		'keeps the popup shut given a user_state rule with %s',
		async ( label, values, negate ) => {
			const { boot } = await loadController();
			const element = writePopup( POPUP_ID, POPUP_SLUG );
			const events = recordEvents();

			respondWith( LOGGED_IN );

			boot(
				configWith(
					[
						{
							rules: [
								negated(
									emittedRule( 'user_state', values ),
									negate
								),
							],
						},
					],
					{ needsContext: true }
				)
			);
			await flush();

			/*
			 * The payload arrived and says `logged_in`, which is what separates
			 * this from the context-outage row of the matrix: the round trip was
			 * made, the answer is available, and the rule is still denied because
			 * the rule itself cannot be read. Without the fetch assertion this
			 * would pass against a controller that had simply failed to request
			 * context at all.
			 */
			expect( window.fetch ).toHaveBeenCalledTimes( 1 );
			expect( events ).toEqual( [] );
			expect( element.hidden ).toBe( true );
		}
	);

	it( 'fails closed on a user_state rule the config never asked context for', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const events = recordEvents();

		/*
		 * The other half of the context denial, and a different code path: the
		 * emitted flag says no round trip is needed while a popup carries a rule
		 * that requires one. The controller never considers that popup at all —
		 * fail-closed expressed as control flow rather than as a branch that
		 * could be got wrong — so there is no request either.
		 */
		boot(
			configWith( [ { rules: [ USER_STATE_RULE ] } ], {
				needsContext: false,
			} )
		);
		await flush();

		expect( window.fetch ).not.toHaveBeenCalled();
		expect( events ).toEqual( [] );
		expect( element.hidden ).toBe( true );
	} );

	it( 'fails closed on a user_state rule the config asked context for and negated', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot(
			configWith( [ { rules: [ negated( USER_STATE_RULE, true ) ] } ], {
				needsContext: false,
			} )
		);
		await flush();

		expect( element.hidden ).toBe( true );
	} );

	it.each( [
		[ 'a group whose rules are null', [ { rules: null } ] ],
		[ 'a group whose rules are not an array', [ { rules: 'all' } ] ],
		[ 'a group that is not an object', [ 'everyone' ] ],
		[ 'a rule that is not an object', [ { rules: [ null ] } ] ],
		[ 'a rule that is a bare string', [ { rules: [ 'device' ] } ] ],
	] )( 'keeps the popup shut given %s', async ( label, groups ) => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		expect( () => boot( configWith( groups ) ) ).not.toThrow();

		await flush();

		/*
		 * A malformed *group* is not the same as an absent one. No groups at all
		 * means "no targeting", which shows; a group that arrived unreadable is
		 * targeting nothing can satisfy, which does not.
		 *
		 * Unlike every other fixture in this file these shapes are ones the
		 * emitter cannot produce — `strip_group()` always writes an array of
		 * rules, and a stored entry that is not a rule leaves as
		 * `popkit_unsatisfiable`, which the matrix above covers as the emitted
		 * case. What arrives here is a config some other hand mangled: a caching
		 * layer that rewrote the JSON, an optimiser that inlined it wrong, a
		 * filter on the script tag. The controller parses whatever is in that
		 * element, so it is worth knowing it fails shut on a config popkit did not
		 * write, and worth saying plainly that this is what these rows are.
		 */
		expect( element.hidden ).toBe( true );
	} );
} );

describe( 'stage 7 -> the controls, without which the matrix proves nothing', () => {
	it( 'opens a popup whose device rule resolves true', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );
		const events = recordEvents();

		boot( configWith( [ { rules: [ DEVICE_PASSES ] } ] ) );
		await flush();

		expect( events ).toEqual( [
			`popkit:before-open:${ POPUP_SLUG }`,
			`popkit:open:${ POPUP_SLUG }`,
		] );
		expect( element.hidden ).toBe( false );
	} );

	it( 'keeps a popup shut when its device rule resolves false', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot( configWith( [ { rules: [ DEVICE_FAILS ] } ] ) );
		await flush();

		expect( element.hidden ).toBe( true );
	} );

	it( 'opens a popup whose user_state rule matches the payload', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		respondWith( LOGGED_IN );

		boot(
			configWith( [ { rules: [ USER_STATE_RULE ] } ], {
				needsContext: true,
			} )
		);
		await flush();

		// The control for the context cell of the matrix. Without it, that cell
		// would pass against a runtime where `user_state` never resolved at all.
		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		expect( window.fetch.mock.calls[ 0 ][ 0 ] ).toBe(
			`${ REST_URL }?fields=user_state`
		);
		expect( element.hidden ).toBe( false );
	} );

	it( 'keeps a popup shut when its user_state rule does not match the payload', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		respondWith( { user: { state: 'logged_out' } } );

		boot(
			configWith( [ { rules: [ USER_STATE_RULE ] } ], {
				needsContext: true,
			} )
		);
		await flush();

		expect( element.hidden ).toBe( true );
	} );
} );

describe( 'stage 7 -> the unknown tag against the five built-in conditions', () => {
	it.each( BUILT_IN_CASES.map( ( builtIn ) => [ builtIn.label, builtIn ] ) )(
		'opens a popup whose %s rule arrived as the server emits a registered one',
		async ( label, builtIn ) => {
			const { boot } = await loadController();
			const element = writePopup( POPUP_ID, POPUP_SLUG );

			builtIn.prepare?.();

			boot(
				configWith( [ { rules: [ builtIn.rule ] } ], {
					needsContext: true === builtIn.needsContext,
				} )
			);
			await flush();

			/*
			 * Five conditions, five evaluators, one registry, read through
			 * `boot()`. A module absent from `CLIENT_CONDITIONS` behaves exactly
			 * like a module that does not exist, and a unit test importing the
			 * module directly cannot tell the difference — so the registry is
			 * exercised here or nowhere.
			 */
			expect( element.hidden ).toBe( false );
		}
	);

	it.each( BUILT_IN_CASES.map( ( builtIn ) => [ builtIn.label, builtIn ] ) )(
		'keeps that same %s popup shut when the server tagged the rule unknown',
		async ( label, builtIn ) => {
			const { boot } = await loadController();
			const element = writePopup( POPUP_ID, POPUP_SLUG );
			const events = recordEvents();

			builtIn.prepare?.();

			/*
			 * The identical rule, plus the one key a missing PHP registration
			 * adds. Everything else about the pageview is the same as the test
			 * above, which opened — so this pair says precisely what the tag does
			 * and nothing else could account for the difference.
			 *
			 * This is the regression the phase actually shipped: all five of these
			 * conditions had JavaScript evaluators and no PHP registration, so the
			 * server tagged every rule of every one of them and the client denied
			 * the lot. No popup targeted by device, login state, referrer, UTM or
			 * visit history could open anywhere on the site, and no JavaScript test
			 * saw it because none of them put the tag in a fixture.
			 */
			boot(
				configWith(
					[ { rules: [ { ...builtIn.rule, unknown: true } ] } ],
					{ needsContext: true === builtIn.needsContext }
				)
			);
			await flush();

			expect( events ).toEqual( [] );
			expect( element.hidden ).toBe( true );
		}
	);
} );

describe( 'stage 7 -> negate inverts a rule that was actually decided', () => {
	it( 'opens a popup whose device rule resolved false and was negated', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot( configWith( [ { rules: [ negated( DEVICE_FAILS, true ) ] } ] ) );
		await flush();

		/*
		 * "Anything wider than 320px" is a rule an author writes, and it has to
		 * work. Fail-closed denial and `negate` are separate mechanisms in the
		 * controller precisely so that this stays live while the matrix above
		 * stays shut; a fix for one that broke the other would be caught here.
		 */
		expect( element.hidden ).toBe( false );
	} );

	it( 'keeps shut a popup whose device rule resolved true and was negated', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot( configWith( [ { rules: [ negated( DEVICE_PASSES, true ) ] } ] ) );
		await flush();

		expect( element.hidden ).toBe( true );
	} );

	it( 'inverts a user_state rule against a real payload', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		respondWith( { user: { state: 'logged_out' } } );

		// "Logged-out visitors only", which is how the data model spells it:
		// `state: logged_in` with `negate: true`. The single most common
		// negated rule in the plugin, and the one the matrix must not break.
		boot(
			configWith( [ { rules: [ negated( USER_STATE_RULE, true ) ] } ], {
				needsContext: true,
			} )
		);
		await flush();

		expect( element.hidden ).toBe( false );
	} );
} );

describe( 'stage 7 -> AND within a group, OR across groups', () => {
	it( 'requires every rule in a group', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot( configWith( [ { rules: [ DEVICE_PASSES, DEVICE_FAILS ] } ] ) );
		await flush();

		expect( element.hidden ).toBe( true );
	} );

	it( 'passes a group whose rules all pass', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot(
			configWith( [
				{
					rules: [
						DEVICE_PASSES,
						emittedRule( 'device', { max_width: 2048 } ),
					],
				},
			] )
		);
		await flush();

		expect( element.hidden ).toBe( false );
	} );

	it( 'lets one unresolvable rule fail a group of otherwise passing rules', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot(
			configWith( [
				{
					rules: [
						DEVICE_PASSES,
						unknownRule( 'user_role', { roles: [ 'member' ] } ),
					],
				},
			] )
		);
		await flush();

		// The deactivated-extension case in its realistic form: the author's
		// other targeting is fine, and the popup still must not show. Narrowing
		// to nobody is the correct answer; widening to everybody is not.
		expect( element.hidden ).toBe( true );
	} );

	it( 'passes when any group passes', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot(
			configWith( [
				{ rules: [ DEVICE_FAILS ] },
				{ rules: [ DEVICE_PASSES ] },
			] )
		);
		await flush();

		expect( element.hidden ).toBe( false );
	} );

	it( 'fails when every group fails', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot(
			configWith( [
				{ rules: [ DEVICE_FAILS ] },
				{ rules: [ DEVICE_FAILS ] },
			] )
		);
		await flush();

		expect( element.hidden ).toBe( true );
	} );

	it( 'does not let an unresolvable group poison a sibling that passes', async () => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot(
			configWith( [
				{ rules: [ unknownRule( 'user_role', {} ) ] },
				{ rules: [ DEVICE_PASSES ] },
			] )
		);
		await flush();

		/*
		 * Denial is scoped to the rule's own group. Groups are OR'd, so a group
		 * that cannot be judged contributes nothing rather than vetoing the set
		 * — the author gave a second, complete reason to show this popup and it
		 * still holds.
		 */
		expect( element.hidden ).toBe( false );
	} );

	it.each( [
		[ 'no groups at all, which means always match', [] ],
		[
			'a group whose rules were all stripped as server rules',
			[ { rules: [] } ],
		],
	] )( 'opens a popup with %s', async ( label, groups ) => {
		const { boot } = await loadController();
		const element = writePopup( POPUP_ID, POPUP_SLUG );

		boot( configWith( groups ) );
		await flush();

		/*
		 * Two empty cases, both load bearing and not the same case. No groups
		 * means the author set no targeting. An empty group means every rule it
		 * had was a server rule that already passed and `strip_server_rules()`
		 * removed it as decided — reading that as "nothing satisfied" would
		 * suppress every popup targeted purely by post type or URL.
		 */
		expect( element.hidden ).toBe( false );
	} );
} );

describe( 'stage 7 -> one popup does not inherit another popup outage', () => {
	it( 'still evaluates a context-free condition when the context fetch failed', async () => {
		const { boot } = await loadController();
		const waiting = writePopup( POPUP_ID, POPUP_SLUG );
		const independent = writePopup( OTHER_ID, OTHER_SLUG );
		const button = writeButton();
		const events = recordEvents();

		failContext();

		/*
		 * The popup that needs context is left with no triggers, so it would
		 * open on load if it were allowed to. The popup that does not need
		 * context is given a click trigger, so nothing is on screen while the
		 * first is being judged — otherwise "one popup at a time" would refuse
		 * the second open and the two suppressions would be indistinguishable.
		 */
		boot(
			configOf(
				[
					popupEntry( {
						conditions: {
							groups: [ { rules: [ USER_STATE_RULE ] } ],
						},
					} ),
					popupEntry( {
						id: OTHER_ID,
						slug: OTHER_SLUG,
						conditions: {
							groups: [ { rules: [ DEVICE_PASSES ] } ],
						},
						triggers: [ CLICK_TRIGGER ],
					} ),
				],
				{ needsContext: true }
			)
		);
		await flush();

		expect( window.fetch ).toHaveBeenCalledTimes( 1 );
		expect( events ).toEqual( [] );
		expect( waiting.hidden ).toBe( true );

		clickOn( button );

		/*
		 * The device-targeted popup was armed and opens. A popup targeted by
		 * viewport has no stake in a round trip some other popup on the page
		 * needed, and suppressing it would let one popup's outage silently
		 * narrow another's audience — which is a widening failure's mirror
		 * image and just as invisible.
		 */
		expect( independent.hidden ).toBe( false );
		expect( waiting.hidden ).toBe( true );
		expect( events ).toEqual( [
			`popkit:before-open:${ OTHER_SLUG }`,
			`popkit:open:${ OTHER_SLUG }`,
		] );
	} );

	it( 'opens both once the context arrives, so the failure above meant something', async () => {
		const { boot } = await loadController();
		const waiting = writePopup( POPUP_ID, POPUP_SLUG );
		const independent = writePopup( OTHER_ID, OTHER_SLUG );
		const button = writeButton();

		respondWith( LOGGED_IN );

		boot(
			configOf(
				[
					popupEntry( {
						conditions: {
							groups: [ { rules: [ USER_STATE_RULE ] } ],
						},
					} ),
					popupEntry( {
						id: OTHER_ID,
						slug: OTHER_SLUG,
						conditions: {
							groups: [ { rules: [ DEVICE_PASSES ] } ],
						},
						triggers: [ CLICK_TRIGGER ],
					} ),
				],
				{ needsContext: true }
			)
		);
		await flush();

		expect( waiting.hidden ).toBe( false );

		window.popkit.close( POPUP_SLUG );
		clickOn( button );

		expect( independent.hidden ).toBe( false );
	} );
} );
