<?php
/**
 * Human-readable names for the values a popup stores.
 *
 * @package Popkit
 * @since   0.2.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * The label for every stored token an author is ever shown a choice of.
 *
 * {@see Meta} decides what a popup may store. This decides what those values are
 * called on screen, and it is the only place either editor reads a label from.
 *
 * ## Why the two are separate
 *
 * A stored value is a permanent identifier: `once_per_session` is written into
 * post meta, read by `src/frontend/frequency.js`, and compared byte for byte.
 * Renaming one is a migration. A label is prose — it gets rewritten when it turns
 * out to read badly, translated into thirty languages, and adjusted when somebody
 * points out that "Inline banner" and "notification bar" are the same thing said
 * two ways.
 *
 * Keeping them in one array would mean every wording change touching the file
 * that defines what is storable, and would make it tempting to solve an awkward
 * label by renaming the value instead — which is the one change that costs a
 * migration.
 *
 * ## Why not in JavaScript
 *
 * These labels started in `src/editor/panels/display.js`, which worked for
 * exactly as long as there was one editor. The classic screen renders from PHP
 * and had no way to reach them, so it fell back to printing the stored token:
 * an author choosing how often a popup may show read `once_per_session` and
 * `suppress_forever` where the block editor showed sentences.
 *
 * So PHP owns them and {@see Rest_Schema::get_registry()} hands them to the block
 * editor alongside the condition and trigger vocabulary it already fetches. That
 * request is not an added dependency: `src/editor/index.js` renders an error
 * notice and no panels at all when it fails, so the appearance panel already
 * could not draw itself without it.
 *
 * Labels a *registration* supplies travel a different road — a condition declares
 * `enum_labels` in its field schema and {@see Condition} validates it. This class
 * is only for the vocabulary popkit itself defines.
 *
 * ## Coverage is a tested invariant, not a convention
 *
 * `tests/php/unit/test-vocabulary.php` asserts that every map here has exactly
 * the members of the constant it labels — no gaps and no strays. Without that,
 * adding a theme or a frequency mode leaves a select showing one raw token among
 * a list of sentences, which reads as a bug in that option rather than a missing
 * label, and nothing fails until somebody notices.
 *
 * @since 0.2.0
 */
final class Vocabulary {

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	/**
	 * Every label map, in the shape the block editor reads.
	 *
	 * Keyed by the thing being labeled rather than by meta key, because
	 * `positions` is nested by layout and `scales` is nested by field, and
	 * flattening either would lose the structure the editor needs to pick the
	 * right subset.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> Label maps for the built-in vocabulary.
	 */
	public static function all(): array {
		return array(
			'layouts'    => self::layouts(),
			'themes'     => self::themes(),
			'sizes'      => self::sizes(),
			'positions'  => self::positions(),
			'animations' => self::animations(),
			'scales'     => self::scales(),
			'colors'     => self::colors(),
			'modes'      => self::frequency_modes(),
			'onConvert'  => self::frequency_on_convert(),
			'timezones'  => self::schedule_timezones(),
		);
	}

	/**
	 * Labels for `display.layout`.
	 *
	 * "Notification bar" rather than "Inline banner", which is what the block
	 * editor called it and what the stored token still says. The banner is fixed
	 * to the viewport, so nothing about it is inline; authors asking for this
	 * feature ask for a notification bar, and the position select already used
	 * that name. The stored value stays `banner` — renaming it would be a
	 * migration to fix a word.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function layouts(): array {
		return array(
			'modal'  => __( 'Modal dialog', 'popkit' ),
			'banner' => __( 'Notification bar', 'popkit' ),
		);
	}

	/**
	 * Labels for `display.theme`.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function themes(): array {
		return array(
			'inherit'  => __( 'Inherit from the site', 'popkit' ),
			'light'    => __( 'Light', 'popkit' ),
			'dark'     => __( 'Dark', 'popkit' ),
			'bordered' => __( 'Bordered', 'popkit' ),
		);
	}

	/**
	 * Labels for `display.size`.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function sizes(): array {
		return array(
			'small'  => __( 'Small', 'popkit' ),
			'medium' => __( 'Medium', 'popkit' ),
			'large'  => __( 'Large', 'popkit' ),
			'full'   => __( 'Full width', 'popkit' ),
		);
	}

	/**
	 * Labels for `display.position`, nested by the layout they belong to.
	 *
	 * `top` appears under both layouts and is deliberately named differently in
	 * each. A modal placed at the top sits near the top of the viewport with the
	 * page visible around it; a notification bar at the top is flush against the
	 * edge and spans the width. "Top" alone would be accurate for neither and
	 * would leave the two indistinguishable in a flat list.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, string>> Layout => value => label.
	 */
	public static function positions(): array {
		return array(
			'modal'  => array(
				'center' => __( 'Centered', 'popkit' ),
				'top'    => __( 'Near the top', 'popkit' ),
			),
			'banner' => array(
				'top'         => __( 'Top of the page', 'popkit' ),
				'bottom'      => __( 'Bottom of the page', 'popkit' ),
				'lower_third' => __( 'Lower third of the screen', 'popkit' ),
			),
		);
	}

	/**
	 * Labels for the layout a set of positions belongs to.
	 *
	 * Used as `optgroup` labels on the classic screen, where every position is
	 * offered at once and the group is what tells the author which layout a
	 * position applies to.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Layout => label.
	 */
	public static function position_groups(): array {
		return array(
			'modal'  => __( 'Modal positions', 'popkit' ),
			'banner' => __( 'Notification bar positions', 'popkit' ),
		);
	}

	/**
	 * Labels for `display.animation`.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function animations(): array {
		return array(
			'none'  => __( 'None', 'popkit' ),
			'fade'  => __( 'Fade in', 'popkit' ),
			'slide' => __( 'Slide in', 'popkit' ),
		);
	}

	/**
	 * Labels for each scale in {@see Meta::DISPLAY_SCALE_FIELDS}.
	 *
	 * `inherit` is named for what it does in that particular field rather than
	 * with one shared word. "Theme default" is right for a border, where the
	 * theme genuinely specifies one; "Follow the page" is right for the font,
	 * where the popup takes whatever the surrounding page is already using and no
	 * popkit theme has an opinion.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, string>> Field => value => label.
	 */
	public static function scales(): array {
		return array(
			'custom_border_width' => array(
				'inherit' => __( 'Theme default', 'popkit' ),
				'none'    => __( 'No border', 'popkit' ),
				'thin'    => __( 'Thin', 'popkit' ),
				'medium'  => __( 'Medium', 'popkit' ),
				'thick'   => __( 'Thick', 'popkit' ),
			),
			'custom_radius'       => array(
				'inherit' => __( 'Theme default', 'popkit' ),
				'none'    => __( 'Square corners', 'popkit' ),
				'small'   => __( 'Slightly rounded', 'popkit' ),
				'medium'  => __( 'Rounded', 'popkit' ),
				'large'   => __( 'Very rounded', 'popkit' ),
			),
			'custom_font'         => array(
				'inherit' => __( 'Follow the page', 'popkit' ),
				'system'  => __( 'System font', 'popkit' ),
				'serif'   => __( 'Serif', 'popkit' ),
				'sans'    => __( 'Sans serif', 'popkit' ),
				'mono'    => __( 'Monospace', 'popkit' ),
			),
			'custom_font_size'    => array(
				'inherit' => __( 'Follow the page', 'popkit' ),
				'small'   => __( 'Small', 'popkit' ),
				'medium'  => __( 'Medium', 'popkit' ),
				'large'   => __( 'Large', 'popkit' ),
			),
		);
	}

	/**
	 * Labels for the four color overrides in {@see Meta::DISPLAY_COLOR_FIELDS}.
	 *
	 * Short names, because each control carries its own "leave blank to keep the
	 * theme" hint underneath rather than folding that sentence into the label. A
	 * label is what a screen reader announces on focus and what the color picker
	 * button is named by; a sentence there is read out in full every time.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Field => label.
	 */
	public static function colors(): array {
		return array(
			'custom_background'   => __( 'Background', 'popkit' ),
			'custom_text'         => __( 'Text', 'popkit' ),
			'custom_accent'       => __( 'Links', 'popkit' ),
			'custom_border_color' => __( 'Border', 'popkit' ),
		);
	}

	/**
	 * Labels for `frequency.mode`.
	 *
	 * `once_per_days` reads as an incomplete sentence on its own — the number of
	 * days lives in a separate field — so the label names the field that finishes
	 * it rather than leaving the author to guess where the count comes from.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function frequency_modes(): array {
		return array(
			'always'           => __( 'Every time they are eligible', 'popkit' ),
			'once_per_session' => __( 'Once per browsing session', 'popkit' ),
			'once_per_days'    => __( 'Once every few days, set below', 'popkit' ),
			'once_ever'        => __( 'Once, and never again', 'popkit' ),
		);
	}

	/**
	 * Labels for `frequency.on_convert`.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function frequency_on_convert(): array {
		return array(
			'suppress_forever' => __( 'Never show it to them again', 'popkit' ),
			'none'             => __( 'Keep applying the setting above', 'popkit' ),
		);
	}

	/**
	 * Labels for `schedule.timezone`.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Value => label.
	 */
	public static function schedule_timezones(): array {
		return array(
			'site'    => __( 'The site’s timezone', 'popkit' ),
			'visitor' => __( 'Each visitor’s own timezone', 'popkit' ),
		);
	}

	/**
	 * Labels for the constrained URL match language, {@see Url_Matcher::modes()}.
	 *
	 * Declared as a condition's `enum_labels` by both `url_path` and `referrer`,
	 * so it is a method rather than a literal in either: one match language, one
	 * set of names for its modes, wherever it is offered.
	 *
	 * `glob` is labeled by what it is for rather than by its name. "Glob" is a
	 * word for people who already know it, and an author who picks it without
	 * knowing writes a literal asterisk into a path and gets a rule that never
	 * matches with no error to explain why — which is the same failure the
	 * `url-match` control's help text exists to head off.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Mode => label.
	 */
	public static function url_match_modes(): array {
		return array(
			'exact'    => __( 'Is exactly', 'popkit' ),
			'prefix'   => __( 'Starts with', 'popkit' ),
			'contains' => __( 'Contains', 'popkit' ),
			'glob'     => __( 'Matches a wildcard pattern', 'popkit' ),
		);
	}

	/**
	 * Looks one value up in a label map, falling back to the value itself.
	 *
	 * The fallback is what makes an unlabeled vocabulary degrade to what the
	 * editors did before this class existed, rather than to a blank option. A
	 * blank option is unselectable in practice and unreadable to a screen reader;
	 * a raw token is ugly and works.
	 *
	 * Compared on string casts, because PHP turns a numeric string array key into
	 * an integer and an enum member may be either.
	 *
	 * @since 0.2.0
	 *
	 * @param array<array-key, mixed> $labels Label map.
	 * @param string|int              $value  Stored value.
	 * @return string Label, or the value itself when it has none.
	 */
	public static function label( array $labels, $value ): string {
		foreach ( $labels as $candidate => $label ) {
			if ( (string) $candidate === (string) $value && is_string( $label ) && '' !== $label ) {
				return $label;
			}
		}

		return (string) $value;
	}
}
