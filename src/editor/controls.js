/**
 * popkit — the shared control map.
 *
 * One component per entry in {@link Condition::FIELD_CONTROLS}, and a dispatcher
 * that picks between them from a field schema fetched over REST. This file is
 * the reason registering a condition in PHP produces working editor UI with no
 * JavaScript changes: the registration declares a `control`, and the declaration
 * is looked up here.
 *
 * ## Dispatch reads `type` as well as `control`
 *
 * The obvious implementation keys on `control` alone and is wrong. `post_type`
 * declares `post-type-select` on an `array` field and `taxonomy_term` declares
 * `taxonomy-select` on a `string` one — the same family of control at different
 * arity. Keying on the control name alone would render a single select for a
 * field the sanitizer expects to hold a list, and the value would round-trip
 * through save as the wrong shape without anything reporting it.
 *
 * So every control that can be either reads `schema.type` and renders
 * accordingly. `multiselect` does the same thing for a different reason: with an
 * `enum` it is a fixed set of checkboxes, and without one — `post_ids.ids` has
 * no enum, because a post ID cannot be enumerated — it is freeform token entry.
 *
 * ## Coercion belongs here, not in the panel
 *
 * A `TextControl` hands back a string whatever the field declares, so a `number`
 * field would store `"5"` where the schema says `5`. PHP sanitization would
 * repair it on save and the editor would look correct throughout, which is
 * exactly what makes it worth fixing here: the stored value and the value the
 * editor is holding would differ for the whole editing session, and every
 * warning that reads stored values would be reading the wrong ones.
 *
 * {@link coerce} is applied on the way out of every control, keyed off the
 * declared type. It never repairs an unreadable value into a plausible one — an
 * empty number field yields `undefined` rather than `0`, because `0` is a width
 * somebody could have meant.
 *
 * ## No control ships from a registration
 *
 * A plugin registering a condition names a control from this map. It cannot
 * supply one. That is a deliberate limit rather than an unfinished feature: a
 * registration that shipped its own React component would need a build step, a
 * second copy of the editor's dependencies, and a review process this plugin
 * does not have. Adding a control means adding it here *and* to
 * `Condition::FIELD_CONTROLS`, which is two files and a reviewable act.
 *
 * ## Why this file pins the classic JSX runtime
 *
 * `@wordpress/babel-preset-default` hardcodes `runtime: 'automatic'`, and
 * wp-scripts runs babel-loader with `babelrc: false, configFile: false` — so a
 * project babel config is ignored and the file-level pragma below is the only
 * way to override it.
 *
 * It has to be overridden. The automatic runtime emits an import that
 * dependency extraction resolves to the `react-jsx-runtime` script handle, and
 * WordPress did not register that handle until 6.6. This plugin declares
 * `Requires at least: 6.5`. WordPress skips an unregistered dependency silently
 * rather than failing, so on 6.5 the bundle would enqueue, load, and throw a
 * `ReferenceError` on its first render — an editor screen that looks completely
 * normal and simply has no popkit panels in it, which is the exact failure
 * `Editor::render_missing_build_notice()` exists to make impossible for the
 * other cause.
 *
 * The classic runtime compiles to `createElement`, which resolves to
 * `wp-element` — registered since WordPress 5.0. Raising the minimum to 6.6
 * would work too and buys nothing this plugin needs.
 *
 * @jsxRuntime classic
 * @jsx createElement
 * @jsxFrag Fragment
 *
 * @see docs/CLAUDE.md -> Architecture invariants
 * @see includes/class-condition.php
 */

/* eslint-disable no-unused-vars -- createElement and Fragment are the JSX pragma targets. */
import { createElement, Fragment } from '@wordpress/element';
/* eslint-enable no-unused-vars */

import {
	CheckboxControl,
	DateTimePicker,
	FormTokenField,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { store as coreStore } from '@wordpress/core-data';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Turns a control's raw output into the type the field schema declares.
 *
 * Returns `undefined` for a value that cannot be read as the declared type,
 * which the caller stores as "field absent" rather than as a default. The
 * distinction matters on numeric fields: an author who clears a number input
 * has not asked for `0`, and `device.max_width` treats `0` as a rule nobody
 * matches. Coercing an empty input to zero would silently write that rule.
 *
 * @param {Object} schema Field schema from the registry.
 * @param {*}      raw    Value the control produced.
 * @return {*} Value in the declared type, or undefined when it cannot be read.
 */
export function coerce( schema, raw ) {
	const type = schema?.type;

	if ( 'integer' === type || 'number' === type ) {
		if ( '' === raw || null === raw || undefined === raw ) {
			return undefined;
		}

		const parsed = Number( raw );

		if ( ! Number.isFinite( parsed ) ) {
			return undefined;
		}

		return 'integer' === type ? Math.trunc( parsed ) : parsed;
	}

	if ( 'boolean' === type ) {
		return Boolean( raw );
	}

	if ( 'array' === type ) {
		if ( ! Array.isArray( raw ) ) {
			return undefined;
		}

		if ( 'integer' === schema?.items ) {
			// Tokens arrive as strings even when the field stores IDs. A token
			// that is not a number is dropped rather than stored as NaN, which
			// would fail validation on save with a message about the whole field.
			return raw
				.map( ( item ) => Number( item ) )
				.filter( ( item ) => Number.isInteger( item ) );
		}

		return raw.map( ( item ) => String( item ) );
	}

	return undefined === raw || null === raw ? undefined : String( raw );
}

/**
 * Builds `SelectControl` options from a field's `enum`.
 *
 * The enum member is used as its own label. Registrations declare enums as
 * stored vocabulary — `logged_in`, `prefix` — and there is no label map in the
 * field schema to read a friendlier string from. Inventing one here would mean
 * this file carrying a translation table for every condition any plugin might
 * register, which is the coupling the registry exists to avoid.
 *
 * @param {Object} schema Field schema from the registry.
 * @return {Array<{label: string, value: string}>} Select options.
 */
function enumOptions( schema ) {
	return ( schema?.enum ?? [] ).map( ( member ) => ( {
		label: String( member ),
		value: String( member ),
	} ) );
}

/**
 * Single-line text.
 *
 * `maxLength` is passed through from the schema so the browser stops the author
 * at the same boundary the sanitizer would, rather than letting them type a
 * value that is silently truncated on save.
 *
 * @param {Object}   props          Control props.
 * @param {Object}   props.schema   Field schema.
 * @param {string}   props.label    Control label.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Whether the control is read-only.
 * @return {JSX.Element} Control.
 */
function TextField( { schema, label, value, onChange, disabled } ) {
	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={
				undefined === value || null === value ? '' : String( value )
			}
			maxLength={ schema?.max_length }
			disabled={ disabled }
			onChange={ ( next ) => onChange( coerce( schema, next ) ) }
		/>
	);
}

/**
 * Numeric entry with the schema's bounds applied to the input.
 *
 * @param {Object}   props          Control props.
 * @param {Object}   props.schema   Field schema.
 * @param {string}   props.label    Control label.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Whether the control is read-only.
 * @return {JSX.Element} Control.
 */
function NumberField( { schema, label, value, onChange, disabled } ) {
	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			type="number"
			label={ label }
			value={
				undefined === value || null === value ? '' : String( value )
			}
			min={ schema?.min }
			max={ schema?.max }
			step={ 'integer' === schema?.type ? 1 : 'any' }
			disabled={ disabled }
			onChange={ ( next ) => onChange( coerce( schema, next ) ) }
		/>
	);
}

/**
 * Slider, for a bounded integer the author picks by feel rather than by value.
 *
 * Falls back to the schema's bounds and then to 0–100. `scroll_depth.percent` is
 * the motivating field and declares 1–100; a registration that names `range`
 * without bounds gets a usable control rather than a broken one.
 *
 * @param {Object}   props          Control props.
 * @param {Object}   props.schema   Field schema.
 * @param {string}   props.label    Control label.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Whether the control is read-only.
 * @return {JSX.Element} Control.
 */
function RangeField( { schema, label, value, onChange, disabled } ) {
	return (
		<RangeControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ Number.isFinite( value ) ? value : undefined }
			min={ schema?.min ?? 0 }
			max={ schema?.max ?? 100 }
			step={ 1 }
			disabled={ disabled }
			onChange={ ( next ) => onChange( coerce( schema, next ) ) }
		/>
	);
}

/**
 * Boolean switch.
 *
 * @param {Object}   props          Control props.
 * @param {Object}   props.schema   Field schema.
 * @param {string}   props.label    Control label.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Whether the control is read-only.
 * @return {JSX.Element} Control.
 */
function ToggleField( { schema, label, value, onChange, disabled } ) {
	return (
		<ToggleControl
			__nextHasNoMarginBottom
			label={ label }
			checked={ Boolean( value ) }
			disabled={ disabled }
			onChange={ ( next ) => onChange( coerce( schema, next ) ) }
		/>
	);
}

/**
 * One choice from the field's `enum`.
 *
 * @param {Object}   props          Control props.
 * @param {Object}   props.schema   Field schema.
 * @param {string}   props.label    Control label.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Whether the control is read-only.
 * @return {JSX.Element} Control.
 */
function SelectField( { schema, label, value, onChange, disabled } ) {
	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={
				undefined === value || null === value ? '' : String( value )
			}
			options={ enumOptions( schema ) }
			disabled={ disabled }
			onChange={ ( next ) => onChange( coerce( schema, next ) ) }
		/>
	);
}

/**
 * Several choices at once.
 *
 * Two shapes, chosen by whether the field declares an `enum`. With one, the
 * choices are known and finite and render as checkboxes, where every option is
 * visible without interaction. Without one — `post_ids.ids` holds arbitrary post
 * IDs, which cannot be enumerated — it is freeform token entry.
 *
 * @param {Object}   props          Control props.
 * @param {Object}   props.schema   Field schema.
 * @param {string}   props.label    Control label.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Whether the control is read-only.
 * @return {JSX.Element} Control.
 */
function MultiSelectField( { schema, label, value, onChange, disabled } ) {
	const selected = Array.isArray( value ) ? value : [];

	if ( Array.isArray( schema?.enum ) && 0 < schema.enum.length ) {
		return (
			<fieldset className="popkit-field popkit-field--checkboxes">
				<legend className="popkit-field__legend">{ label }</legend>
				{ schema.enum.map( ( member ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ String( member ) }
						label={ String( member ) }
						checked={ selected
							.map( String )
							.includes( String( member ) ) }
						disabled={ disabled }
						onChange={ ( checked ) => {
							const next = checked
								? [ ...selected, member ]
								: selected.filter(
										( item ) =>
											String( item ) !== String( member )
								  );

							onChange( coerce( schema, next ) );
						} }
					/>
				) ) }
			</fieldset>
		);
	}

	return (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ selected.map( String ) }
			disabled={ disabled }
			onChange={ ( next ) => onChange( coerce( schema, next ) ) }
		/>
	);
}

/**
 * Renders a set of entity choices as either a single select or a checkbox list.
 *
 * Shared by {@link PostTypeField} and {@link TaxonomyField}, which differ only in
 * which entities they fetch. The arity comes from `schema.type`, so the same
 * declared control serves `post_type.types` (an array) and
 * `taxonomy_term.taxonomy` (a string) without either registration saying which
 * shape it wanted beyond the type it already declared.
 *
 * @param {Object}   props              Control props.
 * @param {Object}   props.schema       Field schema.
 * @param {string}   props.label        Control label.
 * @param {*}        props.value        Current value.
 * @param {Function} props.onChange     Change handler.
 * @param {boolean}  props.disabled     Whether the control is read-only.
 * @param {Array}    props.options      Available options.
 * @param {boolean}  props.isLoading    Whether the entities are still being fetched.
 * @param {string}   props.loadingLabel Text shown while loading.
 * @return {JSX.Element} Control.
 */
function EntityField( {
	schema,
	label,
	value,
	onChange,
	disabled,
	options,
	isLoading,
	loadingLabel,
} ) {
	if ( isLoading ) {
		return (
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				value=""
				placeholder={ loadingLabel }
				disabled
				onChange={ () => {} }
			/>
		);
	}

	if ( 'array' === schema?.type ) {
		const selected = ( Array.isArray( value ) ? value : [] ).map( String );

		return (
			<fieldset className="popkit-field popkit-field--checkboxes">
				<legend className="popkit-field__legend">{ label }</legend>
				{ options.map( ( option ) => (
					<CheckboxControl
						__nextHasNoMarginBottom
						key={ option.value }
						label={ option.label }
						checked={ selected.includes( option.value ) }
						disabled={ disabled }
						onChange={ ( checked ) => {
							const next = checked
								? [ ...selected, option.value ]
								: selected.filter(
										( item ) => item !== option.value
								  );

							onChange( coerce( schema, next ) );
						} }
					/>
				) ) }
			</fieldset>
		);
	}

	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={
				undefined === value || null === value ? '' : String( value )
			}
			options={ [
				{ label: __( '— Select —', 'popkit' ), value: '' },
				...options,
			] }
			disabled={ disabled }
			onChange={ ( next ) => onChange( coerce( schema, next ) ) }
		/>
	);
}

/**
 * Registered post types, filtered to the ones a visitor can reach.
 *
 * `viewable` is the filter rather than `public`, because that is the question
 * the condition is actually asking: a post type nobody can load a single view of
 * cannot be the page a popup appears on.
 *
 * @param {Object} props Control props, forwarded to {@link EntityField}.
 * @return {JSX.Element} Control.
 */
function PostTypeField( props ) {
	const { options, isLoading } = useSelect( ( select ) => {
		const { getPostTypes, hasFinishedResolution } = select( coreStore );
		const query = { per_page: -1 };
		const types = getPostTypes( query );

		return {
			options: ( types ?? [] )
				.filter( ( type ) => type.viewable )
				.map( ( type ) => ( {
					label: type.labels?.singular_name ?? type.name ?? type.slug,
					value: type.slug,
				} ) ),
			isLoading: ! hasFinishedResolution( 'getPostTypes', [ query ] ),
		};
	}, [] );

	return (
		<EntityField
			{ ...props }
			options={ options }
			isLoading={ isLoading }
			loadingLabel={ __( 'Loading post types…', 'popkit' ) }
		/>
	);
}

/**
 * Registered taxonomies, filtered to the ones a visitor can reach.
 *
 * @param {Object} props Control props, forwarded to {@link EntityField}.
 * @return {JSX.Element} Control.
 */
function TaxonomyField( props ) {
	const { options, isLoading } = useSelect( ( select ) => {
		const { getTaxonomies, hasFinishedResolution } = select( coreStore );
		const query = { per_page: -1 };
		const taxonomies = getTaxonomies( query );

		return {
			options: ( taxonomies ?? [] )
				.filter( ( taxonomy ) => taxonomy.visibility?.public )
				.map( ( taxonomy ) => ( {
					label: taxonomy.name ?? taxonomy.slug,
					value: taxonomy.slug,
				} ) ),
			isLoading: ! hasFinishedResolution( 'getTaxonomies', [ query ] ),
		};
	}, [] );

	return (
		<EntityField
			{ ...props }
			options={ options }
			isLoading={ isLoading }
			loadingLabel={ __( 'Loading taxonomies…', 'popkit' ) }
		/>
	);
}

/**
 * A moment in time, stored as ISO 8601 UTC.
 *
 * `DateTimePicker` works in the site's timezone and emits a local-looking
 * string, so the value is converted at both boundaries. Storing what the picker
 * hands back would write a timestamp with no zone into a field
 * `docs/data-model.md` defines as UTC, and every schedule comparison downstream
 * would be off by the site's offset — invisibly, and only for sites not on UTC.
 *
 * The field schema is not read. `date-time` has no bounds, no length cap and no
 * enum to honour — the only constraint on the value is that it parses, which is
 * enforced on the way out rather than declared.
 *
 * @param {Object}   props          Control props.
 * @param {string}   props.label    Control label.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Whether the control is read-only.
 * @return {JSX.Element} Control.
 */
function DateTimeField( { label, value, onChange, disabled } ) {
	const parsed = 'string' === typeof value ? Date.parse( value ) : NaN;

	return (
		<fieldset
			className="popkit-field popkit-field--date"
			disabled={ disabled }
		>
			<legend className="popkit-field__legend">{ label }</legend>
			<DateTimePicker
				currentDate={
					Number.isFinite( parsed ) ? new Date( parsed ) : undefined
				}
				onChange={ ( next ) => {
					const stamp = Date.parse( next );

					onChange(
						Number.isFinite( stamp )
							? new Date( stamp ).toISOString()
							: undefined
					);
				} }
			/>
		</fieldset>
	);
}

/**
 * A value for the constrained URL match language.
 *
 * Renders as text, and adds the syntax help the mode needs. `glob` is the only
 * mode with syntax to explain, and an author who picks it without knowing that
 * `*` is the wildcard writes a literal asterisk into a path and gets a rule that
 * never matches — with no error, because the value is perfectly valid.
 *
 * The sibling `match` field is read to decide which help to show. That coupling
 * is by field *name* and is the one place in this file that knows anything about
 * a specific condition. It is guarded: an absent sibling shows the generic help
 * rather than throwing, so a registration declaring `url-match` without a
 * `match` field still gets a working control.
 *
 * @param {Object}   props          Control props.
 * @param {Object}   props.schema   Field schema.
 * @param {string}   props.label    Control label.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Whether the control is read-only.
 * @param {Object}   props.siblings The rule's other stored values.
 * @return {JSX.Element} Control.
 */
function UrlMatchField( {
	schema,
	label,
	value,
	onChange,
	disabled,
	siblings,
} ) {
	const mode = siblings?.match;

	const help =
		'glob' === mode
			? __(
					'Use * to match any run of characters and ? to match exactly one. No other characters are special.',
					'popkit'
			  )
			: sprintf(
					/* translators: %s: the selected match mode, such as "prefix". */
					__(
						'Compared using the “%s” mode. The query string and fragment are ignored.',
						'popkit'
					),
					String( mode ?? __( 'exact', 'popkit' ) )
			  );

	return (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={
				undefined === value || null === value ? '' : String( value )
			}
			maxLength={ schema?.max_length }
			help={ help }
			disabled={ disabled }
			onChange={ ( next ) => onChange( coerce( schema, next ) ) }
		/>
	);
}

/**
 * Every control a field schema may name, keyed by the name it names.
 *
 * A `Map` rather than an object literal for the reason
 * `src/frontend/conditions/index.js` gives: the key arrives from stored data, and
 * a field declaring `"control": "constructor"` finds nothing on a `Map` and
 * something inherited on an object.
 *
 * @type {Map<string, Function>}
 */
export const CONTROLS = new Map( [
	[ 'text', TextField ],
	[ 'number', NumberField ],
	[ 'range', RangeField ],
	[ 'toggle', ToggleField ],
	[ 'select', SelectField ],
	[ 'multiselect', MultiSelectField ],
	[ 'post-type-select', PostTypeField ],
	[ 'taxonomy-select', TaxonomyField ],
	[ 'date-time', DateTimeField ],
	[ 'url-match', UrlMatchField ],
] );

/**
 * Renders one field from its schema.
 *
 * A field naming a control this build does not have renders as disabled text
 * showing the stored value, not as nothing. The case is the field-level twin of
 * an unavailable condition: something is stored, this editor cannot edit it, and
 * the one unacceptable outcome is a control that silently writes over it. It is
 * reachable in a real install — a plugin registering against a newer popkit that
 * added a control this one predates — and `Condition::FIELD_CONTROLS` rejects it
 * at registration, so it also cannot happen without a version skew.
 *
 * @param {Object}   props            Field props.
 * @param {Object}   props.schema     Field schema from the registry.
 * @param {*}        props.value      Current stored value.
 * @param {Function} props.onChange   Called with the new value.
 * @param {boolean}  [props.disabled] Whether the field is read-only.
 * @param {Object}   [props.siblings] The rule's other stored values.
 * @return {JSX.Element} Rendered control.
 */
export function FieldControl( {
	schema,
	value,
	onChange,
	disabled = false,
	siblings = {},
} ) {
	const Control = CONTROLS.get( schema?.control );
	const label = schema?.label ?? '';

	if ( ! Control ) {
		return (
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				value={ JSON.stringify( value ?? null ) }
				disabled
				help={ sprintf(
					/* translators: %s: the control name a field schema asked for. */
					__(
						'This field asks for a “%s” control, which this version of popkit does not have. The stored value is shown as saved and is left unchanged.',
						'popkit'
					),
					String( schema?.control ?? '' )
				) }
				onChange={ () => {} }
			/>
		);
	}

	return (
		<Control
			schema={ schema }
			label={ label }
			value={ value }
			onChange={ onChange }
			disabled={ disabled }
			siblings={ siblings }
		/>
	);
}
