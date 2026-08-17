<?php
/**
 * The popup custom post type.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `popkit_popup`, the post type every popup is stored in.
 *
 * The arguments are the table in `docs/data-model.md` -> Post type, expressed
 * once. Three of them are load bearing and are not preferences:
 *
 * - **`public => false`.** A popup is a fragment rendered into another page, not
 *   a page of its own. Making it public would give every popup a front-end URL,
 *   put unpublished offers in search results and in the sitemap, and invite
 *   visitors to read a modal outside the context it was written for.
 * - **`map_meta_cap => true` with the full map from {@see Capabilities::map()}.**
 *   `capability_type` alone grants nobody anything, and an incomplete map is the
 *   classic custom post type bug in which an author can edit a draft and then
 *   loses access to it the moment it is published. The map is reused rather than
 *   restated here so the declaration and the capabilities assigned on activation
 *   cannot drift apart.
 * - **`show_in_rest => true` with `custom-fields` support.** The block editor is
 *   the authoring surface and the Phase 5 sidebar reads and writes the
 *   `_popkit_*` meta over REST. Without `custom-fields` in `supports`, the meta
 *   registered by {@see Meta} is invisible to `/wp/v2/popkit_popup` however it is
 *   registered, and every editor panel silently fails to save.
 *
 * The post slug doubles as the public identifier used by `window.popkit.open()`
 * and the `?popkit=` deep link, which is why `rewrite` and `query_var` are off
 * but the slug itself still matters: nothing may claim a front-end route, yet the
 * name has to stay stable and human-readable.
 *
 * @since 0.1.0
 */
final class Post_Type {

	/**
	 * Post type key.
	 *
	 * Stored in `wp_posts.post_type`, so it is fixed for the life of the plugin.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const POST_TYPE = 'popkit_popup';

	/**
	 * Dashicon shown beside the admin menu entry.
	 *
	 * A dashicon rather than a bundled SVG or a data URI: it inherits the admin
	 * colour scheme, costs no request, and cannot fail a contrast check in a
	 * scheme popkit has never seen.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const MENU_ICON = 'dashicons-megaphone';

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Hooks post type registration onto `init`.
	 *
	 * Registration runs on `init` at the default priority, which is where
	 * WordPress expects a post type to appear: earlier and the taxonomies,
	 * capabilities and REST controllers it depends on are not ready, later and
	 * admin menus and the REST server have already been built.
	 *
	 * If `init` has somehow already fired by the time the plugin boots — a late
	 * `require`, a test harness loading the plugin by hand — registration happens
	 * immediately instead of never. {@see Meta::init()} follows the same rule and
	 * runs after this one either way.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( did_action( 'init' ) ) {
			self::register();

			return;
		}

		add_action( 'init', self::register( ... ) );
	}

	/**
	 * Registers the post type with WordPress.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_type( self::POST_TYPE, self::args() );
	}

	/**
	 * Arguments handed to `register_post_type()`.
	 *
	 * Exposed so the test suite can assert the declaration itself rather than
	 * asserting the object WordPress builds from it, and so a reviewer can read
	 * the whole contract in one place.
	 *
	 * `capability_type` is supplied alongside the explicit `capabilities` map even
	 * though the map covers every key core consults. It is what
	 * `get_post_type_capabilities()` falls back to, so a future key added by core
	 * resolves to a popkit capability rather than to the `post` capability of the
	 * same name — which would quietly judge a popup by whether the user can edit
	 * blog posts.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Arguments for `register_post_type()`.
	 */
	public static function args(): array {
		return array(
			'labels'              => self::labels(),
			'description'         => __( 'Accessible popups shown on the front end of this site.', 'popkit' ),

			/*
			 * A popup is a fragment of another page and never a page of its own.
			 * `public => false` already implies the three arguments below; they
			 * are written out because each is a separate promise, and a later
			 * change to one of them should be a deliberate, reviewable edit
			 * rather than a side effect of flipping `public`.
			 */
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_in_nav_menus'   => false,

			// Authored in the admin and in the block editor, which needs REST.
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'menu_icon'           => self::MENU_ICON,

			/*
			 * `custom-fields` is required for the `_popkit_*` meta to be exposed
			 * on the REST post object at all; without it the Phase 5 sidebar
			 * reads and writes nothing. `revisions` is what lets an author undo a
			 * bad edit to a live popup.
			 */
			'supports'            => array( 'title', 'editor', 'custom-fields', 'revisions' ),

			// See the class docblock. Both are required; neither works alone.
			'capability_type'     => Capabilities::CAPABILITY_TYPE,
			'map_meta_cap'        => true,
			'capabilities'        => Capabilities::map(),

			'hierarchical'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'can_export'          => true,

			/*
			 * Deleting a user must not delete the site's popups. They are site
			 * infrastructure that happens to have an author, and losing a live
			 * campaign because the person who wrote it left the organisation is
			 * the kind of silent content destruction `docs/CLAUDE.md` forbids.
			 */
			'delete_with_user'    => false,
		);
	}

	/**
	 * The complete label set.
	 *
	 * Every label WordPress defines is declared, including the ones this post
	 * type will rarely show. A missing label falls back to the wording for
	 * ordinary blog posts, so an author trashing a popup would be told "Post
	 * trashed." — and Plugin Check reports the omission.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Label name => translated label.
	 */
	public static function labels(): array {
		return array(
			'name'                     => __( 'Popups', 'popkit' ),
			'singular_name'            => __( 'Popup', 'popkit' ),
			'menu_name'                => __( 'Popups', 'popkit' ),
			'name_admin_bar'           => __( 'Popup', 'popkit' ),
			'add_new'                  => __( 'Add Popup', 'popkit' ),
			'add_new_item'             => __( 'Add New Popup', 'popkit' ),
			'edit_item'                => __( 'Edit Popup', 'popkit' ),
			'new_item'                 => __( 'New Popup', 'popkit' ),
			'view_item'                => __( 'View Popup', 'popkit' ),
			'view_items'               => __( 'View Popups', 'popkit' ),
			'search_items'             => __( 'Search Popups', 'popkit' ),
			'not_found'                => __( 'No popups found.', 'popkit' ),
			'not_found_in_trash'       => __( 'No popups found in Trash.', 'popkit' ),
			'parent_item_colon'        => __( 'Parent Popup:', 'popkit' ),
			'all_items'                => __( 'All Popups', 'popkit' ),
			'archives'                 => __( 'Popup Archives', 'popkit' ),
			'attributes'               => __( 'Popup Attributes', 'popkit' ),
			'insert_into_item'         => __( 'Insert into popup', 'popkit' ),
			'uploaded_to_this_item'    => __( 'Uploaded to this popup', 'popkit' ),
			'featured_image'           => __( 'Featured image', 'popkit' ),
			'set_featured_image'       => __( 'Set featured image', 'popkit' ),
			'remove_featured_image'    => __( 'Remove featured image', 'popkit' ),
			'use_featured_image'       => __( 'Use as featured image', 'popkit' ),
			'filter_items_list'        => __( 'Filter popups list', 'popkit' ),
			'filter_by_date'           => __( 'Filter by date', 'popkit' ),
			'items_list_navigation'    => __( 'Popups list navigation', 'popkit' ),
			'items_list'               => __( 'Popups list', 'popkit' ),
			'item_published'           => __( 'Popup published.', 'popkit' ),
			'item_published_privately' => __( 'Popup published privately.', 'popkit' ),
			'item_reverted_to_draft'   => __( 'Popup reverted to draft.', 'popkit' ),
			'item_trashed'             => __( 'Popup trashed.', 'popkit' ),
			'item_scheduled'           => __( 'Popup scheduled.', 'popkit' ),
			'item_updated'             => __( 'Popup updated.', 'popkit' ),
			'item_link'                => __( 'Popup Link', 'popkit' ),
			'item_link_description'    => __( 'A link to a popup.', 'popkit' ),
			'template_name'            => __( 'Single item: Popup', 'popkit' ),
		);
	}
}
