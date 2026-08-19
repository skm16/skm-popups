<?php
/**
 * The capability lifecycle matrix.
 *
 * Phase 0 exit criteria, docs/build-plan.md: for each of administrator, editor and
 * subscriber, assert the expected allow/deny for create draft, edit own draft,
 * publish, edit own published, edit own private, trash, restore from trash, delete
 * permanently, edit another user's popup and delete another user's popup.
 *
 * Why the full lifecycle rather than plain CRUD:
 *
 * `capability_type => 'popkit_popup'` grants nobody anything on its own. WordPress
 * resolves the meta capability `edit_post` through `map_meta_cap()`, and which
 * primitive that lands on depends on the post's **status**:
 *
 * - draft, owned by the current user      -> edit_popkit_popups
 * - published, owned by the current user  -> edit_published_popkit_popups
 * - owned by another user                 -> edit_others_popkit_popups (plus
 *                                            edit_published_popkit_popups when
 *                                            published, edit_private_popkit_popups
 *                                            when private)
 *
 * So a role granted only `edit_popkit_popups` can create a popup, edit it as a
 * draft, publish it — and then permanently lose access to the thing it just
 * published. An author locked out of their own popup, with no error message
 * anywhere, because the capability the check now resolves to was never granted.
 * The same routing applies to `delete_post` via `delete_published_popkit_popups`,
 * which governs trashing, restoring and permanent deletion.
 *
 * A draft-only test suite passes clean against that bug. That is precisely why it
 * ships. Every row below that touches a non-draft status exists to catch it.
 *
 * @package Popkit
 */

use Popkit\Capabilities;

/**
 * Integration coverage for role capabilities across the full popup lifecycle.
 */
final class Test_Popkit_Capabilities extends WP_UnitTestCase {

	/**
	 * Post type under test.
	 *
	 * @var string
	 */
	private const POST_TYPE = 'popkit_popup';

	/**
	 * Roles covered by the matrix.
	 *
	 * @var string[]
	 */
	private const ROLES = array( 'administrator', 'editor', 'subscriber' );

	/**
	 * Every operation in the matrix, in assertion order.
	 *
	 * The first eight are own-content operations; the rest cover another user's
	 * popups. `read private popup owned by another user` exercises
	 * `read_private_popkit_popups`, which no other row reaches.
	 *
	 * @var string[]
	 */
	private const OPERATIONS = array(
		'create draft',
		'edit own draft',
		'publish',
		'edit own published',
		'edit own private',
		'trash own published',
		'restore own from trash',
		'delete own permanently',
		'edit draft owned by another user',
		'edit published popup owned by another user',
		'edit private popup owned by another user',
		'read private popup owned by another user',
		'delete published popup owned by another user',
		'delete private popup owned by another user',
	);

	/**
	 * The distinct primitive capabilities activation grants.
	 *
	 * Pinned here rather than derived from the map so that a wrong map produces a
	 * failure instead of a matching wrong expectation. tests/php/unit/test-capability-map.php
	 * asserts the map itself.
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
	 * The one capability an editor must not hold.
	 *
	 * @var string
	 */
	private const EDITOR_DENIED_CAPABILITY = 'delete_others_popkit_popups';

	/**
	 * Whether this test registered the temporary post type itself.
	 *
	 * @var bool
	 */
	private $registered_post_type = false;

	/**
	 * User IDs keyed by role slug.
	 *
	 * @var array<string, int>
	 */
	private $users = array();

	/**
	 * A second author, used for the "another user" rows.
	 *
	 * @var int
	 */
	private $other_author = 0;

	/**
	 * Assigns capabilities, creates one user per role and ensures the post type.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// The role option is rolled back between tests but the WP_Roles global is
		// not, so re-read from the database before assigning. Without this a test
		// that removes capabilities (uninstall) leaves the global short of them.
		wp_roles()->for_site();

		$this->assign_capabilities();

		foreach ( self::ROLES as $role ) {
			$this->users[ $role ] = self::factory()->user->create( array( 'role' => $role ) );
		}

		$this->other_author = self::factory()->user->create( array( 'role' => 'administrator' ) );

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
	 * The post type resolves every capability to a popkit capability name.
	 *
	 * @return void
	 */
	public function test_post_type_declares_map_meta_cap_and_a_complete_capability_map() {
		$post_type = get_post_type_object( self::POST_TYPE );

		$this->assertInstanceOf( 'WP_Post_Type', $post_type, 'The popkit_popup post type is not registered.' );
		$this->assertTrue(
			$post_type->map_meta_cap,
			'map_meta_cap must be true. Without it edit_post is never resolved per object, the auth_callback on every meta key misbehaves, and the primitives used inside map_meta_cap() are never consulted at all.'
		);

		$expected = array(
			'edit_post'              => 'edit_popkit_popup',
			'read_post'              => 'read_popkit_popup',
			'delete_post'            => 'delete_popkit_popup',
			'edit_posts'             => 'edit_popkit_popups',
			'edit_others_posts'      => 'edit_others_popkit_popups',
			'publish_posts'          => 'publish_popkit_popups',
			'read_private_posts'     => 'read_private_popkit_popups',
			'delete_posts'           => 'delete_popkit_popups',
			'create_posts'           => 'edit_popkit_popups',
			'edit_private_posts'     => 'edit_private_popkit_popups',
			'edit_published_posts'   => 'edit_published_popkit_popups',
			'delete_private_posts'   => 'delete_private_popkit_popups',
			'delete_published_posts' => 'delete_published_popkit_popups',
			'delete_others_posts'    => 'delete_others_popkit_popups',
		);

		foreach ( $expected as $key => $capability ) {
			$this->assertTrue(
				property_exists( $post_type->cap, $key ),
				sprintf( 'Registered capability "%s" is missing from the post type.', $key )
			);
			$this->assertIsString( $post_type->cap->$key, sprintf( 'Registered capability "%s" must be a string.', $key ) );
			$this->assertNotSame( '', trim( $post_type->cap->$key ), sprintf( 'Registered capability "%s" resolves to an empty name.', $key ) );
			$this->assertSame(
				$capability,
				$post_type->cap->$key,
				sprintf( 'Registered capability "%s" must resolve to "%s".', $key, $capability )
			);
		}
	}

	/**
	 * Administrators may perform every operation.
	 *
	 * @return void
	 */
	public function test_administrator_capability_matrix() {
		$this->assertSame(
			$this->expected_matrix( true ),
			$this->evaluate_matrix( 'administrator' ),
			'Administrators must be able to perform every popup operation.'
		);
	}

	/**
	 * Editors may do everything except delete another user's popup.
	 *
	 * Note the rows that would fail if `edit_published_popkit_popups`,
	 * `edit_private_popkit_popups`, `delete_published_popkit_popups` or
	 * `delete_private_popkit_popups` were missing from the map or from the role.
	 *
	 * @return void
	 */
	public function test_editor_capability_matrix() {
		$expected = $this->expected_matrix(
			true,
			array(
				'delete published popup owned by another user' => false,
				'delete private popup owned by another user'   => false,
			)
		);

		$this->assertSame(
			$expected,
			$this->evaluate_matrix( 'editor' ),
			'Editors hold every popkit capability except delete_others_popkit_popups.'
		);
	}

	/**
	 * Subscribers may do nothing at all.
	 *
	 * @return void
	 */
	public function test_subscriber_is_denied_every_operation() {
		$this->assertSame(
			$this->expected_matrix( false ),
			$this->evaluate_matrix( 'subscriber' ),
			'Roles other than administrator and editor receive no popkit capabilities.'
		);
	}

	/**
	 * An editor does not lose access to a popup by publishing it.
	 *
	 * The regression test for the `edit_published_posts` omission, walked as a
	 * real lifecycle rather than as a set of independent fixtures: draft, publish,
	 * private, trash, restore, trash again, permanent delete.
	 *
	 * @return void
	 */
	public function test_editor_keeps_access_to_a_popup_through_the_whole_lifecycle() {
		$editor = $this->users['editor'];
		$caps   = get_post_type_object( self::POST_TYPE )->cap;

		wp_set_current_user( $editor );

		$this->assertTrue( current_user_can( $caps->create_posts ), 'An editor must be able to create a popup.' );
		$this->assertTrue( current_user_can( $caps->publish_posts ), 'An editor must be able to publish a popup.' );

		$popup_id = $this->create_popup( $editor, 'draft' );
		$this->assertTrue( current_user_can( 'edit_post', $popup_id ), 'An editor must be able to edit their own draft.' );

		wp_update_post(
			array(
				'ID'          => $popup_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 'publish', get_post_status( $popup_id ), 'The popup did not reach published status.' );
		$this->assertTrue(
			current_user_can( 'edit_post', $popup_id ),
			'An editor lost access to their own popup the moment it was published. map_meta_cap() routes edit_post through edit_published_popkit_popups once the status is publish, so that primitive must be in the capability map and on the role.'
		);
		$this->assertTrue(
			current_user_can( 'delete_post', $popup_id ),
			'An editor must be able to trash their own published popup. delete_post routes through delete_published_popkit_popups once published.'
		);

		wp_update_post(
			array(
				'ID'          => $popup_id,
				'post_status' => 'private',
			)
		);

		$this->assertSame( 'private', get_post_status( $popup_id ), 'The popup did not reach private status.' );
		$this->assertTrue( current_user_can( 'edit_post', $popup_id ), 'An editor must be able to edit their own private popup.' );
		$this->assertTrue( current_user_can( 'delete_post', $popup_id ), 'An editor must be able to delete their own private popup.' );

		wp_update_post(
			array(
				'ID'          => $popup_id,
				'post_status' => 'publish',
			)
		);

		wp_trash_post( $popup_id );

		$this->assertSame(
			'trash',
			get_post_status( $popup_id ),
			'The popup was not trashed. If EMPTY_TRASH_DAYS is 0 the post was deleted outright and this scenario cannot be asserted.'
		);
		$this->assertSame(
			'publish',
			get_post_meta( $popup_id, '_wp_trash_meta_status', true ),
			'The trashed popup must remember it was published; map_meta_cap() reads that value to pick between delete_popkit_popups and delete_published_popkit_popups.'
		);
		$this->assertTrue(
			current_user_can( 'delete_post', $popup_id ),
			'An editor must be able to restore their own popup from the trash. wp-admin routes untrash through the delete_post meta capability.'
		);

		wp_untrash_post( $popup_id );

		$this->assertNotSame( 'trash', get_post_status( $popup_id ), 'The popup was not restored from the trash.' );
		$this->assertTrue( current_user_can( 'edit_post', $popup_id ), 'An editor must be able to edit a popup restored from the trash.' );

		wp_trash_post( $popup_id );

		$this->assertTrue(
			current_user_can( 'delete_post', $popup_id ),
			'An editor must be able to permanently delete their own trashed popup.'
		);
	}

	/**
	 * A subscriber cannot reach a popup at any point in its lifecycle.
	 *
	 * @return void
	 */
	public function test_subscriber_cannot_reach_a_popup_at_any_status() {
		$subscriber = $this->users['subscriber'];

		foreach ( array( 'draft', 'publish', 'private' ) as $status ) {
			$own = $this->create_popup( $subscriber, $status );

			wp_set_current_user( $subscriber );

			$this->assertFalse(
				current_user_can( 'edit_post', $own ),
				sprintf( 'A subscriber must not be able to edit a %s popup even when listed as its author.', $status )
			);
			$this->assertFalse(
				current_user_can( 'delete_post', $own ),
				sprintf( 'A subscriber must not be able to delete a %s popup even when listed as its author.', $status )
			);
		}
	}

	/**
	 * Roles hold exactly the documented capability sets.
	 *
	 * @return void
	 */
	public function test_roles_hold_the_documented_capability_sets() {
		$administrator = get_role( 'administrator' );
		$editor        = get_role( 'editor' );
		$subscriber    = get_role( 'subscriber' );

		$this->assertInstanceOf( 'WP_Role', $administrator, 'The administrator role is missing.' );
		$this->assertInstanceOf( 'WP_Role', $editor, 'The editor role is missing.' );
		$this->assertInstanceOf( 'WP_Role', $subscriber, 'The subscriber role is missing.' );

		foreach ( self::PRIMITIVES as $capability ) {
			$this->assertTrue(
				$administrator->has_cap( $capability ),
				sprintf( 'The administrator role is missing "%s".', $capability )
			);

			$this->assertFalse(
				$subscriber->has_cap( $capability ),
				sprintf( 'The subscriber role must not hold "%s".', $capability )
			);

			if ( self::EDITOR_DENIED_CAPABILITY === $capability ) {
				$this->assertFalse(
					$editor->has_cap( $capability ),
					sprintf( 'The editor role must not hold "%s".', $capability )
				);
				continue;
			}

			$this->assertTrue(
				$editor->has_cap( $capability ),
				sprintf( 'The editor role is missing "%s".', $capability )
			);
		}

		$this->assertCount( count( self::PRIMITIVES ), $this->popkit_capabilities_of( 'administrator' ), 'The administrator role holds an unexpected number of popkit capabilities.' );
		$this->assertCount( count( self::PRIMITIVES ) - 1, $this->popkit_capabilities_of( 'editor' ), 'The editor role holds an unexpected number of popkit capabilities.' );
		$this->assertSame( array(), $this->popkit_capabilities_of( 'subscriber' ), 'The subscriber role must hold no popkit capabilities.' );
	}

	/**
	 * Meta capability names are never granted to a role.
	 *
	 * A role holding `edit_popkit_popup` would satisfy the per-object check for
	 * every popup regardless of author or status, defeating map_meta_cap()
	 * entirely.
	 *
	 * @return void
	 */
	public function test_meta_capabilities_are_not_granted_to_roles() {
		foreach ( array( 'administrator', 'editor', 'subscriber' ) as $role_name ) {
			$role = get_role( $role_name );

			foreach ( array( 'edit_popkit_popup', 'read_popkit_popup', 'delete_popkit_popup' ) as $meta_capability ) {
				$this->assertFalse(
					$role->has_cap( $meta_capability ),
					sprintf(
						'The %s role holds the meta capability "%s". Meta capabilities are resolved per object by map_meta_cap() and must never sit on a role.',
						$role_name,
						$meta_capability
					)
				);
			}
		}
	}

	/**
	 * Running the assignment routine twice changes nothing.
	 *
	 * A site restored from a backup taken before activation must self-heal, so
	 * the routine re-runs. It must be a no-op when the capabilities are already
	 * in place: no duplicated entries, no capability gained or lost.
	 *
	 * @return void
	 */
	public function test_assignment_is_idempotent() {
		$before = $this->role_capability_snapshot();

		$this->assign_capabilities();
		wp_roles()->for_site();

		$after = $this->role_capability_snapshot();

		$this->assertSame( $before, $after, 'Re-running capability assignment must leave the role capability sets byte-identical.' );

		$this->assertCount( count( self::PRIMITIVES ), $this->popkit_capabilities_of( 'administrator' ), 'Re-running assignment changed the administrator capability count.' );
		$this->assertCount( count( self::PRIMITIVES ) - 1, $this->popkit_capabilities_of( 'editor' ), 'Re-running assignment changed the editor capability count.' );
		$this->assertSame( array(), $this->popkit_capabilities_of( 'subscriber' ), 'Re-running assignment granted capabilities to a role that should have none.' );
	}

	/**
	 * A second assignment does not disturb the matrix.
	 *
	 * @return void
	 */
	public function test_matrix_is_unchanged_after_a_second_assignment() {
		$first = $this->evaluate_matrix( 'editor' );

		$this->assign_capabilities();
		wp_roles()->for_site();

		$this->assertSame( $first, $this->evaluate_matrix( 'editor' ), 'The editor matrix changed after assignment re-ran.' );
	}

	/**
	 * Evaluates every matrix operation for one role.
	 *
	 * Fixtures are created first, as whichever user the harness has current, with
	 * explicit authors. The current user is only switched immediately before the
	 * capability checks so that nothing in the fixture setup depends on caps.
	 *
	 * @param string $role Role slug.
	 * @return array<string, bool> Operation label => allowed.
	 */
	private function evaluate_matrix( $role ) {
		$this->assertArrayHasKey( $role, $this->users, sprintf( 'No fixture user for role "%s".', $role ) );

		$user_id = $this->users[ $role ];

		$own_draft     = $this->create_popup( $user_id, 'draft' );
		$own_published = $this->create_popup( $user_id, 'publish' );
		$own_private   = $this->create_popup( $user_id, 'private' );
		$own_trashed   = $this->create_trashed_popup( $user_id );

		$other_draft     = $this->create_popup( $this->other_author, 'draft' );
		$other_published = $this->create_popup( $this->other_author, 'publish' );
		$other_private   = $this->create_popup( $this->other_author, 'private' );

		$caps = get_post_type_object( self::POST_TYPE )->cap;

		wp_set_current_user( $user_id );

		$results = array(
			'create draft'                                 => current_user_can( $caps->create_posts ),
			'edit own draft'                               => current_user_can( 'edit_post', $own_draft ),
			'publish'                                      => current_user_can( $caps->publish_posts ),
			'edit own published'                           => current_user_can( 'edit_post', $own_published ),
			'edit own private'                             => current_user_can( 'edit_post', $own_private ),
			'trash own published'                          => current_user_can( 'delete_post', $own_published ),

			/*
			 * Restore and permanent delete are both gated by delete_post on the
			 * trashed post — that is the check wp-admin performs for the untrash
			 * and delete actions alike. They are listed separately because they
			 * are separate operations in the exit criteria and would diverge if
			 * either check were ever customized.
			 */
			'restore own from trash'                       => current_user_can( 'delete_post', $own_trashed ),
			'delete own permanently'                       => current_user_can( 'delete_post', $own_trashed ),

			'edit draft owned by another user'             => current_user_can( 'edit_post', $other_draft ),
			'edit published popup owned by another user'   => current_user_can( 'edit_post', $other_published ),
			'edit private popup owned by another user'     => current_user_can( 'edit_post', $other_private ),
			'read private popup owned by another user'     => current_user_can( 'read_post', $other_private ),
			'delete published popup owned by another user' => current_user_can( 'delete_post', $other_published ),
			'delete private popup owned by another user'   => current_user_can( 'delete_post', $other_private ),
		);

		$this->assertSame(
			self::OPERATIONS,
			array_keys( $results ),
			'The evaluated matrix drifted from the documented operation list.'
		);

		return $results;
	}

	/**
	 * Builds an expectation row.
	 *
	 * @param bool                $baseline  Expected result for every operation.
	 * @param array<string, bool> $overrides Per-operation exceptions.
	 * @return array<string, bool>
	 */
	private function expected_matrix( $baseline, array $overrides = array() ) {
		foreach ( array_keys( $overrides ) as $operation ) {
			$this->assertContains( $operation, self::OPERATIONS, sprintf( 'Unknown matrix operation "%s".', $operation ) );
		}

		return array_merge( array_fill_keys( self::OPERATIONS, $baseline ), $overrides );
	}

	/**
	 * Creates a popup owned by a given user.
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
	 * Creates a published popup and trashes it.
	 *
	 * Trashed *from published* on purpose: map_meta_cap() reads
	 * `_wp_trash_meta_status` to decide whether restoring and deleting need
	 * `delete_popkit_popups` or `delete_published_popkit_popups`. A popup trashed
	 * from draft would take the easier of the two paths and hide the omission.
	 *
	 * @param int $author_id Author user ID.
	 * @return int Post ID.
	 */
	private function create_trashed_popup( $author_id ) {
		$post_id = $this->create_popup( $author_id, 'publish' );

		wp_trash_post( $post_id );

		$this->assertSame(
			'trash',
			get_post_status( $post_id ),
			'Fixture popup was not trashed. If EMPTY_TRASH_DAYS is 0, wp_trash_post() deletes outright and the trash rows cannot be asserted.'
		);
		$this->assertSame(
			'publish',
			get_post_meta( $post_id, '_wp_trash_meta_status', true ),
			'The trashed fixture must remember that it was published.'
		);

		return $post_id;
	}

	/**
	 * Registers a temporary popkit_popup post type when one is not present.
	 *
	 * Phase 0 registers no post type — that is Phase 1's job — but the capability
	 * matrix has to be assertable now, because the omission it guards against is
	 * made in Phase 0's capability map. The registration below is built from the
	 * plugin's own map, so what is under test is the plugin's declaration and not
	 * a fixture invented here.
	 *
	 * Once Phase 1 registers the real post type this either finds it already
	 * present and leaves it alone, or re-registers an identical one from the same
	 * map. Either way the assertions stay valid and this method can be deleted
	 * without touching a single expectation.
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
	 * Returns the popkit capabilities currently held by a role, sorted.
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

		sort( $capabilities );

		return $capabilities;
	}

	/**
	 * Snapshots the popkit capabilities of every role, for idempotency checks.
	 *
	 * @return array<string, string[]>
	 */
	private function role_capability_snapshot() {
		$snapshot = array();

		foreach ( array_keys( wp_roles()->get_names() ) as $role_name ) {
			$snapshot[ $role_name ] = $this->popkit_capabilities_of( $role_name );
		}

		ksort( $snapshot );

		return $snapshot;
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
	 * Accepts either the bare map or a post type argument array wrapping it under
	 * a `capabilities` key, so a rename of the accessor does not require this file
	 * to change in lockstep.
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
