<?php
/**
 * Unit tests for the structural bounds on unregistered rule values.
 *
 * `Popkit\Rule_Bounds` is what stops "preserve rules whose type is not
 * registered" from meaning "store whatever arrives". Its job is narrow and its
 * failure modes are specific, so the tests below are organized around two
 * properties rather than around method coverage.
 *
 * **Nothing is truncated.** Every limit is asserted from both sides: the value at
 * the limit is accepted, and the value one step past it refuses the whole rule
 * and names the limit it hit.
 *
 * Refusal is the only half of that property `check()` can express. It takes its
 * argument by value and answers with a verdict, so no implementation of it could
 * mutate a caller's payload and an assertion that the payload "came back
 * unchanged" would hold however badly the class behaved. Truncation is only
 * observable one layer up, at the single caller that turns a verdict into
 * something stored, so that is where it is asserted: the two sanitizer tests at
 * the bottom of this file push an over-bounds rule through
 * `Popkit\Sanitizer::sanitize_rule()` and `Popkit\Sanitizer::sanitize_rule_set()`
 * and demand that the write is refused and that no fragment of the refused rule —
 * tracked by a canary string planted in it — survives into the stored set. Their
 * counterpart asserts that a rule sitting exactly on the limits is stored with
 * its values intact, so the canary is a real discriminator rather than a string
 * that never appears in either outcome.
 *
 * A half-preserved targeting rule is more dangerous than a refused one because it
 * looks intact: an author would see a rule in the editor, believe it was
 * narrowing the audience, and it would not be.
 *
 * **Nothing recurses.** A payload nested a thousand levels deep is the obvious
 * attack on any structural validator, and the obvious implementation — a
 * recursive walk — answers it with a stack exhaustion, which in PHP is a fatal
 * error rather than a catchable one. `Rule_Bounds` uses an explicit queue and
 * checks the depth limit before enqueuing children, and that is asserted here
 * with a real thousand-level payload rather than reasoned about.
 *
 * The limits themselves come from `docs/data-model.md` -> Bounds on unknown rule
 * values. They are asserted against literal numbers once, at the top, so that a
 * change to a constant fails a test naming the document instead of silently
 * relaxing every boundary case below it.
 *
 * @package Popkit
 */

use PHPUnit\Framework\TestCase;
use Popkit\Rule_Bounds;
use Popkit\Sanitizer;

/*
 * Load the classes directly. Guarded so a bootstrap that does register an
 * autoloader does not end up loading either file twice.
 *
 * Sanitizer is loaded because it is where this class's refusals become
 * observable; see the note on truncation in the file docblock.
 */
if ( ! class_exists( Rule_Bounds::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/class-rule-bounds.php';
}

if ( ! class_exists( Sanitizer::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/class-sanitizer.php';
}

/**
 * Asserts each structural limit, from both sides, and the refusal contract.
 */
final class Test_Popkit_Rule_Bounds extends TestCase {

	/**
	 * Byte overhead of a JSON list of five strings.
	 *
	 * Two brackets, four commas and ten quotes, so the encoded size of such a
	 * list is this plus the total length of its strings. Used to build payloads
	 * that land on an exact byte count; the tests assert the arithmetic held
	 * rather than trusting it.
	 *
	 * @var int
	 */
	private const FIVE_STRING_LIST_OVERHEAD = 16;

	/**
	 * A rule type nothing registers, standing in for a deactivated extension.
	 *
	 * Rules of a registered type are validated against a field schema, so only an
	 * unregistered one reaches Rule_Bounds at all.
	 *
	 * @var string
	 */
	private const UNREGISTERED_TYPE = 'popkit_test_bounds_absent';

	/**
	 * String planted in every payload the sanitizer tests submit.
	 *
	 * Distinctive enough that its presence anywhere in the encoded rule set means
	 * a fragment of the submitted payload survived.
	 *
	 * @var string
	 */
	private const CANARY = 'canary';

	/**
	 * The limits are the ones the data model documents.
	 *
	 * Every boundary case below is written in terms of these constants, so this
	 * is the single test that would catch a limit being quietly relaxed.
	 *
	 * @return void
	 */
	public function test_the_limits_match_the_data_model() {
		$this->assertSame( 5, Rule_Bounds::MAX_DEPTH, 'Maximum nesting depth is 5.' );
		$this->assertSame( 50, Rule_Bounds::MAX_ITEMS_PER_LEVEL, 'Maximum keys or array items per level is 50.' );
		$this->assertSame( 2000, Rule_Bounds::MAX_STRING_LENGTH, 'Maximum string length is 2,000 characters.' );
		$this->assertSame( 8192, Rule_Bounds::MAX_SERIALIZED_BYTES, 'Maximum serialized size of one rule values object is 8 KB.' );
		$this->assertSame( 50, Rule_Bounds::MAX_RULES_PER_GROUP, 'Maximum rules per group is 50.' );
		$this->assertSame( 20, Rule_Bounds::MAX_GROUPS_PER_SET, 'Maximum groups per set is 20.' );
	}

	/**
	 * A payload nested to the depth limit is accepted.
	 *
	 * @return void
	 */
	public function test_values_nested_to_the_depth_limit_are_accepted() {
		$values = self::nested_values( Rule_Bounds::MAX_DEPTH );

		$this->assertSame(
			true,
			Rule_Bounds::check( $values ),
			sprintf( 'A payload of exactly %d nested arrays is within the documented depth limit and must be accepted.', Rule_Bounds::MAX_DEPTH )
		);
	}

	/**
	 * A payload one level past the depth limit refuses the whole rule.
	 *
	 * @return void
	 */
	public function test_values_nested_past_the_depth_limit_are_rejected() {
		$values = self::nested_values( Rule_Bounds::MAX_DEPTH + 1 );

		$result = Rule_Bounds::check( $values );

		$this->assert_whole_rule_refused( $result, 'depth', Rule_Bounds::MAX_DEPTH );

		$this->assertStringContainsString(
			'values.level1',
			(string) $result->get_error_data()['path'],
			'The reported path must lead the author to the offending value rather than naming the rule as a whole.'
		);
	}

	/**
	 * A level holding exactly the item limit is accepted.
	 *
	 * @return void
	 */
	public function test_a_level_at_the_item_limit_is_accepted() {
		$values = array_fill( 0, Rule_Bounds::MAX_ITEMS_PER_LEVEL, 'x' );

		$this->assertCount( Rule_Bounds::MAX_ITEMS_PER_LEVEL, $values, 'Fixture drift: the payload must hold exactly the limit.' );
		$this->assertSame(
			true,
			Rule_Bounds::check( $values ),
			sprintf( 'A level holding exactly %d items is within the limit and must be accepted.', Rule_Bounds::MAX_ITEMS_PER_LEVEL )
		);
	}

	/**
	 * A level holding one item too many refuses the whole rule.
	 *
	 * @return void
	 */
	public function test_a_level_past_the_item_limit_is_rejected() {
		$values = array_fill( 0, Rule_Bounds::MAX_ITEMS_PER_LEVEL + 1, 'x' );

		$result = Rule_Bounds::check( $values );

		$this->assert_whole_rule_refused( $result, 'items', Rule_Bounds::MAX_ITEMS_PER_LEVEL );

		$this->assertStringContainsString(
			(string) ( Rule_Bounds::MAX_ITEMS_PER_LEVEL + 1 ),
			$result->get_error_message(),
			'The message must report how many items actually arrived, so an author reading it can see the size of the payload that was refused rather than only the limit.'
		);
	}

	/**
	 * The item limit applies at every level, not only the outermost.
	 *
	 * @return void
	 */
	public function test_the_item_limit_applies_at_a_nested_level() {
		$values = array(
			'audience' => array(
				'tags' => array_fill( 0, Rule_Bounds::MAX_ITEMS_PER_LEVEL + 1, 'x' ),
			),
		);

		$result = Rule_Bounds::check( $values );

		$this->assert_whole_rule_refused( $result, 'items', Rule_Bounds::MAX_ITEMS_PER_LEVEL );

		$this->assertSame(
			'values.audience.tags',
			$result->get_error_data()['path'],
			'The path must name the nested level that broke the limit.'
		);
	}

	/**
	 * A string of exactly the maximum length is accepted.
	 *
	 * @return void
	 */
	public function test_a_string_at_the_length_limit_is_accepted() {
		$values = array( 'label' => str_repeat( 'a', Rule_Bounds::MAX_STRING_LENGTH ) );

		$this->assertSame(
			true,
			Rule_Bounds::check( $values ),
			sprintf( 'A string of exactly %d characters is within the limit and must be accepted.', Rule_Bounds::MAX_STRING_LENGTH )
		);
	}

	/**
	 * A string one character too long refuses the whole rule.
	 *
	 * @return void
	 */
	public function test_a_string_past_the_length_limit_is_rejected() {
		$values = array(
			'keep'  => 'a value the author cares about',
			'label' => str_repeat( 'a', Rule_Bounds::MAX_STRING_LENGTH + 1 ),
		);

		$result = Rule_Bounds::check( $values );

		$this->assert_whole_rule_refused( $result, 'string_length', Rule_Bounds::MAX_STRING_LENGTH );

		$this->assertSame(
			'values.label',
			$result->get_error_data()['path'],
			'The path must name the offending string.'
		);
		$this->assertStringContainsString(
			(string) ( Rule_Bounds::MAX_STRING_LENGTH + 1 ),
			$result->get_error_message(),
			'The message must report the length that actually arrived, which is also what tells an author the string was measured rather than shortened.'
		);
	}

	/**
	 * A payload encoding to exactly the size limit is accepted.
	 *
	 * @return void
	 */
	public function test_values_encoding_to_exactly_the_size_limit_are_accepted() {
		$values = self::values_of_encoded_size( Rule_Bounds::MAX_SERIALIZED_BYTES );

		$this->assertSame(
			Rule_Bounds::MAX_SERIALIZED_BYTES,
			strlen( (string) wp_json_encode( $values ) ),
			'Fixture drift: this payload must encode to exactly the limit.'
		);

		$this->assertSame(
			true,
			Rule_Bounds::check( $values ),
			'A payload encoding to exactly the limit is within it and must be accepted.'
		);
	}

	/**
	 * A payload one byte over the size limit refuses the whole rule.
	 *
	 * The payload is deliberately legal in every other respect — five items, each
	 * string within the character limit, one level deep — so the only thing under
	 * test is the serialized size.
	 *
	 * @return void
	 */
	public function test_values_encoding_over_the_size_limit_are_rejected() {
		$values = self::values_of_encoded_size( Rule_Bounds::MAX_SERIALIZED_BYTES + 1 );

		$this->assertSame(
			Rule_Bounds::MAX_SERIALIZED_BYTES + 1,
			strlen( (string) wp_json_encode( $values ) ),
			'Fixture drift: this payload must encode to one byte over the limit.'
		);

		$result = Rule_Bounds::check( $values );

		$this->assert_whole_rule_refused( $result, 'size', Rule_Bounds::MAX_SERIALIZED_BYTES );

		$this->assertStringContainsString(
			(string) ( Rule_Bounds::MAX_SERIALIZED_BYTES + 1 ),
			$result->get_error_message(),
			'The message must report the size that actually arrived. A message quoting the limit as the size found would be the signature of a payload measured after being cut down to fit.'
		);
	}

	/**
	 * A PHP object anywhere in the payload refuses the whole rule.
	 *
	 * Nothing is unserialized and nothing is inspected: an object is refused on
	 * sight, wherever it sits.
	 *
	 * @dataProvider data_payloads_containing_an_object
	 *
	 * @param mixed  $values        Payload under test.
	 * @param string $expected_path Path the error should report.
	 * @return void
	 */
	public function test_a_php_object_in_the_payload_is_rejected( mixed $values, string $expected_path ) {
		$result = Rule_Bounds::check( $values );

		$this->assert_whole_rule_refused( $result, 'type', 0 );

		$this->assertSame(
			$expected_path,
			$result->get_error_data()['path'],
			'The path must name the position of the object.'
		);
		$this->assertStringContainsString(
			'stdClass',
			$result->get_error_message(),
			'The message must name the type that was refused, so the author can see what arrived rather than being told only that something did.'
		);
	}

	/**
	 * Payloads with an object at various positions.
	 *
	 * @return array[] Test name => array( values, expected path ).
	 */
	public function data_payloads_containing_an_object() {
		return array(
			'the values are an object' => array( new stdClass(), 'values' ),
			'an object in a map'       => array( array( 'audience' => new stdClass() ), 'values.audience' ),
			'an object in a list'      => array( array( 'a', new stdClass() ), 'values[1]' ),
			'an object nested deep'    => array(
				array(
					'a' => array(
						'b' => array( 'c' => new stdClass() ),
					),
				),
				'values.a.b.c',
			),
		);
	}

	/**
	 * A resource in the payload refuses the whole rule.
	 *
	 * @return void
	 */
	public function test_a_resource_in_the_payload_is_rejected() {
		$handle = fopen( 'php://memory', 'rb' );

		$this->assertIsResource( $handle, 'Fixture drift: the test needs a real resource to submit.' );

		try {
			$values = array( 'handle' => $handle );
			$result = Rule_Bounds::check( $values );

			$this->assert_whole_rule_refused( $result, 'type', 0 );
			$this->assertSame(
				'values.handle',
				$result->get_error_data()['path'],
				'The path must name the position of the resource.'
			);
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * A number that is not finite refuses the whole rule.
	 *
	 * The permitted types are string, number, boolean, null and arrays of those.
	 * INF and NAN are numbers that no JSON encoder can represent, so accepting
	 * them would store a rule that cannot be read back.
	 *
	 * @dataProvider data_non_finite_numbers
	 *
	 * @param float $number Value to submit.
	 * @return void
	 */
	public function test_a_non_finite_number_is_rejected( float $number ) {
		$values = array( 'score' => $number );

		$result = Rule_Bounds::check( $values );

		$this->assert_whole_rule_refused( $result, 'number', 0 );
	}

	/**
	 * Numbers with no JSON representation.
	 *
	 * @return array[] Test name => array( number ).
	 */
	public function data_non_finite_numbers() {
		return array(
			'positive infinity' => array( INF ),
			'negative infinity' => array( -INF ),
			'not a number'      => array( NAN ),
		);
	}

	/**
	 * Ordinary scalars and nested lists of them are accepted unchanged.
	 *
	 * The bounds exist to refuse the pathological, not to narrow what an
	 * extension may legitimately store. Without this the suite would pass with a
	 * `check()` that refused everything.
	 *
	 * That the values themselves survive is asserted where it can be seen, at the
	 * sanitizer — see
	 * self::test_a_rule_sitting_on_the_limits_is_stored_with_its_values_intact().
	 *
	 * @return void
	 */
	public function test_a_legitimate_payload_is_accepted() {
		$values = array(
			'match'    => 'prefix',
			'value'    => '/campaigns/',
			'weight'   => 42,
			'ratio'    => 0.5,
			'enabled'  => true,
			'disabled' => false,
			'absent'   => null,
			'audience' => array(
				'tags'    => array( 'members', 'donors' ),
				'nested'  => array( 'deeper' => array( 'deepest' => 'ok' ) ),
				'numbers' => array( 1, 2, 3 ),
			),
			'empty'    => array(),
		);

		$this->assertSame(
			true,
			Rule_Bounds::check( $values ),
			'A payload well inside every limit must be accepted.'
		);
	}

	/**
	 * A thousand-level payload returns an error instead of exhausting the stack.
	 *
	 * This is the obvious attack on a structural validator, and the obvious
	 * implementation loses to it: a recursive walk would exhaust the PHP stack,
	 * which is a fatal error rather than a catchable one, so the whole request
	 * dies rather than the rule being refused. Reaching the assertion at all is
	 * most of what this test proves.
	 *
	 * @return void
	 */
	public function test_a_thousand_level_payload_returns_an_error_rather_than_exhausting_the_stack() {
		$values = self::nested_values( 1000 );

		$started = hrtime( true );
		$result  = Rule_Bounds::check( $values );
		$elapsed = ( hrtime( true ) - $started ) / 1000000000;

		$this->assert_whole_rule_refused( $result, 'depth', Rule_Bounds::MAX_DEPTH );

		$this->assertLessThan(
			1.0,
			$elapsed,
			sprintf(
				'The check took %01.4f seconds. The traversal is meant to stop at the depth limit, so the remaining levels should never be visited at all.',
				$elapsed
			)
		);
	}

	/**
	 * A self-referential payload terminates at the depth limit.
	 *
	 * A cycle is the other shape that hangs a naive traversal. The depth limit is
	 * checked before children are enqueued, so the walk stops rather than
	 * following the loop forever.
	 *
	 * @return void
	 */
	public function test_a_self_referential_payload_terminates() {
		$values         = array( 'name' => 'loop' );
		$values['self'] = &$values;

		$started = hrtime( true );
		$result  = Rule_Bounds::check( $values );
		$elapsed = ( hrtime( true ) - $started ) / 1000000000;

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			'A payload that contains itself exceeds the depth limit and must be refused.'
		);
		$this->assertSame(
			'depth',
			$result->get_error_data()['limit'],
			'The cycle should be caught by the depth limit, which is what bounds the traversal.'
		);
		$this->assertLessThan(
			1.0,
			$elapsed,
			sprintf( 'The check took %01.4f seconds; a cycle must not be followed past the depth limit.', $elapsed )
		);
	}

	/**
	 * Every refusal names the limit it hit, in machine-readable data and in prose.
	 *
	 * The data is what code branches on; the message is what the editor shows an
	 * author. Both are asserted because a refusal the author cannot act on is
	 * only marginally better than a silent one.
	 *
	 * @dataProvider data_refusals
	 *
	 * @param mixed  $values Payload to submit.
	 * @param string $limit  Machine name of the limit expected to be hit.
	 * @param int    $max    The limit's numeric value, or 0 where it has none.
	 * @return void
	 */
	public function test_every_refusal_names_the_limit_it_hit( mixed $values, string $limit, int $max ) {
		$result = Rule_Bounds::check( $values );

		$this->assertInstanceOf( WP_Error::class, $result, sprintf( 'The %s limit must produce a WP_Error.', $limit ) );

		$data    = $result->get_error_data();
		$message = $result->get_error_message();

		$this->assertSame( $limit, $data['limit'], 'The error data must name the limit that was hit.' );
		$this->assertSame( $max, $data['max'], 'The error data must carry the limit value.' );
		$this->assertNotSame( '', $message, sprintf( 'The %s limit must carry an editor-facing message.', $limit ) );
		$this->assertNotSame(
			'',
			$data['path'],
			'The error data must locate the offending value, so the author can find it in a rule they did not necessarily write.'
		);

		if ( 0 !== $max ) {
			$this->assertStringContainsString(
				(string) $max,
				$message,
				sprintf( 'The message for the %s limit must state the limit itself. "Too large" without a number is not something an author can act on.', $limit )
			);
		}
	}

	/**
	 * One payload per limit that produces a refusal.
	 *
	 * @return array[] Test name => array( values, limit name, limit value ).
	 */
	public function data_refusals() {
		return array(
			'depth'         => array( self::nested_values( Rule_Bounds::MAX_DEPTH + 1 ), 'depth', Rule_Bounds::MAX_DEPTH ),
			'items'         => array( array_fill( 0, Rule_Bounds::MAX_ITEMS_PER_LEVEL + 1, 'x' ), 'items', Rule_Bounds::MAX_ITEMS_PER_LEVEL ),
			'string length' => array(
				array( 'label' => str_repeat( 'a', Rule_Bounds::MAX_STRING_LENGTH + 1 ) ),
				'string_length',
				Rule_Bounds::MAX_STRING_LENGTH,
			),
			'size'          => array(
				self::values_of_encoded_size( Rule_Bounds::MAX_SERIALIZED_BYTES + 1 ),
				'size',
				Rule_Bounds::MAX_SERIALIZED_BYTES,
			),
			'type'          => array( array( 'thing' => new stdClass() ), 'type', 0 ),
		);
	}

	/**
	 * A group at the rule limit is accepted and one past it is refused.
	 *
	 * @return void
	 */
	public function test_the_rules_per_group_limit() {
		$this->assertSame(
			true,
			Rule_Bounds::check_rule_count( Rule_Bounds::MAX_RULES_PER_GROUP ),
			'A group holding exactly the limit must be accepted.'
		);
		$this->assertSame(
			true,
			Rule_Bounds::check_rule_count( 0 ),
			'An empty group is within the limit.'
		);

		$result = Rule_Bounds::check_rule_count( Rule_Bounds::MAX_RULES_PER_GROUP + 1 );

		$this->assertInstanceOf( WP_Error::class, $result, 'One rule past the limit must refuse the group.' );
		$this->assertSame( Rule_Bounds::ERROR_CODE, $result->get_error_code(), 'Every refusal carries the one error code.' );
		$this->assertSame( 'rules_per_group', $result->get_error_data()['limit'], 'The error data must name the limit.' );
		$this->assertStringContainsString(
			(string) Rule_Bounds::MAX_RULES_PER_GROUP,
			$result->get_error_message(),
			'The message must state the limit.'
		);
	}

	/**
	 * A set at the group limit is accepted and one past it is refused.
	 *
	 * @return void
	 */
	public function test_the_groups_per_set_limit() {
		$this->assertSame(
			true,
			Rule_Bounds::check_group_count( Rule_Bounds::MAX_GROUPS_PER_SET ),
			'A set holding exactly the limit must be accepted.'
		);
		$this->assertSame(
			true,
			Rule_Bounds::check_group_count( 0 ),
			'An empty set is within the limit; the data model reads it as "always match".'
		);

		$result = Rule_Bounds::check_group_count( Rule_Bounds::MAX_GROUPS_PER_SET + 1 );

		$this->assertInstanceOf( WP_Error::class, $result, 'One group past the limit must refuse the set.' );
		$this->assertSame( Rule_Bounds::ERROR_CODE, $result->get_error_code(), 'Every refusal carries the one error code.' );
		$this->assertSame( 'groups_per_set', $result->get_error_data()['limit'], 'The error data must name the limit.' );
		$this->assertStringContainsString(
			(string) Rule_Bounds::MAX_GROUPS_PER_SET,
			$result->get_error_message(),
			'The message must state the limit.'
		);
	}

	/**
	 * A refusal is answered with an error and nothing else.
	 *
	 * `check()` returns a verdict, never a cleaned-up payload, and the WP_Error
	 * it returns carries only the four diagnostic keys. If a salvaged fragment of
	 * the payload ever rode along in the error data, a caller could be tempted to
	 * store it, which is the truncation the data model refuses.
	 *
	 * @return void
	 */
	public function test_a_refusal_returns_a_verdict_and_never_a_salvaged_payload() {
		$values = array(
			'keep'  => 'a value the author cares about',
			'label' => str_repeat( 'a', Rule_Bounds::MAX_STRING_LENGTH + 1 ),
		);

		$result = Rule_Bounds::check( $values );

		$this->assertInstanceOf( WP_Error::class, $result, 'An out-of-bounds payload must be refused.' );

		$this->assertSame(
			array( 'limit', 'path', 'max', 'status' ),
			array_keys( $result->get_error_data() ),
			'The error data is diagnostic only. Any key carrying part of the submitted payload would invite a caller to store the surviving half.'
		);
		$this->assertSame(
			400,
			$result->get_error_data()['status'],
			'The refusal is a bad request, so a REST write reports it as one rather than as a server fault.'
		);
	}

	/**
	 * An over-bounds rule fails the write instead of being cut down to fit.
	 *
	 * The first of the three tests that make the anti-truncation property
	 * observable. `Popkit\Sanitizer` is the only caller that turns a verdict from
	 * this class into something stored, so it is the only place where "the rule
	 * was refused rather than trimmed" can be seen at all.
	 *
	 * @dataProvider data_over_bounds_values_carrying_a_canary
	 *
	 * @param mixed  $values Values submitted with an unregistered rule type.
	 * @param string $limit  Machine name of the limit expected to be reported.
	 * @return void
	 */
	public function test_an_over_bounds_rule_is_refused_by_the_sanitizer_rather_than_trimmed( mixed $values, string $limit ) {
		$result = Sanitizer::sanitize_rule(
			array(
				'type'   => self::UNREGISTERED_TYPE,
				'negate' => false,
				'values' => $values,
			)
		);

		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			sprintf(
				'A rule breaking the %s limit must fail the write with a message the editor can show. Anything else it could return is a rule, and the only rule it could build from this payload is a smaller one than the author submitted.',
				$limit
			)
		);
		$this->assertSame(
			Rule_Bounds::ERROR_CODE,
			$result->get_error_code(),
			'The refusal reaching the sanitizer must be the one this class issues, not a second refusal invented on the way.'
		);
		$this->assertSame(
			$limit,
			$result->get_error_data()['limit'],
			'The limit reported to the caller must be the one that was actually hit.'
		);
	}

	/**
	 * A refused rule leaves nothing of itself in the stored rule set.
	 *
	 * `sanitize_rule_set()` is the storage-level floor: it is reached by a direct
	 * `update_post_meta()` call, has no way to report an error, and must therefore
	 * return something storable whatever arrives. The refused rule is replaced by
	 * the marker rather than removed — removing an ANDed rule widens the group
	 * that held it — and this asserts that the replacement is total, by planting a
	 * canary string in the submitted values and requiring it to be absent from the
	 * encoded set. A surviving fragment is what "truncated" would look like.
	 *
	 * @dataProvider data_over_bounds_values_carrying_a_canary
	 *
	 * @param mixed  $values Values submitted with an unregistered rule type.
	 * @param string $limit  Machine name of the limit expected to be reported.
	 * @return void
	 */
	public function test_an_over_bounds_rule_leaves_no_fragment_of_itself_in_a_stored_set( mixed $values, string $limit ) {
		$stored = Sanitizer::sanitize_rule_set(
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => self::UNREGISTERED_TYPE,
								'negate' => false,
								'values' => array( 'keep' => 'a value the author cares about' ),
							),
							array(
								'type'   => self::UNREGISTERED_TYPE,
								'negate' => false,
								'values' => $values,
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
			'The refused rule is replaced, never dropped. Rules within a group are ANDed, so removing one widens the group it sat in.'
		);
		$this->assertSame(
			array( 'keep' => 'a value the author cares about' ),
			$rules[0]['values'],
			'The rule beside the refused one is untouched. The refusal is of one rule, not a filtering pass over the group.'
		);
		$this->assertSame(
			array(
				'type'    => Sanitizer::REJECTED_RULE_TYPE,
				'negate'  => false,
				'values'  => array(),
				'unknown' => true,
			),
			$rules[1],
			sprintf(
				'A rule breaking the %s limit must be stored as the marker and nothing else. The marker names a type nothing can register, so the group it sits in can no longer match anybody.',
				$limit
			)
		);
		$this->assertStringNotContainsString(
			self::CANARY,
			(string) wp_json_encode( $stored ),
			sprintf(
				'A fragment of the rule refused for the %s limit reached storage. A half-preserved targeting rule is more dangerous than a refused one, because the author sees a rule in the editor, believes it is narrowing the audience, and it is not.',
				$limit
			)
		);
	}

	/**
	 * A rule sitting exactly on the limits is stored with its values intact.
	 *
	 * The counterpart to the two tests above, and what makes them mean something:
	 * without it they would both pass against a sanitizer that refused every
	 * unregistered rule, and the canary would be a string that never appears in
	 * either outcome. It is also where the byte-identical round trip is asserted
	 * from — `Rule_Bounds::check()` receives a copy, so it can neither preserve nor
	 * damage the caller's payload.
	 *
	 * @return void
	 */
	public function test_a_rule_sitting_on_the_limits_is_stored_with_its_values_intact() {
		$values = self::values_on_every_limit();

		$this->assertSame(
			true,
			Rule_Bounds::check( $values ),
			'Fixture drift: this payload sits on every limit without breaking one, so it must be accepted.'
		);

		$stored = Sanitizer::sanitize_rule_set(
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => self::UNREGISTERED_TYPE,
								'negate' => false,
								'values' => $values,
							),
						),
					),
				),
			)
		);

		$rule = $stored['groups'][0]['rules'][0];

		$this->assertSame(
			self::UNREGISTERED_TYPE,
			$rule['type'],
			'A rule within the bounds is stored under its own type, not swapped for the refusal marker.'
		);
		$this->assertSame(
			$values,
			$rule['values'],
			'An accepted payload is stored byte-identical, in content, key order and type. A condition supplied by a plugin that is deactivated at save time has to see the same values when it comes back.'
		);
		$this->assertStringContainsString(
			self::CANARY,
			(string) wp_json_encode( $stored ),
			'Fixture drift: the canary must reach storage when the rule is accepted, or its absence from a refused rule proves nothing.'
		);
	}

	/**
	 * One over-bounds payload per value-shaped limit, each carrying the canary.
	 *
	 * Every payload is legal in every respect but the one named, so each case
	 * isolates a single limit.
	 *
	 * @return array[] Test name => array( values, limit name ).
	 */
	public function data_over_bounds_values_carrying_a_canary() {
		return array(
			'nested past the depth limit'      => array(
				self::canary_nested_values( Rule_Bounds::MAX_DEPTH + 1 ),
				'depth',
			),
			'more items than a level may hold' => array(
				array_fill( 0, Rule_Bounds::MAX_ITEMS_PER_LEVEL + 1, self::CANARY ),
				'items',
			),
			'a string past the length limit'   => array(
				array( 'label' => self::canary_string( Rule_Bounds::MAX_STRING_LENGTH + 6 ) ),
				'string_length',
			),
			'encoding past the size limit'     => array(
				self::canary_values_over_the_size_limit(),
				'size',
			),
		);
	}

	/**
	 * Asserts the refusal contract for one payload.
	 *
	 * Collects the three things every refusal must satisfy: it is a WP_Error, it
	 * carries the shared error code, and it names the limit and that limit's
	 * value.
	 *
	 * Deliberately says nothing about the submitted payload. `check()` takes its
	 * argument by value, so a payload asserted after the call is the caller's own
	 * copy and would come back untouched however the class behaved. What happens
	 * to a refused payload is asserted at the sanitizer instead — see
	 * self::test_an_over_bounds_rule_leaves_no_fragment_of_itself_in_a_stored_set().
	 *
	 * @param mixed  $result Return value of the call under test.
	 * @param string $limit  Machine name of the limit expected to be hit.
	 * @param int    $max    The limit's numeric value, or 0 where it has none.
	 * @return void
	 */
	private function assert_whole_rule_refused( mixed $result, string $limit, int $max ) {
		$this->assertInstanceOf(
			WP_Error::class,
			$result,
			sprintf( 'The %s limit must refuse the whole rule.', $limit )
		);
		$this->assertSame(
			Rule_Bounds::ERROR_CODE,
			$result->get_error_code(),
			'Every refusal carries the one error code, so the failure stays greppable.'
		);
		$this->assertSame(
			$limit,
			$result->get_error_data()['limit'],
			sprintf( 'The refusal should name the %s limit.', $limit )
		);
		$this->assertSame(
			$max,
			$result->get_error_data()['max'],
			'The error data must carry the limit value that was exceeded.'
		);
	}

	/**
	 * Builds a payload consisting of a given number of nested arrays.
	 *
	 * `nested_values( 5 )` returns five arrays inside one another with a string at
	 * the bottom, which is exactly the depth limit: the outermost array is level
	 * one, so an array at level five may hold scalars but no further arrays.
	 *
	 * Built with a loop rather than recursion, so the fixture itself cannot be
	 * what exhausts the stack in the thousand-level test.
	 *
	 * @param int $levels Number of nested arrays to build. At least 1.
	 * @return array
	 */
	private static function nested_values( int $levels ) {
		$value = 'leaf';

		for ( $index = $levels; $index >= 1; --$index ) {
			$value = array( 'level' . $index => $value );
		}

		return $value;
	}

	/**
	 * Builds nested arrays carrying the canary at every level.
	 *
	 * The same shape as self::nested_values(), with the canary in each key as well
	 * as at the leaf. Keying it in too matters: a hypothetical implementation that
	 * cut a payload off at the depth limit would drop the leaf, so a canary that
	 * only lived there would be absent from storage for the same reason a proper
	 * refusal makes it absent, and the assertion would pass on a truncation.
	 *
	 * @param int $levels Number of nested arrays to build. At least 1.
	 * @return array
	 */
	private static function canary_nested_values( int $levels ) {
		$value = self::CANARY;

		for ( $index = $levels; $index >= 1; --$index ) {
			$value = array( self::CANARY . '_level' . $index => $value );
		}

		return $value;
	}

	/**
	 * Builds a string of a given length made of repetitions of the canary.
	 *
	 * @param int $length Desired length in characters. At least one canary long.
	 * @return string
	 */
	private static function canary_string( int $length ) {
		$repeats = (int) ceil( $length / strlen( self::CANARY ) );

		return substr( str_repeat( self::CANARY, max( 1, $repeats ) ), 0, $length );
	}

	/**
	 * Builds a payload that breaks the size limit and no other.
	 *
	 * A short list of canary strings, each the longest multiple of the canary that
	 * still fits within the character limit, and enough of them that their
	 * encoding passes the byte limit. Both counts are derived from the constants,
	 * so the payload keeps isolating the size limit if a limit moves.
	 *
	 * @return array
	 */
	private static function canary_values_over_the_size_limit() {
		$length = Rule_Bounds::MAX_STRING_LENGTH - ( Rule_Bounds::MAX_STRING_LENGTH % strlen( self::CANARY ) );
		$count  = intdiv( Rule_Bounds::MAX_SERIALIZED_BYTES, $length ) + 1;

		return array_fill( 0, $count, self::canary_string( $length ) );
	}

	/**
	 * Builds a payload sitting on the depth, item and string limits at once.
	 *
	 * Every limit is met exactly and none is broken, so the payload is the largest
	 * shape an extension may legitimately store. It carries the canary, so its
	 * arrival in storage is what proves the canary can get there at all.
	 *
	 * @return array
	 */
	private static function values_on_every_limit() {
		return array(
			'label'  => str_repeat( 'a', Rule_Bounds::MAX_STRING_LENGTH ),
			'tags'   => array_fill( 0, Rule_Bounds::MAX_ITEMS_PER_LEVEL, self::CANARY ),
			'nested' => self::canary_nested_values( Rule_Bounds::MAX_DEPTH - 1 ),
		);
	}

	/**
	 * Builds a payload whose JSON encoding is exactly the requested byte count.
	 *
	 * A list of five ASCII strings: four at the maximum permitted length and one
	 * pad. Every other limit is comfortably satisfied — five items, one level
	 * deep, no string over the character limit — so a payload from here isolates
	 * the size check.
	 *
	 * @param int $bytes Desired encoded size. Must be within padding range of four maximum strings.
	 * @return array
	 */
	private static function values_of_encoded_size( int $bytes ) {
		$fixed = 4 * Rule_Bounds::MAX_STRING_LENGTH;
		$pad   = $bytes - self::FIVE_STRING_LIST_OVERHEAD - $fixed;

		return array(
			str_repeat( 'a', Rule_Bounds::MAX_STRING_LENGTH ),
			str_repeat( 'a', Rule_Bounds::MAX_STRING_LENGTH ),
			str_repeat( 'a', Rule_Bounds::MAX_STRING_LENGTH ),
			str_repeat( 'a', Rule_Bounds::MAX_STRING_LENGTH ),
			str_repeat( 'a', $pad ),
		);
	}
}
