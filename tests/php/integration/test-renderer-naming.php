<?php
/**
 * Integration tests for how a popup gets its accessible name.
 *
 * A dialog with no accessible name is announced as "dialog" and nothing else,
 * and a dialog with the *wrong* name is worse: it tells the visitor something
 * confident and false about what just appeared. `docs/CLAUDE.md` ->
 * Accessibility calls this the product differentiator rather than a polish item,
 * so the naming rule is held here rather than left to the end-to-end suite.
 *
 * ## The bug this file was written for
 *
 * `aria-labelledby` is an IDREF, and an IDREF resolves against the whole
 * document — first match in tree order wins. popkit prints its popups on
 * `wp_footer`, at the very end of a page it did not write, so an `id` it did not
 * mint is a promise it cannot keep. An author who sets the block editor's HTML
 * Anchor to `newsletter` on a popup heading, on a site whose footer already
 * carries `<section id="newsletter">`, used to get a modal announced with that
 * whole section's text.
 *
 * {@see Test_Popkit_Renderer_Naming::test_an_authored_anchor_names_the_popup_by_its_own_text()}
 * is the regression test. The rest of the file pins the behaviour either side of
 * it, because the fix is only correct if the common case is untouched — a
 * heading with no anchor must still be named by `aria-labelledby`, and the
 * author's own `id` must still survive the render byte for byte.
 *
 * ## Why the assertions parse rather than match
 *
 * Attributes are read with {@see WP_HTML_Tag_Processor}, not with `strpos()` on
 * the rendered string. An assertion that greps for `aria-label="Join our
 * newsletter"` passes just as happily when the attribute is on the wrong
 * element, and this file exists because of a bug about which element an
 * attribute pointed at.
 *
 * @package Popkit
 */

use Popkit\Meta;
use Popkit\Post_Type;
use Popkit\Renderer;

/**
 * Integration coverage for Popkit\Renderer's accessible name.
 */
final class Test_Popkit_Renderer_Naming extends WP_UnitTestCase {

	/**
	 * A heading popkit anchored itself is named by reference.
	 *
	 * This is the common case and the one the fix must not disturb: no author
	 * anchor, so popkit mints `popkit-title-{ID}`, puts it on the heading, and
	 * points `aria-labelledby` at it. Nothing in this path is a claim about the
	 * rest of the document, because the id is namespaced and carries the post ID.
	 *
	 * @return void
	 */
	public function test_a_heading_without_an_anchor_is_named_by_reference() {
		$popup = $this->popup( '<h2>Join our newsletter</h2>' );
		$html  = $this->render( $popup );
		$root  = $this->root_attributes( $html );

		$this->assertSame(
			Renderer::TITLE_ID_PREFIX . $popup->ID,
			$root['aria-labelledby'],
			'A heading with no author anchor must be referenced by a popkit-minted id.'
		);
		$this->assertNull( $root['aria-label'], 'Exactly one naming attribute is ever emitted.' );
		$this->assertSame(
			Renderer::TITLE_ID_PREFIX . $popup->ID,
			$this->heading_id( $html ),
			'The id the root points at has to actually be on the heading.'
		);
	}

	/**
	 * An author's anchor names the popup by text, and survives untouched.
	 *
	 * The regression test. Both halves matter and they pull in opposite
	 * directions: the name must stop depending on an id popkit does not own, and
	 * the author's id must still be exactly where they put it, because their
	 * anchor links and their CSS are written against it.
	 *
	 * @return void
	 */
	public function test_an_authored_anchor_names_the_popup_by_its_own_text() {
		$popup = $this->popup( '<h2 id="newsletter">Join our newsletter</h2>' );
		$html  = $this->render( $popup );
		$root  = $this->root_attributes( $html );

		$this->assertSame(
			'Join our newsletter',
			$root['aria-label'],
			'An anchored heading must name the popup with its own text.'
		);
		$this->assertNull(
			$root['aria-labelledby'],
			'popkit must not emit an IDREF it did not mint: the page may hold that id first.'
		);

		$this->assertSame(
			'newsletter',
			$this->heading_id( $html ),
			"The author's anchor must survive the render unchanged."
		);
		$this->assertStringNotContainsString(
			Renderer::TITLE_ID_PREFIX,
			$html,
			'No popkit id is added to a heading that already has one.'
		);
	}

	/**
	 * Nested markup is flattened, and entities make the round trip once.
	 *
	 * `get_modifiable_text()` hands back decoded text and `esc_attr()` re-encodes
	 * it on the way out. That is correct and is asserted here so nobody later
	 * "fixes" a double encoding that was never there.
	 *
	 * @return void
	 */
	public function test_nested_markup_flattens_and_entities_round_trip() {
		$nested = $this->render( $this->popup( '<h2 id="a">Join our <em>free</em> newsletter</h2>' ) );

		$this->assertSame(
			'Join our free newsletter',
			$this->root_attributes( $nested )['aria-label'],
			'Inline markup inside a heading is part of its text, not a boundary.'
		);

		$entity = $this->render( $this->popup( '<h2 id="a">Buy 1 &amp; get 1</h2>' ) );

		$this->assertSame(
			'Buy 1 & get 1',
			$this->root_attributes( $entity )['aria-label'],
			'The parser decodes and esc_attr re-encodes: the name must not be double encoded.'
		);
		$this->assertStringContainsString(
			'aria-label="Buy 1 &amp; get 1"',
			$entity,
			'The emitted bytes carry a single, correct HTML encoding.'
		);
	}

	/**
	 * A heading that is blank or unbalanced falls back rather than lies.
	 *
	 * The unbalanced half is the important one. Walking tokens to find a closing
	 * tag that never comes would otherwise accumulate every piece of text after
	 * the heading, and announce the entire popup as its own name.
	 *
	 * @return void
	 */
	public function test_a_blank_or_unbalanced_heading_falls_back_to_the_title() {
		$blank = $this->render( $this->popup( '<h2 id="a"></h2>', 'Spring sale' ) );

		$this->assertSame(
			'Spring sale',
			$this->root_attributes( $blank )['aria-label'],
			'An empty anchored heading names the popup with the post title.'
		);

		$nbsp = $this->render( $this->popup( '<h2 id="a">&nbsp;</h2>', 'Spring sale' ) );

		$this->assertSame(
			'Spring sale',
			$this->root_attributes( $nbsp )['aria-label'],
			'A heading holding only a non-breaking space reads as blank, as the editor warning also treats it.'
		);

		$unbalanced = $this->render(
			$this->popup( '<h2 id="a">Hi<p>Secret paragraph text.</p>', 'Spring sale' )
		);
		$name       = $this->root_attributes( $unbalanced )['aria-label'];

		$this->assertSame(
			'Spring sale',
			$name,
			'A heading that never closes must not be trusted as a name.'
		);
		$this->assertStringNotContainsString(
			'Secret paragraph',
			(string) $name,
			'The token walk must stop at the heading, never swallow the popup into its own name.'
		);
	}

	/**
	 * Two popups sharing one anchor are each named by their own heading.
	 *
	 * The multi-popup form of the bug: duplicating a popup copies its
	 * `post_content`, anchor and all. Both dialogs used to resolve to whichever
	 * heading came first in the footer, so one of them announced the other's
	 * name.
	 *
	 * @return void
	 */
	public function test_two_popups_sharing_an_anchor_do_not_borrow_each_others_names() {
		$first  = $this->render( $this->popup( '<h2 id="shared">First popup heading</h2>' ) );
		$second = $this->render( $this->popup( '<h2 id="shared">Second popup heading</h2>' ) );

		$this->assertSame( 'First popup heading', $this->root_attributes( $first )['aria-label'] );
		$this->assertSame( 'Second popup heading', $this->root_attributes( $second )['aria-label'] );

		foreach ( array( $first, $second ) as $html ) {
			$this->assertNull(
				$this->root_attributes( $html )['aria-labelledby'],
				'Neither popup may lean on an ambiguous id to say what it is.'
			);
		}
	}

	/**
	 * Rendering twice produces identical bytes.
	 *
	 * The naming walk advances a parser, so this pins that it holds no state
	 * between calls. A renderer whose output drifts between two calls in one
	 * request cannot be byte-identical across two requests either, which is the
	 * invariant the whole plugin is built on.
	 *
	 * @return void
	 */
	public function test_rendering_the_same_popup_twice_is_identical() {
		$popup = $this->popup( '<h2 id="a">Join our newsletter</h2>' );

		$this->assertSame(
			$this->render( $popup ),
			$this->render( $popup ),
			'Naming must be a pure function of the content.'
		);
	}

	/**
	 * Creates a published popup.
	 *
	 * @param string $content Post content.
	 * @param string $title   Post title.
	 * @return WP_Post Popup.
	 */
	private function popup( $content, $title = 'Naming fixture' ) {
		return get_post(
			self::factory()->post->create(
				array(
					'post_type'    => Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $content,
				)
			)
		);
	}

	/**
	 * Renders a popup with the default display settings.
	 *
	 * @param WP_Post $popup Popup.
	 * @return string Rendered markup.
	 */
	private function render( WP_Post $popup ) {
		return Renderer::render( $popup, Meta::default_display() );
	}

	/**
	 * Reads both naming attributes off the popup's root element.
	 *
	 * Parsed rather than matched, so an attribute on the wrong element cannot
	 * satisfy an assertion about the root.
	 *
	 * @param string $html Rendered markup.
	 * @return array{aria-labelledby: string|null, aria-label: string|null} Root naming attributes.
	 */
	private function root_attributes( $html ) {
		$processor = new WP_HTML_Tag_Processor( $html );

		$this->assertTrue( $processor->next_tag(), 'The rendered popup has a root element.' );
		$this->assertStringContainsString(
			Renderer::ROOT_CLASS,
			(string) $processor->get_attribute( 'class' ),
			'The first tag is the popup root.'
		);

		$labelledby = $processor->get_attribute( 'aria-labelledby' );
		$label      = $processor->get_attribute( 'aria-label' );

		return array(
			'aria-labelledby' => is_string( $labelledby ) ? $labelledby : null,
			'aria-label'      => is_string( $label ) ? $label : null,
		);
	}

	/**
	 * Reads the `id` of the first heading in rendered markup.
	 *
	 * @param string $html Rendered markup.
	 * @return string|null The heading's id, or null when it has none.
	 */
	private function heading_id( $html ) {
		$processor = new WP_HTML_Tag_Processor( $html );
		$headings  = array( 'H1', 'H2', 'H3', 'H4', 'H5', 'H6' );

		while ( $processor->next_tag() ) {
			if ( in_array( $processor->get_tag(), $headings, true ) ) {
				$id = $processor->get_attribute( 'id' );

				return is_string( $id ) ? $id : null;
			}
		}

		return null;
	}
}
