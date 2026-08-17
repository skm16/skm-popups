<?php
/**
 * Uninstall routine for popkit.
 *
 * Runs when the site owner deletes the plugin from the Plugins screen. The
 * plugin itself is NOT loaded at this point — no autoloader, no constants, no
 * classes — so this file depends on nothing but WordPress core, which is fully
 * loaded by the time core includes it.
 *
 * Policy, per docs/CLAUDE.md -> Uninstall:
 *
 * - Plugin INFRASTRUCTURE is always removed: options, transients, scheduled
 *   events and capabilities.
 * - AUTHORED CONTENT — popkit_popup posts and their meta — is removed only when
 *   the site owner explicitly opted in. The opt-in defaults to false, and the
 *   removal it authorizes is irreversible.
 *
 * On multisite every site is cleaned individually, and the opt-in is read per
 * site, because options are per site. A network where one site opted in must
 * not lose the other sites' popups.
 *
 * @package Popkit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * The capability names granted by the plugin.
 *
 * Deliberately inlined rather than read from Popkit\Capabilities: requiring a
 * class file from a plugin that is mid-deletion risks a fatal (a missing parent,
 * interface or trait aborts the require), and a fatal here leaves the site with
 * a half-uninstalled plugin. The list is fixed by docs/CLAUDE.md, and
 * popkit_uninstall_is_popkit_capability() catches anything added later.
 *
 * @return string[] Capability names.
 */
function popkit_uninstall_capabilities(): array {
	return array(
		// Meta capabilities, resolved per object by map_meta_cap().
		'edit_popkit_popup',
		'read_popkit_popup',
		'delete_popkit_popup',

		// Primitive capabilities used outside map_meta_cap().
		'edit_popkit_popups',
		'edit_others_popkit_popups',
		'publish_popkit_popups',
		'read_private_popkit_popups',
		'delete_popkit_popups',

		// Primitive capabilities used within map_meta_cap().
		'edit_private_popkit_popups',
		'edit_published_popkit_popups',
		'delete_private_popkit_popups',
		'delete_published_popkit_popups',
		'delete_others_popkit_popups',
	);
}

/**
 * Whether a capability name belongs to popkit.
 *
 * Matches the plugin's naming patterns only, so a capability belonging to some
 * other plugin can never be removed by this uninstall.
 *
 * @param string $capability Capability name.
 * @return bool
 */
function popkit_uninstall_is_popkit_capability( string $capability ): bool {
	return str_starts_with( $capability, 'popkit_' )
		|| str_ends_with( $capability, '_popkit_popup' )
		|| str_ends_with( $capability, '_popkit_popups' );
}

/**
 * Whether the site owner opted in to deleting authored content.
 *
 * Mirrors Popkit\Settings::delete_data_on_uninstall(), which cannot be called
 * here because the plugin is not loaded. Both read the settings array first and
 * fall back to the standalone option documented in docs/CLAUDE.md only when the
 * settings array has never been written — so once the setting has been saved
 * through the plugin, what the admin screen showed is exactly what happens here.
 *
 * Anything unrecognized coerces to false. The default is "keep the content".
 *
 * @return bool
 */
function popkit_uninstall_should_delete_content(): bool {
	// An explicit sentinel tells "option absent" apart from "option stored".
	// Popkit\Settings uses the same value for the same reason.
	$sentinel = '__popkit_settings_unset__';
	$settings = get_option( 'popkit_settings', $sentinel );

	if ( is_array( $settings ) ) {
		if ( ! array_key_exists( 'delete_data_on_uninstall', $settings ) ) {
			return false;
		}

		return (bool) filter_var( $settings['delete_data_on_uninstall'], FILTER_VALIDATE_BOOLEAN );
	}

	if ( $sentinel !== $settings ) {
		// Stored, but not as an array. Corrupt value, so assume no consent.
		return false;
	}

	return (bool) filter_var( get_option( 'popkit_delete_data_on_uninstall', false ), FILTER_VALIDATE_BOOLEAN );
}

/**
 * Permanently deletes every popkit_popup post and its meta on the current site.
 *
 * Only ever called when popkit_uninstall_should_delete_content() is true.
 *
 * Posts are found with a direct query rather than get_posts(), because the post
 * type is not registered during uninstall and because `post_status => 'any'`
 * excludes trashed and auto-draft posts — exactly the rows that must not be left
 * behind. wp_delete_post( $id, true ) is then used per post so meta, revisions,
 * term relationships and caches are cleaned by core.
 *
 * @return void
 */
function popkit_uninstall_delete_popups(): void {
	global $wpdb;

	$batch   = 200;
	$last_id = 0;

	do {
		// Direct query: see the function docblock. Uncached by design — these
		// rows are being deleted, and caching them would be pointless work.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
				'popkit_popup',
				$last_id,
				$batch
			)
		);

		if ( empty( $post_ids ) ) {
			return;
		}

		// Counted once, here, rather than in the loop condition: the batch size is
		// fixed for the life of the loop and re-counting per iteration is wasted
		// work (Squiz.PHP.DisallowSizeFunctionsInLoops).
		$found = count( (array) $post_ids );

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			// The cursor advances whether or not the delete succeeded, so a post
			// that refuses to delete cannot spin this loop forever.
			$last_id = $post_id;

			wp_delete_post( $post_id, true );
		}
	} while ( $found === $batch );
}

/**
 * Deletes the plugin's options on the current site.
 *
 * @return void
 */
function popkit_uninstall_delete_options(): void {
	global $wpdb;

	// Direct query: core offers no API for enumerating options by prefix, and the
	// plugin's own list of option names is not loaded during uninstall. Every row
	// found is removed through delete_option() so the alloptions cache and the
	// delete_option hooks stay correct.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'popkit_' ) . '%'
		)
	);

	foreach ( (array) $option_names as $option_name ) {
		delete_option( $option_name );
	}

	// Belt and braces: named explicitly so the two options that matter are
	// removed even if the sweep above returns nothing (an object-cache-backed
	// options implementation, for instance).
	delete_option( 'popkit_settings' );
	delete_option( 'popkit_delete_data_on_uninstall' );
}

/**
 * Deletes the plugin's transients on the current site.
 *
 * Transients held in a persistent object cache cannot be enumerated by prefix;
 * those are left to expire on their own, which they do without the plugin's
 * help. Everything in the options table is removed here, including orphaned
 * timeout rows.
 *
 * @return void
 */
function popkit_uninstall_delete_transients(): void {
	global $wpdb;

	// Direct query: there is no core API for listing transients by prefix. Each
	// name found is removed through delete_transient() / delete_option() so the
	// object cache is invalidated too.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_popkit_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_popkit_' ) . '%',
			$wpdb->esc_like( '_site_transient_popkit_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_popkit_' ) . '%'
		)
	);

	foreach ( (array) $option_names as $option_name ) {
		if ( str_starts_with( $option_name, '_transient_timeout_' ) || str_starts_with( $option_name, '_site_transient_timeout_' ) ) {
			delete_option( $option_name );
			continue;
		}

		if ( str_starts_with( $option_name, '_site_transient_' ) ) {
			delete_site_transient( substr( $option_name, strlen( '_site_transient_' ) ) );
			continue;
		}

		delete_transient( substr( $option_name, strlen( '_transient_' ) ) );
	}
}

/**
 * Unschedules any cron event registered under the plugin's prefix.
 *
 * @return void
 */
function popkit_uninstall_clear_scheduled_events(): void {
	$cron = _get_cron_array();

	if ( ! is_array( $cron ) ) {
		return;
	}

	foreach ( $cron as $events ) {
		if ( ! is_array( $events ) ) {
			continue;
		}

		foreach ( array_keys( $events ) as $hook ) {
			if ( is_string( $hook ) && str_starts_with( $hook, 'popkit_' ) ) {
				wp_unschedule_hook( $hook );
			}
		}
	}
}

/**
 * Removes every popkit capability from every role on the current site.
 *
 * @return void
 */
function popkit_uninstall_remove_capabilities(): void {
	if ( ! function_exists( 'wp_roles' ) ) {
		return;
	}

	$roles = wp_roles();

	// switch_to_blog() only re-points WP_Roles when `init` has fired. Uninstall
	// runs late in an admin request so it always has, but being explicit costs
	// nothing and makes the multisite path obviously correct.
	if ( method_exists( $roles, 'for_site' ) ) {
		$roles->for_site( get_current_blog_id() );
	}

	$known = popkit_uninstall_capabilities();

	foreach ( array_keys( (array) $roles->roles ) as $role_name ) {
		$role = $roles->get_role( $role_name );

		if ( ! $role instanceof WP_Role ) {
			continue;
		}

		$remove = array();

		// Only capabilities the role actually holds are removed: WP_Role::remove_cap()
		// writes the roles option on every call, so filtering first turns dozens
		// of writes into at most one per role.
		foreach ( array_keys( (array) $role->capabilities ) as $capability ) {
			$capability = (string) $capability;

			if ( in_array( $capability, $known, true ) || popkit_uninstall_is_popkit_capability( $capability ) ) {
				$remove[] = $capability;
			}
		}

		foreach ( $remove as $capability ) {
			$role->remove_cap( $capability );
		}
	}
}

/**
 * Runs the full cleanup for the current site.
 *
 * @return void
 */
function popkit_uninstall_site(): void {
	// Read the opt-in before any option is deleted.
	if ( popkit_uninstall_should_delete_content() ) {
		popkit_uninstall_delete_popups();
	}

	popkit_uninstall_delete_options();
	popkit_uninstall_delete_transients();
	popkit_uninstall_clear_scheduled_events();
	popkit_uninstall_remove_capabilities();
}

/**
 * Removes network level options and site transients on multisite.
 *
 * Authored content is never network level, so nothing here is gated on the
 * opt-in.
 *
 * @return void
 */
function popkit_uninstall_network(): void {
	global $wpdb;

	if ( ! is_multisite() ) {
		return;
	}

	// Direct query: no core API enumerates network options by prefix. Each key is
	// removed through the network options API so caches are invalidated.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$meta_keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE site_id = %d AND ( meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s )",
			get_current_network_id(),
			$wpdb->esc_like( 'popkit_' ) . '%',
			$wpdb->esc_like( '_site_transient_popkit_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_popkit_' ) . '%'
		)
	);

	foreach ( (array) $meta_keys as $meta_key ) {
		if ( str_starts_with( $meta_key, '_site_transient_timeout_' ) ) {
			delete_site_option( $meta_key );
			continue;
		}

		if ( str_starts_with( $meta_key, '_site_transient_' ) ) {
			delete_site_transient( substr( $meta_key, strlen( '_site_transient_' ) ) );
			continue;
		}

		delete_site_option( $meta_key );
	}
}

/**
 * Entry point: cleans every site, then the network.
 *
 * @return void
 */
function popkit_uninstall_run(): void {
	if ( ! is_multisite() ) {
		popkit_uninstall_site();

		return;
	}

	$batch  = 100;
	$offset = 0;

	do {
		$site_ids = get_sites(
			array(
				'fields'                 => 'ids',
				'number'                 => $batch,
				'offset'                 => $offset,
				'orderby'                => 'id',
				'order'                  => 'ASC',
				'update_site_cache'      => false,
				'update_site_meta_cache' => false,
			)
		);

		// Counted once, here, rather than in the loop condition: the batch size is
		// fixed for the life of the loop and re-counting per iteration is wasted
		// work (Squiz.PHP.DisallowSizeFunctionsInLoops).
		$found = count( (array) $site_ids );

		foreach ( (array) $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			popkit_uninstall_site();
			restore_current_blog();
		}

		$offset += $batch;
	} while ( $found === $batch );

	popkit_uninstall_network();
}

popkit_uninstall_run();
