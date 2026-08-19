/**
 * popkit — trigger arming, first fire wins, and teardown.
 *
 * Pipeline stage 10 (`docs/CLAUDE.md` -> Architecture invariants), and nothing
 * else. This module decides *which* triggers are live and *when* they stop being
 * live; the triggers themselves decide what to listen to, and the controller
 * decides what firing means.
 *
 * ## The contract this depends on, and cannot enforce
 *
 * **`armTriggers()` is called only for a popup that has already passed stages 7,
 * 8 and 9** — client conditions, schedule, and the frequency cap. That gate lives
 * in `controller.js`, and it is what makes the invariant "nothing binds to the
 * DOM for a popup that could never show" true. This module has no way to check
 * it: by the time it is handed a popup and an element, the questions have been
 * answered and the answers are not carried in the record. Calling it earlier
 * would not fail loudly, it would quietly attach listeners for popups the
 * pipeline had already rejected — so the gate stays at the one call site rather
 * than being re-derived here from data that would eventually disagree with it.
 *
 * A deep link is a trigger and not a bypass, for the same reason
 * (`docs/build-plan.md` -> Phase 3): `?popkit={slug}` arrives from whoever sent
 * the link, so it reaches this stage after the pipeline like every other trigger
 * rather than through `window.popkit.open()`, which does bypass targeting.
 *
 * ## First fire wins — once something has actually been decided
 *
 * Multiple triggers on one popup are independent and race. A `fire()` that
 * settles the question calls back into the controller and then tears down
 * **every** trigger for that popup, including the one that fired. Later calls —
 * from a sibling that was mid-flight, from a listener the browser had already
 * queued, from a trigger that fires twice — are no-ops.
 *
 * What makes the question settled is the controller's answer, not the attempt.
 * `onFire()` reports one of two things, and they are not the same event:
 *
 * - **`false` — refused, and the refusal may not hold later.** One popup is on
 *   screen at a time, so a fire arriving while another popup is open opens
 *   nothing. Latching there would spend the popup's one chance on a moment the
 *   visitor was looking at something else: the click on the author's configured
 *   selector would be swallowed, and that popup would be dead for the rest of
 *   the pageview with no way back. So nothing is latched, nothing is torn down,
 *   and the next fire is allowed to try again.
 * - **Anything else — the question is answered and arming is over.** `true` is
 *   an open. `null`, `undefined`, and a `fire()` that threw are refusals nothing
 *   later will change: `popkit:before-open` was vetoed, the markup is missing,
 *   or `<dialog>` refused to open. A veto especially must latch — re-arming on
 *   one would ask the visitor's consent integration the same question on every
 *   scroll event.
 *
 * Only `false` means "try again", so a caller that cannot tell its refusals
 * apart, or reports nothing at all, gets the conservative behavior rather than
 * a popup that re-asks forever.
 *
 * ## Teardown is required, and it is the whole point
 *
 * `setup()` must return a function. One that does not is a defect and is warned
 * about rather than accommodated: an unarmed or un-tearable trigger is either a
 * popup that never opens or a listener that outlives the popup, and both are
 * invisible without the message. Such a trigger is recorded as having armed
 * nothing at all, which is what it did — a popup left with no real teardown does
 * not bind the shared `pagehide` listener, exactly as one whose every trigger
 * was unregistered does not.
 *
 * Teardown runs on fire, on abort, and on a `pagehide` that means the document
 * is being discarded. Not on `unload`, which is unreliable and disqualifies the
 * page from the back/forward cache. A `pagehide` that means the document is
 * being *frozen* into that cache is deliberately not a teardown — see
 * {@link onPagehide}, where the two are told apart.
 *
 * @see docs/CLAUDE.md
 * @see docs/data-model.md -> Triggers
 * @see docs/build-plan.md -> Phase 3
 */

/**
 * Disarm functions for every popup with triggers armed right now.
 *
 * One shared `pagehide` listener serves all of them, added when the first popup
 * arms and removed when the last one disarms. That is one listener per page
 * rather than one per popup: the exit-criterion test counts listeners across
 * `pagehide`, and a set that is empty again afterwards is the same evidence
 * whether the page carried one popup or ten.
 *
 * Held strongly, which is safe because disarming removes the entry — and every
 * path out of arming disarms.
 *
 * @type {Set<Function>}
 */
const armedPopups = new Set();

/**
 * Tears every armed popup down, but only when the page is really going away.
 *
 * `pagehide` names two different events, and `persisted` is what tells them
 * apart:
 *
 * - **`false` — the document is being discarded.** Every listener and timer
 *   armed on its behalf goes with it, which is what this loop is for.
 * - **`true` — the document is being frozen into the back/forward cache.** It
 *   is not going away. The visitor may press Back and be handed this same
 *   document, with this same DOM and these same listeners still attached.
 *
 * A frozen page therefore keeps its triggers, and this returns without touching
 * them. Tearing down here would leave every popup on a restored page inert with
 * nothing to re-arm it — the exact failure that choosing `pagehide` over
 * `unload` was supposed to avoid, reintroduced by the handler.
 *
 * The alternative — disarm now, re-arm on a `pageshow` with `persisted` set —
 * was considered and rejected. It runs every `setup()` a second time, which
 * restarts `page_load`'s delay from zero and re-measures `scroll_depth` and
 * `deep_link` against the restored page, and it costs bytes against a budget
 * `docs/CLAUDE.md` -> Weight treats as a hard limit, to express a difference the
 * frozen document does not have.
 *
 * Leaving timers armed into the cache is safe because a frozen document runs
 * nothing: its task queues are suspended, so no timer, no scroll handler and no
 * click handler can run while it sits there. `page_load` is the one worth saying
 * outright — its timer is suspended along with everything else and resumes on
 * restore, so a delay the visitor had nearly finished earning is honored on the
 * document they came back to rather than canceled behind their back. Time spent
 * on another page is not time spent on this one, and that is what the pause
 * expresses.
 *
 * Iterating the set directly is safe: each disarm removes only its own entry,
 * and an entry deleted during iteration is one this loop has already visited.
 *
 * @param {PageTransitionEvent} event The `pagehide` event being handled.
 * @return {void}
 */
function onPagehide( event ) {
	if ( event.persisted ) {
		return;
	}

	for ( const disarm of armedPopups ) {
		disarm();
	}
}

/**
 * Starts sharing the `pagehide` listener with one more popup.
 *
 * @param {Function} disarm Popup's disarm function.
 * @return {void}
 */
function watchPagehide( disarm ) {
	armedPopups.add( disarm );

	if ( 1 === armedPopups.size ) {
		window.addEventListener( 'pagehide', onPagehide );
	}
}

/**
 * Stops sharing the `pagehide` listener, removing it once nobody is left.
 *
 * @param {Function} disarm Popup's disarm function.
 * @return {void}
 */
function unwatchPagehide( disarm ) {
	if ( armedPopups.delete( disarm ) && 0 === armedPopups.size ) {
		window.removeEventListener( 'pagehide', onPagehide );
	}
}

/**
 * Runs one teardown, swallowing anything it throws.
 *
 * A throwing teardown must not strand the ones queued behind it. The listeners
 * this loop exists to remove are the reason the loop cannot be allowed to stop
 * halfway, and the common caller is `pagehide`, where a console message would
 * arrive as the document is going away and help nobody.
 *
 * @param {Function} teardown Teardown function returned by a trigger's setup().
 * @return {void}
 */
function run( teardown ) {
	try {
		teardown();
	} catch {
		// Deliberately silent — see above.
	}
}

/**
 * Warns about a trigger that will not work.
 *
 * Kept to a fragment rather than a sentence, on purpose. Every byte of it ships
 * to every visitor on every page carrying a popup, and it is read by a developer
 * with a console open — for whom the trigger type, the popup slug and three
 * words are the whole of the information. What follows from an unarmed trigger
 * is documented where a developer who needs it will look, and this module's
 * budget is `docs/CLAUDE.md` -> Weight rather than the width of a console.
 *
 * Not translated, for the reason given in `api.js`: `@wordpress/i18n` is not
 * available to this bundle, and this is a developer-facing diagnostic naming a
 * trigger type and a slug that are themselves untranslated.
 *
 * @param {string} type   Configured trigger type.
 * @param {string} slug   Popup slug, for locating the configuration.
 * @param {string} reason What is wrong with it.
 * @return {void}
 */
function warn( type, slug, reason ) {
	// eslint-disable-next-line no-console -- A trigger that did not arm is a popup that never opens, with nothing else on screen to explain why.
	console.warn( `popkit: trigger "${ type }" on "${ slug }" ${ reason }` );
}

/**
 * Resolves a configured trigger type to its registered module.
 *
 * Both registry shapes are accepted — a `Map`, and a plain object keyed by
 * trigger key. `controller.js` builds a `Map`; an object literal is what a test
 * arming one trigger reaches for, and what an integration adding a trigger from
 * outside the bundle is most likely to hand over. The two lines are worth not
 * having every trigger on a site report as unregistered over a container choice.
 *
 * The `setup` check is what makes a plain-object lookup safe: a `type` that
 * happens to name something on `Object.prototype` resolves to a value with no
 * `setup` function and is reported as unregistered, which it is.
 *
 * @param {Map|Object} registry Trigger modules, keyed by trigger key.
 * @param {string}     type     Configured trigger type.
 * @return {Object|null} The module, or null when nothing usable is registered.
 */
function resolve( registry, type ) {
	if ( ! type || ! registry ) {
		return null;
	}

	const module =
		'function' === typeof registry.get
			? registry.get( type )
			: registry[ type ];

	return module && 'function' === typeof module.setup ? module : null;
}

/**
 * Calls one trigger's `setup()` and returns the teardown it can be held to.
 *
 * A `setup()` that threw, or that returned anything other than a function,
 * yields null: it armed nothing this module can account for, so it contributes
 * nothing to the popup's teardown list and cannot on its own cause the shared
 * `pagehide` listener to be bound. The popup's other triggers stay armed and
 * tearable, and the defect is on the console rather than in the behavior.
 *
 * A `setup()` that throws *after* binding a listener leaks it, and nothing here
 * can know that it did. The warning is the remedy.
 *
 * @param {Object} module Registered trigger module.
 * @param {Object} api    Trigger API for this popup.
 * @param {Object} values Configured values for this trigger.
 * @param {string} type   Configured trigger type, for the warning.
 * @param {string} slug   Popup slug, for the warning.
 * @return {Function|null} Teardown function, or null when nothing armed.
 */
function setUp( module, api, values, type, slug ) {
	try {
		const teardown = module.setup( api, values );

		if ( 'function' === typeof teardown ) {
			return teardown;
		}

		warn( type, slug, 'returned no teardown' );
	} catch {
		warn( type, slug, 'threw from setup()' );
	}

	return null;
}

/**
 * The object every trigger's `setup()` is handed.
 *
 * One per popup, shared by all of its triggers: nothing on it varies per
 * trigger, and a fresh object per trigger would only cost bytes to express a
 * distinction that does not exist.
 *
 * @typedef  {Object}       PopkitTriggerApi
 * @property {Function}     fire    Asks the controller to open the popup, and
 *                                  tears every trigger down once it answers with
 *                                  anything but "not now". Takes one optional
 *                                  argument, the element the visitor interacted
 *                                  with, which is forwarded to the controller so
 *                                  focus can return there on close.
 * @property {Function}     abort   Tears every trigger down without opening.
 * @property {Object}       popup   The popup's config object: `id`, `slug`, and
 *                                  the rest of the emitted record.
 * @property {Element|null} element The popup's root element.
 */

/**
 * Arms every configured trigger for one popup. Pipeline stage 10.
 *
 * **Only ever call this for a popup that passed stages 7 to 9.** See the file
 * header for why that gate is the caller's and cannot be re-derived here.
 *
 * Arming stops the moment anything opens the popup, so a trigger that fires
 * successfully from its own `setup()` — `page_load` with `delay_ms: 0` is the
 * one legitimate case — leaves the siblings after it unarmed rather than armed
 * and immediately torn down. A teardown returned by a `setup()` that fired is
 * run at once, so the trigger that opened the popup cleans up like any other. A
 * fire the controller refused with `false` settles nothing, and arming carries
 * on past it.
 *
 * Nothing fires on its own: a popup with no configured triggers arms nothing and
 * gets a no-op teardown. What an untriggered popup should do is the controller's
 * decision, not this module's.
 *
 * `values` is passed to `setup()` as stored, with `{}` substituted for a missing
 * or non-object value. Defaults belong to the PHP field schema and to the
 * trigger module reading it defensively; a second set applied here would be a
 * second source of truth for them.
 *
 * @param {Object}       popup    Popup config object, whose `triggers` array is
 *                                the emitted `[ { type, values } ]` list.
 * @param {Element|null} element  Popup root element, already resolved.
 * @param {Map|Object}   registry Trigger modules, keyed by trigger key.
 * @param {Function}     onFire   Opens the popup. Called with the interacted-with
 *                                element if the firing trigger supplied one, and
 *                                expected to return `false` — and only `false` —
 *                                when it refused for a reason that may not hold
 *                                the next time a trigger fires. See the file
 *                                header.
 * @return {Function} Teardown for this popup. Idempotent, safe to call twice.
 */
export function armTriggers( popup, element, registry, onFire ) {
	const configs = Array.isArray( popup?.triggers ) ? popup.triggers : [];
	const slug = popup?.slug;
	const teardowns = [];

	let latched = false;
	let disarmed = false;

	/**
	 * Tears every trigger on this popup down, and stops sharing `pagehide`.
	 *
	 * Idempotent: the flag is set before anything is run, so a teardown that
	 * calls back in — an abort from inside a teardown, a `pagehide` arriving
	 * mid-fire — finds nothing left to do. The list is drained rather than
	 * iterated, so a second call cannot remove a listener twice.
	 *
	 * @return {void}
	 */
	function disarm() {
		if ( disarmed ) {
			return;
		}

		disarmed = true;
		unwatchPagehide( disarm );

		while ( 0 < teardowns.length ) {
			run( teardowns.pop() );
		}
	}

	/** @type {PopkitTriggerApi} */
	const api = {
		fire( trigger ) {
			if ( latched || disarmed ) {
				return;
			}

			/*
			 * Latched *before* the call and released after, rather than set
			 * once afterwards. Opening dispatches `popkit:before-open` into
			 * whatever the site has listening, and a listener that scrolls,
			 * clicks, or calls a trigger's own handler would otherwise re-enter
			 * this function while the first call is still in flight.
			 */
			latched = true;

			let opened;

			try {
				opened = onFire( trigger );
			} finally {
				/*
				 * `false` is the one answer that means "not now, and ask again"
				 * — another popup is on screen. Everything else settles it,
				 * including an exception on its way out of `popkit:before-open`,
				 * which leaves `opened` undefined: the page is left free of this
				 * popup's listeners either way.
				 */
				latched = false !== opened;

				if ( latched ) {
					disarm();
				}
			}
		},

		abort: disarm,
		popup,
		element,
	};

	for ( const config of configs ) {
		if ( disarmed ) {
			break;
		}

		const type =
			config && 'string' === typeof config.type ? config.type : '';
		const module = resolve( registry, type );

		if ( ! module ) {
			warn( type, slug, 'is not registered' );
			continue;
		}

		const values = config.values;
		const teardown = setUp(
			module,
			api,
			values && 'object' === typeof values ? values : {},
			type,
			slug
		);

		if ( ! teardown ) {
			continue;
		}

		if ( disarmed ) {
			run( teardown );
		} else {
			teardowns.push( teardown );
		}
	}

	/*
	 * Nothing armed, nothing to tear down, and therefore nothing bound on this
	 * popup's behalf — not even the shared listener. A popup whose every trigger
	 * was unregistered and one whose every trigger failed its `setup()` are the
	 * same page: no listeners, no popup.
	 */
	if ( ! disarmed && 0 < teardowns.length ) {
		watchPagehide( disarm );
	}

	return disarm;
}
