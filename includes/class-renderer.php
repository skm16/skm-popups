<?php
/**
 * Accessible markup for one popup.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

use WP_HTML_Tag_Processor;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the HTML popkit prints for a popup that survived server evaluation.
 *
 * This is the whole of popkit's front-end markup. It is a pure function of the
 * popup and its display settings: the same popup and the same settings produce
 * the same bytes, on every request, for every visitor. Nothing here reads a
 * cookie, a user agent, a referrer or the clock, because
 * `docs/CLAUDE.md` -> Cache safety requires two requests to one URL to be
 * byte-identical and a page cache to be able to serve one response to everybody.
 * There is no timestamp in this output for the same reason there is no
 * `serverTime` in the emitted config: a value frozen at cache-fill time is wrong
 * for every later hit.
 *
 * ## Emitted hidden, revealed by the controller
 *
 * A modal is a native `<dialog>` **without** the `open` attribute, which is how
 * the HTML specification spells "closed": the user agent stylesheet hides it, and
 * the controller opens it with `.showModal()` so the browser supplies the top
 * layer, the backdrop, inertness of the page behind, and Escape.
 *
 * The dialog must never carry `hidden`. The user agent rule `[hidden]` is more
 * specific than the `dialog` rule that displays an open dialog, so a `hidden`
 * dialog stays invisible after `.showModal()` succeeds — a failure that looks
 * like broken JavaScript and is not.
 *
 * A banner is a plain `<div hidden>`, because it has no native closed state.
 * `hidden` rather than a class is deliberate: it works before the stylesheet
 * loads and it still works if the stylesheet never loads, so a visitor whose
 * assets failed does not find the popup's contents sitting in the page footer.
 *
 * ## Accessible name
 *
 * `docs/CLAUDE.md` -> Accessibility requires an accessible name in every case,
 * so this resolves one in three steps and the last cannot fail:
 *
 * 1. `aria-labelledby` pointing at the first heading in the rendered content.
 *    An `id` the author already set is used as it stands; otherwise one is added.
 * 2. `aria-label` carrying the post title, when the content has no heading.
 * 3. `aria-label` carrying a generic translated word, when the popup has no
 *    title either. A weak name is worth having; an unnamed dialog is announced
 *    as nothing at all.
 *
 * "First heading" means first in document order, not "first block". A popup that
 * opens with an image, an eyebrow line or a logo above its headline is ordinary,
 * and requiring the heading to come first would drop the real name in exactly
 * those cases and fall back to a post title written for the admin list table.
 *
 * The one case this cannot see is a heading element that is present but empty.
 * Reading a heading's text back out of rendered HTML needs an API popkit's
 * minimum WordPress does not have, so the Phase 5 editor warning for a missing
 * accessible name has to cover an empty heading as well as an absent one.
 *
 * ## The close button
 *
 * Always rendered, in both layouts, in every theme, with no setting that removes
 * it — see `docs/data-model.md` -> Display, which has no `close_button` field.
 * It is a real `<button type="button">` carrying real text, so it has an
 * accessible name, is reachable by keyboard, is discoverable by assistive
 * technology, and matches what a voice-control user would say. Overlay clicks and
 * Escape are additional dismissal paths and never substitutes: an overlay has no
 * name, no focus, and no way of announcing that it can be clicked.
 *
 * Two requirements land on the stylesheet rather than here, and both are part of
 * the contract:
 *
 * - `.popkit-popup__close` needs a hit area of at least 44x44 CSS pixels in
 *   every theme and both layouts.
 * - `.popkit-popup__close-text` may be visually hidden by clipping, the way
 *   `.screen-reader-text` is. It must never be hidden with `display: none` or
 *   `visibility: hidden`, either of which removes the button's only name.
 *
 * The button is the first thing inside the container. On a browser that does not
 * yet implement the current dialog focusing steps, `.showModal()` focuses the
 * first focusable descendant instead of honouring `autofocus` on the dialog, and
 * putting the close button first means that fallback lands on the dismissal
 * control rather than on whichever form field the author happened to place at
 * the top — which the constitution forbids outright.
 *
 * ## Banner semantics
 *
 * A banner is a `<div role="region">` with an accessible name, which makes it a
 * generic landmark: reachable from a screen reader's landmark list, and not
 * announced as a dialog, because it is not one. Nothing about it is modal — the
 * page behind stays interactive and focus stays where the visitor left it.
 *
 * Three things it deliberately is not:
 *
 * - Not `role="banner"`. That is the site header landmark, there should be one
 *   per page, and claiming it would rename part of the site's own structure. The
 *   collision is with popkit's layout name only.
 * - Not `role="dialog"` and not `aria-modal`. Both would promise a focus trap
 *   and an inert background that a banner does not provide.
 * - Not `aria-live`. A live region interrupts whatever is being read to announce
 *   the whole banner, and popkit's banner can carry paragraphs. How and whether
 *   its arrival is announced is the controller's decision, made once, rather
 *   than something baked into markup that cannot take it back.
 *
 * ## DOM contract
 *
 * The runtime and the stylesheet both key off this, so it is public API and
 * deliberately small. Five attributes on the root element:
 *
 * | Attribute | Value |
 * |---|---|
 * | `data-popkit-id` | Post ID, matching `id` in the emitted config |
 * | `data-popkit-slug` | Post slug, the identifier `window.popkit.open()` takes |
 * | `data-popkit-layout` | `modal` or `banner` |
 * | `data-popkit-position` | `center` or `top` for a modal, `top` or `bottom` for a banner |
 * | `data-popkit-animation` | `none`, `fade` or `slide` |
 *
 * Plus one on the close button, `data-popkit-close`, so the runtime's binding
 * survives a theme rewriting the class list.
 *
 * Everything purely presentational is a class instead, because it has no runtime
 * meaning: `popkit-popup`, `popkit-popup--modal` or `--banner`,
 * `popkit-popup--theme-*`, `popkit-popup--size-*`, and `popkit-popup--no-overlay`
 * on a modal whose overlay is off. `close_on_overlay_click` appears in neither
 * list — it is behaviour, the controller reads it from the emitted config, and
 * putting it in the DOM would be a second copy of a setting that already has a
 * home.
 *
 * @since 0.1.0
 */
final class Renderer {

	/**
	 * Marker attribute identifying the close button to the runtime.
	 *
	 * A class would do the same job until a theme rewrote it. This is the one
	 * attribute in the contract that is not on the root element.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const CLOSE_ATTRIBUTE = 'data-popkit-close';

	/**
	 * Class every popup root carries, and the base of every modifier and element.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const ROOT_CLASS = 'popkit-popup';

	/**
	 * Prefix of the `id` given to a heading that is about to name the popup.
	 *
	 * Completed with the post ID, so a page carrying several popups gives each
	 * one a distinct target. It is deliberately not the `popkit-{slug}` spelling
	 * the deep link trigger uses: an element bearing that id would make the
	 * browser treat `#popkit-{slug}` as an ordinary fragment navigation.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const TITLE_ID_PREFIX = 'popkit-title-';

	/**
	 * Landmark role a banner carries. See the class docblock.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const BANNER_ROLE = 'region';

	/**
	 * Layout rendered as a plain element rather than as a native dialog.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const BANNER_LAYOUT = 'banner';

	/**
	 * Tag names that can supply the popup's accessible name.
	 *
	 * Uppercase because that is how {@see WP_HTML_Tag_Processor::get_tag()}
	 * reports a tag name, whatever case the author's markup used.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	private const HEADING_TAGS = array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' );

	/**
	 * The close button's icon.
	 *
	 * Inline SVG rather than an icon font or a background image: it inherits
	 * `currentColor`, so it cannot fail a contrast check the button's own text
	 * passes, and it costs no extra request. It is `aria-hidden` because the
	 * button is already named by its text — announcing the glyph as well would
	 * name the control twice.
	 *
	 * The `width` and `height` attributes are not styling. Without them an SVG
	 * with no intrinsic size falls back to 300x150 CSS pixels, so a stylesheet
	 * that has not loaded yet would show an enormous cross.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const CLOSE_ICON = '<svg class="' . self::ROOT_CLASS . '__close-icon" width="24" height="24"'
		. ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
		. ' aria-hidden="true"><path d="M6 6 18 18M18 6 6 18"/></svg>';

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Renders one popup, hidden and ready for the controller to open.
	 *
	 * The caller owns selection: this renders whatever popup it is handed and
	 * makes no judgement about whether that popup should be on the page, which
	 * `docs/CLAUDE.md` -> Architecture invariants settles two stages earlier.
	 *
	 * `$display` is read defensively even though it arrives sanitized. Every
	 * value is resolved against the enumeration `docs/data-model.md` declares for
	 * it, so a setting written by an older popkit, by a migration, or by a direct
	 * `update_post_meta()` call can never put an unrecognized token into an
	 * attribute the stylesheet and the runtime both match on.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $popup   Popup being rendered.
	 * @param array   $display Display configuration, in the `_popkit_display` shape.
	 * @return string Popup markup, escaped and ready to print.
	 */
	public static function render( WP_Post $popup, array $display ): string {
		$defaults  = Meta::default_display();
		$layout    = self::choice( $display, 'layout', Meta::DISPLAY_LAYOUTS, $defaults['layout'] );
		$positions = Meta::DISPLAY_POSITIONS[ $layout ] ?? Meta::DISPLAY_POSITIONS['modal'];
		$is_banner = self::BANNER_LAYOUT === $layout;
		$named     = self::accessible_name( self::content( $popup ), $popup );

		/*
		 * An overlay belongs to a modal. A banner never has one, so it is passed
		 * as null rather than false — false would put the `--no-overlay` modifier
		 * on a banner and describe the absence of something it never had.
		 */
		$overlay = $is_banner ? null : self::flag( $display, 'overlay', (bool) $defaults['overlay'] );

		$attributes = array(
			'class'                 => self::class_list(
				$layout,
				self::choice( $display, 'theme', Meta::DISPLAY_THEMES, $defaults['theme'] ),
				self::choice( $display, 'size', Meta::DISPLAY_SIZES, $defaults['size'] ),
				$overlay
			),
			'data-popkit-id'        => (string) $popup->ID,
			'data-popkit-slug'      => $popup->post_name,
			'data-popkit-layout'    => $layout,
			'data-popkit-position'  => self::choice( $display, 'position', $positions, $positions[0] ),
			'data-popkit-animation' => self::choice( $display, 'animation', Meta::DISPLAY_ANIMATIONS, $defaults['animation'] ),

			// Exactly one of these two is ever a string. See self::accessible_name().
			'aria-labelledby'       => $named['labelledby'],
			'aria-label'            => $named['label'],

			/*
			 * So the controller can move focus to the popup itself. Focus lands on
			 * the container or the heading and never on a form field, and a root
			 * that is not programmatically focusable leaves it nowhere to put it.
			 */
			'tabindex'              => '-1',
		);

		if ( $is_banner ) {
			$attributes['role']   = self::BANNER_ROLE;
			$attributes['hidden'] = true;

			return sprintf(
				'<div%1$s>%2$s</div>',
				self::attributes( $attributes ),
				self::container( $named['content'] )
			);
		}

		/*
		 * `autofocus` on the dialog itself is what the current dialog focusing
		 * steps read first, so the browser focuses the dialog rather than hunting
		 * for a focusable descendant and finding the author's email field. It
		 * cannot steal focus at page load: the dialog is closed, a closed dialog
		 * is not a focusable area, and the autofocus candidate list skips it.
		 *
		 * No `open` attribute — that is the closed state. No `hidden` — see the
		 * class docblock. No `aria-modal`, because `.showModal()` supplies modal
		 * semantics and a static attribute would keep claiming them while the
		 * dialog is closed.
		 */
		$attributes['autofocus'] = true;

		return sprintf(
			'<dialog%1$s>%2$s</dialog>',
			self::attributes( $attributes ),
			self::container( $named['content'] )
		);
	}

	/**
	 * Wraps the close button and the popup's content in the layout container.
	 *
	 * The container exists so that a modal has something to animate and to
	 * position independently of the `<dialog>`, which the browser also positions,
	 * and so that both layouts present the stylesheet with the same two elements
	 * to work with.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content Rendered popup content.
	 * @return string Container markup.
	 */
	private static function container( string $content ): string {
		return sprintf(
			'<div class="%1$s__container">%2$s<div class="%1$s__content">%3$s</div></div>',
			self::ROOT_CLASS,
			self::close_button(),
			$content
		);
	}

	/**
	 * Builds the close button.
	 *
	 * Every popup gets this, and there is no argument that changes it. See the
	 * class docblock for what the stylesheet has to hold up.
	 *
	 * @since 0.1.0
	 *
	 * @return string Close button markup.
	 */
	private static function close_button(): string {
		return sprintf(
			'<button type="button" class="%1$s__close" %2$s>%3$s<span class="%1$s__close-text">%4$s</span></button>',
			self::ROOT_CLASS,
			self::CLOSE_ATTRIBUTE,
			self::CLOSE_ICON,
			esc_html_x( 'Close', 'button that dismisses a popup', 'popkit' )
		);
	}

	/**
	 * Renders the popup's content through the block editor pipeline.
	 *
	 * `the_content` is applied rather than reimplemented. It is what turns blocks
	 * into HTML, and a hand-rolled substitute would drift from core the first time
	 * the pipeline gains a step. The content was already run through
	 * `wp_filter_post_kses()` on save for any author without `unfiltered_html`,
	 * and re-filtering the rendered output would strip the `<style>` elements
	 * block supports emit, embeds, and the figure markup core itself writes.
	 *
	 * The global `$post` is deliberately left alone. Swapping it for the popup
	 * would change what every block on the page thinks the current post is, for
	 * the rest of the request, in exchange for a popup that is a fragment of that
	 * page rather than a page of its own.
	 *
	 * Cache safety survives this because the filter chain is a function of the
	 * content and of site settings. Nothing popkit adds to that chain varies by
	 * visitor, and a site that hooks something visitor-varying onto `the_content`
	 * has already broken page caching for its own posts.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $popup Popup being rendered.
	 * @return string Rendered content, empty when the popup has none.
	 */
	private static function content( WP_Post $popup ): string {
		if ( '' === trim( $popup->post_content ) ) {
			return '';
		}

		/*
		 * Core's own content filter, applied rather than declared. The prefix rule
		 * in `docs/CLAUDE.md` governs hooks popkit introduces, and there is no
		 * popkit spelling of `the_content` that would render a block.
		 */
		/** This filter is documented in wp-includes/post-template.php */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applying a core hook, not registering one.
		$content = (string) apply_filters( 'the_content', $popup->post_content );

		// What the_content() does, so a CDATA close in the content cannot end one early.
		return str_replace( ']]>', ']]&gt;', $content );
	}

	/**
	 * Resolves the popup's accessible name, adding a heading `id` when it helps.
	 *
	 * Returns the content alongside the name because naming the popup can mean
	 * editing the content: a heading with no `id` of its own is given one so that
	 * `aria-labelledby` has something to point at. {@see WP_HTML_Tag_Processor}
	 * does that edit lexically, leaving every other byte of the author's markup
	 * where it was — and it parses the HTML rather than matching it, which is
	 * what keeps `docs/CLAUDE.md` -> Security's ban on running a regular
	 * expression over authored input intact.
	 *
	 * An `id` the author set is used unchanged. Overwriting it would break their
	 * own anchor links and their own CSS.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $content Rendered popup content.
	 * @param WP_Post $popup   Popup being rendered.
	 * @return array{content: string, labelledby: string|null, label: string|null} Content to
	 *               print, plus exactly one of the two naming values.
	 */
	private static function accessible_name( string $content, WP_Post $popup ): array {
		$processor = new WP_HTML_Tag_Processor( $content );

		while ( $processor->next_tag() ) {
			if ( ! in_array( $processor->get_tag(), self::HEADING_TAGS, true ) ) {
				continue;
			}

			$existing = $processor->get_attribute( 'id' );

			if ( is_string( $existing ) && '' !== trim( $existing ) ) {
				return array(
					'content'    => $content,
					'labelledby' => $existing,
					'label'      => null,
				);
			}

			$heading_id = self::TITLE_ID_PREFIX . $popup->ID;

			$processor->set_attribute( 'id', $heading_id );

			return array(
				'content'    => $processor->get_updated_html(),
				'labelledby' => $heading_id,
				'label'      => null,
			);
		}

		return array(
			'content'    => $content,
			'labelledby' => null,
			'label'      => self::fallback_label( $popup ),
		);
	}

	/**
	 * The name used when the content carries no heading.
	 *
	 * The post title is read raw rather than through `get_the_title()`, which
	 * prepends "Private:" or "Protected:" to the titles of popups in those
	 * statuses. That prefix is written for an editor reading a list table, and
	 * reading it out to a visitor would describe the popup's post status instead
	 * of the popup.
	 *
	 * A popup with no heading and no title still gets a name. It is a poor one,
	 * which is why Phase 5 warns about it in the editor, but a dialog whose name
	 * is empty is announced as "dialog" and nothing else.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $popup Popup being rendered.
	 * @return string Accessible name.
	 */
	private static function fallback_label( WP_Post $popup ): string {
		$title = trim( wp_strip_all_tags( $popup->post_title ) );

		if ( '' !== $title ) {
			return $title;
		}

		return _x( 'Popup', 'accessible name for a popup with no heading and no title', 'popkit' );
	}

	/**
	 * Builds the root element's class list.
	 *
	 * Presentation only. Every value is already one of the tokens
	 * `docs/data-model.md` declares, so no class name can be invented by stored
	 * data.
	 *
	 * @since 0.1.0
	 *
	 * @param string    $layout  Resolved layout.
	 * @param string    $theme   Resolved theme.
	 * @param string    $size    Resolved size.
	 * @param bool|null $overlay Whether a modal dims the page behind it; null for a
	 *                           banner, which has no overlay to describe.
	 * @return string Space separated class list.
	 */
	private static function class_list( string $layout, string $theme, string $size, ?bool $overlay ): string {
		$classes = array(
			self::ROOT_CLASS,
			self::ROOT_CLASS . '--' . $layout,
			self::ROOT_CLASS . '--theme-' . $theme,
			self::ROOT_CLASS . '--size-' . $size,
		);

		if ( false === $overlay ) {
			$classes[] = self::ROOT_CLASS . '--no-overlay';
		}

		return implode( ' ', $classes );
	}

	/**
	 * Serializes an attribute map, escaping every value.
	 *
	 * `null` and `false` drop the attribute, `true` prints it bare — the spelling
	 * of a boolean attribute such as `hidden` or `autofocus` — and anything else
	 * prints as a quoted, escaped value. Attribute *names* are literals from this
	 * file and never come from stored data, so nothing here can introduce one.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $attributes Attribute name => value.
	 * @return string Attributes, each preceded by a space.
	 */
	private static function attributes( array $attributes ): string {
		$html = '';

		foreach ( $attributes as $name => $value ) {
			if ( null === $value || false === $value ) {
				continue;
			}

			if ( true === $value ) {
				$html .= ' ' . $name;

				continue;
			}

			$html .= ' ' . $name . '="' . esc_attr( (string) $value ) . '"';
		}

		return $html;
	}

	/**
	 * Resolves one display setting against the values its field permits.
	 *
	 * Routed through {@see Sanitizer::sanitize_value()} rather than compared here,
	 * so the value this renders is resolved by the same code that decided what to
	 * store. A second opinion about what `position` means would eventually
	 * disagree with the first.
	 *
	 * @since 0.1.0
	 *
	 * @param array    $display  Display configuration.
	 * @param string   $key      Setting name.
	 * @param string[] $allowed  Permitted values, in the order data-model.md lists them.
	 * @param mixed    $fallback Value used when the setting is absent or unusable.
	 * @return string Resolved setting.
	 */
	private static function choice( array $display, string $key, array $allowed, mixed $fallback ): string {
		$value = Sanitizer::sanitize_value(
			$display[ $key ] ?? null,
			array(
				'type'    => 'enum',
				'enum'    => $allowed,
				'default' => $fallback,
			)
		);

		return is_string( $value ) ? $value : (string) $fallback;
	}

	/**
	 * Resolves one boolean display setting.
	 *
	 * The default is substituted before sanitization rather than after, because
	 * the boolean sanitizer reads a missing value as false and a missing
	 * `overlay` means the documented default, which is true.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $display  Display configuration.
	 * @param string $key      Setting name.
	 * @param bool   $fallback Value used when the setting is absent.
	 * @return bool Resolved setting.
	 */
	private static function flag( array $display, string $key, bool $fallback ): bool {
		$raw = array_key_exists( $key, $display ) ? $display[ $key ] : $fallback;

		return (bool) Sanitizer::sanitize_value(
			$raw,
			array(
				'type'    => 'boolean',
				'default' => $fallback,
			)
		);
	}
}
