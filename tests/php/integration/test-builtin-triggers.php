<?php
/**
 * Integration tests for the triggers popkit ships with.
 *
 * Four triggers ship in v1 — `page_load`, `scroll_depth`, `click_selector` and
 * `deep_link` — and they are declared in PHP alone. docs/CLAUDE.md -> Registry
 * invariants requires that: one source of truth drives the editor control, the
 * REST validation and the sanitizer, and JavaScript supplies only the runtime.
 * So three separate things are asserted here.
 *
 * **The declarations are what data-model.md says they are.** The expected
 * schemas below are written out literally rather than read back from
 * `Builtin_Triggers::all()`. A payload compared against the source that produced
 * it mirrors every change made to that source, so a schema the editor could not
 * render would match itself and pass.
 *
 * **Each declaration satisfies the registry route's own advertised schema.** That
 * route sets `additionalProperties => false` over the field vocabulary, so a
 * trigger declaring a key the schema does not advertise makes the route emit a
 * payload that fails its own contract — the route reports itself invalid.
 *
 * **The declarations reach the editor.** The registry route is the editor's only
 * source of field schemas, so a trigger missing from that payload has no control
 * and cannot be configured at all.
 *
 * Nothing in this file attaches `popkit_register_triggers`, and that omission is
 * deliberate. The integration bootstrap loads the plugin on `muplugins_loaded`,
 * so `Plugin::boot()` has run on `plugins_loaded` long before PHPUnit includes
 * this file: the only attachment in the process is the plugin's own. A fixture
 * calling `Builtin_Triggers::init()` here would supply the very thing under test
 * — `init()` attaches a stable array callable that WordPress deduplicates, so the
 * assertions below would pass with the call deleted from `boot_registries()` and
 * would prove only that this file works.
 *
 * Tests that need a clean registry build one with `new Triggers()` and call
 * `Builtin_Triggers::register()` on it directly. Those assert what the
 * declarations *are*; the two that read the shared registry through the REST
 * route assert that the plugin *made* them reachable.
 *
 * @package Popkit
 */

use Popkit\Capabilities;
use Popkit\Rest_Schema;
use Popkit\Trigger;
use Popkit\Triggers;
use Popkit\Triggers\Builtin_Triggers;

/**
 * Integration coverage for Popkit\Triggers\Builtin_Triggers.
 */
final class Test_Popkit_Builtin_Triggers extends WP_UnitTestCase {

	/**
	 * Registry key of the fixture user that may read the registry route.
	 *
	 * @var int
	 */
	private $editor = 0;

	/**
	 * Every built-in trigger key, in the order the editor lists them.
	 *
	 * Pinned literally. These strings are stored verbatim as a trigger config's
	 * `type` and are matched by the front-end runtime, so renaming one silently
	 * stops every already-saved popup of that type from ever opening.
	 *
	 * @return string[]
	 */
	public static function expected_keys() {
		return array( 'page_load', 'scroll_depth', 'click_selector', 'deep_link' );
	}

	/**
	 * The field schema each built-in trigger is expected to declare.
	 *
	 * Transcribed from docs/data-model.md -> Triggers and from the bounds in the
	 * Phase 3 brief, not read back from the registration.
	 *
	 * @return array<string, array<string, mixed>> Trigger key => expected fields.
	 */
	public static function expected_fields() {
		return array(
			'page_load'      => array(
				'delay_ms' => array(
					'type'    => 'integer',
					'default' => 0,
					'label'   => 'Delay before opening, in milliseconds (0 opens immediately)',
					'control' => 'number',
					'min'     => 0,
					'max'     => 600000,
				),
			),
			'scroll_depth'   => array(
				'percent' => array(
					'type'    => 'integer',
					'default' => 50,
					'label'   => 'Percent of the page scrolled',
					'control' => 'range',
					'min'     => 1,
					'max'     => 100,
				),
			),
			'click_selector' => array(
				'selector' => array(
					'type'       => 'string',
					'default'    => '',
					'label'      => 'CSS selector of the element to watch for clicks',
					'control'    => 'text',
					'max_length' => 255,
				),
			),
			'deep_link'      => array(),
		);
	}

	/**
	 * Grants capabilities, creates the fixture user, and drops the REST server.
	 *
	 * The server is dropped so it is rebuilt from the hooks in place right now: a
	 * server cached in the global by an earlier test holds whichever routes were
	 * registered at the moment it was created.
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
	 * The plugin attaches registration to the one supported registration point.
	 *
	 * `popkit_register_triggers` is dispatched exactly once, tied to the lazy
	 * construction of the registry. A built-in registered by calling the accessor
	 * directly would fire that action before any extension had attached to it, and
	 * every third-party trigger would be silently dropped.
	 *
	 * The attachment asserted here is the one `Plugin::boot_registries()` made on
	 * `plugins_loaded`. This file attaches nothing, so removing that call turns
	 * this test red rather than leaving it passing on its own fixture.
	 *
	 * @return void
	 */
	public function test_the_builtins_register_through_the_registration_action() {
		$this->assertNotFalse(
			has_action( 'popkit_register_triggers', array( Builtin_Triggers::class, 'register' ) ),
			'Builtin_Triggers::register is not attached to popkit_register_triggers. Plugin::boot_registries() must call Builtin_Triggers::init(); without it no trigger is registered, the editor renders an empty triggers panel, and stored trigger configs sanitize against nothing.'
		);

		foreach ( self::expected_keys() as $key ) {
			$this->assertTrue(
				popkit_triggers()->is_registered( $key ),
				sprintf(
					'The built-in trigger "%s" is missing from the shared registry the plugin builds. Attachment alone is not enough: this is the registry the REST route, the meta sanitizers and the front-end matcher all read. Membership is asserted rather than the whole key list, because other suites register fixture triggers into this same process-wide registry.',
					$key
				)
			);
		}
	}

	/**
	 * Every built-in trigger registers, in the documented order.
	 *
	 * Asserted against a registry built with `new`. The class holds no global
	 * state, so an independent instance behaves identically and the assertion is
	 * not at the mercy of whatever else the process registered.
	 *
	 * @return void
	 */
	public function test_every_builtin_trigger_registers() {
		$registry = new Triggers();

		Builtin_Triggers::register( $registry );

		$this->assertSame(
			self::expected_keys(),
			array_keys( $registry->all() ),
			'The built-in trigger set changed. These keys are stored verbatim as a trigger config type and are matched by the front-end runtime, and registration order is the order the editor presents its panels in.'
		);

		foreach ( self::expected_keys() as $key ) {
			$this->assertInstanceOf(
				Trigger::class,
				$registry->get( $key ),
				sprintf( 'The built-in trigger "%s" did not come back as a Trigger.', $key )
			);
		}
	}

	/**
	 * No trigger deferred to 1.1 is registered.
	 *
	 * These four are deferred deliberately in docs/data-model.md, `exit_intent`
	 * most of all: it ships only when its mobile behavior is honest, and history
	 * interception is rejected permanently. Shipping one early would put a control
	 * in the editor that no runtime implements.
	 *
	 * @return void
	 */
	public function test_no_deferred_trigger_is_registered() {
		$registry = new Triggers();

		Builtin_Triggers::register( $registry );

		foreach ( array( 'time_on_page', 'element_visible', 'idle', 'exit_intent' ) as $deferred ) {
			$this->assertFalse(
				$registry->is_registered( $deferred ),
				sprintf( 'The trigger "%s" is deferred to 1.1 and must not be registered in v1.', $deferred )
			);
		}
	}

	/**
	 * Each built-in declares the field schema the data model documents.
	 *
	 * @return void
	 */
	public function test_each_builtin_declares_the_documented_field_schema() {
		$registry = new Triggers();

		Builtin_Triggers::register( $registry );

		foreach ( self::expected_fields() as $key => $expected ) {
			$this->assertSame(
				$expected,
				$registry->get( $key )->fields,
				sprintf(
					'The declared field schema for "%s" is not the one docs/data-model.md describes. That schema is the single source of truth driving the control, the REST validation and the sanitizer, so a change here silently changes all three.',
					$key
				)
			);
		}
	}

	/**
	 * Every field names a control the editor can actually render.
	 *
	 * A control absent from the shared map has no renderer, so the editor produces
	 * an empty panel rather than an error. The Trigger constructor enforces this,
	 * and this asserts the enforcement is reached rather than trusting it.
	 *
	 * @return void
	 */
	public function test_every_declared_field_names_a_shared_control() {
		foreach ( Builtin_Triggers::all() as $trigger ) {
			foreach ( $trigger->fields as $name => $schema ) {
				$this->assertContains(
					$schema['control'],
					\Popkit\Condition::FIELD_CONTROLS,
					sprintf( 'The field "%s" on trigger "%s" names a control that is not in the shared control map.', $name, $trigger->key )
				);

				$this->assertArrayHasKey( 'type', $schema, sprintf( 'The field "%s" on trigger "%s" declares no type. Sanitization is derived from that declaration.', $name, $trigger->key ) );
				$this->assertArrayHasKey( 'label', $schema, sprintf( 'The field "%s" on trigger "%s" declares no label. An unlabeled control is not accessible.', $name, $trigger->key ) );
			}
		}
	}

	/**
	 * Every built-in validates against the registry route's advertised schema.
	 *
	 * The field-schema object in that schema sets `additionalProperties => false`,
	 * so a trigger declaring a key the route does not advertise emits a payload
	 * that fails the route's own contract.
	 *
	 * @return void
	 */
	public function test_every_builtin_validates_against_the_advertised_trigger_schema() {
		$advertised = Rest_Schema::get_schema();
		$expected   = $advertised['properties']['triggers']['additionalProperties'];

		foreach ( Builtin_Triggers::all() as $trigger ) {
			$entry           = $trigger->to_schema();
			$entry['fields'] = (object) $entry['fields'];

			$valid = rest_validate_value_from_schema( $entry, $expected, 'triggers/' . $trigger->key );

			$this->assertNotWPError(
				$valid,
				sprintf(
					'The built-in trigger "%s" does not satisfy the schema GET /popkit/v1/registry advertises for a trigger entry.',
					$trigger->key
				)
			);
		}
	}

	/**
	 * Every built-in reaches the editor through the registry route.
	 *
	 * @return void
	 */
	public function test_every_builtin_appears_in_the_registry_route_payload() {
		wp_set_current_user( $this->editor );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/' . Rest_Schema::REST_NAMESPACE . Rest_Schema::ROUTE )
		);

		$this->assertSame( 200, $response->get_status(), 'The registry route did not answer a user holding the capability it requires.' );

		$triggers = (array) $response->get_data()['triggers'];

		foreach ( self::expected_fields() as $key => $fields ) {
			$this->assertArrayHasKey(
				$key,
				$triggers,
				sprintf(
					'The built-in trigger "%s" never reached the registry payload. The editor learns field schemas from this route at runtime, so a trigger missing here has no control and cannot be configured at all.',
					$key
				)
			);

			$this->assertSame(
				$key,
				$triggers[ $key ]['key'],
				sprintf( 'The trigger "%s" came back under a different key.', $key )
			);

			$this->assertSame(
				$fields,
				(array) $triggers[ $key ]['fields'],
				sprintf( 'The declared field schema for "%s" did not survive the trip to the editor.', $key )
			);
		}
	}

	/**
	 * The registry payload as a whole still satisfies the route's schema.
	 *
	 * The per-entry assertion above cannot catch a built-in that breaks the shape
	 * of the containing payload, and this route is what the editor caches and
	 * renders every panel from.
	 *
	 * @return void
	 */
	public function test_the_payload_carrying_the_builtins_satisfies_the_route_schema() {
		wp_set_current_user( $this->editor );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/' . Rest_Schema::REST_NAMESPACE . Rest_Schema::ROUTE )
		);

		$this->assertSame( 200, $response->get_status(), 'Fixture: the registry route must answer.' );

		$valid = rest_validate_value_from_schema( $response->get_data(), Rest_Schema::get_schema(), 'registry' );

		$this->assertNotWPError( $valid, 'With the built-in triggers registered, the registry payload no longer satisfies the schema the route advertises.' );
	}

	/**
	 * The declared bounds are the ones the brief and the data model set.
	 *
	 * The constants exist so the front-end runtime and any reviewer can find the
	 * numbers, so they are pinned separately from the schemas that consume them.
	 *
	 * @return void
	 */
	public function test_the_declared_bounds_are_pinned() {
		$this->assertSame( 600000, Builtin_Triggers::MAX_DELAY_MS, 'The page_load delay cap moved. Ten minutes is the documented ceiling.' );
		$this->assertSame( 1, Builtin_Triggers::MIN_SCROLL_PERCENT, 'A scroll depth of zero is page_load with no delay wearing another name.' );
		$this->assertSame( 100, Builtin_Triggers::MAX_SCROLL_PERCENT, 'Scroll depth is a percentage of the document.' );
		$this->assertSame( 255, Builtin_Triggers::MAX_SELECTOR_LENGTH, 'The selector cap moved. It matches Url_Matcher::MAX_VALUE_LENGTH so the plugin caps its bounded free-text fields at one number.' );
	}
}
