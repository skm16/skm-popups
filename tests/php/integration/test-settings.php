<?php
/**
 * Integration tests for the plugin settings option.
 *
 * One setting exists in v1 and it authorizes irreversible deletion, so the
 * assertions below are weighted accordingly.
 *
 * The registration test is not bookkeeping. `Popkit\Settings::register()` is
 * what installs the `sanitize_option_popkit_settings` filter; if `init()` is
 * never called from the boot sequence, `update_option( 'popkit_settings', … )`
 * stores whatever it is handed, unmodified. A REST write, a WP-CLI script or a
 * third-party plugin could then leave the string `'banana'` in
 * `delete_data_on_uninstall`, and every `if ( $value )` in the codebase would
 * read that as "yes, destroy the client's popups". A registration test that
 * looks like paperwork is the test that catches it.
 *
 * The default is asserted the honest way — with no option row present at all,
 * not with a row holding `false`. An implementation that reads
 * `get_option( $key, true )` passes the second and fails the first.
 *
 * @package Popkit
 */

use Popkit\Settings;

/**
 * Integration coverage for Popkit\Settings.
 */
final class Test_Popkit_Settings extends WP_UnitTestCase {

	/**
	 * The option holding every plugin setting.
	 *
	 * The literal name is used rather than the class constant: this is the row
	 * uninstall sweeps and the key the REST route exposes, so renaming it is a
	 * breaking change and should fail here.
	 *
	 * @var string
	 */
	private const OPTION = 'popkit_settings';

	/**
	 * The uninstall opt-in key inside the option.
	 *
	 * @var string
	 */
	private const SETTING = 'delete_data_on_uninstall';

	/**
	 * The standalone option name documented in docs/CLAUDE.md.
	 *
	 * @var string
	 */
	private const ALIAS_OPTION = 'popkit_delete_data_on_uninstall';

	/**
	 * A key the plugin does not know about.
	 *
	 * @var string
	 */
	private const UNKNOWN_KEY = 'delete_everything_immediately';

	/**
	 * Clears both storage locations so every test starts from "never written".
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( self::OPTION );
		delete_option( self::ALIAS_OPTION );
	}

	/**
	 * The option is registered with WordPress.
	 *
	 * Proves the boot sequence actually calls Settings::init(). Without it there
	 * is no sanitize callback, no default and no REST schema.
	 *
	 * @return void
	 */
	public function test_the_settings_option_is_registered() {
		$registered = get_registered_settings();

		$this->assertArrayHasKey(
			self::OPTION,
			$registered,
			'popkit_settings is not registered. Settings::init() has to run from the plugin boot sequence: register_setting() is what installs the sanitize callback, so without it update_option() stores whatever it is handed and an unrecognized truthy value would arm irreversible content deletion.'
		);

		$args = $registered[ self::OPTION ];

		$this->assertArrayHasKey( 'sanitize_callback', $args, 'The setting must be registered with a sanitize callback.' );
		$this->assertIsCallable( $args['sanitize_callback'], 'The registered sanitize callback is not callable, so WordPress will never run it.' );
		$this->assertArrayHasKey( 'default', $args, 'The setting must be registered with a default, or get_option() returns false rather than the settings shape.' );
		$this->assertSame(
			array( self::SETTING => false ),
			$args['default'],
			'The registered default must be the documented settings shape with the uninstall opt-in switched off.'
		);

		$this->assertNotFalse(
			has_filter( 'sanitize_option_' . self::OPTION ),
			'register_setting() installs the sanitize callback as a sanitize_option_ filter. Without that filter, update_option() writes the raw submitted value straight to the database.'
		);
	}

	/**
	 * With nothing stored, the uninstall opt-in reads false.
	 *
	 * @return void
	 */
	public function test_the_uninstall_opt_in_defaults_to_false() {
		$this->assertFalse(
			$this->raw_option_row_exists(),
			'Fixture: no popkit_settings row may exist, so the default is asserted against a genuinely absent option.'
		);

		$this->assertSame(
			array( self::SETTING => false ),
			get_option( self::OPTION ),
			'With no row stored, get_option() must return the registered defaults.'
		);
		$this->assertSame(
			array( self::SETTING => false ),
			Settings::all(),
			'Settings::all() must report the documented shape with the opt-in off.'
		);
		$this->assertFalse(
			Settings::get( self::SETTING ),
			'Settings::get() must report boolean false for the uninstall opt-in when nothing has been stored.'
		);
		$this->assertFalse(
			Settings::delete_data_on_uninstall(),
			'The uninstall opt-in defaults to false. Deleting a client\'s authored popups because a plugin was removed during routine maintenance is not acceptable behavior.'
		);
	}

	/**
	 * A value the sanitizer does not recognize becomes boolean false.
	 *
	 * The opt-in is armed first on purpose. Without that step the assertion could
	 * pass because update_option() declined to write a value identical to the
	 * default, rather than because anything was sanitized.
	 *
	 * @return void
	 */
	public function test_an_unrecognized_value_is_coerced_to_boolean_false() {
		update_option( self::OPTION, array( self::SETTING => true ) );

		$this->assertTrue(
			Settings::delete_data_on_uninstall(),
			'Fixture: the opt-in must be armed before the garbage write, so a stored false afterwards can only be the sanitizer\'s doing.'
		);

		update_option( self::OPTION, array( self::SETTING => 'banana' ) );

		$stored = get_option( self::OPTION );

		$this->assertIsArray( $stored, 'The option must remain an array.' );
		$this->assertArrayHasKey( self::SETTING, $stored, 'The uninstall opt-in key must survive sanitization.' );
		$this->assertNotSame( 'banana', $stored[ self::SETTING ], 'The raw submitted string was stored unmodified.' );
		$this->assertSame(
			false,
			$stored[ self::SETTING ],
			'An unrecognized value must become boolean false. A truthy string here reads as "yes" to every later check and arms the irreversible deletion of every popup on the site.'
		);
		$this->assertSame(
			array( self::SETTING => false ),
			$this->raw_option_value(),
			'The database row itself must hold a boolean. Coercing on read is not enough: uninstall.php runs with the plugin unloaded and reads the stored value directly.'
		);
		$this->assertFalse(
			Settings::delete_data_on_uninstall(),
			'The opt-in must read false after an unrecognized value was submitted.'
		);
	}

	/**
	 * Every accepted and rejected value round-trips to the right boolean.
	 *
	 * The opposite value is stored before each case so that a write always has
	 * to happen; a sanitizer that did nothing would leave the raw value behind
	 * and fail.
	 *
	 * Note the numeric rows. `filter_var()` accepts only `1`, `true`, `on` and
	 * `yes` as true, so `2` is false. That is the documented fail-closed
	 * behavior for a setting that authorizes destruction, not an oversight.
	 *
	 * @return void
	 */
	public function test_submitted_values_round_trip_to_the_expected_boolean() {
		$cases = array(
			array( '1', true ),
			array( 'true', true ),
			array( 'TRUE', true ),
			array( 'on', true ),
			array( 'yes', true ),
			array( 1, true ),
			array( true, true ),
			array( '0', false ),
			array( 'false', false ),
			array( 'off', false ),
			array( 'no', false ),
			array( '', false ),
			array( '2', false ),
			array( 'banana', false ),
			array( 0, false ),
			array( false, false ),
		);

		foreach ( $cases as $case ) {
			list( $submitted, $expected ) = $case;

			$label = (string) wp_json_encode( $submitted );

			update_option( self::OPTION, array( self::SETTING => ! $expected ) );

			$this->assertSame(
				! $expected,
				Settings::delete_data_on_uninstall(),
				'Fixture: the opposite value must be stored before each case so that a real write is required.'
			);

			update_option( self::OPTION, array( self::SETTING => $submitted ) );

			$this->assertSame(
				$expected,
				Settings::delete_data_on_uninstall(),
				sprintf(
					'Submitting %1$s must store boolean %2$s.',
					$label,
					$expected ? 'true' : 'false'
				)
			);
			$this->assertSame(
				array( self::SETTING => $expected ),
				$this->raw_option_value(),
				sprintf( 'Submitting %s must leave a boolean in the database row.', $label )
			);
		}
	}

	/**
	 * Keys the plugin does not know about never reach the option.
	 *
	 * `Popkit\Settings::defaults()` defines the shape. Anything else submitted
	 * alongside it is discarded rather than persisted, so a forged or malformed
	 * write cannot smuggle a key that a later version might start reading.
	 *
	 * @return void
	 */
	public function test_unknown_keys_are_not_promoted_into_the_option() {
		update_option(
			self::OPTION,
			array(
				self::SETTING     => true,
				self::UNKNOWN_KEY => true,
				'nested'          => array( 'anything' => 'at all' ),
			)
		);

		$stored = get_option( self::OPTION );

		$this->assertSame(
			array( self::SETTING => true ),
			$stored,
			'Only documented settings may be stored. An unknown key that survives the write is a key nothing validates, nothing displays and nothing removes.'
		);
		$this->assertArrayNotHasKey( self::UNKNOWN_KEY, $stored, 'An unknown key was written to the option.' );
		$this->assertSame(
			array( self::SETTING => true ),
			$this->raw_option_value(),
			'An unknown key reached the database row.'
		);
		$this->assertArrayNotHasKey( self::UNKNOWN_KEY, Settings::all(), 'An unknown key was returned by Settings::all().' );
		$this->assertNull(
			Settings::get( self::UNKNOWN_KEY ),
			'An unknown key must not be readable through Settings::get().'
		);
	}

	/**
	 * Settings::update() merges without promoting unknown keys.
	 *
	 * @return void
	 */
	public function test_update_merges_without_promoting_unknown_keys() {
		Settings::update(
			array(
				self::SETTING     => true,
				self::UNKNOWN_KEY => 'smuggled',
			)
		);

		$this->assertSame(
			array( self::SETTING => true ),
			get_option( self::OPTION ),
			'Settings::update() must write the known shape only.'
		);

		Settings::update( array( self::SETTING => 'banana' ) );

		$this->assertFalse(
			Settings::delete_data_on_uninstall(),
			'Settings::update() must sanitize what it is handed; an unrecognized value must not arm the uninstall opt-in.'
		);
	}

	/**
	 * A non-array value collapses to the defaults.
	 *
	 * @return void
	 */
	public function test_a_non_array_value_collapses_to_the_defaults() {
		update_option( self::OPTION, array( self::SETTING => true ) );

		$this->assertTrue( Settings::delete_data_on_uninstall(), 'Fixture: the opt-in must be armed first.' );

		update_option( self::OPTION, 'banana' );

		$this->assertSame(
			array( self::SETTING => false ),
			get_option( self::OPTION ),
			'A scalar written over the settings option must collapse to the defaults rather than being stored as a string. Every later read expects an array, and a truthy string would satisfy a naive opt-in check.'
		);
		$this->assertSame(
			array( self::SETTING => false ),
			$this->raw_option_value(),
			'A scalar reached the database row.'
		);
	}

	/**
	 * Reads the stored option straight out of the database.
	 *
	 * `get_option()` cannot answer this question: with the setting registered it
	 * returns the default for an absent row, so a read through the API cannot
	 * distinguish "stored false" from "never stored", and the object cache can
	 * hide what was actually written.
	 *
	 * @return mixed The unserialized option value, or null when no row exists.
	 */
	private function raw_option_value() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the row itself is the assertion; the options API would answer with a registered default and the cache would answer with something that was never written.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );

		if ( null === $value ) {
			return null;
		}

		return maybe_unserialize( $value );
	}

	/**
	 * Whether a row for the settings option exists at all.
	 *
	 * @return bool
	 */
	private function raw_option_row_exists() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The absence of the row is the assertion, and get_option() reports the registered default for a row that is not there.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );

		return 0 < $count;
	}
}
