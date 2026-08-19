<?php
/**
 * Unit tests for schema-derived sanitization.
 *
 * Three properties are load bearing here, and each has a failure mode that is
 * silent in production:
 *
 * 1. **Coercion follows the declared type.** `docs/CLAUDE.md` -> Security
 *    requires sanitization to be derived from a field's declaration rather than
 *    hand-written per field, so every declared type is exercised against the
 *    values a form post and a JSON body actually produce — strings where
 *    integers were declared, integers where booleans were declared, and so on.
 *
 * 2. **Booleans are read the way the storage layer will read them.** A plain
 *    cast makes the string `'false'` true. The tests below assert the falsey
 *    forms hard, because a boolean that reads as enabled when its stored value
 *    says otherwise is how a popup targets the wrong audience and how an
 *    irreversible action reads as authorized.
 *
 * 3. **An unregistered rule survives intact or is refused whole.** Nothing in
 *    between. `docs/data-model.md` -> Bounds on unknown rule values is explicit
 *    that a partially preserved targeting rule is more dangerous than a refused
 *    one, because it looks intact. So the round trip is asserted byte for byte,
 *    and the refusal is asserted to leave no remnant of what it refused.
 *
 * The suite runs without WordPress. `Popkit\Sanitizer` reaches the registry
 * through `popkit_conditions()`, which holds one process-wide instance, so the
 * probe condition registers under a `popkit_test_` key and registration is
 * guarded.
 *
 * @package Popkit
 */

use PHPUnit\Framework\TestCase;
use Popkit\Condition;
use Popkit\Context;
use Popkit\Rule_Bounds;
use Popkit\Sanitizer;

/*
 * Load the registry accessor. It declares functions rather than a class, so no
 * autoloader can reach it — production requires it explicitly too, from
 * Popkit\Plugin::boot().
 */
if ( ! function_exists( 'popkit_conditions' ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/functions.php';
}

/*
 * Load the subject directly, so this file is a genuine unit test rather than a
 * test of whatever the bootstrap happened to autoload.
 */
if ( ! class_exists( Sanitizer::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/class-sanitizer.php';
}

/**
 * Asserts how submitted values become stored values.
 */
final class Test_Popkit_Sanitizer extends TestCase {

	/**
	 * Key of the registered condition used to exercise the schema-driven path.
	 *
	 * @var string
	 */
	private const PROBE_KEY = 'popkit_test_sanitizer_probe';

	/**
	 * A condition key nothing registers, standing in for a deactivated extension.
	 *
	 * @var string
	 */
	private const UNKNOWN_KEY = 'popkit_test_sanitizer_absent';

	/**
	 * Registers the probe condition in the shared registry.
	 *
	 * Its fields cover every type a registration may declare, using the exact
	 * schema vocabulary {@see Popkit\Condition} accepts — `min` and `max` rather
	 * than `minimum` and `maximum`, and `items` as a bare type name. Declaring
	 * them any other way would test a schema the plugin cannot actually register.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$registry = popkit_conditions();

		if ( $registry->is_registered( self::PROBE_KEY ) ) {
			return;
		}

		$registry->register(
			new Condition(
				self::PROBE_KEY,
				Context::Server,
				'content',
				'Test sanitizer probe',
				array(
					'text'  => array(
						'type'    => 'string',
						'label'   => 'Text',
						'control' => 'text',
					),
					'count' => array(
						'type'    => 'integer',
						'label'   => 'Count',
						'control' => 'number',
						'min'     => 1,
						'max'     => 10,
						'default' => 3,
					),
					'ratio' => array(
						'type'    => 'number',
						'label'   => 'Ratio',
						'control' => 'range',
						'min'     => 0,
						'max'     => 1,
					),
					'flag'  => array(
						'type'    => 'boolean',
						'label'   => 'Flag',
						'control' => 'toggle',
					),
					'mode'  => array(
						'type'    => 'enum',
						'label'   => 'Mode',
						'control' => 'select',
						'enum'    => array( 'fade', 'slide' ),
						'default' => 'slide',
					),
					'ids'   => array(
						'type'    => 'array',
						'label'   => 'Post IDs',
						'control' => 'multiselect',
						'items'   => 'integer',
					),
				)
			)
		);
	}

	/**
	 * A string field is sanitized and, when the schema says so, truncated.
	 *
	 * @return void
	 */
	public function test_a_string_field_is_sanitized_and_truncated() {
		$this->assertSame(
			'hi there you',
			Sanitizer::sanitize_value( "  hi <b>there</b>\n\tyou  ", array( 'type' => 'string' ) ),
			'A string field passes through sanitize_text_field(): tags removed, whitespace runs collapsed, ends trimmed.'
		);

		$this->assertSame(
			'abcde',
			Sanitizer::sanitize_value(
				'abcdefghij',
				array(
					'type'      => 'string',
					'maxLength' => 5,
				)
			),
			'The canonical JSON Schema spelling of the length cap is honored.'
		);

		$this->assertSame(
			'abcde',
			Sanitizer::sanitize_value(
				'abcdefghij',
				array(
					'type'       => 'string',
					'max_length' => 5,
				)
			),
			'The snake_case alias is honored too, so a registration array reads naturally.'
		);

		$this->assertIsString(
			Sanitizer::sanitize_value( array( 'not', 'a', 'string' ), array( 'type' => 'string' ) ),
			'A string field handed an array must still store a string. Storing the array would put a shape in the database that nothing downstream can read.'
		);
	}

	/**
	 * An integer field is coerced to an integer and clamped to its declared range.
	 *
	 * @dataProvider data_integer_values
	 *
	 * @param mixed $raw      Submitted value.
	 * @param array $schema   Field schema.
	 * @param int   $expected Expected stored value.
	 * @return void
	 */
	public function test_an_integer_field_is_coerced_and_clamped( $raw, $schema, $expected ) {
		$actual = Sanitizer::sanitize_value( $raw, $schema );

		$this->assertIsInt( $actual, 'An integer field must store an integer, not a numeric string.' );
		$this->assertSame( $expected, $actual, 'Coercion and clamping are both derived from the declared schema.' );
	}

	/**
	 * A number field is coerced to a float and clamped to its declared range.
	 *
	 * @dataProvider data_number_values
	 *
	 * @param mixed $raw      Submitted value.
	 * @param array $schema   Field schema.
	 * @param float $expected Expected stored value.
	 * @return void
	 */
	public function test_a_number_field_is_coerced_and_clamped( $raw, $schema, $expected ) {
		$actual = Sanitizer::sanitize_value( $raw, $schema );

		$this->assertIsFloat( $actual, 'A number field must store a float.' );
		$this->assertSame( $expected, $actual, 'Coercion and clamping are both derived from the declared schema.' );
	}

	/**
	 * Every form of "off" a stored or submitted value can take reads as false.
	 *
	 * This is the assertion that matters most in the file. `'0'` and `'false'`
	 * are both truthy under a plain PHP cast, and a field that reads as enabled
	 * when its stored value says otherwise is how a popup targets the wrong
	 * audience — and, wherever a boolean guards a destructive action, how that
	 * action reads as authorized.
	 *
	 * @dataProvider data_false_values
	 *
	 * @param mixed $raw Submitted value.
	 * @return void
	 */
	public function test_a_boolean_field_reads_every_falsey_form_as_false( $raw ) {
		$actual = Sanitizer::sanitize_value( $raw, array( 'type' => 'boolean' ) );

		$this->assertIsBool( $actual, 'A boolean field must store a real boolean.' );
		$this->assertFalse( $actual, 'This value says "off". Reading it as true would silently enable whatever it guards.' );
	}

	/**
	 * Every form of "on" reads as true.
	 *
	 * @dataProvider data_true_values
	 *
	 * @param mixed $raw Submitted value.
	 * @return void
	 */
	public function test_a_boolean_field_reads_every_truthy_form_as_true( $raw ) {
		$actual = Sanitizer::sanitize_value( $raw, array( 'type' => 'boolean' ) );

		$this->assertIsBool( $actual, 'A boolean field must store a real boolean.' );
		$this->assertTrue( $actual, 'This value says "on".' );
	}

	/**
	 * Boolean coercion matches core's REST semantics, including where they surprise.
	 *
	 * `rest_sanitize_boolean()` special-cases exactly two strings, `'false'` and
	 * `'0'`, and casts everything else. So `'no'` and `'off'` are **true**.
	 * popkit replicates that rather than inventing its own list, because meta is
	 * registered with `show_in_rest` and core sanitizes a REST write before
	 * popkit ever sees it: a stricter list here would make the same payload
	 * store differently depending on which path it arrived through.
	 *
	 * Two other coercions in the plugin use `filter_var()` instead —
	 * `Popkit\Settings::to_bool()` and `Popkit\Rule_Evaluator::rule_is_negated()`
	 * — and those *do* read `'no'` and `'off'` as false. The divergence is
	 * deliberate at both ends but is asserted here so it stays visible: nothing
	 * in popkit's own editor emits these strings, and if that ever changes, this
	 * test is the one that has to be argued with first.
	 *
	 * @return void
	 */
	public function test_boolean_coercion_matches_core_rest_semantics() {
		foreach ( array( 'no', 'off', 'n', '0.0' ) as $raw ) {
			$this->assertTrue(
				Sanitizer::sanitize_value( $raw, array( 'type' => 'boolean' ) ),
				sprintf( 'rest_sanitize_boolean() casts the string "%s" to true, and popkit stores what a REST write would store.', $raw )
			);
		}
	}

	/**
	 * An enum field always resolves to one of its permitted values.
	 *
	 * @return void
	 */
	public function test_an_enum_field_resolves_to_a_permitted_value() {
		$with_default = array(
			'type'    => 'enum',
			'enum'    => array( 'fade', 'slide' ),
			'default' => 'slide',
		);

		$this->assertSame(
			'fade',
			Sanitizer::sanitize_value( 'fade', $with_default ),
			'A permitted value is stored unchanged.'
		);

		$this->assertSame(
			'slide',
			Sanitizer::sanitize_value( 'somersault', $with_default ),
			'An unrecognized value falls back to the declared default rather than being stored.'
		);

		$this->assertSame(
			'fade',
			Sanitizer::sanitize_value(
				'somersault',
				array(
					'type' => 'enum',
					'enum' => array( 'fade', 'slide' ),
				)
			),
			'With no default declared, the first permitted value is the fallback. Storing the unrecognized value would put a state in the database that no control can render.'
		);

		$this->assertSame(
			'fade',
			Sanitizer::sanitize_value(
				'somersault',
				array(
					'type'    => 'enum',
					'enum'    => array( 'fade', 'slide' ),
					'default' => 'cartwheel',
				)
			),
			'A default that is not itself permitted cannot be the fallback either.'
		);

		$this->assertSame(
			2,
			Sanitizer::sanitize_value(
				'2',
				array(
					'type' => 'enum',
					'enum' => array( 1, 2, 3 ),
				)
			),
			'A JSON body may send an integer choice as a string. The value stored is the one the schema declared, with the type the schema declared.'
		);
	}

	/**
	 * An array field sanitizes each item and stores a list.
	 *
	 * @return void
	 */
	public function test_an_array_field_sanitizes_each_item() {
		$schema = array(
			'type'  => 'array',
			'items' => array( 'type' => 'integer' ),
		);

		$this->assertSame(
			array( 1, 2, 3 ),
			Sanitizer::sanitize_value( array( '1', 2, 3.7 ), $schema ),
			'Every item is sanitized against the item schema.'
		);

		$this->assertSame(
			array( 1, 2 ),
			Sanitizer::sanitize_value(
				array(
					'first'  => '1',
					'second' => '2',
				),
				$schema
			),
			'Items are re-indexed. A declared array is a list, and submitted keys carry no meaning the schema recognizes.'
		);

		$this->assertSame(
			array(),
			Sanitizer::sanitize_value( 'not an array', $schema ),
			'A value that is not an array cannot be coerced into one without inventing an item.'
		);
	}

	/**
	 * An array field stores items of the item type its registration declared.
	 *
	 * {@see Popkit\Condition} requires `items` to name a type — `'integer'` or
	 * `'string'` — and the registry REST route publishes it as a string. That is
	 * the only spelling a condition can actually register, and it is the spelling
	 * `post_ids` needs: `docs/data-model.md` declares that field as `ids: int[]`.
	 *
	 * Sanitization must therefore honor it. Reading `items` only as a nested
	 * schema array leaves the bare type name unrecognized, the item schema
	 * silently defaults to `string`, and every stored ID becomes `'42'` instead
	 * of `42` — a shape mismatch that survives the round trip and only surfaces
	 * downstream, wherever a strict comparison against a real post ID fails.
	 *
	 * @return void
	 */
	public function test_an_array_field_honors_the_registered_item_type() {
		$this->assertSame(
			array( 12, 34 ),
			Sanitizer::sanitize_value(
				array( '12', '34' ),
				array(
					'type'  => 'array',
					'items' => 'integer',
				)
			),
			'`items` declared as a bare type name is the registration vocabulary Condition enforces, so sanitization has to read it. Accept a string there in Sanitizer::sanitize_array() by promoting it to array( "type" => $items ).'
		);

		$rule = Sanitizer::sanitize_rule(
			array(
				'type'   => self::PROBE_KEY,
				'values' => array( 'ids' => array( '12', '34' ) ),
			)
		);

		$this->assertSame(
			array( 12, 34 ),
			$rule['values']['ids'],
			'The same through the production path: a registered condition declaring items as integer must not store its IDs as strings.'
		);
	}

	/**
	 * A schema declaring a type sanitization does not know falls back to its default.
	 *
	 * @return void
	 */
	public function test_an_unrecognized_field_type_falls_back_to_the_declared_default() {
		$this->assertSame(
			'fallback',
			Sanitizer::sanitize_value(
				'submitted',
				array(
					'type'    => 'quaternion',
					'default' => 'fallback',
				)
			),
			'Guessing at a sanitizer for an unknown type is how hand-written per-field sanitization creeps back in.'
		);

		$this->assertNull(
			Sanitizer::sanitize_value( 'submitted', array( 'type' => 'quaternion' ) ),
			'With no default to fall back to there is nothing safe to store.'
		);
	}

	/**
	 * A rule whose type is unusable is dropped rather than stored.
	 *
	 * @dataProvider data_rules_without_a_usable_type
	 *
	 * @param mixed $raw Submitted rule.
	 * @return void
	 */
	public function test_a_rule_without_a_usable_type_is_dropped( $raw ) {
		$this->assertSame(
			array(),
			Sanitizer::sanitize_rule( $raw ),
			'A rule that names no condition cannot be evaluated, emitted, or shown in the editor. It is not a rule.'
		);
	}

	/**
	 * A rule type is a key, and is stored as one.
	 *
	 * @return void
	 */
	public function test_a_rule_type_is_sanitized() {
		$rule = Sanitizer::sanitize_rule(
			array(
				'type'   => '<b>acme_thing</b>',
				'values' => array(),
			)
		);

		$this->assertSame(
			'acme_thing',
			$rule['type'],
			'The type is a key, not authored content, so sanitizing it cannot lose information a real condition key carries.'
		);
	}

	/**
	 * Negate is stored as a real boolean.
	 *
	 * @dataProvider data_negate_values
	 *
	 * @param mixed $raw      Submitted negate value.
	 * @param bool  $expected Expected stored value.
	 * @return void
	 */
	public function test_negate_is_stored_as_a_real_boolean( $raw, $expected ) {
		$rule = Sanitizer::sanitize_rule(
			array(
				'type'   => self::UNKNOWN_KEY,
				'negate' => $raw,
				'values' => array(),
			)
		);

		$this->assertIsBool( $rule['negate'], 'Negate must be stored as a boolean so nothing downstream has to guess how to read it.' );
		$this->assertSame( $expected, $rule['negate'], 'Negate inverts a rule. Reading its stored value wrongly inverts the audience of the popup.' );
	}

	/**
	 * Negate defaults to false when the rule does not declare it.
	 *
	 * @return void
	 */
	public function test_negate_defaults_to_false() {
		$rule = Sanitizer::sanitize_rule( array( 'type' => self::UNKNOWN_KEY ) );

		$this->assertFalse( $rule['negate'], 'An absent negate flag means the rule is not negated. Defaulting to true would invert every rule that omits it.' );
	}

	/**
	 * A registered rule is validated against the condition's declared fields.
	 *
	 * @return void
	 */
	public function test_a_registered_rule_is_validated_against_its_schema() {
		$rule = Sanitizer::sanitize_rule(
			array(
				'type'   => self::PROBE_KEY,
				'negate' => 'true',
				'values' => array(
					'text'   => ' <em>Give</em> today ',
					'count'  => '99',
					'ratio'  => '0.25',
					'flag'   => 'false',
					'mode'   => 'somersault',
					'sneaky' => 'undeclared',
				),
			)
		);

		$this->assertSame(
			array(
				'text'  => 'Give today',
				'count' => 10,
				'ratio' => 0.25,
				'flag'  => false,
				'mode'  => 'slide',
			),
			$rule['values'],
			'Every declared field is coerced to its declared type, out-of-range values are clamped, an unrecognized enum choice falls back to the declared default, and a key the schema does not declare is dropped. `ids` was neither submitted nor given a default, so it is absent rather than invented as an empty list.'
		);

		$this->assertArrayNotHasKey(
			'unknown',
			$rule,
			'A registered type is not unknown, so nothing may tag it as such — the client would then fail it closed and the popup would never show.'
		);
	}

	/**
	 * A registered rule fills in declared defaults for absent fields.
	 *
	 * @return void
	 */
	public function test_a_registered_rule_fills_in_declared_defaults() {
		$rule = Sanitizer::sanitize_rule(
			array(
				'type'   => self::PROBE_KEY,
				'values' => array( 'text' => 'Hello' ),
			)
		);

		$this->assertSame(
			array(
				'text'  => 'Hello',
				'count' => 3,
				'mode'  => 'slide',
			),
			$rule['values'],
			'A field with a declared default is stored at that default. A field with neither a submitted value nor a default — `ratio`, `flag` and `ids` here — is omitted rather than invented, because a fabricated value is a targeting decision nobody made.'
		);
	}

	/**
	 * An unregistered rule round-trips with its values byte-identical.
	 *
	 * The keys below are deliberately not in alphabetical order and the values
	 * deliberately mix types, including a numeric string that a coercing
	 * sanitizer would turn into an integer. A condition supplied by a plugin that
	 * is deactivated at save time must survive the round trip exactly, because
	 * dropping or rewriting its values would destroy targeting the author never
	 * edited and widen the audience on reactivation.
	 *
	 * @return void
	 */
	public function test_an_unknown_rule_round_trips_with_values_byte_identical() {
		$values = array(
			'zeta'    => 'last alphabetically, first here',
			'alpha'   => array(
				'nested_two' => 2,
				'nested_one' => '007',
				'flag'       => false,
				'nothing'    => null,
				'ratio'      => 0.5,
			),
			'mid'     => array( 3, '3', true ),
			'ordinal' => '10',
		);

		$first = Sanitizer::sanitize_rule(
			array(
				'type'   => self::UNKNOWN_KEY,
				'negate' => true,
				'values' => $values,
			)
		);

		$this->assertSame(
			array( 'type', 'negate', 'values', 'unknown' ),
			array_keys( $first ),
			'The unknown tag is appended last, so the stored keys keep the order the rest of the plugin reads them in.'
		);

		$this->assertTrue( $first['unknown'], 'The rule is marked unknown so the server defers it, the client denies it, and the editor flags it.' );
		$this->assertSame( $values, $first['values'], 'Values must be identical in content, order and type.' );
		$this->assertSame( array_keys( $values ), array_keys( $first['values'] ), 'Key order is part of what "preserved" means.' );
		$this->assertSame( array_keys( $values['alpha'] ), array_keys( $first['values']['alpha'] ), 'Nested key order is preserved too.' );
		$this->assertSame( '007', $first['values']['alpha']['nested_one'], 'A numeric string is not a number. Coercing it would change what the extension stored.' );

		$second = Sanitizer::sanitize_rule( $first );

		$this->assertSame(
			$first['values'],
			$second['values'],
			'Sanitizing an already sanitized rule changes nothing. A save, load and save again is the cycle the editor performs on every edit.'
		);

		$this->assertSame(
			wp_json_encode( $values ),
			wp_json_encode( $second['values'] ),
			'The JSON encoding is the form these values take over REST and in the emitted config, so it is the form byte-identity is measured in.'
		);
	}

	/**
	 * An unknown rule set round-trips through the whole-set sanitizer unchanged.
	 *
	 * @return void
	 */
	public function test_a_sanitized_rule_set_is_stable_under_a_second_pass() {
		$submitted = array(
			'groups' => array(
				array(
					'rules' => array(
						array(
							'type'   => self::PROBE_KEY,
							'negate' => '1',
							'values' => array(
								'text'  => 'Give today',
								'count' => '4',
							),
						),
						array(
							'type'   => self::UNKNOWN_KEY,
							'negate' => false,
							'values' => array(
								'zeta'  => array( 'deep' => '007' ),
								'alpha' => null,
							),
						),
					),
				),
			),
		);

		$first  = Sanitizer::sanitize_rule_set( $submitted );
		$second = Sanitizer::sanitize_rule_set( $first );

		$this->assertSame(
			$first,
			$second,
			'Sanitization must be idempotent. A shape that changes on every save would make the stored rule set drift with each edit.'
		);

		$this->assertSame(
			$submitted['groups'][0]['rules'][1]['values'],
			$second['groups'][0]['rules'][1]['values'],
			'The unknown rule survives the full save, load and save cycle with its values untouched.'
		);
	}

	/**
	 * An unknown rule outside the structural bounds is refused whole.
	 *
	 * @dataProvider data_out_of_bounds_values
	 *
	 * @param mixed  $values Values submitted with an unregistered rule type.
	 * @param string $limit  Machine name of the limit expected to be reported.
	 * @return void
	 */
	public function test_an_unknown_rule_outside_the_bounds_is_refused_whole( $values, $limit ) {
		$result = Sanitizer::sanitize_rule(
			array(
				'type'   => self::UNKNOWN_KEY,
				'values' => $values,
			)
		);

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'An over-bounds payload must fail the write with a message the editor can show, not be quietly reshaped into something storable.'
		);

		$this->assertSame( Rule_Bounds::ERROR_CODE, $result->get_error_code(), 'One error code keeps the refusal greppable.' );
		$this->assertNotSame( '', $result->get_error_message(), 'The refusal must carry a message the editor can render.' );

		$data = $result->get_error_data();

		$this->assertIsArray( $data, 'The error data is what code and tests branch on.' );
		$this->assertSame( $limit, $data['limit'], 'The reported limit must name the bound that was actually hit.' );
	}

	/**
	 * A refused rule leaves no remnant of itself in the stored set.
	 *
	 * @return void
	 */
	public function test_a_refused_rule_leaves_no_remnant_in_the_stored_set() {
		$keeper = array(
			'type'   => self::UNKNOWN_KEY,
			'negate' => false,
			'values' => array( 'keep' => 'me' ),
		);

		$stored = Sanitizer::sanitize_rule_set(
			array(
				'groups' => array(
					array(
						'rules' => array(
							$keeper,
							array(
								'type'   => self::UNKNOWN_KEY,
								'negate' => false,
								'values' => array( 'oversized' => str_repeat( 'canary', 400 ) ),
							),
						),
					),
				),
			)
		);

		$rules = $stored['groups'][0]['rules'];

		$this->assertCount(
			2,
			$rules,
			'The refused rule is replaced, never removed. Removing an ANDed rule widens the group that held it, which is the opposite of what a refusal should do.'
		);

		$this->assertSame(
			array( 'keep' => 'me' ),
			$rules[0]['values'],
			'The rule beside it is untouched.'
		);

		$this->assertSame(
			array(
				'type'    => Sanitizer::REJECTED_RULE_TYPE,
				'negate'  => false,
				'values'  => array(),
				'unknown' => true,
			),
			$rules[1],
			'The marker names a type nothing can register, so the group it sits in can no longer match anybody.'
		);

		$this->assertStringNotContainsString(
			'canary',
			(string) wp_json_encode( $stored ),
			'Nothing of the refused payload may reach storage. A half-preserved targeting rule is more dangerous than a refused one, because it looks intact.'
		);
	}

	/**
	 * A REST write carrying an over-bounds payload is refused before it is stored.
	 *
	 * @return void
	 */
	public function test_validation_refuses_an_over_bounds_write() {
		$this->assertTrue(
			Sanitizer::validate_rule_set(
				array(
					'groups' => array(
						array( 'rules' => array( array( 'type' => self::UNKNOWN_KEY ) ) ),
					),
				)
			),
			'A rule set within the bounds validates.'
		);

		$too_many_groups = array_fill(
			0,
			Rule_Bounds::MAX_GROUPS_PER_SET + 1,
			array( 'rules' => array( array( 'type' => self::UNKNOWN_KEY ) ) )
		);

		$result = Sanitizer::validate_rule_set( array( 'groups' => $too_many_groups ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'More groups than the limit refuses the write.' );
		$this->assertSame( 'groups_per_set', $result->get_error_data()['limit'], 'The reported limit names the bound that was hit.' );

		$too_many_rules = array_fill(
			0,
			Rule_Bounds::MAX_RULES_PER_GROUP + 1,
			array( 'type' => self::UNKNOWN_KEY )
		);

		$result = Sanitizer::validate_rule_set(
			array( 'groups' => array( array( 'rules' => $too_many_rules ) ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'More rules in one group than the limit refuses the write.' );
		$this->assertSame( 'rules_per_group', $result->get_error_data()['limit'], 'The reported limit names the bound that was hit.' );
	}

	/**
	 * A group holding more rules than the limit is reduced to the refusal marker.
	 *
	 * @return void
	 */
	public function test_an_oversized_group_is_reduced_to_the_refusal_marker() {
		$rules = array_fill(
			0,
			Rule_Bounds::MAX_RULES_PER_GROUP + 1,
			array(
				'type'   => self::UNKNOWN_KEY,
				'values' => array( 'keep' => 'me' ),
			)
		);

		$stored = Sanitizer::sanitize_rule_set( array( 'groups' => array( array( 'rules' => $rules ) ) ) );

		$this->assertSame(
			array(
				array(
					'rules' => array(
						array(
							'type'    => Sanitizer::REJECTED_RULE_TYPE,
							'negate'  => false,
							'values'  => array(),
							'unknown' => true,
						),
					),
				),
			),
			$stored['groups'],
			'Keeping the first fifty rules would drop the rest, and dropping an ANDed rule widens the group. The group keeps its place and stops matching instead.'
		);
	}

	/**
	 * Dropping every rule never produces a set that matches everybody.
	 *
	 * @return void
	 */
	public function test_dropping_every_rule_never_produces_an_always_match_set() {
		$stored = Sanitizer::sanitize_rule_set(
			array(
				'groups' => array(
					array( 'rules' => array( array( 'negate' => true ) ) ),
					array( 'rules' => array( 'corrupt' ) ),
				),
			)
		);

		$this->assertNotSame(
			array(),
			$stored['groups'],
			'An empty groups array means "always match". Collapsing a set that declared targeting into that shape is the silent widening this plugin exists to avoid.'
		);

		$this->assertSame(
			Sanitizer::REJECTED_RULE_TYPE,
			$stored['groups'][0]['rules'][0]['type'],
			'What survives is a group nothing can satisfy.'
		);
	}

	/**
	 * A rule set with no groups is stored as the documented "always match" shape.
	 *
	 * @return void
	 */
	public function test_a_rule_set_with_no_groups_is_stored_as_always_match() {
		$this->assertSame(
			array( 'groups' => array() ),
			Sanitizer::sanitize_rule_set( array( 'groups' => array() ) ),
			'A popup whose author configured no targeting matches every page, and that is the shape docs/data-model.md gives it.'
		);
	}

	/**
	 * Malformed payloads sanitize into a well-formed rule set without fatalling.
	 *
	 * @dataProvider data_malformed_rule_sets
	 *
	 * @param mixed $raw Submitted payload.
	 * @return void
	 */
	public function test_a_malformed_payload_sanitizes_without_fatalling( $raw ) {
		$this->assert_well_formed_rule_set( Sanitizer::sanitize_rule_set( $raw ) );
	}

	/**
	 * Integer coercion cases.
	 *
	 * @return array<string, array{0: mixed, 1: array, 2: int}>
	 */
	public static function data_integer_values() {
		$bounded = array(
			'type' => 'integer',
			'min'  => 10,
			'max'  => 100,
		);

		return array(
			'numeric string'            => array( '42', array( 'type' => 'integer' ), 42 ),
			'padded numeric string'     => array( ' 42 ', array( 'type' => 'integer' ), 42 ),
			'float truncates'           => array( 42.9, array( 'type' => 'integer' ), 42 ),
			'negative float truncates'  => array( -42.9, array( 'type' => 'integer' ), -42 ),
			'true is one'               => array( true, array( 'type' => 'integer' ), 1 ),
			'false is zero'             => array( false, array( 'type' => 'integer' ), 0 ),
			'below the minimum'         => array( 5, $bounded, 10 ),
			'above the maximum'         => array( 500, $bounded, 100 ),
			'within the range'          => array( 50, $bounded, 50 ),
			'canonical minimum'         => array(
				5,
				array(
					'type'    => 'integer',
					'minimum' => 10,
				),
				10,
			),
			'canonical maximum'         => array(
				500,
				array(
					'type'    => 'integer',
					'maximum' => 100,
				),
				100,
			),
			'unusable falls to default' => array(
				'not a number',
				array(
					'type'    => 'integer',
					'default' => 50,
					'min'     => 1,
					'max'     => 100,
				),
				50,
			),
			'unusable with no default'  => array( 'not a number', $bounded, 10 ),
		);
	}

	/**
	 * Number coercion cases.
	 *
	 * @return array<string, array{0: mixed, 1: array, 2: float}>
	 */
	public static function data_number_values() {
		$bounded = array(
			'type' => 'number',
			'min'  => 0,
			'max'  => 1,
		);

		return array(
			'numeric string'  => array( '3.25', array( 'type' => 'number' ), 3.25 ),
			'integer widens'  => array( 3, array( 'type' => 'number' ), 3.0 ),
			'below the floor' => array( -2.5, $bounded, 0.0 ),
			'above the cap'   => array( 2.5, $bounded, 1.0 ),
			'within range'    => array( 0.25, $bounded, 0.25 ),
			'infinite'        => array( INF, $bounded, 0.0 ),
			'not a number'    => array( NAN, $bounded, 0.0 ),
			'unusable string' => array( 'nope', $bounded, 0.0 ),
		);
	}

	/**
	 * Values that must read as false.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function data_false_values() {
		return array(
			'string zero'   => array( '0' ),
			'string false'  => array( 'false' ),
			'string FALSE'  => array( 'FALSE' ),
			'string False'  => array( 'False' ),
			'empty string'  => array( '' ),
			'integer zero'  => array( 0 ),
			'float zero'    => array( 0.0 ),
			'boolean false' => array( false ),
			'null'          => array( null ),
			'empty array'   => array( array() ),
		);
	}

	/**
	 * Values that must read as true.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function data_true_values() {
		return array(
			'string one'     => array( '1' ),
			'string true'    => array( 'true' ),
			'string TRUE'    => array( 'TRUE' ),
			'string yes'     => array( 'yes' ),
			'integer one'    => array( 1 ),
			'boolean true'   => array( true ),
			'positive float' => array( 0.5 ),
			'other string'   => array( 'enabled' ),
		);
	}

	/**
	 * Payloads that are not rules.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function data_rules_without_a_usable_type() {
		return array(
			'no type at all'      => array( array( 'values' => array( 'a' => 1 ) ) ),
			'integer type'        => array(
				array(
					'type'   => 42,
					'values' => array(),
				),
			),
			'array type'          => array(
				array(
					'type'   => array( 'post_type' ),
					'values' => array(),
				),
			),
			'null type'           => array( array( 'type' => null ) ),
			'boolean type'        => array( array( 'type' => true ) ),
			'empty type'          => array( array( 'type' => '' ) ),
			'whitespace type'     => array( array( 'type' => '   ' ) ),
			'type over the limit' => array( array( 'type' => str_repeat( 'a', Rule_Bounds::MAX_STRING_LENGTH + 1 ) ) ),
			'not an array at all' => array( 'post_type' ),
			'integer'             => array( 42 ),
			'null'                => array( null ),
		);
	}

	/**
	 * Stored negate values and the boolean each must become.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function data_negate_values() {
		return array(
			'boolean true'  => array( true, true ),
			'boolean false' => array( false, false ),
			'string true'   => array( 'true', true ),
			'string false'  => array( 'false', false ),
			'string one'    => array( '1', true ),
			'string zero'   => array( '0', false ),
			'integer one'   => array( 1, true ),
			'integer zero'  => array( 0, false ),
			'empty string'  => array( '', false ),
			'null'          => array( null, false ),
		);
	}

	/**
	 * Unknown values that violate one structural bound each.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function data_out_of_bounds_values() {
		$deep = 'bottom';

		for ( $level = 0; $level < Rule_Bounds::MAX_DEPTH + 1; $level++ ) {
			$deep = array( 'down' => $deep );
		}

		return array(
			'nested past the depth limit' => array( $deep, 'depth' ),
			'too many items at a level'   => array(
				array( 'many' => array_fill( 0, Rule_Bounds::MAX_ITEMS_PER_LEVEL + 1, 'x' ) ),
				'items',
			),
			'a string past the limit'     => array(
				array( 'long' => str_repeat( 'a', Rule_Bounds::MAX_STRING_LENGTH + 1 ) ),
				'string_length',
			),
			'values past the byte limit'  => array(
				array_fill( 0, Rule_Bounds::MAX_ITEMS_PER_LEVEL, str_repeat( 'a', 1000 ) ),
				'size',
			),
			'a PHP object'                => array( array( 'object' => new stdClass() ), 'type' ),
			'an infinite float'           => array( array( 'number' => INF ), 'number' ),
		);
	}

	/**
	 * Malformed payloads a forged write or a corrupt row could produce.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function data_malformed_rule_sets() {
		$deep = 'bottom';

		for ( $level = 0; $level < 200; $level++ ) {
			$deep = array( 'down' => $deep );
		}

		$recursive         = array( 'name' => 'loop' );
		$recursive['self'] = &$recursive;

		return array(
			'a string'                                => array( 'not a rule set' ),
			'an integer'                              => array( 42 ),
			'null'                                    => array( null ),
			'true'                                    => array( true ),
			'false'                                   => array( false ),
			'an empty array'                          => array( array() ),
			'groups as a string'                      => array( array( 'groups' => 'nope' ) ),
			'groups as a scalar list'                 => array( array( 'groups' => array( 'a', 42, null, true ) ) ),
			'rules as a string'                       => array( array( 'groups' => array( array( 'rules' => 'nope' ) ) ) ),
			'rules as a scalar list'                  => array( array( 'groups' => array( array( 'rules' => array( 'a', 42, null ) ) ) ) ),
			'a group with no rules key'               => array( array( 'groups' => array( array( 'label' => 'orphan' ) ) ) ),
			'deeply nested values'                    => array(
				array(
					'groups' => array(
						array(
							'rules' => array(
								array(
									'type'   => self::UNKNOWN_KEY,
									'values' => $deep,
								),
							),
						),
					),
				),
			),
			'self-referential values'                 => array(
				array(
					'groups' => array(
						array(
							'rules' => array(
								array(
									'type'   => self::UNKNOWN_KEY,
									'values' => $recursive,
								),
							),
						),
					),
				),
			),
			'values as a scalar'                      => array(
				array(
					'groups' => array(
						array(
							'rules' => array(
								array(
									'type'   => self::UNKNOWN_KEY,
									'values' => 'a bare string',
								),
							),
						),
					),
				),
			),
			'values as a scalar on a registered type' => array(
				array(
					'groups' => array(
						array(
							'rules' => array(
								array(
									'type'   => self::PROBE_KEY,
									'values' => 42,
								),
							),
						),
					),
				),
			),
			'more groups than the limit'              => array(
				array(
					'groups' => array_fill(
						0,
						Rule_Bounds::MAX_GROUPS_PER_SET + 5,
						array( 'rules' => array( array( 'type' => self::UNKNOWN_KEY ) ) )
					),
				),
			),
		);
	}

	/**
	 * Asserts that a sanitized rule set has the shape the rest of the plugin reads.
	 *
	 * @param mixed $set Result of sanitization.
	 * @return void
	 */
	private function assert_well_formed_rule_set( $set ) {
		$this->assertIsArray( $set, 'Sanitization always returns a storable rule set, whatever it was handed.' );
		$this->assertArrayHasKey( 'groups', $set, 'The stored shape always carries a groups key.' );
		$this->assertIsArray( $set['groups'], 'Groups is always an array.' );
		$this->assertTrue( array_is_list( $set['groups'] ), 'Groups is a list, so it encodes as a JSON array rather than an object.' );
		$this->assertLessThanOrEqual( Rule_Bounds::MAX_GROUPS_PER_SET, count( $set['groups'] ), 'A stored set never holds more groups than the limit allows.' );

		foreach ( $set['groups'] as $group ) {
			$this->assertIsArray( $group, 'Every group is an array.' );
			$this->assertArrayHasKey( 'rules', $group, 'Every group carries a rules key.' );
			$this->assertIsArray( $group['rules'], 'Rules is always an array.' );
			$this->assertNotSame( array(), $group['rules'], 'A group with no rules matches everybody, so it is dropped rather than stored.' );
			$this->assertLessThanOrEqual( Rule_Bounds::MAX_RULES_PER_GROUP, count( $group['rules'] ), 'A stored group never holds more rules than the limit allows.' );

			foreach ( $group['rules'] as $rule ) {
				$this->assertIsArray( $rule, 'Every stored rule is an array.' );
				$this->assertArrayHasKey( 'type', $rule, 'Every stored rule names a condition.' );
				$this->assertIsString( $rule['type'], 'A rule type is a string.' );
				$this->assertNotSame( '', $rule['type'], 'A rule type is never empty.' );
				$this->assertArrayHasKey( 'negate', $rule, 'Every stored rule declares whether it is negated.' );
				$this->assertIsBool( $rule['negate'], 'Negate is stored as a real boolean.' );
				$this->assertArrayHasKey( 'values', $rule, 'Every stored rule carries its values, even when they are empty.' );
			}
		}
	}
}
