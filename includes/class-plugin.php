<?php
/**
 * Plugin container and boot sequence.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Wires popkit's subsystems onto WordPress.
 *
 * A single instance is held for the lifetime of the request and reached through
 * `Plugin::instance()`. `CLAUDE.md` bans singletons for state — this is not one:
 * it holds no plugin data, exposes no getters, and exists only so `boot()` is
 * unambiguously idempotent when a caller loads the plugin file twice. Subsystems
 * added in later phases keep their own state; nothing hangs off this object.
 *
 * Phase 0 boots the text domain, the settings registration and the self-heal
 * hook, and nothing else. The extension points below are
 * named and documented so later phases have one obvious place to attach, and so
 * a reviewer can tell an unfinished phase from a missing one.
 *
 * @since 0.1.0
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @since 0.1.0
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Use instance().
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Not cloneable.
	 *
	 * @since 0.1.0
	 */
	private function __clone() {}

	/**
	 * Returns the single instance.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		self::$instance ??= new self();

		return self::$instance;
	}

	/**
	 * Registers everything the plugin does.
	 *
	 * Runs on `plugins_loaded`. Safe to call more than once; subsequent calls do
	 * nothing, so a double include cannot double-register a hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		/*
		 * Priority 0 on `init` rather than an immediate call: loading translations
		 * before `init` fires triggers a _doing_it_wrong() notice in modern
		 * WordPress, and Phase 0 must activate clean under WP_DEBUG.
		 *
		 * First-class callable syntax lets a private method be hooked without
		 * widening the class's public surface.
		 */
		add_action( 'init', $this->load_textdomain( ... ), 0 );

		/*
		 * Settings registration. Settings::init() decides for itself whether to
		 * register immediately or on `init`, so it is correct to call here on
		 * `plugins_loaded` and stays correct if the plugin is ever booted late.
		 *
		 * This is not a phase extension point. The option carries the uninstall
		 * opt-in documented in `CLAUDE.md` -> Uninstall, and without this call
		 * register_setting() never runs: no sanitize_callback, no REST exposure,
		 * and an opt-in that can only be set by hand-writing an option row.
		 */
		Settings::init();

		/*
		 * Self-heal. Kept as an array callable rather than a first-class callable
		 * so the hook stays removable and assertable by name in tests.
		 *
		 * `wp_doing_ajax()` belongs in the guard because admin-ajax.php fires
		 * `admin_init` for any request carrying a non-empty `action`, before it
		 * dispatches to the authenticated or the `nopriv` handler. `is_admin()`
		 * alone would therefore let an anonymous GET reach a routine that writes
		 * roles and an option. admin-post.php behaves the same way and is not
		 * covered by this guard, which is why Activator::maybe_upgrade() checks a
		 * capability of its own before writing anything.
		 */
		if ( is_admin() && ! wp_doing_ajax() ) {
			add_action( 'admin_init', array( Activator::class, 'maybe_upgrade' ) );
		}

		$this->boot_data_model();
		$this->boot_registries();
		$this->boot_rest();
		$this->boot_frontend();
		$this->boot_admin();
	}

	/**
	 * Loads the plugin text domain.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain( 'popkit', false, dirname( POPKIT_BASENAME ) . '/languages' );
	}

	/**
	 * Extension point: post type and meta registration.
	 *
	 * Phase 1 attaches the `popkit_popup` post type and the `_popkit_*` meta
	 * keys here, each with an explicit REST schema and auth callback. Nothing is
	 * registered in Phase 0.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function boot_data_model(): void {
		/* Intentionally empty until Phase 1. */
	}

	/**
	 * Extension point: condition and trigger registries.
	 *
	 * Phase 1 constructs the two registries and fires
	 * `popkit_register_conditions` / `popkit_register_triggers` here. Phase 4
	 * registers the built-in conditions against them.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function boot_registries(): void {
		/* Intentionally empty until Phase 1. */
	}

	/**
	 * Extension point: REST routes.
	 *
	 * Phase 1 attaches the registry schema route. Phase 2 attaches
	 * `GET /popkit/v1/context`, whose public permission callback is the one
	 * documented exception in `CLAUDE.md`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function boot_rest(): void {
		/* Intentionally empty until Phase 1. */
	}

	/**
	 * Extension point: front end.
	 *
	 * Phase 2 attaches server-side matching, config emission, popup markup and
	 * the conditional asset enqueue. Nothing may be enqueued unconditionally:
	 * assets load only when at least one popup survives server evaluation.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function boot_frontend(): void {
		/* Intentionally empty until Phase 2. */
	}

	/**
	 * Extension point: admin and block editor.
	 *
	 * Phase 5 attaches the editor sidebar assets and the schema-driven controls.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function boot_admin(): void {
		/* Intentionally empty until Phase 5. */
	}
}
