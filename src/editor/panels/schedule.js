/**
 * popkit — the schedule panel.
 *
 * A schedule has three independent parts and the panel keeps them visually
 * separate because they answer different questions: a date range says *when the
 * campaign runs*, weekdays and time windows say *when within that range it is
 * active*, and the timezone says *whose clock decides*.
 *
 * ## The midnight-crossing note is not decoration
 *
 * A window whose `to` is earlier than its `from` crosses midnight, and belongs
 * to its **starting** day. `22:00`–`02:00` on Monday runs until Tuesday 01:59
 * and does *not* make Tuesday's own small hours active. That is the single most
 * surprising rule in `docs/data-model.md`, it is invisible in the stored value,
 * and an author who guesses wrong writes a schedule that is silently wrong for
 * two hours a day. The panel states it beside the control that causes it.
 *
 * ## Dates are stored UTC and edited locally
 *
 * The `date-time` control handles the conversion — see `DateTimeField` in
 * `src/editor/controls.js`. Nothing here touches the format, which is why the
 * panel does not have its own opinion about what a valid date looks like.
 *
 * @jsxRuntime classic
 * @jsx createElement
 * @jsxFrag Fragment
 *
 * @see src/editor/controls.js -> Why this file pins the classic JSX runtime
 * @see docs/data-model.md -> Schedule
 */

import { createElement, Fragment } from '@wordpress/element';

import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { FieldControl } from '../controls.js';

/**
 * ISO 8601 weekdays, in the order they are shown.
 *
 * Monday is 1, matching `docs/data-model.md` and PHP's `N` format. Starting the
 * list at Monday rather than at the site's `start_of_week` is deliberate: the
 * stored numbers are ISO weekdays whatever the site's display preference, and a
 * reordered list would put a checkbox labelled "Sunday" first while storing 7.
 *
 * @type {Array<{value: number, label: string}>}
 */
const WEEKDAYS = [
	{ value: 1, label: __( 'Monday', 'popkit' ) },
	{ value: 2, label: __( 'Tuesday', 'popkit' ) },
	{ value: 3, label: __( 'Wednesday', 'popkit' ) },
	{ value: 4, label: __( 'Thursday', 'popkit' ) },
	{ value: 5, label: __( 'Friday', 'popkit' ) },
	{ value: 6, label: __( 'Saturday', 'popkit' ) },
	{ value: 7, label: __( 'Sunday', 'popkit' ) },
];

/**
 * Field schema for the two date controls.
 *
 * Written here rather than fetched, because `start` and `end` are popkit's own
 * meta rather than a registered condition's fields — there is no registry entry
 * to read them from. The shape matches what {@link FieldControl} expects, so the
 * same control renders them.
 *
 * @type {Object}
 */
const DATE_SCHEMA = { type: 'string', control: 'date-time' };

/**
 * The schedule panel body.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.schedule Stored schedule.
 * @param {Function} props.onChange Called with the updated schedule.
 * @return {JSX.Element} Panel body.
 */
export function SchedulePanel( { schedule, onChange } ) {
	const recurrence = schedule?.recurrence ?? { days: [], windows: [] };
	const days = Array.isArray( recurrence.days ) ? recurrence.days : [];
	const windows = Array.isArray( recurrence.windows )
		? recurrence.windows
		: [];

	/**
	 * Updates the recurrence object without disturbing the rest of the schedule.
	 *
	 * @param {Object} next Partial recurrence.
	 * @return {void}
	 */
	const setRecurrence = ( next ) =>
		onChange( {
			...schedule,
			recurrence: { ...recurrence, ...next },
		} );

	return (
		<div className="popkit-schedule">
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Limit when this popup runs', 'popkit' ) }
				help={ __(
					'When off, the popup is eligible at any time and the settings below are ignored.',
					'popkit'
				) }
				checked={ Boolean( schedule?.enabled ) }
				onChange={ ( enabled ) => onChange( { ...schedule, enabled } ) }
			/>

			{ ! schedule?.enabled ? null : (
				<Fragment>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Whose clock decides', 'popkit' ) }
						value={ schedule?.timezone ?? 'site' }
						options={ [
							{
								label: __( 'The site’s timezone', 'popkit' ),
								value: 'site',
							},
							{
								label: __(
									'The visitor’s own timezone',
									'popkit'
								),
								value: 'visitor',
							},
						] }
						onChange={ ( timezone ) =>
							onChange( { ...schedule, timezone } )
						}
					/>

					<FieldControl
						schema={ {
							...DATE_SCHEMA,
							label: __( 'Starts', 'popkit' ),
						} }
						value={ schedule?.start }
						onChange={ ( start ) =>
							onChange( { ...schedule, start } )
						}
					/>

					<Button
						variant="link"
						__next40pxDefaultSize
						onClick={ () =>
							onChange( { ...schedule, start: null } )
						}
					>
						{ __( 'Clear start date', 'popkit' ) }
					</Button>

					<FieldControl
						schema={ {
							...DATE_SCHEMA,
							label: __( 'Ends', 'popkit' ),
						} }
						value={ schedule?.end }
						onChange={ ( end ) => onChange( { ...schedule, end } ) }
					/>

					<Button
						variant="link"
						__next40pxDefaultSize
						onClick={ () => onChange( { ...schedule, end: null } ) }
					>
						{ __( 'Clear end date', 'popkit' ) }
					</Button>

					<fieldset className="popkit-schedule__days">
						<legend>{ __( 'Days of the week', 'popkit' ) }</legend>
						<p className="popkit-schedule__hint">
							{ __(
								'Leave every day unchecked to run on all of them.',
								'popkit'
							) }
						</p>
						{ WEEKDAYS.map( ( day ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ day.value }
								label={ day.label }
								checked={ days.includes( day.value ) }
								onChange={ ( checked ) =>
									setRecurrence( {
										days: checked
											? [ ...days, day.value ].sort(
													( a, b ) => a - b
											  )
											: days.filter(
													( value ) =>
														value !== day.value
											  ),
									} )
								}
							/>
						) ) }
					</fieldset>

					<fieldset className="popkit-schedule__windows">
						<legend>{ __( 'Times of day', 'popkit' ) }</legend>
						<p className="popkit-schedule__hint">
							{ __(
								'Leave empty to run all day. A window whose end is earlier than its start crosses midnight and belongs to the day it began on — 22:00 to 02:00 on Monday runs until Tuesday at 01:59, and does not make Tuesday’s own early hours active.',
								'popkit'
							) }
						</p>

						{ windows.map( ( window, index ) => (
							<div
								key={ index }
								className="popkit-schedule__window"
							>
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									type="time"
									label={ __( 'From', 'popkit' ) }
									value={ window?.from ?? '' }
									onChange={ ( from ) =>
										setRecurrence( {
											windows: windows.map(
												( item, at ) =>
													at === index
														? { ...item, from }
														: item
											),
										} )
									}
								/>
								<TextControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									type="time"
									label={ __( 'To', 'popkit' ) }
									value={ window?.to ?? '' }
									onChange={ ( to ) =>
										setRecurrence( {
											windows: windows.map(
												( item, at ) =>
													at === index
														? { ...item, to }
														: item
											),
										} )
									}
								/>
								{ window?.from &&
									window?.to &&
									window.to < window.from && (
										<Notice
											status="info"
											isDismissible={ false }
										>
											{ __(
												'This window crosses midnight and belongs to the day it starts on.',
												'popkit'
											) }
										</Notice>
									) }
								<Button
									variant="link"
									isDestructive
									__next40pxDefaultSize
									onClick={ () =>
										setRecurrence( {
											windows: windows.filter(
												( _item, at ) => at !== index
											),
										} )
									}
								>
									{ __( 'Remove window', 'popkit' ) }
								</Button>
							</div>
						) ) }

						<Button
							variant="secondary"
							__next40pxDefaultSize
							onClick={ () =>
								setRecurrence( {
									windows: [
										...windows,
										{ from: '09:00', to: '17:00' },
									],
								} )
							}
						>
							{ __( 'Add time window', 'popkit' ) }
						</Button>
					</fieldset>
				</Fragment>
			) }
		</div>
	);
}
