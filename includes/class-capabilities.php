<?php
/**
 * Capability names, the post type capability map, and role assignment.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for every popkit capability.
 *
 * `capability_type` on its own grants nobody anything. WordPress resolves meta
 * capabilities such as `edit_post` down to primitive capabilities that must
 * actually exist on a role, so this class owns both halves of that contract:
 * the map handed to `register_post_type()` and the primitives handed to
 * `WP_Role::add_cap()` on activation.
 *
 * Two rules govern everything below.
 *
 * 1. **Meta capabilities are never granted to a role.** `edit_popkit_popup`,
 *    `read_popkit_popup`, and `delete_popkit_popup` are resolved per object by
 *    `map_meta_cap()` at check time. Storing one on a role would short-circuit
 *    that resolution and grant blanket access to every popup regardless of
 *    authorship or post status. Only {@see Capabilities::primitives()} is ever
 *    written to a role.
 * 2. **The five primitives consulted inside `map_meta_cap()` are mandatory.**
 *    Once a popup is published, `edit_post` routes through
 *    `edit_published_popkit_popups`; once private, through
 *    `edit_private_popkit_popups`; the delete equivalents behave the same way.
 *    Granting only `edit_popkit_popups` produces the classic custom post type
 *    bug where an author can create and edit a draft, then loses access to it
 *    permanently the moment they publish it. Draft-only tests pass clean
 *    against that bug, which is why the Phase 0 matrix covers the full
 *    lifecycle.
 *
 * @since 0.1.0
 */
final class Capabilities {

	/**
	 * Singular and plural capability type for `register_post_type()`.
	 *
	 * Passed as `capability_type`, alongside `map_meta_cap => true` and the
	 * explicit map returned by {@see Capabilities::map()}.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const CAPABILITY_TYPE = array( 'popkit_popup', 'popkit_popups' );

	/**
	 * Full capability map for the `capabilities` argument of `register_post_type()`.
	 *
	 * Every key must be present. A missing or mistyped key silently falls back
	 * to core's default, which grants the corresponding `post` capability — an
	 * editor would then be judged against their ability to edit blog posts
	 * rather than popups, and the failure is invisible until a real permission
	 * check runs against a published popup.
	 *
	 * `create_posts` deliberately maps to `edit_popkit_popups` rather than to a
	 * separate primitive, matching core's default. Splitting it would let a role
	 * edit existing popups but not create them, which is not a distinction this
	 * plugin needs.
	 *
	 * This map must be used with `map_meta_cap => true`. Without it the three
	 * meta capabilities are never resolved per object, the `auth_callback` on
	 * every registered meta key misbehaves, and the final five primitives are
	 * never consulted at all.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Core capability key => popkit capability name.
	 */
	public static function map(): array {
		return array(
			// Meta capabilities — resolved per object by map_meta_cap().
			// These are never granted to a role. See the class docblock.
			'edit_post'              => 'edit_popkit_popup',
			'read_post'              => 'read_popkit_popup',
			'delete_post'            => 'delete_popkit_popup',

			// Primitive capabilities used outside map_meta_cap().
			'edit_posts'             => 'edit_popkit_popups',
			'edit_others_posts'      => 'edit_others_popkit_popups',
			'publish_posts'          => 'publish_popkit_popups',
			'read_private_posts'     => 'read_private_popkit_popups',
			'delete_posts'           => 'delete_popkit_popups',
			'create_posts'           => 'edit_popkit_popups',

			// Primitive capabilities used *within* map_meta_cap().
			// Omitting any of these is the classic CPT capability bug.
			'edit_private_posts'     => 'edit_private_popkit_popups',
			'edit_published_posts'   => 'edit_published_popkit_popups',
			'delete_private_posts'   => 'delete_private_popkit_popups',
			'delete_published_posts' => 'delete_published_popkit_popups',
			'delete_others_posts'    => 'delete_others_popkit_popups',
		);
	}

	/**
	 * Meta capability names.
	 *
	 * Listed so that tests can assert none of them ever appears on a role, and
	 * so that {@see Capabilities::remove()} can clean up a site where one was
	 * granted by mistake. Nothing in this plugin grants them.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Meta capability names.
	 */
	public static function meta_capabilities(): array {
		return array(
			'edit_popkit_popup',
			'read_popkit_popup',
			'delete_popkit_popup',
		);
	}

	/**
	 * Primitive capability names, deduplicated.
	 *
	 * These are the only capabilities ever written to a role. `create_posts`
	 * and `edit_posts` both map to `edit_popkit_popups`, so the distinct set is
	 * shorter than the map returned by {@see Capabilities::map()}.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Primitive capability names.
	 */
	public static function primitives(): array {
		return array(
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
	}

	/**
	 * Every capability name this plugin owns, meta and primitive.
	 *
	 * Used by uninstall to guarantee that no popkit capability of any kind
	 * survives on any role.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] All popkit capability names.
	 */
	public static function all_capabilities(): array {
		return array_merge( self::meta_capabilities(), self::primitives() );
	}

	/**
	 * Roles that receive capabilities, and which primitives each receives.
	 *
	 * One source of truth shared by activation, uninstall, and the test suite.
	 * Roles absent from this map receive nothing — subscribers, contributors,
	 * and authors have no access to popups.
	 *
	 * `editor` receives every primitive except `delete_others_popkit_popups`:
	 * an editor may edit another author's popup but may not delete it. That
	 * list is written out literally rather than derived from
	 * {@see Capabilities::primitives()} so that the granted set is greppable
	 * and so that adding a primitive is a deliberate decision for each role
	 * rather than an automatic grant. The Phase 0 suite asserts it equals the
	 * full primitive set minus that one name.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string[]> Role slug => primitive capability names.
	 */
	public static function roles_map(): array {
		return array(
			'administrator' => self::primitives(),
			'editor'        => array(
				'edit_popkit_popups',
				'edit_others_popkit_popups',
				'publish_popkit_popups',
				'read_private_popkit_popups',
				'delete_popkit_popups',
				'edit_private_popkit_popups',
				'edit_published_popkit_popups',
				'delete_private_popkit_popups',
				'delete_published_popkit_popups',
			),
		);
	}

	/**
	 * Grant the documented primitives to the documented roles.
	 *
	 * Runs on activation and again on any version change, so a site restored
	 * from a backup taken before activation heals itself. Idempotent: granting
	 * a capability a role already holds is a no-op, and the roles option is
	 * only written when its value actually changes.
	 *
	 * Deliberately additive. It never revokes a popkit capability, because a
	 * site owner may have granted popkit primitives to a custom role of their
	 * own; silently stripping those on every plugin update would be hostile and
	 * hard to diagnose. Revocation belongs to {@see Capabilities::remove()},
	 * which runs only on uninstall.
	 *
	 * A role missing from the site — `editor` can be removed by other plugins —
	 * is skipped rather than recreated.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function assign(): void {
		foreach ( self::roles_map() as $role_name => $capabilities ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			foreach ( $capabilities as $capability ) {
				$role->add_cap( $capability, true );
			}
		}
	}

	/**
	 * Strip every popkit capability from every role on the site.
	 *
	 * Called from uninstall. Iterates all registered roles rather than only the
	 * two in {@see Capabilities::roles_map()}, so capabilities granted to a
	 * custom role by a site owner or by a membership plugin are also cleaned
	 * up. Meta capability names are included defensively: nothing here grants
	 * them, but a site that acquired one elsewhere should not be left with an
	 * orphaned popkit capability after the plugin is gone.
	 *
	 * Removing a capability a role does not hold is a no-op, so this is safe to
	 * run repeatedly.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function remove(): void {
		$capabilities = self::all_capabilities();
		$role_names   = array_keys( wp_roles()->get_names() );

		foreach ( $role_names as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			foreach ( $capabilities as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}
}
