<?php
/**
 * Integration tests for uninstall behavior.
 *
 * Two behaviors are asserted, and the difference between them is the whole point:
 *
 * 1. **Default.** Uninstall removes plugin *infrastructure* — options, transients,
 *    capabilities — and leaves every authored `popkit_popup` post and its meta
 *    exactly where it was. Deleting a client's popups because a plugin was removed
 *    during routine maintenance is not acceptable behavior, and "the docs said it
 *    would" is not a defense.
 * 2. **Opt in.** With the opt-in setting set to `true`, and only then, the popups
 *    and their meta go too.
 *
 * The opt-in lives in two places and both are covered here: the canonical
 * `popkit_settings` array — what the admin screen and the REST route write — and
 * the standalone `popkit_delete_data_on_uninstall` alias, consulted only while the
 * settings array has never been written. The array wins whenever it exists, and
 * that precedence is asserted rather than assumed.
 *
 * The setting defaults to `false`, and "defaults to false" is asserted the honest
 * way: with no option row present at all, not with a row explicitly holding false.
 * An implementation that reads `get_option( $key, true )` would pass the second and
 * fail the first. The "explicitly false" scenario is written with `add_option()`,
 * because `update_option( $key, false )` on an option that does not exist writes
 * nothing at all and would quietly collapse into the "absent" scenario.
 *
 * Every run is guarded by a sentinel — see self::assert_uninstall_executed(). A
 * test suite that asserts "the content survived" is only meaningful if the
 * destructive code actually ran, so a run that turns out to be a no-op fails
 * loudly instead of passing green.
 *
 * @package Popkit
 */

use Popkit\Capabilities;

/**
 * Integration coverage for uninstall.php.
 */
final class Test_Popkit_Uninstall extends WP_UnitTestCase {

	/**
	 * Post type under test.
	 *
	 * @var string
	 */
	private const POST_TYPE = 'popkit_popup';

	/**
	 * The standalone opt-in option, read only while self::SETTINGS has never
	 * been written.
	 *
	 * @var string
	 */
	private const SETTING = 'popkit_delete_data_on_uninstall';

	/**
	 * The canonical settings array. When this option exists it is authoritative
	 * and self::SETTING is never consulted.
	 *
	 * @var string
	 */
	private const SETTINGS = 'popkit_settings';

	/**
	 * The key holding the opt-in inside the settings array.
	 *
	 * @var string
	 */
	private const SETTINGS_KEY = 'delete_data_on_uninstall';

	/**
	 * An option belonging to another plugin. It must survive.
	 *
	 * @var string
	 */
	private const FOREIGN_OPTION = 'not_a_popkit_option';

	/**
	 * A transient the prefix sweep is expected to remove.
	 *
	 * @var string
	 */
	private const PROBE_TRANSIENT = 'popkit_probe';

	/**
	 * Sentinel option seeded immediately before every uninstall run.
	 *
	 * Prefixed `popkit_`, so the option sweep removes it unconditionally —
	 * regardless of the opt-in, which governs content only.
	 *
	 * @var string
	 */
	private const SENTINEL_OPTION = 'popkit_uninstall_sentinel';

	/**
	 * Sentinel transient seeded immediately before every uninstall run.
	 *
	 * Removed by the transient sweep, which is a different code path from the
	 * option sweep. Two sentinels on two paths means no single change to
	 * uninstall.php can make a no-op run look like a real one.
	 *
	 * @var string
	 */
	private const SENTINEL_TRANSIENT = 'popkit_uninstall_sentinel_transient';

	/**
	 * The documented entry point of uninstall.php.
	 *
	 * @var string
	 */
	private const ENTRY_POINT = 'popkit_uninstall_run';

	/**
	 * The primitive capabilities uninstall must revoke.
	 *
	 * @var string[]
	 */
	private const PRIMITIVES = array(
		'edit_popkit_popups',
		'edit_others_popkit_popups',
		'publish_popkit_popups',
		'read_private_popkit_popups',
		'delete_popkit_popups',
		'edit_private_popkit_popups',
		'edit_published_popkit_popups',
		'delete_private_popkit_popups',
		'delete_published_popkit_popups',
		'delete_others_popkit_popups',
	);

	/**
	 * Whether this test registered the temporary post type itself.
	 *
	 * @var bool
	 */
	private $registered_post_type = false;

	/**
	 * Whether uninstall.php has already been loaded in this PHP process.
	 *
	 * @var bool
	 */
	private static $uninstall_loaded = false;

	/**
	 * Entry point captured from the first load.
	 *
	 * `null` means the file declared nothing and may simply be included again.
	 * `false` means it declared something this test cannot re-invoke.
	 *
	 * @var callable|null|false
	 */
	private static $uninstall_entry_point = null;

	/**
	 * Assigns capabilities and ensures the post type exists.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// The role option is rolled back between tests but the WP_Roles global is
		// not, and these tests strip capabilities from it. Re-read from the
		// database before assigning so each test starts from a known state.
		wp_roles()->for_site();

		$this->assign_capabilities();
		$this->ensure_post_type();
	}

	/**
	 * Removes the temporary post type registration.
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( $this->registered_post_type ) {
			unregister_post_type( self::POST_TYPE );
			$this->registered_post_type = false;
		}

		parent::tear_down();
	}

	/**
	 * With no setting stored at all, authored popups survive uninstall.
	 *
	 * @return void
	 */
	public function test_uninstall_leaves_popups_intact_when_the_setting_is_absent() {
		$fixture = $this->seed_fixture();

		$this->clear_setting();

		$this->run_uninstall();

		foreach ( $fixture['popups'] as $label => $post_id ) {
			$this->assertInstanceOf(
				'WP_Post',
				get_post( $post_id ),
				sprintf( 'The %s popup was deleted by an uninstall that was never opted in to. Authored content is not plugin infrastructure.', $label )
			);
		}

		$this->assertSame( 'publish', get_post_status( $fixture['popups']['published'] ), 'The published popup changed status during uninstall.' );
		$this->assertSame( 'draft', get_post_status( $fixture['popups']['draft'] ), 'The draft popup changed status during uninstall.' );
		$this->assertSame( 'trash', get_post_status( $fixture['popups']['trashed'] ), 'The trashed popup changed status during uninstall.' );
	}

	/**
	 * With the setting stored as false, authored popups survive uninstall.
	 *
	 * The row is written with add_option(), not update_option(): update_option()
	 * short-circuits when the new value matches the stored one, and get_option()
	 * on an option that was never written returns false — so
	 * `update_option( $key, false )` here would write nothing and this test would
	 * silently duplicate the "absent" case above instead of covering the branch
	 * it names.
	 *
	 * @return void
	 */
	public function test_uninstall_leaves_popups_intact_when_the_setting_is_false() {
		$fixture = $this->seed_fixture();

		$this->store_setting( false );

		$this->run_uninstall();

		foreach ( $fixture['popups'] as $label => $post_id ) {
			$this->assertInstanceOf(
				'WP_Post',
				get_post( $post_id ),
				sprintf( 'The %s popup was deleted although the opt-in setting was stored as false.', $label )
			);
		}
	}

	/**
	 * Popup meta survives the default uninstall.
	 *
	 * What is guarded here is that a default uninstall does not remove authored
	 * meta — not that it is stored byte for byte as it was handed over. The
	 * fixture seeds `array( 'layout' => 'modal' )` and the registered
	 * `sanitize_callback` correctly fills in the rest of the documented defaults
	 * from `docs/data-model.md` -> Display, so the authored value is normalized on
	 * *write*, long before uninstall runs. The byte-identical requirement in
	 * `docs/CLAUDE.md` is about the `values` of an unknown rule type, which has no
	 * declared schema to normalize against; registered meta that does have one is
	 * a different contract.
	 *
	 * Existence is therefore asserted with metadata_exists() rather than inferred
	 * from what get_post_meta() returns. A registered key carries a default, and
	 * get_post_meta() serves that default for a row that is no longer there — so
	 * reading the value alone would report a deleted row as an intact one.
	 *
	 * @return void
	 */
	public function test_uninstall_leaves_popup_meta_intact_by_default() {
		$fixture = $this->seed_fixture();

		$this->clear_setting();

		$this->run_uninstall();

		foreach ( $fixture['popups'] as $label => $post_id ) {
			$this->assertTrue(
				metadata_exists( 'post', $post_id, '_popkit_display' ),
				sprintf( 'The display meta row for the %s popup was deleted by an uninstall that was never opted in to. Authored content is not plugin infrastructure.', $label )
			);

			$display = get_post_meta( $post_id, '_popkit_display', true );

			$this->assertIsArray(
				$display,
				sprintf( 'Display meta for the %s popup no longer reads as an object after a default uninstall.', $label )
			);
			$this->assertNotSame(
				array(),
				$display,
				sprintf( 'Display meta for the %s popup was emptied by a default uninstall.', $label )
			);
			$this->assertSame(
				'modal',
				$display['layout'] ?? null,
				sprintf( 'The authored layout was lost from the %s popup by a default uninstall. Sanitization fills in the other documented display defaults on write; it never rewrites what the author chose.', $label )
			);

			$this->assertTrue(
				metadata_exists( 'post', $post_id, '_popkit_schema_version' ),
				sprintf( 'The schema version meta row for the %s popup was deleted by a default uninstall. Without it a reinstall reads the popup as version 1 and reruns migrations that already ran.', $label )
			);
			$this->assertSame(
				1,
				(int) get_post_meta( $post_id, '_popkit_schema_version', true ),
				sprintf( 'Schema version meta on the %s popup changed value during a default uninstall.', $label )
			);
		}
	}

	/**
	 * The default uninstall still removes options, transients and capabilities.
	 *
	 * @return void
	 */
	public function test_uninstall_removes_infrastructure_by_default() {
		$this->seed_fixture();

		$this->clear_setting();

		$this->run_uninstall();

		$this->assert_infrastructure_removed();
	}

	/**
	 * With the setting enabled, popups and their meta are removed.
	 *
	 * @return void
	 */
	public function test_opt_in_uninstall_removes_popups_and_their_meta() {
		$fixture = $this->seed_fixture();

		$this->store_setting( true );

		$this->run_uninstall();

		foreach ( $fixture['popups'] as $label => $post_id ) {
			$this->assertNull(
				get_post( $post_id ),
				sprintf( 'The %s popup survived an opt-in uninstall.', $label )
			);
		}

		$this->assertSame(
			0,
			$this->count_popup_rows(),
			'Rows for popkit_popup remain in the posts table after an opt-in uninstall.'
		);
		$this->assertSame(
			0,
			$this->count_meta_rows( array_values( $fixture['popups'] ) ),
			'Post meta rows for deleted popups remain. wp_delete_post() with force removes them; a direct DELETE on the posts table alone orphans every meta row.'
		);
	}

	/**
	 * The opt-in uninstall also removes trashed popups.
	 *
	 * A popup in the trash is still authored content, and it is still a row. The
	 * common way to miss it is `get_posts( array( 'post_status' => 'any' ) )`,
	 * which excludes trash by design.
	 *
	 * @return void
	 */
	public function test_opt_in_uninstall_removes_trashed_popups() {
		$fixture = $this->seed_fixture();

		$this->store_setting( true );

		$this->run_uninstall();

		$this->assertNull(
			get_post( $fixture['popups']['trashed'] ),
			'A trashed popup survived an opt-in uninstall. Query popup IDs by post type across every status, including trash — post_status "any" silently excludes it.'
		);
	}

	/**
	 * The opt-in uninstall removes infrastructure as well as content.
	 *
	 * @return void
	 */
	public function test_opt_in_uninstall_removes_infrastructure() {
		$this->seed_fixture();

		$this->store_setting( true );

		$this->run_uninstall();

		$this->assert_infrastructure_removed();
	}

	/**
	 * The canonical settings array opts in on its own.
	 *
	 * `popkit_settings` is what the admin screen and the REST route actually
	 * write; the standalone option is only a fallback for sites that never saved
	 * the settings. Covering the fallback alone leaves the authoritative branch
	 * untested — and it is the branch that authorizes irreversible deletion.
	 *
	 * @return void
	 */
	public function test_opt_in_through_the_settings_array_removes_popups() {
		$fixture = $this->seed_fixture();

		// No standalone option anywhere: the array has to carry this on its own.
		delete_option( self::SETTING );
		$this->store_settings( array( self::SETTINGS_KEY => true ) );

		$this->run_uninstall();

		foreach ( $fixture['popups'] as $label => $post_id ) {
			$this->assertNull(
				get_post( $post_id ),
				sprintf( 'The %s popup survived an uninstall opted in to through the popkit_settings array. Read the settings array, not only the standalone option.', $label )
			);
		}

		$this->assertSame(
			0,
			$this->count_popup_rows(),
			'Rows for popkit_popup remain after an uninstall opted in to through the popkit_settings array.'
		);
	}

	/**
	 * The canonical settings array holding false keeps authored content.
	 *
	 * @return void
	 */
	public function test_settings_array_set_to_false_leaves_popups_intact() {
		$fixture = $this->seed_fixture();

		// No standalone option anywhere: the array has to carry this on its own.
		delete_option( self::SETTING );
		$this->store_settings( array( self::SETTINGS_KEY => false ) );

		$this->run_uninstall();

		foreach ( $fixture['popups'] as $label => $post_id ) {
			$this->assertInstanceOf(
				'WP_Post',
				get_post( $post_id ),
				sprintf( 'The %s popup was deleted although the popkit_settings array holds false.', $label )
			);
		}
	}

	/**
	 * The settings array wins over the standalone option.
	 *
	 * Once the settings array exists, what the admin screen showed is what must
	 * happen. A stale standalone option left over from an earlier version cannot
	 * be allowed to authorize a deletion the site owner switched off.
	 *
	 * @return void
	 */
	public function test_settings_array_takes_precedence_over_the_standalone_option() {
		$fixture = $this->seed_fixture();

		$this->store_setting( true );
		$this->store_settings( array( self::SETTINGS_KEY => false ) );

		$this->run_uninstall();

		foreach ( $fixture['popups'] as $label => $post_id ) {
			$this->assertInstanceOf(
				'WP_Post',
				get_post( $post_id ),
				sprintf( 'The %s popup was deleted on the strength of the standalone option while the canonical popkit_settings array said no. The array is authoritative whenever it exists.', $label )
			);
		}
	}

	/**
	 * Neither mode touches anything belonging to anybody else.
	 *
	 * Asserted against the opt-in path because that is the destructive one.
	 *
	 * @return void
	 */
	public function test_opt_in_uninstall_touches_nothing_outside_the_plugin() {
		$fixture = $this->seed_fixture();

		$this->store_setting( true );

		$this->run_uninstall();

		$this->assertInstanceOf(
			'WP_Post',
			get_post( $fixture['foreign_post'] ),
			'An ordinary post was deleted by uninstall. Deletion must be scoped to the popkit_popup post type.'
		);
		$this->assertSame(
			'keep me',
			get_post_meta( $fixture['foreign_post'], 'unrelated_meta_key', true ),
			'Meta belonging to an ordinary post was deleted by uninstall.'
		);
		$this->assertSame(
			'keep me',
			get_option( self::FOREIGN_OPTION ),
			'An option belonging to another plugin was deleted by uninstall.'
		);
		$this->assertTrue(
			get_role( 'editor' )->has_cap( 'edit_posts' ),
			'Core capabilities were revoked by uninstall. Only popkit capabilities may be removed.'
		);
		$this->assertTrue(
			get_role( 'administrator' )->has_cap( 'manage_options' ),
			'Core capabilities were revoked by uninstall. Only popkit capabilities may be removed.'
		);
	}

	/**
	 * Asserts that plugin infrastructure is gone.
	 *
	 * @return void
	 */
	private function assert_infrastructure_removed() {
		$this->assertSame(
			'missing',
			get_option( self::SETTING, 'missing' ),
			'The opt-in setting is itself a plugin option and must be deleted by uninstall.'
		);

		$this->assertSame(
			array(),
			$this->remaining_popkit_options(),
			'Options prefixed popkit_ remain after uninstall. Sweep the prefix rather than deleting a hard-coded list, or every option added later is left behind.'
		);

		$this->assertFalse(
			get_transient( self::PROBE_TRANSIENT ),
			'A popkit_ prefixed transient survived uninstall. Transients are named at the point of use, so only a prefix sweep can remove them all.'
		);

		// Re-read the roles from the database: uninstall may have written the
		// option directly rather than through the Roles API.
		wp_roles()->for_site();

		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );

			$this->assertInstanceOf( 'WP_Role', $role, sprintf( 'The %s role was removed by uninstall.', $role_name ) );

			foreach ( self::PRIMITIVES as $capability ) {
				$this->assertFalse(
					$role->has_cap( $capability ),
					sprintf( 'The %s role still holds "%s" after uninstall. Deactivation keeps capabilities; uninstall removes them.', $role_name, $capability )
				);
			}
		}
	}

	/**
	 * Executes uninstall.php.
	 *
	 * `WP_UNINSTALL_PLUGIN` has to be defined before the file loads or its guard
	 * clause calls `exit` and takes the PHPUnit process with it. The constant
	 * cannot be undefined afterwards, which is harmless — nothing but an uninstall
	 * routine ever reads it.
	 *
	 * The file is loaded with `include`, not `include_once`, so every scenario
	 * gets a genuine run. A procedural uninstall.php — the shape WordPress
	 * documents — re-includes safely. If it were ever changed to declare a
	 * function or a class, a second include would fatal on redeclaration, so the
	 * first run records what the file itself declared (checked by reflection
	 * against the file path, so classes autoloaded as a side effect are not
	 * mistaken for declarations) and later runs call that entry point instead.
	 *
	 * Whichever route is taken, the run is bracketed by the sentinel: seeded
	 * here, asserted gone in self::assert_uninstall_executed(). There is one
	 * return path so that no future edit can arrange for the assertion to be
	 * skipped.
	 *
	 * @return void
	 */
	private function run_uninstall() {
		$path = $this->uninstall_path();

		$this->assertFileExists( $path, 'uninstall.php is required by Phase 0.' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			// Only the presence of the constant matters to the guard clause.
			define( 'WP_UNINSTALL_PLUGIN', 'popkit/popkit.php' );
		}

		$this->seed_sentinel();

		if ( self::$uninstall_loaded ) {
			$this->assertNotFalse(
				self::$uninstall_entry_point,
				'uninstall.php declares functions or classes but exposes no callable entry point, so it can only be executed once per process. Expose a named function or a static method, or keep the file procedural.'
			);

			if ( null === self::$uninstall_entry_point ) {
				include $path;
			} else {
				call_user_func( self::$uninstall_entry_point );
			}
		} else {
			$functions_before = get_defined_functions()['user'];
			$classes_before   = get_declared_classes();

			include $path;

			self::$uninstall_loaded      = true;
			self::$uninstall_entry_point = $this->resolve_entry_point(
				$path,
				array_diff( get_defined_functions()['user'], $functions_before ),
				array_diff( get_declared_classes(), $classes_before )
			);
		}

		$this->refresh_caches();
		$this->assert_uninstall_executed();
	}

	/**
	 * Seeds the probes that prove the next run really did something.
	 *
	 * @return void
	 */
	private function seed_sentinel() {
		delete_option( self::SENTINEL_OPTION );
		delete_transient( self::SENTINEL_TRANSIENT );

		add_option( self::SENTINEL_OPTION, 'sentinel' );
		set_transient( self::SENTINEL_TRANSIENT, 'sentinel', HOUR_IN_SECONDS );

		$this->assertSame(
			'sentinel',
			get_option( self::SENTINEL_OPTION ),
			'The sentinel option could not be seeded, so the run that follows could not be verified.'
		);
		$this->assertSame(
			'sentinel',
			get_transient( self::SENTINEL_TRANSIENT ),
			'The sentinel transient could not be seeded, so the run that follows could not be verified.'
		);
	}

	/**
	 * Asserts that the last run of uninstall.php actually executed.
	 *
	 * Without this, a mis-resolved entry point is invisible: the destructive code
	 * never runs, and every "the content survived" assertion passes for the worst
	 * possible reason. Those assertions are the plugin's central data-safety
	 * guarantee, so a no-op has to be loud rather than green.
	 *
	 * Both probes are plugin infrastructure, removed on every uninstall
	 * regardless of the content opt-in, and they are removed by two different
	 * sweeps — so this cannot be satisfied by a partial run either.
	 *
	 * @return void
	 */
	private function assert_uninstall_executed() {
		$message = sprintf(
			'The uninstall sentinel survived, so uninstall.php did not really run: the entry point resolved to %s. Any "content survived" assertion after this point would pass without the destructive code ever executing. Fix the entry point resolution, not this assertion.',
			$this->describe_entry_point()
		);

		$this->assertSame( 'missing', get_option( self::SENTINEL_OPTION, 'missing' ), $message );
		$this->assertFalse( get_transient( self::SENTINEL_TRANSIENT ), $message );
	}

	/**
	 * Describes the resolved entry point for failure messages.
	 *
	 * @return string
	 */
	private function describe_entry_point() {
		$entry_point = self::$uninstall_entry_point;

		if ( null === $entry_point ) {
			return 'a fresh include of the file';
		}

		if ( is_array( $entry_point ) ) {
			return sprintf( '"%s::%s()"', (string) $entry_point[0], (string) $entry_point[1] );
		}

		return sprintf( '"%s()"', (string) $entry_point );
	}

	/**
	 * Works out how to run uninstall.php a second time.
	 *
	 * Functions are matched in descending order of confidence: the documented
	 * entry point by name, then anything named `*_run`, then — as a last resort —
	 * the first function that merely mentions "uninstall". That last rule alone
	 * is a trap: `get_defined_functions()` preserves declaration order, so it
	 * happily returns a helper such as `popkit_uninstall_capabilities()`, a
	 * getter that deletes nothing, and the whole suite then tests nothing. The
	 * sentinel in run_uninstall() is the backstop; this ordering is what keeps it
	 * from ever being needed.
	 *
	 * @param string   $path          Absolute path to uninstall.php.
	 * @param string[] $new_functions Functions defined during the include.
	 * @param string[] $new_classes   Classes declared during the include.
	 * @return callable|null|false Callable entry point, null when re-including is
	 *                             safe, false when neither is possible.
	 */
	private function resolve_entry_point( $path, array $new_functions, array $new_classes ) {
		$declared_functions = array();

		foreach ( $new_functions as $function ) {
			$reflection = new ReflectionFunction( $function );

			if ( $this->is_same_file( $reflection->getFileName(), $path ) ) {
				$declared_functions[] = $function;
			}
		}

		$declared_classes = array();

		foreach ( $new_classes as $class ) {
			$reflection = new ReflectionClass( $class );

			if ( $this->is_same_file( $reflection->getFileName(), $path ) ) {
				$declared_classes[] = $class;
			}
		}

		if ( array() === $declared_functions && array() === $declared_classes ) {
			return null;
		}

		// 1. The documented entry point, by exact name.
		foreach ( $declared_functions as $function ) {
			if ( 0 === strcasecmp( $function, self::ENTRY_POINT ) ) {
				return $function;
			}
		}

		// 2. Renamed, but still following the convention: something_run().
		foreach ( $declared_functions as $function ) {
			if ( str_ends_with( strtolower( $function ), '_run' ) ) {
				return $function;
			}
		}

		// 3. Last resort. A guess, and the sentinel treats it as one.
		foreach ( $declared_functions as $function ) {
			if ( false !== stripos( $function, 'uninstall' ) ) {
				return $function;
			}
		}

		foreach ( $declared_classes as $class ) {
			foreach ( array( 'uninstall', 'run', 'execute' ) as $method ) {
				if ( method_exists( $class, $method ) ) {
					return array( $class, $method );
				}
			}
		}

		return false;
	}

	/**
	 * Compares two file paths, tolerating separators and symlinks.
	 *
	 * @param string|false $candidate Path reported by reflection.
	 * @param string       $path      Path to compare against.
	 * @return bool
	 */
	private function is_same_file( $candidate, $path ) {
		if ( ! is_string( $candidate ) || '' === $candidate ) {
			return false;
		}

		return wp_normalize_path( (string) realpath( $candidate ) ) === wp_normalize_path( (string) realpath( $path ) );
	}

	/**
	 * Absolute path to the plugin's uninstall.php.
	 *
	 * @return string
	 */
	private function uninstall_path() {
		return dirname( __DIR__, 3 ) . '/uninstall.php';
	}

	/**
	 * Removes every trace of the opt-in, canonical storage included.
	 *
	 * "The setting is absent" means both option rows are absent. Deleting only
	 * the standalone option would leave whatever a previous test wrote in the
	 * settings array, and the settings array is the one that is read first.
	 *
	 * @return void
	 */
	private function clear_setting() {
		delete_option( self::SETTING );
		delete_option( self::SETTINGS );

		$this->assertSame(
			'missing',
			get_option( self::SETTINGS, 'missing' ),
			'The canonical settings option still exists, so this test is not exercising the "never configured" default.'
		);
	}

	/**
	 * Writes the standalone opt-in option, forcing the row into existence.
	 *
	 * Written with add_option() rather than update_option(): update_option()
	 * short-circuits when the new value equals the current one, and get_option()
	 * returns false for an option that was never written — so
	 * `update_option( $key, false )` on an absent option is a guaranteed no-op
	 * and the "stored as false" branch would never be reached.
	 *
	 * @param mixed $value Value to store.
	 * @return void
	 */
	private function store_setting( $value ) {
		delete_option( self::SETTING );

		add_option( self::SETTING, $value );

		$this->assertNotSame(
			'missing',
			get_option( self::SETTING, 'missing' ),
			'The standalone opt-in option row does not exist after add_option(). "Stored as false" and "never stored" are different scenarios and this test needs the first one.'
		);
	}

	/**
	 * Writes the canonical settings array, forcing the row into existence.
	 *
	 * @param array<string, mixed> $settings Settings array to store.
	 * @return void
	 */
	private function store_settings( array $settings ) {
		delete_option( self::SETTINGS );

		add_option( self::SETTINGS, $settings );

		$stored = get_option( self::SETTINGS, 'missing' );

		$this->assertIsArray(
			$stored,
			'The canonical settings option was not stored as an array, so uninstall would read it as corrupt and fall back to the standalone option — which is not what this test is covering.'
		);
		$this->assertArrayHasKey(
			self::SETTINGS_KEY,
			$stored,
			'The opt-in key was dropped from the stored settings array, so this test would assert the "key absent" default instead of the value it set.'
		);
	}

	/**
	 * Drops cached reads so assertions see the database, not a stale cache.
	 *
	 * An uninstall routine is free to use raw SQL — it often has to, to sweep by
	 * prefix — which leaves the options and post caches describing rows that no
	 * longer exist.
	 *
	 * @return void
	 */
	private function refresh_caches() {
		wp_cache_flush();
	}

	/**
	 * Creates popups, meta, an unrelated post and unrelated options.
	 *
	 * @return array{popups: array<string, int>, foreign_post: int}
	 */
	private function seed_fixture() {
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$popups = array(
			'published' => $this->create_popup( $author, 'publish' ),
			'draft'     => $this->create_popup( $author, 'draft' ),
			'private'   => $this->create_popup( $author, 'private' ),
			'trashed'   => $this->create_popup( $author, 'publish' ),
		);

		wp_trash_post( $popups['trashed'] );

		$this->assertSame(
			'trash',
			get_post_status( $popups['trashed'] ),
			'Fixture popup was not trashed. If EMPTY_TRASH_DAYS is 0, wp_trash_post() deletes outright and the trash scenario cannot be asserted.'
		);

		foreach ( $popups as $post_id ) {
			update_post_meta( $post_id, '_popkit_display', array( 'layout' => 'modal' ) );
			update_post_meta( $post_id, '_popkit_schema_version', 1 );
		}

		$foreign_post = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_author' => $author,
				'post_title'  => 'An ordinary post',
			)
		);

		update_post_meta( $foreign_post, 'unrelated_meta_key', 'keep me' );
		update_option( self::FOREIGN_OPTION, 'keep me' );
		set_transient( self::PROBE_TRANSIENT, 'probe value', HOUR_IN_SECONDS );

		return array(
			'popups'       => $popups,
			'foreign_post' => $foreign_post,
		);
	}

	/**
	 * Creates one popup.
	 *
	 * @param int    $author_id Author user ID.
	 * @param string $status    Post status.
	 * @return int Post ID.
	 */
	private function create_popup( $author_id, $status ) {
		return self::factory()->post->create(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => $status,
				'post_author' => $author_id,
				'post_title'  => sprintf( 'Popup (%s)', $status ),
			)
		);
	}

	/**
	 * Returns every remaining option name prefixed `popkit_`.
	 *
	 * @return string[]
	 */
	private function remaining_popkit_options() {
		global $wpdb;

		$like = $wpdb->esc_like( 'popkit_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading raw rows is the point of the assertion; a cached or API read would report options that uninstall deleted with direct SQL, and vice versa.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

		sort( $names );

		return $names;
	}

	/**
	 * Counts rows in the posts table for the popup post type.
	 *
	 * Counted directly rather than through WP_Query so that trashed, draft and
	 * private rows are all included with no status filtering applied anywhere.
	 *
	 * @return int
	 */
	private function count_popup_rows() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting raw rows is the assertion; WP_Query would apply status filtering that hides exactly the leftovers being looked for.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", self::POST_TYPE ) );
	}

	/**
	 * Counts post meta rows belonging to the given posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return int
	 */
	private function count_meta_rows( array $post_ids ) {
		global $wpdb;

		if ( array() === $post_ids ) {
			return 0;
		}

		$ids = implode( ',', array_map( 'absint', $post_ids ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The ID list is built from absint() so there is nothing to prepare, and orphaned meta rows are invisible to the meta API once their post is gone.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ( {$ids} )" );
	}

	/**
	 * Registers a temporary popkit_popup post type when one is not present.
	 *
	 * Phase 0 registers no post type — that is Phase 1's job — so one is put in
	 * place here from the plugin's own capability map. Uninstall must handle
	 * popkit_popup rows whether or not the post type happens to be registered at
	 * the time it runs, which on a real uninstall it usually is not.
	 *
	 * @return void
	 */
	private function ensure_post_type() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		$post_type = register_post_type(
			self::POST_TYPE,
			array(
				'public'          => false,
				'show_ui'         => true,
				'show_in_rest'    => true,
				'supports'        => array( 'title', 'editor', 'custom-fields', 'revisions' ),
				'capability_type' => array( 'popkit_popup', 'popkit_popups' ),
				'map_meta_cap'    => true,
				'capabilities'    => $this->capability_map(),
			)
		);

		$this->assertNotWPError( $post_type, 'The temporary popkit_popup post type could not be registered.' );

		$this->registered_post_type = true;
	}

	/**
	 * Runs the plugin's capability assignment routine.
	 *
	 * @return void
	 */
	private function assign_capabilities() {
		$this->call_capabilities_method( array( 'assign', 'assign_roles', 'add_caps', 'grant', 'install' ) );
	}

	/**
	 * Returns the plugin's capability map.
	 *
	 * @return array<string, string>
	 */
	private function capability_map() {
		$map = $this->call_capabilities_method(
			array( 'for_post_type', 'capability_map', 'map', 'get_map', 'capabilities' )
		);

		$this->assertIsArray( $map, 'The capability map accessor must return an array.' );

		if ( isset( $map['capabilities'] ) && is_array( $map['capabilities'] ) ) {
			$map = $map['capabilities'];
		}

		return $map;
	}

	/**
	 * Calls the first static method on Popkit\Capabilities that exists.
	 *
	 * @param string[] $candidates Method names, most likely first.
	 * @return mixed
	 */
	private function call_capabilities_method( array $candidates ) {
		foreach ( $candidates as $method ) {
			if ( method_exists( Capabilities::class, $method ) ) {
				return call_user_func( array( Capabilities::class, $method ) );
			}
		}

		$this->fail(
			sprintf(
				'Popkit\Capabilities exposes none of the expected methods: %s. Update this list if the method was renamed.',
				implode( ', ', $candidates )
			)
		);
	}
}
