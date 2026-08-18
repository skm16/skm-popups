/**
 * The editor's four warnings.
 *
 * Every case here is a popup the REST schema accepts and the server serves. That
 * is the point of the set: a misconfiguration the save rejects reports itself, and
 * these do not.
 *
 * The registry fixture below is a trimmed copy of a real
 * `GET /popkit/v1/registry` payload, not an invented one. Two of the four
 * warnings read field schemas out of it, and a fixture that guessed at the shape
 * would let those tests pass against a registry the plugin does not produce —
 * the failure this suite exists to catch, reproduced inside the suite itself.
 *
 * @see src/editor/warnings.js
 */

/* global describe, it, expect */

import {
	WARNING,
	collectWarnings,
	impossibleConditions,
	impossibleGroups,
	missingAccessibleName,
	scheduleExpired,
	unavailableCondition,
	unavailableTypes,
} from '../../src/editor/warnings.js';

/**
 * Trimmed registry payload, copied from a live dump.
 *
 * Three entries, each earning its place: `user_state` is the single-enum-field
 * shape the conflict detector acts on, `referrer` is the two-field shape it must
 * leave alone, and `is_front_page` is the no-field shape that must not divide by
 * zero on the way past.
 */
const REGISTRY = {
	conditions: {
		user_state: {
			key: 'user_state',
			context: 'client',
			group: 'visitor',
			label: 'User state',
			fields: {
				state: {
					type: 'enum',
					enum: [ 'logged_in', 'logged_out' ],
					default: 'logged_in',
					label: 'Visitor state',
					control: 'select',
				},
			},
		},
		referrer: {
			key: 'referrer',
			context: 'client',
			group: 'visitor',
			label: 'Referrer',
			fields: {
				match: {
					type: 'enum',
					enum: [ 'exact', 'prefix', 'contains', 'glob' ],
					default: 'exact',
					label: 'How the referrer is compared',
					control: 'select',
				},
				value: {
					type: 'string',
					default: '',
					label: 'Referrer to match',
					control: 'url-match',
					max_length: 255,
				},
			},
		},
		is_front_page: {
			key: 'is_front_page',
			context: 'server',
			group: 'content',
			label: 'Front page',
			fields: {},
		},
	},
};

/**
 * Builds a rule set from groups of rules.
 *
 * @param {...Array<Object>} groups Rules per group.
 * @return {Object} Stored rule set.
 */
const ruleSet = ( ...groups ) => ( {
	groups: groups.map( ( rules ) => ( { rules } ) ),
} );

/**
 * Builds one heading block.
 *
 * @param {string} content Stored inner HTML.
 * @return {Object} Block.
 */
const heading = ( content ) => ( {
	name: 'core/heading',
	attributes: { content },
	innerBlocks: [],
} );

describe( 'missingAccessibleName', () => {
	it( 'warns when the popup has neither heading nor title', () => {
		const warning = missingAccessibleName( { blocks: [], title: '' } );

		expect( warning?.id ).toBe( WARNING.MISSING_ACCESSIBLE_NAME );
	} );

	it( 'is satisfied by a heading with text', () => {
		expect(
			missingAccessibleName( {
				blocks: [ heading( 'Join the list' ) ],
				title: '',
			} )
		).toBeNull();
	} );

	it( 'is satisfied by a title when there is no heading', () => {
		expect(
			missingAccessibleName( { blocks: [], title: 'Newsletter promo' } )
		).toBeNull();
	} );

	it( 'finds a heading nested inside a group', () => {
		const blocks = [
			{
				name: 'core/group',
				attributes: {},
				innerBlocks: [
					{
						name: 'core/columns',
						attributes: {},
						innerBlocks: [ heading( 'Buried but real' ) ],
					},
				],
			},
		];

		expect( missingAccessibleName( { blocks, title: '' } ) ).toBeNull();
	} );

	/*
	 * The case Renderer::accessible_name() cannot see. It matches the first
	 * heading *tag* in rendered HTML and has no way to read its text, so an empty
	 * heading gives the dialog an empty accessible name and the server is content.
	 * The editor holds the block tree and can tell the difference, which is the
	 * whole reason this check is stronger than the PHP rather than a mirror of it.
	 */
	it.each( [
		[ 'a heading with no content at all', '' ],
		[ 'a heading holding only markup', '<em></em>' ],
		[ 'a heading holding only a space inside markup', '<em> </em>' ],
		[ 'a heading holding only a non-breaking space', '&nbsp;' ],
		[ 'a heading holding only whitespace', '   ' ],
	] )( 'still warns for %s', ( _label, content ) => {
		const warning = missingAccessibleName( {
			blocks: [ heading( content ) ],
			title: '',
		} );

		expect( warning?.id ).toBe( WARNING.MISSING_ACCESSIBLE_NAME );
	} );

	it( 'treats a whitespace-only title as no title', () => {
		const warning = missingAccessibleName( { blocks: [], title: '   ' } );

		expect( warning?.id ).toBe( WARNING.MISSING_ACCESSIBLE_NAME );
	} );

	it( 'survives a missing block tree', () => {
		expect( missingAccessibleName( {} ) ).not.toBeNull();
		expect( missingAccessibleName() ).not.toBeNull();
	} );
} );

describe( 'scheduleExpired', () => {
	const now = Date.parse( '2026-08-18T12:00:00Z' );

	it( 'warns when an enabled schedule ended in the past', () => {
		const warning = scheduleExpired( {
			schedule: { enabled: true, end: '2026-08-01T00:00:00Z' },
			now,
		} );

		expect( warning?.id ).toBe( WARNING.SCHEDULE_EXPIRED );
	} );

	it( 'stays quiet when the end is in the future', () => {
		expect(
			scheduleExpired( {
				schedule: { enabled: true, end: '2027-01-01T00:00:00Z' },
				now,
			} )
		).toBeNull();
	} );

	/*
	 * A disabled schedule is not consulted at display time, so its dates describe
	 * nothing. Warning about them would fire on a popup that shows correctly.
	 */
	it( 'stays quiet when the schedule is disabled', () => {
		expect(
			scheduleExpired( {
				schedule: { enabled: false, end: '2026-08-01T00:00:00Z' },
				now,
			} )
		).toBeNull();
	} );

	it( 'stays quiet on an open-ended schedule', () => {
		expect(
			scheduleExpired( { schedule: { enabled: true, end: null }, now } )
		).toBeNull();
	} );

	/*
	 * An unreadable date must not read as expired. Date.parse() returns NaN and
	 * every comparison against NaN is false, so a version that compared without
	 * checking would warn on it — telling the author their schedule has expired
	 * when what is actually wrong is that the value is not a date.
	 */
	it( 'stays quiet on an unparseable end date', () => {
		expect(
			scheduleExpired( {
				schedule: { enabled: true, end: 'next Tuesday' },
				now,
			} )
		).toBeNull();
	} );

	it( 'does not warn when the current time is unknown', () => {
		expect(
			scheduleExpired( {
				schedule: { enabled: true, end: '2026-08-01T00:00:00Z' },
			} )
		).toBeNull();
	} );

	it( 'warns at the exact moment the schedule ends', () => {
		const warning = scheduleExpired( {
			schedule: { enabled: true, end: '2026-08-18T12:00:00Z' },
			now,
		} );

		expect( warning?.id ).toBe( WARNING.SCHEDULE_EXPIRED );
	} );
} );

describe( 'impossibleConditions', () => {
	const contradiction = [
		{ type: 'is_front_page', negate: false, values: {} },
		{ type: 'is_front_page', negate: true, values: {} },
	];

	/*
	 * An empty rule set means "always match" and is what every new popup carries.
	 * A warning here would greet the author on a blank screen.
	 */
	it( 'stays quiet on an empty rule set', () => {
		expect(
			impossibleConditions( {
				conditions: { groups: [] },
				registry: REGISTRY,
			} )
		).toBeNull();
	} );

	it( 'warns on a group holding a rule and its own negation', () => {
		const warning = impossibleConditions( {
			conditions: ruleSet( contradiction ),
			registry: REGISTRY,
		} );

		expect( warning?.id ).toBe( WARNING.IMPOSSIBLE_CONDITIONS );
	} );

	it( 'compares values regardless of key order', () => {
		const warning = impossibleConditions( {
			conditions: ruleSet( [
				{
					type: 'referrer',
					negate: false,
					values: { match: 'prefix', value: '/a/' },
				},
				{
					type: 'referrer',
					negate: true,
					values: { value: '/a/', match: 'prefix' },
				},
			] ),
			registry: REGISTRY,
		} );

		expect( warning?.id ).toBe( WARNING.IMPOSSIBLE_CONDITIONS );
	} );

	/*
	 * `negate` is optional and defaults to false, so a stored rule that omits it
	 * must still pair with an explicitly negated twin. Comparing the raw values
	 * would make `undefined !== false` true and miss the pair.
	 */
	it( 'treats an omitted negate as false', () => {
		const warning = impossibleConditions( {
			conditions: ruleSet( [
				{ type: 'is_front_page', values: {} },
				{ type: 'is_front_page', negate: true, values: {} },
			] ),
			registry: REGISTRY,
		} );

		expect( warning?.id ).toBe( WARNING.IMPOSSIBLE_CONDITIONS );
	} );

	it( 'warns on a group demanding two values of one enum field', () => {
		const warning = impossibleConditions( {
			conditions: ruleSet( [
				{
					type: 'user_state',
					negate: false,
					values: { state: 'logged_in' },
				},
				{
					type: 'user_state',
					negate: false,
					values: { state: 'logged_out' },
				},
			] ),
			registry: REGISTRY,
		} );

		expect( warning?.id ).toBe( WARNING.IMPOSSIBLE_CONDITIONS );
	} );

	/*
	 * Two negated enum rules are unsatisfiable only because this enum happens to
	 * have two members. Deciding that would make the warning depend on a
	 * vocabulary a plugin can extend, so the detector deliberately declines.
	 */
	it( 'declines to judge two negated enum rules', () => {
		expect(
			impossibleConditions( {
				conditions: ruleSet( [
					{
						type: 'user_state',
						negate: true,
						values: { state: 'logged_in' },
					},
					{
						type: 'user_state',
						negate: true,
						values: { state: 'logged_out' },
					},
				] ),
				registry: REGISTRY,
			} )
		).toBeNull();
	} );

	/*
	 * A two-field condition can hold rules that differ in one field and agree in
	 * the other, so "different values" is not proof of anything. Firing here would
	 * call a working popup broken, which is the failure mode that teaches authors
	 * to ignore warnings.
	 */
	it( 'declines to judge a condition with more than one field', () => {
		expect(
			impossibleConditions( {
				conditions: ruleSet( [
					{
						type: 'referrer',
						negate: false,
						values: { match: 'prefix', value: '/a/' },
					},
					{
						type: 'referrer',
						negate: false,
						values: { match: 'prefix', value: '/b/' },
					},
				] ),
				registry: REGISTRY,
			} )
		).toBeNull();
	} );

	/*
	 * Groups are OR'd. One satisfiable group gives the popup an audience, so the
	 * set-level warning must not fire — even though a group in it is dead.
	 */
	it( 'stays quiet when one group of several can still match', () => {
		expect(
			impossibleConditions( {
				conditions: ruleSet( contradiction, [
					{
						type: 'user_state',
						negate: false,
						values: { state: 'logged_in' },
					},
				] ),
				registry: REGISTRY,
			} )
		).toBeNull();
	} );

	it( 'warns only when every group is impossible', () => {
		const warning = impossibleConditions( {
			conditions: ruleSet( contradiction, contradiction ),
			registry: REGISTRY,
		} );

		expect( warning?.id ).toBe( WARNING.IMPOSSIBLE_CONDITIONS );
		expect( warning?.groups ).toEqual( [ 0, 1 ] );
	} );

	it( 'reports the individual dead group even when the set survives', () => {
		expect(
			impossibleGroups(
				ruleSet(
					[
						{
							type: 'user_state',
							negate: false,
							values: { state: 'logged_in' },
						},
					],
					contradiction
				),
				REGISTRY
			)
		).toEqual( [ 1 ] );
	} );
} );

describe( 'unavailableCondition', () => {
	it( 'warns when a stored rule names an unregistered condition', () => {
		const warning = unavailableCondition( {
			conditions: ruleSet( [
				{ type: 'acme_membership_tier', negate: false, values: {} },
			] ),
			registry: REGISTRY,
		} );

		expect( warning?.id ).toBe( WARNING.UNAVAILABLE_CONDITION );
		expect( warning?.types ).toEqual( [ 'acme_membership_tier' ] );
	} );

	it( 'names the type in the message, so the author knows what to reactivate', () => {
		const warning = unavailableCondition( {
			conditions: ruleSet( [
				{ type: 'acme_membership_tier', negate: false, values: {} },
			] ),
			registry: REGISTRY,
		} );

		expect( warning?.message ).toContain( 'acme_membership_tier' );
	} );

	it( 'stays quiet when every stored type is registered', () => {
		expect(
			unavailableCondition( {
				conditions: ruleSet( [
					{ type: 'is_front_page', negate: false, values: {} },
				] ),
				registry: REGISTRY,
			} )
		).toBeNull();
	} );

	it( 'lists each unregistered type once, in first-seen order', () => {
		expect(
			unavailableTypes(
				ruleSet(
					[
						{ type: 'zeta_rule', values: {} },
						{ type: 'alpha_rule', values: {} },
					],
					[ { type: 'zeta_rule', values: {} } ]
				),
				REGISTRY
			)
		).toEqual( [ 'zeta_rule', 'alpha_rule' ] );
	} );

	/*
	 * A registry that has not arrived yet is not evidence that every condition is
	 * unavailable. Warning during the fetch would flash the most alarming message
	 * in the editor on every page load of a correctly configured popup.
	 */
	it( 'does not treat a missing registry as everything being unavailable', () => {
		const conditions = ruleSet( [
			{ type: 'is_front_page', negate: false, values: {} },
		] );

		expect( unavailableTypes( conditions, undefined ) ).toEqual( [
			'is_front_page',
		] );
		expect(
			unavailableCondition( { conditions, registry: { conditions: {} } } )
				?.types
		).toEqual( [ 'is_front_page' ] );
	} );
} );

describe( 'collectWarnings', () => {
	it( 'returns nothing for a well-formed popup', () => {
		expect(
			collectWarnings( {
				blocks: [ heading( 'Join the list' ) ],
				title: 'Newsletter',
				schedule: { enabled: false },
				conditions: { groups: [] },
				registry: REGISTRY,
				now: Date.parse( '2026-08-18T12:00:00Z' ),
			} )
		).toEqual( [] );
	} );

	/*
	 * Order is asserted because it is a decision, not an accident: an unavailable
	 * condition is usually the *cause* of the impossible-set warning beside it, and
	 * an author reading the symptom first will go looking in the wrong place.
	 */
	it( 'reports the cause before the symptom', () => {
		const warnings = collectWarnings( {
			blocks: [],
			title: '',
			schedule: { enabled: true, end: '2026-01-01T00:00:00Z' },
			conditions: ruleSet( [
				{ type: 'acme_gone', values: {} },
				{ type: 'is_front_page', negate: false, values: {} },
				{ type: 'is_front_page', negate: true, values: {} },
			] ),
			registry: REGISTRY,
			now: Date.parse( '2026-08-18T12:00:00Z' ),
		} );

		expect( warnings.map( ( warning ) => warning.id ) ).toEqual( [
			WARNING.UNAVAILABLE_CONDITION,
			WARNING.IMPOSSIBLE_CONDITIONS,
			WARNING.MISSING_ACCESSIBLE_NAME,
			WARNING.SCHEDULE_EXPIRED,
		] );
	} );
} );
