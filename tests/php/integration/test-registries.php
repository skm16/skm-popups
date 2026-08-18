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
 * ## Why the fixtures here are fixtures, and what now guards the real registry
 *
 * Every condition this file builds is deliberately synthetic, and each one is
 * standing in for a *third party* rather than for a built-in:
 *
 * - {@see Test_Popkit_Registries::sample_condition()} is what an extension
 *   registers. The question it answers — does a condition popkit has never heard
 *   of survive the action, the registry and the REST route untouched? — can only
 *   be asked with a key popkit does not ship. Substituting `device` would test
 *   the built-in set instead and would answer nothing about extensibility.
 * - {@see Test_Popkit_Registries::condition()} and
 *   {@see Test_Popkit_Registries::trigger()} feed registries built with `new`.
 *   Those tests are about the container — storage, retrieval, ordering, the
 *   refusal of a duplicate key — and a container behaves the same whatever is put
 *   in it. Using the real built-ins there would couple assertions about `all()`
 *   to whichever conditions v1 happens to ship.
 *
 * That reasoning is sound and it was also the blind spot. Phase 4 registered its
 * seven server conditions and none of its five client ones, and this file did not
 * notice, because wherever it needed a client-context condition it registered one
 * of its own. {@see Test_Popkit_Registries::test_the_shared_registry_carries_every_builtin_condition()}
 * closes that gap: it reads the shared registry the plugin built and checks it
 * against a list written out below, so a missing built-in fails here as well as
 * in tests/php/integration/test-builtin-conditions.php, which owns the full
 * schema and emission assertions.
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
	 * Every condition key popkit itself must register, transcribed from the data model.
	 *
	 * Written out here rather than read back from the registry, and written out
	 * again in tests/php/integration/test-builtin-conditions.php rather than
	 * shared between the two files. Both choices are deliberate. A list derived
	 * from the registry agrees with a registry that is missing five entries; a
	 * list shared between two test files means one edit changes what both of them
	 * accept, which is the same problem wearing a different hat.
	 *
	 * The first seven are `Context::Server` and the last five are
	 * `Context::Client`. That split is asserted where the schemas are, in
	 * test-builtin-conditions.php; membership alone is asserted here.
	 *
	 * @var string[]
	 */
	public const BUILTIN_CONDITION_KEYS = array(
		'post_type',
		'post_ids',
		'taxonomy_term',
		'is_front_page',
		'is_404',
		'template',
		'url_path',
		'device',
		'user_state',
		'referrer',
		'utm',
		'visit_history',
	);

	/**
	 * A built-in condition that is decided in the browser rather than in PHP.
	 *
	 * Used wherever this file needs a *real* client-context registration, as
	 * opposed to the third-party fixture. `device` is the plainest of the five: one
	 * integer field, no context round trip, and nothing about it varies with the
	 * WordPress install.
	 *
	 * @var string
	 */
	public const BUILTIN_CLIENT_CONDITION = 'device';

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
	 * **A fixture is correct here, and a built-in would be wrong.** The question
	 * this condition exists to answer is whether a key popkit has never heard of
	 * survives the registration action, the registry and the REST route with its
	 * schema intact — the extensibility contract in `docs/CLAUDE.md` -> Registry
	 * invariants. Asking it with `device` would assert something about popkit's own
	 * set and nothing at all about a third party's. What a fixture must never do is
	 * stand in for a built-in whose presence nothing else checks, which is why
	 * {@see Test_Popkit_Registries::test_the_shared_registry_carries_every_builtin_condition()}
	 * exists.
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

		/*
		 * The fixture above is not the only client-context registration this test
		 * is entitled to see. popkit registers five of its own through the same
		 * action, and asserting one of them here is what stops this test from
		 * reporting a healthy client-context pipeline that consists entirely of
		 * its own fixture.
		 */
		$builtin = $conditions->get( self::BUILTIN_CLIENT_CONDITION );

		$this->assertInstanceOf(
			Condition::class,
			$builtin,
			sprintf(
				'The built-in client condition "%s" is not in the registry, so the only client-context condition this test can see is the one it registered itself. That is exactly how five unregistered built-ins went unnoticed through 953 passing PHP tests.',
				self::BUILTIN_CLIENT_CONDITION
			)
		);
		$this->assertSame(
			Context::Client,
			$builtin->context,
			sprintf( 'The built-in condition "%s" is registered as a server condition. It varies by visitor, so judging it in PHP would vary the cached response by device.', self::BUILTIN_CLIENT_CONDITION )
		);
	}

	/**
	 * The shared registry carries every condition popkit itself ships.
	 *
	 * This is the assertion whose absence let Phase 4 ship with its five client
	 * conditions declared in JavaScript and registered nowhere. Everything else in
	 * this file works on a registry it filled itself, so all of it stayed green
	 * while `device`, `user_state`, `referrer`, `utm` and `visit_history` were
	 * missing from the registry the plugin actually built — and a rule of an
	 * unregistered type is emitted tagged `unknown`, which the front-end controller
	 * denies before it looks up an evaluator.
	 *
	 * The list is checked in full rather than key by key so a partial registration
	 * reports every key it dropped. Membership is asserted rather than equality
	 * with the whole registry, because this file and several others register
	 * fixture conditions into the same process-wide instance.
	 *
	 * Field schemas, contexts, groups, the JavaScript pairing and the emitted
	 * `unknown` tag are asserted in tests/php/integration/test-builtin-conditions.php.
	 * What is asserted here is the narrower thing this file was in a position to
	 * notice and did not: that they are registered at all.
	 *
	 * @return void
	 */
	public function test_the_shared_registry_carries_every_builtin_condition() {
		$registry = popkit_conditions();
		$missing  = array();

		foreach ( self::BUILTIN_CONDITION_KEYS as $key ) {
			if ( ! $registry->is_registered( $key ) ) {
				$missing[] = $key;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'Conditions popkit documents as built in are absent from the registry it built. Every rule of a missing type is indeterminate on the server, emitted tagged unknown, and denied in the browser, so no popup targeted by one can open on any page.'
		);

		$this->assertFalse(
			$registry->is_registered( 'user_role' ),
			'The condition "user_role" is deferred to 1.1 in docs/data-model.md. The context endpoint returns a login boolean and nothing else, so a rule of this type could never be evaluated.'
		);
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
	 * A fixture is correct here for a different reason than on
	 * {@see Test_Popkit_Registries::sample_condition()}: the tests using this one
	 * assert the behaviour of the *container* — storage, retrieval, registration
	 * order, the copy `all()` hands out, and the refusal of a duplicate key — and a
	 * container behaves identically whatever is put into it. Filling those
	 * registries with the real built-ins would tie an assertion about `all()` to
	 * whichever conditions v1 happens to ship and would break every time the set
	 * changed, while proving nothing extra about the registry.
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
