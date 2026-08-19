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
 * Two things fix it and both are tested here: popups opt back into the block
 * editor by default even under Classic Editor ({@see Popkit\Editor_Mode}), and a
 * site that declines that gets real meta boxes ({@see Popkit\Classic_Editor}).
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
		 * Every callback in Classic_Editor asks Editor_Mode first and returns if
		 * the block editor is in use — which is the default. So a test of the
		 * classic surface has to put the site into the state where that surface is
		 * the one that runs, exactly as a real site would.
		 *
		 * This is not test scaffolding working around the code: without it these
		 * tests pass vacuously, asserting that nothing happened while believing
		 * they asserted that the right thing happened.
		 */
		add_filter( 'popkit_use_block_editor', '__return_false' );

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
		Capabilities::remove();

		parent::tear_down();
	}

	/**
	 * Popups use the block editor by default, whatever the site chose.
	 *
	 * The value handed in is `false` — what the Classic Editor plugin passes when
	 * it is replacing the block editor site-wide. Popups override it; nothing
	 * else does.
	 *
	 * @return void
	 */
	public function test_popups_opt_back_into_the_block_editor() {
		// set_up() puts the site in classic mode for the rest of this file. This
		// one test is about the default, so it takes that back off.
		remove_filter( 'popkit_use_block_editor', '__return_false' );

		$this->assertTrue(
			Editor_Mode::filter_post_type( false, Post_Type::POST_TYPE ),
			'A popup is authored as blocks, so it opts back into the block editor.'
		);
		$this->assertFalse(
			Editor_Mode::filter_post_type( false, 'page' ),
			"Another post type keeps the site's own answer, untouched."
		);
		$this->assertTrue(
			Editor_Mode::filter_post_type( true, 'page' ),
			'In both directions.'
		);
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
	 * their targeting. Writing defaults on an unrecognised save would look
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
	 * The appearance box offers the customisation controls.
	 *
	 * @return void
	 */
	public function test_the_appearance_box_offers_customisation() {
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
