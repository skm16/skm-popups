<?php
/**
 * Renders registry field schemas as classic-editor form controls.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * The classic screen's half of the shared control map.
 *
 * ## The registry invariant, in PHP
 *
 * `docs/CLAUDE.md` makes registering a condition a PHP-only act: declare fields
 * and the controls they use, and working UI appears with no JavaScript change.
 * That promise was kept by `src/editor/controls.js` for the block editor. This
 * file keeps the same promise for the classic editor, from the same schemas, so
 * a condition registered by a third party renders in both screens without
 * knowing either exists.
 *
 * Every control in {@see Condition::FIELD_CONTROLS} has a case here. A control
 * added to that list without a case here would render nothing on the classic
 * screen — which is why the list is the single source of truth and this file is
 * a `match` over it rather than a lookup that can silently miss.
 *
 * ## Where this is deliberately not identical to the block editor
 *
 * `multiselect` **with** an `enum` renders as a real multiple-select. Without
 * one, the field is an open-ended list — post IDs, term IDs, template names —
 * and the block editor fills those from REST as the author types. The classic
 * screen renders a comma-separated text input instead.
 *
 * That is a schema-driven rule, not a per-field exception, which matters: the
 * alternative was special-casing the built-in field *names*, and a rule keyed on
 * `ids` or `templates` would silently fail to apply to a condition somebody else
 * registered. A uniform rule is worse UX for three built-in fields and correct
 * for every field that will ever exist.
 *
 * `post-type-select` and `taxonomy-select` are named controls, so they get real
 * selects populated from `get_post_types()` and `get_taxonomies()` — cheaper and
 * more reliable server-side than the round trip the block editor has to make.
 *
 * ## Escaping
 *
 * Everything printed here is escaped at the point of output. Field schemas come
 * from registrations, which are code, but their labels are translatable and a
 * translation is data. Stored values are author input that has been through
 * sanitization but never through an HTML escaper.
 *
 * @since 0.1.0
 */
final class Classic_Fields {

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Renders one field as a labelled form control.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name   Full `name` attribute for the control.
	 * @param string               $id     DOM id, unique on the screen.
	 * @param array<string, mixed> $schema Field schema from the registry.
	 * @param mixed                $value  Stored value, or null for the default.
	 * @return void
	 */
	public static function render( string $name, string $id, array $schema, $value = null ): void {
		$control = isset( $schema['control'] ) ? (string) $schema['control'] : 'text';
		$label   = isset( $schema['label'] ) ? (string) $schema['label'] : $name;

		if ( null === $value && array_key_exists( 'default', $schema ) ) {
			$value = $schema['default'];
		}

		echo '<p class="popkit-field popkit-field--' . esc_attr( $control ) . '">';

		// A toggle labels itself, because the checkbox belongs inside its label.
		if ( 'toggle' !== $control ) {
			echo '<label class="popkit-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		}

		switch ( $control ) {
			case 'toggle':
				self::toggle( $name, $id, $label, $value );
				break;

			case 'select':
				self::select( $name, $id, $schema, $value, false );
				break;

			case 'multiselect':
				self::multiselect( $name, $id, $schema, $value );
				break;

			case 'post-type-select':
				self::options_select( $name, $id, self::post_type_options(), $value, self::is_array_field( $schema ) );
				break;

			case 'taxonomy-select':
				self::options_select( $name, $id, self::taxonomy_options(), $value, self::is_array_field( $schema ) );
				break;

			case 'number':
			case 'range':
				self::number( $name, $id, $schema, $value, 'range' === $control );
				break;

			case 'date-time':
				self::input( 'datetime-local', $name, $id, $schema, $value );
				break;

			case 'url-match':
			case 'text':
			default:
				self::input( 'text', $name, $id, $schema, $value );
				break;
		}

		echo '</p>';
	}

	/**
	 * Coerces submitted strings to the types the schema declares.
	 *
	 * `$_POST` is strings all the way down. The registered `sanitize_callback` on
	 * each meta key is the real validator and is not bypassed — but it validates
	 * *types*, so handing it the string `"1"` where it expects a boolean, or
	 * `"5"` where it expects an integer, makes it reject a rule the author filled
	 * in correctly. This turns the form's strings back into the shapes the schema
	 * describes, and nothing more: no clamping, no defaulting, no dropping of
	 * unknown keys. Deciding what is *valid* stays in one place.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $raw    Raw submitted values for one rule or trigger.
	 * @param array<string, mixed> $fields Field schemas, keyed by field name.
	 * @return array<string, mixed> Values with schema-declared types.
	 */
	public static function coerce( array $raw, array $fields ): array {
		$values = array();

		foreach ( $fields as $field => $schema ) {
			if ( ! array_key_exists( $field, $raw ) ) {
				continue;
			}

			$values[ $field ] = self::coerce_one( $raw[ $field ], (array) $schema );
		}

		return $values;
	}

	/**
	 * Coerces one submitted value against one field schema.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed                $value  Submitted value.
	 * @param array<string, mixed> $schema Field schema.
	 * @return mixed Value with the schema-declared type.
	 */
	private static function coerce_one( $value, array $schema ) {
		$type = isset( $schema['type'] ) ? (string) $schema['type'] : 'string';

		if ( 'array' === $type ) {
			$items = isset( $schema['items'] ) ? (string) $schema['items'] : 'string';

			return self::coerce_list( $value, $items );
		}

		if ( is_array( $value ) ) {
			// A scalar field cannot be satisfied by an array; hand it back for the
			// sanitizer to refuse rather than flattening it into something plausible.
			return $value;
		}

		switch ( $type ) {
			case 'boolean':
				return '' !== (string) $value && '0' !== (string) $value;

			case 'integer':
				return (int) $value;

			case 'number':
				return (float) $value;

			default:
				return (string) $value;
		}
	}

	/**
	 * Turns a multiple-select array or a comma-separated string into a typed list.
	 *
	 * Blank entries are dropped, because "a, ,b" is a typo rather than a request
	 * for an empty item, and an empty string in a list of post IDs would be
	 * stored as 0 and match nothing forever.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $value Submitted value.
	 * @param string $items Declared item type.
	 * @return array<int, string|int> Typed list.
	 */
	private static function coerce_list( $value, string $items ): array {
		$parts = is_array( $value ) ? $value : explode( ',', (string) $value );
		$list  = array();

		foreach ( $parts as $part ) {
			if ( is_array( $part ) ) {
				continue;
			}

			$part = trim( (string) $part );

			if ( '' === $part ) {
				continue;
			}

			$list[] = 'integer' === $items ? (int) $part : $part;
		}

		return $list;
	}

	/**
	 * Whether a schema describes a list.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $schema Field schema.
	 * @return bool True for an array field.
	 */
	private static function is_array_field( array $schema ): bool {
		return isset( $schema['type'] ) && 'array' === $schema['type'];
	}

	/**
	 * Renders a checkbox that carries its own label.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name  Name attribute.
	 * @param string $id    DOM id.
	 * @param string $label Label text.
	 * @param mixed  $value Stored value.
	 * @return void
	 */
	private static function toggle( string $name, string $id, string $label, $value ): void {
		/*
		 * The hidden companion is what makes unticking mean "off": an unchecked
		 * box posts nothing, so without it an absent key would be indistinguishable
		 * from a field the author never saw.
		 */
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />';
		echo '<label for="' . esc_attr( $id ) . '">';
		echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( (bool) $value, true, false ) . ' /> ';
		echo esc_html( $label );
		echo '</label>';
	}

	/**
	 * Renders a select built from the schema's `enum`.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name     Name attribute.
	 * @param string               $id       DOM id.
	 * @param array<string, mixed> $schema   Field schema.
	 * @param mixed                $value    Stored value.
	 * @param bool                 $multiple Whether several values may be chosen.
	 * @return void
	 */
	private static function select( string $name, string $id, array $schema, $value, bool $multiple ): void {
		$options = array();

		foreach ( (array) ( $schema['enum'] ?? array() ) as $choice ) {
			$options[ (string) $choice ] = (string) $choice;
		}

		self::options_select( $name, $id, $options, $value, $multiple );
	}

	/**
	 * Renders a multiselect, or a comma-separated text input when open-ended.
	 *
	 * See the class docblock for why the fallback is keyed on the absence of an
	 * `enum` rather than on the field's name.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name   Name attribute.
	 * @param string               $id     DOM id.
	 * @param array<string, mixed> $schema Field schema.
	 * @param mixed                $value  Stored value.
	 * @return void
	 */
	private static function multiselect( string $name, string $id, array $schema, $value ): void {
		if ( ! empty( $schema['enum'] ) ) {
			self::select( $name, $id, $schema, $value, true );

			return;
		}

		$list = is_array( $value ) ? $value : array();

		echo '<input type="text" class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"';
		echo ' value="' . esc_attr( implode( ', ', array_map( 'strval', $list ) ) ) . '"';
		echo ' aria-describedby="' . esc_attr( $id ) . '-hint" />';
		echo '<span class="description" id="' . esc_attr( $id ) . '-hint">';
		echo esc_html__( 'Separate several values with commas.', 'popkit' );
		echo '</span>';
	}

	/**
	 * Renders a select from an explicit option map.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $name     Name attribute.
	 * @param string                $id       DOM id.
	 * @param array<string, string> $options  Value => label.
	 * @param mixed                 $value    Stored value.
	 * @param bool                  $multiple Whether several values may be chosen.
	 * @return void
	 */
	private static function options_select( string $name, string $id, array $options, $value, bool $multiple ): void {
		$selected = is_array( $value ) ? array_map( 'strval', $value ) : array( (string) $value );

		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $multiple ? $name . '[]' : $name ) . '"';
		echo $multiple ? ' multiple size="4"' : '';
		echo '>';

		if ( ! $multiple ) {
			echo '<option value="">' . esc_html__( '— Select —', 'popkit' ) . '</option>';
		}

		foreach ( $options as $option_value => $option_label ) {
			echo '<option value="' . esc_attr( (string) $option_value ) . '"';
			echo in_array( (string) $option_value, $selected, true ) ? ' selected' : '';
			echo '>' . esc_html( (string) $option_label ) . '</option>';
		}

		echo '</select>';
	}

	/**
	 * Renders a number or range input, honouring declared bounds.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $name   Name attribute.
	 * @param string               $id     DOM id.
	 * @param array<string, mixed> $schema Field schema.
	 * @param mixed                $value  Stored value.
	 * @param bool                 $range  Whether to render a slider.
	 * @return void
	 */
	private static function number( string $name, string $id, array $schema, $value, bool $range ): void {
		echo '<input type="' . ( $range ? 'range' : 'number' ) . '" id="' . esc_attr( $id ) . '"';
		echo ' name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"';

		if ( isset( $schema['min'] ) ) {
			echo ' min="' . esc_attr( (string) $schema['min'] ) . '"';
		}

		if ( isset( $schema['max'] ) ) {
			echo ' max="' . esc_attr( (string) $schema['max'] ) . '"';
		}

		if ( isset( $schema['type'] ) && 'number' === $schema['type'] ) {
			echo ' step="any"';
		}

		echo ' />';
	}

	/**
	 * Renders a text-like input.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $type   Input type attribute.
	 * @param string               $name   Name attribute.
	 * @param string               $id     DOM id.
	 * @param array<string, mixed> $schema Field schema.
	 * @param mixed                $value  Stored value.
	 * @return void
	 */
	private static function input( string $type, string $name, string $id, array $schema, $value ): void {
		echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $id ) . '"';
		echo ' name="' . esc_attr( $name ) . '" value="' . esc_attr( is_scalar( $value ) ? (string) $value : '' ) . '"';

		if ( isset( $schema['max_length'] ) ) {
			echo ' maxlength="' . esc_attr( (string) (int) $schema['max_length'] ) . '"';
		}

		echo ' />';
	}

	/**
	 * Post types an author may target.
	 *
	 * `show_ui` rather than `public`: a post type with an admin screen is one an
	 * author can see and reason about, and the popup post type itself is removed
	 * because targeting a popup at popups is not a thing that can happen.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Post type name => label.
	 */
	private static function post_type_options(): array {
		$options = array();

		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $post_type ) {
			if ( Post_Type::POST_TYPE === $post_type->name ) {
				continue;
			}

			$options[ $post_type->name ] = $post_type->labels->singular_name ?? $post_type->name;
		}

		return $options;
	}

	/**
	 * Taxonomies an author may target.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Taxonomy name => label.
	 */
	private static function taxonomy_options(): array {
		$options = array();

		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $taxonomy ) {
			$options[ $taxonomy->name ] = $taxonomy->labels->singular_name ?? $taxonomy->name;
		}

		return $options;
	}
}
