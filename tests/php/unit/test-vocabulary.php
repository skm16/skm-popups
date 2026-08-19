<?php
/**
 * Unit tests for Popkit\Vocabulary.
 *
 * `Meta` decides what a popup may store; `Vocabulary` decides what those values
 * are called on screen. Two files, and therefore two things that can disagree.
 *
 * The disagreement is not hypothetical and is not loud. Adding a theme to
 * `Meta::DISPLAY_THEMES` without labeling it leaves a select whose options read
 * "Inherit from the site", "Light", "Dark" and then `bordered` — which looks like
 * a bug in that one option rather than a missing label, and which nothing
 * reports. Removing a value leaves a label that silently stops applying. Both
 * pass every other test in this suite.
 *
 * So the coverage is asserted in both directions, from a table pairing each map
 * with the constant it labels. Adding a vocabulary means adding one row here; a
 * vocabulary with no row is itself caught, because {@see Vocabulary::all()} is
 * compared against the table.
 *
 * These are also the strings an author reads, so they are checked for being
 * strings that a human could read: non-empty, not merely the stored token
 * repeated back, and — for the two vocabularies whose values differ per layout —
 * actually distinct where the same token means different things.
 *
 * @package Popkit
 */

declare( strict_types = 1 );

use Popkit\Meta;
use Popkit\Url_Matcher;
use Popkit\Vocabulary;

/**
 * Unit coverage for Popkit\Vocabulary.
 */
final class Test_Popkit_Vocabulary extends PHPUnit\Framework\TestCase {

	/**
	 * Every flat label map, paired with the permitted values it must cover.
	 *
	 * @return array<string, array{0: array<string, string>, 1: string[]}>
	 */
	public static function flat_maps(): array {
		return array(
			'layouts'              => array( Vocabulary::layouts(), Meta::DISPLAY_LAYOUTS ),
			'themes'               => array( Vocabulary::themes(), Meta::DISPLAY_THEMES ),
			'sizes'                => array( Vocabulary::sizes(), Meta::DISPLAY_SIZES ),
			'animations'           => array( Vocabulary::animations(), Meta::DISPLAY_ANIMATIONS ),
			'colors'               => array( Vocabulary::colors(), Meta::DISPLAY_COLOR_FIELDS ),
			'frequency modes'      => array( Vocabulary::frequency_modes(), Meta::FREQUENCY_MODES ),
			'frequency on_convert' => array( Vocabulary::frequency_on_convert(), Meta::FREQUENCY_ON_CONVERT ),
			'schedule timezones'   => array( Vocabulary::schedule_timezones(), Meta::SCHEDULE_TIMEZONES ),
			'url match modes'      => array( Vocabulary::url_match_modes(), Url_Matcher::modes() ),
			'position groups'      => array( Vocabulary::position_groups(), array_keys( Meta::DISPLAY_POSITIONS ) ),
		);
	}

	/**
	 * Every label map is exactly the vocabulary it labels — no gaps, no strays.
	 *
	 * Order is asserted too, not just membership. The block editor builds its
	 * selects by enumerating these maps, so the key order here is the order the
	 * options appear in; a map that happened to list `dark` before `light` would
	 * silently reorder a control.
	 *
	 * @dataProvider flat_maps
	 *
	 * @param array<string, string> $labels Label map.
	 * @param string[]              $values Permitted values.
	 * @return void
	 */
	public function test_a_label_map_covers_its_vocabulary_exactly( array $labels, array $values ) {
		$this->assertSame(
			$values,
			array_keys( $labels ),
			'A label map must hold exactly the permitted values, in the same order. A missing entry renders that one option as its stored token beside options that read as prose; a stray entry is a label that never applies. The order is the order the editor offers the choices in.'
		);
	}

	/**
	 * Every label is prose an author could read.
	 *
	 * @dataProvider flat_maps
	 *
	 * @param array<string, string> $labels Label map.
	 * @param string[]              $values Permitted values.
	 * @return void
	 */
	public function test_every_label_is_readable( array $labels, array $values ) {
		unset( $values );

		foreach ( $labels as $value => $label ) {
			$this->assertIsString( $label, sprintf( 'The label for "%s" must be a string.', $value ) );
			$this->assertNotSame( '', trim( $label ), sprintf( 'The label for "%s" is blank. A blank option is unreadable to a screen reader and unpickable in practice.', $value ) );
			$this->assertNotSame(
				(string) $value,
				$label,
				sprintf( 'The label for "%s" is the stored token repeated back, which is what having no label at all already does. Either write a name for it or drop the entry.', $value )
			);
		}
	}

	/**
	 * Positions are labeled per layout, because the same token means two things.
	 *
	 * `top` is permitted under both layouts and is a different place in each: a
	 * modal near the top of the viewport with the page visible around it, or a
	 * bar flush against the edge spanning the width. A flat map could hold only
	 * one of those.
	 *
	 * @return void
	 */
	public function test_positions_are_labeled_per_layout() {
		$positions = Vocabulary::positions();

		$this->assertSame(
			array_keys( Meta::DISPLAY_POSITIONS ),
			array_keys( $positions ),
			'Every layout that permits positions must label them.'
		);

		foreach ( Meta::DISPLAY_POSITIONS as $layout => $permitted ) {
			$this->assertSame(
				$permitted,
				array_keys( $positions[ $layout ] ),
				sprintf( 'The %s layout must label exactly the positions it permits, in the order it permits them.', $layout )
			);
		}

		$this->assertNotSame(
			$positions['modal']['top'],
			$positions['banner']['top'],
			'A modal at the top and a notification bar at the top are different places. Labeling them identically is the reason the map is nested by layout in the first place.'
		);
	}

	/**
	 * Each appearance scale labels exactly the steps it permits.
	 *
	 * @return void
	 */
	public function test_every_scale_labels_its_own_steps() {
		$scales = Vocabulary::scales();

		$this->assertSame(
			array_keys( Meta::DISPLAY_SCALE_FIELDS ),
			array_keys( $scales ),
			'Every appearance scale must have a label map.'
		);

		foreach ( Meta::DISPLAY_SCALE_FIELDS as $field => $steps ) {
			$this->assertSame(
				$steps,
				array_keys( $scales[ $field ] ),
				sprintf( 'The %s scale must label exactly the steps it permits, in order.', $field )
			);
		}
	}

	/**
	 * `inherit` is named for what it does in each field rather than with one word.
	 *
	 * Every scale has an `inherit` step and they do not mean the same thing. A
	 * border's `inherit` takes a value the popkit theme genuinely specifies; the
	 * font's takes whatever the surrounding page is already using, which no
	 * popkit theme has an opinion about. Naming both "Theme default" would be
	 * wrong for the font, and naming both "Follow the page" wrong for the border.
	 *
	 * @return void
	 */
	public function test_inherit_is_named_for_what_it_does() {
		$labels = array();

		foreach ( Vocabulary::scales() as $field => $steps ) {
			$this->assertArrayHasKey( 'inherit', $steps, sprintf( 'The %s scale must label its inherit step.', $field ) );

			$labels[ $steps['inherit'] ] = true;
		}

		$this->assertGreaterThan(
			1,
			count( $labels ),
			'Every scale labels its inherit step with the same words, which means at least one of them is describing something it does not do.'
		);
	}

	/**
	 * The payload handed to the block editor carries every map.
	 *
	 * @return void
	 */
	public function test_all_carries_every_map() {
		$all = Vocabulary::all();

		foreach ( array( 'layouts', 'themes', 'sizes', 'positions', 'animations', 'scales', 'colors', 'modes', 'onConvert', 'timezones' ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$all,
				sprintf( 'The registry payload must carry "%s". The block editor renders that control from it and would otherwise offer an empty select.', $key )
			);
			$this->assertNotEmpty( $all[ $key ], sprintf( 'The "%s" map reached the editor empty.', $key ) );
		}
	}

	/**
	 * An unlabeled value falls back to itself rather than to nothing.
	 *
	 * This is what a third-party condition that declares no `enum_labels` gets,
	 * and it has to be the stored token rather than an empty string: an empty
	 * option is unselectable in practice and silent to a screen reader, where a
	 * raw token is ugly and works.
	 *
	 * @return void
	 */
	public function test_an_unlabeled_value_falls_back_to_itself() {
		$this->assertSame( 'gold', Vocabulary::label( array(), 'gold' ) );
		$this->assertSame( 'gold', Vocabulary::label( array( 'silver' => 'Silver' ), 'gold' ) );
		$this->assertSame( 'Silver', Vocabulary::label( array( 'silver' => 'Silver' ), 'silver' ) );
	}

	/**
	 * A blank or non-string label is treated as absent.
	 *
	 * @return void
	 */
	public function test_a_blank_label_is_treated_as_absent() {
		$this->assertSame( 'gold', Vocabulary::label( array( 'gold' => '' ), 'gold' ) );
		$this->assertSame( 'gold', Vocabulary::label( array( 'gold' => null ), 'gold' ) );
		$this->assertSame( 'gold', Vocabulary::label( array( 'gold' => array( 'Gold' ) ), 'gold' ) );
	}

	/**
	 * Lookup survives PHP turning a numeric string key into an integer.
	 *
	 * A vocabulary keyed by numbers is not one popkit ships, but an enum may hold
	 * integers and a registration may label them. `array( '1' => 'Monday' )` has
	 * an integer key by the time it is read, so a strict comparison against the
	 * string `'1'` would find nothing and the author would see a bare `1`.
	 *
	 * @return void
	 */
	public function test_lookup_survives_numeric_key_coercion() {
		$labels = array(
			'1' => 'Monday',
			'2' => 'Tuesday',
		);

		$this->assertSame( 'Monday', Vocabulary::label( $labels, 1 ) );
		$this->assertSame( 'Monday', Vocabulary::label( $labels, '1' ) );
		$this->assertSame( 'Tuesday', Vocabulary::label( $labels, 2 ) );
	}
}
