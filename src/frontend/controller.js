/**
 * popkit — front-end controller: pipeline stages 6 to 10.
 *
 * `docs/CLAUDE.md` -> Architecture invariants splits evaluation across the
 * cache boundary. Stages 1 to 5 ran in PHP and their result is the emitted
 * config; this file is everything after it, in the documented order and no
 * other:
 *
 *   6. Fetch context, if and only if `needsContext` is set.
 *   7. Evaluate `Context::Client` rules — fail closed.
 *   8. Evaluate the schedule against the corrected clock.
 *   9. Check the frequency cap.
 *  10. Arm triggers, first fire wins, open, tear down the siblings.
 *
 * ## Two independent lanes
 *
 * A popup that needs no context is evaluated synchronously during boot and can
 * be on screen before any response arrives. A popup that needs context waits
 * for it. The fetch is started and then left alone: nothing here awaits it
 * before running the first lane, so it can never delay first paint.
 *
 * ## Fail closed, everywhere except one place
 *
 * Every unanswerable question is answered "no":
 *
 * - Context unavailable — network error, non-200, malformed payload, or a
 *   requested key missing — suppresses every popup that depended on it.
 * - A rule whose evaluator is not registered evaluates false and fails its
 *   group. `negate` is deliberately **not** applied: negating an unknown would
 *   resurrect it, and a deactivated extension would turn "members only" into
 *   "everyone".
 * - An enabled schedule with no calibrated clock suppresses the popup.
 *
 * The single exception is the frequency cap, which fails open when browser
 * storage throws. That decision and its reasoning live in `frequency.js`.
 *
 * ## Stage 7 denies whatever it cannot evaluate
 *
 * `CLIENT_CONDITIONS`, in `conditions/index.js`, holds v1's five client
 * conditions and owns the contract their modules implement — including the one
 * that matters here, that an evaluator answers `true`, answers `false`, or
 * returns something else meaning it could not answer. A rule either resolves to
 * one of those modules or does not resolve at all, and that is the whole of the
 * stage: a resolved rule is evaluated and then negated if it asked to be, and an
 * unresolved one is denied with `negate` left unapplied.
 *
 * That registry sits beside its modules while {@link TRIGGERS} is spelled out in
 * this file, and the asymmetry is worth naming rather than dressing up: it is
 * where two authors drew the line differently, and neither placement is wrong.
 * The property that actually matters is the one Phase 3 established when it
 * deleted `triggers/index.js` — a registry nothing imports is dead code.
 * `conditions/index.js` has exactly one consumer, this file. If stage 7 ever
 * stops consulting it, delete it rather than leave it looking load bearing.
 *
 * Whether a condition needs the context payload is the condition's own
 * declaration, and a rule that needs it is denied **before** its evaluator runs
 * rather than inside it. That is what keeps "I could not answer" out of the
 * evaluator's return value, where it would arrive as `false` — indistinguishable
 * from an answer of "no", and `negate` would turn it into "yes". The two cases
 * are separated at the one point that knows the difference; see
 * {@link ruleAllows}.
 *
 * The context payload is all-or-nothing before it reaches this stage.
 * `context.js` discards a response with any requested key missing or malformed,
 * so a payload that is not null carries every field that was asked for, and
 * there is no half-answered context for a condition to misread.
 *
 * ## Stage 10, and what an empty trigger list means
 *
 * Arming is `trigger-controller.js`. This file decides *which* popups are armed
 * and *when*, and the answer is "only the ones that passed stages 7 to 9, and
 * not one moment earlier". `docs/CLAUDE.md` -> Architecture invariants states it
 * as an absolute: nothing binds to the DOM for a popup that could never show. A
 * popup its targeting refused, its schedule closed, or its frequency cap
 * suppressed installs no timer, no scroll listener, no click handler and no
 * observer — there is no code path here from which it could.
 *
 * **A popup with no configured triggers opens on page load**, as though it
 * carried a single `page_load` with `delay_ms: 0`. This is the default a site
 * owner hits when they save a popup without opening the trigger panel, so it is
 * chosen rather than inherited: a published, eligible, correctly targeted popup
 * that stays invisible is indistinguishable from a broken plugin, produces no
 * error anywhere to lead its author to the panel they did not open, and the
 * support question it generates has no good answer. It is also what Phase 2
 * shipped, so no popup changes behaviour on the day triggers land.
 *
 * A trigger list that is **not** empty is honoured exactly as written, and the
 * two cases are deliberately not merged. A popup whose only configured trigger
 * cannot be armed — an unregistered type left behind by a deactivated extension
 * — stays shut. The author asked for something specific and popkit could not do
 * it; substituting "open immediately" would show the popup to everyone on every
 * page as the *consequence of an error*, which is the direction this plugin
 * never fails in.
 *
 * On fire the popup opens and every sibling trigger for it is torn down, so the
 * first to fire wins. Teardown also runs on abort and on `pagehide`, and it is
 * `trigger-controller.js` that owns all three; the one teardown this file calls
 * for itself is on a programmatic open, which that module has no way to observe.
 *
 * ## What this file does not do
 *
 * Focus, the close button, overlay clicks, Escape, focus return, removal from
 * the document, and modal supersession are all `dialog.js`. Event dispatch is
 * all `events.js`. This file decides *whether* and *when*; it does not decide
 * *how*, and it deliberately owns no DOM behaviour it could get subtly wrong in
 * a second place.
 *
 * @see docs/CLAUDE.md
 * @see docs/data-model.md
 * @see docs/build-plan.md
 */

import { installApi } from './api.js';
import { createClock } from './clock.js';
import { CLIENT_CONDITIONS } from './conditions/index.js';
import { fetchContext } from './context.js';
import { closePopup, isPopupOpen, openPopup } from './dialog.js';
import { emitBeforeOpen, emitClose, emitOpen } from './events.js';
import { isSuppressed, recordConvert, recordSeen } from './frequency.js';
import { scheduleAllows } from './schedule.js';
import { armTriggers } from './trigger-controller.js';
import clickSelector from './triggers/click-selector.js';
import deepLink from './triggers/deep-link.js';
import pageLoad from './triggers/page-load.js';
import scrollDepth from './triggers/scroll-depth.js';

/**
 * `id` of the script element carrying the emitted config.
 *
 * Matches `Popkit\Frontend::CONFIG_ELEMENT_ID`. The two are joined by nothing
 * but this string, so it is not spelled anywhere else in the bundle.
 *
 * @type {string}
 */
const CONFIG_ELEMENT_ID = 'popkit-config';

/**
 * Context field names the server allowlists.
 *
 * Wire values, so they keep the server's spelling. `user_state` is snake_case
 * because that is what `Popkit\Rest_Context::FIELD_USER_STATE` answers to, not
 * because popkit's JavaScript uses snake_case anywhere else.
 *
 * @type {{time: string, userState: string}}
 */
const CONTEXT_FIELDS = {
	time: 'time',
	userState: 'user_state',
};

/**
 * Rule type whose evaluation requires the context route.
 *
 * The only one in v1. Matched as a literal for the same reason the PHP side
 * matches it as a literal: a rule's `type` is stored verbatim and has to be
 * recognisable whether or not anything has registered a condition under it.
 *
 * Read twice, for the two halves of one decision. {@link hasUserStateRule} uses
 * it to work out whether this page must pay for the round trip at all, which is
 * the same question `Popkit\Frontend::rule_set_needs_context()` answers from the
 * same literal — so client and server ask for and expect the same fields.
 * {@link ruleAllows} uses it to deny the rule when the payload did not arrive,
 * independently of what `user-state.js` declares about itself.
 *
 * @type {string}
 */
const USER_STATE_RULE = 'user_state';

/**
 * Trigger modules, keyed by the key each module declares for itself.
 *
 * v1's four, and **the only trigger registry in the shipped bundle**. Adding a
 * fifth trigger to popkit itself means editing this map, and that is the design
 * rather than an omission waiting to be tidied — see "No client-side extension
 * point in v1" below, which is the part a reader most needs to be told the truth
 * about.
 *
 * The list is spelled here rather than inside `trigger-controller.js` because
 * arming a trigger and knowing which triggers exist are different jobs: that
 * module takes the registry as an argument, so it can be tested against a single
 * trigger without the other three coming along.
 *
 * The keys come off the modules. `page_load`, `scroll_depth`, `click_selector`
 * and `deep_link` are stored strings — a popup's trigger config carries the same
 * spelling as its `type`, and the PHP registry declares it a third time — so the
 * fewer places the bundle repeats them, the fewer places one can be wrong.
 *
 * A `Map` rather than an object literal, because a configured `type` is author
 * data that arrived over the wire and reaches this container as a lookup key. On
 * a `Map` a config saying `"type": "constructor"` finds nothing; on an object
 * literal it would find something off `Object.prototype`.
 * `trigger-controller.js` guards the same hazard from its own side by requiring
 * a `setup` function on whatever it resolves, and two cheap guards is the right
 * number when the alternative is a popup opening on an event nobody configured.
 *
 * ## What a trigger module is
 *
 * ```js
 * export default {
 *     key: 'scroll_depth',
 *     setup( api, values ) {
 *         // Arm listeners, timers or observers here.
 *         return () => {}; // Teardown. Required, never optional.
 *     },
 * };
 * ```
 *
 * `setup()` is handed the popup's trigger API — `fire()`, `abort()`, `popup`,
 * `element` — and that trigger's stored values, and **must** return a teardown
 * function. `trigger-controller.js` owns that contract, calls the teardown on
 * fire, on abort and on `pagehide`, and reports a module that breaks it.
 *
 * ## No client-side extension point in v1
 *
 * A trigger has two halves, and only one of them is extensible today.
 *
 * **The schema half is.** PHP, on the `popkit_register_triggers` action, declares
 * the key, the editor label and the field definitions, and
 * `docs/CLAUDE.md` -> Registry invariants makes that the only place a field
 * schema is declared — so the editor control, the REST validation and the
 * sanitizer all follow from one registration, with no React and no JavaScript.
 *
 * **The runtime half is not.** A `setup()` written outside this bundle has no way
 * in, and a `registerTrigger()` function would not by itself create one:
 *
 * - The bundle is an IIFE that exports nothing to the page, so the function
 *   would have to be published on `window.popkit`. That object is `api.js`'s,
 *   deliberately three members wide, and a fourth member is an `API_VERSION`
 *   increment rather than an implementation detail.
 * - Registration would have to happen **before** `boot()`, which runs
 *   synchronously as the bundle executes. `wp_enqueue_script()` naming
 *   `popkit-frontend` as a dependency orders the other script *after* the
 *   bundle, which is too late — arming has already run for this pageview. So
 *   the extension point needs a queue, a deferred boot, or a documented event
 *   to register into, and choosing one of those is a v1.1 decision with a public
 *   contract attached.
 *
 * Third-party triggers are therefore out of scope for v1, stated rather than
 * implied. Nothing is lost by an extension that arrives early: PHP keeps an
 * unregistered trigger's stored config intact, and `trigger-controller.js`
 * reports a type it cannot arm and leaves the popup shut, so the author's
 * configuration is still there on the day a runtime path exists for it.
 *
 * @see docs/CLAUDE.md -> Registry invariants
 * @see docs/data-model.md -> Triggers
 *
 * @type {Map<string, Object>}
 */
const TRIGGERS = new Map(
	[ pageLoad, scrollDepth, clickSelector, deepLink ].map( ( trigger ) => [
		trigger.key,
		trigger,
	] )
);

/**
 * One popup, as the controller works with it.
 *
 * `element` is deliberately three-valued: `undefined` means the DOM has not
 * been searched yet, `null` means it was searched and the markup is absent.
 * Nothing looks it up until stages 7 to 9 have passed, because
 * `docs/CLAUDE.md` -> Architecture invariants requires that nothing binds to
 * the DOM for a popup that could never show.
 *
 * @typedef  {Object}                PopkitRecord
 * @property {number}            id           Post ID, and the frequency storage key.
 * @property {string}            slug         Post slug, the identifier the public API takes.
 * @property {Object}            conditions   Emitted rule set, client-context rules only.
 * @property {Array}             triggers     Emitted trigger list, armed at stage 10.
 * @property {Object}            schedule     Emitted schedule object.
 * @property {Object}            frequency    Emitted frequency object.
 * @property {Object}            display      Emitted display object.
 * @property {boolean}           needsContext Whether stages 7 and 8 need the context route.
 * @property {Element|null|void} element      Popup root, once looked up.
 * @property {boolean}           isBound      Whether its conversion listener is attached.
 * @property {Function|null}     teardown     Disarms this popup's triggers, or null.
 */

/**
 * State shared by the runtime for the life of the pageview, or null before boot.
 *
 * A module-level singleton rather than an argument threaded through every
 * function: there is exactly one document, one clock and one record set per
 * pageview, and passing them around would cost bytes against an 8 KB budget to
 * express a plurality that does not exist.
 *
 * @type {{clock: Object, siteTimezone: string, records: PopkitRecord[]}|null}
 */
let session = null;

/**
 * Reads and parses the emitted config.
 *
 * Total: every failure returns null, and null means "this page has no popkit
 * config", which is the same as a page popkit never touched. A JSON parse
 * failure must not throw into the page — a plugin whose config was mangled by
 * an over-eager optimiser should cost the site nothing but its own popups.
 *
 * @return {Object|null} Parsed config, or null when it is absent or unusable.
 */
export function readConfig() {
	const element = window.document.getElementById( CONFIG_ELEMENT_ID );

	if ( ! element ) {
		return null;
	}

	try {
		const parsed = JSON.parse( element.textContent );

		return isObject( parsed ) ? parsed : null;
	} catch {
		return null;
	}
}

/**
 * Runs the client half of the evaluation pipeline.
 *
 * Called once per pageview. A second call is a no-op, and so is a call on a
 * page where another copy of the bundle already installed the public API — two
 * controllers over one DOM would open every popup twice, and each would believe
 * the other's dialog was its own.
 *
 * @param {Object|null} config Parsed config, as returned by readConfig().
 */
export function boot( config ) {
	if ( null !== session || window.popkit || ! isObject( config ) ) {
		return;
	}

	const records = toRecords( config.popups );

	if ( 0 === records.length ) {
		return;
	}

	session = {
		clock: createClock(),
		siteTimezone:
			'string' === typeof config.siteTimezone ? config.siteTimezone : '',
		records,
	};

	installApi( {
		open: openBySlug,
		close: closeBySlug,
	} );

	/*
	 * Stage 6. `needsContext` is the server's answer to "is this round trip
	 * worth making", and when it is false no request is made at all — not a
	 * conditional one, not a speculative one. The field list is derived from the
	 * same config the server derived the flag from, so the two agree; an empty
	 * list would make the response `{}`, which is a request with nothing to read
	 * in it, so that is not sent either.
	 */
	const fields = requestedFields( records );
	const context =
		true === config.needsContext &&
		0 < fields.length &&
		'string' === typeof config.restUrl
			? fetchContext( config.restUrl, fields, session.clock ).catch(
					() => null
			  )
			: null;

	for ( const record of records ) {
		if ( ! record.needsContext ) {
			consider( record, null );
		}
	}

	/*
	 * Popups that need context and cannot have it are simply never considered.
	 * That is the fail-closed path expressed as control flow rather than as a
	 * branch that could be got wrong: there is no code here that could evaluate
	 * them.
	 */
	if ( null === context ) {
		return;
	}

	context.then( ( resolved ) => {
		for ( const record of records ) {
			if ( record.needsContext ) {
				consider( record, resolved );
			}
		}
	} );
}

/**
 * Builds the record set from the emitted popup list.
 *
 * Every field is read defensively. The config is a JSON document that arrived
 * over the wire, and an entry this cannot make sense of is dropped rather than
 * repaired — a popup that is not in the record set cannot open, which is the
 * direction this plugin fails in.
 *
 * @param {*} popups Emitted `popups` array.
 * @return {PopkitRecord[]} Usable records, in emitted order.
 */
function toRecords( popups ) {
	if ( ! Array.isArray( popups ) ) {
		return [];
	}

	const records = [];

	for ( const entry of popups ) {
		if ( ! isObject( entry ) ) {
			continue;
		}

		const id = entry.id;
		const slug = entry.slug;

		if ( ! Number.isInteger( id ) || 0 >= id || 'string' !== typeof slug ) {
			continue;
		}

		const conditions = isObject( entry.conditions ) ? entry.conditions : {};
		const schedule = isObject( entry.schedule ) ? entry.schedule : {};

		records.push( {
			id,
			slug,
			conditions,
			triggers: Array.isArray( entry.triggers ) ? entry.triggers : [],
			schedule,
			frequency: isObject( entry.frequency ) ? entry.frequency : {},
			display: isObject( entry.display ) ? entry.display : {},
			needsContext:
				isScheduled( schedule ) || hasUserStateRule( conditions ),
			element: undefined,
			isBound: false,
			teardown: null,
		} );
	}

	return records;
}

/**
 * Derives the context fields this page actually needs.
 *
 * `time` when any popup has an enabled schedule, `user_state` when any carries
 * a `user_state` rule, and nothing else — the endpoint returns exactly what is
 * asked for, so asking for less is the whole of the privacy story.
 *
 * @param {PopkitRecord[]} records Record set.
 * @return {string[]} Allowlisted field names.
 */
function requestedFields( records ) {
	const fields = [];

	if ( records.some( ( record ) => isScheduled( record.schedule ) ) ) {
		fields.push( CONTEXT_FIELDS.time );
	}

	if ( records.some( ( record ) => hasUserStateRule( record.conditions ) ) ) {
		fields.push( CONTEXT_FIELDS.userState );
	}

	return fields;
}

/**
 * Runs stages 7 to 10 for one popup.
 *
 * The stages are separate statements in the documented order, and each one only
 * ever returns early. Merging them would save a few bytes and would make the
 * ordering — which is the invariant — a property of an expression rather than
 * of the code.
 *
 * @param {PopkitRecord} record  Popup being considered.
 * @param {Object|null}  context Context payload, or null when unavailable.
 */
function consider( record, context ) {
	// Stage 7.
	if ( ! conditionsAllow( record.conditions, context ) ) {
		return;
	}

	// Stage 8.
	if ( ! scheduleAllowsRecord( record, context ) ) {
		return;
	}

	// Stage 9.
	if ( isSuppressed( record.id, record.frequency ) ) {
		return;
	}

	// Stage 10.
	arm( record );
}

/**
 * Arms a popup's triggers. Pipeline stage 10, and the last thing that runs.
 *
 * Reached only from {@link consider}, and only past stages 7, 8 and 9. That is
 * the whole of the invariant `docs/CLAUDE.md` -> Architecture invariants states
 * — nothing binds to the DOM for a popup that could never show — and it is held
 * by there being no other caller rather than by a check inside the trigger
 * modules, which is where it could be forgotten one trigger at a time.
 *
 * The markup is resolved first and its absence ends the stage. A popup the
 * server emitted config for but no markup cannot be opened by anything, so
 * arming its triggers would install listeners for an open that could never
 * happen — the same waste the invariant exists to prevent, one step later.
 *
 * An empty trigger list opens the popup here and now. That decision, and why an
 * *unarmable* trigger is emphatically not the same case, is in the file header.
 *
 * `armTriggers()` is handed the record as `api.popup` — it is the popup config
 * the emitted JSON described, `record.triggers` is the list it arms from, and
 * `deep_link` needs the slug off it — plus {@link TRIGGERS} as the registry to
 * resolve each configured `type` against. Its return is the teardown for the
 * whole set, kept only so that a programmatic open can disarm; every other
 * teardown path, including `pagehide`, is that module's own.
 *
 * @param {PopkitRecord} record Popup that passed stages 7 to 9.
 */
function arm( record ) {
	const element = elementFor( record );

	if ( ! element ) {
		return;
	}

	if ( 0 === record.triggers.length ) {
		openRecord( record, false );
		return;
	}

	record.teardown = armTriggers( record, element, TRIGGERS, ( source ) =>
		openRecord( record, false, source )
	);
}

/**
 * Tears down a popup's triggers, at most once.
 *
 * Called when a popup is opened by `window.popkit.open()`, which is the one
 * open `trigger-controller.js` cannot see. Leaving the triggers armed would let
 * a scroll depth crossed after the visitor dismissed an author-opened popup put
 * it back on screen, and the frequency cap would not catch it — stage 9 ran
 * once, at arming time, and a fire does not re-run it.
 *
 * The reference is cleared before the call, so this is safe to call twice and
 * safe to call from inside a teardown.
 *
 * @param {PopkitRecord} record Popup to disarm.
 */
function disarm( record ) {
	const teardown = record.teardown;

	record.teardown = null;

	if ( 'function' === typeof teardown ) {
		teardown();
	}
}

/**
 * Evaluates an emitted rule set. Pipeline stage 7.
 *
 * Groups are OR'd and rules within a group are AND'd, matching the server. Two
 * empty cases are not the same and both are load bearing:
 *
 * - No groups at all means "always match". A popup with no targeting shows.
 * - A group with no rules means every rule it had was a server rule that
 *   already passed, and `strip_server_rules()` removed them as decided. The
 *   group passes.
 *
 * A group that failed on the server was never emitted, so nothing here can
 * resurrect one; a rule set whose groups all failed arrives as a single group
 * carrying one rule nothing can satisfy.
 *
 * @param {Object}      conditions Emitted rule set.
 * @param {Object|null} context    Context payload, or null when unavailable.
 * @return {boolean} True when the popup's targeting is satisfied.
 */
function conditionsAllow( conditions, context ) {
	const groups = conditions.groups;

	if ( ! Array.isArray( groups ) || 0 === groups.length ) {
		return true;
	}

	return groups.some( ( group ) => {
		const rules = isObject( group ) ? group.rules : null;

		if ( ! Array.isArray( rules ) ) {
			return false;
		}

		return rules.every( ( rule ) => ruleAllows( rule, context ) );
	} );
}

/**
 * Evaluates one emitted rule.
 *
 * The fail-closed path returns false **without applying `negate`**, and that is
 * the single most important line in this file. `negate` inverts a decision; an
 * unresolvable rule produced no decision to invert, and inverting the denial
 * would turn an unavailable condition into a rule that passes for everybody —
 * exactly the silent audience-widening `docs/data-model.md` calls the worst
 * failure this plugin can have.
 *
 * A rule the server tagged `unknown` is denied before its type is even looked
 * up. The tag records that nothing had registered the condition when the config
 * was built, and a JavaScript evaluator that turned up without its PHP
 * declaration is not evidence that the author's targeting is understood.
 *
 * There are five ways to be unresolvable and they are all the same denial: no
 * module under this `type`, a module with no callable `evaluate`, a declared
 * need for context that is not there, an evaluator that threw, and an evaluator
 * that returned something other than a boolean. The last is how a module says
 * "I could not tell" without saying "no", and it is the reason the contract asks
 * for a non-boolean rather than a `false` — see {@link CLIENT_CONDITIONS}.
 *
 * The context check reads the condition's declaration **or** the one rule type
 * known to need the route. That is not a redundant second source of truth: it is
 * the same {@link USER_STATE_RULE} literal `Popkit\Frontend` matches to set the
 * page-level flag, and stating it here means the single most safety-critical
 * denial in the plugin does not depend on a property in another file being
 * spelled correctly. A `user_state` rule with no payload is denied whether or
 * not `user-state.js` remembered to declare itself.
 *
 * Note what is *not* denied: a rule whose condition needs no context evaluates
 * normally even when the fetch failed. A popup targeted by device width has no
 * stake in a context request some other popup on the page needed, and
 * suppressing it would let one popup's outage silently narrow another's
 * audience.
 *
 * @param {*}           rule    Emitted rule.
 * @param {Object|null} context Context payload, or null when unavailable.
 * @return {boolean} True only when a registered evaluator affirmatively passed it.
 */
function ruleAllows( rule, context ) {
	if ( ! isObject( rule ) || true === rule.unknown ) {
		return false;
	}

	const condition = CLIENT_CONDITIONS.get( rule.type );

	if ( 'function' !== typeof condition?.evaluate ) {
		return false;
	}

	if (
		( true === condition.needsContext || USER_STATE_RULE === rule.type ) &&
		! isObject( context )
	) {
		return false;
	}

	let result;

	try {
		result = condition.evaluate( rule.values, context );
	} catch {
		return false;
	}

	// Anything but a boolean is an evaluator that did not answer the question.
	if ( 'boolean' !== typeof result ) {
		return false;
	}

	return true === rule.negate ? ! result : result;
}

/**
 * Evaluates a popup's schedule against the corrected clock. Pipeline stage 8.
 *
 * A disabled schedule is not a constraint and passes without consulting
 * anything. An enabled one needs authoritative time, so it is denied outright
 * when the context payload is missing or the clock was never calibrated —
 * showing an expired campaign is worse than showing nothing, and a live one
 * missing a few impressions during an outage is recoverable.
 *
 * The moment being tested comes from `clock.now()`, which is
 * `performance.timeOrigin + performance.now() + offset`. The device wall clock
 * is absent from this path by construction — it is read for neither the offset
 * nor the comparison — so a device clock changed after calibration moves
 * nothing. The literal spelling of that call is kept out of this file so that
 * the repository-wide grep asserting its absence has one fewer false positive
 * to explain.
 *
 * @param {PopkitRecord} record  Popup being considered.
 * @param {Object|null}  context Context payload, or null when unavailable.
 * @return {boolean} True when the popup is inside its schedule.
 */
function scheduleAllowsRecord( record, context ) {
	if ( ! isScheduled( record.schedule ) ) {
		return true;
	}

	if ( null === context || ! session.clock.isCalibrated() ) {
		return false;
	}

	return (
		true ===
		scheduleAllows(
			record.schedule,
			session.clock.now(),
			session.siteTimezone
		)
	);
}

/**
 * Opens a popup. Every path that shows one goes through here.
 *
 * Two decisions live at this single point rather than at each caller:
 *
 * - **One popup open at a time.** An automatic open is skipped while anything
 *   is already on screen, and is not queued for later; a visitor who dismissed
 *   one popup has not asked for the next. A programmatic open is not skipped —
 *   an author called it on purpose — and `dialog.js` supersedes an open modal
 *   for it, which is where that belongs and where the close reason is recorded.
 * - **`popkit:before-open` can veto.** Nothing is shown and — the part that is
 *   easy to get wrong — no seen record is written when it does. An aborted open
 *   is invisible to the visitor, and recording it would spend their single
 *   impression on something they never saw.
 *
 * Open state is asked of `dialog.js` rather than tracked here. It is the module
 * that opens and closes things, it already unregisters a popup removed from the
 * document while open, and a second opinion kept in this file would eventually
 * disagree with the first and leave the runtime refusing to open anything.
 *
 * `openPopup()` returning without opening — a `<dialog>` that is detached, say
 * — is checked rather than assumed, so a popup that was not shown is not
 * announced as open and does not consume the visitor's impression.
 *
 * Seen is recorded for a programmatic open too. `docs/data-model.md` conditions
 * the record on a successful, non-cancelled open and on nothing else, and a
 * visitor who was shown a popup was shown it however it was summoned.
 *
 * `source` is the element a trigger fired from — the link or button a
 * `click_selector` matched. It is passed straight to `dialog.js` as the element
 * focus returns to on close, which is the accessibility rule in
 * `docs/CLAUDE.md` -> Accessibility: focus returns to the element that triggered
 * the popup. Every other trigger omits it, and focus then returns to wherever it
 * was before opening, which is that rule's other half.
 *
 * Three outcomes, not two, because the trigger controller has to tell a refusal
 * that may not hold next time from one that settles the popup:
 *
 * - `true`  — open. The trigger latches, as first-fire-wins requires.
 * - `false` — **busy, and only busy.** Another popup holds the screen. The
 *   trigger stays armed so the visitor can use it again once the screen is free;
 *   retiring it here leaves the author's button on the page doing nothing, with
 *   nothing to explain why.
 * - `null`  — settled without opening: vetoed through `popkit:before-open`, or
 *   the element is missing. The trigger latches. Re-arming on a veto would put
 *   the same question to a consent integration on every click, every scroll
 *   event and every fire of every sibling trigger, which is the load
 *   first-fire-wins exists to prevent.
 *
 * {@see armTriggers()} latches on anything that is not `false`, so `false` is
 * reserved for the one genuinely retryable case and nothing else may use it.
 *
 * @param {PopkitRecord} record       Popup to open.
 * @param {boolean}      programmatic Whether this came from the public API.
 * @param {Element}      [source]     Element a trigger fired from, if any.
 * @return {boolean|null} True when open, false when busy, null when settled.
 */
function openRecord( record, programmatic, source ) {
	const element = elementFor( record );

	if ( ! element ) {
		return null;
	}

	if ( isPopupOpen( element ) ) {
		return true;
	}

	if ( ! programmatic && anyOpen() ) {
		return false;
	}

	if ( ! emitBeforeOpen( record ) ) {
		return null;
	}

	bindConvert( record, element );

	openPopup( element, {
		/*
		 * `layout` is deliberately not passed. `dialog.js` derives it from the
		 * element — a `<dialog>` is a modal, anything else is a banner — and the
		 * element is what actually has to be opened. A stored setting that
		 * disagreed with the markup would be the one thing capable of asking
		 * `showModal()` of a `<div>`.
		 *
		 * `close_on_overlay_click` keeps its snake_case because it is a stored
		 * field name travelling through JSON, not a name this bundle chose. It
		 * requires an overlay to exist, so the two settings are resolved
		 * together here rather than separately in the dialog module.
		 */
		closeOnOverlayClick:
			false !== record.display.overlay &&
			false !== record.display.close_on_overlay_click,

		/*
		 * The element a click trigger fired from, when there was one.
		 * `dialog.js` falls back to whatever held focus before the open, so
		 * passing `undefined` for every other trigger is the correct instruction
		 * rather than a missing one — and anything that cannot take focus is
		 * turned back into that instruction here. A truthy value `dialog.js`
		 * could not focus would suppress the fallback and strand focus on the
		 * body, which is worse than the omission it came from.
		 */
		trigger:
			source && 'function' === typeof source.focus ? source : undefined,

		onClose: () => emitClose( record ),
	} );

	if ( ! isPopupOpen( element ) ) {
		return false;
	}

	emitOpen( record );

	/*
	 * The frequency object goes with the id. `docs/data-model.md` gives `always`
	 * a storage of "none", and `recordSeen()` can only honour that if it is told
	 * the mode — the storage key carries no copy of it. Omitting it writes a
	 * record nothing reads, which then outlives the setting that produced it: an
	 * author switching an uncapped popup to `once_ever` would find it already
	 * suppressed for every visitor who saw it while it was uncapped.
	 */
	recordSeen( record.id, record.frequency );

	/*
	 * An author-initiated open ends this popup's trigger arming. Every other
	 * path here was reached by a trigger firing, and `trigger-controller.js`
	 * has already torn its own set down by then — see disarm().
	 */
	if ( programmatic ) {
		disarm( record );
	}

	return true;
}

/**
 * Closes a popup.
 *
 * `popkit:close` is dispatched from the `onClose` callback given at open time
 * and from nowhere else, because `dialog.js` calls it once for every close
 * path — Escape, the close button, an overlay click, supersession, removal from
 * the document, and this function. Emitting it here as well would fire it
 * twice for exactly the closes an integration is most likely to be counting.
 *
 * @param {PopkitRecord} record Popup to close.
 * @return {boolean} True when the popup was open and is now closed.
 */
function closeRecord( record ) {
	if ( ! record.element || ! isPopupOpen( record.element ) ) {
		return false;
	}

	closePopup( record.element );

	return true;
}

/**
 * Reports whether any popup on this page is currently open.
 *
 * @return {boolean} True when one is on screen.
 */
function anyOpen() {
	return session.records.some(
		( record ) => record.element && isPopupOpen( record.element )
	);
}

/**
 * Attaches the conversion listener, once, on a popup's first open.
 *
 * Not at boot: `docs/CLAUDE.md` -> Architecture invariants requires that
 * nothing binds to the DOM for a popup that could never show, and by the time
 * this runs the popup is about to be on screen.
 *
 * `popkit:convert` is listened for rather than dispatched. v1 ships no
 * conversion detection — integrations dispatch the event themselves — and it
 * bubbles, so a listener on the popup root catches one fired from a form deep
 * inside its content. Whether a conversion actually suppresses the popup is
 * `frequency.js`'s decision, made from `on_convert`, not this file's.
 *
 * @param {PopkitRecord} record  Popup being opened.
 * @param {Element}      element Popup root.
 */
function bindConvert( record, element ) {
	if ( record.isBound ) {
		return;
	}

	record.isBound = true;

	element.addEventListener( 'popkit:convert', () =>
		recordConvert( record.id )
	);
}

/**
 * Finds a popup's markup, once.
 *
 * The selector interpolates an integer that `toRecords()` already validated
 * with `Number.isInteger()`, so nothing author-supplied reaches the selector
 * parser.
 *
 * @param {PopkitRecord} record Popup to locate.
 * @return {Element|null} Popup root, or null when the markup is absent.
 */
function elementFor( record ) {
	if ( undefined === record.element ) {
		record.element = window.document.querySelector(
			`[data-popkit-id="${ record.id }"]`
		);
	}

	return record.element;
}

/**
 * Opens a popup by slug, on behalf of the public API.
 *
 * @param {*} slug Popup slug.
 * @return {boolean|null} Whether it opened, or null when no such popup is on the page.
 */
function openBySlug( slug ) {
	const record = findBySlug( slug );

	if ( null === record ) {
		return null;
	}

	/*
	 * `null` means two different things on either side of this line, and the
	 * public API only understands one of them. To {@see openRecord()} it means
	 * "settled without opening" — vetoed, or the element is missing. To
	 * {@see resolve()} in `api.js` it means "no popup with that slug", which is
	 * printed to the console as a diagnostic for a mistyped call.
	 *
	 * Passing one through as the other would tell an author their slug was wrong
	 * when what actually happened is that their own `popkit:before-open`
	 * listener cancelled the open. Collapse to a boolean here: the caller asked
	 * whether the popup opened, and it did not.
	 */
	return true === openRecord( record, true );
}

/**
 * Closes a popup by slug, on behalf of the public API.
 *
 * @param {*} slug Popup slug.
 * @return {boolean|null} Whether it closed, or null when no such popup is on the page.
 */
function closeBySlug( slug ) {
	const record = findBySlug( slug );

	return null === record ? null : closeRecord( record );
}

/**
 * Resolves a slug to a record.
 *
 * @param {*} slug Popup slug.
 * @return {PopkitRecord|null} Matching record, or null.
 */
function findBySlug( slug ) {
	if ( null === session || 'string' !== typeof slug || '' === slug ) {
		return null;
	}

	for ( const record of session.records ) {
		if ( record.slug === slug ) {
			return record;
		}
	}

	return null;
}

/**
 * Reports whether a schedule constrains anything.
 *
 * Truthiness rather than a strict comparison, and it leans the safe way: a
 * schedule whose `enabled` arrived as something other than a boolean is treated
 * as switched on, which narrows the audience rather than widening it.
 *
 * @param {Object} schedule Emitted schedule object.
 * @return {boolean} True when the schedule is enabled.
 */
function isScheduled( schedule ) {
	return !! schedule.enabled;
}

/**
 * Reports whether a rule set carries a rule the context route answers.
 *
 * @param {Object} conditions Emitted rule set.
 * @return {boolean} True when a `user_state` rule is present.
 */
function hasUserStateRule( conditions ) {
	const groups = conditions.groups;

	if ( ! Array.isArray( groups ) ) {
		return false;
	}

	return groups.some( ( group ) => {
		const rules = isObject( group ) ? group.rules : null;

		return (
			Array.isArray( rules ) &&
			rules.some(
				( rule ) => isObject( rule ) && USER_STATE_RULE === rule.type
			)
		);
	} );
}

/**
 * Reports whether a value is a non-null object.
 *
 * @param {*} value Value to test.
 * @return {boolean} True for objects and arrays, false for null and primitives.
 */
function isObject( value ) {
	return 'object' === typeof value && null !== value;
}
