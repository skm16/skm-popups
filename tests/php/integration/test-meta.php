<?php
/**
 * Integration tests for the six popup meta keys.
 *
 * Every key is protected — the names start with an underscore — which changes
 * what registration has to supply and what happens when it does not:
 *
 * - Without an explicit `show_in_rest` **schema**, `register_meta()` refuses to
 *   expose an `object` or `array` key over REST at all. `show_in_rest => true`
 *   registers a key the block editor cannot read, and nothing reports it.
 * - Without an `auth_callback`, a protected key falls back to `__return_false`
 *   and no write ever succeeds, including from the editor.
 *
 * Both failures are silent, which is why the assertions below read the
 * registration arguments back out of WordPress rather than trusting the
 * declaration.
 *
 * The sanitization tests exist because two shapes were **removed** from
 * `docs/data-model.md` before v1 shipped, and a value that survives
 * sanitization is a value the runtime will act on:
 *
 * - `display.close_button` — every popup renders a visible close button in
 *   every layout and theme. An overlay click is not an affordance: it is
 *   invisible, has no accessible name, is unreachable by keyboard and is
 *   undiscoverable by screen reader. A stored `close_button` key is therefore
 *   discarded rather than honoured.
 * - `frequency.mode` values `until_dismissed` and `until_converted`, and the
 *   `reset_on_convert` key. The first named three different behaviours at once,
 *   the second was `once_ever` with extra steps, and the third described the
 *   inverse of the useful behaviour — it would have made a visitor who just
 *   converted immediately eligible again.
 *
 * @package Popkit
 */

use Popkit\Capabilities;
use Popkit\Meta;
use Popkit\Post_Type;

/**
 * Integration coverage for Popkit\Meta.
 */
final class Test_Popkit_Meta extends WP_UnitTestCase {

	/**
	 * The six meta keys `docs/data-model.md` documents, pinned literally.
	 *
	 * Written out rather than read from Meta::keys() so that a key renamed in the
	 * plugin fails here instead of agreeing with itself. A rename is a data
	 * migration: meta rows already in the database keep the old name.
	 *
	 * @var string[]
	 */
	private const KEYS = array(
		'_popkit_conditions',
		'_popkit_triggers',
		'_popkit_schedule',
		'_popkit_frequency',
		'_popkit_display',
		'_popkit_schema_version',
	);

	/**
	 * Rule types used by the round trip, chosen so they can never be registered.
	 *
	 * No condition is registered under an `acme_` prefix and none ever will be,
	 * so these rules take the unregistered path for the life of the plugin. That
	 * is the path worth pinning: a rule whose condition is supplied by a plugin
	 * that happens to be deactivated at save time must survive the round trip
	 * semantically intact, because dropping it would destroy the author's
	 * targeting and widen the audience on reactivation.
	 *
	 * Using the built-in keys instead would make this test agree with whichever
	 * conditions happen to be registered in the phase it is run in.
	 *
	 * @var string[]
	 */
	private const VENDOR_TYPES = array( 'acme_post_type', 'acme_user_state', 'acme_url_path' );

	/**
	 * Popup the meta under test is written to.
	 *
	 * @var int
	 */
	private $popup_id = 0;

	/**
	 * Fixture user IDs, keyed by role slug.
	 *
	 * @var array<string, int>
	 */
	private $users = array();

	/**
	 * Grants capabilities, re-registers the meta, and creates a popup owned by the editor.
	 *
	 * The role option is rolled back between tests but the `WP_Roles` global is
	 * not, so roles are re-read from the database before assignment.
	 *
	 * Meta registration is re-established per test because
	 * `WP_UnitTestCase_Base::tear_down()` calls `unregister_all_meta_keys()`,
	 * which empties `$wp_meta_keys` of every key of every subtype.
	 * `Popkit\Meta::init()` registers once on `init`, which is right for
	 * production — `init` fires once per request — but in a suite only the first
	 * test in the process ever sees that registration. Registering here is what
	 * WordPress core's own meta tests do.
	 *
	 * This removes a flaky *pass* as well as the failures: with no re-
	 * registration, test_every_documented_meta_key_is_registered() only passes
	 * when it happens to be the first test in the process, and the tests after it
	 * fail with "Undefined array key" rather than with anything about popkit.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_roles()->for_site();

		Capabilities::assign();

		Meta::register();

		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$this->users[ $role ] = self::factory()->user->create( array( 'role' => $role ) );
		}

		$this->popup_id = $this->create_popup( $this->users['editor'] );
	}

	/**
	 * Every documented key is registered against the popup post type.
	 *
	 * @return void
	 */
	public function test_every_documented_meta_key_is_registered() {
		$registered = get_registered_meta_keys( 'post', Post_Type::POST_TYPE );

		foreach ( self::KEYS as $meta_key ) {
			$this->assertArrayHasKey(
				$meta_key,
				$registered,
				sprintf( 'The meta key "%s" is not registered against popkit_popup. Nothing about a popup beyond its title and content would be stored or exposed.', $meta_key )
			);
		}

		$this->assertSame(
			self::KEYS,
			Meta::keys(),
			'Popkit\Meta registers a different set of keys than docs/data-model.md documents. Renaming a stored key orphans the rows already written under the old name.'
		);
	}

	/**
	 * Each key is single, carries an explicit REST schema, a sanitizer and an auth callback.
	 *
	 * The schema assertion is deliberately about its *type*: `show_in_rest` set
	 * to boolean true is the failure being looked for, because register_meta()
	 * silently declines to expose an object or array key registered that way and
	 * the editor then reads nothing.
	 *
	 * @return void
	 */
	public function test_each_meta_key_declares_a_schema_a_sanitizer_and_an_auth_callback() {
		$registered = get_registered_meta_keys( 'post', Post_Type::POST_TYPE );

		foreach ( self::KEYS as $meta_key ) {
			$args = $registered[ $meta_key ];

			$this->assertTrue(
				$args['single'],
				sprintf( 'The meta key "%s" must be registered single. Every popkit key holds one value, and a multi-value key changes the shape the editor and the front end both read.', $meta_key )
			);

			$this->assertNotSame(
				true,
				$args['show_in_rest'],
				sprintf( 'The meta key "%s" declares show_in_rest as boolean true. register_meta() refuses to expose an object or array key without an explicit schema, so the key is registered but the editor can never read it — and nothing reports the omission.', $meta_key )
			);
			$this->assertIsArray(
				$args['show_in_rest'],
				sprintf( 'The meta key "%s" must declare show_in_rest as an array carrying an explicit schema.', $meta_key )
			);
			$this->assertArrayHasKey(
				'schema',
				$args['show_in_rest'],
				sprintf( 'The meta key "%s" declares show_in_rest without a schema key.', $meta_key )
			);
			$this->assertIsArray(
				$args['show_in_rest']['schema'],
				sprintf( 'The REST schema for "%s" must be an array describing the stored shape.', $meta_key )
			);
			$this->assertArrayHasKey(
				'type',
				$args['show_in_rest']['schema'],
				sprintf( 'The REST schema for "%s" declares no type, so REST has nothing to validate a write against.', $meta_key )
			);

			$this->assertIsCallable(
				$args['sanitize_callback'],
				sprintf( 'The meta key "%s" must declare a sanitize_callback. It is the floor beneath a direct update_post_meta() call, where there is nobody to report an error to.', $meta_key )
			);
			$this->assertIsCallable(
				$args['auth_callback'],
				sprintf( 'The meta key "%s" must declare an auth_callback. Every popkit key is protected, so without one register_meta() falls back to __return_false and no write ever succeeds — including from the block editor.', $meta_key )
			);
		}
	}

	/**
	 * Authorization follows `edit_post` on the popup, per user.
	 *
	 * Asserted through `map_meta_cap()` rather than by calling the callback
	 * directly, because that is the path WordPress actually takes: the meta
	 * capability `edit_post_meta` resolves to the registered auth callback, and a
	 * callback that answered for the wrong user would pass a direct call and fail
	 * here.
	 *
	 * @return void
	 */
	public function test_meta_authorization_follows_edit_post_on_the_object() {
		foreach ( self::KEYS as $meta_key ) {
			$this->assertTrue(
				user_can( $this->users['editor'], 'edit_post_meta', $this->popup_id, $meta_key ),
				sprintf( 'An editor who may edit this popup must be allowed to write "%s". Denying it would leave the block editor unable to save a panel it can see.', $meta_key )
			);
			$this->assertFalse(
				user_can( $this->users['subscriber'], 'edit_post_meta', $this->popup_id, $meta_key ),
				sprintf( 'A subscriber may not edit this popup, so they must not be able to write "%s" either. Targeting, schedule and frequency decide who sees a popup; a writable meta key is a writable audience.', $meta_key )
			);
		}
	}

	/**
	 * The auth callback answers for the user it was handed, and ignores the incoming verdict.
	 *
	 * `map_meta_cap()` is reachable through `user_can( $other_user, … )`, so a
	 * callback that consulted the current user would report on the wrong person.
	 * The incoming `$allowed` is passed as the opposite of the expected answer in
	 * each case, so a callback that simply returned it would fail both rows.
	 *
	 * @return void
	 */
	public function test_the_auth_callback_answers_for_the_supplied_user() {
		$this->assertTrue(
			Meta::can_edit( false, Meta::CONDITIONS, $this->popup_id, $this->users['editor'] ),
			'The auth callback denied a user who may edit the popup.'
		);
		$this->assertFalse(
			Meta::can_edit( true, Meta::CONDITIONS, $this->popup_id, $this->users['subscriber'] ),
			'The auth callback allowed a user who may not edit the popup, by trusting the verdict it was handed rather than resolving edit_post itself.'
		);
		$this->assertFalse(
			Meta::can_edit( true, Meta::CONDITIONS, 0, $this->users['editor'] ),
			'The auth callback allowed a write against no object at all. There is nothing for map_meta_cap() to resolve edit_post against, so the only safe answer is no.'
		);
	}

	/**
	 * A full rule set survives a save and a load semantically intact.
	 *
	 * Groups are ORed and rules within a group are ANDed, so both the order of
	 * the groups and the membership of each group are meaning, not presentation.
	 * `assertSame()` on arrays compares key order and value types as well as
	 * values, which is what makes this a byte-identical claim about `values`
	 * rather than a loose one.
	 *
	 * The second save asserts idempotence: reading a stored set and writing it
	 * back must not accumulate changes. A round trip that drifted by one key per
	 * save would look correct in isolation and destroy targeting over a few
	 * edits.
	 *
	 * @return void
	 */
	public function test_conditions_round_trip_a_full_rule_set() {
		$rule_set = array(
			'groups' => array(
				array(
					'rules' => array(
						array(
							'type'   => self::VENDOR_TYPES[0],
							'negate' => false,
							'values' => array( 'types' => array( 'post' ) ),
						),
						array(
							'type'   => self::VENDOR_TYPES[1],
							'negate' => true,
							'values' => array( 'state' => 'logged_in' ),
						),
					),
				),
				array(
					'rules' => array(
						array(
							'type'   => self::VENDOR_TYPES[2],
							'negate' => false,
							'values' => array(
								'match' => 'prefix',
								'value' => '/campaigns/',
							),
						),
					),
				),
			),
		);

		$expected = array(
			'groups' => array(
				array(
					'rules' => array(
						array(
							'type'    => self::VENDOR_TYPES[0],
							'negate'  => false,
							'values'  => array( 'types' => array( 'post' ) ),
							'unknown' => true,
						),
						array(
							'type'    => self::VENDOR_TYPES[1],
							'negate'  => true,
							'values'  => array( 'state' => 'logged_in' ),
							'unknown' => true,
						),
					),
				),
				array(
					'rules' => array(
						array(
							'type'    => self::VENDOR_TYPES[2],
							'negate'  => false,
							'values'  => array(
								'match' => 'prefix',
								'value' => '/campaigns/',
							),
							'unknown' => true,
						),
					),
				),
			),
		);

		update_post_meta( $this->popup_id, Meta::CONDITIONS, $rule_set );

		$stored = get_post_meta( $this->popup_id, Meta::CONDITIONS, true );

		$this->assertSame(
			$expected,
			$stored,
			'The stored rule set is not the one that was saved. An unregistered rule type is preserved and tagged unknown so the server can defer on it and the client can fail it closed; anything else — a dropped rule, a reordered group, a coerced value — changes who the popup shows to.'
		);

		/*
		 * Deleted first so the second write is a real one. update_metadata()
		 * returns early when the sanitized value matches what is already stored,
		 * which would leave this asserting the first save a second time.
		 */
		delete_post_meta( $this->popup_id, Meta::CONDITIONS );
		update_post_meta( $this->popup_id, Meta::CONDITIONS, $stored );

		$this->assertSame(
			$expected,
			get_post_meta( $this->popup_id, Meta::CONDITIONS, true ),
			'Saving a rule set that was just loaded changed it. Sanitization must be idempotent: a set that drifts by a key on every save destroys targeting over a handful of edits, and each individual save looks correct.'
		);
	}

	/**
	 * An empty rule set is stored as the documented "always match" shape.
	 *
	 * @return void
	 */
	public function test_an_empty_rule_set_is_stored_as_no_groups() {
		update_post_meta( $this->popup_id, Meta::CONDITIONS, array( 'groups' => array() ) );

		$this->assertSame(
			array( 'groups' => array() ),
			get_post_meta( $this->popup_id, Meta::CONDITIONS, true ),
			'A popup with no targeting stores an empty groups list, which docs/data-model.md defines as "always match".'
		);
	}

	/**
	 * A stored `close_button` key is discarded.
	 *
	 * @return void
	 */
	public function test_a_stored_close_button_key_is_discarded() {
		$display = array_merge(
			Meta::default_display(),
			array( 'close_button' => false )
		);

		update_post_meta( $this->popup_id, Meta::DISPLAY, $display );

		$stored = get_post_meta( $this->popup_id, Meta::DISPLAY, true );

		$this->assertArrayNotHasKey(
			'close_button',
			$stored,
			'A close_button key survived sanitization. There is no such field: every popup renders a visible close button in every layout and theme, and a stored false would eventually be read by something.'
		);
		$this->assertSame(
			Meta::default_display(),
			$stored,
			'Sanitizing a default display object with one stray key must yield the default display object exactly. The stored object is built from the declared fields, so an undeclared key has nowhere to land.'
		);
		$this->assertArrayNotHasKey(
			'close_button',
			Meta::display_schema()['properties'],
			'The REST schema declares a close_button property. The editor would render a control for a setting that cannot be honoured.'
		);
	}

	/**
	 * The frequency modes removed from the data model do not survive as values.
	 *
	 * @return void
	 */
	public function test_removed_frequency_modes_do_not_survive_sanitization() {
		foreach ( array( 'until_dismissed', 'until_converted' ) as $mode ) {
			$this->assertNotContains(
				$mode,
				Meta::FREQUENCY_MODES,
				sprintf( 'The frequency mode "%s" was removed from docs/data-model.md and must not be a permitted value again.', $mode )
			);

			update_post_meta(
				$this->popup_id,
				Meta::FREQUENCY,
				array(
					'mode'             => $mode,
					'days'             => 7,
					'on_convert'       => 'suppress_forever',
					'reset_on_convert' => true,
				)
			);

			$stored = get_post_meta( $this->popup_id, Meta::FREQUENCY, true );

			$this->assertNotSame(
				$mode,
				$stored['mode'],
				sprintf( 'The removed mode "%s" was stored verbatim. The front end has no implementation for it, so the cap it describes would never be applied and the popup would show on every pageview.', $mode )
			);
			$this->assertContains(
				$stored['mode'],
				Meta::FREQUENCY_MODES,
				'A stored frequency mode must always be one of the permitted values.'
			);
			$this->assertSame(
				'always',
				$stored['mode'],
				'An unrecognized mode falls back to the declared default, "always". That is the visible outcome an author can see and correct, rather than a cap that silently does nothing.'
			);
			$this->assertArrayNotHasKey(
				'reset_on_convert',
				$stored,
				'A reset_on_convert key survived sanitization. It was replaced by on_convert because "reset" described the inverse of the useful behaviour: it would clear the suppression and make a visitor who just converted immediately eligible again.'
			);
			$this->assertSame(
				array( 'mode', 'days', 'on_convert' ),
				array_keys( $stored ),
				'The stored frequency object carries keys the data model does not document.'
			);
		}
	}

	/**
	 * A recognized frequency mode is stored unchanged.
	 *
	 * Establishes that the assertions above are about the removed names and not
	 * about a sanitizer that rewrites every mode it is handed.
	 *
	 * @return void
	 */
	public function test_a_documented_frequency_mode_is_stored_unchanged() {
		update_post_meta(
			$this->popup_id,
			Meta::FREQUENCY,
			array(
				'mode'       => 'once_per_days',
				'days'       => 30,
				'on_convert' => 'none',
			)
		);

		$this->assertSame(
			array(
				'mode'       => 'once_per_days',
				'days'       => 30,
				'on_convert' => 'none',
			),
			get_post_meta( $this->popup_id, Meta::FREQUENCY, true ),
			'A documented frequency configuration must survive a save unchanged.'
		);
	}

	/**
	 * The stored schema version defaults to 1.
	 *
	 * The default is what a popup that never wrote the key reads as, so it is
	 * what the migration chain compares against. It is deliberately the *lowest*
	 * version popkit will ever store rather than the current one: defaulting to
	 * the current version would make every such popup claim to be already
	 * migrated the moment the current version is raised, and the migration would
	 * be skipped in silence.
	 *
	 * @return void
	 */
	public function test_the_schema_version_defaults_to_one() {
		$registered = get_registered_meta_keys( 'post', Post_Type::POST_TYPE );

		$this->assertSame(
			1,
			$registered[ Meta::SCHEMA_VERSION_KEY ]['default'],
			'The registered default for the schema version is not 1. It is what a popup that never wrote the key reads as, and therefore what the migration chain compares against.'
		);

		$fresh = $this->create_popup( $this->users['editor'] );

		$this->assertSame(
			1,
			get_post_meta( $fresh, Meta::SCHEMA_VERSION_KEY, true ),
			'A popup that has never written a schema version must read as version 1.'
		);
		$this->assertSame(
			1,
			Meta::SCHEMA_VERSION,
			'The current schema version is no longer 1. Raising it requires a migration in includes/migrations/ in the same commit; see docs/data-model.md -> Migrations.'
		);
	}

	/**
	 * A schema version below the floor is raised to it rather than stored.
	 *
	 * The stored value is cast before comparison: MySQL holds meta in a text
	 * column, so a scalar written as an integer reads back as the string `'1'`.
	 * The registered default asserted above is the value that is never written,
	 * and it stays a real integer.
	 *
	 * @return void
	 */
	public function test_a_schema_version_below_the_floor_is_raised_to_it() {
		update_post_meta( $this->popup_id, Meta::SCHEMA_VERSION_KEY, 0 );

		$this->assertSame(
			1,
			(int) get_post_meta( $this->popup_id, Meta::SCHEMA_VERSION_KEY, true ),
			'A schema version below 1 must be raised to 1. A zero would send the popup through a migration chain that starts at 1 and never ran for it.'
		);
	}

	/**
	 * Creates a draft popup owned by a given user.
	 *
	 * @param int $author_id Author user ID.
	 * @return int Post ID.
	 */
	private function create_popup( $author_id ) {
		return self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
				'post_author' => $author_id,
				'post_title'  => 'Giving Tuesday',
			)
		);
	}

	/**
	 * A popup that has stored nothing still serves complete defaults over REST.
	 *
	 * The editor depends on this and holds no defaults of its own. `useMeta()` in
	 * `src/editor/use-meta.js` returns whatever the entity record carries, so a
	 * key that arrived absent or half-populated would render panels with empty
	 * selects — and the first save would write whatever those empty controls
	 * produced over a configuration the author never opened.
	 *
	 * An earlier revision of the editor carried its own copy of these values and
	 * had `on_convert` wrong. Nothing failed, because both sides were internally
	 * consistent and only disagreed with each other. Removing the copy fixed that
	 * and made this test the thing holding the contract up.
	 *
	 * Asserted through `rest_do_request()` rather than `get_post_meta()`, because
	 * the REST response is what the editor actually receives: a key registered
	 * with a default but without `show_in_rest` would pass the direct read and
	 * arrive empty in the browser.
	 *
	 * @return void
	 */
	public function test_a_fresh_popup_serves_every_meta_default_over_rest() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );

		$popup = $this->create_popup( $administrator );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Post_Type::POST_TYPE . '/' . $popup ) );

		$this->assertSame( 200, $response->get_status(), 'The popup could not be read over REST at all.' );

		$meta = $response->get_data()['meta'] ?? array();

		$expected = array(
			Meta::CONDITIONS => Meta::default_conditions(),
			Meta::TRIGGERS   => Meta::default_triggers(),
			Meta::SCHEDULE   => Meta::default_schedule(),
			Meta::FREQUENCY  => Meta::default_frequency(),
			Meta::DISPLAY    => Meta::default_display(),
		);

		foreach ( $expected as $key => $default ) {
			$this->assertArrayHasKey(
				$key,
				$meta,
				sprintf(
					'"%s" is absent from the REST response for a popup that has stored nothing. The editor reads its starting values from this response and has no fallback, so its panel for this key would render empty and then save that emptiness.',
					$key
				)
			);

			$this->assertSame(
				$default,
				$meta[ $key ],
				sprintf(
					'"%s" does not arrive over REST as the value Meta declares as its default. The editor renders exactly what this response carries, so any difference here is a difference between what an author is shown and what the server holds.',
					$key
				)
			);
		}
	}
}
