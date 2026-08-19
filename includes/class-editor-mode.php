<?php
/**
 * Which editor a popup is authored in.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Decides, once, whether popups use the block editor — and lets a site say no.
 *
 * ## Why this class exists
 *
 * PopKit shipped a block-editor sidebar and nothing else. On a site running the
 * Classic Editor plugin, `enqueue_block_editor_assets` never fires, no meta box
 * was ever registered, and the result was a popup screen with a WYSIWYG box and
 * no targeting, triggers, schedule, frequency or appearance controls at all. The
 * plugin was not degraded on those sites; it was unusable, and silently so.
 *
 * Two surfaces now exist. This class is the single place that decides which one
 * a given request gets, so they can never both mount — two UIs writing the same
 * post meta is how a popup ends up with settings that depend on which screen was
 * saved last.
 *
 * ## The default is the block editor, even under Classic Editor
 *
 * A popup's *content* is blocks. That is not a preference, it is the data model:
 * `Renderer::content()` runs `the_content`, and the editor warnings read the
 * block tree. A site that has chosen the classic editor for its posts and pages
 * has made a decision about posts and pages, and honouring it for a post type
 * whose content is blocks would mean authoring blocks in a textarea.
 *
 * So popups opt back into the block editor by default, and the rest of the site
 * is left exactly as the site owner configured it.
 *
 * ## How a site says no
 *
 * ```php
 * add_filter( 'popkit_use_block_editor', '__return_false' );
 * ```
 *
 * That is a real supported answer, not an escape hatch nobody should take: a
 * site may have the block editor disabled for reasons PopKit cannot see. When it
 * returns false, {@see Classic_Editor} renders the same five panels as meta
 * boxes and the block editor sidebar does not load.
 *
 * ## Priority 1000
 *
 * The Classic Editor plugin filters `use_block_editor_for_post_type` at priority
 * **100** when it is set to replace the block editor site-wide. Running at the
 * default 10 would be overruled by it, silently, on exactly the sites this class
 * exists for. 1000 is late enough to win and is documented here so nobody
 * "tidies" it back to the default.
 *
 * `classic_editor_enabled_editors_for_post_type` is filtered as well. That is the
 * Classic Editor plugin's own per-post-type integration point, and answering it
 * is what stops the plugin offering an "Edit (Classic)" link for a post type
 * whose content cannot survive one.
 *
 * @since 0.1.0
 */
final class Editor_Mode {

	/**
	 * Priority that beats the Classic Editor plugin's own filter. See the class docblock.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private const LATE = 1000;

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Hooks the editor preference.
	 *
	 * Array callables, for the reason given on {@see Rest_Schema::init()}: a
	 * stable identity, so WordPress deduplicates them, a test can assert them by
	 * name, and a site owner can remove them.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'use_block_editor_for_post_type', array( self::class, 'filter_post_type' ), self::LATE, 2 );
		add_filter( 'classic_editor_enabled_editors_for_post_type', array( self::class, 'filter_classic_editor' ), self::LATE, 2 );
	}

	/**
	 * Whether popups should be authored in the block editor.
	 *
	 * The single source of truth. Both {@see Editor} and {@see Classic_Editor}
	 * ask this, so exactly one of them mounts.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the block editor is used for popups.
	 */
	public static function uses_block_editor(): bool {
		/**
		 * Filters whether popups are authored in the block editor.
		 *
		 * Return false to author popups in the classic editor instead, where
		 * PopKit renders its panels as meta boxes.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $use_block_editor Whether to use the block editor.
		 */
		return (bool) apply_filters( 'popkit_use_block_editor', true );
	}

	/**
	 * Answers core's own "does this post type use the block editor" question.
	 *
	 * Only the popup post type is touched. Every other post type keeps whatever
	 * answer the site, its theme and its plugins already agreed on — this filter
	 * returns the value it was handed, unchanged.
	 *
	 * @since 0.1.0
	 *
	 * @param bool   $use_block_editor Whether the post type uses the block editor.
	 * @param string $post_type        Post type being asked about.
	 * @return bool Whether the post type uses the block editor.
	 */
	public static function filter_post_type( $use_block_editor, $post_type ): bool {
		if ( Post_Type::POST_TYPE !== $post_type ) {
			return (bool) $use_block_editor;
		}

		return self::uses_block_editor();
	}

	/**
	 * Tells the Classic Editor plugin which editors a popup may be opened in.
	 *
	 * Returning a single enabled editor rather than both is deliberate. The
	 * plugin's "allow users to switch" mode otherwise puts an *Edit (Classic)*
	 * link on a post type whose settings live in the other screen, and a link
	 * that drops half the interface is worse than no link.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, bool> $editors   Enabled editors, keyed by editor name.
	 * @param string              $post_type Post type being asked about.
	 * @return array<string, bool> Enabled editors.
	 */
	public static function filter_classic_editor( $editors, $post_type ): array {
		if ( Post_Type::POST_TYPE !== $post_type || ! is_array( $editors ) ) {
			return (array) $editors;
		}

		$block = self::uses_block_editor();

		return array(
			'classic_editor' => ! $block,
			'block_editor'   => $block,
		);
	}
}
