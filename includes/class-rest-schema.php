<?php
/**
 * REST route exposing the condition and trigger registries to the editor.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the registry schema that the block editor renders its controls from.
 *
 * `GET /wp-json/popkit/v1/registry` returns every registered condition, every
 * registered trigger, and the names of the controls the editor must be able to
 * render. It exists so that registering a condition is a PHP-only act:
 * docs/CLAUDE.md -> Registry invariants requires a new registration to produce
 * working editor UI with zero JavaScript changes, and that is only achievable if
 * the editor learns the field schemas at runtime instead of having them compiled
 * into its bundle.
 *
 * Nothing here knows what a field schema contains. The payload is assembled from
 * {@see Condition::to_schema()} and {@see Trigger::to_schema()}, and the control
 * list is {@see Condition::FIELD_CONTROLS} verbatim, so the vocabulary has one
 * definition and this route cannot drift from it.
 *
 * ## Why this route checks a capability
 *
 * The response is admin configuration surface. It enumerates every targeting
 * dimension the site has available — including any contributed by other plugins
 * — which together describe the site's extensions and how its authors segment
 * visitors. docs/CLAUDE.md -> Security requires a capability check on every REST
 * route and carves out exactly one public exception, the Phase 2 context
 * endpoint. This is not that route, so it is gated, and it is gated on a
 * capability read from {@see Capabilities::map()} rather than on a string
 * written out here, so the gate cannot drift from the map handed to
 * `register_post_type()`.
 *
 * ## Caching
 *
 * Labels are translated, so the payload varies by the reader's locale, and the
 * registry itself varies by which plugins are active. It is therefore cacheable
 * per user by the editor for the lifetime of a page, and not shared between
 * users. This is an authenticated admin route: it emits no markup and takes no
 * part in the page-cache-safety rules that govern the front end.
 *
 * ## Determinism
 *
 * The response is byte-stable for a given site, locale, and set of active
 * plugins, so the editor can cache it and a test can compare two responses
 * byte for byte:
 *
 * - The three top-level keys are written in a fixed order.
 * - Conditions and triggers appear in registration order, which
 *   {@see Conditions::all()} preserves — built-ins first, extensions after.
 *   Registration order rather than alphabetical order is deliberate: it is what
 *   the editor presents, and sorting here would reorder the editor's panels.
 * - Each entry's keys and each entry's fields come straight from `to_schema()`,
 *   which returns them in declaration order. Field order is meaningful — it is
 *   the order the controls render in — so it is never sorted either.
 *
 * @since 0.1.0
 */
final class Rest_Schema {

	/**
	 * REST namespace every popkit route is registered under.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const REST_NAMESPACE = 'popkit/v1';

	/**
	 * Route path, relative to the namespace.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const ROUTE = '/registry';

	/**
	 * Hooks route registration onto `rest_api_init`.
	 *
	 * Called from `Plugin::boot()` on `plugins_loaded`, which always runs before
	 * `rest_api_init`. The callback is an array callable rather than a
	 * first-class callable so the hook keeps a stable identity: WordPress
	 * deduplicates it, a test can assert it by name, and a site owner can remove
	 * it. A first-class callable produces a new closure on every call and would
	 * do none of those things.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Registers the registry route.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_registry' ),
					'permission_callback' => array( self::class, 'can_read' ),
					'args'                => array(),
				),
				'schema' => array( self::class, 'get_schema' ),
			)
		);
	}

	/**
	 * Capability required to read the registry schema.
	 *
	 * Derived from {@see Capabilities::map()} so that renaming a capability in
	 * one place renames it everywhere. `edit_posts` is the primitive WordPress
	 * consults outside `map_meta_cap()` for "may work with this post type at
	 * all", which is exactly the question this route asks: there is no object to
	 * resolve a meta capability against.
	 *
	 * @since 0.1.0
	 *
	 * @return string Capability name, currently `edit_popkit_popups`.
	 */
	public static function capability(): string {
		return Capabilities::map()['edit_posts'];
	}

	/**
	 * Permission callback for the registry route.
	 *
	 * Never `__return_true`. See the class docblock for why this route is not
	 * the one public exception docs/CLAUDE.md permits.
	 *
	 * Returning a `WP_Error` rather than `false` lets
	 * `rest_authorization_required_code()` distinguish a logged-out visitor (401,
	 * so the client knows to authenticate) from a logged-in user without the
	 * capability (403, so it knows not to retry).
	 *
	 * @since 0.1.0
	 *
	 * @return true|\WP_Error True when the current user may read the registry.
	 */
	public static function can_read() {
		if ( ! current_user_can( self::capability() ) ) {
			return new \WP_Error(
				'popkit_rest_cannot_read_registry',
				__( 'Sorry, you are not allowed to read the popkit condition and trigger registry.', 'popkit' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Builds the registry payload.
	 *
	 * Takes no request argument because the response depends on nothing in the
	 * request. There are no parameters, no context modes, and no filtering: the
	 * editor needs the whole registry or none of it.
	 *
	 * @since 0.1.0
	 *
	 * @return \WP_REST_Response Registry schema for the editor.
	 */
	public static function get_registry(): \WP_REST_Response {
		$conditions = array();
		$triggers   = array();

		foreach ( popkit_conditions()->all() as $key => $condition ) {
			$conditions[ $key ] = self::prepare_entry( $condition->to_schema() );
		}

		foreach ( popkit_triggers()->all() as $key => $trigger ) {
			$triggers[ $key ] = self::prepare_entry( $trigger->to_schema() );
		}

		return rest_ensure_response(
			array(
				'conditions' => (object) $conditions,
				'triggers'   => (object) $triggers,
				'controls'   => Condition::FIELD_CONTROLS,
			)
		);
	}

	/**
	 * Normalizes one registry entry for JSON encoding.
	 *
	 * PHP cannot distinguish an empty map from an empty list, so an entry for a
	 * condition that takes no configuration — `is_front_page`, say — would encode
	 * its `fields` as `[]` rather than `{}`. Both `to_schema()` implementations
	 * document that the cast belongs to the caller, and it belongs here: the
	 * editor iterates `fields` as an object, and a control renderer that has to
	 * special-case an array is one more place for a new field type to break.
	 *
	 * The same reasoning applies to the two top-level maps, which
	 * {@see Rest_Schema::get_registry()} casts for itself.
	 *
	 * Nothing else about the entry is touched. Key order is left exactly as
	 * `to_schema()` produced it — see the class docblock on determinism.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $entry Result of a `to_schema()` call.
	 * @return array<string, mixed> Entry with `fields` guaranteed to encode as an object.
	 */
	private static function prepare_entry( array $entry ): array {
		$entry['fields'] = (object) $entry['fields'];

		return $entry;
	}

	/**
	 * JSON Schema describing the response, for the route's `schema` callback.
	 *
	 * Makes the route self-documenting: an `OPTIONS` request against it returns
	 * this, so the editor's field vocabulary — permitted types, controls, groups
	 * and contexts — is discoverable without reading PHP. Every enumeration below
	 * is read from the constants that enforce it at registration time, so the
	 * documentation cannot describe a vocabulary the plugin does not accept.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema for the registry response.
	 */
	public static function get_schema(): array {
		return array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'popkit-registry',
			'description'          => __( 'Every registered popkit condition and trigger, and the controls the editor renders them with.', 'popkit' ),
			'type'                 => 'object',
			'properties'           => array(
				'conditions' => array(
					'description'          => __( 'Registered conditions, keyed by condition key, in registration order.', 'popkit' ),
					'type'                 => 'object',
					'readonly'             => true,
					'additionalProperties' => self::condition_schema(),
				),
				'triggers'   => array(
					'description'          => __( 'Registered triggers, keyed by trigger key, in registration order.', 'popkit' ),
					'type'                 => 'object',
					'readonly'             => true,
					'additionalProperties' => self::trigger_schema(),
				),
				'controls'   => array(
					'description' => __( 'Every control name the editor must be able to render. A field schema may name only these.', 'popkit' ),
					'type'        => 'array',
					'readonly'    => true,
					'items'       => array(
						'type' => 'string',
						'enum' => Condition::FIELD_CONTROLS,
					),
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * JSON Schema for one condition entry.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema.
	 */
	private static function condition_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key'     => array(
					'description' => __( 'Registry key. Stored verbatim as a rule type.', 'popkit' ),
					'type'        => 'string',
				),
				'context' => array(
					'description' => __( 'Where the condition is decided: on the server, or in the visitor browser.', 'popkit' ),
					'type'        => 'string',
					'enum'        => self::context_values(),
				),
				'group'   => array(
					'description' => __( 'Editor group the condition is presented under.', 'popkit' ),
					'type'        => 'string',
					'enum'        => Condition::GROUPS,
				),
				'label'   => array(
					'description' => __( 'Translated, human-readable name.', 'popkit' ),
					'type'        => 'string',
				),
				'fields'  => self::fields_schema(),
			),
			'required'             => array( 'key', 'context', 'group', 'label', 'fields' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * JSON Schema for one trigger entry.
	 *
	 * A trigger declares no context and no group. Every trigger is armed in the
	 * browser, and the editor lists them all in one panel.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema.
	 */
	private static function trigger_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'key'    => array(
					'description' => __( 'Registry key. Stored verbatim as a trigger config type.', 'popkit' ),
					'type'        => 'string',
				),
				'label'  => array(
					'description' => __( 'Translated, human-readable name.', 'popkit' ),
					'type'        => 'string',
				),
				'fields' => self::fields_schema(),
			),
			'required'             => array( 'key', 'label', 'fields' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * JSON Schema for a `fields` map, shared by conditions and triggers.
	 *
	 * Both registries declare fields with the same vocabulary — see
	 * {@see Condition::validate_field_schemas()}, which validates both — so the
	 * description of that vocabulary is written once here.
	 *
	 * `default` deliberately declares no type. Its permitted type is whatever the
	 * sibling `type` key declares, which draft-04 cannot express without a
	 * dependency clause that no consumer of this route would read.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema.
	 */
	private static function fields_schema(): array {
		return array(
			'description'          => __( 'Field name to field schema, in the order the editor renders the controls.', 'popkit' ),
			'type'                 => 'object',
			'additionalProperties' => array(
				'type'                 => 'object',
				'properties'           => array(
					'type'       => array(
						'description' => __( 'Value type. Sanitization is derived from this declaration.', 'popkit' ),
						'type'        => 'string',
						'enum'        => Condition::FIELD_TYPES,
					),
					'items'      => array(
						'description' => __( 'Item type of an array field. Present only when the type is array.', 'popkit' ),
						'type'        => 'string',
						'enum'        => Condition::FIELD_ITEM_TYPES,
					),
					'enum'       => array(
						'description' => __( 'Permitted values of an enum field. Present only when the type is enum.', 'popkit' ),
						'type'        => 'array',
					),

					/*
					 * `default` is deliberately polymorphic: it matches whatever its
					 * field declared, so it may be any JSON type. It still has to say
					 * so. A property carrying no `type` at all makes
					 * rest_validate_value_from_schema() fail with an undefined array
					 * key rather than a validation message, so an untyped property
					 * does not mean "anything goes" — it means the schema cannot be
					 * validated at all.
					 */
					'default'    => array(
						'description' => __( 'Default value, matching the declared type. Null means no default.', 'popkit' ),
						'type'        => array( 'string', 'integer', 'number', 'boolean', 'array', 'null' ),
					),
					'label'      => array(
						'description' => __( 'Translated control label. Never empty.', 'popkit' ),
						'type'        => 'string',
					),
					'control'    => array(
						'description' => __( 'Control the editor renders this field with, from the shared control map.', 'popkit' ),
						'type'        => 'string',
						'enum'        => Condition::FIELD_CONTROLS,
					),
					'min'        => array(
						'description' => __( 'Inclusive lower bound. Numeric fields only.', 'popkit' ),
						'type'        => 'number',
					),
					'max'        => array(
						'description' => __( 'Inclusive upper bound. Numeric fields only.', 'popkit' ),
						'type'        => 'number',
					),

					/*
					 * Kept in step with Condition::FIELD_SCHEMA_KEYS. Because this
					 * object declares additionalProperties => false, a key a
					 * Condition may legally declare but this map omits makes the
					 * route emit a payload that fails its own advertised schema.
					 * There is a parity assertion in the test suite for exactly
					 * this, so the two lists cannot drift apart again.
					 */
					'max_length' => array(
						'description' => __( 'Maximum stored length. String fields only.', 'popkit' ),
						'type'        => 'integer',
						'minimum'     => 1,
					),
				),
				'required'             => array( 'type', 'label', 'control' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Backed values of every {@see Context} case.
	 *
	 * Read from the enum rather than listed, so a context added later documents
	 * itself here without an edit.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Context values, currently `server` and `client`.
	 */
	private static function context_values(): array {
		return array_map(
			static function ( Context $context ): string {
				return $context->value;
			},
			Context::cases()
		);
	}
}
