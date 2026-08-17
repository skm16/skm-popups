<?php
/**
 * Condition registry for popkit.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every condition type that a targeting rule may reference.
 *
 * A {@see Condition} describes *what* can be targeted: its key, the context it
 * is evaluated in, the editor group it belongs to, its label, and the schema of
 * the fields it accepts. It deliberately carries no evaluation logic. Server
 * evaluation lives in {@see Rule_Evaluator}; client evaluation is supplied in
 * JavaScript and keyed by the same string. Field schemas are declared here in
 * PHP for both contexts, so one source of truth drives the admin controls, REST
 * validation, and sanitization.
 *
 * The registry is reached through {@see popkit_conditions()} rather than through
 * a static property. `CLAUDE.md` permits exactly two process-wide instances —
 * this one and {@see Triggers} — on the condition that they are reached through
 * accessor functions. An accessor can be called from the REST schema route, from
 * the front-end matcher, from an extension, and from a
 * `popkit_register_conditions` callback without any of them knowing how the
 * instance is stored, and it keeps the storage out of this class so the class
 * itself stays an ordinary object.
 *
 * State is a private instance array. Constructing a second registry is legal and
 * is what the unit suite does: nothing in this class touches global state, so a
 * test never has to unpick the shared instance.
 *
 * Unknown rule types are *not* the registry's problem. A rule whose `type` is
 * absent from this registry survives sanitization, is treated as indeterminate
 * on the server, and fails closed on the client — see `data-model.md` for the
 * full asymmetry. Nothing here drops or rewrites a rule.
 *
 * @since 0.1.0
 */
final class Conditions {

	/**
	 * Registered conditions, keyed by condition key, in registration order.
	 *
	 * @since 0.1.0
	 * @var array<string, Condition>
	 */
	private array $conditions = array();

	/**
	 * Adds a condition to the registry.
	 *
	 * Registration is deliberately not idempotent: a key that is already taken
	 * raises rather than overwriting. A silent overwrite would let one plugin
	 * take over another plugin's condition, and the takeover would be invisible.
	 * Every stored rule keeps pointing at the same `type` string while the
	 * context, the fields, and therefore the audience behind that string all
	 * change — targeting silently widens or narrows without anyone editing a
	 * popup. Failing loudly at registration time turns that into a bug report
	 * from the developer who caused it, on the request that caused it.
	 *
	 * A caller that genuinely does not know whether a key is free should ask
	 * {@see Conditions::is_registered()} first.
	 *
	 * @since 0.1.0
	 *
	 * @param Condition $condition Condition to register.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the key is empty, or is already registered.
	 */
	public function register( Condition $condition ): void {
		/*
		 * Defence in depth. A well-formed Condition cannot carry an empty key,
		 * but the registry is what the rest of the plugin indexes by that key,
		 * so it refuses to hold one rather than trusting its caller.
		 */
		if ( '' === $condition->key ) {
			throw new \InvalidArgumentException(
				esc_html__( 'A condition cannot be registered with an empty key.', 'popkit' )
			);
		}

		if ( isset( $this->conditions[ $condition->key ] ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: %s: condition key that is already in use. */
						__( 'A condition is already registered under the key "%s". Condition keys must be unique.', 'popkit' ),
						$condition->key
					)
				)
			);
		}

		$this->conditions[ $condition->key ] = $condition;
	}

	/**
	 * Returns a registered condition by key.
	 *
	 * Returning `null` for an unrecognised key is the normal case, not an error:
	 * a rule may reference a condition whose plugin is deactivated, and callers
	 * are required to handle that by deferring on the server and failing closed
	 * on the client.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Condition key.
	 * @return Condition|null The condition, or null when the key is not registered.
	 */
	public function get( string $key ): ?Condition {
		return $this->conditions[ $key ] ?? null;
	}

	/**
	 * Returns every registered condition, in registration order.
	 *
	 * Order is preserved so the editor can present conditions in the sequence
	 * they were registered, with the built-ins first and extensions after them,
	 * rather than in an order that shifts between requests.
	 *
	 * The array is returned by value, so a caller cannot add to or remove from
	 * the registry through it. The {@see Condition} objects inside it are
	 * readonly, so they cannot be mutated either.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Condition> Condition key => condition.
	 */
	public function all(): array {
		return $this->conditions;
	}

	/**
	 * Reports whether a condition key is registered.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Condition key.
	 * @return bool True when a condition is registered under the key.
	 */
	public function is_registered( string $key ): bool {
		return isset( $this->conditions[ $key ] );
	}
}
