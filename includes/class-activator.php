<?php
/**
 * Activation, deactivation and self-healing upgrade routines.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the plugin lifecycle events that are not uninstall.
 *
 * Uninstall lives in `uninstall.php`, because it must run without the plugin
 * being loaded. Note the asymmetry required by `CLAUDE.md`: deactivation removes
 * nothing, uninstall removes infrastructure, and authored content is removed
 * only when the site owner has opted in.
 *
 * @since 0.1.0
 */
final class Activator {

	/**
	 * Option storing the version whose install routines have already run.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const VERSION_OPTION = 'popkit_version';

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Runs on plugin activation.
	 *
	 * Refuses to activate on an unsupported environment, then assigns
	 * capabilities, records the installed version and flushes rewrite rules.
	 * Every step is idempotent.
	 *
	 * The version option is written only when capability assignment actually
	 * landed. That option is the flag which suppresses the self-heal path in
	 * {@see Activator::maybe_upgrade()}, so recording it after a no-op assignment
	 * would produce a site with no popkit capabilities and no route back short of
	 * deactivating and reactivating the plugin. Leaving it unwritten costs one
	 * option read per admin request until the next attempt succeeds.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! Requirements::met() ) {
			self::halt();
		}

		if ( self::assign_capabilities() ) {
			update_option( self::VERSION_OPTION, POPKIT_VERSION );
		}

		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * Flushes rewrite rules and nothing else. Capabilities are deliberately left
	 * in place — a deactivation is routinely temporary, and stripping roles would
	 * silently lock authors out of content they still own. Uninstall removes them.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Re-runs install routines when the stored version differs from the current one.
	 *
	 * Hooked to `admin_init` by Plugin::boot(). This is the self-heal path
	 * required by `CLAUDE.md`: a site restored from a backup taken before
	 * activation, or updated by dropping files in over FTP, never fires the
	 * activation hook and would otherwise sit permanently without capabilities.
	 *
	 * Cheap on the common path — one option read, then an early return.
	 *
	 * Healing writes roles and an option, so it is restricted to a user who could
	 * have activated the plugin themselves. `admin_init` is not an authenticated
	 * context on its own: both admin-ajax.php and admin-post.php fire it for any
	 * request carrying a non-empty `action`, before dispatching to their `nopriv`
	 * handlers, which would otherwise hand an anonymous visitor a database write
	 * and make healing race between concurrent requests. Plugin::boot() keeps the
	 * hook off admin-ajax.php as well; this check is the half that also covers
	 * admin-post.php. Anyone who fails it simply gets no write, and the next
	 * administrator to load an admin screen heals the site.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$stored = (string) get_option( self::VERSION_OPTION, '' );

		if ( POPKIT_VERSION === $stored ) {
			return;
		}

		/*
		 * Healing is admin-screen work. This method is public and therefore
		 * callable directly, so the guard cannot live only at the hook site in
		 * Plugin::boot() — admin-post.php fires `admin_init` too, and any other
		 * caller bypasses that guard entirely.
		 *
		 * `is_admin()` is true on admin-ajax.php, so it does not exclude Ajax on
		 * its own; rewriting every role in the middle of an arbitrary Ajax
		 * handler, including a third party's `nopriv` one, is not something the
		 * caller asked for. Both halves are required.
		 */
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! self::assign_capabilities() ) {
			return;
		}

		update_option( self::VERSION_OPTION, POPKIT_VERSION );
	}

	/**
	 * Assigns role capabilities and reports whether the grant actually landed.
	 *
	 * The `class_exists()` guard this method used to carry has been removed
	 * deliberately. Popkit\Capabilities is a first-party class resolved by the
	 * same autoloader that already resolved this one, so it cannot be missing for
	 * load-order reasons — only because the install is broken. Swallowing that
	 * produced the worst outcome available: a silent no-op, followed by a version
	 * option asserting the install succeeded. A fatal at activation is loud,
	 * immediate and fixable; a capability-less site is neither.
	 *
	 * Capabilities::assign() returns void and skips roles that do not exist on
	 * the site, so the result is confirmed by reading the roles back rather than
	 * by assuming the call did something. Assignment is idempotent, so the
	 * upgrade path re-running it is harmless.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when every popkit-targeted role present on this site
	 *              holds its documented capabilities.
	 */
	private static function assign_capabilities(): bool {
		Capabilities::assign();

		return self::capabilities_present();
	}

	/**
	 * Whether the documented capabilities are present on the documented roles.
	 *
	 * A site whose `editor` role was removed by another plugin is not a failure:
	 * Capabilities::assign() skips absent roles by design, and the roles that do
	 * exist are still correct. A site where *no* targeted role exists is a
	 * failure, because nothing was granted at all — that is precisely the state
	 * the version option must not be allowed to declare finished.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	private static function capabilities_present(): bool {
		$present = false;

		foreach ( Capabilities::roles_map() as $role_name => $capabilities ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			foreach ( $capabilities as $capability ) {
				if ( ! $role->has_cap( $capability ) ) {
					return false;
				}
			}

			$present = true;
		}

		return $present;
	}

	/**
	 * Deactivates the plugin and stops activation with an explanatory message.
	 *
	 * @since 0.1.0
	 *
	 * @return never
	 */
	private static function halt(): never {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( POPKIT_BASENAME );

		wp_die(
			esc_html( implode( ' ', Requirements::failures() ) ),
			esc_html__( 'PopKit could not be activated', 'popkit' ),
			array(
				'back_link' => true,
				'response'  => 200,
			)
		);
	}
}
