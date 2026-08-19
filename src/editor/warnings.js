/**
 * popkit — the editor's four warnings, as pure functions.
 *
 * Nothing here imports React, touches the store, or reads the DOM. Every check
 * takes plain data and returns plain data, which is what lets the whole set be
 * unit tested without mounting an editor — and, more to the point, what lets a
 * mutation proof show a test going red. A warning implemented inside a
 * component can only be tested by rendering one, and a rendered assertion that
 * silently stops reaching the branch it names still passes.
 *
 * ## What a warning is for
 *
 * Each one names a popup that is *saveable and wrong*: the editor will store it,
 * the server will serve it, and nothing downstream will complain. That is the
 * whole selection criterion. A misconfiguration the REST schema rejects needs no
 * warning because the save fails and says why; a misconfiguration that produces
 * a popup nobody will ever see needs one, because the author's only other signal
 * is silence.
 *
 * None of them blocks saving. An author part-way through building a schedule has
 * an expired one for as long as it takes to type the end date, and a warning that
 * refused the save would be wrong about every intermediate state.
 *
 * ## `now` is a parameter
 *
 * {@link scheduleExpired} takes the current time rather than reading a clock.
 * The frontend bans `Date.now()` outright — see `src/frontend/clock.js` — for
 * reasons that do not apply in an authenticated admin screen, so the ban is not
 * what motivates this. Testability is: a test asserting "this schedule is
 * expired" has to name the moment it is expired *at*, or it is a test that
 * changes its mind depending on when it runs.
 *
 * @see docs/build-plan.md -> Phase 5
 * @see docs/data-model.md
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Block names that can supply the popup's accessible name.
 *
 * `core/heading` only. `core/post-title` is not here: a popup is rendered
 * outside the loop and the block would resolve against whatever post the page
 * is showing, which is not the popup.
 *
 * @type {string[]}
 */
const HEADING_BLOCKS = [ 'core/heading' ];

/**
 * Warning identifiers, exported so tests and panels name the same strings.
 *
 * @type {Object<string, string>}
 */
export const WARNING = {
	MISSING_ACCESSIBLE_NAME: 'missing-accessible-name',
	SCHEDULE_EXPIRED: 'schedule-expired',
	IMPOSSIBLE_CONDITIONS: 'impossible-conditions',
	UNAVAILABLE_CONDITION: 'unavailable-condition',
};

/**
 * Strips tags and entities from a block's stored HTML and reports whether
 * anything legible is left.
 *
 * A heading block stores rich text, so `"<em> </em>"` and `"&nbsp;"` are both
 * headings a reader would call empty and `String.prototype.trim()` alone would
 * call filled.
 *
 * Both steps avoid a regular expression. `tests/js/no-regex-invariant.test.js`
 * does not scan this directory — src/editor is admin-side and ships separately —
 * so this is a choice rather than an enforced rule, and the security argument
 * behind the ban is weak here: a heading is authored by someone who already holds
 * `edit_post`. It is written this way because a linear walk costs nothing at the
 * size of a heading, and a file that reaches for a pattern *because* nothing is
 * checking is how the exception becomes the convention.
 *
 * @param {string} [html] Stored inner HTML of a heading block.
 * @return {boolean} True when the heading would announce something.
 */
function hasVisibleText( html ) {
	if ( 'string' !== typeof html ) {
		return false;
	}

	let text = '';
	let depth = 0;

	for ( const character of html ) {
		if ( '<' === character ) {
			depth += 1;
		} else if ( '>' === character ) {
			depth = 0 < depth ? depth - 1 : 0;
		} else if ( 0 === depth ) {
			text += character;
		}
	}

	// `&nbsp;` survives the tag strip as an entity and is whitespace to a reader.
	// split/join rather than replace(), which would need a pattern to go global.
	return '' !== text.split( '&nbsp;' ).join( ' ' ).trim();
}

/**
 * Walks a block tree looking for a heading that would name the popup.
 *
 * Recursive because a heading inside a group, a column or a cover is still the
 * popup's headline. The renderer finds it too — it reads the *rendered* HTML, so
 * nesting is invisible to it — and a warning that disagreed with the renderer
 * about what counts as a heading would fire on popups that are correctly named.
 *
 * @param {Array<Object>} [blocks] Block tree, as `getBlocks()` returns it.
 * @return {boolean} True when some heading in the tree has visible text.
 */
function hasNamingHeading( blocks ) {
	if ( ! Array.isArray( blocks ) ) {
		return false;
	}

	return blocks.some( ( block ) => {
		if (
			HEADING_BLOCKS.includes( block?.name ) &&
			hasVisibleText( block?.attributes?.content )
		) {
			return true;
		}

		return hasNamingHeading( block?.innerBlocks );
	} );
}

/**
 * Warns when the popup would be announced as the generic word "Popup".
 *
 * `Renderer::accessible_name()` resolves a name in four steps and the last
 * cannot fail, so this is never about an unnamed dialog. It is about the last
 * step: a popup with no heading and no title is announced as "Popup", which
 * tells a screen reader user that something opened and nothing about what.
 *
 * This check is deliberately stronger than the server's, and for a narrower
 * reason than it once claimed. `Renderer`'s generated-`id` branch — the one that
 * mints `popkit-title-{ID}` for a heading with no author anchor — matches the
 * heading *tag* and never looks at its text, so an empty `<h2>` satisfies it and
 * produces an empty accessible name. The editor holds the block tree and can see
 * the difference, and the renderer's own docblock delegates the case here.
 *
 * What this comment used to say — that reading a heading's text back out needs
 * an API popkit's minimum WordPress does not have — was simply false.
 * `WP_HTML_Tag_Processor::next_token()`, `::get_token_type()` and
 * `::get_modifiable_text()` are all `@since 6.5.0`, and the renderer now uses
 * them for the anchored-heading branch. The empty-heading gap survives on the
 * other branch by choice, not by capability: it costs a token walk on every
 * popup to catch a case the editor already refuses to let an author save.
 *
 * @param {Object}        [subject]        Editor state.
 * @param {Array<Object>} [subject.blocks] Block tree of the popup content.
 * @param {string}        [subject.title]  Popup post title.
 * @return {Object|null} Warning, or null when the popup is adequately named.
 */
export function missingAccessibleName( { blocks, title } = {} ) {
	if ( hasNamingHeading( blocks ) ) {
		return null;
	}

	if ( 'string' === typeof title && '' !== title.trim() ) {
		return null;
	}

	return {
		id: WARNING.MISSING_ACCESSIBLE_NAME,
		message: __(
			'This popup has no heading and no title, so screen readers will announce it only as “Popup”. Add a heading to the content, or give the popup a title.',
			'popkit'
		),
	};
}

/**
 * Parses a stored ISO 8601 UTC timestamp into milliseconds.
 *
 * Returns `null` for everything that is not one, rather than the `NaN`
 * `Date.parse()` produces, so callers test for a value instead of remembering
 * to test for `NaN` — a comparison against `NaN` is false in both directions and
 * would make an unreadable date look like a schedule that has not expired.
 *
 * @param {string} [value] Stored timestamp.
 * @return {number|null} Milliseconds since the epoch, or null.
 */
function parseStamp( value ) {
	if ( 'string' !== typeof value || '' === value ) {
		return null;
	}

	const parsed = Date.parse( value );

	return Number.isFinite( parsed ) ? parsed : null;
}

/**
 * Warns when the schedule's end date has already passed.
 *
 * Only fires on an *enabled* schedule with a readable `end` in the past. A
 * disabled schedule is inert — the popup shows regardless of what the dates say
 * — so warning about it would describe a field the popup does not consult.
 *
 * `start` is not checked against `now`. A schedule that begins next week is the
 * ordinary reason to write one, and warning about it would fire on correct
 * configuration, which is the fastest way to teach an author to ignore warnings.
 *
 * @param {Object} [subject]          Editor state.
 * @param {Object} [subject.schedule] Stored schedule object.
 * @param {number} [subject.now]      Current time, in milliseconds since the epoch.
 * @return {Object|null} Warning, or null when the schedule has not expired.
 */
export function scheduleExpired( { schedule, now } = {} ) {
	if ( true !== schedule?.enabled ) {
		return null;
	}

	const end = parseStamp( schedule?.end );

	if ( null === end || ! Number.isFinite( now ) || end > now ) {
		return null;
	}

	return {
		id: WARNING.SCHEDULE_EXPIRED,
		message: __(
			'This popup’s schedule ended in the past, so it will not display to anyone. Change the end date, or turn the schedule off.',
			'popkit'
		),
	};
}

/**
 * Compares two stored `values` objects for equality.
 *
 * Order-insensitive on keys and recursive through arrays and plain objects,
 * because two rules written in either order are the same rule. Values are
 * scalars, arrays and objects out of a JSON payload, so there is nothing here
 * that `JSON.stringify()` would get wrong except key order — which is exactly
 * the thing that would make it wrong.
 *
 * @param {*} a First value.
 * @param {*} b Second value.
 * @return {boolean} True when the two are structurally equal.
 */
function sameValues( a, b ) {
	if ( a === b ) {
		return true;
	}

	if ( Array.isArray( a ) || Array.isArray( b ) ) {
		return (
			Array.isArray( a ) &&
			Array.isArray( b ) &&
			a.length === b.length &&
			a.every( ( item, index ) => sameValues( item, b[ index ] ) )
		);
	}

	if (
		'object' !== typeof a ||
		'object' !== typeof b ||
		null === a ||
		null === b
	) {
		return false;
	}

	const keys = Object.keys( a );

	return (
		keys.length === Object.keys( b ).length &&
		keys.every(
			( key ) =>
				Object.hasOwn( b, key ) && sameValues( a[ key ], b[ key ] )
		)
	);
}

/**
 * Whether a group contains a rule and its own negation.
 *
 * Rules in a group are AND'd, so `X AND NOT X` is unsatisfiable whatever `X`
 * means — no knowledge of the condition is needed, which is what makes this
 * check safe to run against a condition this build has never heard of.
 *
 * @param {Array<Object>} rules Rules in one group.
 * @return {boolean} True when the group contradicts itself.
 */
function hasDirectContradiction( rules ) {
	return rules.some( ( rule, index ) =>
		rules.slice( index + 1 ).some(
			( other ) =>
				rule?.type === other?.type &&
				// `negate` is optional and defaults false, so both are coerced
				// before comparison: `undefined !== false` would miss the pair.
				Boolean( rule?.negate ) !== Boolean( other?.negate ) &&
				sameValues( rule?.values, other?.values )
		)
	);
}

/**
 * Whether a group demands two different values of one single-valued enum field.
 *
 * `user_state` is the motivating case: a group requiring both `logged_in` and
 * `logged_out` matches nobody, and an author who wrote it meant two groups.
 * Restricted to conditions whose schema is exactly one `enum` field, because
 * that is the only shape where "two rules, different values" is provably
 * unsatisfiable rather than merely suspicious — a condition with two fields can
 * have rules that differ in one and agree in the other.
 *
 * Both rules must be un-negated. `NOT logged_in AND NOT logged_out` is
 * unsatisfiable too, but only because the enum has two members, and counting
 * members to decide would make the warning depend on a vocabulary a plugin can
 * extend.
 *
 * @param {Array<Object>} rules      Rules in one group.
 * @param {Object}        conditions Registry conditions, keyed by condition key.
 * @return {boolean} True when the group demands incompatible enum values.
 */
function hasEnumConflict( rules, conditions ) {
	return rules.some( ( rule, index ) => {
		if ( Boolean( rule?.negate ) ) {
			return false;
		}

		const fields = conditions?.[ rule?.type ]?.fields;
		const names = fields ? Object.keys( fields ) : [];

		if ( 1 !== names.length || 'enum' !== fields[ names[ 0 ] ]?.type ) {
			return false;
		}

		const field = names[ 0 ];

		return rules
			.slice( index + 1 )
			.some(
				( other ) =>
					rule?.type === other?.type &&
					! Boolean( other?.negate ) &&
					! sameValues(
						rule?.values?.[ field ],
						other?.values?.[ field ]
					)
			);
	} );
}

/**
 * Reports which groups in a rule set can never be satisfied.
 *
 * Exported for the panel, which marks the individual groups, and used by
 * {@link impossibleConditions} for the set-level warning.
 *
 * @param {Object} [conditions] Stored rule set.
 * @param {Object} [registry]   Registry payload.
 * @return {number[]} Indexes of groups that can never match.
 */
export function impossibleGroups( conditions, registry ) {
	const groups = Array.isArray( conditions?.groups ) ? conditions.groups : [];

	return groups.reduce( ( found, group, index ) => {
		const rules = Array.isArray( group?.rules ) ? group.rules : [];

		if (
			hasDirectContradiction( rules ) ||
			hasEnumConflict( rules, registry?.conditions )
		) {
			found.push( index );
		}

		return found;
	}, [] );
}

/**
 * Warns when no group in the rule set can ever be satisfied.
 *
 * Groups are OR'd, so one satisfiable group is enough for the popup to have an
 * audience and there is nothing to warn about. The warning fires only when the
 * set has groups and every one of them is impossible — at which point the popup
 * is stored, saved, served and unreachable.
 *
 * An empty `groups` array means "always match" and is the default a new popup
 * carries. It is not a contradiction and never warns.
 *
 * The two detectors are deliberately conservative. Proving that an arbitrary
 * pair of conditions cannot hold together needs semantics this layer does not
 * have — whether `/blog/` prefix and `/shop/` prefix overlap is a question about
 * the URL matcher — so this reports only the two shapes that are unsatisfiable
 * on structure alone. A missed contradiction leaves an author where they already
 * were; a false one tells them a working popup is broken.
 *
 * @param {Object} [subject]            Editor state.
 * @param {Object} [subject.conditions] Stored rule set.
 * @param {Object} [subject.registry]   Registry payload.
 * @return {Object|null} Warning, or null when some group can match.
 */
export function impossibleConditions( { conditions, registry } = {} ) {
	const groups = Array.isArray( conditions?.groups ) ? conditions.groups : [];

	if ( 0 === groups.length ) {
		return null;
	}

	const impossible = impossibleGroups( conditions, registry );

	if ( impossible.length !== groups.length ) {
		return null;
	}

	return {
		id: WARNING.IMPOSSIBLE_CONDITIONS,
		groups: impossible,
		message: __(
			'Every targeting group contains rules that contradict each other, so this popup will not display to anyone. Rules in a group must all match; put alternatives in separate groups.',
			'popkit'
		),
	};
}

/**
 * Lists stored rule types this build has no registration for.
 *
 * Exported so the panel can render those rules read-only without repeating the
 * membership test and drifting from it.
 *
 * @param {Object} [conditions] Stored rule set.
 * @param {Object} [registry]   Registry payload.
 * @return {string[]} Unregistered rule types, in first-seen order, without repeats.
 */
export function unavailableTypes( conditions, registry ) {
	const groups = Array.isArray( conditions?.groups ) ? conditions.groups : [];
	const known = registry?.conditions ?? {};
	const found = [];

	for ( const group of groups ) {
		const rules = Array.isArray( group?.rules ) ? group.rules : [];

		for ( const rule of rules ) {
			const type = rule?.type;

			if (
				'string' === typeof type &&
				'' !== type &&
				! Object.hasOwn( known, type ) &&
				! found.includes( type )
			) {
				found.push( type );
			}
		}
	}

	return found;
}

/**
 * Warns when a stored rule names a condition that is not registered.
 *
 * The usual cause is a deactivated plugin, and the consequence is severe and
 * invisible: the client fails an unresolvable rule closed, its group fails with
 * it, and if that was the only group the popup displays to nobody. Nothing in
 * the admin says so, and the stored targeting still reads as though it works.
 *
 * The warning names the types rather than describing the situation generically,
 * because "a condition is unavailable" does not tell an author which plugin to
 * reactivate and the type key usually does.
 *
 * @param {Object} [subject]            Editor state.
 * @param {Object} [subject.conditions] Stored rule set.
 * @param {Object} [subject.registry]   Registry payload.
 * @return {Object|null} Warning, or null when every stored rule type is registered.
 */
export function unavailableCondition( { conditions, registry } = {} ) {
	const types = unavailableTypes( conditions, registry );

	if ( 0 === types.length ) {
		return null;
	}

	return {
		id: WARNING.UNAVAILABLE_CONDITION,
		types,
		message: sprintf(
			/* translators: %s: comma-separated list of unregistered condition type keys. */
			__(
				'This popup targets conditions that are not available on this site: %s. Their rules cannot be evaluated, so the popup will not display to anyone while they remain unresolvable. The stored settings are shown as saved and are preserved until you remove them.',
				'popkit'
			),
			types.join( ', ' )
		),
	};
}

/**
 * Runs every warning against one editor state.
 *
 * Order is fixed and deliberate: unavailable conditions first, because that one
 * usually explains the others — a deactivated plugin produces an unresolvable
 * rule, and an author reading "this popup shows to nobody" needs the cause
 * before the symptom.
 *
 * @param {Object}        [subject]            Editor state.
 * @param {Array<Object>} [subject.blocks]     Block tree of the popup content.
 * @param {string}        [subject.title]      Popup post title.
 * @param {Object}        [subject.schedule]   Stored schedule object.
 * @param {Object}        [subject.conditions] Stored rule set.
 * @param {Object}        [subject.registry]   Registry payload.
 * @param {number}        [subject.now]        Current time, in milliseconds since the epoch.
 * @return {Array<Object>} Every warning that fired, in the order above.
 */
export function collectWarnings( subject = {} ) {
	return [
		unavailableCondition( subject ),
		impossibleConditions( subject ),
		missingAccessibleName( subject ),
		scheduleExpired( subject ),
	].filter( Boolean );
}
