/**
 * popkit — the triggers panel.
 *
 * Triggers are OR'd: the popup opens when any configured trigger fires, and the
 * first one to fire wins. A popup with no trigger never opens, which the panel
 * says rather than leaving the author to discover it on the front end.
 *
 * The same registry drives this panel and the conditions panel, through the same
 * control map, so a trigger registered by a plugin gets working UI on the same
 * terms a condition does. Unlike conditions there is no unavailable-trigger
 * warning: an unregistered trigger simply never fires, which is the same outcome
 * as having no trigger and is not the silent audience-narrowing failure an
 * unresolvable *condition* causes. It is still shown read-only rather than
 * dropped, for the same preservation reason.
 *
 * A stored trigger keys its settings under `values`, matching the rule shape and
 * the REST schema in `Popkit\Meta`. It is not `config`, which is what the word
 * "trigger config" in the build plan reads as and is worth stating once here so
 * the next reader does not have to check.
 *
 * @jsxRuntime classic
 * @jsx createElement
 * @jsxFrag Fragment
 *
 * @see src/editor/controls.js -> Why this file pins the classic JSX runtime
 * @see docs/data-model.md -> Triggers
 */

/* eslint-disable no-unused-vars -- createElement and Fragment are the JSX pragma targets. */
import { createElement, Fragment } from '@wordpress/element';
/* eslint-enable no-unused-vars */

import { Button, Notice, SelectControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { FieldControl } from '../controls.js';
import { defaultValues } from '../registry.js';

/**
 * The triggers panel body.
 *
 * @param {Object}   props          Props.
 * @param {Array}    props.triggers Stored trigger configs.
 * @param {Function} props.onChange Called with the updated list.
 * @param {Object}   props.registry Registry payload.
 * @return {JSX.Element} Panel body.
 */
export function TriggersPanel( { triggers, onChange, registry } ) {
	const stored = Array.isArray( triggers ) ? triggers : [];
	const available = Object.values( registry?.triggers ?? {} );
	const firstType = available[ 0 ]?.key;

	if ( ! firstType ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ __(
					'No triggers are registered on this site, so this popup cannot be opened. Any triggers already saved are left untouched.',
					'popkit'
				) }
			</Notice>
		);
	}

	/**
	 * Replaces one stored trigger, leaving the others untouched.
	 *
	 * @param {number}      index Trigger index.
	 * @param {Object|null} next  Replacement trigger, or null to remove it.
	 * @return {void}
	 */
	const replace = ( index, next ) =>
		onChange(
			null === next
				? stored.filter( ( _trigger, at ) => at !== index )
				: stored.map( ( trigger, at ) =>
						at === index ? next : trigger
				  )
		);

	return (
		<div className="popkit-triggers">
			{ 0 === stored.length && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This popup has no trigger, so it will never open. Add one below.',
						'popkit'
					) }
				</Notice>
			) }

			{ stored.map( ( trigger, index ) => {
				const entry = registry?.triggers?.[ trigger?.type ];

				if ( ! entry ) {
					return (
						<div
							key={ index }
							className="popkit-trigger popkit-trigger--unavailable"
						>
							<Notice status="warning" isDismissible={ false }>
								{ sprintf(
									/* translators: %s: the stored trigger type key. */
									__(
										'“%s” is not available on this site and will never fire. Its settings are shown exactly as stored and are preserved when you save.',
										'popkit'
									),
									String( trigger?.type ?? '' )
								) }
							</Notice>
							<pre className="popkit-trigger__raw" tabIndex={ 0 }>
								{ JSON.stringify(
									trigger?.values ?? {},
									null,
									2
								) }
							</pre>
							<Button
								variant="link"
								isDestructive
								__next40pxDefaultSize
								onClick={ () => replace( index, null ) }
							>
								{ __( 'Remove this trigger', 'popkit' ) }
							</Button>
						</div>
					);
				}

				return (
					<fieldset key={ index } className="popkit-trigger">
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Trigger', 'popkit' ) }
							value={ trigger.type }
							options={ available.map( ( entryOption ) => ( {
								label: entryOption.label,
								value: entryOption.key,
							} ) ) }
							onChange={ ( type ) =>
								replace( index, {
									...trigger,
									type,
									// Field names differ between triggers, so
									// carrying values across a type change would
									// store a shape the sanitizer strips on save.
									values: defaultValues(
										registry.triggers[ type ]
									),
								} )
							}
						/>

						{ Object.entries( entry.fields ?? {} ).map(
							( [ name, schema ] ) => (
								<FieldControl
									key={ name }
									schema={ schema }
									value={ trigger.values?.[ name ] }
									siblings={ trigger.values ?? {} }
									onChange={ ( next ) =>
										replace( index, {
											...trigger,
											values: {
												...trigger.values,
												[ name ]: next,
											},
										} )
									}
								/>
							)
						) }

						<Button
							variant="link"
							isDestructive
							__next40pxDefaultSize
							onClick={ () => replace( index, null ) }
						>
							{ __( 'Remove this trigger', 'popkit' ) }
						</Button>
					</fieldset>
				);
			} ) }

			<Button
				variant="primary"
				__next40pxDefaultSize
				onClick={ () =>
					onChange( [
						...stored,
						{
							type: firstType,
							values: defaultValues(
								registry.triggers[ firstType ]
							),
						},
					] )
				}
			>
				{ __( 'Add trigger', 'popkit' ) }
			</Button>

			{ 1 < stored.length && (
				<p className="popkit-triggers__note">
					{ __(
						'The popup opens when any one trigger fires.',
						'popkit'
					) }
				</p>
			) }
		</div>
	);
}
