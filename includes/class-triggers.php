<?php
/**
 * Trigger registry for popkit.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every trigger type that a popup may arm.
 *
 * A {@see Trigger} declares its key, its editor label, and the schema of the
 * fields it accepts — nothing more. Triggers have no server-side behavior at
 * all: arming, first-fire-wins, and teardown are entirely a front-end concern,
 * and the runtime that implements them keys off the same string. This class
 * exists so that the editor can render controls for a trigger and so that the
 * stored `_popkit_triggers` meta can be sanitized against a declared schema
 * rather than by hand.
 *
 * The registry is reached through {@see popkit_triggers()} rather than through a
 * static property, for the reasons set out on {@see Conditions}.
 *
 * This class is intentionally a near-duplicate of {@see Conditions} rather than
 * a shared base class or trait. `CLAUDE.md` prefers composition over
 * inheritance, the two registries hold different value objects, and the
 * duplication is a few lines of storage against a layer of indirection that
 * would have to be read before either registry could be understood.
 *
 * @since 0.1.0
 */
final class Triggers {

	/**
	 * Registered triggers, keyed by trigger key, in registration order.
	 *
	 * @since 0.1.0
	 * @var array<string, Trigger>
	 */
	private array $triggers = array();

	/**
	 * Adds a trigger to the registry.
	 *
	 * Registration is deliberately not idempotent: a key that is already taken
	 * raises rather than overwriting. A silent overwrite would let one plugin
	 * take over another plugin's trigger while every stored trigger config kept
	 * pointing at the same `type` string, so a popup would quietly start opening
	 * on a different event than its author chose.
	 *
	 * A caller that genuinely does not know whether a key is free should ask
	 * {@see Triggers::is_registered()} first.
	 *
	 * @since 0.1.0
	 *
	 * @param Trigger $trigger Trigger to register.
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the key is empty, or is already registered.
	 */
	public function register( Trigger $trigger ): void {
		/*
		 * Defense in depth. A well-formed Trigger cannot carry an empty key, but
		 * the registry is what the rest of the plugin indexes by that key, so it
		 * refuses to hold one rather than trusting its caller.
		 */
		if ( '' === $trigger->key ) {
			throw new \InvalidArgumentException(
				esc_html__( 'A trigger cannot be registered with an empty key.', 'popkit' )
			);
		}

		if ( isset( $this->triggers[ $trigger->key ] ) ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: %s: trigger key that is already in use. */
						__( 'A trigger is already registered under the key "%s". Trigger keys must be unique.', 'popkit' ),
						$trigger->key
					)
				)
			);
		}

		$this->triggers[ $trigger->key ] = $trigger;
	}

	/**
	 * Returns a registered trigger by key.
	 *
	 * Returning `null` for an unrecognized key is the normal case, not an error:
	 * a popup may carry a trigger config whose plugin is deactivated.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Trigger key.
	 * @return Trigger|null The trigger, or null when the key is not registered.
	 */
	public function get( string $key ): ?Trigger {
		return $this->triggers[ $key ] ?? null;
	}

	/**
	 * Returns every registered trigger, in registration order.
	 *
	 * Order is preserved so the editor presents triggers in the sequence they
	 * were registered — built-ins first, extensions after — rather than in an
	 * order that shifts between requests.
	 *
	 * The array is returned by value, so a caller cannot add to or remove from
	 * the registry through it. The {@see Trigger} objects inside it are readonly,
	 * so they cannot be mutated either.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, Trigger> Trigger key => trigger.
	 */
	public function all(): array {
		return $this->triggers;
	}

	/**
	 * Reports whether a trigger key is registered.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Trigger key.
	 * @return bool True when a trigger is registered under the key.
	 */
	public function is_registered( string $key ): bool {
		return isset( $this->triggers[ $key ] );
	}
}
