<?php
/**
 * Immutable description of one registered targeting condition.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Value object describing one targeting condition, and the field schema vocabulary.
 *
 * A condition is constructed once during `popkit_register_conditions`, handed to
 * {@see \Popkit\Conditions::register()}, and never mutated. Every property is
 * readonly, so a registration cannot be rewritten by later code and the registry
 * cannot hand out a mutable reference to shared state.
 *
 * Registration is developer-facing, so every constraint below fails loudly with
 * an `InvalidArgumentException` at registration time rather than degrading
 * quietly at evaluation time. A malformed condition that survived registration
 * would surface as an unlabeled control in the editor, a rule that sanitizes to
 * nothing, or, worst case, targeting that silently widens.
 *
 * ## Field schema
 *
 * `$fields` maps a field name to a field schema. That schema is the single
 * source of truth driving three separate consumers, which is why it is validated
 * this strictly:
 *
 * 1. The block editor sidebar renders a control from `control` and `label`.
 * 2. The REST layer validates incoming rule `values` against `type`.
 * 3. Sanitization derives its sanitizer from `type`, never hand-written per
 *    field. See docs/CLAUDE.md -> Registry invariants.
 *
 * A field name obeys the same grammar as a registry key: lowercase snake_case,
 * see {@see Condition::is_valid_key()}.
 *
 * ```php
 * array(
 *     'match' => array(
 *         'type'    => 'enum',
 *         'enum'    => array( 'exact', 'prefix', 'contains', 'glob' ),
 *         'default' => 'exact',
 *         'label'   => __( 'Match type', 'popkit' ),
 *         'control' => 'select',
 *     ),
 *     'value' => array(
 *         'type'       => 'string',
 *         'default'    => '',
 *         'label'      => __( 'Path', 'popkit' ),
 *         'control'    => 'url-match',
 *         'max_length' => Url_Matcher::MAX_VALUE_LENGTH,
 *     ),
 * )
 * ```
 *
 * Recognized schema keys, and nothing else:
 *
 * - `type` — **required**. One of {@see Condition::FIELD_TYPES}: `string`,
 *   `integer`, `number`, `boolean`, `array`, `enum`.
 * - `items` — **required when `type` is `array`, forbidden otherwise**. One of
 *   {@see Condition::FIELD_ITEM_TYPES}: `string` or `integer`. Arrays are flat
 *   lists of one scalar type; there are no arrays of objects and no mixed
 *   arrays, because a sanitizer derived from a declaration cannot bound a shape
 *   the declaration does not describe.
 * - `enum` — **required when `type` is `enum`, forbidden otherwise**. A
 *   non-empty list of string or integer values. The stored value must be one of
 *   them.
 * - `enum_labels` — optional, permitted only when `type` is `enum`. A map from
 *   each permitted value to the human-readable, already-translated string the
 *   editor shows in its place. Without it both editors label an option with the
 *   stored token itself, so an author picking how a URL is compared reads
 *   `prefix` and `glob` rather than "Starts with" and "Wildcard pattern".
 *
 *   It must cover **every** member and name **no** value outside the list.
 *   Partial coverage is rejected rather than filled in, because a half-labeled
 *   select renders some options as prose and the rest as identifiers, and the
 *   one it forgot is invariably the one added last.
 * - `label` — **required**. Already translated by the caller; this class does
 *   not translate it and does not escape it, because escaping belongs at
 *   output. An empty label is rejected: an unlabeled control is an
 *   accessibility failure, and accessibility is this plugin's differentiator.
 * - `control` — **required**. One of {@see Condition::FIELD_CONTROLS}. The
 *   editor renders from the shared control map only; a registration may not
 *   ship its own UI. Adding a control means adding it here *and* to that map,
 *   which is deliberately a reviewable act.
 * - `default` — optional. Must match the declared `type`, or be `null` to mean
 *   no default. A `default` of the wrong type would be handed straight to a
 *   type-derived sanitizer.
 * - `min` / `max` — optional, permitted only on `integer` and `number`.
 *   Inclusive numeric bounds. Integers for `integer`; integers or floats for
 *   `number`. `min` may not exceed `max`.
 * - `max_length` — optional, permitted only on `string`. A positive integer
 *   giving the longest value the field accepts, measured in **bytes**, matching
 *   how {@see \Popkit\Url_Matcher::MAX_VALUE_LENGTH} is measured. This is the
 *   only way to declare the documented length cap on a `url_path` or `referrer`
 *   `value`; a `string` field without it is stored uncapped. A declared
 *   `default` must itself fit within the cap, for the same reason `min` may not
 *   exceed `max`: a bound no value could satisfy is a registration bug, not a
 *   runtime one.
 *
 * Any other key is rejected. A typo such as `lable` would otherwise sit in the
 * schema doing nothing while the control rendered unlabeled — the same class of
 * silent failure as a mistyped capability map key.
 *
 * ## What this class is not responsible for
 *
 * It evaluates nothing, knows nothing about `negate`, and never touches stored
 * rule values. Unknown rule types are preserved by sanitization precisely
 * because they have no `Condition` to validate against.
 *
 * @since 0.1.0
 */
final class Condition {

	/**
	 * Maximum length in bytes of a registry key or a field name.
	 *
	 * Registry keys are stored verbatim as a rule's `type` and as an object key
	 * in the emitted config, so they are bounded like every other stored value.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_KEY_LENGTH = 64;

	/**
	 * Editor groups a condition may belong to.
	 *
	 * `content` conditions describe the page; `visitor` conditions describe who
	 * is looking at it. The grouping is presentational and does not imply a
	 * context. The two correlate for the built-in set, but an extension may
	 * register a server-context `visitor` condition that is a pure function of
	 * the URL.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const GROUPS = array( 'content', 'visitor' );

	/**
	 * Permitted values for a field schema's `type`.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const FIELD_TYPES = array( 'string', 'integer', 'number', 'boolean', 'array', 'enum' );

	/**
	 * Permitted values for a field schema's `items`.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const FIELD_ITEM_TYPES = array( 'string', 'integer' );

	/**
	 * Permitted values for a field schema's `control`.
	 *
	 * Mirrors the shared control map consumed by the editor sidebar. A control
	 * name absent from this list has no renderer, so accepting it would produce
	 * an empty panel rather than an error.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const FIELD_CONTROLS = array(
		'text',
		'number',
		'range',
		'toggle',
		'select',
		'multiselect',
		'post-type-select',
		'taxonomy-select',
		'date-time',
		'url-match',
		'color',
	);

	/**
	 * Every key a field schema may declare.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const FIELD_SCHEMA_KEYS = array( 'type', 'items', 'enum', 'enum_labels', 'default', 'label', 'control', 'min', 'max', 'max_length' );

	/**
	 * Field types that may declare `min` and `max`.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const BOUNDED_FIELD_TYPES = array( 'integer', 'number' );

	/**
	 * Field types that may declare `max_length`.
	 *
	 * Only `string`. An array's length is not expressible here: `max_length`
	 * names one meaning, the longest a single stored string may be, and a second
	 * meaning on the same key would leave sanitization guessing which one a given
	 * field intended — the same reasoning that keeps `min` and `max` numeric.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const LENGTH_CAPPED_FIELD_TYPES = array( 'string' );

	/**
	 * Characters a registry key or field name may contain.
	 *
	 * Used with strspn() for a linear character-class test. The security
	 * invariant in docs/CLAUDE.md bans PCRE on user-supplied patterns, and this
	 * plugin does not reach for a regular expression merely because a particular
	 * validation happens to be developer-facing. A reviewer grepping this
	 * repository for PCRE calls should find none at all.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const KEY_CHARACTERS = 'abcdefghijklmnopqrstuvwxyz0123456789_';

	/**
	 * Characters a registry key or field name may begin with.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const KEY_FIRST_CHARACTERS = 'abcdefghijklmnopqrstuvwxyz';

	/**
	 * Builds and validates a condition.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $key     Unique registry key, lowercase snake_case. Stored
	 *                         verbatim as a rule's `type`.
	 * @param Context $context Whether the condition is decided on the server or
	 *                         in the browser.
	 * @param string  $group   Editor group: `content` or `visitor`.
	 * @param string  $label   Translated, human-readable name for the editor.
	 * @param array   $fields  Field name => field schema. May be empty for a
	 *                         condition that takes no configuration, such as
	 *                         `is_front_page`.
	 *
	 * @throws \InvalidArgumentException When any argument violates the contract
	 *                                   documented on this class.
	 */
	public function __construct(
		public readonly string $key,
		public readonly Context $context,
		public readonly string $group,
		public readonly string $label,
		public readonly array $fields
	) {
		if ( ! self::is_valid_key( $key ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: 1: the rejected condition key, 2: maximum key length. */
						__( 'A popkit condition key must be lowercase snake_case: it starts with a letter, contains only a-z, 0-9 and single underscores, does not end in an underscore, and is at most %2$d characters long. Received `%1$s`.', 'popkit' ),
						$key,
						self::MAX_KEY_LENGTH
					)
				)
			);
		}

		if ( ! in_array( $group, self::GROUPS, true ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: 1: condition key, 2: the rejected group, 3: comma-separated list of permitted groups. */
						__( 'The popkit condition `%1$s` declares the unknown group `%2$s`. Permitted groups are: %3$s.', 'popkit' ),
						$key,
						$group,
						implode( ', ', self::GROUPS )
					)
				)
			);
		}

		if ( '' === trim( $label ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: %s: condition key. */
						__( 'The popkit condition `%s` must declare a non-empty label. The editor has no other way to name its control, and an unlabeled control is not accessible.', 'popkit' ),
						$key
					)
				)
			);
		}

		self::validate_field_schemas( $key, $fields );
	}

	/**
	 * Returns the JSON-serializable shape consumed by REST and the editor.
	 *
	 * `context` is flattened to its backed string value so the payload survives
	 * a JSON round trip without a lookup table on the client.
	 *
	 * `fields` is returned as a PHP array. A condition with no fields therefore
	 * yields an empty array, which `wp_json_encode()` renders as `[]` rather
	 * than `{}`; a caller that needs a JSON object for an empty map casts it at
	 * encode time, because casting here would break array comparison for every
	 * other consumer.
	 *
	 * Nothing is escaped here. This is data, not output; escaping happens at the
	 * point of rendering.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Registry schema for one condition.
	 */
	public function to_schema(): array {
		return array(
			'key'     => $this->key,
			'context' => $this->context->value,
			'group'   => $this->group,
			'label'   => $this->label,
			'fields'  => $this->fields,
		);
	}

	/**
	 * Whether a string is usable as a registry key or a field name.
	 *
	 * The grammar is lowercase snake_case: it begins with a letter, contains
	 * only `a-z`, `0-9` and underscores, never has two adjacent underscores,
	 * never ends in one, and is at most {@see Condition::MAX_KEY_LENGTH} bytes.
	 *
	 * Tested with strspn() and strpos() rather than a regular expression, for
	 * the reason given on {@see Condition::KEY_CHARACTERS}.
	 *
	 * Shared with {@see \Popkit\Trigger}, which registers under the same
	 * grammar. There is no base class: docs/CLAUDE.md prefers composition, so
	 * the rule lives once, here, and Trigger calls it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Candidate key or field name.
	 * @return bool True when the key is well-formed.
	 */
	public static function is_valid_key( string $key ): bool {
		$length = strlen( $key );

		if ( 0 === $length || self::MAX_KEY_LENGTH < $length ) {
			return false;
		}

		if ( strspn( $key, self::KEY_CHARACTERS ) !== $length ) {
			return false;
		}

		if ( 1 !== strspn( $key, self::KEY_FIRST_CHARACTERS, 0, 1 ) ) {
			return false;
		}

		if ( '_' === $key[ $length - 1 ] ) {
			return false;
		}

		return false === strpos( $key, '__' );
	}

	/**
	 * Validates a map of field name => field schema.
	 *
	 * Public and static because {@see \Popkit\Trigger} declares its fields with
	 * exactly the same vocabulary. Keeping one implementation means the lists of
	 * permitted types and controls cannot drift between the two registries,
	 * which is what makes the phrase "single source of truth" mean something.
	 *
	 * @since 0.1.0
	 *
	 * @param string $owner_key Registry key of the condition or trigger being
	 *                          registered. Used only so the error message can
	 *                          locate the problem.
	 * @param array  $fields    Field name => field schema.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When any field name or schema is invalid.
	 */
	public static function validate_field_schemas( string $owner_key, array $fields ): void {
		foreach ( $fields as $field_name => $schema ) {
			if ( ! is_string( $field_name ) || ! self::is_valid_key( $field_name ) ) {
				throw new \InvalidArgumentException(
					esc_html(
						sprintf(
							/* translators: 1: registry key being registered, 2: the rejected field name. */
							__( 'The popkit registration `%1$s` declares the field name %2$s, which is not lowercase snake_case. Field names follow the same grammar as registry keys.', 'popkit' ),
							$owner_key,
							self::describe( $field_name )
						)
					)
				);
			}

			if ( ! is_array( $schema ) ) {
				throw new \InvalidArgumentException(
					esc_html(
						sprintf(
							/* translators: 1: registry key being registered, 2: field name, 3: the type received instead of an array. */
							__( 'The popkit registration `%1$s` declares the field `%2$s` as %3$s. A field must be declared as an array describing its schema.', 'popkit' ),
							$owner_key,
							$field_name,
							get_debug_type( $schema )
						)
					)
				);
			}

			self::validate_field_schema( $owner_key, $field_name, $schema );
		}
	}

	/**
	 * Validates one field schema.
	 *
	 * The order of the checks below is load bearing: `items` and `enum` are
	 * settled before `default`, because a default is judged against them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $owner_key  Registry key of the condition or trigger.
	 * @param string $field_name Field name the schema belongs to.
	 * @param array  $schema     Field schema.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the schema is invalid.
	 */
	private static function validate_field_schema( string $owner_key, string $field_name, array $schema ): void {
		$unknown_keys = array_diff( array_keys( $schema ), self::FIELD_SCHEMA_KEYS );

		if ( ! empty( $unknown_keys ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: 1: comma-separated list of unrecognized schema keys, 2: comma-separated list of permitted schema keys. */
							__( 'It declares the unrecognized schema key or keys %1$s. Permitted keys are: %2$s. A mistyped key would sit in the schema doing nothing.', 'popkit' ),
							implode( ', ', array_map( self::describe( ... ), $unknown_keys ) ),
							implode( ', ', self::FIELD_SCHEMA_KEYS )
						)
					)
				)
			);
		}

		if ( ! array_key_exists( 'type', $schema ) || ! in_array( $schema['type'], self::FIELD_TYPES, true ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: %s: comma-separated list of permitted field types. */
							__( 'It must declare a type of one of: %s. Sanitization is derived from that declaration, so a field without one cannot be stored safely.', 'popkit' ),
							implode( ', ', self::FIELD_TYPES )
						)
					)
				)
			);
		}

		$type = $schema['type'];

		self::validate_items( $owner_key, $field_name, $schema, $type );
		self::validate_enum( $owner_key, $field_name, $schema, $type );
		self::validate_enum_labels( $owner_key, $field_name, $schema, $type );
		self::validate_label_and_control( $owner_key, $field_name, $schema );
		self::validate_default( $owner_key, $field_name, $schema, $type );
		self::validate_bounds( $owner_key, $field_name, $schema, $type );
		self::validate_max_length( $owner_key, $field_name, $schema, $type );
	}

	/**
	 * Validates the `items` key against the declared type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $owner_key  Registry key of the condition or trigger.
	 * @param string $field_name Field name the schema belongs to.
	 * @param array  $schema     Field schema.
	 * @param string $type       Declared field type.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When `items` is missing, misplaced or invalid.
	 */
	private static function validate_items( string $owner_key, string $field_name, array $schema, string $type ): void {
		$declared = array_key_exists( 'items', $schema );

		if ( 'array' !== $type ) {
			if ( $declared ) {
				throw new \InvalidArgumentException(
					esc_html(
						self::field_message(
							$owner_key,
							$field_name,
							sprintf(
								/* translators: %s: declared field type. */
								__( 'It declares items but its type is `%s`. Only an array field describes its items.', 'popkit' ),
								$type
							)
						)
					)
				);
			}

			return;
		}

		if ( ! $declared || ! in_array( $schema['items'], self::FIELD_ITEM_TYPES, true ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: %s: comma-separated list of permitted item types. */
							__( 'An array field must declare items as one of: %s. Arrays are flat lists of one scalar type.', 'popkit' ),
							implode( ', ', self::FIELD_ITEM_TYPES )
						)
					)
				)
			);
		}
	}

	/**
	 * Validates the `enum` key against the declared type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $owner_key  Registry key of the condition or trigger.
	 * @param string $field_name Field name the schema belongs to.
	 * @param array  $schema     Field schema.
	 * @param string $type       Declared field type.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When `enum` is missing, misplaced or invalid.
	 */
	private static function validate_enum( string $owner_key, string $field_name, array $schema, string $type ): void {
		$declared = array_key_exists( 'enum', $schema );

		if ( 'enum' !== $type ) {
			if ( $declared ) {
				throw new \InvalidArgumentException(
					esc_html(
						self::field_message(
							$owner_key,
							$field_name,
							sprintf(
								/* translators: %s: declared field type. */
								__( 'It declares enum but its type is `%s`. Only an enum field lists its permitted values.', 'popkit' ),
								$type
							)
						)
					)
				);
			}

			return;
		}

		$values = $declared ? $schema['enum'] : null;

		if ( ! is_array( $values ) || array() === $values || ! array_is_list( $values ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						__( 'An enum field must declare enum as a non-empty list of permitted values.', 'popkit' )
					)
				)
			);
		}

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				throw new \InvalidArgumentException(
					esc_html(
						self::field_message(
							$owner_key,
							$field_name,
							sprintf(
								/* translators: %s: the offending enum value type. */
								__( 'Its enum list contains a value of type %s. Permitted values must be strings or integers so they survive a JSON round trip.', 'popkit' ),
								get_debug_type( $value )
							)
						)
					)
				);
			}
		}
	}

	/**
	 * Validates the optional `enum_labels` map against the declared values.
	 *
	 * Coverage is required in both directions: every permitted value must be
	 * labeled, and no label may name a value that is not permitted. Each half
	 * catches a different mistake. A missing label renders that one option as its
	 * stored token beside options that read as prose, which looks like a bug in
	 * the option rather than in the registration. A label for a value that was
	 * renamed or removed is dead weight that silently stops applying, and the
	 * author sees the raw token again with nothing to explain why.
	 *
	 * Membership is compared on string casts, because PHP normalizes a numeric
	 * string array key to an integer: a registration writing `'1' => 'Monday'`
	 * against an enum of `array( 1, 2 )` has declared the label it meant, and
	 * rejecting it for the key type PHP chose would be an error about nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param string $owner_key  Registry key of the condition or trigger.
	 * @param string $field_name Field name the schema belongs to.
	 * @param array  $schema     Field schema.
	 * @param string $type       Declared field type.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the map is misplaced, mistyped, or
	 *                                   does not correspond exactly to the enum.
	 */
	private static function validate_enum_labels( string $owner_key, string $field_name, array $schema, string $type ): void {
		if ( ! array_key_exists( 'enum_labels', $schema ) ) {
			return;
		}

		if ( 'enum' !== $type ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: %s: declared field type. */
							__( 'It declares enum_labels but its type is `%s`. Only an enum field names its permitted values, so only an enum field can label them.', 'popkit' ),
							$type
						)
					)
				)
			);
		}

		$labels = $schema['enum_labels'];

		if ( ! is_array( $labels ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: %s: the type received instead of an array. */
							__( 'Its enum_labels is %s. It must be an array mapping each permitted value to the text the editor shows for it.', 'popkit' ),
							get_debug_type( $labels )
						)
					)
				)
			);
		}

		$members = array_map( 'strval', $schema['enum'] );
		$labeled = array_map( 'strval', array_keys( $labels ) );

		$unlabeled = array_diff( $members, $labeled );

		if ( array() !== $unlabeled ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: %s: comma-separated list of permitted values with no label. */
							__( 'Its enum_labels does not label the value or values %s. Every permitted value must be labeled, so that a select never mixes readable options with stored tokens.', 'popkit' ),
							implode( ', ', array_map( self::describe( ... ), $unlabeled ) )
						)
					)
				)
			);
		}

		$strays = array_diff( $labeled, $members );

		if ( array() !== $strays ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: 1: comma-separated list of labeled values that are not permitted, 2: comma-separated list of permitted values. */
							__( 'Its enum_labels labels %1$s, which is not among its permitted values: %2$s. A label for a value the field cannot hold never applies.', 'popkit' ),
							implode( ', ', array_map( self::describe( ... ), $strays ) ),
							implode( ', ', array_map( self::describe( ... ), $members ) )
						)
					)
				)
			);
		}

		foreach ( $labels as $value => $label ) {
			if ( ! is_string( $label ) || '' === trim( $label ) ) {
				throw new \InvalidArgumentException(
					esc_html(
						self::field_message(
							$owner_key,
							$field_name,
							sprintf(
								/* translators: 1: the enum value whose label is invalid, 2: the type received instead of a non-empty string. */
								__( 'Its enum_labels entry for %1$s is %2$s. A label must be a non-empty, already-translated string.', 'popkit' ),
								self::describe( $value ),
								is_string( $label ) ? __( 'blank', 'popkit' ) : get_debug_type( $label )
							)
						)
					)
				);
			}
		}
	}

	/**
	 * Validates the `label` and `control` keys.
	 *
	 * @since 0.1.0
	 *
	 * @param string $owner_key  Registry key of the condition or trigger.
	 * @param string $field_name Field name the schema belongs to.
	 * @param array  $schema     Field schema.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When either key is missing or invalid.
	 */
	private static function validate_label_and_control( string $owner_key, string $field_name, array $schema ): void {
		$label = $schema['label'] ?? null;

		if ( ! is_string( $label ) || '' === trim( $label ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						__( 'It must declare a non-empty label. The editor renders the control from this schema alone, and a control without a label is not accessible.', 'popkit' )
					)
				)
			);
		}

		if ( ! array_key_exists( 'control', $schema ) || ! in_array( $schema['control'], self::FIELD_CONTROLS, true ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: %s: comma-separated list of permitted controls. */
							__( 'It must declare a control of one of: %s. Registrations render through the shared control map and may not ship an interface of their own.', 'popkit' ),
							implode( ', ', self::FIELD_CONTROLS )
						)
					)
				)
			);
		}
	}

	/**
	 * Validates that `default`, when present, matches the declared type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $owner_key  Registry key of the condition or trigger.
	 * @param string $field_name Field name the schema belongs to.
	 * @param array  $schema     Field schema.
	 * @param string $type       Declared field type.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the default does not match the type.
	 */
	private static function validate_default( string $owner_key, string $field_name, array $schema, string $type ): void {
		if ( ! array_key_exists( 'default', $schema ) ) {
			return;
		}

		$default = $schema['default'];

		if ( null === $default ) {
			return;
		}

		$matches = match ( $type ) {
			'string'  => is_string( $default ),
			'integer' => is_int( $default ),
			'number'  => is_int( $default ) || is_float( $default ),
			'boolean' => is_bool( $default ),
			'array'   => self::is_list_of( $default, $schema['items'] ),
			'enum'    => in_array( $default, $schema['enum'], true ),
		};

		if ( $matches ) {
			return;
		}

		/*
		 * Reporting only the declared type would be useless for `enum` and
		 * `array`, where a default of the right PHP type can still be wrong.
		 */
		$problem = match ( $type ) {
			'enum'  => sprintf(
				/* translators: 1: the rejected default value, 2: comma-separated list of permitted values. */
				__( 'Its default %1$s is not one of the permitted values: %2$s. Use null to mean no default.', 'popkit' ),
				self::describe( $default ),
				implode( ', ', array_map( self::describe( ... ), $schema['enum'] ) )
			),
			'array' => sprintf(
				/* translators: %s: declared item type, either string or integer. */
				__( 'Its default must be a flat list of %s values. Use null to mean no default.', 'popkit' ),
				$schema['items']
			),
			default => sprintf(
				/* translators: 1: type of the supplied default, 2: declared field type. */
				__( 'Its default is %1$s, which does not satisfy the declared type `%2$s`. Use null to mean no default.', 'popkit' ),
				get_debug_type( $default ),
				$type
			),
		};

		throw new \InvalidArgumentException( esc_html( self::field_message( $owner_key, $field_name, $problem ) ) );
	}

	/**
	 * Validates the optional `min` and `max` bounds.
	 *
	 * Bounds are inclusive and permitted only on numeric fields. String length is
	 * deliberately not expressible through the same two keys: a second meaning
	 * would leave sanitization guessing which one a given field intended, so a
	 * length cap is declared as `max_length` instead. See
	 * {@see Condition::validate_max_length()}.
	 *
	 * @since 0.1.0
	 *
	 * @param string $owner_key  Registry key of the condition or trigger.
	 * @param string $field_name Field name the schema belongs to.
	 * @param array  $schema     Field schema.
	 * @param string $type       Declared field type.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When bounds are misplaced, mistyped or inverted.
	 */
	private static function validate_bounds( string $owner_key, string $field_name, array $schema, string $type ): void {
		$has_min = array_key_exists( 'min', $schema );
		$has_max = array_key_exists( 'max', $schema );

		if ( ! $has_min && ! $has_max ) {
			return;
		}

		if ( ! in_array( $type, self::BOUNDED_FIELD_TYPES, true ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: 1: declared field type, 2: comma-separated list of types that accept bounds. */
							__( 'It declares min or max but its type is `%1$s`. Bounds are numeric and are permitted only on: %2$s.', 'popkit' ),
							$type,
							implode( ', ', self::BOUNDED_FIELD_TYPES )
						)
					)
				)
			);
		}

		foreach ( array( 'min', 'max' ) as $bound ) {
			if ( array_key_exists( $bound, $schema ) && ! self::is_valid_bound( $schema[ $bound ], $type ) ) {
				throw new \InvalidArgumentException(
					esc_html(
						self::field_message(
							$owner_key,
							$field_name,
							sprintf(
								/* translators: 1: bound key, either min or max, 2: type of the supplied bound, 3: declared field type. */
								__( 'Its %1$s bound is %2$s. A bound on a field of type `%3$s` must be a number.', 'popkit' ),
								$bound,
								get_debug_type( $schema[ $bound ] ),
								$type
							)
						)
					)
				);
			}
		}

		if ( $has_min && $has_max && $schema['min'] > $schema['max'] ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						__( 'Its min bound is greater than its max bound, so no value could ever satisfy it.', 'popkit' )
					)
				)
			);
		}
	}

	/**
	 * Validates the optional `max_length` cap.
	 *
	 * The cap is the vocabulary's only way to say how long a stored string may
	 * be, so a `string` field that omits it is stored uncapped. It is measured in
	 * bytes, matching {@see \Popkit\Url_Matcher::MAX_VALUE_LENGTH}, so that the
	 * declared cap on a `url_path` or `referrer` `value` and the cap the matcher
	 * enforces at runtime are the same number read the same way.
	 *
	 * A cap of zero or less is rejected rather than honored: it would describe a
	 * field that can never hold a usable value, which is a registration mistake
	 * every time.
	 *
	 * @since 0.1.0
	 *
	 * @param string $owner_key  Registry key of the condition or trigger.
	 * @param string $field_name Field name the schema belongs to.
	 * @param array  $schema     Field schema.
	 * @param string $type       Declared field type.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the cap is misplaced, mistyped, not
	 *                                   positive, or shorter than the declared default.
	 */
	private static function validate_max_length( string $owner_key, string $field_name, array $schema, string $type ): void {
		if ( ! array_key_exists( 'max_length', $schema ) ) {
			return;
		}

		if ( ! in_array( $type, self::LENGTH_CAPPED_FIELD_TYPES, true ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: 1: declared field type, 2: comma-separated list of types that accept a length cap. */
							__( 'It declares max_length but its type is `%1$s`. A length cap measures a stored string and is permitted only on: %2$s.', 'popkit' ),
							$type,
							implode( ', ', self::LENGTH_CAPPED_FIELD_TYPES )
						)
					)
				)
			);
		}

		$max_length = $schema['max_length'];

		if ( ! is_int( $max_length ) || 1 > $max_length ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: %s: the rejected max_length value. */
							__( 'Its max_length is %s. A length cap must be a positive integer number of bytes.', 'popkit' ),
							self::describe( $max_length )
						)
					)
				)
			);
		}

		$default = $schema['default'] ?? null;

		if ( is_string( $default ) && strlen( $default ) > $max_length ) {
			throw new \InvalidArgumentException(
				esc_html(
					self::field_message(
						$owner_key,
						$field_name,
						sprintf(
							/* translators: 1: length of the declared default in bytes, 2: the declared max_length. */
							__( 'Its default is %1$d bytes long, which exceeds its max_length of %2$d. A default that the field itself would reject can never be stored.', 'popkit' ),
							strlen( $default ),
							$max_length
						)
					)
				)
			);
		}
	}

	/**
	 * Whether a bound value is acceptable for the declared type.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $bound Bound value as declared.
	 * @param string $type  Declared field type.
	 * @return bool True when the bound is usable.
	 */
	private static function is_valid_bound( $bound, string $type ): bool {
		if ( is_int( $bound ) ) {
			return true;
		}

		return 'number' === $type && is_float( $bound );
	}

	/**
	 * Whether a value is a flat list of the given scalar item type.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $value     Value to test.
	 * @param string $item_type Either `string` or `integer`.
	 * @return bool True when every item matches.
	 */
	private static function is_list_of( $value, string $item_type ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			$matches = 'integer' === $item_type ? is_int( $item ) : is_string( $item );

			if ( ! $matches ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Builds an exception message naming the registration and the field.
	 *
	 * Assembled from placeholders rather than by concatenating fragments, so
	 * each sentence stays translatable as a whole.
	 *
	 * @since 0.1.0
	 *
	 * @param string $owner_key  Registry key of the condition or trigger.
	 * @param string $field_name Field name the schema belongs to.
	 * @param string $problem    Translated sentence describing the problem.
	 * @return string Complete message.
	 */
	private static function field_message( string $owner_key, string $field_name, string $problem ): string {
		return sprintf(
			/* translators: 1: registry key being registered, 2: field name, 3: sentence describing the problem. */
			__( 'The popkit registration `%1$s` declares an invalid schema for the field `%2$s`. %3$s', 'popkit' ),
			$owner_key,
			$field_name,
			$problem
		);
	}

	/**
	 * Renders any value as a short, delimited description for an error message.
	 *
	 * Scalars are shown as they were given so a typo is obvious; anything else
	 * is reduced to its type name, so an exception message can never carry a
	 * whole payload.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value Value to describe.
	 * @return string Human-readable description.
	 */
	private static function describe( $value ): string {
		if ( is_string( $value ) || is_int( $value ) ) {
			return '`' . $value . '`';
		}

		return get_debug_type( $value );
	}
}
