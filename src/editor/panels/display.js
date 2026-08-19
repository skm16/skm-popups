/**
 * popkit — the appearance panel.
 *
 * Layout, theme, size, position, overlay and animation.
 *
 * ## Position depends on layout
 *
 * A modal sits `center` or `top`; a banner sits `top` or `bottom`. The two
 * vocabularies overlap on `top` and differ everywhere else, so the options are
 * rebuilt when the layout changes and a position the new layout cannot express
 * is replaced with that layout's default. Leaving `center` selected on a banner
 * would store a value `Sanitizer::position_field()` rejects, and the author
 * would see their choice silently become something else on save.
 *
 * ## Overlay settings are modal-only
 *
 * `overlay` is ignored for a banner, and `close_on_overlay_click` means nothing
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
 * @see docs/data-model.md -> Display
 */

import { createElement, Fragment } from '@wordpress/element';

import {
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Per-popup colour overrides, matching `Meta::DISPLAY_COLOR_FIELDS`.
 *
 * Plain text inputs rather than `ColorPalette` or `ColorPicker`, and that is a
 * deliberate trade rather than a shortcut. Those components have no empty state
 * — they report a colour as soon as they are touched — so an author who opened
 * one and changed their mind would have silently set a colour. Blank has to stay
 * expressible, because blank is what "keep the theme" means, and the theme is
 * the value whose contrast has actually been measured.
 *
 * @type {Array<{key: string, label: string}>}
 */
const COLORS = [
	{ key: 'custom_background', label: __( 'Background colour', 'popkit' ) },
	{ key: 'custom_text', label: __( 'Text colour', 'popkit' ) },
	{ key: 'custom_accent', label: __( 'Link colour', 'popkit' ) },
	{ key: 'custom_border_color', label: __( 'Border colour', 'popkit' ) },
];

/**
 * Non-colour overrides, matching `Meta::DISPLAY_SCALE_FIELDS`.
 *
 * Steps on a scale rather than measurements, so nothing an author picks is ever
 * parsed as CSS and the stylesheet keeps deciding what a step looks like.
 *
 * @type {Array<{key: string, label: string, options: Array<{value: string, label: string}>}>}
 */
const SCALES = [
	{
		key: 'custom_border_width',
		label: __( 'Border width', 'popkit' ),
		options: [
			{ value: 'inherit', label: __( 'Theme default', 'popkit' ) },
			{ value: 'none', label: __( 'None', 'popkit' ) },
			{ value: 'thin', label: __( 'Thin', 'popkit' ) },
			{ value: 'medium', label: __( 'Medium', 'popkit' ) },
			{ value: 'thick', label: __( 'Thick', 'popkit' ) },
		],
	},
	{
		key: 'custom_radius',
		label: __( 'Corner rounding', 'popkit' ),
		options: [
			{ value: 'inherit', label: __( 'Theme default', 'popkit' ) },
			{ value: 'none', label: __( 'Square', 'popkit' ) },
			{ value: 'small', label: __( 'Slightly rounded', 'popkit' ) },
			{ value: 'medium', label: __( 'Rounded', 'popkit' ) },
			{ value: 'large', label: __( 'Very rounded', 'popkit' ) },
		],
	},
	{
		key: 'custom_font',
		label: __( 'Font', 'popkit' ),
		options: [
			{ value: 'inherit', label: __( 'Follow the page', 'popkit' ) },
			{ value: 'system', label: __( 'System', 'popkit' ) },
			{ value: 'serif', label: __( 'Serif', 'popkit' ) },
			{ value: 'sans', label: __( 'Sans serif', 'popkit' ) },
			{ value: 'mono', label: __( 'Monospace', 'popkit' ) },
		],
	},
	{
		key: 'custom_font_size',
		label: __( 'Text size', 'popkit' ),
		options: [
			{ value: 'inherit', label: __( 'Follow the page', 'popkit' ) },
			{ value: 'small', label: __( 'Small', 'popkit' ) },
			{ value: 'medium', label: __( 'Medium', 'popkit' ) },
			{ value: 'large', label: __( 'Large', 'popkit' ) },
		],
	},
];

/**
 * Positions each layout allows, matching `Meta::DISPLAY_POSITIONS`.
 *
 * @type {Object<string, Array<{value: string, label: string}>>}
 */
const POSITIONS = {
	modal: [
		{ value: 'center', label: __( 'Centred', 'popkit' ) },
		{ value: 'top', label: __( 'Near the top', 'popkit' ) },
	],
	banner: [
		{ value: 'top', label: __( 'Top of the page', 'popkit' ) },
		{ value: 'bottom', label: __( 'Bottom of the page', 'popkit' ) },
		{ value: 'lower_third', label: __( 'Lower third', 'popkit' ) },
	],
};

/**
 * The appearance panel body.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.display  Stored display object.
 * @param {Function} props.onChange Called with the updated display object.
 * @return {JSX.Element} Panel body.
 */
export function DisplayPanel( { display, onChange } ) {
	const layout = 'banner' === display?.layout ? 'banner' : 'modal';
	const positions = POSITIONS[ layout ];
	const isModal = 'modal' === layout;

	return (
		<div className="popkit-display">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Layout', 'popkit' ) }
				value={ layout }
				options={ [
					{ value: 'modal', label: __( 'Modal dialog', 'popkit' ) },
					{ value: 'banner', label: __( 'Inline banner', 'popkit' ) },
				] }
				onChange={ ( next ) => {
					const allowed = POSITIONS[ next ].map(
						( option ) => option.value
					);

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
				options={ [
					{
						value: 'inherit',
						label: __( 'Inherit from the site', 'popkit' ),
					},
					{ value: 'light', label: __( 'Light', 'popkit' ) },
					{ value: 'dark', label: __( 'Dark', 'popkit' ) },
					{ value: 'bordered', label: __( 'Bordered', 'popkit' ) },
				] }
				onChange={ ( theme ) => onChange( { ...display, theme } ) }
			/>

			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Size', 'popkit' ) }
				value={ display?.size ?? 'medium' }
				options={ [
					{ value: 'small', label: __( 'Small', 'popkit' ) },
					{ value: 'medium', label: __( 'Medium', 'popkit' ) },
					{ value: 'large', label: __( 'Large', 'popkit' ) },
					{ value: 'full', label: __( 'Full width', 'popkit' ) },
				] }
				onChange={ ( size ) => onChange( { ...display, size } ) }
			/>

			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Position', 'popkit' ) }
				value={ display?.position ?? positions[ 0 ].value }
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
				options={ [
					{ value: 'none', label: __( 'None', 'popkit' ) },
					{ value: 'fade', label: __( 'Fade', 'popkit' ) },
					{ value: 'slide', label: __( 'Slide', 'popkit' ) },
				] }
				onChange={ ( animation ) =>
					onChange( { ...display, animation } )
				}
			/>

			{ COLORS.map( ( { key, label } ) => (
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					key={ key }
					label={ label }
					help={ __(
						'A hex value such as #ffffff. Leave blank to keep the theme’s own colour.',
						'popkit'
					) }
					value={ display?.[ key ] ?? '' }
					onChange={ ( value ) =>
						onChange( { ...display, [ key ]: value } )
					}
				/>
			) ) }

			{ SCALES.map( ( { key, label, options } ) => (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					key={ key }
					label={ label }
					value={ display?.[ key ] ?? 'inherit' }
					options={ options }
					onChange={ ( value ) =>
						onChange( { ...display, [ key ]: value } )
					}
				/>
			) ) }

			<p className="popkit-panel__note">
				{ __(
					'Check the contrast between your background and text colours before publishing. The shipped themes are measured against WCAG AA; a pair you choose is not.',
					'popkit'
				) }
			</p>
		</div>
	);
}
