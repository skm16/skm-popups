<?php
/**
 * Integration tests for authoring popups in the classic editor.
 *
 * PopKit shipped a block-editor sidebar and nothing else. On a site running the
 * Classic Editor plugin, `enqueue_block_editor_assets` never fires and no meta
 * box existed, so the popup screen was a WYSIWYG box with no targeting,
 * triggers, schedule, frequency or appearance controls at all — every setting
 * unreachable, with no error to say so.
 *
 * The fix is that PopKit follows whichever editor the site already uses
 * ({@see Popkit\Editor_Mode}) and renders the same five panels as meta boxes when
 * that is the classic one ({@see Popkit\Classic_Editor}). Both halves are tested
 * here, including the two ways the first attempt got it wrong: overriding the
 * site's choice rather than following it, and stating the preference on a hook
 * that does not decide.
 *
 * ## What these tests are really guarding
 *
 * Not "does a checkbox render". The save path is where an authoring surface
 * quietly destroys work, and three of those failures are specifically pinned:
 *
 * - A save with no nonce — a bulk edit, a quick edit, another plugin calling
 *   `wp_update_post()` — must leave every panel alone rather than write the
 *   defaults over an author's targeting.
 * - A rule whose condition belongs to a deactivated plugin must survive a save
 *   with its values intact. It renders no controls, so nothing would carry it.
 * - Unticking a checkbox must store a real `false`, because an unchecked box
 *   posts nothing at all.
 *
 * @package Popkit
 */

use Popkit\Capabilities;
use Popkit\Classic_Editor;
use Popkit\Classic_Fields;
use Popkit\Editor_Mode;
use Popkit\Meta;
use Popkit\Post_Type;

/**
 * Integration coverage for the classic-editor authoring surface.
 */
final class Test_Popkit_Classic_Editor extends WP_UnitTestCase {

	/**
	 * Popup under test.
	 *
	 * @var int
	 */
	private $popup = 0;

	/**
	 * Grants capabilities and creates a popup owned by an administrator.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		/*
		 * Put the site in the state where the classic surface is the one that runs.
		 * Without it these tests pass vacuously, asserting that nothing happened
		 * while believing they asserted that the right thing happened.
		 *
		 * `Editor_Mode::init()` is called again afterwards on purpose. It reads the
		 * filter once, at boot, and attaches nothing when nobody has an opinion — so
		 * a filter added after boot registers no override, exactly as it would on a
		 * real site where the filter has to be in place before `plugins_loaded`.
		 * Adding the filter without re-initializing would test a configuration no
		 * site can actually have.
		 */
		add_filter( 'popkit_use_block_editor', '__return_false' );
		Editor_Mode::init();

		Capabilities::assign();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->popup = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Classic fixture',
			)
		);
	}

	/**
	 * Clears request state and granted capabilities.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST = array();

		remove_filter( 'popkit_use_block_editor', '__return_false' );

		// Leave the hook table as it was found: these are attached by init() above
		// and would otherwise decide the editor for every test that runs after.
		remove_filter( 'use_block_editor_for_post', array( Editor_Mode::class, 'filter_post' ), 1000 );
		remove_filter( 'use_block_editor_for_post_type', array( Editor_Mode::class, 'filter_post_type' ), 1000 );
		remove_filter( 'classic_editor_enabled_editors_for_post_type', array( Editor_Mode::class, 'filter_classic_editor' ), 1000 );

		Capabilities::remove();

		parent::tear_down();
	}

	/**
	 * By default PopKit registers no editor filter and follows the site.
	 *
	 * The reversal of the original design. Forcing the block editor for popups
	 * overrode a decision the site owner had already made, on the reasoning that a
	 * popup's content is blocks — true of the data model and beside the point,
	 * since `post_content` is stored and rendered identically whichever screen
	 * wrote it. What PopKit owes a site is that the settings appear on the screen
	 * it chose, not that it chooses the screen.
	 *
	 * Asserted as the *absence* of hooks rather than as a return value: "follows
	 * the site" means the hook table is one PopKit never touched, and a test that
	 * only checked the outcome would still pass if PopKit overrode the site to the
	 * same answer by luck.
	 *
	 * @return void
	 */
	public function test_by_default_popkit_does_not_touch_the_sites_editor() {
		$this->forget_override();

		$this->assertNull( Editor_Mode::forced(), 'The default is no opinion.' );

		Editor_Mode::init();

		$this->assertFalse(
			has_filter( 'use_block_editor_for_post', array( Editor_Mode::class, 'filter_post' ) ),
			'With no opinion, PopKit must not filter the per-post decision.'
		);
		$this->assertFalse(
			has_filter( 'use_block_editor_for_post_type', array( Editor_Mode::class, 'filter_post_type' ) ),
			'Nor the per-post-type one.'
		);
	}

	/**
	 * A site that uses the classic editor gets popups in the classic editor.
	 *
	 * The behavior the reversal exists for, expressed the way a real site
	 * produces it: something else forces the classic editor, and PopKit follows
	 * rather than fighting.
	 *
	 * @return void
	 */
	public function test_popkit_follows_a_site_that_uses_the_classic_editor() {
		$this->forget_override();

		// The Classic Editor plugin's default configuration, reproduced exactly.
		add_filter( 'use_block_editor_for_post', '__return_false', 100 );

		$popup = get_post( $this->popup );

		$this->assertFalse(
			Editor_Mode::resolved_for_post( $popup ),
			'PopKit must not drag a classic site into the block editor.'
		);

		$GLOBALS['wp_meta_boxes'] = array();

		Classic_Editor::register_meta_boxes( $popup );

		$this->assertNotSame(
			array(),
			$GLOBALS['wp_meta_boxes'],
			'And the settings have to be on the screen the site actually serves.'
		);

		remove_filter( 'use_block_editor_for_post', '__return_false', 100 );
		$GLOBALS['wp_meta_boxes'] = array();
	}

	/**
	 * The preference survives a plugin forcing the classic editor per post.
	 *
	 * The regression test for the bug that shipped. `use_block_editor_for_post_type`
	 * is not the final word: core calls it from `use_block_editor_for_post()` and
	 * then filters *that*, so anything hooking the per-post filter overrules it.
	 *
	 * The Classic Editor plugin does exactly this, and by the route that is easy
	 * to miss — on its default settings it hooks `__return_false` straight onto
	 * `use_block_editor_for_post` at priority 100, never consulting the
	 * settings-aware `choose_editor()` that would have let popups through. Filtering
	 * only the post-type hook produced a popup screen served by the classic editor
	 * while the classic meta boxes, trusting popkit's own preference, stood down.
	 * No error, no notice — a popup with nowhere to configure anything.
	 *
	 * `__return_false` at 100 is that plugin's behavior reproduced exactly.
	 *
	 * @return void
	 */
	public function test_the_block_editor_preference_beats_a_per_post_override() {
		$this->forget_override();
		add_filter( 'popkit_use_block_editor', '__return_true' );
		Editor_Mode::init();

		add_filter( 'use_block_editor_for_post', '__return_false', 100 );

		$this->assertTrue(
			use_block_editor_for_post( get_post( $this->popup ) ),
			'A plugin forcing the classic editor per post must not strand a popup with neither interface.'
		);

		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertFalse(
			use_block_editor_for_post( get_post( $page ) ),
			"Every other post type keeps the site's own answer."
		);

		remove_filter( 'use_block_editor_for_post', '__return_false', 100 );
		remove_filter( 'popkit_use_block_editor', '__return_true' );
	}

	/**
	 * The surfaces follow what WordPress resolved, not what popkit preferred.
	 *
	 * The second half of the same bug. Even with the filter above in place, gating
	 * the meta boxes on popkit's *preference* means that any future disagreement
	 * about which editor loads takes the settings interface down with it. Gating on
	 * the resolved answer bounds the damage: the panels can end up on the other
	 * screen, never on neither.
	 *
	 * @return void
	 */
	public function test_the_meta_boxes_follow_the_resolved_editor() {
		$this->forget_override();

		$popup = get_post( $this->popup );

		$this->assertTrue(
			Editor_Mode::resolved_for_post( $popup ),
			'Precondition: this test environment has no classic editor, so popups resolve to the block editor.'
		);

		// Something outside popkit forces the classic screen for this post, at a
		// priority popkit cannot outrun.
		add_filter( 'use_block_editor_for_post', '__return_false', 99999 );

		$this->assertFalse(
			Editor_Mode::resolved_for_post( $popup ),
			'The resolved answer has to reflect what actually happened.'
		);

		$GLOBALS['wp_meta_boxes'] = array();

		Classic_Editor::register_meta_boxes( $popup );

		$this->assertNotSame(
			array(),
			$GLOBALS['wp_meta_boxes'],
			'When the classic screen is served, the classic boxes must render — whoever decided that.'
		);

		remove_filter( 'use_block_editor_for_post', '__return_false', 99999 );
		$GLOBALS['wp_meta_boxes'] = array();
	}

	/**
	 * A site can decline, and then the classic surface is the one that mounts.
	 *
	 * @return void
	 */
	public function test_a_site_can_decline_the_block_editor_for_popups() {
		// The filter is already in place from set_up(); this asserts what it does.
		$this->assertFalse( Editor_Mode::uses_block_editor() );
		$this->assertFalse(
			Editor_Mode::filter_post_type( true, Post_Type::POST_TYPE ),
			'Declining has to actually reach core.'
		);

		$editors = Editor_Mode::filter_classic_editor(
			array(
				'classic_editor' => true,
				'block_editor'   => true,
			),
			Post_Type::POST_TYPE
		);

		$this->assertSame(
			array(
				'classic_editor' => true,
				'block_editor'   => false,
			),
			$editors,
			'Exactly one editor is offered, so no link can drop half the interface.'
		);
	}

	/**
	 * Targeting survives a round trip through the form.
	 *
	 * @return void
	 */
	public function test_targeting_saves_from_the_meta_box() {
		$this->submit(
			array(
				'popkit_conditions' => array(
					'groups' => array(
						array(
							'rules' => array(
								array(
									'type'   => 'url_path',
									'negate' => '1',
									'values' => array(
										'match' => 'prefix',
										'value' => '/pricing/',
									),
								),
							),
						),
					),
				),
			)
		);

		$stored = get_post_meta( $this->popup, Meta::CONDITIONS, true );

		$this->assertSame(
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => 'url_path',
								'negate' => true,
								'values' => array(
									'match' => 'prefix',
									'value' => '/pricing/',
								),
							),
						),
					),
				),
			),
			$stored,
			'A rule written on the classic screen has to store exactly what REST would.'
		);
	}

	/**
	 * A rule with no condition chosen is dropped rather than stored empty.
	 *
	 * An empty type is what a freshly added row looks like before the author
	 * picks anything, and storing it leaves a rule that can never be evaluated
	 * and never explains itself.
	 *
	 * @return void
	 */
	public function test_a_rule_naming_no_condition_is_dropped() {
		$this->submit(
			array(
				'popkit_conditions' => array(
					'groups' => array(
						array(
							'rules' => array(
								array(
									'type'   => '',
									'values' => array(),
								),
							),
						),
					),
				),
			)
		);

		$this->assertSame(
			array( 'groups' => array() ),
			get_post_meta( $this->popup, Meta::CONDITIONS, true ),
			'An unfinished row is not targeting.'
		);
	}

	/**
	 * A rule whose plugin is deactivated keeps its values through a save.
	 *
	 * It renders no controls, because there is no schema to render from, so
	 * without the hidden carrier field pressing Update would silently delete an
	 * extension's targeting.
	 *
	 * @return void
	 */
	public function test_an_unavailable_rule_keeps_its_settings() {
		$preserved = array(
			'threshold' => 42,
			'label'     => 'from an extension',
		);

		$this->submit(
			array(
				'popkit_conditions' => array(
					'groups' => array(
						array(
							'rules' => array(
								array(
									'type'      => 'some_absent_extension_rule',
									'preserved' => wp_json_encode( $preserved ),
								),
							),
						),
					),
				),
			)
		);

		$stored = get_post_meta( $this->popup, Meta::CONDITIONS, true );

		$this->assertSame(
			$preserved,
			$stored['groups'][0]['rules'][0]['values'],
			'Deactivating a plugin must not let a save destroy the rules it owned.'
		);
	}

	/**
	 * Triggers are built from the checkboxes that are ticked.
	 *
	 * @return void
	 */
	public function test_triggers_save_only_the_ticked_ones() {
		$this->submit(
			array(
				'popkit_triggers' => array(
					'page_load'    => array(
						'enabled' => '1',
						'values'  => array( 'delay_ms' => '250' ),
					),
					'scroll_depth' => array( 'values' => array( 'percent' => '50' ) ),
				),
			)
		);

		$stored = get_post_meta( $this->popup, Meta::TRIGGERS, true );

		$this->assertCount( 1, $stored, 'An unticked trigger is not stored.' );
		$this->assertSame( 'page_load', $stored[0]['type'] );
		$this->assertSame(
			250,
			$stored[0]['values']['delay_ms'],
			'A form posts strings; the stored value has to be the integer the schema declares.'
		);
	}

	/**
	 * Unticking an appearance checkbox stores a real false.
	 *
	 * @return void
	 */
	public function test_unticking_a_display_toggle_stores_false() {
		$this->submit(
			array(
				'popkit_display' => array(
					'layout'  => 'banner',
					'overlay' => '0',
				),
			)
		);

		$stored = get_post_meta( $this->popup, Meta::DISPLAY, true );

		$this->assertSame( 'banner', $stored['layout'] );
		$this->assertFalse( $stored['overlay'], 'An unchecked box posts nothing, and that has to mean off.' );
	}

	/**
	 * An empty schedule bound is stored as null, not an empty string.
	 *
	 * `docs/data-model.md` gives null the meaning "no bound". An empty string is
	 * a value that happens to parse to nothing, which is a different claim.
	 *
	 * @return void
	 */
	public function test_an_empty_schedule_bound_is_null() {
		$this->submit(
			array(
				'popkit_schedule' => array(
					'enabled'  => '1',
					'timezone' => 'visitor',
					'start'    => '2026-01-01T09:00',
					'end'      => '',
				),
			)
		);

		$stored = get_post_meta( $this->popup, Meta::SCHEDULE, true );

		$this->assertTrue( $stored['enabled'] );
		$this->assertSame( 'visitor', $stored['timezone'] );
		$this->assertNull( $stored['end'], 'No end bound is null, never an empty string.' );
	}

	/**
	 * A save carrying no nonce leaves every panel alone.
	 *
	 * This is the one that protects an author from a bulk status change wiping
	 * their targeting. Writing defaults on an unrecognized save would look
	 * harmless in a unit test and destroy work on a real site.
	 *
	 * @return void
	 */
	public function test_a_save_without_the_nonce_changes_nothing() {
		$before = array(
			'groups' => array(
				array(
					'rules' => array(
						array(
							'type'   => 'is_404',
							'negate' => false,
							'values' => array(),
						),
					),
				),
			),
		);
		update_post_meta( $this->popup, Meta::CONDITIONS, $before );

		$_POST = array(
			'popkit_conditions' => array( 'groups' => array() ),
			'popkit_display'    => array( 'layout' => 'banner' ),
		);

		Classic_Editor::save( $this->popup, get_post( $this->popup ) );

		$this->assertSame(
			$before,
			get_post_meta( $this->popup, Meta::CONDITIONS, true ),
			'A save this screen did not render must not rewrite the panels it never showed.'
		);
	}

	/**
	 * A user who cannot edit the popup cannot write its settings.
	 *
	 * @return void
	 */
	public function test_a_user_without_the_capability_cannot_save() {
		$before = get_post_meta( $this->popup, Meta::DISPLAY, true );

		$_POST = array(
			Classic_Editor::NONCE => wp_create_nonce( Classic_Editor::NONCE ),
			'popkit_display'      => array( 'layout' => 'banner' ),
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		Classic_Editor::save( $this->popup, get_post( $this->popup ) );

		$this->assertSame(
			$before,
			get_post_meta( $this->popup, Meta::DISPLAY, true ),
			'A valid nonce is not authorization.'
		);
	}

	/**
	 * The save handler and the meta boxes are actually hooked.
	 *
	 * Every other test here calls the class directly and would pass on a class
	 * nothing boots.
	 *
	 * @return void
	 */
	public function test_the_classic_surface_is_hooked_when_it_is_the_chosen_one() {
		Classic_Editor::init();

		$this->assertNotFalse(
			has_action( 'add_meta_boxes_' . Post_Type::POST_TYPE, array( Classic_Editor::class, 'register_meta_boxes' ) ),
			'The meta boxes must be registered, or the screen is empty again.'
		);
		$this->assertNotFalse(
			has_action( 'save_post_' . Post_Type::POST_TYPE, array( Classic_Editor::class, 'save' ) ),
			'And the save handler, or nothing an author types is kept.'
		);
	}

	/**
	 * Submitted strings become the types the field schemas declare.
	 *
	 * @return void
	 */
	public function test_values_are_coerced_to_their_declared_types() {
		$coerced = Classic_Fields::coerce(
			array(
				'count'   => '12',
				'ratio'   => '0.5',
				'on'      => '1',
				'off'     => '0',
				'ids'     => '4, 8, ,15',
				'names'   => array( 'a', '', 'b' ),
				'ignored' => 'not in the schema',
			),
			array(
				'count' => array( 'type' => 'integer' ),
				'ratio' => array( 'type' => 'number' ),
				'on'    => array( 'type' => 'boolean' ),
				'off'   => array( 'type' => 'boolean' ),
				'ids'   => array(
					'type'  => 'array',
					'items' => 'integer',
				),
				'names' => array(
					'type'  => 'array',
					'items' => 'string',
				),
			)
		);

		$this->assertSame( 12, $coerced['count'] );
		$this->assertSame( 0.5, $coerced['ratio'] );
		$this->assertTrue( $coerced['on'] );
		$this->assertFalse( $coerced['off'] );
		$this->assertSame( array( 4, 8, 15 ), $coerced['ids'], 'Blank entries in a list are a typo, not an empty item.' );
		$this->assertSame( array( 'a', 'b' ), $coerced['names'] );
		$this->assertArrayNotHasKey( 'ignored', $coerced, 'The schema defines the shape; the form does not.' );
	}

	/**
	 * The targeting box renders a usable form, driven by the registry.
	 *
	 * The registry invariant, on this screen: the condition list is not written
	 * out anywhere in `Classic_Editor`, so a condition registered by a third party
	 * appears here without either file knowing about the other.
	 *
	 * @return void
	 */
	public function test_the_targeting_box_renders_the_registered_conditions() {
		$html = $this->render( 'render_targeting' );

		$this->assertStringContainsString( Classic_Editor::NONCE, $html, 'Without a nonce field, no save is ever accepted.' );
		$this->assertStringContainsString( 'value="url_path"', $html, 'Conditions come from the registry.' );
		$this->assertStringContainsString( 'value="post_type"', $html );
		$this->assertStringContainsString( 'data-popkit-template="rule"', $html, 'The repeater needs a template to clone.' );
		$this->assertStringContainsString( 'data-popkit-add-group', $html );
	}

	/**
	 * A stored rule renders with its own values, and its condition selected.
	 *
	 * @return void
	 */
	public function test_a_stored_rule_renders_its_values() {
		update_post_meta(
			$this->popup,
			Meta::CONDITIONS,
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => 'url_path',
								'negate' => false,
								'values' => array(
									'match' => 'contains',
									'value' => '/offers/',
								),
							),
						),
					),
				),
			)
		);

		$html = $this->render( 'render_targeting' );

		$this->assertStringContainsString( 'value="/offers/"', $html, 'A saved rule has to come back into its own control.' );
		$this->assertStringContainsString( 'value="contains" selected', $html );
	}

	/**
	 * The appearance box offers the customization controls.
	 *
	 * @return void
	 */
	public function test_the_appearance_box_offers_customization() {
		$html = $this->render( 'render_appearance' );

		$this->assertStringContainsString( 'popkit_display[custom_background]', $html );
		$this->assertStringContainsString( 'popkit_display[custom_font]', $html );
		$this->assertStringContainsString( 'value="lower_third"', $html, 'A banner can be placed in the lower third from this screen.' );
	}

	/**
	 * Nothing renders and nothing enqueues while the block editor owns popups.
	 *
	 * Both surfaces attach their hooks at boot, so the guard inside each callback
	 * is the only thing keeping them from running together.
	 *
	 * @return void
	 */
	public function test_the_classic_boxes_stand_down_for_the_block_editor() {
		remove_filter( 'popkit_use_block_editor', '__return_false' );

		$GLOBALS['wp_meta_boxes'] = array();

		Classic_Editor::register_meta_boxes();

		$this->assertSame(
			array(),
			$GLOBALS['wp_meta_boxes'],
			'The classic boxes must not appear alongside the block editor sidebar.'
		);
	}

	/**
	 * Returns the site to "PopKit has no opinion", as set_up() left it otherwise.
	 *
	 * @return void
	 */
	private function forget_override() {
		remove_filter( 'popkit_use_block_editor', '__return_false' );
		remove_filter( 'use_block_editor_for_post', array( Editor_Mode::class, 'filter_post' ), 1000 );
		remove_filter( 'use_block_editor_for_post_type', array( Editor_Mode::class, 'filter_post_type' ), 1000 );
		remove_filter( 'classic_editor_enabled_editors_for_post_type', array( Editor_Mode::class, 'filter_classic_editor' ), 1000 );
	}

	/**
	 * Captures a meta box's output.
	 *
	 * @param string $method Renderer to call.
	 * @return string Rendered markup.
	 */
	private function render( $method ) {
		ob_start();
		Classic_Editor::$method( get_post( $this->popup ) );

		return (string) ob_get_clean();
	}

	/**
	 * Submits a form the way the meta boxes would, with a valid nonce.
	 *
	 * @param array<string, mixed> $fields Panel input.
	 * @return void
	 */
	private function submit( array $fields ) {
		$_POST = array_merge(
			array( Classic_Editor::NONCE => wp_create_nonce( Classic_Editor::NONCE ) ),
			$fields
		);

		Classic_Editor::save( $this->popup, get_post( $this->popup ) );
	}
}
