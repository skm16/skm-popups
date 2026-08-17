<?php
/**
 * Unit tests for the trigger value object.
 *
 * `Popkit\Trigger` is deliberately the smaller of the two registration value
 * objects: it holds a key, a label and a field map, and delegates every field
 * schema question to {@see \Popkit\Condition::validate_field_schemas()}. Two of
 * its properties are worth more than the line count suggests, and both are
 * asserted here rather than assumed.
 *
 * **The delegation is real.** One vocabulary with two implementations would
 * drift, and drift here means a control that renders in a condition panel and
 * not in a trigger panel, or a value sanitized one way on one route and another
 * way on the other. The delegation tests below drive a broken schema through
 * every sub-validator `Condition` owns and assert that the refusal arrives — and
 * that its message names the *trigger*, not the condition whose class raised it.
 * A reimplemented validator would fail those cases the moment the two lists of
 * permitted controls diverged.
 *
 * **A trigger has no context and no group.** Every trigger is armed in the
 * browser by definition, so there is no server-side trigger to express. That is
 * asserted as an absence in `to_schema()`: a `context` key appearing there would
 * be a way to describe a trigger the pipeline cannot honour.
 *
 * Rejections assert the exception type *and* the message, for the reason set out
 * at the top of test-condition.php: a test that only proves something threw
 * cannot tell a working guard from a typo in its own fixture.
 *
 * @package Popkit
 */

use PHPUnit\Framework\TestCase;
use Popkit\Condition;
use Popkit\Trigger;

/*
 * Load the classes directly. Guarded so a bootstrap that does register an
 * autoloader does not end up loading either file twice. `Condition` is required
 * as well as `Trigger`, because the field schema vocabulary lives there.
 */
if ( ! class_exists( Condition::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/class-condition.php';
}

if ( ! class_exists( Trigger::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/class-trigger.php';
}

/**
 * Asserts the registration contract every trigger is validated against.
 */
final class Test_Popkit_Trigger extends TestCase {

	/**
	 * Registry key used by every fixture, so a message can be asserted to name it.
	 *
	 * @var string
	 */
	private const OWNER_KEY = 'sample_trigger';

	/**
	 * Field name used by every field fixture, for the same reason.
	 *
	 * @var string
	 */
	private const FIELD_NAME = 'sample_field';

	/**
	 * Sentence fragment shared by every field-level rejection.
	 *
	 * @var string
	 */
	private const FIELD_PREAMBLE = 'The popkit registration `sample_trigger` declares an invalid schema for the field `sample_field`';

	/**
	 * A well-formed trigger is accepted and exposes exactly what it was given.
	 *
	 * @return void
	 */
	public function test_a_well_formed_trigger_is_accepted() {
		$fields = array(
			'seconds' => array(
				'type'    => 'integer',
				'default' => 5,
				'label'   => 'Seconds',
				'control' => 'number',
				'min'     => 0,
			),
		);

		$trigger = new Trigger( 'time_on_page', 'Time on page', $fields );

		$this->assertSame( 'time_on_page', $trigger->key, 'The key must be stored verbatim: it is what a stored trigger config records as its type.' );
		$this->assertSame( 'Time on page', $trigger->label, 'The label must be stored verbatim and unescaped; escaping belongs at output.' );
		$this->assertSame( $fields, $trigger->fields, 'The field map must be stored verbatim.' );
	}

	/**
	 * The example in the class docblock is a registrable trigger.
	 *
	 * Documentation the validator would reject is worse than none, because it is
	 * copied.
	 *
	 * @return void
	 */
	public function test_the_documented_example_registers() {
		$trigger = new Trigger(
			'scroll_depth',
			'Scroll depth',
			array(
				'percent' => array(
					'type'    => 'integer',
					'default' => 50,
					'label'   => 'Percent scrolled',
					'control' => 'range',
					'min'     => 1,
					'max'     => 100,
				),
			)
		);

		$this->assertSame( 50, $trigger->fields['percent']['default'], 'The documented example must survive validation unchanged.' );
	}

	/**
	 * A trigger that takes no configuration may declare no fields.
	 *
	 * @return void
	 */
	public function test_a_trigger_may_declare_no_fields() {
		$trigger = new Trigger( 'deep_link', 'Deep link', array() );

		$this->assertSame( array(), $trigger->fields, 'A trigger such as deep_link takes no arming parameters and must be registrable without inventing a field.' );
	}

	/**
	 * A key that is not lowercase snake_case is refused, and the message quotes it.
	 *
	 * The message must say *trigger*: a registration error that names the wrong
	 * registry sends the author looking in the wrong file.
	 *
	 * @dataProvider data_malformed_keys
	 *
	 * @param string $key Candidate key under test.
	 * @return void
	 */
	public function test_a_malformed_key_is_rejected( string $key ) {
		$this->assert_rejected(
			static function () use ( $key ) {
				new Trigger( $key, 'Sample', array() );
			},
			array(
				'A popkit trigger key must be lowercase snake_case',
				'Received `' . $key . '`',
			),
			'A key outside the documented grammar must be refused at registration, and the message must name the trigger registry and quote what arrived.'
		);
	}

	/**
	 * Keys that violate the grammar in one way each.
	 *
	 * @return array[] Test name => array( key ).
	 */
	public function data_malformed_keys() {
		return array(
			'empty'               => array( '' ),
			'uppercase'           => array( 'Scroll_Depth' ),
			'hyphenated'          => array( 'scroll-depth' ),
			'leading digit'       => array( '2fa' ),
			'leading underscore'  => array( '_scroll_depth' ),
			'trailing underscore' => array( 'scroll_depth_' ),
			'double underscore'   => array( 'scroll__depth' ),
			'contains a space'    => array( 'scroll depth' ),
			'camelCase'           => array( 'scrollDepth' ),
			'one byte too long'   => array( str_repeat( 'a', Condition::MAX_KEY_LENGTH + 1 ) ),
		);
	}

	/**
	 * Triggers and conditions register under one grammar, not two.
	 *
	 * Asserted as an agreement rather than a list of expected outcomes: whatever
	 * the grammar decides, both registries must decide the same way. A `Trigger`
	 * that grew its own copy of the rule would pass a hand-written list of cases
	 * right up to the first time the two copies diverged.
	 *
	 * @dataProvider data_keys_of_both_kinds
	 *
	 * @param string $key Candidate key under test.
	 * @return void
	 */
	public function test_the_key_grammar_is_shared_with_conditions( string $key ) {
		$accepted = true;

		try {
			new Trigger( $key, 'Sample', array() );
		} catch ( InvalidArgumentException $exception ) {
			$accepted = false;
		}

		$this->assertSame(
			Condition::is_valid_key( $key ),
			$accepted,
			'A trigger key and a condition key obey one grammar. `' . $key . '` was judged differently by the two registries, which means the rule now exists in two places.'
		);
	}

	/**
	 * Keys spanning both sides of the grammar, including its length boundary.
	 *
	 * @return array[] Test name => array( key ).
	 */
	public function data_keys_of_both_kinds() {
		return array(
			'a single letter'     => array( 'a' ),
			'snake case'          => array( 'scroll_depth' ),
			'trailing digit'      => array( 'utm2' ),
			'digits inside'       => array( 'level_2_only' ),
			'exactly the maximum' => array( str_repeat( 'a', Condition::MAX_KEY_LENGTH ) ),
			'one byte too long'   => array( str_repeat( 'a', Condition::MAX_KEY_LENGTH + 1 ) ),
			'empty'               => array( '' ),
			'uppercase'           => array( 'Scroll_Depth' ),
			'hyphenated'          => array( 'scroll-depth' ),
			'leading digit'       => array( '2fa' ),
			'trailing underscore' => array( 'scroll_depth_' ),
			'double underscore'   => array( 'scroll__depth' ),
			'a dot'               => array( 'scroll.depth' ),
		);
	}

	/**
	 * A key that is not a string at all cannot reach the grammar check.
	 *
	 * @dataProvider data_non_strings
	 *
	 * @param mixed $key Value offered as a key.
	 * @return void
	 */
	public function test_a_non_string_key_is_rejected_by_the_signature( $key ) {
		$this->expectException( TypeError::class );

		new Trigger( $key, 'Sample', array() );
	}

	/**
	 * Values that no string parameter can accept.
	 *
	 * @return array[] Test name => array( value ).
	 */
	public function data_non_strings() {
		return array(
			'null'   => array( null ),
			'array'  => array( array( 'scroll_depth' ) ),
			'object' => array( new stdClass() ),
		);
	}

	/**
	 * A trigger with a blank label is refused, and the message names the key.
	 *
	 * @dataProvider data_blank_labels
	 *
	 * @param string $label Label under test.
	 * @return void
	 */
	public function test_a_blank_label_is_rejected( string $label ) {
		$this->assert_rejected(
			static function () use ( $label ) {
				new Trigger( self::OWNER_KEY, $label, array() );
			},
			array(
				'The popkit trigger `' . self::OWNER_KEY . '`',
				'must declare a non-empty label',
			),
			'The editor has no other way to name a trigger, so a blank label must be refused at registration rather than rendered nameless.'
		);
	}

	/**
	 * Labels that carry no visible text.
	 *
	 * @return array[] Test name => array( label ).
	 */
	public function data_blank_labels() {
		return array(
			'empty'            => array( '' ),
			'one space'        => array( ' ' ),
			'several spaces'   => array( '     ' ),
			'a tab'            => array( "\t" ),
			'a newline'        => array( "\n" ),
			'mixed whitespace' => array( " \t\r\n " ),
		);
	}

	/**
	 * The field map must be an array, not a single field name.
	 *
	 * @dataProvider data_non_arrays
	 *
	 * @param mixed $fields Value offered as a field map.
	 * @return void
	 */
	public function test_the_field_map_must_be_an_array( $fields ) {
		$this->expectException( TypeError::class );

		new Trigger( 'scroll_depth', 'Scroll depth', $fields );
	}

	/**
	 * Values that are not arrays.
	 *
	 * @return array[] Test name => array( value ).
	 */
	public function data_non_arrays() {
		return array(
			'a string'   => array( 'items' ),
			'an integer' => array( 1 ),
			'null'       => array( null ),
			'an object'  => array( new stdClass() ),
		);
	}

	/**
	 * Every field schema guard applies to a trigger, and names the trigger.
	 *
	 * One case per sub-validator `Condition` owns. Together they prove the
	 * delegation reaches all of them rather than stopping at the first check,
	 * and that `$owner_key` is threaded through so the message locates the
	 * problem in the trigger being registered.
	 *
	 * @dataProvider data_invalid_field_schemas
	 *
	 * @param array  $fields   Field map under test.
	 * @param string $sentence Sentence the message must carry.
	 * @return void
	 */
	public function test_every_field_schema_guard_applies_to_a_trigger( array $fields, string $sentence ) {
		$this->assert_rejected(
			static function () use ( $fields ) {
				new Trigger( self::OWNER_KEY, 'Sample trigger', $fields );
			},
			array( 'The popkit registration `' . self::OWNER_KEY . '`', $sentence ),
			'A trigger declares its fields in the same vocabulary as a condition, so the same guard must refuse the same mistake and the message must name the trigger.'
		);
	}

	/**
	 * One broken schema per guard, with the sentence each must report.
	 *
	 * @return array[] Test name => array( field map, expected sentence ).
	 */
	public function data_invalid_field_schemas() {
		return array(
			'a malformed field name'        => array(
				array( 'Percent' => $this->schema() ),
				'declares the field name `Percent`, which is not lowercase snake_case',
			),
			'an integer field name'         => array(
				array( 0 => $this->schema() ),
				'declares the field name `0`, which is not lowercase snake_case',
			),
			'a schema that is not an array' => array(
				array( self::FIELD_NAME => 'integer' ),
				'as string. A field must be declared as an array describing its schema',
			),
			'an unrecognized key'           => array(
				array( self::FIELD_NAME => $this->schema( array( 'lable' => 'Percent' ) ) ),
				'unrecognized schema key or keys `lable`',
			),
			'no type'                       => array(
				array(
					self::FIELD_NAME => array(
						'label'   => 'Percent',
						'control' => 'range',
					),
				),
				'It must declare a type of one of:',
			),
			'an unsupported type'           => array(
				array( self::FIELD_NAME => $this->schema( array( 'type' => 'float' ) ) ),
				'It must declare a type of one of:',
			),
			'an array without items'        => array(
				array( self::FIELD_NAME => $this->schema( array( 'type' => 'array' ) ) ),
				'An array field must declare items as one of:',
			),
			'an array-shaped items'         => array(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						)
					),
				),
				'An array field must declare items as one of:',
			),
			'items on a string field'       => array(
				array( self::FIELD_NAME => $this->schema( array( 'items' => 'string' ) ) ),
				'It declares items but its type is `string`',
			),
			'an enum without a list'        => array(
				array( self::FIELD_NAME => $this->schema( array( 'type' => 'enum' ) ) ),
				'An enum field must declare enum as a non-empty list of permitted values',
			),
			'an empty enum list'            => array(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type' => 'enum',
							'enum' => array(),
						)
					),
				),
				'An enum field must declare enum as a non-empty list of permitted values',
			),
			'an unusable enum member'       => array(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type' => 'enum',
							'enum' => array( 'once', 1.5 ),
						)
					),
				),
				'Its enum list contains a value of type float',
			),
			'an enum on a string field'     => array(
				array( self::FIELD_NAME => $this->schema( array( 'enum' => array( 'once' ) ) ) ),
				'It declares enum but its type is `string`',
			),
			'a blank field label'           => array(
				array(
					self::FIELD_NAME => array(
						'type'    => 'integer',
						'label'   => '   ',
						'control' => 'range',
					),
				),
				'It must declare a non-empty label',
			),
			'an unsupported control'        => array(
				array( self::FIELD_NAME => $this->schema( array( 'control' => 'slider' ) ) ),
				'It must declare a control of one of:',
			),
			'a mismatched default'          => array(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'    => 'integer',
							'default' => '50',
						)
					),
				),
				'does not satisfy the declared type `integer`',
			),
			'a bound on a string'           => array(
				array( self::FIELD_NAME => $this->schema( array( 'min' => 1 ) ) ),
				'It declares min or max but its type is `string`',
			),
			'a non-numeric bound'           => array(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type' => 'integer',
							'max'  => '100',
						)
					),
				),
				'Its max bound is string',
			),
			'an inverted range'             => array(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type' => 'integer',
							'min'  => 100,
							'max'  => 1,
						)
					),
				),
				'Its min bound is greater than its max bound',
			),
		);
	}

	/**
	 * The length cap guard applies to a trigger too.
	 *
	 * Separated from the table above because `max_length` is newer than the rest
	 * of the vocabulary; skipping loudly beats asserting against a schema key the
	 * class does not have.
	 *
	 * @return void
	 */
	public function test_the_length_cap_guard_applies_to_a_trigger() {
		if ( ! in_array( 'max_length', Condition::FIELD_SCHEMA_KEYS, true ) ) {
			$this->markTestSkipped( 'The field schema vocabulary does not declare max_length.' );
		}

		$this->assert_rejected(
			static function () {
				new Trigger(
					self::OWNER_KEY,
					'Sample trigger',
					array(
						self::FIELD_NAME => array(
							'type'       => 'integer',
							'label'      => 'Percent',
							'control'    => 'range',
							'max_length' => 10,
						),
					)
				);
			},
			array( self::FIELD_PREAMBLE, 'It declares max_length but its type is `integer`' ),
			'A length cap measures a stored string, and the guard must reach a trigger field exactly as it reaches a condition field.'
		);

		$trigger = new Trigger(
			self::OWNER_KEY,
			'Sample trigger',
			array(
				self::FIELD_NAME => $this->schema( array( 'max_length' => 32 ) ),
			)
		);

		$this->assertSame(
			32,
			$trigger->fields[ self::FIELD_NAME ]['max_length'],
			'A cap on a string field is legitimate on a trigger and must register unchanged.'
		);
	}

	/**
	 * Every type in the shared vocabulary registers on a trigger.
	 *
	 * The mirror of the rejection table: delegation that refused everything would
	 * satisfy those cases and none of these.
	 *
	 * @dataProvider data_field_types
	 *
	 * @param string $type Field type under test.
	 * @return void
	 */
	public function test_every_supported_type_registers_on_a_trigger( string $type ) {
		$extras = match ( $type ) {
			'array' => array(
				'items'   => 'string',
				'control' => 'multiselect',
			),
			'enum'  => array(
				'enum'    => array( 'once', 'always' ),
				'control' => 'select',
			),
			default => array(),
		};

		$trigger = new Trigger(
			self::OWNER_KEY,
			'Sample trigger',
			array( self::FIELD_NAME => $this->schema( array_merge( array( 'type' => $type ), $extras ) ) )
		);

		$this->assertSame(
			$type,
			$trigger->fields[ self::FIELD_NAME ]['type'],
			'Every type listed in FIELD_TYPES must be registrable on a trigger; a vocabulary that only half applies is two vocabularies.'
		);
	}

	/**
	 * Every declared field type.
	 *
	 * @return array[] Type name => array( type ).
	 */
	public function data_field_types() {
		$cases = array();

		foreach ( Condition::FIELD_TYPES as $type ) {
			$cases[ $type ] = array( $type );
		}

		return $cases;
	}

	/**
	 * Every control in the shared map registers on a trigger.
	 *
	 * @dataProvider data_field_controls
	 *
	 * @param string $control Control name under test.
	 * @return void
	 */
	public function test_every_supported_control_registers_on_a_trigger( string $control ) {
		$trigger = new Trigger(
			self::OWNER_KEY,
			'Sample trigger',
			array( self::FIELD_NAME => $this->schema( array( 'control' => $control ) ) )
		);

		$this->assertSame(
			$control,
			$trigger->fields[ self::FIELD_NAME ]['control'],
			'Every control listed in FIELD_CONTROLS must be registrable on a trigger, or the editor renders one panel from two different maps.'
		);
	}

	/**
	 * Every declared control.
	 *
	 * @return array[] Control name => array( control ).
	 */
	public function data_field_controls() {
		$cases = array();

		foreach ( Condition::FIELD_CONTROLS as $control ) {
			$cases[ $control ] = array( $control );
		}

		return $cases;
	}

	/**
	 * The to_schema() payload has the documented shape, in the documented order.
	 *
	 * @return void
	 */
	public function test_to_schema_returns_the_documented_shape() {
		$fields = array(
			'percent' => array(
				'type'    => 'integer',
				'label'   => 'Percent scrolled',
				'control' => 'range',
				'min'     => 1,
				'max'     => 100,
			),
		);

		$trigger = new Trigger( 'scroll_depth', 'Scroll depth', $fields );

		$this->assertSame(
			array(
				'key'    => 'scroll_depth',
				'label'  => 'Scroll depth',
				'fields' => $fields,
			),
			$trigger->to_schema(),
			'The registry payload is a documented contract with REST and the editor: three keys, nothing escaped, nothing added.'
		);
	}

	/**
	 * The payload carries no context and no group.
	 *
	 * Every trigger is armed in the browser by definition. A context key here
	 * would be a way to express a server-side trigger, which the pipeline cannot
	 * honour and which would let a trigger influence cached markup.
	 *
	 * @return void
	 */
	public function test_to_schema_declares_neither_a_context_nor_a_group() {
		$schema = ( new Trigger( 'deep_link', 'Deep link', array() ) )->to_schema();

		$this->assertArrayNotHasKey(
			'context',
			$schema,
			'A trigger has no context. Emitting one would invite a server-side trigger, and no trigger may influence the cached response.'
		);
		$this->assertArrayNotHasKey(
			'group',
			$schema,
			'A trigger has no editor group; the editor lists every trigger in one panel.'
		);
		$this->assertSame(
			array( 'key', 'label', 'fields' ),
			array_keys( $schema ),
			'The payload must carry exactly the three documented keys, so a consumer can rely on their presence and their absence alike.'
		);
	}

	/**
	 * The to_schema() payload is stable across calls and hands out no state.
	 *
	 * @return void
	 */
	public function test_to_schema_is_stable_across_calls() {
		$trigger = new Trigger(
			'scroll_depth',
			'Scroll depth',
			array( self::FIELD_NAME => $this->schema() )
		);

		$first  = $trigger->to_schema();
		$second = $trigger->to_schema();

		$this->assertSame(
			$first,
			$second,
			'Two calls must produce identical payloads. Anything that varies between them would make the registry route uncacheable and the editor state unstable.'
		);

		$first['key']    = 'mutated';
		$first['fields'] = array();

		$this->assertSame(
			array(
				'key'    => 'scroll_depth',
				'label'  => 'Scroll depth',
				'fields' => array( self::FIELD_NAME => $this->schema() ),
			),
			$trigger->to_schema(),
			'A caller editing the payload it was handed must not be able to reach the registration behind it.'
		);
	}

	/**
	 * The payload survives a JSON round trip unchanged.
	 *
	 * @return void
	 */
	public function test_to_schema_survives_a_json_round_trip() {
		$trigger = new Trigger( 'deep_link', 'Deep link', array() );
		$encoded = wp_json_encode( $trigger->to_schema() );

		$this->assertIsString( $encoded, 'The payload must be JSON-serializable; REST and the editor consume nothing else.' );
		$this->assertStringContainsString(
			'"fields":[]',
			$encoded,
			'A trigger with no fields encodes as an empty JSON array, exactly as documented. A caller needing an object casts at encode time.'
		);
		$this->assertSame(
			$trigger->to_schema(),
			json_decode( $encoded, true ),
			'The payload must decode back to itself.'
		);
	}

	/**
	 * A registered trigger cannot be rewritten by later code.
	 *
	 * @return void
	 */
	public function test_a_registered_trigger_cannot_be_mutated() {
		$trigger = new Trigger( 'deep_link', 'Deep link', array() );

		try {
			$trigger->label = 'Something else';
		} catch ( Error $error ) {
			$this->assertStringContainsString(
				'readonly',
				$error->getMessage(),
				'The refusal must come from the readonly declaration, not from some unrelated failure.'
			);

			return;
		}

		$this->fail( 'Every property is readonly, so a trigger handed to the registry cannot be rewritten by later code.' );
	}

	/**
	 * Asserts that a registration is refused with a message naming its cause.
	 *
	 * @param callable $register Closure performing the registration.
	 * @param string[] $needles  Fragments the message must contain.
	 * @param string   $why      Explanation reported when the assertion fails.
	 * @return void
	 */
	private function assert_rejected( callable $register, array $needles, string $why ) {
		try {
			$register();
		} catch ( InvalidArgumentException $exception ) {
			foreach ( $needles as $needle ) {
				$this->assertStringContainsString( $needle, $exception->getMessage(), $why );
			}

			return;
		}

		$this->fail( $why );
	}

	/**
	 * Builds a minimal valid field schema, with overrides merged over it.
	 *
	 * @param array $overrides Schema keys to add or replace.
	 * @return array Field schema.
	 */
	private function schema( array $overrides = array() ) {
		return array_merge(
			array(
				'type'    => 'string',
				'label'   => 'Sample field',
				'control' => 'text',
			),
			$overrides
		);
	}
}
