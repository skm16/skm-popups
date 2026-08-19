<?php
/**
 * Integration tests for the activation, deactivation and self-heal lifecycle.
 *
 * `tests/php/integration/test-capabilities.php` asserts that
 * `Popkit\Capabilities::assign()` is idempotent. That is one layer below the
 * exit criterion in `docs/CLAUDE.md`, which is about *activation*: "Capability
 * assignment is idempotent and re-runs on version upgrade — a site restored from
 * a backup taken before activation must self-heal." An `Activator::activate()`
 * that flushed and rebuilt roles, or that wrote the version option before the
 * capabilities landed, would leave `Capabilities::assign()` perfectly idempotent
 * and the plugin still broken. So the whole entry point is exercised here.
 *
 * The security half matters just as much. `Activator::maybe_upgrade()` is hooked
 * to `admin_init`, and `admin_init` runs for *every* request that reaches
 * wp-admin — including `admin-post.php` and `admin-ajax.php`, both of which are
 * reachable while logged out. An unguarded self-heal is therefore an anonymous
 * write primitive: any visitor could force a role rewrite and an option write by
 * requesting a URL. The guards are asserted below the same way an attacker would
 * probe them — from the outside, by checking that nothing changed.
 *
 * @package Popkit
 */

use Popkit\Activator;

/**
 * Integration coverage for Popkit\Activator.
 */
final class Test_Popkit_Activator extends WP_UnitTestCase {

	/**
	 * Option recording the version whose install routines have run.
	 *
	 * The literal name is used rather than `Activator::VERSION_OPTION` because
	 * the option name is data, not an implementation detail: uninstall sweeps it
	 * by prefix and site owners inspect it with WP-CLI. Renaming it is a
	 * breaking change and should fail here.
	 *
	 * @var string
	 */
	private const VERSION_OPTION = 'popkit_version';

	/**
	 * A version older than any the plugin has shipped.
	 *
	 * @var string
	 */
	private const STALE_VERSION = '0.0.1';

	/**
	 * The capability stripped from `editor` to make a self-heal observable.
	 *
	 * A lifecycle primitive on purpose. Its absence is exactly the failure that
	 * draft-only coverage cannot see: an editor who publishes a popup and loses
	 * access to it.
	 *
	 * @var string
	 */
	private const PROBE_CAPABILITY = 'edit_published_popkit_popups';

	/**
	 * The distinct primitives activation grants, pinned rather than derived.
	 *
	 * Reading them back out of `Popkit\Capabilities` would make a wrong map
	 * produce a matching wrong expectation.
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
	 * The one primitive an editor must never hold.
	 *
	 * @var string
	 */
	private const EDITOR_DENIED_CAPABILITY = 'delete_others_popkit_popups';

	/**
	 * Admin screen in place before the test ran, restored afterwards.
	 *
	 * @var WP_Screen|null
	 */
	private $original_screen = null;

	/**
	 * Re-reads roles from the database and records the current screen.
	 *
	 * The role option is rolled back between tests but the `WP_Roles` global is
	 * not, and these tests deliberately strip capabilities from it.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_roles()->for_site();

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
	 * Activating twice leaves every role byte-identical.
	 *
	 * The documented exit criterion, asserted at the activation entry point
	 * rather than at `Capabilities::assign()`. Two independent comparisons run:
	 * the in-memory capability set of every role on the site, and the stored
	 * roles option itself, so a routine that reaches the same set by removing
	 * and re-adding capabilities is caught by the second even if the first
	 * agrees.
	 *
	 * The set assertions afterwards exist so this cannot pass by assigning
	 * nothing at all, twice.
	 *
	 * @return void
	 */
	public function test_activating_twice_leaves_every_role_identical() {
		Activator::activate();

		$first_roles  = $this->capability_snapshot();
		$first_option = wp_json_encode( $this->roles_option() );

		Activator::activate();

		$second_roles  = $this->capability_snapshot();
		$second_option = wp_json_encode( $this->roles_option() );

		$this->assertSame(
			$first_roles,
			$second_roles,
			'A second activation changed the capabilities held by at least one role. Activation runs again on every reactivation and on every self-heal, so it has to be a no-op once the capabilities are in place.'
		);
		$this->assertSame(
			$first_option,
			$second_option,
			'A second activation rewrote the stored roles option. The capability sets may still match, which is exactly how a routine that strips and re-adds capabilities hides: any role a site owner customized is silently rebuilt on every plugin update.'
		);

		$this->assertSame(
			$this->sorted( self::PRIMITIVES ),
			$this->popkit_capabilities_of( 'administrator' ),
			'Administrators must hold all ten primitives after activation.'
		);
		$this->assertSame(
			$this->sorted( array_diff( self::PRIMITIVES, array( self::EDITOR_DENIED_CAPABILITY ) ) ),
			$this->popkit_capabilities_of( 'editor' ),
			'Editors must hold every primitive except delete_others_popkit_popups after activation.'
		);
		$this->assertSame(
			array(),
			$this->popkit_capabilities_of( 'subscriber' ),
			'Subscribers must hold no popkit capability after activation.'
		);
	}

	/**
	 * Activation records the running version.
	 *
	 * Asserted from a state with no option row at all, so an implementation that
	 * merely leaves an existing row alone cannot pass.
	 *
	 * @return void
	 */
	public function test_activation_records_the_current_version() {
		delete_option( self::VERSION_OPTION );

		$this->assertFalse(
			get_option( self::VERSION_OPTION ),
			'Fixture: the version option must be absent before activation runs.'
		);

		Activator::activate();

		$this->assertSame(
			POPKIT_VERSION,
			get_option( self::VERSION_OPTION ),
			'Activation must record POPKIT_VERSION. The self-heal path compares against this value, so an unwritten or stale option makes every subsequent admin request re-run the install routines.'
		);
	}

	/**
	 * Deactivation removes nothing.
	 *
	 * `docs/CLAUDE.md`: "Deactivation does not remove capabilities. Uninstall
	 * does." A deactivation is routinely temporary — a plugin conflict being
	 * bisected, a staging site being cloned — and stripping roles would lock
	 * authors out of content they still own.
	 *
	 * @return void
	 */
	public function test_deactivation_removes_nothing() {
		Activator::activate();

		$before = $this->capability_snapshot();

		Activator::deactivate();

		// Re-read from the database: a deactivation routine that wrote the roles
		// option directly would otherwise be invisible to the in-memory global.
		wp_roles()->for_site();

		$this->assertSame(
			$before,
			$this->capability_snapshot(),
			'Deactivation changed the capabilities held by at least one role. Deactivation is temporary and must remove nothing; uninstall is where capabilities go.'
		);

		foreach ( self::PRIMITIVES as $capability ) {
			$this->assertTrue(
				get_role( 'administrator' )->has_cap( $capability ),
				sprintf( 'Deactivation revoked "%s" from the administrator role.', $capability )
			);
		}

		$this->assertSame(
			POPKIT_VERSION,
			get_option( self::VERSION_OPTION ),
			'Deactivation deleted the version option. Nothing is removed on deactivation, and clearing it would make the next admin request re-run the install routines for no reason.'
		);
	}

	/**
	 * The self-heal restores a capability an administrator lost.
	 *
	 * The scenario is a site restored from a backup taken before activation, or
	 * updated by dropping files in over FTP: the activation hook never fires, so
	 * the version option is stale and the roles are wrong. One admin request as
	 * a user who may activate plugins has to put both right.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_heals_a_stale_install_for_an_authorized_administrator() {
		$expected = $this->stale_install();

		$this->enter_admin();
		wp_set_current_user( $this->privileged_user() );

		$this->assertTrue(
			current_user_can( 'activate_plugins' ),
			'Fixture: the user driving the self-heal must be allowed to activate plugins.'
		);

		Activator::maybe_upgrade();

		$this->assertContains(
			self::PROBE_CAPABILITY,
			$this->popkit_capabilities_of( 'editor' ),
			sprintf( 'The self-heal did not restore "%s" to the editor role.', self::PROBE_CAPABILITY )
		);
		$this->assertSame(
			$expected,
			$this->popkit_capabilities_of( 'editor' ),
			'The self-heal did not restore the editor role to its documented capability set.'
		);
		$this->assertSame(
			POPKIT_VERSION,
			get_option( self::VERSION_OPTION ),
			'The self-heal must record the running version once the install routines have succeeded, or it re-runs on every single admin request.'
		);
	}

	/**
	 * A logged-out visitor cannot trigger the self-heal.
	 *
	 * `admin_init` fires for anonymous requests to `admin-post.php` and
	 * `admin-ajax.php`. Without a capability check this hook is an unauthenticated
	 * write primitive: a role rewrite and an option write, triggered by fetching
	 * a URL.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_writes_nothing_for_a_logged_out_visitor() {
		$this->stale_install();

		$this->enter_admin();
		wp_set_current_user( 0 );

		$this->assertFalse(
			current_user_can( 'activate_plugins' ),
			'Fixture: a logged-out visitor must not be allowed to activate plugins.'
		);

		Activator::maybe_upgrade();

		$this->assert_install_untouched( 'a logged-out visitor reached admin_init' );
	}

	/**
	 * A subscriber cannot trigger the self-heal.
	 *
	 * The logged-in half of the same hole. A subscriber can legitimately reach
	 * wp-admin — the profile screen — so `is_user_logged_in()` is not a guard.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_writes_nothing_for_a_subscriber() {
		$this->stale_install();

		$this->enter_admin();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertFalse(
			current_user_can( 'activate_plugins' ),
			'Fixture: a subscriber must not be allowed to activate plugins.'
		);

		Activator::maybe_upgrade();

		$this->assert_install_untouched( 'a subscriber reached admin_init' );
	}

	/**
	 * The self-heal does not run on the front end.
	 *
	 * Rewriting roles and writing an option is not front-end work, and a front-end
	 * request is the one place the write cannot be attributed to anybody.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_writes_nothing_on_the_front_end() {
		$this->stale_install();

		$this->enter_front_end();
		wp_set_current_user( $this->privileged_user() );

		Activator::maybe_upgrade();

		$this->assert_install_untouched( 'the request was a front end request' );
	}

	/**
	 * The self-heal does not run during an Ajax request.
	 *
	 * `admin-ajax.php` sets up the admin context, so `is_admin()` alone does not
	 * exclude it. Rewriting every role in the middle of an arbitrary Ajax handler
	 * — including a third party's, including one reachable with `nopriv` — is not
	 * something the caller asked for.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_writes_nothing_during_an_ajax_request() {
		$this->stale_install();

		$this->enter_admin();
		wp_set_current_user( $this->privileged_user() );

		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->assertTrue( wp_doing_ajax(), 'Fixture: the request must look like an Ajax request.' );

		Activator::maybe_upgrade();

		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->assert_install_untouched( 'the request was an Ajax request' );
	}

	/**
	 * Puts the site into the state a stale install is in.
	 *
	 * Activates, then removes one capability from `editor` and rolls the version
	 * option back, so that any self-heal is observable and any refusal to heal is
	 * equally observable.
	 *
	 * @return string[] The editor's popkit capabilities as activation left them.
	 */
	private function stale_install() {
		Activator::activate();

		$granted = $this->popkit_capabilities_of( 'editor' );

		$this->assertContains(
			self::PROBE_CAPABILITY,
			$granted,
			sprintf( 'Fixture: activation must grant "%s" to the editor role before it can be stripped.', self::PROBE_CAPABILITY )
		);

		get_role( 'editor' )->remove_cap( self::PROBE_CAPABILITY );

		$this->assertNotContains(
			self::PROBE_CAPABILITY,
			$this->popkit_capabilities_of( 'editor' ),
			'Fixture: the probe capability must be missing before the self-heal is invoked.'
		);

		update_option( self::VERSION_OPTION, self::STALE_VERSION );

		return $granted;
	}

	/**
	 * Asserts that the self-heal changed nothing at all.
	 *
	 * @param string $reason Why the write was expected to be refused.
	 * @return void
	 */
	private function assert_install_untouched( $reason ) {
		$this->assertSame(
			self::STALE_VERSION,
			get_option( self::VERSION_OPTION ),
			sprintf( 'maybe_upgrade() wrote the version option although %s.', $reason )
		);
		$this->assertNotContains(
			self::PROBE_CAPABILITY,
			$this->popkit_capabilities_of( 'editor' ),
			sprintf( 'maybe_upgrade() rewrote role capabilities although %s.', $reason )
		);
	}

	/**
	 * Creates a user who is allowed to activate plugins.
	 *
	 * On multisite the `activate_plugins` capability is mapped to
	 * `manage_network_plugins` unless the network has opened the plugins menu to
	 * site administrators, so the fixture is promoted rather than assumed.
	 *
	 * @return int User ID.
	 */
	private function privileged_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() && function_exists( 'grant_super_admin' ) ) {
			grant_super_admin( $user_id );
		}

		return $user_id;
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

	/**
	 * Puts the request on the front end.
	 *
	 * @return void
	 */
	private function enter_front_end() {
		unset( $GLOBALS['current_screen'] );

		$this->assertFalse( is_admin(), 'Fixture: the request must be a front end request.' );
	}

	/**
	 * Returns the popkit capabilities a role currently holds, sorted.
	 *
	 * @param string $role_name Role slug.
	 * @return string[]
	 */
	private function popkit_capabilities_of( $role_name ) {
		$role = get_role( $role_name );

		if ( ! $role instanceof WP_Role ) {
			return array();
		}

		$capabilities = array();

		foreach ( $role->capabilities as $capability => $granted ) {
			if ( ! $granted || false === strpos( $capability, 'popkit_popup' ) ) {
				continue;
			}

			$capabilities[] = $capability;
		}

		return $this->sorted( $capabilities );
	}

	/**
	 * Snapshots every capability of every role on the site.
	 *
	 * Core capabilities are included deliberately: an activation routine that
	 * rebuilt roles rather than adding to them would leave the popkit half
	 * correct and quietly drop everything else.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function capability_snapshot() {
		$snapshot = array();

		foreach ( array_keys( wp_roles()->get_names() ) as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof WP_Role ) {
				continue;
			}

			$capabilities = (array) $role->capabilities;
			ksort( $capabilities );

			$snapshot[ $role_name ] = $capabilities;
		}

		ksort( $snapshot );

		return $snapshot;
	}

	/**
	 * Reads the stored roles option exactly as WordPress persists it.
	 *
	 * @return array<string, mixed>
	 */
	private function roles_option() {
		return (array) get_option( wp_roles()->role_key, array() );
	}

	/**
	 * Returns a re-indexed, sorted copy of a list.
	 *
	 * @param array<int|string, string> $values Capability names.
	 * @return string[]
	 */
	private function sorted( array $values ) {
		$values = array_values( $values );

		sort( $values );

		return $values;
	}
}
