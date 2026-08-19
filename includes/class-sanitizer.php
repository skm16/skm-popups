<?php
/**
 * Schema-derived sanitization for stored popup data.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns submitted values into stored values, driven by the registered schema.
 *
 * `docs/CLAUDE.md` -> Security requires that a field declares its type and that
 * sanitization is derived from that declaration. There is therefore no
 * hand-written per-field sanitizer anywhere in popkit: a condition or trigger
 * declares `type`, and this class maps that declaration to a sanitized value.
 * Adding a field type means adding an arm to self::sanitize_value(), never a
 * bespoke callback next to a control.
 *
 * Two behaviors here are targeting-safety decisions rather than conveniences,
 * and both follow from `docs/CLAUDE.md` -> Registry invariants:
 *
 * - **A rule whose type is unregistered is never discarded.** Its values are
 *   preserved semantically — no key reordering, no type coercion, no stripping
 *   of unrecognized keys — subject only to Rule_Bounds. A condition supplied by
 *   a plugin that happens to be deactivated at save time must survive the round
 *   trip intact, because dropping it would destroy the author's targeting and
 *   widen the audience on reactivation.
 * - **A rule that cannot be preserved is replaced, not removed.** Removing a
 *   rule from a group widens that group, since rules within a group are ANDed.
 *   The refused rule is therefore swapped for self::REJECTED_RULE_TYPE, an
 *   unregistered marker that is indeterminate on the server, fails closed on the
 *   client, and shows in the editor as an unavailable condition. The group keeps
 *   its shape and can no longer match anybody. This holds for a rule that is
 *   merely unreadable as much as for one that is refused: the two are the same
 *   event from the group's point of view, and {@see Rule_Evaluator::strip_group()}
 *   keeps an unreadable rule for the identical reason. A rule dropped here and
 *   kept there would mean a group lost a rule between the save and the render.
 *
 * The primary refusal path is self::sanitize_rule() and self::validate_rule_set(),
 * which return WP_Error so a REST write fails with an editor-visible message.
 * {@see Meta::validate_rest_write()} is what puts self::validate_rule_set() in
 * front of a write; the docblock there records which core hook can actually
 * refuse one and why the alternatives cannot.
 *
 * self::sanitize_rule_set() is the storage-level floor beneath that, reached by
 * a direct update_post_meta() call: it cannot report an error, so it fails
 * closed instead.
 *
 * @since 0.1.0
 */
final class Sanitizer {

	/**
	 * Rule type standing in for a rule that could not be preserved.
	 *
	 * Deliberately not a registered condition and never registrable as one: the
	 * marker works precisely because nothing can evaluate it, so the group it
	 * sits in fails closed on the client.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const REJECTED_RULE_TYPE = 'popkit_rejected';

	/**
	 * Field type assumed for array items when `items` declares nothing.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const DEFAULT_ITEM_TYPE = 'string';

	/**
	 * Root label used when reporting the position of an offending value.
	 *
	 * The same label {@see Rule_Bounds} reports under, so a refusal reads the same
	 * way whether the rule's type was registered or not.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const PATH_ROOT = 'values';

	/**
	 * Machine name reported for a value longer than its field's declared cap.
	 *
	 * Named after the schema key that was violated, so the error says which limit
	 * refused the write.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const MAX_LENGTH_LIMIT = 'max_length';

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Sanitizes one value against one field schema.
	 *
	 * Recognized schema keys:
	 *
	 * - `type`      — `string`, `integer`, `number`, `boolean`, `enum` or `array`.
	 * - `default`   — used when a value is absent or unusable.
	 * - `enum`      — permitted values, for `type` `enum`.
	 * - `items`     — schema each item is sanitized against, for `type` `array`.
	 * - `minimum` / `maximum` — clamp for `integer` and `number`. `min` and `max`
	 *   are accepted as aliases.
	 * - `maxLength` — truncation point for `string`. `max_length` is an alias.
	 *
	 * A schema declaring a type this class does not know falls back to the
	 * declared default, or null. Guessing at a sanitizer for an unknown type is
	 * how hand-written per-field sanitization creeps back in.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw          Submitted value.
	 * @param array $field_schema Field schema as declared by a Condition or Trigger.
	 * @return mixed Sanitized value, typed per the schema.
	 */
	public static function sanitize_value( mixed $raw, array $field_schema ): mixed {
		$type = $field_schema['type'] ?? self::DEFAULT_ITEM_TYPE;
		$type = is_string( $type ) ? strtolower( $type ) : self::DEFAULT_ITEM_TYPE;

		return match ( $type ) {
			'string'          => self::sanitize_string( $raw, $field_schema ),
			'integer', 'int'  => self::sanitize_integer( $raw, $field_schema ),
			'number', 'float' => self::sanitize_number( $raw, $field_schema ),
			'boolean', 'bool' => self::sanitize_boolean( $raw ),
			'enum'            => self::sanitize_enum( $raw, $field_schema ),
			'array'           => self::sanitize_array( $raw, $field_schema ),
			default           => self::declared_default( $field_schema ),
		};
	}

	/**
	 * Sanitizes a whole rule set into the shape stored in `_popkit_conditions`.
	 *
	 * Always returns a well-formed set, because it is the last thing to run
	 * before a write and has no way to report a failure. What it does with a
	 * payload it cannot fully preserve is chosen so that no outcome ever widens
	 * the audience:
	 *
	 * - Absent, empty or unreadable `groups` becomes an empty set, which
	 *   `docs/data-model.md` defines as "always match". That is the documented
	 *   meaning of a popup with no targeting.
	 * - A group holding more than Rule_Bounds::MAX_RULES_PER_GROUP rules keeps
	 *   its place but is reduced to the refusal marker. Keeping some of its rules
	 *   would drop the rest, and dropping an ANDed rule widens the group.
	 * - Groups past Rule_Bounds::MAX_GROUPS_PER_SET are dropped. Groups are ORed,
	 *   so dropping one can only narrow.
	 * - A group left with no rules is dropped, because a group with no rules
	 *   matches everybody.
	 * - If every group is dropped, one refusal group is emitted. Collapsing a set
	 *   that declared targeting into the empty "always match" set is the silent
	 *   widening this plugin exists to avoid.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted rule set.
	 * @return array Rule set in the `array( 'groups' => array( array( 'rules' => array() ) ) )` shape.
	 */
	public static function sanitize_rule_set( mixed $raw ): array {
		$groups = self::raw_groups( $raw );

		if ( array() === $groups ) {
			return array( 'groups' => array() );
		}

		$sanitized = array();

		foreach ( $groups as $group ) {
			if ( count( $sanitized ) >= Rule_Bounds::MAX_GROUPS_PER_SET ) {
				break;
			}

			$rules = self::sanitize_group_rules( $group );

			if ( array() === $rules ) {
				continue;
			}

			$sanitized[] = array( 'rules' => $rules );
		}

		if ( array() === $sanitized ) {
			$sanitized[] = array( 'rules' => array( self::rejected_rule() ) );
		}

		return array( 'groups' => $sanitized );
	}

	/**
	 * Sanitizes one rule.
	 *
	 * A registered type has its values validated against the condition's field
	 * schema, and keys the schema does not declare are dropped: the schema is
	 * authoritative for a type popkit can actually evaluate. A value longer than
	 * the `max_length` its field declares refuses the whole rule, for the same
	 * reason an over-bounds unknown payload does — see
	 * self::check_field_lengths().
	 *
	 * An unregistered type has its values preserved exactly as submitted, and is
	 * marked `unknown` so the server can treat it as indeterminate, the client
	 * can fail it closed, and the editor can flag it. Rule_Bounds is the only
	 * thing standing between those values and the database, so a payload outside
	 * its limits refuses the whole rule rather than storing part of it.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted rule.
	 * @return array|WP_Error Sanitized rule; an empty array when the payload is not
	 *                        a rule at all; WP_Error when it is a rule that must be
	 *                        refused.
	 */
	public static function sanitize_rule( mixed $raw ): array|WP_Error {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$type = $raw['type'] ?? null;

		if ( ! is_string( $type ) ) {
			return array();
		}

		/*
		 * The type is a key, not authored content, so sanitizing it cannot lose
		 * information a real condition key carries. It is bounded by the same
		 * string limit as everything else in the rule.
		 */
		$type = sanitize_text_field( $type );

		if ( '' === $type || strlen( $type ) > Rule_Bounds::MAX_STRING_LENGTH ) {
			return array();
		}

		$negate    = self::sanitize_boolean( $raw['negate'] ?? false );
		$values    = $raw['values'] ?? array();
		$condition = self::condition( $type );

		if ( $condition instanceof Condition ) {
			$over_length = self::check_field_lengths( $values, $condition->fields );

			if ( $over_length instanceof WP_Error ) {
				return $over_length;
			}

			return array(
				'type'   => $type,
				'negate' => $negate,
				'values' => self::sanitize_fields( $values, $condition->fields ),
			);
		}

		$bounds = Rule_Bounds::check( $values );

		if ( $bounds instanceof WP_Error ) {
			return $bounds;
		}

		return array(
			'type'    => $type,
			'negate'  => $negate,
			'values'  => $values,
			'unknown' => true,
		);
	}

	/**
	 * Validates a rule set without sanitizing it, for a REST write.
	 *
	 * Sanitization alone cannot refuse a write: self::sanitize_rule_set() has to
	 * return something storable. This is the check that runs first, so an
	 * over-bounds payload fails the request with a message the editor can show
	 * rather than being quietly replaced by the refusal marker.
	 *
	 * {@see Meta::validate_rest_write()} is what calls it, on
	 * `rest_pre_insert_popkit_popup` — the one hook that both aborts the write and
	 * still has the submitted payload to explain the refusal from. Calling it
	 * anywhere later is pointless, because core sanitizes meta before every hook
	 * that can short-circuit the database write.
	 *
	 * The declared return type is `bool|WP_Error` rather than `true|WP_Error`
	 * only because the standalone `true` type is PHP 8.2; success is always
	 * boolean true.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted rule set.
	 * @return bool|WP_Error True when the set can be stored intact, WP_Error
	 *                       describing the first refusal otherwise.
	 */
	public static function validate_rule_set( mixed $raw ): bool|WP_Error {
		$groups = self::raw_groups( $raw );
		$check  = Rule_Bounds::check_group_count( count( $groups ) );

		if ( $check instanceof WP_Error ) {
			return $check;
		}

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$rules = $group['rules'] ?? array();

			if ( ! is_array( $rules ) ) {
				continue;
			}

			$check = Rule_Bounds::check_rule_count( count( $rules ) );

			if ( $check instanceof WP_Error ) {
				return $check;
			}

			foreach ( $rules as $rule ) {
				$sanitized = self::sanitize_rule( $rule );

				if ( $sanitized instanceof WP_Error ) {
					return $sanitized;
				}
			}
		}

		return true;
	}

	/**
	 * Sanitizes the rules of one group.
	 *
	 * Every submitted rule produces exactly one stored rule. A rule that was
	 * refused, and a rule that could not be read as a rule at all, both become the
	 * refusal marker rather than disappearing.
	 *
	 * Dropping either would widen the group. Rules within a group are ANDed, so a
	 * group of three constraints that loses one is left constraining less than its
	 * author asked for, and the popup shows to people it was targeted away from.
	 * {@see Rule_Evaluator::strip_group()} keeps an unreadable rule for exactly
	 * this reason, and the two have to agree: a rule dropped at save time and kept
	 * at render time means a group quietly lost a constraint in between.
	 *
	 * An empty result therefore means the group carried no rules to begin with,
	 * which {@see Sanitizer::sanitize_rule_set()} handles by dropping the group —
	 * a group with no rules matches everybody.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $group Submitted group.
	 * @return array Sanitized rules, empty when the group cannot contribute one.
	 */
	private static function sanitize_group_rules( mixed $group ): array {
		if ( ! is_array( $group ) ) {
			return array();
		}

		$raw_rules = $group['rules'] ?? null;

		if ( ! is_array( $raw_rules ) || array() === $raw_rules ) {
			return array();
		}

		if ( count( $raw_rules ) > Rule_Bounds::MAX_RULES_PER_GROUP ) {
			return array( self::rejected_rule() );
		}

		$rules = array();

		foreach ( $raw_rules as $raw_rule ) {
			$rule = self::sanitize_rule( $raw_rule );

			// Refused, or unreadable. Either way the group keeps a rule in its place.
			if ( $rule instanceof WP_Error || array() === $rule ) {
				$rules[] = self::rejected_rule();

				continue;
			}

			$rules[] = $rule;
		}

		return $rules;
	}

	/**
	 * Sanitizes a rule's values against a condition's declared fields.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $values Submitted values.
	 * @param array $fields Field name => field schema, as declared by the condition.
	 * @return array Sanitized values, holding only declared fields.
	 */
	private static function sanitize_fields( mixed $values, array $fields ): array {
		if ( ! is_array( $values ) ) {
			$values = array();
		}

		$sanitized = array();

		foreach ( $fields as $name => $schema ) {
			if ( ! is_array( $schema ) ) {
				continue;
			}

			if ( array_key_exists( $name, $values ) ) {
				$sanitized[ $name ] = self::sanitize_value( $values[ $name ], $schema );

				continue;
			}

			if ( array_key_exists( 'default', $schema ) ) {
				$sanitized[ $name ] = self::sanitize_value( $schema['default'], $schema );
			}
		}

		return $sanitized;
	}

	/**
	 * Checks a condition's values against the length caps its fields declare.
	 *
	 * `docs/data-model.md` -> Bounds on unknown rule values requires an
	 * over-bounds payload to be "rejected whole at save time, never truncated",
	 * and {@see Url_Matcher::is_valid_value()} documents the same contract for the
	 * 255 byte cap on a match value. Truncation is not a gentler form of the same
	 * refusal, it is a different and worse outcome: a `prefix` rule shortened from
	 * `/members/private-briefing` to `/members/pri` saves without complaint, reads
	 * as intact in the editor, and matches every page under that prefix. The rule
	 * that was supposed to narrow the audience widened it.
	 *
	 * The cap is measured in bytes, because {@see Condition} defines `max_length`
	 * in bytes so that a declared cap and the cap {@see Url_Matcher} enforces at
	 * runtime are the same number read the same way.
	 *
	 * It is measured *after* sanitize_text_field(), because that is the string
	 * that would have been stored. Measuring the submitted value instead would
	 * refuse `<em>hi</em>` against a cap of five bytes that the stored `hi`
	 * clears.
	 *
	 * A field absent from the payload is not checked: it falls back to the
	 * declared default, and {@see Condition} already refuses to register a default
	 * that does not fit its own cap.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $values Submitted values.
	 * @param array $fields Field name => field schema, as declared by the condition.
	 * @return WP_Error|null WP_Error naming the first cap exceeded, null when every
	 *                       declared cap is respected.
	 */
	private static function check_field_lengths( mixed $values, array $fields ): ?WP_Error {
		if ( ! is_array( $values ) ) {
			return null;
		}

		foreach ( $fields as $name => $schema ) {
			if ( ! is_array( $schema ) || ! array_key_exists( $name, $values ) ) {
				continue;
			}

			$error = self::check_length( $values[ $name ], $schema, self::PATH_ROOT . '.' . $name );

			if ( $error instanceof WP_Error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Checks one value against the length cap its own schema declares.
	 *
	 * An array field is checked item by item, so a list of paths is bounded the
	 * same way a single path is. {@see Condition} permits `items` to name a scalar
	 * type only, so this cannot recurse further than one level.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $raw    Submitted value.
	 * @param array  $schema Field schema.
	 * @param string $path   Position of the value, for the error message.
	 * @return WP_Error|null WP_Error when the cap is exceeded, null otherwise.
	 */
	private static function check_length( mixed $raw, array $schema, string $path ): ?WP_Error {
		$type = $schema['type'] ?? self::DEFAULT_ITEM_TYPE;
		$type = is_string( $type ) ? strtolower( $type ) : self::DEFAULT_ITEM_TYPE;

		if ( 'array' === $type ) {
			return self::check_item_lengths( $raw, $schema, $path );
		}

		if ( 'string' !== $type ) {
			return null;
		}

		$max = self::numeric_option( $schema, 'maxLength', 'max_length' );

		if ( null === $max || $max <= 0 ) {
			return null;
		}

		$length = strlen( sanitize_text_field( $raw ) );

		if ( $length <= $max ) {
			return null;
		}

		return new WP_Error(
			Rule_Bounds::ERROR_CODE,
			sprintf(
				/* translators: 1: position of the offending value, for example values.value, 2: length found in bytes, 3: maximum length in bytes. */
				__( 'This targeting rule was not saved: the text at %1$s is %2$d bytes long and the limit is %3$d.', 'popkit' ),
				$path,
				$length,
				(int) $max
			),
			array(
				'limit'  => self::MAX_LENGTH_LIMIT,
				'path'   => $path,
				'max'    => (int) $max,
				'status' => 400,
			)
		);
	}

	/**
	 * Checks each item of an array field against the item schema's length cap.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $raw    Submitted value.
	 * @param array  $schema Field schema declaring `type` `array`.
	 * @param string $path   Position of the value, for the error message.
	 * @return WP_Error|null WP_Error when an item exceeds the cap, null otherwise.
	 */
	private static function check_item_lengths( mixed $raw, array $schema, string $path ): ?WP_Error {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$items = self::item_schema( $schema );
		$index = 0;

		foreach ( $raw as $item ) {
			$error = self::check_length( $item, $items, $path . '[' . $index . ']' );

			if ( $error instanceof WP_Error ) {
				return $error;
			}

			++$index;
		}

		return null;
	}

	/**
	 * Reads the schema each item of an array field is measured against.
	 *
	 * `Condition` requires `items` to be a bare type name — see
	 * Condition::FIELD_ITEM_TYPES, which rejects anything else at registration.
	 * That spelling is promoted to a schema here so the rest of this class has one
	 * shape to read.
	 *
	 * @since 0.1.0
	 *
	 * @param array $schema Field schema declaring `type` `array`.
	 * @return array Item schema.
	 */
	private static function item_schema( array $schema ): array {
		$items = $schema['items'] ?? null;

		if ( is_string( $items ) && '' !== $items ) {
			return array( 'type' => $items );
		}

		if ( ! is_array( $items ) ) {
			return array( 'type' => self::DEFAULT_ITEM_TYPE );
		}

		return $items;
	}

	/**
	 * Sanitizes a string field.
	 *
	 * The cap is applied here as a floor, not as the enforcement of the contract.
	 * A rule's values never arrive over-length, because
	 * self::check_field_lengths() has already refused the whole rule by the time
	 * this runs; what remains is a direct call with a schema nothing checked, and
	 * for that a bounded string is better than an unbounded one.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw    Submitted value.
	 * @param array $schema Field schema.
	 * @return string
	 */
	private static function sanitize_string( mixed $raw, array $schema ): string {
		$value = sanitize_text_field( $raw );
		$max   = self::numeric_option( $schema, 'maxLength', 'max_length' );

		if ( null !== $max && $max > 0 ) {
			$value = self::truncate( $value, (int) $max );
		}

		return $value;
	}

	/**
	 * Sanitizes an integer field.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw    Submitted value.
	 * @param array $schema Field schema.
	 * @return int
	 */
	private static function sanitize_integer( mixed $raw, array $schema ): int {
		$number = self::to_number( $raw, $schema );

		return (int) self::clamp( (int) $number, $schema );
	}

	/**
	 * Sanitizes a floating point field.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw    Submitted value.
	 * @param array $schema Field schema.
	 * @return float
	 */
	private static function sanitize_number( mixed $raw, array $schema ): float {
		return (float) self::clamp( self::to_number( $raw, $schema ), $schema );
	}

	/**
	 * Coerces a submitted value to a finite number.
	 *
	 * A value that is not a number at all falls back to the declared default
	 * rather than to zero: for a field such as a device width, zero is a real
	 * setting that silently changes who the popup targets.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw    Submitted value.
	 * @param array $schema Field schema.
	 * @return float
	 */
	private static function to_number( mixed $raw, array $schema ): float {
		if ( is_bool( $raw ) ) {
			return $raw ? 1.0 : 0.0;
		}

		if ( is_int( $raw ) ) {
			return (float) $raw;
		}

		if ( is_float( $raw ) && is_finite( $raw ) ) {
			return $raw;
		}

		if ( is_string( $raw ) && is_numeric( trim( $raw ) ) ) {
			$value = (float) trim( $raw );

			if ( is_finite( $value ) ) {
				return $value;
			}
		}

		$default = self::declared_default( $schema );

		if ( is_int( $default ) || ( is_float( $default ) && is_finite( $default ) ) ) {
			return (float) $default;
		}

		return 0.0;
	}

	/**
	 * Clamps a number to the schema's declared range.
	 *
	 * @since 0.1.0
	 *
	 * @param int|float $value  Value to clamp.
	 * @param array     $schema Field schema.
	 * @return int|float
	 */
	private static function clamp( int|float $value, array $schema ): int|float {
		$min = self::numeric_option( $schema, 'minimum', 'min' );
		$max = self::numeric_option( $schema, 'maximum', 'max' );

		if ( null !== $min && $value < $min ) {
			$value = $min;
		}

		if ( null !== $max && $value > $max ) {
			$value = $max;
		}

		return $value;
	}

	/**
	 * Coerces a submitted value to a boolean.
	 *
	 * These are rest_sanitize_boolean()'s semantics, replicated rather than
	 * called so that this class stays pure logic and testable without WordPress
	 * loaded. The important half is that the strings `'0'` and `'false'` are
	 * false: a plain cast makes `'false'` true, and a rule or setting that reads
	 * as enabled when its stored value says otherwise is how a popup targets the
	 * wrong audience, or worse, how an irreversible action reads as authorized.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted value.
	 * @return bool
	 */
	private static function sanitize_boolean( mixed $raw ): bool {
		if ( is_string( $raw ) ) {
			$raw = strtolower( $raw );

			if ( 'false' === $raw || '0' === $raw ) {
				return false;
			}
		}

		return (bool) $raw;
	}

	/**
	 * Resolves a submitted value to one of the schema's permitted values.
	 *
	 * The return value is always a member of the declared list, or null when the
	 * schema declares no list. A value arriving from a form or a JSON body may be
	 * a string where the schema declared an integer, so an exact match is tried
	 * first and a scalar comparison second; either way the value stored is the
	 * one the schema declared, with the type the schema declared.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw    Submitted value.
	 * @param array $schema Field schema.
	 * @return mixed
	 */
	private static function sanitize_enum( mixed $raw, array $schema ): mixed {
		$allowed = $schema['enum'] ?? array();

		if ( ! is_array( $allowed ) || array() === $allowed ) {
			return self::declared_default( $schema );
		}

		$allowed = array_values( $allowed );

		if ( in_array( $raw, $allowed, true ) ) {
			return $raw;
		}

		if ( is_scalar( $raw ) ) {
			foreach ( $allowed as $candidate ) {
				if ( is_scalar( $candidate ) && (string) $candidate === (string) $raw ) {
					return $candidate;
				}
			}
		}

		$default = self::declared_default( $schema );

		if ( in_array( $default, $allowed, true ) ) {
			return $default;
		}

		return $allowed[0];
	}

	/**
	 * Sanitizes an array field, item by item.
	 *
	 * Items are re-indexed: a declared array is a list, and keys arriving from a
	 * submitted payload carry no meaning the schema recognizes.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw    Submitted value.
	 * @param array $schema Field schema.
	 * @return array
	 */
	private static function sanitize_array( mixed $raw, array $schema ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		/*
		 * Read through self::item_schema(), which promotes the bare type name
		 * `Condition` requires to a schema.
		 *
		 * Treating that string as "unspecified" and falling through to the default
		 * silently sanitized every declared int[] field as strings, so `post_ids`
		 * and `taxonomy_term` would have stored array( '12', '34' ) and the wrong
		 * type would survive the round trip.
		 */
		$items     = self::item_schema( $schema );
		$sanitized = array();

		foreach ( $raw as $item ) {
			$sanitized[] = self::sanitize_value( $item, $items );
		}

		return $sanitized;
	}

	/**
	 * Reads a numeric schema option under its canonical name or its alias.
	 *
	 * The canonical names are the JSON Schema ones, so a field schema can be
	 * handed to the REST validator unchanged. The short aliases are accepted
	 * because they read better in a registration array.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $schema    Field schema.
	 * @param string $canonical Canonical option name.
	 * @param string $alias     Accepted alias.
	 * @return int|float|null The option, or null when it is absent or not a number.
	 */
	private static function numeric_option( array $schema, string $canonical, string $alias ): int|float|null {
		foreach ( array( $canonical, $alias ) as $name ) {
			if ( ! array_key_exists( $name, $schema ) ) {
				continue;
			}

			$value = $schema[ $name ];

			if ( is_int( $value ) ) {
				return $value;
			}

			if ( is_float( $value ) && is_finite( $value ) ) {
				return $value;
			}

			if ( is_string( $value ) && is_numeric( trim( $value ) ) ) {
				return (float) trim( $value );
			}
		}

		return null;
	}

	/**
	 * Reads the schema's declared default.
	 *
	 * @since 0.1.0
	 *
	 * @param array $schema Field schema.
	 * @return mixed The declared default, or null when none is declared.
	 */
	private static function declared_default( array $schema ): mixed {
		return $schema['default'] ?? null;
	}

	/**
	 * Shortens a string to a maximum number of bytes.
	 *
	 * Bytes, because that is the unit {@see Condition} declares `max_length` in.
	 * `mb_strcut()` is the byte-oriented cut, so the result respects the cap
	 * without splitting a multi-byte character in half — a split one would leave
	 * invalid UTF-8, which {@see Rule_Bounds::check_string()} refuses outright.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value  Value to shorten.
	 * @param int    $length Maximum length in bytes.
	 * @return string
	 */
	private static function truncate( string $value, int $length ): string {
		if ( strlen( $value ) <= $length ) {
			return $value;
		}

		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, $length, 'UTF-8' );
		}

		return substr( $value, 0, $length );
	}

	/**
	 * Reads the groups out of a submitted rule set.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted rule set.
	 * @return array Submitted groups, empty when there are none to read.
	 */
	private static function raw_groups( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$groups = $raw['groups'] ?? null;

		return is_array( $groups ) ? $groups : array();
	}

	/**
	 * Builds the marker that replaces a rule which could not be preserved.
	 *
	 * @since 0.1.0
	 *
	 * @return array A rule no condition can evaluate, so its group fails closed.
	 */
	private static function rejected_rule(): array {
		return array(
			'type'    => self::REJECTED_RULE_TYPE,
			'negate'  => false,
			'values'  => array(),
			'unknown' => true,
		);
	}

	/**
	 * Looks a condition up in the registry.
	 *
	 * The registry accessor is guarded rather than assumed. This class is loaded
	 * by the classmap and is used from contexts where the registries have not
	 * been constructed — an early meta write, a unit test, a partially loaded
	 * plugin. An absent registry means every rule is treated as unregistered,
	 * which preserves the author's values instead of rewriting them against a
	 * schema popkit cannot currently see.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type Rule type.
	 * @return Condition|null The registered condition, or null when there is none.
	 */
	private static function condition( string $type ): ?Condition {
		if ( ! function_exists( __NAMESPACE__ . '\popkit_conditions' ) && ! function_exists( 'popkit_conditions' ) ) {
			return null;
		}

		$conditions = popkit_conditions();

		if ( ! $conditions instanceof Conditions ) {
			return null;
		}

		$condition = $conditions->get( $type );

		return $condition instanceof Condition ? $condition : null;
	}
}
