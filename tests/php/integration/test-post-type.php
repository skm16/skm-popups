<?php
/**
 * Integration tests for the popkit_popup post type.
 *
 * The declaration in `Popkit\Post_Type` is asserted here against the post type
 * WordPress actually built from it, because several of its arguments are only
 * resolved at registration time and a wrong one fails silently rather than
 * loudly:
 *
 * - `public => false` is what keeps a popup from acquiring a front-end URL of
 *   its own, appearing in search results, and being read outside the page it was
 *   written for. Flipping it produces no error anywhere.
 * - `show_in_rest` plus `custom-fields` is what exposes the `_popkit_*` meta on
 *   the REST post object. Without either one the Phase 5 sidebar reads and
 *   writes nothing, and no message says so.
 * - `map_meta_cap => true` with the full map is what makes `edit_post` resolve
 *   per object. Without it the `auth_callback` on every meta key misbehaves and
 *   the primitives consulted inside `map_meta_cap()` are never reached.
 *
 * The capability expectations are read from {@see Popkit\Capabilities::map()}
 * rather than restated, because the deep allow/deny matrix already lives in
 * `tests/php/integration/test-capabilities.php`. What is asserted here is that
 * the post type carries that map — not what the map contains, and not what each
 * role may do with it.
 *
 * @package Popkit
 */

use Popkit\Capabilities;
use Popkit\Post_Type;

/**
 * Integration coverage for Popkit\Post_Type.
 */
final class Test_Popkit_Post_Type extends WP_UnitTestCase {

	/**
	 * Post type key, pinned literally.
	 *
	 * It is written into `wp_posts.post_type`, so it is fixed for the life of the
	 * plugin: a rename is a data migration, not a refactor, and this constant is
	 * what makes an accidental one fail.
	 *
	 * @var string
	 */
	private const POST_TYPE = 'popkit_popup';

	/**
	 * The four features `docs/data-model.md` requires the post type to support.
	 *
	 * Pinned rather than read from the declaration, so a feature quietly dropped
	 * from `Post_Type::args()` fails here instead of agreeing with itself.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_FEATURES = array( 'title', 'editor', 'custom-fields', 'revisions' );

	/**
	 * The singular and plural capability type, pinned literally.
	 *
	 * Pinned for the same reason as the two constants above, and unlike the
	 * capability map it has nowhere else to be pinned:
	 * `Popkit\Capabilities::map()` writes its capability names out by hand rather
	 * than deriving them from this pair, so nothing else in the suite reads it.
	 * Comparing the declaration against `Capabilities::CAPABILITY_TYPE` alone
	 * would let the pair be changed to anything at all — `post` and `posts`
	 * included — and still agree with itself.
	 *
	 * @var string[]
	 */
	private const CAPABILITY_TYPE = array( 'popkit_popup', 'popkit_popups' );

	/**
	 * Fixture user IDs, keyed by role slug.
	 *
	 * @var array<string, int>
	 */
	private $users = array();

	/**
	 * Grants the plugin's capabilities and creates one user per role.
	 *
	 * The role option is rolled back between tests but the `WP_Roles` global is
	 * not, so roles are re-read from the database before assignment. Without that
	 * a preceding test which removed capabilities — uninstall does — leaves the
	 * global short of them for every test after it.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_roles()->for_site();

		Capabilities::assign();

		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$this->users[ $role ] = self::factory()->user->create( array( 'role' => $role ) );
		}
	}

	/**
	 * The post type is registered by the plugin, not by a test fixture.
	 *
	 * @return void
	 */
	public function test_the_post_type_is_registered() {
		$this->assertTrue(
			post_type_exists( self::POST_TYPE ),
			'The popkit_popup post type is not registered. Nothing else in the plugin has anywhere to store a popup.'
		);
		$this->assertInstanceOf(
			'WP_Post_Type',
			get_post_type_object( self::POST_TYPE ),
			'get_post_type_object() did not return a post type object for popkit_popup.'
		);
		$this->assertSame(
			self::POST_TYPE,
			Post_Type::POST_TYPE,
			'Popkit\Post_Type::POST_TYPE no longer names the post type stored in wp_posts. Renaming it orphans every popup on every existing site.'
		);
	}

	/**
	 * A popup is private infrastructure and is authored over REST.
	 *
	 * @return void
	 */
	public function test_the_post_type_is_private_but_exposed_in_rest() {
		$post_type = get_post_type_object( self::POST_TYPE );

		$this->assertFalse(
			$post_type->public,
			'popkit_popup must not be public. A public popup gets a front-end URL of its own, lands in search results and in the sitemap, and can be read outside the page it was written for.'
		);
		$this->assertFalse(
			$post_type->publicly_queryable,
			'popkit_popup must not be publicly queryable. A popup is a fragment rendered into another page, never a destination.'
		);
		$this->assertTrue(
			$post_type->show_ui,
			'popkit_popup must show an admin UI. Authors have no other way to reach a popup.'
		);
		$this->assertTrue(
			$post_type->show_in_rest,
			'popkit_popup must be exposed in REST. The block editor is the authoring surface, and the Phase 5 sidebar reads and writes the _popkit_* meta over REST.'
		);
	}

	/**
	 * Every documented feature is supported, and only those.
	 *
	 * `custom-fields` is the load-bearing one: without it the registered
	 * `_popkit_*` meta is invisible on `/wp/v2/popkit_popup` however carefully it
	 * was registered, and every editor panel silently fails to save.
	 *
	 * @return void
	 */
	public function test_the_post_type_supports_the_documented_features() {
		foreach ( self::SUPPORTED_FEATURES as $feature ) {
			$this->assertTrue(
				post_type_supports( self::POST_TYPE, $feature ),
				sprintf( 'popkit_popup must support "%s". See docs/data-model.md -> Post type.', $feature )
			);
		}

		$declared = Post_Type::args()['supports'];

		$this->assertSame(
			self::SUPPORTED_FEATURES,
			$declared,
			'The declared supports list drifted from docs/data-model.md. custom-fields in particular is what exposes the _popkit_* meta over REST; dropping it breaks the editor silently.'
		);

		$this->assertSame(
			self::SUPPORTED_FEATURES,
			array_keys( get_all_post_type_supports( self::POST_TYPE ) ),
			'popkit_popup registered a feature that is not documented, or in a different order than declared. Every supported feature is a promise about what an author can do with a popup.'
		);
	}

	/**
	 * Meta capabilities are mapped, and the map is the plugin's own.
	 *
	 * The expectation is read from Capabilities::map() deliberately: what is
	 * under test here is that the post type carries the plugin's map. The map's
	 * contents are asserted in tests/php/unit/test-capability-map.php and its
	 * behavior in tests/php/integration/test-capabilities.php.
	 *
	 * @return void
	 */
	public function test_the_post_type_maps_meta_capabilities_to_the_popkit_capability_map() {
		$post_type = get_post_type_object( self::POST_TYPE );

		$this->assertTrue(
			$post_type->map_meta_cap,
			'map_meta_cap must be true. Without it edit_post is never resolved per object, the auth_callback on every meta key misbehaves, and the primitives used inside map_meta_cap() are never consulted at all.'
		);

		/*
		 * Asserted against the declaration, not against the built object.
		 * WP_Post_Type::set_props() collapses an array capability_type to its
		 * first entry before storing it — see wp-includes/class-wp-post-type.php,
		 * where the property is documented `@var string` — so the singular and
		 * plural pair only ever exists where it is declared. That is what
		 * Popkit\Post_Type::args() is exposed for; read its docblock.
		 */
		$this->assertSame(
			self::CAPABILITY_TYPE,
			Capabilities::CAPABILITY_TYPE,
			'capability_type must be the plugin\'s own singular and plural pair. It is what get_post_type_capabilities() falls back to, so a future key added by core resolves to a popkit capability rather than to the post capability of the same name.'
		);
		$this->assertSame(
			self::CAPABILITY_TYPE,
			Post_Type::args()['capability_type'],
			'The post type declares a capability_type other than the one Popkit\Capabilities holds, so the fallback used for a capability key core adds later is not the plugin\'s own.'
		);

		/*
		 * And the declaration is what was registered. Without this the two
		 * assertions above would keep passing if register_post_type() were ever
		 * handed something other than self::args().
		 */
		$this->assertSame(
			self::CAPABILITY_TYPE[0],
			$post_type->capability_type,
			'The registered post type did not collapse to the declared singular capability type, so it was registered from arguments other than Popkit\Post_Type::args().'
		);

		foreach ( Capabilities::map() as $key => $capability ) {
			$this->assertTrue(
				property_exists( $post_type->cap, $key ),
				sprintf( 'Capability "%s" is missing from the registered post type.', $key )
			);
			$this->assertIsString(
				$post_type->cap->$key,
				sprintf( 'Capability "%s" must resolve to a capability name.', $key )
			);
			$this->assertNotSame(
				'',
				trim( $post_type->cap->$key ),
				sprintf( 'Capability "%s" resolves to an empty name, so every check against it fails closed with no explanation.', $key )
			);
			$this->assertSame(
				$capability,
				$post_type->cap->$key,
				sprintf(
					'Capability "%s" resolves to "%s" rather than to "%s" from Popkit\Capabilities::map(). A mistyped map key falls back to core\'s default, which judges a popup by whether the user can edit blog posts.',
					$key,
					$post_type->cap->$key,
					$capability
				)
			);
		}

		foreach ( get_object_vars( $post_type->cap ) as $key => $capability ) {
			/*
			 * Core adds `read => read` of its own when map_meta_cap is true. That
			 * one is the site-wide read capability and is deliberately not a
			 * popkit capability; every other entry must be.
			 */
			if ( 'read' === $key ) {
				continue;
			}

			$this->assertStringContainsString(
				'popkit_popup',
				$capability,
				sprintf(
					'Capability "%s" resolves to "%s", which is not a popkit capability. A key core consults but the map does not declare falls back to the post capability of the same name, so a popup would be judged by whether the user can edit blog posts.',
					$key,
					$capability
				)
			);
		}
	}

	/**
	 * An editor may create a popup and edit one they authored.
	 *
	 * Thin on purpose. The full allow/deny grid across roles and post statuses
	 * lives in tests/php/integration/test-capabilities.php; duplicating it here
	 * would mean two places to update and two chances to update only one.
	 *
	 * @return void
	 */
	public function test_an_editor_may_create_and_edit_a_popup() {
		$editor       = $this->users['editor'];
		$capabilities = get_post_type_object( self::POST_TYPE )->cap;

		$popup_id = $this->create_popup( $editor );

		wp_set_current_user( $editor );

		$this->assertTrue(
			current_user_can( $capabilities->create_posts ),
			'An editor must be able to create a popup. Activation grants the editor role every popkit primitive except delete_others_popkit_popups.'
		);
		$this->assertTrue(
			current_user_can( 'edit_post', $popup_id ),
			'An editor must be able to edit a popup they authored.'
		);
	}

	/**
	 * A subscriber may do neither.
	 *
	 * @return void
	 */
	public function test_a_subscriber_may_not_create_or_edit_a_popup() {
		$subscriber   = $this->users['subscriber'];
		$capabilities = get_post_type_object( self::POST_TYPE )->cap;

		$popup_id = $this->create_popup( $subscriber );

		wp_set_current_user( $subscriber );

		$this->assertFalse(
			current_user_can( $capabilities->create_posts ),
			'A subscriber must not be able to create a popup. Roles other than administrator and editor receive no popkit capabilities.'
		);
		$this->assertFalse(
			current_user_can( 'edit_post', $popup_id ),
			'A subscriber must not be able to edit a popup, even one listed as authored by them.'
		);
	}

	/**
	 * Every label is declared and non-empty.
	 *
	 * A missing label is not a cosmetic problem. WordPress falls back to the
	 * wording for ordinary blog posts, so an author trashing a popup is told
	 * "Post trashed.", and Plugin Check reports the omission as a finding against
	 * the repository submission.
	 *
	 * @return void
	 */
	public function test_every_label_is_present_and_non_empty() {
		$declared = Post_Type::labels();

		$this->assertNotSame( array(), $declared, 'The post type declares no labels at all.' );

		foreach ( $declared as $name => $label ) {
			$this->assertIsString( $label, sprintf( 'The declared label "%s" is not a string.', $name ) );
			$this->assertNotSame(
				'',
				trim( $label ),
				sprintf( 'The declared label "%s" is empty. WordPress would fall back to the wording for blog posts.', $name )
			);
		}

		$labels = get_post_type_object( self::POST_TYPE )->labels;

		foreach ( $declared as $name => $label ) {
			$this->assertTrue(
				property_exists( $labels, $name ),
				sprintf( 'The declared label "%s" did not survive registration.', $name )
			);
			$this->assertSame(
				$label,
				$labels->$name,
				sprintf( 'The registered label "%s" does not match the declaration.', $name )
			);
		}

		foreach ( get_object_vars( $labels ) as $name => $label ) {
			$this->assertIsString(
				$label,
				sprintf( 'The registered label "%s" is not a string, so WordPress had no value to fall back on either.', $name )
			);
			$this->assertNotSame(
				'',
				trim( $label ),
				sprintf( 'The registered label "%s" is empty. Plugin Check flags a missing label, and the admin shows blank or blog-post wording where it appears.', $name )
			);
		}
	}

	/**
	 * Creates a popup owned by a given user.
	 *
	 * Drafts only. Status-dependent capability routing is the subject of
	 * tests/php/integration/test-capabilities.php.
	 *
	 * @param int $author_id Author user ID.
	 * @return int Post ID.
	 */
	private function create_popup( $author_id ) {
		return self::factory()->post->create(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'draft',
				'post_author' => $author_id,
				'post_title'  => 'Giving Tuesday',
			)
		);
	}
}
