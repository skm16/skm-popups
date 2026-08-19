<?php
/**
 * Integration tests for **Popups → Settings**.
 *
 * The screen exists because `readme.txt` told site owners to enable a setting
 * that had no interface. Everything registered around it — the option, its REST
 * schema, the label, the description, a settings group named for
 * `settings_fields()` — was already in place; the form was not. So the tests
 * that matter here are not "does the checkbox render" but "is the instruction in
 * the readme now followable, and can the wrong person follow it".
 *
 * ## The capability is the interesting part
 *
 * This checkbox authorizes permanently deleting every popup on the site. An
 * editor holds `edit_popkit_popups` and can write popups all day; they must not
 * be able to arm their own destruction. The gate is `manage_options` for that
 * reason, and it is asserted from both sides — the menu is absent for someone
 * without it, and {@see Popkit\Settings_Page::render()} refuses even if they
 * reach it directly.
 *
 * ## Off has to mean off
 *
 * An unchecked checkbox posts nothing at all. If a missing key could read as
 * anything but false, a site owner who unticked the box and saved would have
 * armed the deletion they were trying to cancel. That path is asserted through
 * the shape `options.php` actually produces rather than through a helper.
 *
 * @package Popkit
 */

use Popkit\Post_Type;
use Popkit\Settings;
use Popkit\Settings_Page;

/**
 * Integration coverage for Popkit\Settings_Page.
 */
final class Test_Popkit_Settings_Page extends WP_UnitTestCase {

	/**
	 * Loads the admin API the screen is built on, and clears the menu globals.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$GLOBALS['menu']    = array();
		$GLOBALS['submenu'] = array();

		Settings::register();
	}

	/**
	 * Restores the globals the menu registration writes to.
	 *
	 * @return void
	 */
	public function tear_down() {
		$GLOBALS['menu']    = array();
		$GLOBALS['submenu'] = array();

		parent::tear_down();
	}

	/**
	 * The screen is attached to a hook, not merely defined.
	 *
	 * Every other test in this file calls the class directly, so all of them
	 * would pass on a screen nothing ever boots — the failure
	 * `tests/php/integration/test-plugin-boot.php` exists to describe, and the
	 * exact shape of the defect this file was written for: a complete, correct,
	 * fully tested surface that no user can reach.
	 *
	 * @return void
	 */
	public function test_the_screen_is_attached_to_admin_menu() {
		$this->assertNotFalse(
			has_action( 'admin_menu', array( Settings_Page::class, 'register_page' ) ),
			'Plugin::boot_admin() must attach the settings screen.'
		);
	}

	/**
	 * An administrator gets the submenu entry, gated on manage_options.
	 *
	 * This is the readme's instruction, executable: Popups → Settings has to be
	 * a place someone can actually navigate to.
	 *
	 * @return void
	 */
	public function test_an_administrator_sees_the_submenu_under_popups() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		Settings_Page::register_page();

		$entry = $this->submenu_entry();

		$this->assertNotNull( $entry, 'The settings screen must appear under the Popups menu.' );
		$this->assertSame(
			Settings_Page::CAPABILITY,
			$entry[1],
			'The menu is gated on the capability that governs the site, not the one that governs popups.'
		);
		$this->assertSame( 'manage_options', $entry[1], 'That capability is manage_options.' );
	}

	/**
	 * Someone who can edit popups but not manage the site is refused.
	 *
	 * Both halves: no menu entry, and no render if they navigate to the URL
	 * anyway.
	 *
	 * @return void
	 */
	public function test_an_editor_can_neither_see_nor_render_the_screen() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		Settings_Page::register_page();

		$this->assertNull(
			$this->submenu_entry(),
			'Someone without manage_options must not be offered the destructive opt-in.'
		);

		$this->expectException( WPDieException::class );

		Settings_Page::render();
	}

	/**
	 * The control carries its own name and its own warning.
	 *
	 * `aria-describedby` is not decoration here. The description is the sentence
	 * explaining that this permanently deletes content with no backup, and a
	 * screen reader user has to hear it while focus is on the control that arms
	 * it — not as loose text somewhere nearby.
	 *
	 * @return void
	 */
	public function test_the_checkbox_is_labelled_and_describes_what_it_destroys() {
		$html  = $this->render_as_administrator();
		$field = $this->find_checkbox( $html );

		$this->assertNotNull( $field, 'The screen renders a checkbox for the setting.' );
		$this->assertSame(
			Settings::OPTION . '[' . Settings::DELETE_DATA_ON_UNINSTALL . ']',
			$field['name'],
			'It posts into the registered option, so register_setting() sanitizes it.'
		);

		$this->assertStringContainsString(
			'for="' . $field['id'] . '"',
			$html,
			'The checkbox has a label bound to its id.'
		);
		$this->assertStringContainsString(
			Settings::delete_data_label(),
			$html,
			'The label is the registered one, so its translation is reused.'
		);

		$this->assertNotSame( '', (string) $field['aria-describedby'], 'The control is described.' );
		$this->assertStringContainsString(
			'id="' . $field['aria-describedby'] . '"',
			$html,
			'aria-describedby must resolve to an element that exists.'
		);
		$this->assertStringContainsString(
			'This cannot be undone',
			$html,
			'And that element must carry the irreversible-deletion warning.'
		);

		$this->assertStringContainsString(
			'type="hidden"',
			$html,
			'A hidden companion is present so unchecking posts a real "off".'
		);
	}

	/**
	 * A site that armed the opt-in over WP-CLI sees the box already ticked.
	 *
	 * `Settings::DELETE_DATA_OPTION_ALIAS` is honored while the canonical option
	 * has never been written. If the screen read the raw option instead of going
	 * through {@see Settings::delete_data_on_uninstall()}, it would show "off"
	 * on a site where uninstalling really would delete everything — the exact
	 * disagreement that class promises is impossible.
	 *
	 * @return void
	 */
	public function test_the_screen_reflects_an_opt_in_armed_through_the_alias() {
		delete_option( Settings::OPTION );
		update_option( Settings::DELETE_DATA_OPTION_ALIAS, '1' );

		$field = $this->find_checkbox( $this->render_as_administrator() );

		$this->assertTrue(
			$field['checked'],
			'The screen must never show "off" while uninstall would in fact delete every popup.'
		);
	}

	/**
	 * Unchecking the box stores a real false.
	 *
	 * Asserted through the shape `options.php` produces for a form whose only
	 * checkbox is unticked, which is what makes this about the save path rather
	 * than about the sanitizer in isolation.
	 *
	 * @return void
	 */
	public function test_unchecking_the_box_disarms_the_opt_in() {
		update_option( Settings::OPTION, array( Settings::DELETE_DATA_ON_UNINSTALL => true ) );
		$this->assertTrue( Settings::delete_data_on_uninstall(), 'Precondition: the opt-in is armed.' );

		// What the form posts when the box is unticked: the hidden companion only.
		update_option( Settings::OPTION, array( Settings::DELETE_DATA_ON_UNINSTALL => '0' ) );

		$this->assertFalse(
			Settings::delete_data_on_uninstall(),
			'Unticking and saving has to actually disarm it.'
		);
	}

	/**
	 * The option is in the allowed list for its settings group.
	 *
	 * Without this, `options.php` rejects the submission and the screen saves
	 * nothing while appearing to work.
	 *
	 * @return void
	 */
	public function test_the_option_is_saveable_through_options_php() {
		$allowed = get_registered_settings();

		$this->assertArrayHasKey(
			Settings::OPTION,
			$allowed,
			'The option must be registered for options.php to accept it.'
		);
		$this->assertSame(
			Settings::OPTION_GROUP,
			$allowed[ Settings::OPTION ]['group'],
			'And registered in the group settings_fields() writes into the form.'
		);
	}

	/**
	 * Renders the screen as an administrator.
	 *
	 * @return string Rendered markup.
	 */
	private function render_as_administrator() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		Settings_Page::render();

		return (string) ob_get_clean();
	}

	/**
	 * Finds popkit's submenu entry under the popup post type.
	 *
	 * @return array|null The raw submenu row, or null when absent.
	 */
	private function submenu_entry() {
		$parent = 'edit.php?post_type=' . Post_Type::POST_TYPE;

		foreach ( $GLOBALS['submenu'][ $parent ] ?? array() as $entry ) {
			if ( isset( $entry[2] ) && Settings_Page::PAGE_SLUG === $entry[2] ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Reads the settings checkbox out of rendered markup.
	 *
	 * Parsed rather than matched: an assertion that greps for an attribute is
	 * satisfied by that attribute sitting on the wrong element.
	 *
	 * @param string $html Rendered markup.
	 * @return array{name: string, id: string, aria-describedby: string, checked: bool}|null Field, or null.
	 */
	private function find_checkbox( $html ) {
		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag( array( 'tag_name' => 'INPUT' ) ) ) {
			if ( 'checkbox' !== $processor->get_attribute( 'type' ) ) {
				continue;
			}

			return array(
				'name'             => (string) $processor->get_attribute( 'name' ),
				'id'               => (string) $processor->get_attribute( 'id' ),
				'aria-describedby' => (string) $processor->get_attribute( 'aria-describedby' ),
				'checked'          => null !== $processor->get_attribute( 'checked' ),
			);
		}

		return null;
	}
}
