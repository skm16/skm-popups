<?php
/**
 * Integration tests for per-popup appearance overrides.
 *
 * Colors, border, corner rounding, font and text size, plus the `lower_third`
 * banner position.
 *
 * ## Why the color tests are the important ones
 *
 * Everything else an author can customize is a step on a scale — `thick`,
 * `large` — so what reaches the page is a token this plugin defines and nothing
 * an author types is ever parsed as CSS. Colors cannot work that way: a color
 * has no scale to be a step on, so it travels as a literal and is printed into a
 * `style` attribute.
 *
 * That makes `Meta::sanitize_color()` the only thing standing between stored
 * post meta and a CSS injection, and `esc_attr()` does not help — it escapes the
 * quote that would end the attribute, not the semicolon that would end the
 * declaration. So the rejection cases below are not defensive padding; they are
 * the feature's security boundary, asserted directly.
 *
 * @package Popkit
 */

use Popkit\Meta;
use Popkit\Post_Type;
use Popkit\Renderer;

/**
 * Integration coverage for display customization.
 */
final class Test_Popkit_Display_Customization extends WP_UnitTestCase {

	/**
	 * A popup with no overrides emits none of the customization hooks.
	 *
	 * The compatibility guarantee: every override defaults to "leave the theme
	 * alone", so a popup authored before this feature existed renders exactly as
	 * it did.
	 *
	 * @return void
	 */
	public function test_an_uncustomized_popup_emits_no_overrides() {
		$html = $this->render( array() );

		$this->assertStringNotContainsString( 'style=', $html, 'No color override means no style attribute at all.' );
		$this->assertStringNotContainsString( 'data-popkit-radius', $html );
		$this->assertStringNotContainsString( 'data-popkit-font', $html );
		$this->assertStringNotContainsString( 'data-popkit-border-width', $html );
	}

	/**
	 * Chosen colors reach the tokens the shipped themes set.
	 *
	 * Feeding the same tokens is what makes an override work everywhere without a
	 * parallel set of rules: one declaration reads `--popkit-surface` and does not
	 * care whether a theme or an author put the value there.
	 *
	 * @return void
	 */
	public function test_colors_are_emitted_as_the_theme_tokens() {
		$html = $this->render(
			array(
				'custom_background'   => '#102030',
				'custom_text'         => '#ffffff',
				'custom_accent'       => '#ffcc00',
				'custom_border_color' => '#abc',
			)
		);

		$style = $this->root_attribute( $html, 'style' );

		$this->assertStringContainsString( '--popkit-surface:#102030', $style );
		$this->assertStringContainsString( '--popkit-on-surface:#ffffff', $style );
		$this->assertStringContainsString( '--popkit-accent:#ffcc00', $style );
		$this->assertStringContainsString( '--popkit-border:#abc', $style, 'Three-digit hex is valid CSS and is kept.' );
	}

	/**
	 * A color that is not a hex triplet is refused, not partially kept.
	 *
	 * Each of these is a real way to end a declaration and start another one. A
	 * refused color stores as empty, which the renderer reads as "use the theme"
	 * — falling back to a value whose contrast has actually been measured.
	 *
	 * @return void
	 */
	public function test_a_color_that_could_carry_css_is_refused() {
		$attacks = array(
			'#fff;position:fixed;inset:0',
			'red;background:url(https://example.com/x)',
			'url(javascript:alert(1))',
			'var(--anything)',
			'expression(alert(1))',
			'#ff',
			'rgb(0 0 0)',
		);

		foreach ( $attacks as $attack ) {
			$stored = Meta::sanitize_display( array( 'custom_background' => $attack ) );

			$this->assertSame(
				'',
				$stored['custom_background'],
				sprintf( 'A color of "%s" must be refused outright rather than trimmed to something plausible.', $attack )
			);
		}

		// And nothing reaches the page even if such a value is already in storage.
		$html = $this->render( array( 'custom_background' => '#fff;position:fixed' ) );

		$this->assertStringNotContainsString( 'position:fixed', $html, 'The renderer re-validates rather than trusting storage.' );
	}

	/**
	 * Scales become data attributes, and `inherit` becomes nothing.
	 *
	 * @return void
	 */
	public function test_scales_become_data_attributes() {
		$html = $this->render(
			array(
				'custom_border_width' => 'thick',
				'custom_radius'       => 'large',
				'custom_font'         => 'serif',
				'custom_font_size'    => 'inherit',
			)
		);

		$this->assertSame( 'thick', $this->root_attribute( $html, 'data-popkit-border-width' ) );
		$this->assertSame( 'large', $this->root_attribute( $html, 'data-popkit-radius' ) );
		$this->assertSame( 'serif', $this->root_attribute( $html, 'data-popkit-font' ) );
		$this->assertNull(
			$this->root_attribute( $html, 'data-popkit-font-size' ),
			'`inherit` is the absence of an override and earns no attribute.'
		);
	}

	/**
	 * A scale value outside the declared list falls back to the theme.
	 *
	 * @return void
	 */
	public function test_an_unknown_scale_value_is_refused() {
		$stored = Meta::sanitize_display( array( 'custom_radius' => 'enormous' ) );

		$this->assertSame( 'inherit', $stored['custom_radius'] );
	}

	/**
	 * A banner may be placed in the lower third; a modal may not.
	 *
	 * The position vocabulary is per layout, so a value belonging to the other
	 * layout is not something this layout can render.
	 *
	 * @return void
	 */
	public function test_lower_third_is_a_banner_position_only() {
		$banner = Meta::sanitize_display(
			array(
				'layout'   => 'banner',
				'position' => 'lower_third',
			)
		);

		$this->assertSame( 'lower_third', $banner['position'] );

		$modal = Meta::sanitize_display(
			array(
				'layout'   => 'modal',
				'position' => 'lower_third',
			)
		);

		$this->assertSame(
			'center',
			$modal['position'],
			'A modal cannot be a lower third, so it falls back to its own default.'
		);
	}

	/**
	 * The lower-third banner reaches the page as a position attribute.
	 *
	 * @return void
	 */
	public function test_a_lower_third_banner_renders_its_position() {
		$html = $this->render(
			array(
				'layout'   => 'banner',
				'position' => 'lower_third',
			)
		);

		$this->assertSame( 'lower_third', $this->root_attribute( $html, 'data-popkit-position' ) );
	}

	/**
	 * Renders a popup with the given display overrides applied to the defaults.
	 *
	 * @param array<string, mixed> $display Display overrides.
	 * @return string Rendered markup.
	 */
	private function render( array $display ) {
		$popup = get_post(
			self::factory()->post->create(
				array(
					'post_type'    => Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => 'Appearance fixture',
					'post_content' => '<h2>Hello</h2>',
				)
			)
		);

		return Renderer::render( $popup, Meta::sanitize_display( array_merge( Meta::default_display(), $display ) ) );
	}

	/**
	 * Reads one attribute off the popup's root element.
	 *
	 * @param string $html      Rendered markup.
	 * @param string $attribute Attribute name.
	 * @return string|null Attribute value, or null when absent.
	 */
	private function root_attribute( $html, $attribute ) {
		$processor = new WP_HTML_Tag_Processor( $html );

		$this->assertTrue( $processor->next_tag(), 'The rendered popup has a root element.' );

		$value = $processor->get_attribute( $attribute );

		return is_string( $value ) ? $value : null;
	}
}
