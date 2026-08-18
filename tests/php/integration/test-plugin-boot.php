<?php
/**
 * Integration tests for the plugin boot sequence.
 *
 * `Popkit\Plugin::boot()` claims to be safe to call more than once. That claim
 * is load bearing and it is not free: the text domain loader is attached with
 * first-class callable syntax, and `$this->load_textdomain( ... )` produces a
 * *new* Closure on every evaluation. WordPress derives a callback's identity
 * from that object, so two evaluations are two different callbacks and
 * `add_action()` will happily hold both. Nothing dedupes them for us — the
 * `$booted` guard is the only thing standing between a double include and a
 * hook registered twice, then three times, then once per include for the rest
 * of the request.
 *
 * A test that only checked `has_action()` would miss this entirely: `has_action()`
 * answers "is there at least one", which stays true no matter how many copies
 * accumulate. So the assertions below count callbacks rather than look for them.
 *
 * @package Popkit
 */

use Popkit\Activator;
use Popkit\Editor;
use Popkit\Plugin;

/**
 * Integration coverage for Popkit\Plugin::boot().
 */
final class Test_Popkit_Plugin_Boot extends WP_UnitTestCase {

	/**
	 * Admin screen in place before the test ran, restored afterwards.
	 *
	 * @var WP_Screen|null
	 */
	private $original_screen = null;

	/**
	 * Records the current screen.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->original_screen = isset( $GLOBALS['current_screen'] ) ? $GLOBALS['current_screen'] : null;
	}

	/**
	 * Restores the admin screen.
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( null === $this->original_screen ) {
			unset( $GLOBALS['current_screen'] );
		} else {
			$GLOBALS['current_screen'] = $this->original_screen;
		}

		parent::tear_down();
	}

	/**
	 * The plugin is booted from `plugins_loaded`, once.
	 *
	 * Establishes that the live instance is genuinely booted, so that the
	 * no-op assertion in the next test is a statement about the guard rather
	 * than about a plugin that never started.
	 *
	 * @return void
	 */
	public function test_the_plugin_is_booted_from_plugins_loaded() {
		$this->assertNotFalse(
			has_action( 'plugins_loaded', 'popkit_boot' ),
			'The plugin must boot on plugins_loaded. Booting earlier runs before other plugins have registered their extension points; booting later is too late for init.'
		);
		$this->assertGreaterThan(
			0,
			did_action( 'plugins_loaded' ),
			'plugins_loaded has not fired, so the instance the next test calls boot() on was never booted in the first place and its no-op would prove nothing.'
		);
	}

	/**
	 * Re-booting the live instance registers nothing.
	 *
	 * The realistic double-include: a second `require` of the plugin file, or a
	 * second `plugins_loaded` in a long-running process.
	 *
	 * @return void
	 */
	public function test_booting_the_live_instance_again_registers_no_further_hooks() {
		$before = $this->hook_snapshot();

		Plugin::instance()->boot();
		Plugin::instance()->boot();

		$this->assertSame(
			$before,
			$this->hook_snapshot(),
			'Calling boot() again on the already booted instance changed the registered hooks. Every hook it registers would then fire twice per request.'
		);
	}

	/**
	 * A first boot registers hooks; every boot after that registers none.
	 *
	 * Driven against a fresh, never-booted instance so that the first call is
	 * observable. The instance is built without the constructor rather than by
	 * resetting the singleton, so the live instance the rest of the suite runs
	 * against is left untouched.
	 *
	 * @return void
	 */
	public function test_boot_registers_its_hooks_exactly_once() {
		$plugin = $this->unbooted_plugin();

		$before = $this->hook_snapshot();

		$plugin->boot();

		$after_first = $this->hook_snapshot();

		$this->assertNotSame(
			$before,
			$after_first,
			'The first boot() registered nothing at all. Everything below tests a guard that has nothing to guard.'
		);
		$this->assertGreaterThan(
			isset( $before['init'] ) ? $before['init'] : 0,
			isset( $after_first['init'] ) ? $after_first['init'] : 0,
			'boot() must add a callback to init. The text domain, and everything later phases attach, hangs off that hook.'
		);

		$plugin->boot();
		$plugin->boot();

		$this->assertSame(
			$after_first,
			$this->hook_snapshot(),
			'boot() registered hooks a second and third time. First-class callable syntax produces a new Closure on every evaluation, so WordPress cannot recognise the duplicate: the $booted guard is the only thing preventing it.'
		);
	}

	/**
	 * Booting attaches a loader for popkit's own translations.
	 *
	 * Asserted behaviourally: the callbacks boot() added to `init` are invoked
	 * against a recording registry, and the recording has to show popkit's text
	 * domain being pointed at popkit's languages directory. A callback that
	 * loaded the wrong domain, or read from the wrong directory, satisfies
	 * `has_action()` and fails here.
	 *
	 * @return void
	 */
	public function test_boot_registers_a_loader_for_the_popkit_text_domain() {
		$plugin = $this->unbooted_plugin();

		$before = $this->hook_callbacks( 'init' );

		$plugin->boot();

		$added = array_diff_key( $this->hook_callbacks( 'init' ), $before );

		$this->assertNotSame( array(), $added, 'boot() registered nothing on init, so the text domain is never loaded.' );

		$this->assertContains(
			0,
			$this->priorities_of( $added ),
			'The text domain must load on init at priority 0. Anything the plugin hooks to init at a normal priority — and anything another plugin hooks — may translate a string, and a string translated before the domain is available is silently returned untranslated.'
		);

		$recorded = $this->record_textdomain_registrations( $added );

		$this->assertArrayHasKey(
			'popkit',
			$recorded,
			'Nothing registered the "popkit" text domain. Every translated string in the plugin uses that domain, so a missing or misspelled domain here means the plugin can never be translated at all — and nothing warns about it, because untranslated strings look exactly like translated ones in English.'
		);
		$this->assertStringEndsWith(
			'/languages',
			wp_normalize_path( (string) $recorded['popkit'] ),
			'The text domain must be loaded from the plugin\'s own languages directory, matching the Domain Path header.'
		);
	}

	/**
	 * Booting in the admin attaches the self-heal routine to `admin_init`.
	 *
	 * Without this hook a site restored from a backup taken before activation,
	 * or updated by dropping files in over FTP, never regains its capabilities:
	 * the activation hook does not fire in either case.
	 *
	 * @return void
	 */
	public function test_boot_attaches_the_self_heal_routine_in_the_admin() {
		$this->enter_admin();

		$this->unbooted_plugin()->boot();

		$this->assertNotFalse(
			has_action( 'admin_init', array( Activator::class, 'maybe_upgrade' ) ),
			'The self-heal routine is not hooked to admin_init. A site whose activation hook never fired — restored from a backup, or updated over FTP — would sit permanently without capabilities and with no error anywhere.'
		);
	}

	/**
	 * Booting attaches the block editor sidebar.
	 *
	 * This is the test for a failure that has already happened twice in this
	 * project, in two different subsystems: a class that is complete, correct and
	 * fully covered by its own tests, whose `init()` nothing ever calls. Every
	 * test of `Popkit\Editor` passes against an editor screen that never loads it,
	 * because those tests call `Editor::init()` themselves. Only a test driven
	 * through `Plugin::boot()` can tell the difference.
	 *
	 * Both hooks are asserted rather than one. They are registered by the same
	 * call and would normally appear together — but `render_missing_build_notice()`
	 * is the thing that *reports* an absent bundle, so a regression that dropped
	 * only the notice would remove the one signal an author gets when the sidebar
	 * silently fails to load.
	 *
	 * @return void
	 */
	public function test_boot_attaches_the_block_editor_sidebar() {
		$this->unbooted_plugin()->boot();

		$this->assertNotFalse(
			has_action( 'enqueue_block_editor_assets', array( Editor::class, 'enqueue_assets' ) ),
			'The editor bundle is never enqueued, so the popup sidebar does not exist on any screen. Editor::init() is not reached from Plugin::boot(). Every other Editor test passes regardless, because they call Editor::init() themselves.'
		);

		$this->assertNotFalse(
			has_action( 'admin_notices', array( Editor::class, 'render_missing_build_notice' ) ),
			'The missing-build notice is not hooked, so a checkout where npm run build never ran shows an editor screen with no popkit panels and no explanation.'
		);
	}

	/**
	 * Builds a Plugin instance that has never been booted.
	 *
	 * The constructor is private and the class holds its own instance, so
	 * reflection is the only way to get a second one. `newInstanceWithoutConstructor()`
	 * still applies declared property defaults, which is all this needs.
	 *
	 * @return Plugin
	 */
	private function unbooted_plugin() {
		$reflection = new ReflectionClass( Plugin::class );

		return $reflection->newInstanceWithoutConstructor();
	}

	/**
	 * Invokes callbacks with a recording text domain registry in place.
	 *
	 * Since WordPress 6.7 `load_plugin_textdomain()` no longer reads a
	 * translation file itself; it hands the plugin's languages directory to the
	 * registry and lets the just-in-time loader take it from there. Recording
	 * that hand-off is the way left to assert from the outside that the callback
	 * hooked to `init` loads popkit's translations from popkit's directory,
	 * rather than merely being a callback that runs without complaint.
	 *
	 * The real registry is swapped back even if an invoked callback throws.
	 *
	 * @param array<string, callable> $callbacks Callbacks to invoke.
	 * @return array<string, string> Text domain => languages directory registered for it.
	 */
	private function record_textdomain_registrations( array $callbacks ) {
		$recorder = new class() extends WP_Textdomain_Registry {

			/**
			 * Custom paths handed to the registry, keyed by text domain.
			 *
			 * @var array<string, string>
			 */
			public $recorded_paths = array();

			/**
			 * Records the custom path, then behaves as the real registry does.
			 *
			 * @param string $domain Text domain.
			 * @param string $path   Language directory path.
			 * @return void
			 */
			public function set_custom_path( $domain, $path ) {
				$this->recorded_paths[ $domain ] = $path;

				parent::set_custom_path( $domain, $path );
			}
		};

		$had      = array_key_exists( 'wp_textdomain_registry', $GLOBALS );
		$previous = $had ? $GLOBALS['wp_textdomain_registry'] : null;

		$GLOBALS['wp_textdomain_registry'] = $recorder;

		try {
			foreach ( $callbacks as $callback ) {
				$this->assertIsCallable( $callback, 'A registered hook callback is not callable.' );

				call_user_func( $callback );
			}
		} finally {
			if ( $had ) {
				$GLOBALS['wp_textdomain_registry'] = $previous;
			} else {
				unset( $GLOBALS['wp_textdomain_registry'] );
			}
		}

		return $recorder->recorded_paths;
	}

	/**
	 * Counts the callbacks registered against every hook.
	 *
	 * A count rather than a presence check: duplicate registrations are the
	 * failure being looked for, and every presence check in WordPress reports
	 * the same answer for one copy as for five.
	 *
	 * @return array<string, int> Hook name => number of registered callbacks.
	 */
	private function hook_snapshot() {
		$snapshot = array();

		foreach ( $GLOBALS['wp_filter'] as $hook_name => $hook ) {
			if ( ! $hook instanceof WP_Hook ) {
				continue;
			}

			$total = 0;

			foreach ( $hook->callbacks as $registered ) {
				$total += count( $registered );
			}

			$snapshot[ (string) $hook_name ] = $total;
		}

		ksort( $snapshot );

		return $snapshot;
	}

	/**
	 * Returns every callback registered against one hook.
	 *
	 * Keys combine priority and WordPress' own callback id, so two registrations
	 * of the same callable at different priorities stay distinguishable and an
	 * array difference against an earlier reading yields exactly what was added.
	 *
	 * @param string $hook_name Hook name.
	 * @return array<string, callable> "priority|id" => callback.
	 */
	private function hook_callbacks( $hook_name ) {
		$callbacks = array();

		if ( ! isset( $GLOBALS['wp_filter'][ $hook_name ] ) || ! $GLOBALS['wp_filter'][ $hook_name ] instanceof WP_Hook ) {
			return $callbacks;
		}

		foreach ( $GLOBALS['wp_filter'][ $hook_name ]->callbacks as $priority => $registered ) {
			foreach ( $registered as $id => $entry ) {
				$callbacks[ $priority . '|' . $id ] = $entry['function'];
			}
		}

		return $callbacks;
	}

	/**
	 * Extracts the priorities from a hook_callbacks() result.
	 *
	 * @param array<string, callable> $callbacks Keyed "priority|id".
	 * @return int[]
	 */
	private function priorities_of( array $callbacks ) {
		$priorities = array();

		foreach ( array_keys( $callbacks ) as $key ) {
			$parts        = explode( '|', (string) $key, 2 );
			$priorities[] = (int) $parts[0];
		}

		return $priorities;
	}

	/**
	 * Puts the request into the admin.
	 *
	 * @return void
	 */
	private function enter_admin() {
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
		}

		set_current_screen( 'dashboard' );

		$this->assertTrue( is_admin(), 'Fixture: the request must be an admin request.' );
	}
}
