/**
 * popkit — the targeting panel.
 *
 * Groups are OR'd and rules inside a group are AND'd, and the panel says so in
 * words between the groups rather than relying on the author inferring it from
 * layout. An empty rule set means "show everywhere", which is what a new popup
 * carries.
 *
 * ## An unregistered rule is shown, not hidden and not repaired
 *
 * A stored rule whose type this build has no registration for renders read-only,
 * with its raw values visible and an explanation of what will happen. It is
 * emphatically not dropped, not silently migrated, and not given editable
 * controls guessed from its shape.
 *
 * The reason is the fail-closed asymmetry in `docs/data-model.md`: the client
 * denies a rule it cannot evaluate and does not apply `negate`, so a popup with
 * an unresolvable rule shows to nobody. If this panel offered a control for such
 * a rule, the first author to open the editor after deactivating a plugin would
 * save the popup and overwrite targeting they cannot see — and the values are
 * unrecoverable once written, because nothing else stores them.
 *
 * Preserving them costs one branch here and means reactivating the plugin
 * restores the popup exactly. That is the whole reason `Sanitizer` keeps unknown
 * rule values within structural bounds instead of discarding them.
 *
 * @jsxRuntime classic
 * @jsx createElement
 * @jsxFrag Fragment
 *
 * @see src/editor/controls.js -> Why this file pins the classic JSX runtime
 * @see docs/data-model.md -> Rule set
 */

import { createElement, Fragment } from '@wordpress/element';

import {
	Button,
	Notice,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { __, _x, sprintf } from '@wordpress/i18n';

import { FieldControl } from '../controls.js';
import { conditionsByGroup, defaultValues } from '../registry.js';
import { impossibleGroups } from '../warnings.js';

/**
 * Human-readable name for a registry group key.
 *
 * The registry declares group keys, not labels, so the two popkit ships are
 * named here. An unrecognised group — one a plugin introduced — falls back to
 * its own key, which is worse than a translated label and much better than
 * dropping the conditions in it on the floor.
 *
 * @param {string} group Registry group key.
 * @return {string} Label.
 */
function groupLabel( group ) {
	if ( 'content' === group ) {
		return __( 'Content', 'popkit' );
	}

	if ( 'visitor' === group ) {
		return __( 'Visitor', 'popkit' );
	}

	return group;
}

/**
 * The rule type chooser, grouped the way the registry declares.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.registry Registry payload.
 * @param {string}   props.value    Currently selected type.
 * @param {Function} props.onChange Called with the new type.
 * @return {JSX.Element} Control.
 */
function TypeSelect( { registry, value, onChange } ) {
	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Condition', 'popkit' ) }
			value={ value }
			onChange={ onChange }
		>
			{ conditionsByGroup( registry ).map( ( { group, conditions } ) => (
				<optgroup key={ group } label={ groupLabel( group ) }>
					{ conditions.map( ( condition ) => (
						<option key={ condition.key } value={ condition.key }>
							{ condition.label }
						</option>
					) ) }
				</optgroup>
			) ) }
		</SelectControl>
	);
}

/**
 * A stored rule this build cannot edit.
 *
 * Renders the values as formatted JSON in a read-only block. JSON rather than a
 * prose summary because the values belong to a vocabulary this build does not
 * know: any friendlier rendering would be a guess about their meaning, and a
 * wrong guess about targeting an author cannot otherwise inspect is worse than
 * showing them exactly what is stored.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.rule     Stored rule.
 * @param {Function} props.onRemove Called when the author removes the rule.
 * @return {JSX.Element} Rule row.
 */
function UnavailableRule( { rule, onRemove } ) {
	return (
		<div className="popkit-rule popkit-rule--unavailable">
			<Notice status="warning" isDismissible={ false }>
				{ sprintf(
					/* translators: %s: the stored condition type key. */
					__(
						'“%s” is not available on this site. This rule cannot be evaluated, so the popup will not display to anyone while it stays unresolvable. Its settings are shown below exactly as stored and are preserved when you save.',
						'popkit'
					),
					String( rule?.type ?? '' )
				) }
			</Notice>

			<p className="popkit-rule__negate">
				{ rule?.negate
					? __( 'Stored as: does not match', 'popkit' )
					: __( 'Stored as: matches', 'popkit' ) }
			</p>

			<pre className="popkit-rule__raw" tabIndex={ 0 }>
				{ JSON.stringify( rule?.values ?? {}, null, 2 ) }
			</pre>

			<Button
				variant="link"
				isDestructive
				onClick={ onRemove }
				__next40pxDefaultSize
			>
				{ __( 'Remove this rule', 'popkit' ) }
			</Button>
		</div>
	);
}

/**
 * One editable rule.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.rule     Stored rule.
 * @param {Object}   props.registry Registry payload.
 * @param {Function} props.onChange Called with the updated rule.
 * @param {Function} props.onRemove Called when the author removes the rule.
 * @return {JSX.Element} Rule row.
 */
function Rule( { rule, registry, onChange, onRemove } ) {
	const entry = registry?.conditions?.[ rule?.type ];

	if ( ! entry ) {
		return <UnavailableRule rule={ rule } onRemove={ onRemove } />;
	}

	const fields = Object.entries( entry.fields ?? {} );

	return (
		<div className="popkit-rule">
			<TypeSelect
				registry={ registry }
				value={ rule.type }
				onChange={ ( type ) =>
					onChange( {
						...rule,
						type,
						// The new condition's fields are unrelated to the old
						// one's, so carrying the values over would store a shape
						// the sanitizer strips on save — leaving the author
						// looking at values that will not survive.
						values: defaultValues( registry?.conditions?.[ type ] ),
					} )
				}
			/>

			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Invert this rule', 'popkit' ) }
				help={
					rule.negate
						? __( 'Matches when the condition is false.', 'popkit' )
						: __( 'Matches when the condition is true.', 'popkit' )
				}
				checked={ Boolean( rule.negate ) }
				onChange={ ( negate ) => onChange( { ...rule, negate } ) }
			/>

			{ fields.map( ( [ name, schema ] ) => (
				<FieldControl
					key={ name }
					schema={ schema }
					value={ rule.values?.[ name ] }
					siblings={ rule.values ?? {} }
					onChange={ ( next ) =>
						onChange( {
							...rule,
							values: { ...rule.values, [ name ]: next },
						} )
					}
				/>
			) ) }

			<Button
				variant="link"
				isDestructive
				onClick={ onRemove }
				__next40pxDefaultSize
			>
				{ __( 'Remove this rule', 'popkit' ) }
			</Button>
		</div>
	);
}

/**
 * The targeting panel body.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.conditions Stored rule set.
 * @param {Function} props.onChange   Called with the updated rule set.
 * @param {Object}   props.registry   Registry payload.
 * @return {JSX.Element} Panel body.
 */
export function ConditionsPanel( { conditions, onChange, registry } ) {
	const groups = Array.isArray( conditions?.groups ) ? conditions.groups : [];
	const dead = impossibleGroups( conditions, registry );

	const firstType = Object.keys( registry?.conditions ?? {} )[ 0 ];

	/**
	 * Replaces one group, leaving the others untouched.
	 *
	 * @param {number}      index Group index.
	 * @param {Object|null} next  Replacement group, or null to remove it.
	 * @return {void}
	 */
	const replaceGroup = ( index, next ) =>
		onChange( {
			...conditions,
			groups:
				null === next
					? groups.filter( ( _group, at ) => at !== index )
					: groups.map( ( group, at ) =>
							at === index ? next : group
					  ),
		} );

	if ( ! firstType ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'No conditions are registered on this site, so targeting cannot be edited. Any rules already saved are left untouched.',
					'popkit'
				) }
			</Notice>
		);
	}

	return (
		<div className="popkit-conditions">
			{ 0 === groups.length && (
				<p className="popkit-conditions__empty">
					{ __(
						'No targeting rules. This popup is eligible on every page.',
						'popkit'
					) }
				</p>
			) }

			{ groups.map( ( group, index ) => {
				const rules = Array.isArray( group?.rules ) ? group.rules : [];

				return (
					<fieldset
						key={ index }
						className="popkit-group"
						aria-label={ sprintf(
							/* translators: %d: the group's position in the list, starting at 1. */
							__( 'Targeting group %d', 'popkit' ),
							index + 1
						) }
					>
						{ dead.includes( index ) && (
							<Notice status="warning" isDismissible={ false }>
								{ __(
									'These rules contradict each other, so this group can never match. Rules in a group must all be true at once; put alternatives in separate groups.',
									'popkit'
								) }
							</Notice>
						) }

						{ rules.map( ( rule, ruleIndex ) => (
							<Fragment key={ ruleIndex }>
								{ 0 < ruleIndex && (
									<p className="popkit-group__joiner">
										{ _x(
											'and',
											'joins two targeting rules that must both match',
											'popkit'
										) }
									</p>
								) }
								<Rule
									rule={ rule }
									registry={ registry }
									onChange={ ( next ) =>
										replaceGroup( index, {
											...group,
											rules: rules.map( ( item, at ) =>
												at === ruleIndex ? next : item
											),
										} )
									}
									onRemove={ () =>
										replaceGroup(
											index,
											1 === rules.length
												? null
												: {
														...group,
														rules: rules.filter(
															( _item, at ) =>
																at !== ruleIndex
														),
												  }
										)
									}
								/>
							</Fragment>
						) ) }

						<Button
							variant="secondary"
							__next40pxDefaultSize
							onClick={ () =>
								replaceGroup( index, {
									...group,
									rules: [
										...rules,
										{
											type: firstType,
											negate: false,
											values: defaultValues(
												registry.conditions[ firstType ]
											),
										},
									],
								} )
							}
						>
							{ __( 'Add rule', 'popkit' ) }
						</Button>
					</fieldset>
				);
			} ) }

			{ 1 < groups.length && (
				<p className="popkit-conditions__note">
					{ __(
						'The popup shows when any one group matches.',
						'popkit'
					) }
				</p>
			) }

			<Button
				variant="primary"
				__next40pxDefaultSize
				onClick={ () =>
					onChange( {
						...conditions,
						groups: [
							...groups,
							{
								rules: [
									{
										type: firstType,
										negate: false,
										values: defaultValues(
											registry.conditions[ firstType ]
										),
									},
								],
							},
						],
					} )
				}
			>
				{ __( 'Add group', 'popkit' ) }
			</Button>
		</div>
	);
}
