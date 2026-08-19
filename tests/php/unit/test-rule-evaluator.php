<?php
/**
 * Unit tests for server-side rule evaluation.
 *
 * These guard the plugin's central safety property. `docs/CLAUDE.md` ->
 * Architecture invariants states it as a table; stated as a sentence it is:
 *
 * > Server-side evaluation may fail a group for exactly one reason — a
 * > `Context::Server` rule that a real evaluator resolved to false. Every other
 * > outcome is indeterminate, the group survives, and the rule travels to the
 * > client to be denied there.
 *
 * The asymmetry matters in one direction far more than the other. If the server
 * wrongly *keeps* a group, the client still has to resolve the rule and fails it
 * closed, so nobody sees a popup they should not. If the server wrongly *fails*
 * a group, the group is never emitted, the client cannot resurrect it, and a
 * correctly targeted popup silently disappears for everybody. The matrices below
 * therefore hammer at the "never returns false" half.
 *
 * A second, quieter property gets the same treatment: indeterminate must mean
 * *deferred*, not *satisfied*. That distinction is invisible in the boolean
 * {@see Popkit\Rule_Evaluator::group_passes_server()} returns — both readings
 * return true — so it is asserted through
 * {@see Popkit\Rule_Evaluator::strip_server_rules()}, which strips a rule the
 * server actually resolved and keeps one it merely deferred. In Phase 1 no
 * evaluator exists yet, so every server rule is deferred; a test asserts that a
 * rule which *would* fail is kept rather than quietly passed.
 *
 * The registry is reached the way production reaches it, through
 * `popkit_conditions()`. Test conditions register under `popkit_test_` keys and
 * registration is guarded, because the accessor holds one process-wide instance
 * that every unit test file shares.
 *
 * @package Popkit
 */

use PHPUnit\Framework\TestCase;
use Popkit\Condition;
use Popkit\Context;
use Popkit\Rule_Evaluator;

/*
 * Load the registry accessor. It declares functions rather than a class, so no
 * autoloader can reach it — production requires it explicitly too, from
 * Popkit\Plugin::boot(). Guarded so a bootstrap that already loaded it, or a
 * sibling test file that got there first, does not redeclare it.
 */
if ( ! function_exists( 'popkit_conditions' ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/functions.php';
}

/*
 * Load the subject directly, so this file is a genuine unit test rather than a
 * test of whatever the bootstrap happened to autoload.
 */
if ( ! class_exists( Rule_Evaluator::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/class-rule-evaluator.php';
}

/**
 * Asserts the rules governing which groups survive the cacheable half of a request.
 */
final class Test_Popkit_Rule_Evaluator extends TestCase {

	/**
	 * Key of the registered `Context::Server` condition used throughout.
	 *
	 * @var string
	 */
	private const SERVER_KEY = 'popkit_test_evaluator_server';

	/**
	 * Key of the registered `Context::Client` condition used throughout.
	 *
	 * @var string
	 */
	private const CLIENT_KEY = 'popkit_test_evaluator_client';

	/**
	 * Key of a registered client condition that declares no fields at all.
	 *
	 * @var string
	 */
	private const CLIENT_BARE_KEY = 'popkit_test_evaluator_bare';

	/**
	 * A condition key nothing registers, standing in for a deactivated extension.
	 *
	 * @var string
	 */
	private const UNKNOWN_KEY = 'popkit_test_evaluator_absent';

	/**
	 * Registers the test conditions in the shared registry.
	 *
	 * The accessor holds one instance for the life of the process and
	 * {@see Popkit\Conditions::register()} raises on a duplicate key, so
	 * registration is guarded rather than assumed to run once.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$registry = popkit_conditions();

		$conditions = array(
			new Condition(
				self::SERVER_KEY,
				Context::Server,
				'content',
				'Test server condition',
				array(
					'pass' => array(
						'type'    => 'boolean',
						'label'   => 'Pass',
						'control' => 'toggle',
					),
				)
			),
			new Condition(
				self::CLIENT_KEY,
				Context::Client,
				'visitor',
				'Test client condition',
				array(
					'state' => array(
						'type'    => 'string',
						'label'   => 'State',
						'control' => 'text',
					),
				)
			),
			new Condition(
				self::CLIENT_BARE_KEY,
				Context::Client,
				'visitor',
				'Test client condition without fields',
				array()
			),
		);

		foreach ( $conditions as $condition ) {
			if ( ! $registry->is_registered( $condition->key ) ) {
				$registry->register( $condition );
			}
		}
	}

	/**
	 * A rule set that expresses no targeting matches every page.
	 *
	 * @return void
	 */
	public function test_a_rule_set_with_no_groups_matches_everything() {
		$this->assertTrue(
			Rule_Evaluator::rule_set_passes_server( array() ),
			'A popup with no stored targeting must match every page. docs/data-model.md defines an empty groups array as "always match".'
		);

		$this->assertTrue(
			Rule_Evaluator::rule_set_passes_server( array( 'groups' => array() ) ),
			'An explicitly empty groups array is the same "always match" shape.'
		);

		$this->assertTrue(
			Rule_Evaluator::rule_set_passes_server( array( 'groups' => 'not-an-array' ) ),
			'Groups that are not an array express no targeting either, so the set is unconstrained rather than failed.'
		);
	}

	/**
	 * Rules within a group are ANDed.
	 *
	 * @return void
	 */
	public function test_rules_within_a_group_are_anded() {
		$passing = $this->group( array( $this->server_rule( true ), $this->server_rule( true ) ) );
		$mixed   = $this->group( array( $this->server_rule( true ), $this->server_rule( false ) ) );

		$this->assertTrue(
			Rule_Evaluator::group_passes_server( $passing, $this->values_evaluator() ),
			'A group whose every server rule resolved true must pass.'
		);

		$this->assertFalse(
			Rule_Evaluator::group_passes_server( $mixed, $this->values_evaluator() ),
			'One resolved server rule returning false must fail its group. Rules within a group are ANDed.'
		);
	}

	/**
	 * Groups are ORed, and a malformed group can only ever narrow the set.
	 *
	 * @return void
	 */
	public function test_groups_are_ored() {
		$failing = $this->group( array( $this->server_rule( false ) ) );
		$passing = $this->group( array( $this->server_rule( true ) ) );

		$this->assertTrue(
			Rule_Evaluator::rule_set_passes_server(
				array( 'groups' => array( $failing, $passing ) ),
				$this->values_evaluator()
			),
			'One surviving group is enough. Groups are ORed.'
		);

		$this->assertFalse(
			Rule_Evaluator::rule_set_passes_server(
				array( 'groups' => array( $failing, $failing ) ),
				$this->values_evaluator()
			),
			'Every group failing means the popup is omitted from the response entirely.'
		);

		$this->assertFalse(
			Rule_Evaluator::rule_set_passes_server(
				array( 'groups' => array( 'corrupt', 42 ) ),
				$this->values_evaluator()
			),
			'A set whose every group is malformed declared targeting that cannot be read. Reading it as "always match" would widen the audience to everybody.'
		);
	}

	/**
	 * A client-context rule never fails a group, whatever it carries.
	 *
	 * This is the exit criterion from docs/build-plan.md -> Phase 1. The
	 * evaluator injected here resolves *everything* to false, so if a client rule
	 * were ever handed to it the group would fail and this test would catch it.
	 *
	 * @dataProvider data_rule_shapes
	 *
	 * @param mixed $values Values carried by the rule.
	 * @param bool  $negate Whether the rule asks for inversion.
	 * @return void
	 */
	public function test_a_client_rule_never_fails_a_group( $values, $negate ) {
		foreach ( array( self::CLIENT_KEY, self::CLIENT_BARE_KEY ) as $key ) {
			$group = $this->group(
				array(
					array(
						'type'   => $key,
						'negate' => $negate,
						'values' => $values,
					),
				)
			);

			$this->assertTrue(
				Rule_Evaluator::group_passes_server( $group, $this->evaluator_returning( false ) ),
				'A Context::Client rule is indeterminate on the server, whatever its values and whatever its negate flag. Failing the group here would drop it from the response, and the client could never resurrect it.'
			);

			$this->assertTrue(
				Rule_Evaluator::rule_set_passes_server(
					array( 'groups' => array( $group ) ),
					$this->evaluator_returning( false )
				),
				'The same must hold at rule-set level: a popup targeted only by client conditions must still be emitted.'
			);
		}
	}

	/**
	 * An unregistered rule type never fails a group, whatever it carries.
	 *
	 * @dataProvider data_rule_shapes
	 *
	 * @param mixed $values Values carried by the rule.
	 * @param bool  $negate Whether the rule asks for inversion.
	 * @return void
	 */
	public function test_an_unknown_rule_never_fails_a_group( $values, $negate ) {
		$group = $this->group(
			array(
				array(
					'type'   => self::UNKNOWN_KEY,
					'negate' => $negate,
					'values' => $values,
				),
			)
		);

		$this->assertTrue(
			Rule_Evaluator::group_passes_server( $group, $this->evaluator_returning( false ) ),
			'An unregistered type is indeterminate on the server. The condition is probably supplied by a plugin that is deactivated right now, and its group must survive to be denied on the client.'
		);
	}

	/**
	 * No shape of unregistered type name fails a group.
	 *
	 * @dataProvider data_unknown_type_names
	 *
	 * @param mixed $type Value stored in the rule's `type` key.
	 * @return void
	 */
	public function test_no_unregistered_type_name_fails_a_group( $type ) {
		foreach ( array( false, true ) as $negate ) {
			$group = $this->group(
				array(
					array(
						'type'   => $type,
						'negate' => $negate,
						'values' => array( 'anything' => 'at all' ),
					),
				)
			);

			$this->assertTrue(
				Rule_Evaluator::group_passes_server( $group, $this->evaluator_returning( false ) ),
				'Any type the registry cannot resolve — including one that is not a string at all — is indeterminate rather than false.'
			);
		}
	}

	/**
	 * A malformed rule entry is indeterminate rather than false.
	 *
	 * @dataProvider data_malformed_rules
	 *
	 * @param mixed $rule Entry found where a rule was expected.
	 * @return void
	 */
	public function test_a_malformed_rule_is_indeterminate( $rule ) {
		$this->assertTrue(
			Rule_Evaluator::group_passes_server( $this->group( array( $rule ) ), $this->evaluator_returning( false ) ),
			'A rule the server cannot even read is not a rule that failed. Failing the group on it would delete targeting nobody judged.'
		);
	}

	/**
	 * The evaluator is consulted for registered server rules and for nothing else.
	 *
	 * Structural proof of the invariant: a client rule or an unknown rule cannot
	 * fail a group if it never reaches the only code that can produce a false.
	 *
	 * @return void
	 */
	public function test_the_evaluator_is_consulted_only_for_registered_server_rules() {
		$seen = array();

		$evaluator = static function ( array $rule, Condition $condition ) use ( &$seen ): ?bool {
			$seen[] = $condition->key;

			return true;
		};

		$group = $this->group(
			array(
				$this->server_rule( true ),
				$this->client_rule(),
				array(
					'type'   => self::UNKNOWN_KEY,
					'negate' => true,
					'values' => array( 'anything' => true ),
				),
				'corrupt',
			)
		);

		$this->assertTrue(
			Rule_Evaluator::group_passes_server( $group, $evaluator ),
			'The group holds nothing that resolves to false.'
		);

		$this->assertSame(
			array( self::SERVER_KEY ),
			$seen,
			'Only a registered Context::Server rule may reach the evaluator. Handing it a client rule would make the response depend on the visitor, which is the cache-poisoning bug the context split exists to prevent.'
		);
	}

	/**
	 * Negating an unknown rule does not turn it into a pass.
	 *
	 * The group survives either way, so the assertion that carries the meaning is
	 * the emitted shape: a satisfied rule is stripped, and this one is not. It
	 * reaches the client still tagged unknown, where the fail-closed path denies
	 * it without applying `negate`.
	 *
	 * @return void
	 */
	public function test_negating_an_unknown_rule_does_not_resurrect_it() {
		$rule = array(
			'type'   => self::UNKNOWN_KEY,
			'negate' => true,
			'values' => array( 'segment' => 'members' ),
		);

		$this->assertTrue(
			Rule_Evaluator::group_passes_server( $this->group( array( $rule ) ), $this->values_evaluator() ),
			'A negated unknown rule is still unknown, so its group is deferred rather than failed.'
		);

		$emitted = Rule_Evaluator::strip_server_rules(
			array( 'groups' => array( $this->group( array( $rule ) ) ) ),
			$this->values_evaluator()
		);

		$rules = $emitted['groups'][0]['rules'];

		$this->assertCount(
			1,
			$rules,
			'A negated unknown rule must still be emitted. Dropping it would leave a group with no constraints at all, which matches everybody.'
		);

		$this->assertTrue(
			$rules[0]['unknown'],
			'The emitted rule must carry the unknown tag so the client denies it and the editor can flag it as unavailable.'
		);

		$this->assertTrue(
			$rules[0]['negate'],
			'The stored negate flag travels unchanged. The client is the one that must decline to apply it.'
		);

		$this->assertSame(
			array( 'segment' => 'members' ),
			$rules[0]['values'],
			'The unknown rule keeps its values so a reactivated extension sees them intact.'
		);
	}

	/**
	 * Negating a client rule does not turn it into a pass either.
	 *
	 * @return void
	 */
	public function test_negating_a_client_rule_does_not_resurrect_it() {
		$rule = array(
			'type'   => self::CLIENT_KEY,
			'negate' => true,
			'values' => array( 'state' => 'logged_in' ),
		);

		$emitted = Rule_Evaluator::strip_server_rules(
			array( 'groups' => array( $this->group( array( $rule ) ) ) ),
			$this->evaluator_returning( false )
		);

		$this->assertSame(
			array( $rule ),
			$emitted['groups'][0]['rules'],
			'A negated client rule is emitted verbatim, with no unknown tag and nothing stripped. The server has decided nothing about it, so it must not look decided.'
		);
	}

	/**
	 * Without an evaluator a registered server rule is deferred, never satisfied.
	 *
	 * Phase 1 registers no server evaluators, so this is the state the plugin
	 * actually ships in until Phase 4. The dangerous reading is "no evaluator, so
	 * the rule passes": targeting nobody checked would be dropped from the
	 * emitted config and the popup would show site-wide.
	 *
	 * @return void
	 */
	public function test_a_server_rule_is_deferred_when_no_evaluator_is_supplied() {
		$rule  = $this->server_rule( false );
		$group = $this->group( array( $rule ) );

		$this->assertFalse(
			Rule_Evaluator::group_passes_server( $group, $this->values_evaluator() ),
			'With a real evaluator this rule resolves false and fails its group. That is the behavior Phase 4 restores.'
		);

		$this->assertTrue(
			Rule_Evaluator::group_passes_server( $group ),
			'With no evaluator the rule cannot be judged, so the group is deferred rather than failed.'
		);

		$deferred = Rule_Evaluator::strip_server_rules( array( 'groups' => array( $group ) ) );

		$this->assertSame(
			array( $rule ),
			$deferred['groups'][0]['rules'],
			'An unjudged server rule must be emitted, not stripped. Stripping it would leave a group that passes unconditionally in the browser — a rule that would have failed silently becoming a rule that passes.'
		);

		$resolved = Rule_Evaluator::strip_server_rules(
			array( 'groups' => array( $this->group( array( $this->server_rule( true ) ) ) ) ),
			$this->values_evaluator()
		);

		$this->assertSame(
			array(),
			$resolved['groups'][0]['rules'],
			'A server rule the evaluator actually resolved is stripped: it has been decided, and emitting it would leak targeting configuration into the page source.'
		);
	}

	/**
	 * An evaluator return value that is not a boolean decides nothing.
	 *
	 * @dataProvider data_non_boolean_evaluator_results
	 *
	 * @param mixed $result Value the evaluator hands back.
	 * @return void
	 */
	public function test_a_non_boolean_evaluator_result_leaves_the_rule_deferred( $result ) {
		$rule  = $this->server_rule( false );
		$group = $this->group( array( $rule ) );

		$this->assertTrue(
			Rule_Evaluator::group_passes_server( $group, $this->evaluator_returning( $result ) ),
			'An evaluator that returns anything other than true or false has not decided anything. Casting that to a boolean would turn a broken evaluator into a targeting decision.'
		);

		$emitted = Rule_Evaluator::strip_server_rules(
			array( 'groups' => array( $group ) ),
			$this->evaluator_returning( $result )
		);

		$this->assertSame(
			array( $rule ),
			$emitted['groups'][0]['rules'],
			'The undecided rule is emitted rather than stripped, so the client denies it instead of ignoring it.'
		);
	}

	/**
	 * Negate inverts a resolved server rule and only a resolved server rule.
	 *
	 * @dataProvider data_negate_flags
	 *
	 * @param mixed $stored   Value found in the rule's `negate` key.
	 * @param bool  $inverts  Whether that value asks for inversion.
	 * @return void
	 */
	public function test_negate_inverts_a_resolved_server_rule( $stored, $inverts ) {
		$rule           = $this->server_rule( true );
		$rule['negate'] = $stored;

		$this->assertSame(
			! $inverts,
			Rule_Evaluator::group_passes_server( $this->group( array( $rule ) ), $this->values_evaluator() ),
			'Negation applies to a rule the server actually resolved. Reading a stored "1" or "true" as not-negated would invert the audience of the popup.'
		);
	}

	/**
	 * Emission keeps what the client must judge and drops what it must not see.
	 *
	 * @return void
	 */
	public function test_emission_strips_resolved_server_rules_and_keeps_the_rest() {
		$client  = $this->client_rule();
		$unknown = array(
			'type'   => self::UNKNOWN_KEY,
			'negate' => false,
			'values' => array( 'segment' => 'donors' ),
		);

		$emitted = Rule_Evaluator::strip_server_rules(
			array( 'groups' => array( $this->group( array( $this->server_rule( true ), $client, $unknown ) ) ) ),
			$this->values_evaluator()
		);

		$rules = $emitted['groups'][0]['rules'];

		$this->assertCount( 2, $rules, 'The resolved server rule is removed; the client rule and the unknown rule remain.' );
		$this->assertSame( array( 0, 1 ), array_keys( $rules ), 'Emitted rules must be a list, so the config encodes as a JSON array rather than an object.' );
		$this->assertSame( $client, $rules[0], 'A client rule travels verbatim.' );
		$this->assertSame(
			array(
				'type'    => self::UNKNOWN_KEY,
				'negate'  => false,
				'values'  => array( 'segment' => 'donors' ),
				'unknown' => true,
			),
			$rules[1],
			'An unknown rule travels verbatim plus the unknown tag, which is appended last so the stored keys keep their order.'
		);
	}

	/**
	 * An unknown rule's values survive emission byte for byte.
	 *
	 * The keys below are deliberately not in alphabetical order and the values
	 * deliberately mix types. A reactivated extension must see exactly what it
	 * stored — reordering or coercing would change the meaning of targeting the
	 * author never edited.
	 *
	 * @return void
	 */
	public function test_emission_preserves_unknown_values_byte_for_byte() {
		$values = array(
			'zeta'  => 'last alphabetically, first here',
			'alpha' => array(
				'nested_two' => 2,
				'nested_one' => '1',
				'flag'       => false,
				'nothing'    => null,
				'ratio'      => 0.5,
			),
			'mid'   => array( 3, '3', true ),
		);

		$rule = array(
			'type'   => self::UNKNOWN_KEY,
			'negate' => false,
			'values' => $values,
		);

		$emitted = Rule_Evaluator::strip_server_rules(
			array( 'groups' => array( $this->group( array( $rule ) ) ) ),
			$this->values_evaluator()
		);

		$stored = $emitted['groups'][0]['rules'][0]['values'];

		$this->assertSame( $values, $stored, 'Values must be identical in content, order and type.' );
		$this->assertSame( array_keys( $values ), array_keys( $stored ), 'Key order is part of what "preserved" means.' );
		$this->assertSame( array_keys( $values['alpha'] ), array_keys( $stored['alpha'] ), 'Nested key order is preserved too.' );
		$this->assertSame(
			wp_json_encode( $values ),
			wp_json_encode( $stored ),
			'The JSON encoding is the form these values take in the emitted config, so it is the form byte-identity is measured in.'
		);
	}

	/**
	 * A group that failed server-side is not emitted at all.
	 *
	 * @return void
	 */
	public function test_emission_omits_groups_that_failed() {
		$failing = $this->group( array( $this->server_rule( false ) ) );
		$client  = $this->group( array( $this->client_rule() ) );

		$emitted = Rule_Evaluator::strip_server_rules(
			array( 'groups' => array( $failing, $client ) ),
			$this->values_evaluator()
		);

		$this->assertCount( 1, $emitted['groups'], 'The failed group is dropped so the client never sees it and cannot resurrect it.' );
		$this->assertSame( array( $this->client_rule() ), $emitted['groups'][0]['rules'], 'The surviving group is the one carrying the client rule.' );
	}

	/**
	 * A wholly failed rule set is never emitted as "always match".
	 *
	 * @return void
	 */
	public function test_emission_never_turns_a_failed_set_into_an_unconstrained_one() {
		$emitted = Rule_Evaluator::strip_server_rules(
			array( 'groups' => array( $this->group( array( $this->server_rule( false ) ) ) ) ),
			$this->values_evaluator()
		);

		$this->assertNotSame(
			array(),
			$emitted['groups'],
			'An empty groups array means "always match". Emitting one for a set whose every group failed would turn a rejected popup into a site-wide one.'
		);

		$this->assertSame(
			array(
				array(
					'rules' => array(
						array(
							'type'    => Rule_Evaluator::UNSATISFIABLE_RULE_TYPE,
							'negate'  => false,
							'values'  => array(),
							'unknown' => true,
						),
					),
				),
			),
			$emitted['groups'],
			'The replacement is a rule nothing can register and nothing can satisfy, tagged unknown so the client denies it through the ordinary fail-closed path.'
		);
	}

	/**
	 * A set that always matched still always matches after emission.
	 *
	 * @return void
	 */
	public function test_emission_keeps_an_unconstrained_set_unconstrained() {
		$this->assertSame(
			array( 'groups' => array() ),
			Rule_Evaluator::strip_server_rules( array() ),
			'A popup with no targeting must keep matching every page once emitted.'
		);

		$this->assertSame(
			array( 'groups' => array() ),
			Rule_Evaluator::strip_server_rules( array( 'groups' => array() ) ),
			'An explicitly empty groups array is the same shape and survives unchanged.'
		);
	}

	/**
	 * A malformed rule is emitted as something the client cannot satisfy.
	 *
	 * @return void
	 */
	public function test_a_malformed_rule_is_emitted_as_unsatisfiable() {
		$emitted = Rule_Evaluator::strip_server_rules(
			array( 'groups' => array( $this->group( array( 'corrupt', $this->client_rule() ) ) ) ),
			$this->values_evaluator()
		);

		$rules = $emitted['groups'][0]['rules'];

		$this->assertCount( 2, $rules, 'A corrupt rule is replaced, never dropped: dropping an ANDed rule widens the group that contained it.' );
		$this->assertSame( Rule_Evaluator::UNSATISFIABLE_RULE_TYPE, $rules[0]['type'], 'The replacement names a type nothing registers.' );
		$this->assertTrue( $rules[0]['unknown'], 'It is tagged unknown so the client denies it.' );
		$this->assertFalse( $rules[0]['negate'], 'Nothing about the replacement may read as an instruction to invert anything.' );
	}

	/**
	 * Unresolvable rules are reported so the editor can flag them.
	 *
	 * @return void
	 */
	public function test_group_has_unknown_reports_unresolvable_rules() {
		$this->assertFalse(
			Rule_Evaluator::group_has_unknown( $this->group( array( $this->server_rule( true ), $this->client_rule() ) ) ),
			'Every rule resolves to a registered condition, so there is nothing to flag.'
		);

		$this->assertFalse(
			Rule_Evaluator::group_has_unknown( $this->group( array() ) ),
			'A group with no rules references no condition at all.'
		);

		$this->assertTrue(
			Rule_Evaluator::group_has_unknown(
				$this->group( array( $this->client_rule(), array( 'type' => self::UNKNOWN_KEY ) ) )
			),
			'An unregistered type is what the editor must surface as an unavailable condition.'
		);

		$this->assertTrue(
			Rule_Evaluator::group_has_unknown( $this->group( array( 'corrupt' ) ) ),
			'A malformed rule names no resolvable condition either, and hiding it would hide the reason the popup never shows.'
		);
	}

	/**
	 * Values and negate flags a rule may plausibly carry.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function data_rule_shapes() {
		$cases = array();

		foreach ( self::rule_value_shapes() as $label => $values ) {
			$cases[ $label . ', not negated' ] = array( $values, false );
			$cases[ $label . ', negated' ]     = array( $values, true );
		}

		return $cases;
	}

	/**
	 * Type names no registry resolves.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function data_unknown_type_names() {
		return array(
			'plain unregistered key' => array( self::UNKNOWN_KEY ),
			'future built-in'        => array( 'user_role' ),
			'namespaced by a plugin' => array( 'acme/subscriber_tier' ),
			'single character'       => array( 'x' ),
			'empty string'           => array( '' ),
			'integer'                => array( 42 ),
			'array'                  => array( array( 'nested' ) ),
			'null'                   => array( null ),
			'boolean'                => array( true ),
		);
	}

	/**
	 * Entries that are not rules at all.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function data_malformed_rules() {
		return array(
			'string'            => array( 'corrupt' ),
			'integer'           => array( 42 ),
			'zero'              => array( 0 ),
			'float'             => array( 1.5 ),
			'null'              => array( null ),
			'true'              => array( true ),
			'false'             => array( false ),
			'rule with no type' => array( array( 'negate' => true ) ),
			'empty array'       => array( array() ),
		);
	}

	/**
	 * Values an evaluator might hand back that are not decisions.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function data_non_boolean_evaluator_results() {
		return array(
			'null'         => array( null ),
			'string false' => array( 'false' ),
			'string zero'  => array( '0' ),
			'string true'  => array( 'true' ),
			'integer zero' => array( 0 ),
			'integer one'  => array( 1 ),
			'empty string' => array( '' ),
			'empty array'  => array( array() ),
			'WP_Error'     => array( new WP_Error( 'popkit_test', 'The evaluator failed.' ) ),
		);
	}

	/**
	 * Stored negate values and whether each asks for inversion.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function data_negate_flags() {
		return array(
			'boolean true'  => array( true, true ),
			'string true'   => array( 'true', true ),
			'string one'    => array( '1', true ),
			'string yes'    => array( 'yes', true ),
			'integer one'   => array( 1, true ),
			'boolean false' => array( false, false ),
			'string false'  => array( 'false', false ),
			'string zero'   => array( '0', false ),
			'string no'     => array( 'no', false ),
			'integer zero'  => array( 0, false ),
			'empty string'  => array( '', false ),
			'null'          => array( null, false ),
		);
	}

	/**
	 * Value payloads used across the invariant matrices.
	 *
	 * @return array<string, mixed>
	 */
	private static function rule_value_shapes() {
		return array(
			'no values'       => array(),
			'login state'     => array( 'state' => 'logged_in' ),
			'zero width'      => array( 'max_width' => 0 ),
			'empty value'     => array( 'value' => '' ),
			'list'            => array( 0, 1, 2 ),
			'nested'          => array( 'deep' => array( 'deeper' => array( 'deepest' => true ) ) ),
			'string instead'  => 'a bare string',
			'integer instead' => 0,
			'float instead'   => 1.5,
			'false instead'   => false,
			'null instead'    => null,
		);
	}

	/**
	 * Builds a rule referencing the registered server condition.
	 *
	 * @param bool $pass What the values evaluator should resolve the rule to.
	 * @return array
	 */
	private function server_rule( $pass ) {
		return array(
			'type'   => self::SERVER_KEY,
			'negate' => false,
			'values' => array( 'pass' => $pass ),
		);
	}

	/**
	 * Builds a rule referencing the registered client condition.
	 *
	 * @return array
	 */
	private function client_rule() {
		return array(
			'type'   => self::CLIENT_KEY,
			'negate' => false,
			'values' => array( 'state' => 'logged_in' ),
		);
	}

	/**
	 * Wraps rules in a group.
	 *
	 * @param array $rules Rules the group holds.
	 * @return array
	 */
	private function group( array $rules ) {
		return array( 'rules' => $rules );
	}

	/**
	 * Returns an evaluator that reads its answer out of the rule.
	 *
	 * Stands in for the Phase 4 evaluator: it decides a rule whose values carry a
	 * boolean `pass`, and declares itself unable to decide anything else.
	 *
	 * @return callable
	 */
	private function values_evaluator() {
		return static function ( array $rule, Condition $condition ): ?bool {
			unset( $condition );

			$pass = $rule['values']['pass'] ?? null;

			return is_bool( $pass ) ? $pass : null;
		};
	}

	/**
	 * Returns an evaluator that always hands back the same value.
	 *
	 * @param mixed $result Value to return for every rule.
	 * @return callable
	 */
	private function evaluator_returning( $result ) {
		return static function ( array $rule, Condition $condition ) use ( $result ) {
			unset( $rule, $condition );

			return $result;
		};
	}
}
