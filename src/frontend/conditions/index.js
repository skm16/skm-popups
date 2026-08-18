/**
 * popkit — the client-context condition registry.
 *
 * Pipeline stage 7 (`docs/CLAUDE.md` -> Architecture invariants) evaluates the
 * rules the server could not judge. This file is the list of rules it *can*
 * judge; everything else fails closed. `ruleAllows()` in `controller.js` owns
 * the denial logic and this file owns the contract it denies against.
 *
 * ## The contract a condition module implements
 *
 * ```js
 * export default {
 *     key: 'device',
 *     needsContext: false,
 *     evaluate( values, ctx ) {
 *         return true | false | undefined;
 *     },
 * };
 * ```
 *
 * `values` is the rule's stored `values` object, emitted verbatim by PHP, so its
 * keys stay snake_case — they are stored field names, not identifiers this
 * bundle chose. `ctx` is the context payload from
 * `GET /wp-json/popkit/v1/context`. A module declaring `needsContext: true` is
 * never called without one: `controller.js` denies the rule before the evaluator
 * runs, so `ctx` is either a payload carrying every requested field or the
 * evaluator was not reached at all.
 *
 * ### Three return values, not two
 *
 * `true` and `false` are decisions, and `controller.js` applies `negate` to
 * them. Anything else — `undefined` is the spelling used throughout — means
 * *the module did not answer the question*, and the rule is denied with `negate`
 * left unapplied.
 *
 * That third value is what lets a module be handed a garbage `max_width`, an
 * unreadable `state`, or a browser that refuses storage and still narrow the
 * audience rather than widen it. Returning `false` for those would be wrong in
 * the one direction that matters: a `negate: true` rule whose evaluator could
 * not answer would then *pass*, and `docs/data-model.md` -> Evaluation semantics
 * is explicit that negating an unknown must never resurrect it.
 *
 * Being unable to answer is rare by design. Every field these modules read is
 * declared in PHP, validated at save time against that declaration, and
 * sanitized from it, so an unreadable value means a hand-edited row or a
 * forged REST write — not an author using the editor. The third value exists
 * because "rare" is not "impossible" and the failure it prevents is silent.
 *
 * ### `needsContext`
 *
 * Read by `controller.js`, not decoration. It is the condition's own declaration
 * that it cannot be evaluated without the context route, and the reason a module
 * never has to invent an answer for a payload that is not there.
 *
 * ## Why a Map
 *
 * A rule's `type` is author data that arrived over the wire and reaches this
 * container as a lookup key. On a `Map` a config saying `"type": "constructor"`
 * finds nothing; on an object literal it would find something off
 * `Object.prototype`, and `controller.js` would then be asking whether that
 * something has a callable `evaluate`. `TRIGGERS` is a `Map` for the same
 * reason.
 *
 * ## No client-side extension point in v1
 *
 * Registering a condition from another plugin declares its *schema* in PHP and
 * gets editor UI, REST validation and sanitization for free. It does not get an
 * `evaluate` function into this bundle — the same limitation, for the same
 * reasons, that `controller.js` documents at length for triggers. An
 * unregistered client rule is emitted with `"unknown": true` and denied, so the
 * author's stored targeting survives intact until a runtime path exists for it.
 *
 * @see docs/CLAUDE.md -> Architecture invariants
 * @see docs/data-model.md -> Built-in conditions
 * @see src/frontend/controller.js
 */

import device from './device.js';
import referrer from './referrer.js';
import userState from './user-state.js';
import utm from './utm.js';
import visitHistory from './visit-history.js';

/**
 * Client-context condition modules, keyed by the key each declares for itself.
 *
 * v1's five, and the only client condition registry in the shipped bundle. The
 * keys come off the modules rather than being spelled again here: each is also a
 * stored rule `type` and a PHP registration key, so the fewer places the bundle
 * repeats one, the fewer places it can be wrong.
 *
 * `user_role` is **not** here and is not a v1 condition. See
 * `docs/data-model.md` -> Deferred to 1.1.
 *
 * @type {Map<string, {key: string, needsContext: boolean, evaluate: Function}>}
 */
export const CLIENT_CONDITIONS = new Map(
	[ device, userState, referrer, utm, visitHistory ].map( ( condition ) => [
		condition.key,
		condition,
	] )
);
