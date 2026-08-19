/**
 * popkit — the appearance panel.
 *
 * Layout, theme, size, position, overlay, animation, and the per-popup color
 * and scale overrides.
 *
 * ## Nothing here names a value or a label
 *
 * Every option in this panel is read from `registry.vocabulary`, which is
 * `Popkit\Vocabulary` serialized. This file used to carry its own copies —
 * `{ value: 'banner', label: 'Inline banner' }` and so on — and they were wrong
 * in the ordinary way copies go wrong: the classic editor, which renders the same
 * settings from PHP, called the same stored value a "Notification bar", and the
 * position `center` was spelled "Centered" on one screen while the rest of the
 * plugin used US spellings.
 *
 * So the labels moved to PHP and travel with the registry. The order of the
 * options travels with them — see {@link labelOptions} — so adding a theme is one
 * edit in one file and both editors offer it.
 *
 * ## Position depends on layout
 *
 * A modal sits `center` or `top`; a notification bar sits `top`, `bottom` or
 * `lower_third`. The two vocabularies overlap on `top` and differ everywhere
 * else, so the options are rebuilt when the layout changes and a position the new
 * layout cannot express is replaced with that layout's first. Leaving `center`
 * selected on a bar would store a value `Meta::sanitize_display()` rejects, and
 * the author would see their choice silently become something else on save.
 *
 * ## Overlay settings are modal-only
 *
 * `overlay` is ignored for a bar, and `close_on_overlay_click` means nothing
 * without an overlay. Both are hidden rather than disabled where they do not
 * apply — a disabled control invites the author to work out how to enable it,
 * and here there is nothing to work out.
 *
 * ## There is no close-button setting
 *
 * Every popup renders a visible close button in every layout and theme. See
 * `docs/data-model.md` -> Display: the field was removed because an overlay
 * click is not a dismissal affordance, and its absence here is the point rather
 * than an omission.
 *
 * @jsxRuntime classic
 * @jsx createElement
 * @jsxFrag Fragment
 *
 * @see src/editor/controls.js -> Why this file pins the classic JSX runtime
 * @see includes/class-vocabulary.php
 * @see docs/data-model.md -> Display
 */

import { createElement, Fragment } from '@wordpress/element';

import { SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { FieldControl } from '../controls.js';
import { labelOptions } from '../registry.js';

/**
 * Field labels for the non-color overrides, matching `Meta::DISPLAY_SCALE_FIELDS`.
 *
 * The names of the *fields* are here; the names of their *values* come from the
 * registry. A field label describes a control this panel chose to render in this
 * order, which is a decision belonging to this file — whereas a value label
 * names something stored, which is not.
 *
 * @type {Array<{key: string, label: string}>}
 */
const SCALES = [
	{ key: 'custom_border_width', label: __( 'Border width', 'popkit' ) },
	{ key: 'custom_radius', label: __( 'Corner rounding', 'popkit' ) },
	{ key: 'custom_font', label: __( 'Font', 'popkit' ) },
	{ key: 'custom_font_size', label: __( 'Text size', 'popkit' ) },
];

/**
 * Field schema for one color override.
 *
 * Built per field rather than declared once, because the label differs and
 * {@link FieldControl} reads it from the schema.
 *
 * @param {string} label Field label.
 * @return {Object} Field schema.
 */
function colorSchema( label ) {
	return {
		type: 'string',
		control: 'color',
		max_length: 7,
		label,
	};
}

/**
 * The appearance panel body.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.display    Stored display object.
 * @param {Function} props.onChange   Called with the updated display object.
 * @param {Object}   props.vocabulary Label maps from the registry.
 * @return {JSX.Element} Panel body.
 */
export function DisplayPanel( { display, onChange, vocabulary } ) {
	const layout = 'banner' === display?.layout ? 'banner' : 'modal';
	const positions = labelOptions( vocabulary?.positions?.[ layout ] );
	const isModal = 'modal' === layout;
	const colors = vocabulary?.colors ?? {};

	return (
		<div className="popkit-display">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Layout', 'popkit' ) }
				value={ layout }
				options={ labelOptions( vocabulary?.layouts ) }
				onChange={ ( next ) => {
					const allowed = labelOptions(
						vocabulary?.positions?.[ next ]
					).map( ( option ) => option.value );

					onChange( {
						...display,
						layout: next,
						position: allowed.includes( display?.position )
							? display.position
							: allowed[ 0 ],
					} );
				} }
			/>

			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Theme', 'popkit' ) }
				value={ display?.theme ?? 'inherit' }
				options={ labelOptions( vocabulary?.themes ) }
				onChange={ ( theme ) => onChange( { ...display, theme } ) }
			/>

			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Size', 'popkit' ) }
				value={ display?.size ?? 'medium' }
				options={ labelOptions( vocabulary?.sizes ) }
				onChange={ ( size ) => onChange( { ...display, size } ) }
			/>

			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Position', 'popkit' ) }
				value={ display?.position ?? positions[ 0 ]?.value }
				options={ positions }
				onChange={ ( position ) =>
					onChange( { ...display, position } )
				}
			/>

			{ isModal && (
				<Fragment>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Dim the page behind it', 'popkit' ) }
						checked={ Boolean( display?.overlay ) }
						onChange={ ( overlay ) =>
							onChange( {
								...display,
								overlay,
								// Overlay-click dismissal cannot outlive the
								// overlay it depends on.
								close_on_overlay_click: overlay
									? display?.close_on_overlay_click
									: false,
							} )
						}
					/>

					{ display?.overlay && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Close when the dimmed area is clicked',
								'popkit'
							) }
							help={ __(
								'An extra way to dismiss the popup. The close button is always shown regardless.',
								'popkit'
							) }
							checked={ Boolean(
								display?.close_on_overlay_click
							) }
							onChange={ ( closeOnOverlayClick ) =>
								onChange( {
									...display,
									close_on_overlay_click: closeOnOverlayClick,
								} )
							}
						/>
					) }
				</Fragment>
			) }

			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Animation', 'popkit' ) }
				help={ __(
					'Any animation becomes none for visitors who ask for reduced motion.',
					'popkit'
				) }
				value={ display?.animation ?? 'fade' }
				options={ labelOptions( vocabulary?.animations ) }
				onChange={ ( animation ) =>
					onChange( { ...display, animation } )
				}
			/>

			<p className="popkit-panel__note">
				{ __(
					'Leave a color unset to keep the theme’s own.',
					'popkit'
				) }
			</p>

			{ Object.entries( colors ).map( ( [ key, label ] ) => (
				<FieldControl
					key={ key }
					schema={ colorSchema( String( label ) ) }
					value={ display?.[ key ] ?? '' }
					onChange={ ( value ) =>
						onChange( { ...display, [ key ]: value } )
					}
				/>
			) ) }

			{ SCALES.map( ( { key, label } ) => (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					key={ key }
					label={ label }
					value={ display?.[ key ] ?? 'inherit' }
					options={ labelOptions( vocabulary?.scales?.[ key ] ) }
					onChange={ ( value ) =>
						onChange( { ...display, [ key ]: value } )
					}
				/>
			) ) }

			<p className="popkit-panel__note">
				{ __(
					'Check the contrast between your background and text colors before publishing. The shipped themes are measured against WCAG AA; a pair you choose is not.',
					'popkit'
				) }
			</p>
		</div>
	);
}
