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
 * Phase 1 adds the public registry accessors, the `popkit_popup` post type, its
 * meta keys and the registry REST route. Each is attached through the extension
 * point named for it below, so later phases have one obvious place to attach and
 * a reviewer can tell an unfinished phase from a missing one.
 *
 * Every subsystem is handed its own `init()` and left to choose the hook and
 * priority it needs. This class decides *what* is booted and in *what order*; it
 * does not decide when a post type registers or which hook a REST route hangs
 * off, because those belong with the code that would have to change if they were
 * wrong.
 *
 * `boot()` names no class from a phase that has not been built, so the one
 * remaining extension point — the block editor UI — is empty rather than
 * defensive. There is deliberately no `class_exists()` guard around any class it
 * does name: they are first-party files resolved by the same autoloader that
 * already resolved this one, so a missing one means a broken install, and
 * guarding it would turn that into a silent no-op — the failure mode
 * {@see Activator::assign_capabilities()} was changed to stop producing.
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

		/*
		 * The registries are booted first because boot_registries() is what makes
		 * popkit_conditions() and popkit_triggers() callable at all, and both the
		 * meta sanitizers and the REST route resolve rule types through them.
		 * Nothing below calls either accessor during boot — see the note on
		 * boot_registries() about why forcing one open here would lose
		 * registrations — so the ordering costs nothing and removes the question.
		 */
		$this->boot_registries();
		$this->boot_data_model();
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
	 * Extension point: condition and trigger registries.
	 *
	 * Loads the plugin's public accessor functions and registers nothing else.
	 * Phase 1 ships no built-in conditions or triggers — those arrive alongside
	 * the front-end runtime in Phases 3 and 4 — so both registries start empty and
	 * are filled by whoever hooks `popkit_register_conditions` or
	 * `popkit_register_triggers`.
	 *
	 * `includes/functions.php` declares `popkit_conditions()` and
	 * `popkit_triggers()` in the global namespace, which means it declares no
	 * class. Composer's autoloader for this plugin is a classmap over `includes/`
	 * and the fallback autoloader in `popkit.php` resolves `Popkit\`-prefixed
	 * class names to a file path, so neither loader can ever reach a file that
	 * defines only functions. An explicit require is the only thing that brings
	 * popkit's public API into existence.
	 *
	 * The require is unguarded on purpose, for the reason given on the class:
	 * a shipped first-party file that is absent means a broken install, and a
	 * `file_exists()` check would defer the failure to whichever extension calls
	 * `popkit_conditions()` first, somewhere far less obvious.
	 *
	 * Neither accessor is called here, and that is the load-bearing part. Each
	 * builds its registry lazily and dispatches its registration action exactly
	 * once, tied to construction rather than to the call. Forcing one open on
	 * `plugins_loaded` would fire `popkit_register_conditions` before a callback
	 * added by a theme, or on `init`, or by any plugin that loads after popkit,
	 * had been attached — and those registrations would then be silently dropped,
	 * because the action never fires a second time. Leaving construction to the
	 * first genuine consumer is what keeps every documented registration point
	 * working.
	 *
	 * {@see \Popkit\Triggers\Builtin_Triggers::init()} is the same shape: it
	 * attaches a callback to `popkit_register_triggers` and registers nothing
	 * yet, so calling it here adds popkit's own four triggers to the queue of
	 * registrations without forcing the registry open ahead of anybody else's.
	 *
	 * That call is unguarded like every other in this class. A `class_exists()`
	 * check around it would read as caution and behave as silent degradation: on
	 * an incomplete deploy the site would boot, the editor would offer no trigger
	 * at all, and every saved trigger config would sanitize against an empty
	 * registry — with nothing anywhere saying why. No test protects that site,
	 * because a test only ever runs against a tree where the file is present. A
	 * missing first-party class is a broken install and reports itself as one.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function boot_registries(): void {
		require_once POPKIT_DIR . 'includes/functions.php';

		Triggers\Builtin_Triggers::init();
	}

	/**
	 * Extension point: post type and meta registration.
	 *
	 * Registers the `popkit_popup` post type and the `_popkit_*` meta keys, each
	 * with an explicit REST schema and auth callback.
	 *
	 * Both subsystems attach themselves to `init` rather than being hooked from
	 * here, so the hook and priority each one needs stay with the code that needs
	 * them. Both `init()` calls run on `plugins_loaded`, which always precedes
	 * `init`.
	 *
	 * The order of the two calls is meaningful. Callbacks added to one hook at one
	 * priority run in the order they were added, so registering the post type
	 * first means `register_post_meta()` always runs against a post type
	 * WordPress already knows: the `auth_callback` on every key resolves
	 * `edit_post` through `map_meta_cap()`, and that resolution needs the
	 * capability map declared at post type registration. Reversing these two lines
	 * would leave the meta keys authorised against core's `post` capabilities
	 * instead, which fails open for anyone holding `edit_posts`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function boot_data_model(): void {
		Post_Type::init();
		Meta::init();
	}

	/**
	 * Extension point: REST routes.
	 *
	 * `Rest_Schema::init()` attaches `GET /popkit/v1/registry` to `rest_api_init`
	 * behind a capability check. That route is what lets the block editor render a
	 * working control for a condition it has never heard of, which is the registry
	 * invariant in `CLAUDE.md` that keeps registering a condition a PHP-only act.
	 *
	 * It is also the first genuine consumer of the two registries, so it is the
	 * call that ordinarily constructs them and fires the registration actions —
	 * see boot_registries().
	 *
	 * Phase 2 attaches `GET /popkit/v1/context`, whose public permission callback
	 * is the one documented exception in `CLAUDE.md`. It is deliberately not
	 * stubbed here: a route that exists but answers nothing is worse than one that
	 * does not exist.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function boot_rest(): void {
		Rest_Schema::init();
		Rest_Context::init();
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
		Frontend::init();
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
