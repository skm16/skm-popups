<?php
/**
 * Integration tests for the condition and trigger registries.
 *
 * Two different things are asserted here and they need different fixtures.
 *
 * The **contract** — register, get, all, is_registered, and the refusal of a
 * duplicate key — is asserted against registries constructed with `new`. Neither
 * registry class touches global state, so an independent instance behaves
 * identically and a test never has to unpick the shared one.
 *
 * The **dispatch contract** cannot be. `popkit_conditions()` and
 * `popkit_triggers()` hold their registry in a static local, build it on first
 * use, and fire their registration action once — tied to construction rather
 * than to the call. That is what lets a third party hook the action from a
 * must-use plugin, from `plugins_loaded`, or from `init` and be picked up
 * regardless. It also means the instance cannot be replaced or reset, and the
 * action fires at most once per PHP process.
 *
 * So the callbacks below are attached at the bottom of this file, at include
 * time. PHPUnit loads every file in a suite before it runs the first test, and
 * the plugin deliberately calls neither accessor while booting, so a hook
 * attached here is in place before anything can construct a registry — whichever
 * test in whichever file turns out to be the first genuine consumer.
 *
 * The dispatch counters are static properties for the same reason: they have to
 * survive the test that happened to trigger construction, which may be in
 * another class entirely.
 *
 * A count of zero therefore does not mean "the hook was ignored". It means
 * something constructed a registry before the test files were loaded — which is
 * the failure `Popkit\Plugin::boot_registries()` exists to prevent, because
 * every registration attached after that point is silently dropped.
 *
 * @package Popkit
 */

use Popkit\Capabilities;
use Popkit\Condition;
use Popkit\Conditions;
use Popkit\Context;
use Popkit\Rest_Schema;
use Popkit\Trigger;
use Popkit\Triggers;

/**
 * Integration coverage for Popkit\Conditions, Popkit\Triggers and their accessors.
 */
final class Test_Popkit_Registries extends WP_UnitTestCase {

	/**
	 * Registry key of the condition this test registers as a third party would.
	 *
	 * @var string
	 */
	public const CONDITION_KEY = 'popkit_registry_test_condition';

	/**
	 * Registry key of the trigger this test registers as a third party would.
	 *
	 * @var string
	 */
	public const TRIGGER_KEY = 'popkit_registry_test_trigger';

	/**
	 * Editor label of the registered test condition.
	 *
	 * @var string
	 */
	public const CONDITION_LABEL = 'Registry test condition';

	/**
	 * Editor label of the registered test trigger.
	 *
	 * @var string
	 */
	public const TRIGGER_LABEL = 'Registry test trigger';

	/**
	 * Times `popkit_register_conditions` has fired in this PHP process.
	 *
	 * @var int
	 */
	public static $condition_dispatches = 0;

	/**
	 * Times `popkit_register_triggers` has fired in this PHP process.
	 *
	 * @var int
	 */
	public static $trigger_dispatches = 0;

	/**
	 * Fixture user holding the capability the registry route requires.
	 *
	 * @var int
	 */
	private $editor = 0;

	/**
	 * Registers the test condition and counts the dispatch.
	 *
	 * Attached to `popkit_register_conditions` at the bottom of this file. This
	 * is exactly what an extension does, and nothing about it is test-only: the
	 * action is the documented and only supported registration point.
	 *
	 * @param Conditions $registry Registry to add conditions to.
	 * @return void
	 */
	public static function register_test_condition( Conditions $registry ) {
		++self::$condition_dispatches;

		$registry->register( self::sample_condition() );
	}

	/**
	 * Registers the test trigger and counts the dispatch.
	 *
	 * @param Triggers $registry Registry to add triggers to.
	 * @return void
	 */
	public static function register_test_trigger( Triggers $registry ) {
		++self::$trigger_dispatches;

		$registry->register( self::sample_trigger() );
	}

	/**
	 * Builds the condition a third party would register.
	 *
	 * Client context and the `visitor` group deliberately: an extension is most
	 * likely to describe the person rather than the page, and a client-context
	 * registration is the one the server must defer on rather than reject.
	 *
	 * @return Condition
	 */
	public static function sample_condition() {
		return new Condition(
			self::CONDITION_KEY,
			Context::Client,
			'visitor',
			self::CONDITION_LABEL,
			array(
				'level' => array(
					'type'    => 'string',
					'default' => '',
					'label'   => 'Membership level',
					'control' => 'text',
				),
			)
		);
	}

	/**
	 * Builds the trigger a third party would register.
	 *
	 * @return Trigger
	 */
	public static function sample_trigger() {
		return new Trigger(
			self::TRIGGER_KEY,
			self::TRIGGER_LABEL,
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
	}

	/**
	 * Grants capabilities, creates the user the REST assertions run as, and drops the REST server.
	 *
	 * The server is dropped rather than reused so that it is rebuilt from the
	 * hooks in place right now. A server built by an earlier test and cached in
	 * the global holds whatever routes were registered at the moment it was
	 * created, which is a different question than the one being asked here.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_roles()->for_site();

		Capabilities::assign();

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Drops the REST server so the next test builds its own.
	 *
	 * @return void
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;

		parent::tear_down();
	}

	/**
	 * A condition registry stores, returns and reports its registrations.
	 *
	 * @return void
	 */
	public function test_a_condition_registry_stores_and_returns_its_registrations() {
		$registry = new Conditions();

		$first  = $this->condition( 'alpha_condition' );
		$second = $this->condition( 'beta_condition' );

		$this->assertFalse( $registry->is_registered( 'alpha_condition' ), 'A registry reports a key as registered before anything registered it.' );
		$this->assertNull( $registry->get( 'alpha_condition' ), 'An unregistered key must resolve to null, not to an error. A rule may reference a condition whose plugin is deactivated, and callers are required to handle that.' );
		$this->assertSame( array(), $registry->all(), 'A new registry is not empty.' );

		$registry->register( $first );
		$registry->register( $second );

		$this->assertTrue( $registry->is_registered( 'alpha_condition' ), 'A registered key must report as registered.' );
		$this->assertSame( $first, $registry->get( 'alpha_condition' ), 'get() returned something other than the object that was registered.' );
		$this->assertSame( $second, $registry->get( 'beta_condition' ), 'get() returned something other than the object that was registered.' );
		$this->assertNull( $registry->get( 'gamma_condition' ), 'get() must return null for a key nothing registered.' );

		$this->assertSame(
			array(
				'alpha_condition' => $first,
				'beta_condition'  => $second,
			),
			$registry->all(),
			'all() must return every registration keyed by its key, in registration order. The editor presents conditions in that sequence — built-ins first, extensions after — and an order that shifts between requests would move the panels around.'
		);
	}

	/**
	 * A trigger registry behaves the same way.
	 *
	 * @return void
	 */
	public function test_a_trigger_registry_stores_and_returns_its_registrations() {
		$registry = new Triggers();

		$first  = $this->trigger( 'alpha_trigger' );
		$second = $this->trigger( 'beta_trigger' );

		$this->assertFalse( $registry->is_registered( 'alpha_trigger' ), 'A registry reports a key as registered before anything registered it.' );
		$this->assertNull( $registry->get( 'alpha_trigger' ), 'An unregistered key must resolve to null.' );
		$this->assertSame( array(), $registry->all(), 'A new registry is not empty.' );

		$registry->register( $first );
		$registry->register( $second );

		$this->assertTrue( $registry->is_registered( 'beta_trigger' ), 'A registered key must report as registered.' );
		$this->assertSame( $first, $registry->get( 'alpha_trigger' ), 'get() returned something other than the object that was registered.' );
		$this->assertNull( $registry->get( 'gamma_trigger' ), 'get() must return null for a key nothing registered.' );

		$this->assertSame(
			array(
				'alpha_trigger' => $first,
				'beta_trigger'  => $second,
			),
			$registry->all(),
			'all() must return every registration keyed by its key, in registration order.'
		);
	}

	/**
	 * The array `all()` returns is a copy.
	 *
	 * A caller that could add to or remove from the registry through the returned
	 * array would be able to unregister a condition from anywhere, at any point in
	 * the request, and every rule of that type would start failing closed with
	 * nothing in the registry to explain why.
	 *
	 * @return void
	 */
	public function test_mutating_the_array_from_all_does_not_change_the_registry() {
		$registry = new Conditions();

		$registry->register( $this->condition( 'alpha_condition' ) );

		$conditions = $registry->all();

		unset( $conditions['alpha_condition'] );

		$conditions['gamma_condition'] = $this->condition( 'gamma_condition' );

		$this->assertTrue( $registry->is_registered( 'alpha_condition' ), 'Removing an entry from the array all() returned unregistered the condition.' );
		$this->assertFalse( $registry->is_registered( 'gamma_condition' ), 'Adding an entry to the array all() returned registered a condition.' );
		$this->assertCount( 1, $registry->all(), 'The registry changed size when the array it handed out was modified.' );
	}

	/**
	 * A duplicate condition key is refused, and the first registration survives.
	 *
	 * Registration is deliberately not idempotent. A silent overwrite would let
	 * one plugin take over another plugin's condition while every stored rule
	 * kept pointing at the same type string — so the context, the fields and
	 * therefore the audience behind that string would all change without anyone
	 * editing a popup.
	 *
	 * @return void
	 */
	public function test_registering_a_duplicate_condition_key_is_refused() {
		$registry = new Conditions();
		$original = $this->condition( 'alpha_condition' );

		$registry->register( $original );

		$caught = null;

		try {
			$registry->register( $this->condition( 'alpha_condition', 'A different condition' ) );
		} catch ( InvalidArgumentException $exception ) {
			$caught = $exception;
		}

		$this->assertInstanceOf(
			InvalidArgumentException::class,
			$caught,
			'Registering a key that is already taken must raise. Failing loudly turns a collision into a bug report from the developer who caused it, on the request that caused it.'
		);
		$this->assertSame(
			$original,
			$registry->get( 'alpha_condition' ),
			'The duplicate registration replaced the original. Every stored rule of that type would keep its type string while the audience behind it changed.'
		);
		$this->assertCount( 1, $registry->all(), 'A refused registration was still counted.' );
	}

	/**
	 * A duplicate trigger key is refused, and the first registration survives.
	 *
	 * @return void
	 */
	public function test_registering_a_duplicate_trigger_key_is_refused() {
		$registry = new Triggers();
		$original = $this->trigger( 'alpha_trigger' );

		$registry->register( $original );

		$caught = null;

		try {
			$registry->register( $this->trigger( 'alpha_trigger', 'A different trigger' ) );
		} catch ( InvalidArgumentException $exception ) {
			$caught = $exception;
		}

		$this->assertInstanceOf(
			InvalidArgumentException::class,
			$caught,
			'Registering a trigger key that is already taken must raise. A silent overwrite would make a popup start opening on a different event than its author chose.'
		);
		$this->assertSame( $original, $registry->get( 'alpha_trigger' ), 'The duplicate registration replaced the original.' );
		$this->assertCount( 1, $registry->all(), 'A refused registration was still counted.' );
	}

	/**
	 * The condition registration action fires exactly once per process.
	 *
	 * Counted rather than checked for presence, because a second dispatch is not
	 * a harmless repeat: `do_action()` runs every attached callback again, and
	 * the second `register()` call for the same key raises. Firing twice turns an
	 * ordinary call to the accessor into a fatal error.
	 *
	 * @return void
	 */
	public function test_the_condition_registration_action_fires_exactly_once() {
		$first  = popkit_conditions();
		$second = popkit_conditions();

		$this->assertInstanceOf( Conditions::class, $first, 'popkit_conditions() must return the condition registry.' );
		$this->assertSame(
			$first,
			$second,
			'popkit_conditions() returned a different registry on the second call. Registrations made through the action would be visible to one caller and not another.'
		);
		$this->assertSame(
			1,
			self::$condition_dispatches,
			'popkit_register_conditions did not fire exactly once. Zero means a registry was constructed before this file was loaded, so every registration attached afterwards was silently dropped; more than one means a second dispatch, which re-runs every callback and raises on the duplicate key.'
		);
	}

	/**
	 * The trigger registration action fires exactly once per process.
	 *
	 * @return void
	 */
	public function test_the_trigger_registration_action_fires_exactly_once() {
		$first  = popkit_triggers();
		$second = popkit_triggers();

		$this->assertInstanceOf( Triggers::class, $first, 'popkit_triggers() must return the trigger registry.' );
		$this->assertSame( $first, $second, 'popkit_triggers() returned a different registry on the second call.' );
		$this->assertSame(
			1,
			self::$trigger_dispatches,
			'popkit_register_triggers did not fire exactly once. Zero means the registry was constructed before this file was loaded; more than one means a second dispatch, which raises on the duplicate key.'
		);
	}

	/**
	 * A registration made from the action is retrievable afterwards.
	 *
	 * @return void
	 */
	public function test_a_registration_made_from_the_action_is_retrievable() {
		$conditions = popkit_conditions();
		$triggers   = popkit_triggers();

		$this->assertTrue(
			$conditions->is_registered( self::CONDITION_KEY ),
			'A condition registered from popkit_register_conditions is not in the registry. That action is the only supported registration point, so nothing else could have added it.'
		);

		$condition = $conditions->get( self::CONDITION_KEY );

		$this->assertInstanceOf( Condition::class, $condition, 'The registered condition did not come back as a Condition.' );
		$this->assertSame( self::CONDITION_KEY, $condition->key, 'The registered condition came back under a different key.' );
		$this->assertSame( Context::Client, $condition->context, 'The registered condition came back with a different context. Context decides whether the server may judge a rule or must defer on it.' );
		$this->assertSame( self::CONDITION_LABEL, $condition->label, 'The registered condition came back with a different label.' );

		$this->assertTrue(
			$triggers->is_registered( self::TRIGGER_KEY ),
			'A trigger registered from popkit_register_triggers is not in the registry.'
		);
		$this->assertSame( self::TRIGGER_KEY, $triggers->get( self::TRIGGER_KEY )->key, 'The registered trigger came back under a different key.' );
	}

	/**
	 * A third party registration reaches the editor with no other change.
	 *
	 * This is the extensibility contract in `docs/CLAUDE.md` -> Registry
	 * invariants: registering a condition is a PHP-only act. Nothing was added to
	 * the editor bundle, no control was written, and no JavaScript was touched —
	 * the field schema below travels over this route and the shared control map
	 * renders it.
	 *
	 * @return void
	 */
	public function test_a_third_party_registration_appears_in_the_rest_registry_payload() {
		wp_set_current_user( $this->editor );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/' . Rest_Schema::REST_NAMESPACE . Rest_Schema::ROUTE ) );

		$this->assertSame( 200, $response->get_status(), 'The registry route did not answer a user holding the capability it requires.' );

		$data       = $response->get_data();
		$conditions = (array) $data['conditions'];
		$triggers   = (array) $data['triggers'];

		$this->assertArrayHasKey(
			self::CONDITION_KEY,
			$conditions,
			'A condition registered from popkit_register_conditions never reached the registry payload. The editor learns field schemas from this route at runtime, so a condition missing here has no control and cannot be configured at all.'
		);

		$entry    = $conditions[ self::CONDITION_KEY ];
		$expected = self::sample_condition()->to_schema();

		$this->assertSame( $expected['key'], $entry['key'], 'The condition key changed in transit.' );
		$this->assertSame( $expected['context'], $entry['context'], 'The condition context changed in transit. The editor and the client both key evaluation off it.' );
		$this->assertSame( $expected['group'], $entry['group'], 'The condition group changed in transit.' );
		$this->assertSame( $expected['label'], $entry['label'], 'The condition label changed in transit.' );
		$this->assertSame(
			$expected['fields'],
			(array) $entry['fields'],
			'The declared field schema did not survive the trip to the editor. It is the single source of truth driving the control, the REST validation and the sanitizer, so a field lost here is a setting the author cannot reach and the sanitizer will not keep.'
		);

		$this->assertArrayHasKey(
			self::TRIGGER_KEY,
			$triggers,
			'A trigger registered from popkit_register_triggers never reached the registry payload.'
		);
		$this->assertSame(
			self::sample_trigger()->to_schema()['fields'],
			(array) $triggers[ self::TRIGGER_KEY ]['fields'],
			'The declared trigger field schema did not survive the trip to the editor.'
		);
	}

	/**
	 * Builds a condition for a fresh registry.
	 *
	 * @param string $key   Registry key.
	 * @param string $label Editor label.
	 * @return Condition
	 */
	private function condition( $key, $label = 'A condition' ) {
		return new Condition( $key, Context::Server, 'content', $label, array() );
	}

	/**
	 * Builds a trigger for a fresh registry.
	 *
	 * @param string $key   Registry key.
	 * @param string $label Editor label.
	 * @return Trigger
	 */
	private function trigger( $key, $label = 'A trigger' ) {
		return new Trigger( $key, $label, array() );
	}
}

/*
 * Attached at include time, which is the whole point: both registries are built
 * lazily and dispatch their registration action exactly once, so a callback
 * added from set_up() would arrive after whichever test first touched an
 * accessor. See the file docblock.
 */
add_action( 'popkit_register_conditions', array( Test_Popkit_Registries::class, 'register_test_condition' ) );
add_action( 'popkit_register_triggers', array( Test_Popkit_Registries::class, 'register_test_trigger' ) );
