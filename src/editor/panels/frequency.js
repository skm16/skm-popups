/**
 * popkit — the frequency panel.
 *
 * How often one visitor may see this popup, and what a conversion does to that.
 *
 * ## `days` is shown only where it means something
 *
 * The stored object always carries `days`, because `Meta::default_frequency()`
 * gives it a value whatever the mode is. The control for it appears only under
 * `once_per_days`, which is the only mode that reads it. Showing it always would
 * present a number that changes nothing — and an author who set it under
 * `always` and saw no effect would reasonably conclude the setting is broken.
 *
 * The stored value is left alone when the mode changes away. Clearing it would
 * lose the author's number if they switch modes and switch back, and the
 * sanitizer ignores it in every other mode anyway.
 *
 * @jsxRuntime classic
 * @jsx createElement
 * @jsxFrag Fragment
 *
 * @see src/editor/controls.js -> Why this file pins the classic JSX runtime
 * @see docs/data-model.md -> Frequency
 */

/* eslint-disable no-unused-vars -- createElement and Fragment are the JSX pragma targets. */
import { createElement, Fragment } from '@wordpress/element';
/* eslint-enable no-unused-vars */

import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { FieldControl } from '../controls.js';

/**
 * Frequency modes, matching `Meta::FREQUENCY_MODES`.
 *
 * @type {Array<{value: string, label: string}>}
 */
const MODES = [
	{ value: 'always', label: __( 'Every time it is eligible', 'popkit' ) },
	{ value: 'once_per_session', label: __( 'Once per session', 'popkit' ) },
	{
		value: 'once_per_days',
		label: __( 'Once every so many days', 'popkit' ),
	},
	{ value: 'once_ever', label: __( 'Once, ever', 'popkit' ) },
];

/**
 * What a conversion does, matching `Meta::FREQUENCY_ON_CONVERT`.
 *
 * @type {Array<{value: string, label: string}>}
 */
const ON_CONVERT = [
	{
		value: 'suppress_forever',
		label: __( 'Never show it to them again', 'popkit' ),
	},
	{
		value: 'none',
		label: __( 'Keep showing it under the rule above', 'popkit' ),
	},
];

/**
 * Field schema for the day-count control.
 *
 * Bounds match `Meta::MIN_FREQUENCY_DAYS` and `Meta::MAX_FREQUENCY_DAYS`, so the
 * control stops at the same place the sanitizer does.
 *
 * @type {Object}
 */
const DAYS_SCHEMA = {
	type: 'integer',
	control: 'number',
	min: 1,
	max: 365,
	label: __( 'Days between showings', 'popkit' ),
};

/**
 * The frequency panel body.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.frequency Stored frequency.
 * @param {Function} props.onChange  Called with the updated frequency.
 * @return {JSX.Element} Panel body.
 */
export function FrequencyPanel( { frequency, onChange } ) {
	const mode = frequency?.mode ?? 'always';

	return (
		<div className="popkit-frequency">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Show this popup', 'popkit' ) }
				value={ mode }
				options={ MODES }
				onChange={ ( next ) =>
					onChange( { ...frequency, mode: next } )
				}
			/>

			{ 'once_per_days' === mode && (
				<FieldControl
					schema={ DAYS_SCHEMA }
					value={ frequency?.days }
					onChange={ ( days ) => onChange( { ...frequency, days } ) }
				/>
			) }

			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'After someone converts', 'popkit' ) }
				help={ __(
					'A conversion is recorded when the visitor completes the popup’s purpose rather than merely closing it.',
					'popkit'
				) }
				value={ frequency?.on_convert ?? 'suppress_forever' }
				options={ ON_CONVERT }
				onChange={ ( next ) =>
					onChange( { ...frequency, on_convert: next } )
				}
			/>
		</div>
	);
}
