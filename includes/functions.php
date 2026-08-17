<?php
/**
 * Public accessor functions for the two popkit registries.
 *
 * WordPress plugins expose their public API as prefixed functions in the global
 * namespace, so an extension can call `popkit_conditions()` without importing a
 * namespace or knowing a class name. Everything else in this plugin lives under
 * `Popkit\`; these two functions are the deliberate exception, and both carry
 * the `popkit_` prefix.
 *
 * This file declares no classes and is therefore invisible to Composer's
 * classmap and to the `Popkit\` autoloaders in `popkit.php` and in the test
 * bootstrap. It must be pulled in with an explicit `require_once` from the
 * plugin bootstrap, before anything calls either accessor.
 *
 * @package Popkit
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'popkit_conditions' ) ) {
	/**
	 * Returns the condition registry, constructing it on first use.
	 *
	 * The instance is held in a static local, built lazily, and
	 * `popkit_register_conditions` fires exactly once — immediately after that
	 * first construction. Every part of that sentence is load-bearing.
	 *
	 * *Lazily*, so registration does not depend on when popkit happens to call
	 * the accessor. A third party hooking `popkit_register_conditions` from a
	 * must-use plugin that loads before popkit, from `plugins_loaded`, or from
	 * `init` is picked up either way: the callbacks are collected by
	 * `add_action()` first and dispatched only when the first caller actually
	 * needs the registry, whether that caller is the REST schema route, the
	 * front-end matcher, or a test.
	 *
	 * *Exactly once*, because `do_action()` runs every attached callback on
	 * every dispatch and {@see \Popkit\Conditions::register()} raises on a
	 * duplicate key. Re-firing would turn an ordinary second call to this
	 * function into a fatal error, so the dispatch is tied to construction
	 * rather than to the call.
	 *
	 * The static is assigned *before* the action fires. A callback that calls
	 * `popkit_conditions()` re-entrantly — to inspect what is already
	 * registered, say — therefore receives the partially populated registry
	 * instead of recursing until the stack runs out.
	 *
	 * Because the instance lives in a static local it cannot be replaced or
	 * reset. Tests that need a clean registry construct
	 * `new \Popkit\Conditions()` directly; the class holds no global state, so
	 * an independent instance behaves identically.
	 *
	 * @since 0.1.0
	 *
	 * @return \Popkit\Conditions Shared condition registry.
	 */
	function popkit_conditions(): \Popkit\Conditions {
		static $registry = null;

		if ( null === $registry ) {
			$registry = new \Popkit\Conditions();

			/**
			 * Fires once, immediately after the condition registry is built.
			 *
			 * Register condition types here. This is the only supported moment
			 * to do so — the registry is read straight afterwards by whichever
			 * caller triggered construction.
			 *
			 * Registering a key that is already taken raises an
			 * `InvalidArgumentException` rather than replacing the existing
			 * condition. Namespace extension keys so they cannot collide.
			 *
			 * @since 0.1.0
			 *
			 * @param \Popkit\Conditions $registry Registry to add conditions to.
			 */
			do_action( 'popkit_register_conditions', $registry );
		}

		return $registry;
	}
}

if ( ! function_exists( 'popkit_triggers' ) ) {
	/**
	 * Returns the trigger registry, constructing it on first use.
	 *
	 * Same lazy-and-once contract as {@see popkit_conditions()}: the instance is
	 * held in a static local, built on the first call, and
	 * `popkit_register_triggers` is dispatched once, tied to construction rather
	 * than to the call. That is what lets a third party hook the action from a
	 * must-use plugin, from `plugins_loaded`, or from `init` and be picked up
	 * regardless, while a second dispatch — which would hit
	 * {@see \Popkit\Triggers::register()}'s duplicate-key guard and fatal —
	 * cannot happen.
	 *
	 * The static is assigned before the action fires, so a re-entrant call from
	 * a callback returns the partially populated registry rather than recursing.
	 *
	 * Tests that need a clean registry construct `new \Popkit\Triggers()`
	 * directly.
	 *
	 * @since 0.1.0
	 *
	 * @return \Popkit\Triggers Shared trigger registry.
	 */
	function popkit_triggers(): \Popkit\Triggers {
		static $registry = null;

		if ( null === $registry ) {
			$registry = new \Popkit\Triggers();

			/**
			 * Fires once, immediately after the trigger registry is built.
			 *
			 * Register trigger types here. This is the only supported moment to
			 * do so — the registry is read straight afterwards by whichever
			 * caller triggered construction.
			 *
			 * Registering a key that is already taken raises an
			 * `InvalidArgumentException` rather than replacing the existing
			 * trigger. Namespace extension keys so they cannot collide.
			 *
			 * @since 0.1.0
			 *
			 * @param \Popkit\Triggers $registry Registry to add triggers to.
			 */
			do_action( 'popkit_register_triggers', $registry );
		}

		return $registry;
	}
}
