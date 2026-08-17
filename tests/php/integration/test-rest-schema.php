<?php
/**
 * Integration tests for GET /popkit/v1/registry.
 *
 * This route is what makes registering a condition a PHP-only act: the editor
 * learns field schemas from it at runtime rather than having them compiled into
 * its bundle, so a condition contributed by another plugin gets a working
 * control with no JavaScript written anywhere. Three properties are asserted
 * here, and each of them is load bearing for a different reason.
 *
 * **It is not public.** The response enumerates every targeting dimension the
 * site has available, including any contributed by other plugins, which together
 * describe the site's extensions and how its authors segment visitors.
 * `docs/CLAUDE.md` -> Security requires a capability check on every REST route
 * and carves out exactly one public exception — the Phase 2 context endpoint,
 * which returns a timestamp and a boolean. This is not that route. A logged-out
 * request must be refused, and so must a logged-in one from somebody who cannot
 * edit popups.
 *
 * **Its key order is deterministic.** The editor caches the payload for the
 * lifetime of a page, and conditions and triggers are presented in registration
 * order — built-ins first, extensions after. Sorting the payload, or letting the
 * order vary between requests, would move the editor's panels around between
 * loads. Two separate requests are therefore dispatched, with the cached REST
 * server dropped between them, and their payloads compared as encoded bytes
 * rather than as arrays, because array equality would pass on a reordering.
 *
 * **A third party registration reaches it.** Asserted from a condition this file
 * registers through the documented action, exactly as an extension would, and
 * against an expected entry written out literally below. Nothing here compares
 * the response against the `to_schema()` call that produced it: a payload
 * compared against its own source mirrors every change made to that source, so a
 * shape the editor could not render would match itself and pass.
 *
 * @package Popkit
 */

use Popkit\Capabilities;
use Popkit\Condition;
use Popkit\Conditions;
use Popkit\Context;
use Popkit\Rest_Schema;
use Popkit\Url_Matcher;

/**
 * Integration coverage for Popkit\Rest_Schema.
 */
final class Test_Popkit_Rest_Schema extends WP_UnitTestCase {

	/**
	 * Registry key of the condition this file registers as a third party would.
	 *
	 * @var string
	 */
	public const CONDITION_KEY = 'popkit_rest_test_condition';

	/**
	 * Editor label of the registered test condition.
	 *
	 * @var string
	 */
	public const CONDITION_LABEL = 'REST test condition';

	/**
	 * Route path, pinned literally.
	 *
	 * The editor addresses this URL, so it is a public contract: changing it
	 * breaks every already-shipped editor bundle and every integration built
	 * against it. The constants are asserted against this value rather than used
	 * to build it.
	 *
	 * @var string
	 */
	private const ROUTE = '/popkit/v1/registry';

	/**
	 * Fixture user IDs, keyed by role slug.
	 *
	 * @var array<string, int>
	 */
	private $users = array();

	/**
	 * Registers the test condition.
	 *
	 * Attached to `popkit_register_conditions` at the bottom of this file rather
	 * than from `set_up()`. Both registries are built lazily and dispatch their
	 * registration action exactly once per PHP process, tied to construction, so
	 * a callback added once a test is running may well arrive after the registry
	 * it wanted to add to was already built.
	 *
	 * @param Conditions $registry Registry to add conditions to.
	 * @return void
	 */
	public static function register_test_condition( Conditions $registry ) {
		$registry->register( self::sample_condition() );
	}

	/**
	 * Builds the condition a third party would register.
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
				'donor_level' => array(
					'type'    => 'enum',
					'enum'    => array( 'bronze', 'silver', 'gold' ),
					'default' => 'bronze',
					'label'   => 'Donor level',
					'control' => 'select',
				),
			)
		);
	}

	/**
	 * Grants capabilities, creates the fixture users, and drops the REST server.
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

		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$this->users[ $role ] = self::factory()->user->create( array( 'role' => $role ) );
		}

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
	 * The route is registered at the documented path.
	 *
	 * @return void
	 */
	public function test_the_route_is_registered_at_the_documented_path() {
		$this->assertSame(
			self::ROUTE,
			'/' . Rest_Schema::REST_NAMESPACE . Rest_Schema::ROUTE,
			'The registry route moved. Its URL is a public contract: the editor bundle and any integration built against it address that path directly.'
		);
		$this->assertArrayHasKey(
			self::ROUTE,
			rest_get_server()->get_routes(),
			'GET /popkit/v1/registry is not registered. The editor has no other way to learn a condition\'s field schema, so every panel it renders would be empty.'
		);
	}

	/**
	 * A user who may edit popups gets the registry.
	 *
	 * @return void
	 */
	public function test_it_returns_the_registry_to_a_user_who_may_edit_popups() {
		wp_set_current_user( $this->users['editor'] );

		$this->assertTrue(
			current_user_can( Rest_Schema::capability() ),
			sprintf( 'Fixture: the editor role must hold "%s", which activation grants it.', Rest_Schema::capability() )
		);

		$response = $this->dispatch();

		$this->assertSame( 200, $response->get_status(), 'The registry route refused a user holding the capability it requires.' );

		$data = $response->get_data();

		$this->assertIsArray( $data, 'The registry payload must be a keyed structure.' );
		$this->assertSame(
			array( 'conditions', 'triggers', 'controls' ),
			array_keys( $data ),
			'The payload must carry conditions, triggers and controls, in that fixed order. The editor reads all three: the first two describe what can be targeted and what can open a popup, the third names the controls it must be able to render.'
		);
		$this->assertSame(
			Condition::FIELD_CONTROLS,
			$data['controls'],
			'The control list must be the shared control map verbatim. A registration may name only these, so a list that drifts from the map lets a field declare a control with no renderer, which produces an empty panel rather than an error.'
		);
	}

	/**
	 * A logged-out request is refused with 401.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_logged_out_request() {
		wp_set_current_user( 0 );

		$response = $this->dispatch();

		$this->assertSame(
			401,
			$response->get_status(),
			'The registry route answered an anonymous request. It is not public: the payload enumerates every targeting dimension the site has available, which describes its extensions and how its authors segment visitors. The one public route docs/CLAUDE.md permits is the Phase 2 context endpoint.'
		);
		$this->assertArrayNotHasKey( 'conditions', (array) $response->get_data(), 'A refused request still returned registry data.' );
	}

	/**
	 * A logged-in user without the capability is refused with 403.
	 *
	 * The two statuses are asserted separately because they mean different
	 * things to the client: 401 tells it to authenticate, 403 tells it not to
	 * retry.
	 *
	 * @return void
	 */
	public function test_it_refuses_a_subscriber() {
		wp_set_current_user( $this->users['subscriber'] );

		$this->assertFalse(
			current_user_can( Rest_Schema::capability() ),
			'Fixture: a subscriber must not hold the capability the route requires.'
		);

		$response = $this->dispatch();

		$this->assertSame(
			403,
			$response->get_status(),
			'The registry route answered a logged-in user who cannot edit popups. Its permission callback must check a capability, never __return_true.'
		);
		$this->assertArrayNotHasKey( 'conditions', (array) $response->get_data(), 'A refused request still returned registry data.' );
	}

	/**
	 * The permission callback is a named callback checking a capability.
	 *
	 * Asserted structurally as well as behaviourally: `__return_true` on this
	 * route would look like an oversight rather than a decision, and the one
	 * deliberate exception in the plugin uses a named callback precisely so the
	 * choice stays greppable.
	 *
	 * @return void
	 */
	public function test_the_permission_callback_checks_the_capability_from_the_map() {
		$this->assertSame(
			Capabilities::map()['edit_posts'],
			Rest_Schema::capability(),
			'The route must gate on the capability read from Popkit\Capabilities::map(), so renaming a capability in one place renames it everywhere.'
		);

		wp_set_current_user( $this->users['subscriber'] );

		$this->assertInstanceOf(
			'WP_Error',
			Rest_Schema::can_read(),
			'The permission callback must refuse a user without the capability, and refuse with a WP_Error so 401 and 403 stay distinguishable.'
		);

		wp_set_current_user( $this->users['editor'] );

		$this->assertTrue( Rest_Schema::can_read(), 'The permission callback refused a user holding the capability.' );
	}

	/**
	 * Two separate requests produce byte-identical payloads.
	 *
	 * Two dispatches, with the cached REST server dropped between them so the
	 * second is answered by a server built from scratch and a payload assembled
	 * from scratch. Comparing one response against itself, or against anything
	 * derived from the same call, would assert nothing at all.
	 *
	 * Encoded before comparison on purpose. Comparing the decoded structures would
	 * pass on a payload whose keys came back in a different order, which is exactly
	 * the regression this asserts against: the editor caches this response and
	 * renders its panels in the order the payload arrived in.
	 *
	 * Both responses are checked for 200 and for the registered test condition
	 * first. Without that, two refusals — or two empty registries — would be
	 * byte-identical to each other and the comparison would pass on a route that
	 * had stopped working entirely.
	 *
	 * @return void
	 */
	public function test_the_payload_is_byte_identical_across_two_separate_requests() {
		wp_set_current_user( $this->users['editor'] );

		$first_response = $this->dispatch();

		$GLOBALS['wp_rest_server'] = null;

		$second_response = $this->dispatch();

		$this->assertSame( 200, $first_response->get_status(), 'The first request was refused, so there is no payload to compare.' );
		$this->assertSame( 200, $second_response->get_status(), 'The second request was refused, so there is no payload to compare.' );

		$first  = wp_json_encode( $first_response->get_data() );
		$second = wp_json_encode( $second_response->get_data() );

		$this->assertIsString( $first, 'The registry payload could not be encoded as JSON.' );
		$this->assertIsString( $second, 'The registry payload could not be encoded as JSON.' );
		$this->assertStringContainsString(
			'"' . self::CONDITION_KEY . '"',
			(string) $first,
			'The compared payload is not a populated registry, so byte-equality between two of them would prove nothing.'
		);
		$this->assertSame(
			$first,
			$second,
			'Two separate requests produced different bytes. Conditions and triggers are returned in registration order — built-ins first, extensions after — and that order is what the editor renders, so a payload that reorders between requests moves the panels around between page loads.'
		);
	}

	/**
	 * A condition registered through the action appears in the payload.
	 *
	 * The expected entry is written out below rather than read back from
	 * `to_schema()`. Comparing the response against the same call that produced it
	 * would mirror whatever shape that call returns: a renamed key, a reordered
	 * fields map or a context left as an enum instance would all match themselves
	 * and the test would stay green while the editor received something it could
	 * not render. What is written out here is the shape the editor bundle reads, so
	 * the two ends of the contract are stated independently.
	 *
	 * @return void
	 */
	public function test_a_condition_registered_through_the_action_appears_in_the_payload() {
		wp_set_current_user( $this->users['editor'] );

		$conditions = (array) $this->dispatch()->get_data()['conditions'];

		$this->assertArrayHasKey(
			self::CONDITION_KEY,
			$conditions,
			'A condition registered from popkit_register_conditions never reached the payload. Registering a condition must produce working editor UI with zero JavaScript changes, and this route is the only thing that carries the schema across.'
		);

		$entry = $conditions[ self::CONDITION_KEY ];

		$this->assertIsArray( $entry, 'A registry entry must be a keyed structure.' );
		$this->assertSame(
			array( 'key', 'context', 'group', 'label', 'fields' ),
			array_keys( $entry ),
			'A registry entry must carry exactly these five keys, in this order. The editor reads all five and the order is what it renders.'
		);

		$this->assertIsString( $entry['key'], 'The key must travel as a string; it is stored verbatim as a rule type.' );
		$this->assertIsString( $entry['context'], 'The context must travel as its backed string value, so the payload survives a JSON round trip without a lookup table on the client.' );
		$this->assertIsString( $entry['group'], 'The group must travel as a string.' );
		$this->assertIsString( $entry['label'], 'The label must travel as a string.' );
		$this->assertInstanceOf( 'stdClass', $entry['fields'], 'The fields map must travel as an object, including for a condition that declares none.' );

		$this->assertSame(
			array(
				'key'     => 'popkit_rest_test_condition',
				'context' => 'client',
				'group'   => 'visitor',
				'label'   => 'REST test condition',
				'fields'  => array(
					'donor_level' => array(
						'type'    => 'enum',
						'enum'    => array( 'bronze', 'silver', 'gold' ),
						'default' => 'bronze',
						'label'   => 'Donor level',
						'control' => 'select',
					),
				),
			),
			array_merge( $entry, array( 'fields' => (array) $entry['fields'] ) ),
			'The registered condition reached the editor in a different shape from the one it was declared in. The field schema is the single source of truth driving the control, the REST validation and the sanitizer, so it travels verbatim: same keys, same order, same types, and enum values in the order they were declared.'
		);
	}

	/**
	 * A field map is encoded as a JSON object, never as an array.
	 *
	 * PHP cannot tell an empty map from an empty list, so a condition that takes
	 * no configuration would otherwise encode its `fields` as `[]`. The editor
	 * iterates `fields` as an object, and a renderer that has to special-case an
	 * array is one more place for a new field type to break.
	 *
	 * @return void
	 */
	public function test_maps_encode_as_json_objects() {
		wp_set_current_user( $this->users['editor'] );

		$payload = json_decode( (string) wp_json_encode( $this->dispatch()->get_data() ) );
		$key     = self::CONDITION_KEY;

		$this->assertInstanceOf( 'stdClass', $payload, 'The payload itself must encode as a JSON object.' );
		$this->assertInstanceOf( 'stdClass', $payload->conditions, 'The conditions map must encode as a JSON object, keyed by condition key.' );
		$this->assertInstanceOf( 'stdClass', $payload->triggers, 'The triggers map must encode as a JSON object, keyed by trigger key.' );
		$this->assertIsArray( $payload->controls, 'The control list must encode as a JSON array; it is a list, not a map.' );
		$this->assertInstanceOf(
			'stdClass',
			$payload->conditions->$key->fields,
			'A condition\'s fields must encode as a JSON object, including when it declares none.'
		);
	}

	/**
	 * The advertised field schema covers every key a Condition may declare.
	 *
	 * The field-schema object in the route's own schema sets
	 * `additionalProperties => false`. A key that Condition accepts but that
	 * object omits therefore makes the route emit a payload which fails its own
	 * advertised contract — the route reports itself invalid.
	 *
	 * This is not hypothetical: `max_length` was accepted by Condition, retained
	 * by `to_schema()`, and missing here, so the first condition to declare it
	 * would have shipped a self-invalidating payload. The two lists are declared
	 * in different files, so only an assertion keeps them together.
	 *
	 * @return void
	 */
	public function test_the_advertised_field_schema_covers_every_condition_schema_key() {
		$advertised = Rest_Schema::get_schema();

		$field_properties = $advertised['properties']['conditions']['additionalProperties']['properties']['fields']['additionalProperties']['properties'];

		$missing = array_diff( Condition::FIELD_SCHEMA_KEYS, array_keys( $field_properties ) );

		$this->assertSame(
			array(),
			array_values( $missing ),
			'Condition accepts field-schema keys the REST schema does not advertise, and additionalProperties is false, so a condition declaring one emits a payload that fails this route\'s own schema.'
		);
	}

	/**
	 * A registered entry validates against the route's advertised schema.
	 *
	 * Asserting the payload's shape against `to_schema()` would only prove
	 * `to_schema()` equals itself. Running core's validator over the response,
	 * using the schema the route publishes, is what makes a wrong shape visible.
	 *
	 * @return void
	 */
	public function test_a_registered_condition_validates_against_the_advertised_schema() {
		add_action(
			'popkit_register_conditions',
			static function ( $registry ) {
				$registry->register(
					new Condition(
						'probe_capped_string',
						Context::Server,
						'content',
						'Probe capped string',
						array(
							'value' => array(
								'type'       => 'string',
								'label'      => 'Value',
								'control'    => 'text',
								'max_length' => Url_Matcher::MAX_VALUE_LENGTH,
							),
						)
					)
				);
			}
		);

		wp_set_current_user( $this->users['editor'] );

		$response = $this->dispatch();

		$this->assertSame( 200, $response->get_status(), 'Fixture: the registry route must answer.' );

		$valid = rest_validate_value_from_schema(
			$response->get_data(),
			Rest_Schema::get_schema(),
			'registry'
		);

		$this->assertNotWPError(
			$valid,
			'The registry payload does not satisfy the schema the route itself advertises.'
		);
	}

	/**
	 * Dispatches a GET against the registry route.
	 *
	 * @return WP_REST_Response
	 */
	private function dispatch() {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );
	}
}

/*
 * Attached at include time. See the note on register_test_condition(): the
 * registration action fires once per process, tied to the construction of the
 * registry rather than to any call, so a hook added from set_up() can arrive
 * after the only dispatch it could have been picked up by.
 */
add_action( 'popkit_register_conditions', array( Test_Popkit_Rest_Schema::class, 'register_test_condition' ) );
