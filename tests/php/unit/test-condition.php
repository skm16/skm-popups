<?php
/**
 * Unit tests for the condition value object and its field schema vocabulary.
 *
 * `Popkit\Condition` is two things at once: the immutable description of one
 * registered targeting condition, and the single definition of the field schema
 * language that `Popkit\Trigger` also registers against. Almost all of it is
 * refusal — roughly a dozen distinct `InvalidArgumentException` paths guarding a
 * vocabulary that three separate consumers read as truth: the editor sidebar
 * renders from `control` and `label`, REST validates incoming values against
 * `type`, and sanitization derives its sanitizer from `type` rather than being
 * hand-written per field.
 *
 * That last consumer is why this file is exhaustive rather than representative.
 * A schema key that slips through validation is not a cosmetic problem: it is a
 * stored value whose sanitizer was chosen from a declaration nobody checked.
 *
 * Two habits below are deliberate and should survive editing:
 *
 * 1. **Every rejection asserts the message, not just the throw.** A test that
 *    only proves "something threw" cannot tell a working guard apart from a typo
 *    in the fixture — a misspelled `contorl` would satisfy such a test by
 *    tripping the unknown-key guard instead of the one under test. Each case
 *    here asserts the exception type, that the message names the registration
 *    and the offending field, and that it carries the sentence belonging to the
 *    specific guard being exercised.
 *
 * 2. **The permitted values are driven from the class constants.** The "every
 *    supported type" and "every supported control" tests iterate
 *    `Condition::FIELD_TYPES` and `Condition::FIELD_CONTROLS`, so adding a
 *    control without teaching this suite how to build a valid schema for it
 *    fails here rather than shipping an unrenderable panel.
 *
 * Boundaries are pinned on both sides wherever a comparison operator could drift:
 * a key of exactly `MAX_KEY_LENGTH` is accepted and one byte more is refused,
 * `min` equal to `max` is accepted and one more is refused, and a default of
 * exactly `max_length` bytes is accepted.
 *
 * `Condition` touches no WordPress function beyond `__()` and `esc_html()`, both
 * of which the stubs bootstrap provides, so this runs with no WordPress, no
 * database and no container.
 *
 * @package Popkit
 */

use PHPUnit\Framework\TestCase;
use Popkit\Condition;
use Popkit\Context;

/*
 * Load the classes directly. Guarded so a bootstrap that does register an
 * autoloader does not end up loading either file twice.
 */
if ( ! enum_exists( Context::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/class-context.php';
}

if ( ! class_exists( Condition::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/class-condition.php';
}

/**
 * Asserts the registration contract every condition is validated against.
 */
final class Test_Popkit_Condition extends TestCase {

	/**
	 * Registry key used by every fixture, so a message can be asserted to name it.
	 *
	 * @var string
	 */
	private const OWNER_KEY = 'sample_condition';

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
	private const FIELD_PREAMBLE = 'declares an invalid schema for the field `sample_field`';

	/**
	 * A well-formed condition is accepted and exposes exactly what it was given.
	 *
	 * @return void
	 */
	public function test_a_well_formed_condition_is_accepted() {
		$fields = array(
			'percent' => array(
				'type'    => 'integer',
				'default' => 50,
				'label'   => 'Percent',
				'control' => 'range',
				'min'     => 1,
				'max'     => 100,
			),
		);

		$condition = new Condition( 'is_front_page', Context::Server, 'content', 'Front page', $fields );

		$this->assertSame( 'is_front_page', $condition->key, 'The key must be stored verbatim: it is what a stored rule records as its type.' );
		$this->assertSame( Context::Server, $condition->context, 'The context must be stored as the enum case it was given.' );
		$this->assertSame( 'content', $condition->group, 'The group must be stored verbatim.' );
		$this->assertSame( 'Front page', $condition->label, 'The label must be stored verbatim and unescaped; escaping belongs at output.' );
		$this->assertSame( $fields, $condition->fields, 'The field map must be stored verbatim. A registration that is rewritten on the way in is a registration the author cannot reason about.' );
	}

	/**
	 * The example schema in the class docblock is a registrable schema.
	 *
	 * Documentation that the validator would reject is worse than no
	 * documentation, because it is copied.
	 *
	 * @return void
	 */
	public function test_the_documented_example_schema_registers() {
		$condition = new Condition(
			'url_path',
			Context::Server,
			'content',
			'URL path',
			array(
				'match' => array(
					'type'    => 'enum',
					'enum'    => array( 'exact', 'prefix', 'contains', 'glob' ),
					'default' => 'exact',
					'label'   => 'Match type',
					'control' => 'select',
				),
				'value' => array(
					'type'       => 'string',
					'default'    => '',
					'label'      => 'Path',
					'control'    => 'url-match',
					'max_length' => 255,
				),
			)
		);

		$this->assertCount( 2, $condition->fields, 'Both documented fields must survive validation.' );
	}

	/**
	 * A key that is not lowercase snake_case is refused, and the message quotes it.
	 *
	 * @dataProvider data_malformed_keys
	 *
	 * @param string $key Candidate key under test.
	 * @return void
	 */
	public function test_a_malformed_key_is_rejected( string $key ) {
		$this->assert_rejected(
			static function () use ( $key ) {
				new Condition( $key, Context::Server, 'content', 'Sample', array() );
			},
			array(
				'A popkit condition key must be lowercase snake_case',
				'Received `' . $key . '`',
			),
			'A key outside the documented grammar must be refused at registration, and the message must quote what arrived so the author can see it.'
		);
	}

	/**
	 * Keys that violate the grammar in one way each.
	 *
	 * @return array[] Test name => array( key ).
	 */
	public function data_malformed_keys() {
		return array(
			'empty'                => array( '' ),
			'uppercase'            => array( 'Is_Front_Page' ),
			'one uppercase letter' => array( 'is_front_Page' ),
			'hyphenated'           => array( 'is-front-page' ),
			'leading digit'        => array( '2fa_required' ),
			'leading underscore'   => array( '_private' ),
			'trailing underscore'  => array( 'is_front_page_' ),
			'double underscore'    => array( 'is__front_page' ),
			'contains a space'     => array( 'is front page' ),
			'contains a dot'       => array( 'is.front.page' ),
			'namespaced'           => array( 'popkit\\is_front_page' ),
			'multibyte'            => array( "caf\u{00e9}_open" ),
			'one byte too long'    => array( str_repeat( 'a', Condition::MAX_KEY_LENGTH + 1 ) ),
		);
	}

	/**
	 * A key of exactly the maximum length is accepted; one byte more is not.
	 *
	 * Asserted as a pair so that a comparison flipped between `<` and `<=` fails
	 * on one half or the other rather than passing both.
	 *
	 * @return void
	 */
	public function test_the_key_length_boundary_is_inclusive() {
		$longest = str_repeat( 'a', Condition::MAX_KEY_LENGTH );

		$this->assertTrue(
			Condition::is_valid_key( $longest ),
			'A key of exactly MAX_KEY_LENGTH bytes is within the documented bound and must be accepted.'
		);
		$this->assertFalse(
			Condition::is_valid_key( $longest . 'a' ),
			'A key one byte past MAX_KEY_LENGTH must be refused; keys are stored verbatim and are bounded like every other stored value.'
		);
	}

	/**
	 * A key that is not a string at all cannot reach the grammar check.
	 *
	 * The constructor signature carries the guard, so the failure is a TypeError.
	 * Asserted rather than assumed: a signature widened to `mixed` would let a
	 * non-string key reach `strspn()`.
	 *
	 * @dataProvider data_non_strings
	 *
	 * @param mixed $key Value offered as a key.
	 * @return void
	 */
	public function test_a_non_string_key_is_rejected_by_the_signature( $key ) {
		$this->expectException( TypeError::class );

		new Condition( $key, Context::Server, 'content', 'Sample', array() );
	}

	/**
	 * An integer key is coerced to a string and then refused by the grammar.
	 *
	 * @return void
	 */
	public function test_an_integer_key_is_rejected_by_the_grammar() {
		$this->assert_rejected(
			static function () {
				new Condition( 12, Context::Server, 'content', 'Sample', array() );
			},
			array( 'A popkit condition key must be lowercase snake_case', 'Received `12`' ),
			'An integer key coerces to a string that begins with a digit, and the grammar must still refuse it rather than registering a numeric key.'
		);
	}

	/**
	 * Values that no string parameter can accept.
	 *
	 * @return array[] Test name => array( value ).
	 */
	public function data_non_strings() {
		return array(
			'null'   => array( null ),
			'array'  => array( array( 'is_front_page' ) ),
			'object' => array( new stdClass() ),
		);
	}

	/**
	 * The is_valid_key() helper accepts every shape the grammar describes.
	 *
	 * @dataProvider data_well_formed_keys
	 *
	 * @param string $key Candidate key under test.
	 * @return void
	 */
	public function test_is_valid_key_accepts_well_formed_keys( string $key ) {
		$this->assertTrue(
			Condition::is_valid_key( $key ),
			'`' . $key . '` obeys the documented grammar and must be accepted; a guard that rejects valid keys blocks legitimate registrations.'
		);
	}

	/**
	 * Keys that obey the grammar.
	 *
	 * @return array[] Test name => array( key ).
	 */
	public function data_well_formed_keys() {
		return array(
			'single letter'      => array( 'a' ),
			'two letters'        => array( 'ab' ),
			'trailing digit'     => array( 'utm2' ),
			'digits inside'      => array( 'a1b2c3' ),
			'single underscores' => array( 'is_front_page' ),
			'many segments'      => array( 'a_b_c_d_e' ),
			'digit after under'  => array( 'level_2_only' ),
		);
	}

	/**
	 * The is_valid_key() helper refuses every shape the grammar excludes.
	 *
	 * @dataProvider data_malformed_keys
	 *
	 * @param string $key Candidate key under test.
	 * @return void
	 */
	public function test_is_valid_key_rejects_malformed_keys( string $key ) {
		$this->assertFalse(
			Condition::is_valid_key( $key ),
			'`' . $key . '` is outside the documented grammar and must be refused.'
		);
	}

	/**
	 * The context must be a Context case, not a string that looks like one.
	 *
	 * The enum is the mechanical expression of the cache-safety rule, so the
	 * parameter may not accept its backed value in its place.
	 *
	 * @dataProvider data_non_contexts
	 *
	 * @param mixed $context Value offered as a context.
	 * @return void
	 */
	public function test_the_context_must_be_a_context_case( $context ) {
		$this->expectException( TypeError::class );

		new Condition( 'is_front_page', $context, 'content', 'Sample', array() );
	}

	/**
	 * Values that are not Context cases.
	 *
	 * @return array[] Test name => array( value ).
	 */
	public function data_non_contexts() {
		return array(
			'the backed value' => array( 'server' ),
			'another string'   => array( 'browser' ),
			'an integer'       => array( 0 ),
			'null'             => array( null ),
			'an object'        => array( new stdClass() ),
		);
	}

	/**
	 * Both contexts are accepted, and to_schema() flattens each to its value.
	 *
	 * @dataProvider data_contexts
	 *
	 * @param Context $context  Context under test.
	 * @param string  $expected Backed value it must serialize to.
	 * @return void
	 */
	public function test_both_contexts_are_accepted_and_flattened( Context $context, string $expected ) {
		$condition = new Condition( 'is_front_page', $context, 'content', 'Front page', array() );
		$schema    = $condition->to_schema();

		$this->assertSame( $context, $condition->context, 'The property must keep the enum case.' );
		$this->assertSame(
			$expected,
			$schema['context'],
			'to_schema() must flatten the context to its backed string value so the payload survives a JSON round trip without a lookup table on the client.'
		);
	}

	/**
	 * Every context case and the string it serializes to.
	 *
	 * @return array[] Test name => array( context, expected value ).
	 */
	public function data_contexts() {
		return array(
			'server' => array( Context::Server, 'server' ),
			'client' => array( Context::Client, 'client' ),
		);
	}

	/**
	 * Both documented editor groups are accepted.
	 *
	 * @dataProvider data_groups
	 *
	 * @param string $group Group under test.
	 * @return void
	 */
	public function test_both_documented_groups_are_accepted( string $group ) {
		$condition = new Condition( 'is_front_page', Context::Server, $group, 'Front page', array() );

		$this->assertSame( $group, $condition->group, 'Both documented groups must register.' );
	}

	/**
	 * The groups the editor knows how to render.
	 *
	 * @return array[] Test name => array( group ).
	 */
	public function data_groups() {
		return array(
			'content' => array( 'content' ),
			'visitor' => array( 'visitor' ),
		);
	}

	/**
	 * A group outside the documented pair is refused.
	 *
	 * @dataProvider data_unknown_groups
	 *
	 * @param string $group Group under test.
	 * @return void
	 */
	public function test_an_unknown_group_is_rejected( string $group ) {
		$this->assert_rejected(
			static function () use ( $group ) {
				new Condition( self::OWNER_KEY, Context::Server, $group, 'Sample', array() );
			},
			array(
				'The popkit condition `' . self::OWNER_KEY . '`',
				'declares the unknown group `' . $group . '`',
				'content, visitor',
			),
			'A group with no panel to render into must be refused, and the message must name both the condition and the permitted groups.'
		);
	}

	/**
	 * Groups that no editor panel corresponds to.
	 *
	 * @return array[] Test name => array( group ).
	 */
	public function data_unknown_groups() {
		return array(
			'empty'          => array( '' ),
			'wrong case'     => array( 'Content' ),
			'pluralized'     => array( 'visitors' ),
			'invented'       => array( 'admin' ),
			'padded'         => array( ' content' ),
			'a context name' => array( 'server' ),
		);
	}

	/**
	 * A condition with a blank label is refused.
	 *
	 * @dataProvider data_blank_labels
	 *
	 * @param string $label Label under test.
	 * @return void
	 */
	public function test_a_blank_condition_label_is_rejected( string $label ) {
		$this->assert_rejected(
			static function () use ( $label ) {
				new Condition( self::OWNER_KEY, Context::Server, 'content', $label, array() );
			},
			array(
				'The popkit condition `' . self::OWNER_KEY . '`',
				'must declare a non-empty label',
			),
			'An unlabeled condition is an accessibility failure, so it must be refused at registration rather than rendered nameless.'
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

		new Condition( 'is_front_page', Context::Server, 'content', 'Sample', $fields );
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
	 * A condition that takes no configuration may declare no fields.
	 *
	 * @return void
	 */
	public function test_a_condition_may_declare_no_fields() {
		$condition = new Condition( 'is_front_page', Context::Server, 'content', 'Front page', array() );

		$this->assertSame( array(), $condition->fields, 'A condition such as is_front_page takes no configuration and must be registrable without inventing a field.' );
	}

	/**
	 * A field name that is not lowercase snake_case is refused.
	 *
	 * @dataProvider data_malformed_field_names
	 *
	 * @param string $field_name Field name under test.
	 * @return void
	 */
	public function test_a_malformed_field_name_is_rejected( string $field_name ) {
		$this->assert_rejected(
			$this->registering( array( $field_name => $this->schema() ) ),
			array(
				'The popkit registration `' . self::OWNER_KEY . '`',
				'declares the field name `' . $field_name . '`',
				'not lowercase snake_case',
			),
			'Field names follow the same grammar as registry keys, and the message must quote the name that arrived.'
		);
	}

	/**
	 * Field names that violate the shared grammar.
	 *
	 * @return array[] Test name => array( field name ).
	 */
	public function data_malformed_field_names() {
		return array(
			'uppercase'           => array( 'Match' ),
			'hyphenated'          => array( 'match-type' ),
			'trailing underscore' => array( 'match_' ),
			'double underscore'   => array( 'match__type' ),
			'contains a space'    => array( 'match type' ),
			'camelCase'           => array( 'matchType' ),
		);
	}

	/**
	 * An integer field name is refused before the grammar is consulted.
	 *
	 * A field map written as a list rather than a map produces integer keys, and
	 * an integer key would otherwise be handed to a string-typed helper.
	 *
	 * @return void
	 */
	public function test_an_integer_field_name_is_rejected() {
		$this->assert_rejected(
			$this->registering( array( 7 => $this->schema() ) ),
			array(
				'The popkit registration `' . self::OWNER_KEY . '`',
				'declares the field name `7`',
				'not lowercase snake_case',
			),
			'A list of schemas has integer keys, which are not field names; the message must quote the offending key.'
		);
	}

	/**
	 * A field declared as anything but an array is refused, and its type is named.
	 *
	 * @dataProvider data_non_array_schemas
	 *
	 * @param mixed  $schema        Value offered as a field schema.
	 * @param string $expected_type Type name the message must report.
	 * @return void
	 */
	public function test_a_field_schema_must_be_an_array( $schema, string $expected_type ) {
		$this->assert_rejected(
			$this->registering( array( self::FIELD_NAME => $schema ) ),
			array(
				'The popkit registration `' . self::OWNER_KEY . '`',
				'declares the field `' . self::FIELD_NAME . '` as ' . $expected_type,
				'must be declared as an array describing its schema',
			),
			'A field declared as a bare value has no schema to derive a sanitizer from, and the message must report what arrived instead.'
		);
	}

	/**
	 * Values that are not field schemas, with the type name each reports.
	 *
	 * @return array[] Test name => array( value, expected type name ).
	 */
	public function data_non_array_schemas() {
		return array(
			'a bare type name' => array( 'string', 'string' ),
			'an integer'       => array( 5, 'int' ),
			'a boolean'        => array( true, 'bool' ),
			'null'             => array( null, 'null' ),
			'an object'        => array( new stdClass(), 'stdClass' ),
		);
	}

	/**
	 * A schema key outside the vocabulary is refused, and the typo is quoted.
	 *
	 * @dataProvider data_unrecognized_schema_keys
	 *
	 * @param string $unknown_key Schema key under test.
	 * @return void
	 */
	public function test_an_unrecognized_schema_key_is_rejected( string $unknown_key ) {
		$this->assert_rejected(
			$this->registering(
				array( self::FIELD_NAME => $this->schema( array( $unknown_key => 'anything' ) ) )
			),
			array(
				self::FIELD_PREAMBLE,
				'unrecognized schema key or keys `' . $unknown_key . '`',
			),
			'A key nothing reads would sit in the schema doing nothing while the control rendered wrong, so it must be refused and quoted back.'
		);
	}

	/**
	 * Keys a registration might plausibly write by mistake.
	 *
	 * @return array[] Test name => array( key ).
	 */
	public function data_unrecognized_schema_keys() {
		return array(
			'a misspelled label'   => array( 'lable' ),
			'a misspelled control' => array( 'contorl' ),
			'a JSON Schema key'    => array( 'description' ),
			'a camelCase variant'  => array( 'defaultValue' ),
			'an invented key'      => array( 'sanitize_callback' ),
			'a required flag'      => array( 'required' ),
		);
	}

	/**
	 * A schema with no type is refused: sanitization has nothing to derive from.
	 *
	 * @return void
	 */
	public function test_a_schema_without_a_type_is_rejected() {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => array(
						'label'   => 'Sample',
						'control' => 'text',
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'It must declare a type of one of:' ),
			'Sanitization is derived from the declared type, so a field without one cannot be stored safely and must be refused.'
		);
	}

	/**
	 * A type outside the vocabulary is refused.
	 *
	 * @dataProvider data_unsupported_types
	 *
	 * @param mixed $type Value offered as a type.
	 * @return void
	 */
	public function test_an_unsupported_type_is_rejected( $type ) {
		$this->assert_rejected(
			$this->registering( array( self::FIELD_NAME => $this->schema( array( 'type' => $type ) ) ) ),
			array( self::FIELD_PREAMBLE, 'It must declare a type of one of:', 'string, integer, number, boolean, array, enum' ),
			'A type with no derived sanitizer must be refused, and the message must list the vocabulary so the author can correct it.'
		);
	}

	/**
	 * Types that have no sanitizer behind them.
	 *
	 * @return array[] Test name => array( value ).
	 */
	public function data_unsupported_types() {
		return array(
			'a PHP type name'     => array( 'float' ),
			'an object type'      => array( 'object' ),
			'a JSON Schema alias' => array( 'int' ),
			'wrong case'          => array( 'INTEGER' ),
			'empty'               => array( '' ),
			'an integer'          => array( 1 ),
			'null'                => array( null ),
			'an array'            => array( array( 'string' ) ),
			'a boolean'           => array( true ),
		);
	}

	/**
	 * Every type in the vocabulary registers.
	 *
	 * Driven from the constant, so a type added without a working schema shape
	 * fails here rather than in the editor.
	 *
	 * @dataProvider data_field_types
	 *
	 * @param string $type Field type under test.
	 * @return void
	 */
	public function test_every_supported_type_is_accepted( string $type ) {
		$condition = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array( self::FIELD_NAME => $this->schema_for_type( $type ) )
		);

		$this->assertSame(
			$type,
			$condition->fields[ self::FIELD_NAME ]['type'],
			'Every type listed in FIELD_TYPES must be registrable; a listed type the validator refuses is a vocabulary nobody can use.'
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
	 * An array field that omits items is refused.
	 *
	 * @return void
	 */
	public function test_an_array_field_must_declare_items() {
		$this->assert_rejected(
			$this->registering(
				array( self::FIELD_NAME => $this->schema( array( 'type' => 'array' ) ) )
			),
			array( self::FIELD_PREAMBLE, 'An array field must declare items as one of:', 'string, integer' ),
			'A sanitizer derived from a declaration cannot bound a shape the declaration does not describe, so an array without items must be refused.'
		);
	}

	/**
	 * Both permitted item types register.
	 *
	 * @dataProvider data_item_types
	 *
	 * @param string $items Item type under test.
	 * @return void
	 */
	public function test_both_item_types_are_accepted( string $items ) {
		$condition = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array(
				self::FIELD_NAME => $this->schema(
					array(
						'type'    => 'array',
						'items'   => $items,
						'control' => 'multiselect',
					)
				),
			)
		);

		$this->assertSame(
			$items,
			$condition->fields[ self::FIELD_NAME ]['items'],
			'Every type listed in FIELD_ITEM_TYPES must be registrable.'
		);
	}

	/**
	 * Every declared item type.
	 *
	 * @return array[] Type name => array( type ).
	 */
	public function data_item_types() {
		$cases = array();

		foreach ( Condition::FIELD_ITEM_TYPES as $type ) {
			$cases[ $type ] = array( $type );
		}

		return $cases;
	}

	/**
	 * The items key must name one of the permitted scalar types and nothing else.
	 *
	 * @dataProvider data_rejected_items
	 *
	 * @param mixed $items Value offered as items.
	 * @return void
	 */
	public function test_items_must_name_a_permitted_scalar_type( $items ) {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'    => 'array',
							'items'   => $items,
							'control' => 'multiselect',
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'An array field must declare items as one of:', 'string, integer' ),
			'Arrays are flat lists of one scalar type; anything else must be refused rather than half-understood.'
		);
	}

	/**
	 * Values items may not take.
	 *
	 * The array-shaped cases are the point of this provider. `items` is a bare
	 * type name here, not a nested schema, which is the opposite of the JSON
	 * Schema convention it resembles; declaring it as a nested schema must fail
	 * loudly rather than be read as some scalar type by accident.
	 *
	 * @return array[] Test name => array( value ).
	 */
	public function data_rejected_items() {
		return array(
			'a nested schema'      => array(
				array(
					'type'  => 'string',
					'label' => 'Item',
				),
			),
			'a nested type only'   => array( array( 'type' => 'string' ) ),
			'a list of type names' => array( array( 'string' ) ),
			'an empty array'       => array( array() ),
			'a non-scalar type'    => array( 'array' ),
			'an enum'              => array( 'enum' ),
			'a number'             => array( 'number' ),
			'a boolean type'       => array( 'boolean' ),
			'wrong case'           => array( 'String' ),
			'empty'                => array( '' ),
			'null'                 => array( null ),
			'an integer'           => array( 1 ),
		);
	}

	/**
	 * An items key on a field that is not an array is refused, and the type named.
	 *
	 * @dataProvider data_types_other_than_array
	 *
	 * @param string $type   Field type under test.
	 * @param array  $extras Schema keys the type requires.
	 * @return void
	 */
	public function test_items_on_a_non_array_field_is_rejected( string $type, array $extras ) {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array_merge(
							array(
								'type'  => $type,
								'items' => 'string',
							),
							$extras
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'It declares items but its type is `' . $type . '`' ),
			'Only an array field describes its items; declaring them elsewhere is a misunderstanding that must be corrected at registration.'
		);
	}

	/**
	 * Every type except array, with whatever else that type requires.
	 *
	 * @return array[] Type name => array( type, extra schema keys ).
	 */
	public function data_types_other_than_array() {
		return array(
			'string'  => array( 'string', array() ),
			'integer' => array( 'integer', array() ),
			'number'  => array( 'number', array() ),
			'boolean' => array( 'boolean', array() ),
			'enum'    => array( 'enum', array( 'enum' => array( 'a', 'b' ) ) ),
		);
	}

	/**
	 * An enum field that omits its list is refused.
	 *
	 * @return void
	 */
	public function test_an_enum_field_must_declare_enum() {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'    => 'enum',
							'control' => 'select',
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'An enum field must declare enum as a non-empty list of permitted values' ),
			'An enum with no permitted values describes a control with nothing to choose from.'
		);
	}

	/**
	 * An enum must be a non-empty list, not a map and not a scalar.
	 *
	 * @dataProvider data_rejected_enums
	 *
	 * @param mixed $enum_values Value offered as an enum list.
	 * @return void
	 */
	public function test_an_enum_must_be_a_non_empty_list( $enum_values ) {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'    => 'enum',
							'enum'    => $enum_values,
							'control' => 'select',
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'An enum field must declare enum as a non-empty list of permitted values' ),
			'A stored value is compared against this list, so a shape the comparison cannot walk must be refused.'
		);
	}

	/**
	 * Values enum may not take.
	 *
	 * @return array[] Test name => array( value ).
	 */
	public function data_rejected_enums() {
		return array(
			'an empty list'   => array( array() ),
			'a label map'     => array(
				array(
					'exact'  => 'Exact',
					'prefix' => 'Prefix',
				),
			),
			'a sparse list'   => array( array( 1 => 'exact' ) ),
			'a single scalar' => array( 'exact' ),
			'null'            => array( null ),
			'an integer'      => array( 3 ),
			'a boolean'       => array( false ),
		);
	}

	/**
	 * Every enum value must survive a JSON round trip as itself.
	 *
	 * @dataProvider data_rejected_enum_values
	 *
	 * @param mixed  $value         Value placed in the enum list.
	 * @param string $expected_type Type name the message must report.
	 * @return void
	 */
	public function test_enum_values_must_be_strings_or_integers( $value, string $expected_type ) {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'    => 'enum',
							'enum'    => array( 'exact', $value ),
							'control' => 'select',
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'Its enum list contains a value of type ' . $expected_type ),
			'Permitted values must survive a JSON round trip, and the message must name the type that did not.'
		);
	}

	/**
	 * Enum members that cannot round trip, with the type name each reports.
	 *
	 * @return array[] Test name => array( value, expected type name ).
	 */
	public function data_rejected_enum_values() {
		return array(
			'a float'   => array( 1.5, 'float' ),
			'a boolean' => array( true, 'bool' ),
			'null'      => array( null, 'null' ),
			'an array'  => array( array( 'nested' ), 'array' ),
			'an object' => array( new stdClass(), 'stdClass' ),
		);
	}

	/**
	 * An enum of integers registers.
	 *
	 * @return void
	 */
	public function test_an_enum_of_integers_is_accepted() {
		$condition = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array(
				self::FIELD_NAME => $this->schema(
					array(
						'type'    => 'enum',
						'enum'    => array( 1, 2, 3 ),
						'default' => 2,
						'control' => 'select',
					)
				),
			)
		);

		$this->assertSame(
			array( 1, 2, 3 ),
			$condition->fields[ self::FIELD_NAME ]['enum'],
			'Integers are documented as permitted enum members and must register unchanged.'
		);
	}

	/**
	 * An enum key on a field that is not an enum is refused, and the type named.
	 *
	 * @dataProvider data_types_other_than_enum
	 *
	 * @param string $type   Field type under test.
	 * @param array  $extras Schema keys the type requires.
	 * @return void
	 */
	public function test_enum_on_a_non_enum_field_is_rejected( string $type, array $extras ) {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array_merge(
							array(
								'type' => $type,
								'enum' => array( 'a', 'b' ),
							),
							$extras
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'It declares enum but its type is `' . $type . '`' ),
			'Only an enum field lists its permitted values; anywhere else the list would be silently ignored.'
		);
	}

	/**
	 * Every type except enum, with whatever else that type requires.
	 *
	 * @return array[] Type name => array( type, extra schema keys ).
	 */
	public function data_types_other_than_enum() {
		return array(
			'string'  => array( 'string', array() ),
			'integer' => array( 'integer', array() ),
			'number'  => array( 'number', array() ),
			'boolean' => array( 'boolean', array() ),
			'array'   => array(
				'array',
				array(
					'items'   => 'string',
					'control' => 'multiselect',
				),
			),
		);
	}

	/**
	 * A field with no usable label is refused.
	 *
	 * @dataProvider data_rejected_field_labels
	 *
	 * @param array $overrides Schema keys replacing the valid label.
	 * @return void
	 */
	public function test_a_field_must_declare_a_non_empty_label( array $overrides ) {
		$schema = $this->schema();
		unset( $schema['label'] );

		$this->assert_rejected(
			$this->registering( array( self::FIELD_NAME => array_merge( $schema, $overrides ) ) ),
			array( self::FIELD_PREAMBLE, 'It must declare a non-empty label' ),
			'The editor renders the control from this schema alone, so a control without a label is not accessible and must be refused.'
		);
	}

	/**
	 * Labels a field may not declare, including declaring none at all.
	 *
	 * @return array[] Test name => array( schema overrides ).
	 */
	public function data_rejected_field_labels() {
		return array(
			'missing'    => array( array() ),
			'empty'      => array( array( 'label' => '' ) ),
			'whitespace' => array( array( 'label' => "  \t " ) ),
			'an integer' => array( array( 'label' => 12 ) ),
			'null'       => array( array( 'label' => null ) ),
			'an array'   => array( array( 'label' => array( 'Sample' ) ) ),
			'a boolean'  => array( array( 'label' => true ) ),
		);
	}

	/**
	 * A field with no renderable control is refused.
	 *
	 * @dataProvider data_rejected_controls
	 *
	 * @param array $overrides Schema keys replacing the valid control.
	 * @return void
	 */
	public function test_a_field_must_declare_a_supported_control( array $overrides ) {
		$schema = $this->schema();
		unset( $schema['control'] );

		$this->assert_rejected(
			$this->registering( array( self::FIELD_NAME => array_merge( $schema, $overrides ) ) ),
			array( self::FIELD_PREAMBLE, 'It must declare a control of one of:', 'text, number, range' ),
			'A control name absent from the shared map has no renderer, so accepting it would produce an empty panel rather than an error.'
		);
	}

	/**
	 * Controls a field may not declare, including declaring none at all.
	 *
	 * @return array[] Test name => array( schema overrides ).
	 */
	public function data_rejected_controls() {
		return array(
			'missing'         => array( array() ),
			'an HTML element' => array( array( 'control' => 'textarea' ) ),
			'wrong case'      => array( array( 'control' => 'Text' ) ),
			'underscored'     => array( array( 'control' => 'url_match' ) ),
			'empty'           => array( array( 'control' => '' ) ),
			'null'            => array( array( 'control' => null ) ),
			'an integer'      => array( array( 'control' => 0 ) ),
			'an array'        => array( array( 'control' => array( 'text' ) ) ),
		);
	}

	/**
	 * Every control in the shared map registers.
	 *
	 * Driven from the constant, so adding a control without teaching this suite
	 * how to build a valid schema for it fails here.
	 *
	 * @dataProvider data_field_controls
	 *
	 * @param string $control Control name under test.
	 * @return void
	 */
	public function test_every_supported_control_is_accepted( string $control ) {
		$condition = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array( self::FIELD_NAME => $this->schema( array( 'control' => $control ) ) )
		);

		$this->assertSame(
			$control,
			$condition->fields[ self::FIELD_NAME ]['control'],
			'Every control listed in FIELD_CONTROLS must be registrable; a listed control the validator refuses is a renderer nobody can reach.'
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
	 * A default that matches its declared type registers.
	 *
	 * @dataProvider data_matching_defaults
	 *
	 * @param array $overrides Schema keys describing the field and its default.
	 * @return void
	 */
	public function test_a_matching_default_is_accepted( array $overrides ) {
		$condition = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array( self::FIELD_NAME => $this->schema( $overrides ) )
		);

		$this->assertArrayHasKey(
			'default',
			$condition->fields[ self::FIELD_NAME ],
			'A default matching its declared type must register unchanged.'
		);
	}

	/**
	 * Defaults that satisfy their declared type.
	 *
	 * @return array[] Test name => array( schema overrides ).
	 */
	public function data_matching_defaults() {
		return array(
			'a string'                => array(
				array(
					'type'    => 'string',
					'default' => 'anything',
				),
			),
			'an empty string'         => array(
				array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'an integer'              => array(
				array(
					'type'    => 'integer',
					'default' => 7,
				),
			),
			'a negative integer'      => array(
				array(
					'type'    => 'integer',
					'default' => -7,
				),
			),
			'an integer for a number' => array(
				array(
					'type'    => 'number',
					'default' => 7,
				),
			),
			'a float for a number'    => array(
				array(
					'type'    => 'number',
					'default' => 7.5,
				),
			),
			'false'                   => array(
				array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'true'                    => array(
				array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'an empty list'           => array(
				array(
					'type'    => 'array',
					'items'   => 'string',
					'control' => 'multiselect',
					'default' => array(),
				),
			),
			'a list of strings'       => array(
				array(
					'type'    => 'array',
					'items'   => 'string',
					'control' => 'multiselect',
					'default' => array( 'a', 'b' ),
				),
			),
			'a list of integers'      => array(
				array(
					'type'    => 'array',
					'items'   => 'integer',
					'control' => 'multiselect',
					'default' => array( 1, 2 ),
				),
			),
			'an enum member'          => array(
				array(
					'type'    => 'enum',
					'enum'    => array( 'exact', 'prefix' ),
					'control' => 'select',
					'default' => 'prefix',
				),
			),
		);
	}

	/**
	 * A null default means no default and is accepted for every type.
	 *
	 * @dataProvider data_field_types
	 *
	 * @param string $type Field type under test.
	 * @return void
	 */
	public function test_a_null_default_is_accepted( string $type ) {
		$schema            = $this->schema_for_type( $type );
		$schema['default'] = null;

		$condition = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array( self::FIELD_NAME => $schema )
		);

		$this->assertNull(
			$condition->fields[ self::FIELD_NAME ]['default'],
			'Null is the documented way to say a field has no default, and it must be accepted for every type.'
		);
	}

	/**
	 * A default of the wrong type is refused, and both types are named.
	 *
	 * @dataProvider data_mismatched_defaults
	 *
	 * @param array  $overrides     Schema keys describing the field and its default.
	 * @param string $expected_type Type name the message must report for the default.
	 * @param string $declared_type Declared field type the message must report.
	 * @return void
	 */
	public function test_a_default_of_the_wrong_type_is_rejected( array $overrides, string $expected_type, string $declared_type ) {
		$this->assert_rejected(
			$this->registering( array( self::FIELD_NAME => $this->schema( $overrides ) ) ),
			array(
				self::FIELD_PREAMBLE,
				'Its default is ' . $expected_type,
				'does not satisfy the declared type `' . $declared_type . '`',
			),
			'A default of the wrong type would be handed straight to a type-derived sanitizer, so it must be refused and both types named.'
		);
	}

	/**
	 * Defaults that contradict their declared type.
	 *
	 * @return array[] Test name => array( overrides, default type name, declared type ).
	 */
	public function data_mismatched_defaults() {
		return array(
			'an integer for a string' => array(
				array(
					'type'    => 'string',
					'default' => 1,
				),
				'int',
				'string',
			),
			'a float for an integer'  => array(
				array(
					'type'    => 'integer',
					'default' => 1.5,
				),
				'float',
				'integer',
			),
			'a numeric string'        => array(
				array(
					'type'    => 'number',
					'default' => '1',
				),
				'string',
				'number',
			),
			'one for a boolean'       => array(
				array(
					'type'    => 'boolean',
					'default' => 1,
				),
				'int',
				'boolean',
			),
			'a string for a boolean'  => array(
				array(
					'type'    => 'boolean',
					'default' => 'true',
				),
				'string',
				'boolean',
			),
			'a bool for a string'     => array(
				array(
					'type'    => 'string',
					'default' => true,
				),
				'bool',
				'string',
			),
		);
	}

	/**
	 * A default outside the enum is refused, and the permitted values are listed.
	 *
	 * @dataProvider data_defaults_outside_the_enum
	 *
	 * @param mixed $default_value Value offered as a default.
	 * @return void
	 */
	public function test_a_default_outside_the_enum_is_rejected( $default_value ) {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'    => 'enum',
							'enum'    => array( 'exact', 'prefix' ),
							'control' => 'select',
							'default' => $default_value,
						)
					),
				)
			),
			array(
				self::FIELD_PREAMBLE,
				'is not one of the permitted values',
				'`exact`, `prefix`',
			),
			'A default of the right PHP type can still name a value the field would refuse, so the enum comparison must be by value and strict.'
		);
	}

	/**
	 * Defaults an enum field would itself refuse.
	 *
	 * @return array[] Test name => array( default ).
	 */
	public function data_defaults_outside_the_enum() {
		return array(
			'an unlisted value' => array( 'contains' ),
			'wrong case'        => array( 'Exact' ),
			'an integer'        => array( 0 ),
			'a boolean'         => array( true ),
			'empty'             => array( '' ),
		);
	}

	/**
	 * An array default must be a flat list of the declared item type.
	 *
	 * @dataProvider data_rejected_array_defaults
	 *
	 * @param string $items         Declared item type.
	 * @param mixed  $default_value Value offered as a default.
	 * @return void
	 */
	public function test_an_array_default_must_be_a_flat_list_of_the_item_type( string $items, $default_value ) {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'    => 'array',
							'items'   => $items,
							'control' => 'multiselect',
							'default' => $default_value,
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'Its default must be a flat list of ' . $items . ' values' ),
			'An array default of the wrong shape would be handed to a sanitizer derived for a different shape, so it must be refused.'
		);
	}

	/**
	 * Array defaults that contradict the declared item type or flatness.
	 *
	 * @return array[] Test name => array( items, default ).
	 */
	public function data_rejected_array_defaults() {
		return array(
			'a map, not a list'      => array( 'string', array( 'a' => 'b' ) ),
			'a sparse list'          => array( 'string', array( 2 => 'a' ) ),
			'a mixed list'           => array( 'string', array( 'a', 1 ) ),
			'integers for strings'   => array( 'string', array( 1, 2 ) ),
			'strings for integers'   => array( 'integer', array( '1', '2' ) ),
			'a nested list'          => array( 'string', array( array( 'a' ) ) ),
			'a bare scalar'          => array( 'string', 'a' ),
			'a list containing null' => array( 'string', array( 'a', null ) ),
		);
	}

	/**
	 * The items key is settled before the default is judged against it.
	 *
	 * The order of the checks is load bearing: judging an array default first
	 * would read an items key that validation has not yet proved exists.
	 *
	 * @return void
	 */
	public function test_items_are_settled_before_the_default() {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'    => 'array',
							'control' => 'multiselect',
							'default' => array( 'a' ),
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'An array field must declare items as one of:' ),
			'The missing items must be reported. Judging the default first would read a schema key that is not there.'
		);
	}

	/**
	 * The enum list is settled before the default is judged against it.
	 *
	 * @return void
	 */
	public function test_the_enum_list_is_settled_before_the_default() {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'    => 'enum',
							'control' => 'select',
							'default' => 'exact',
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'An enum field must declare enum as a non-empty list' ),
			'The missing enum list must be reported. Judging the default first would compare against a schema key that is not there.'
		);
	}

	/**
	 * Bounds are permitted only on numeric fields.
	 *
	 * @dataProvider data_unbounded_types
	 *
	 * @param string $type   Field type under test.
	 * @param array  $extras Schema keys the type requires.
	 * @param string $bound  Bound key under test.
	 * @return void
	 */
	public function test_bounds_are_only_permitted_on_numeric_fields( string $type, array $extras, string $bound ) {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array_merge(
							array(
								'type' => $type,
								$bound => 1,
							),
							$extras
						)
					),
				)
			),
			array(
				self::FIELD_PREAMBLE,
				'It declares min or max but its type is `' . $type . '`',
				'integer, number',
			),
			'A bound on a non-numeric field would leave sanitization guessing whether it meant a value, a length or a count, so it must be refused.'
		);
	}

	/**
	 * Every non-numeric type paired with each bound key.
	 *
	 * @return array[] Test name => array( type, extra schema keys, bound key ).
	 */
	public function data_unbounded_types() {
		$types = array(
			'string'  => array(),
			'boolean' => array(),
			'array'   => array(
				'items'   => 'string',
				'control' => 'multiselect',
			),
			'enum'    => array(
				'enum'    => array( 'a', 'b' ),
				'control' => 'select',
			),
		);

		$cases = array();

		foreach ( $types as $type => $extras ) {
			foreach ( array( 'min', 'max' ) as $bound ) {
				$cases[ $type . ' with ' . $bound ] = array( $type, $extras, $bound );
			}
		}

		return $cases;
	}

	/**
	 * A bound that is not a number is refused, and its type is named.
	 *
	 * @dataProvider data_rejected_bounds
	 *
	 * @param string $type          Declared field type.
	 * @param string $bound         Bound key under test.
	 * @param mixed  $value         Value offered as a bound.
	 * @param string $expected_type Type name the message must report.
	 * @return void
	 */
	public function test_a_bound_must_be_a_number( string $type, string $bound, $value, string $expected_type ) {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type' => $type,
							$bound => $value,
						)
					),
				)
			),
			array(
				self::FIELD_PREAMBLE,
				'Its ' . $bound . ' bound is ' . $expected_type,
				'must be a number',
			),
			'A bound that is not a number cannot be compared against a stored value, and the message must name which bound and what arrived.'
		);
	}

	/**
	 * Bounds that are not usable numbers, including a float on an integer field.
	 *
	 * The float case is the one worth keeping: an integer field whose bound is a
	 * float describes a limit no integer can sit exactly on.
	 *
	 * @return array[] Test name => array( type, bound key, value, type name ).
	 */
	public function data_rejected_bounds() {
		return array(
			'a numeric string min'    => array( 'integer', 'min', '5', 'string' ),
			'a numeric string max'    => array( 'number', 'max', '5', 'string' ),
			'a float on an integer'   => array( 'integer', 'min', 1.5, 'float' ),
			'a round float on an int' => array( 'integer', 'max', 100.0, 'float' ),
			'a boolean min'           => array( 'integer', 'min', true, 'bool' ),
			'a null max'              => array( 'number', 'max', null, 'null' ),
			'an array min'            => array( 'number', 'min', array( 1 ), 'array' ),
		);
	}

	/**
	 * A float bound is accepted on a number field, where it is meaningful.
	 *
	 * The mirror of the rejected float bound on an integer field: together they
	 * pin that the bound check reads the declared type rather than accepting any
	 * numeric value everywhere.
	 *
	 * @return void
	 */
	public function test_a_float_bound_is_accepted_on_a_number_field() {
		$condition = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array(
				self::FIELD_NAME => $this->schema(
					array(
						'type'    => 'number',
						'control' => 'number',
						'min'     => 0.5,
						'max'     => 1.5,
					)
				),
			)
		);

		$this->assertSame( 0.5, $condition->fields[ self::FIELD_NAME ]['min'], 'A float bound is meaningful on a number field and must register.' );
		$this->assertSame( 1.5, $condition->fields[ self::FIELD_NAME ]['max'], 'A float bound is meaningful on a number field and must register.' );
	}

	/**
	 * A range whose minimum exceeds its maximum is refused.
	 *
	 * @dataProvider data_inverted_ranges
	 *
	 * @param string $type Declared field type.
	 * @param mixed  $min  Lower bound.
	 * @param mixed  $max  Upper bound.
	 * @return void
	 */
	public function test_an_inverted_range_is_rejected( string $type, $min, $max ) {
		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'    => $type,
							'control' => 'range',
							'min'     => $min,
							'max'     => $max,
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'Its min bound is greater than its max bound' ),
			'A range no value could satisfy is a registration bug, so it must be refused rather than stored for a sanitizer to puzzle over.'
		);
	}

	/**
	 * Ranges that exclude every value.
	 *
	 * @return array[] Test name => array( type, min, max ).
	 */
	public function data_inverted_ranges() {
		return array(
			'integers by one'     => array( 'integer', 2, 1 ),
			'integers by many'    => array( 'integer', 100, 1 ),
			'negative integers'   => array( 'integer', -1, -2 ),
			'floats'              => array( 'number', 1.5, 0.5 ),
			'a float over an int' => array( 'number', 1.1, 1 ),
		);
	}

	/**
	 * A minimum equal to its maximum is accepted.
	 *
	 * The other half of the inverted-range boundary: a comparison flipped from
	 * `>` to `>=` fails here rather than passing both tests.
	 *
	 * @return void
	 */
	public function test_an_equal_minimum_and_maximum_is_accepted() {
		$condition = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array(
				self::FIELD_NAME => $this->schema(
					array(
						'type'    => 'integer',
						'control' => 'number',
						'min'     => 5,
						'max'     => 5,
					)
				),
			)
		);

		$this->assertSame(
			5,
			$condition->fields[ self::FIELD_NAME ]['min'],
			'Bounds are inclusive, so a single permitted value is expressible and must register.'
		);
	}

	/**
	 * Either bound may be declared alone.
	 *
	 * @dataProvider data_single_bounds
	 *
	 * @param string $bound Bound key under test.
	 * @return void
	 */
	public function test_a_single_bound_is_accepted( string $bound ) {
		$condition = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array(
				self::FIELD_NAME => $this->schema(
					array(
						'type'    => 'integer',
						'control' => 'number',
						$bound    => 10,
					)
				),
			)
		);

		$this->assertSame(
			10,
			$condition->fields[ self::FIELD_NAME ][ $bound ],
			'A one-sided bound is a legitimate declaration and must not require its opposite.'
		);
	}

	/**
	 * Each bound key on its own.
	 *
	 * @return array[] Test name => array( bound key ).
	 */
	public function data_single_bounds() {
		return array(
			'min only' => array( 'min' ),
			'max only' => array( 'max' ),
		);
	}

	/**
	 * A string field may declare a length cap, and the cap registers unchanged.
	 *
	 * `max_length` is checked for membership of FIELD_SCHEMA_KEYS first: this
	 * suite is written alongside the key, and skipping loudly is better than
	 * asserting against a vocabulary the class does not have.
	 *
	 * @return void
	 */
	public function test_a_string_field_may_declare_a_length_cap() {
		$this->requires_max_length();

		$condition = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array(
				self::FIELD_NAME => $this->schema(
					array(
						'type'       => 'string',
						'max_length' => 255,
					)
				),
			)
		);

		$this->assertSame(
			255,
			$condition->fields[ self::FIELD_NAME ]['max_length'],
			'A declared length cap must register unchanged; it is the only way to say how long a stored string may be.'
		);
	}

	/**
	 * A length cap that is not a positive integer is refused.
	 *
	 * @dataProvider data_rejected_max_lengths
	 *
	 * @param mixed $max_length Value offered as a cap.
	 * @return void
	 */
	public function test_a_length_cap_must_be_a_positive_integer( $max_length ) {
		$this->requires_max_length();

		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'       => 'string',
							'max_length' => $max_length,
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'A length cap must be a positive integer number of bytes' ),
			'A cap of zero or less describes a field that can never hold a usable value, and a non-integer cap cannot be compared against a byte length.'
		);
	}

	/**
	 * Caps that describe no usable field.
	 *
	 * @return array[] Test name => array( value ).
	 */
	public function data_rejected_max_lengths() {
		return array(
			'zero'             => array( 0 ),
			'negative'         => array( -1 ),
			'a numeric string' => array( '255' ),
			'a float'          => array( 255.0 ),
			'null'             => array( null ),
			'a boolean'        => array( true ),
			'an array'         => array( array( 255 ) ),
		);
	}

	/**
	 * A cap of one byte is accepted, pinning the lower boundary as inclusive.
	 *
	 * @return void
	 */
	public function test_a_length_cap_of_one_is_accepted() {
		$this->requires_max_length();

		$condition = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array(
				self::FIELD_NAME => $this->schema(
					array(
						'type'       => 'string',
						'max_length' => 1,
					)
				),
			)
		);

		$this->assertSame(
			1,
			$condition->fields[ self::FIELD_NAME ]['max_length'],
			'One byte is a positive cap. Refusing it would mean the guard rejects a legitimate, if narrow, declaration.'
		);
	}

	/**
	 * A length cap on a field that is not a string is refused.
	 *
	 * @dataProvider data_types_other_than_string
	 *
	 * @param string $type   Field type under test.
	 * @param array  $extras Schema keys the type requires.
	 * @return void
	 */
	public function test_a_length_cap_on_a_non_string_field_is_rejected( string $type, array $extras ) {
		$this->requires_max_length();

		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array_merge(
							array(
								'type'       => $type,
								'max_length' => 10,
							),
							$extras
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'It declares max_length but its type is `' . $type . '`', 'string' ),
			'A cap measures a stored string; on an array it would silently mean a count, which is exactly the second meaning the vocabulary refuses to carry.'
		);
	}

	/**
	 * Every type except string, with whatever else that type requires.
	 *
	 * @return array[] Type name => array( type, extra schema keys ).
	 */
	public function data_types_other_than_string() {
		return array(
			'integer' => array( 'integer', array() ),
			'number'  => array( 'number', array() ),
			'boolean' => array( 'boolean', array() ),
			'array'   => array(
				'array',
				array(
					'items'   => 'string',
					'control' => 'multiselect',
				),
			),
			'enum'    => array(
				'enum',
				array(
					'enum'    => array( 'a', 'b' ),
					'control' => 'select',
				),
			),
		);
	}

	/**
	 * A default longer than the cap is refused; one of exactly the cap is not.
	 *
	 * Asserted as a pair so the byte comparison cannot drift by one in either
	 * direction without failing.
	 *
	 * @return void
	 */
	public function test_a_default_may_not_exceed_the_length_cap() {
		$this->requires_max_length();

		$exact = new Condition(
			self::OWNER_KEY,
			Context::Server,
			'content',
			'Sample',
			array(
				self::FIELD_NAME => $this->schema(
					array(
						'type'       => 'string',
						'max_length' => 4,
						'default'    => 'abcd',
					)
				),
			)
		);

		$this->assertSame(
			'abcd',
			$exact->fields[ self::FIELD_NAME ]['default'],
			'A default of exactly the cap fits, and a cap is inclusive.'
		);

		$this->assert_rejected(
			$this->registering(
				array(
					self::FIELD_NAME => $this->schema(
						array(
							'type'       => 'string',
							'max_length' => 4,
							'default'    => 'abcde',
						)
					),
				)
			),
			array( self::FIELD_PREAMBLE, 'exceeds its max_length of 4' ),
			'A default the field itself would reject can never be stored, so it must be refused at registration.'
		);
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
				'label'   => 'Percent',
				'control' => 'range',
				'min'     => 1,
				'max'     => 100,
			),
		);

		$condition = new Condition( 'scroll_reached', Context::Client, 'visitor', 'Scroll reached', $fields );

		$this->assertSame(
			array(
				'key'     => 'scroll_reached',
				'context' => 'client',
				'group'   => 'visitor',
				'label'   => 'Scroll reached',
				'fields'  => $fields,
			),
			$condition->to_schema(),
			'The registry payload is a documented contract with REST and the editor: the same five keys, the context flattened to its string value, and nothing escaped.'
		);
	}

	/**
	 * The to_schema() payload is stable across calls and shares no state.
	 *
	 * @return void
	 */
	public function test_to_schema_is_stable_across_calls() {
		$condition = new Condition(
			'is_front_page',
			Context::Server,
			'content',
			'Front page',
			array( self::FIELD_NAME => $this->schema() )
		);

		$first  = $condition->to_schema();
		$second = $condition->to_schema();

		$this->assertSame(
			$first,
			$second,
			'Two calls must produce identical payloads. Anything that varies between them — a reordered field map, a generated identifier — would make the registry route uncacheable and the editor state unstable.'
		);

		$first['key']    = 'mutated';
		$first['fields'] = array();

		$this->assertSame(
			array(
				'key'     => 'is_front_page',
				'context' => 'server',
				'group'   => 'content',
				'label'   => 'Front page',
				'fields'  => array( self::FIELD_NAME => $this->schema() ),
			),
			$condition->to_schema(),
			'A caller editing the payload it was handed must not be able to reach the registration behind it; a registry that hands out mutable shared state is a registry that can be rewritten by a consumer.'
		);
	}

	/**
	 * The payload survives a JSON round trip unchanged.
	 *
	 * @return void
	 */
	public function test_to_schema_survives_a_json_round_trip() {
		$condition = new Condition( 'is_front_page', Context::Server, 'content', 'Front page', array() );
		$encoded   = wp_json_encode( $condition->to_schema() );

		$this->assertIsString( $encoded, 'The payload must be JSON-serializable; REST and the editor consume nothing else.' );
		$this->assertStringContainsString(
			'"context":"server"',
			$encoded,
			'The context must encode as a string. An enum case would arrive on the client as an object needing a lookup table.'
		);
		$this->assertStringContainsString(
			'"fields":[]',
			$encoded,
			'A condition with no fields encodes as an empty JSON array, exactly as documented. A caller needing an object casts at encode time.'
		);
		$this->assertSame(
			$condition->to_schema(),
			json_decode( $encoded, true ),
			'The payload must decode back to itself.'
		);
	}

	/**
	 * A registered condition cannot be rewritten by later code.
	 *
	 * @return void
	 */
	public function test_a_registered_condition_cannot_be_mutated() {
		$condition = new Condition( 'is_front_page', Context::Server, 'content', 'Front page', array() );

		try {
			$condition->key = 'something_else';
		} catch ( Error $error ) {
			$this->assertStringContainsString(
				'readonly',
				$error->getMessage(),
				'The refusal must come from the readonly declaration, not from some unrelated failure.'
			);

			return;
		}

		$this->fail( 'Every property is readonly so a registration cannot be rewritten by later code and the registry cannot hand out a mutable reference to shared state.' );
	}

	/**
	 * Asserts that a registration is refused with a message naming its cause.
	 *
	 * Every needle is asserted rather than only the first, because a message that
	 * proves a throw happened but not which guard threw cannot tell a working
	 * validator from a mistake in the fixture.
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
	 * Returns a closure registering a condition with the given fields.
	 *
	 * @param array $fields Field name => field schema.
	 * @return callable Closure that performs the registration.
	 */
	private function registering( array $fields ) {
		return static function () use ( $fields ) {
			new Condition( self::OWNER_KEY, Context::Server, 'content', 'Sample condition', $fields );
		};
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

	/**
	 * Builds the smallest valid schema for one field type.
	 *
	 * @param string $type Field type.
	 * @return array Field schema.
	 */
	private function schema_for_type( string $type ) {
		$extras = match ( $type ) {
			'array' => array(
				'items'   => 'string',
				'control' => 'multiselect',
			),
			'enum'  => array(
				'enum'    => array( 'exact', 'prefix' ),
				'control' => 'select',
			),
			default => array(),
		};

		return $this->schema( array_merge( array( 'type' => $type ), $extras ) );
	}

	/**
	 * Skips the calling test when the vocabulary has no length cap.
	 *
	 * @return void
	 */
	private function requires_max_length() {
		if ( ! in_array( 'max_length', Condition::FIELD_SCHEMA_KEYS, true ) ) {
			$this->markTestSkipped( 'The field schema vocabulary does not declare max_length.' );
		}
	}
}
