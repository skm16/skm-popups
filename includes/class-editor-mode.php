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
 * Decides which editor a popup is authored in — by default, by not deciding.
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
 * ## The default is to follow the site
 *
 * PopKit registers **no editor filter at all** unless asked to. A site running
 * the classic editor gets popups in the classic editor, with the five panels as
 * meta boxes; a site running the block editor gets the sidebar.
 *
 * That is a deliberate reversal. The first attempt forced the block editor for
 * popups on the reasoning that a popup's content is blocks — which is true of the
 * data model and beside the point. `post_content` is `post_content`: it is stored
 * the same way and rendered through the same `the_content` filter whichever
 * screen wrote it, so nothing about PopKit needs the block editor. Choosing an
 * editor is the site owner's decision, they have already made it, and a popup
 * plugin overriding it site-wide is not a trade it is entitled to make.
 *
 * What PopKit does owe them is that the settings appear on whichever screen they
 * chose. That is the actual fix, and it is what {@see Classic_Editor} is for.
 *
 * ## Forcing it, either way
 *
 * ```php
 * add_filter( 'popkit_use_block_editor', '__return_true' );   // block editor
 * add_filter( 'popkit_use_block_editor', '__return_false' );  // classic editor
 * ```
 *
 * The filter's default is `null`, meaning "no opinion" — and when it is null this
 * class attaches nothing, so a site's own configuration is not merely honoured,
 * it is untouched. Return a boolean only to override the site for popups.
 *
 * ## Three hooks, and why one is not enough
 *
 * When a site *does* force an editor, the preference is stated on
 * `use_block_editor_for_post` **and** on `use_block_editor_for_post_type`, both
 * at priority 1000.
 *
 * The per-post hook is the one that actually decides: core computes the
 * post-type answer, then filters it per post, so whatever runs there has the
 * last word. Stating a preference only on the post-type hook is precisely the
 * bug this class shipped with — the Classic Editor plugin hooks `__return_false`
 * onto the per-post filter at priority 100 on its default settings, popups were
 * served the classic screen, and the meta boxes stood down because they trusted
 * PopKit's preference instead of the outcome. A popup had no interface at all,
 * with nothing logged and nothing on screen to say why.
 *
 * Priority 1000 rather than the default 10 because 100 is where that plugin
 * hooks both. It is documented here so nobody "tidies" it back.
 *
 * `classic_editor_enabled_editors_for_post_type` is filtered as well, so the
 * Classic Editor plugin's own "Edit (Classic)" affordance agrees with the
 * override rather than offering a link to a screen the override has ruled out.
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
		// No opinion, no filters. A site that has not asked PopKit to override its
		// editor keeps a hook table PopKit never touched.
		if ( null === self::forced() ) {
			return;
		}

		add_filter( 'use_block_editor_for_post', array( self::class, 'filter_post' ), self::LATE, 2 );
		add_filter( 'use_block_editor_for_post_type', array( self::class, 'filter_post_type' ), self::LATE, 2 );
		add_filter( 'classic_editor_enabled_editors_for_post_type', array( self::class, 'filter_classic_editor' ), self::LATE, 2 );
	}

	/**
	 * Whether a site has asked PopKit to override its editor for popups.
	 *
	 * Three answers, not two. `null` is the default and means "no opinion": follow
	 * whatever the site already decided. `true` and `false` force the block editor
	 * or the classic editor for popups alone.
	 *
	 * A tri-state rather than a boolean because a boolean cannot express the
	 * default. `apply_filters( 'popkit_use_block_editor', true )` would make "leave
	 * my site alone" indistinguishable from "force the block editor", and PopKit
	 * would have to register filters — and override the site — just to find out
	 * that nobody wanted it to.
	 *
	 * @since 0.1.0
	 *
	 * @return bool|null True to force the block editor, false to force the classic
	 *                   editor, null to follow the site.
	 */
	public static function forced(): ?bool {
		/**
		 * Filters which editor popups are authored in.
		 *
		 * Return `true` to force the block editor for popups, `false` to force the
		 * classic editor, or `null` — the default — to follow whatever the site
		 * already uses. PopKit renders its five panels on either screen.
		 *
		 * @since 0.1.0
		 *
		 * @param bool|null $use_block_editor Editor override, or null to follow the site.
		 */
		$forced = apply_filters( 'popkit_use_block_editor', null );

		return null === $forced ? null : (bool) $forced;
	}

	/**
	 * Answers the per-post question, which is the one that actually decides.
	 *
	 * `use_block_editor_for_post_type` is not the final word and filtering it
	 * alone is not enough. Core calls it from `use_block_editor_for_post()` and
	 * then filters *that* result, so a plugin hooking the per-post filter
	 * overrules everything the per-post-type filter said.
	 *
	 * The Classic Editor plugin does exactly this, and by a route that is easy to
	 * miss. It has a settings-aware callback, `choose_editor()`, which honours
	 * `classic_editor_enabled_editors_for_post_type` and would have let popups
	 * through — but it only registers that callback when users are allowed to
	 * switch editors. On its default configuration it instead hooks
	 * `__return_false` straight onto `use_block_editor_for_post` at priority 100,
	 * and no filter of ours further up the chain is ever consulted.
	 *
	 * Measured on a real installation rather than reasoned about: the hook table
	 * carried `100: __return_false` and no `choose_editor` at all, while calling
	 * `choose_editor()` by hand returned `true`. Filtering only the post-type hook
	 * therefore produced a popup screen served by the classic editor with the
	 * block editor's sidebar — and, because the meta boxes deferred to what popkit
	 * *wanted* rather than to what WordPress *did*, no settings interface at all.
	 *
	 * @since 0.1.0
	 *
	 * @param bool     $use_block_editor Whether the post uses the block editor.
	 * @param \WP_Post $post             Post being edited.
	 * @return bool Whether the post uses the block editor.
	 */
	public static function filter_post( $use_block_editor, $post ): bool {
		if ( ! $post instanceof \WP_Post || Post_Type::POST_TYPE !== $post->post_type ) {
			return (bool) $use_block_editor;
		}

		return self::forced() ?? (bool) $use_block_editor;
	}

	/**
	 * Which editor WordPress has actually settled on for a given popup.
	 *
	 * {@see self::uses_block_editor()} is what popkit *wants*. This is what the
	 * site *did*, filters and all, and it is what the authoring surfaces gate on.
	 *
	 * The distinction is the whole lesson of this class. When the two disagreed —
	 * because the preference was expressed on a hook that did not decide — the
	 * classic screen was served while the classic meta boxes stood down, and a
	 * popup had nowhere to configure anything. Gating on the real answer means the
	 * worst a future conflict can do is put the panels on the other screen, rather
	 * than on neither.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post|null $post Popup being edited, when one is in hand.
	 * @return bool True when this popup is being authored in the block editor.
	 */
	public static function resolved_for_post( $post = null ): bool {
		if ( $post instanceof \WP_Post && function_exists( 'use_block_editor_for_post' ) ) {
			return (bool) use_block_editor_for_post( $post );
		}

		return self::uses_block_editor();
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
		$forced = self::forced();

		if ( null !== $forced ) {
			return $forced;
		}

		/*
		 * No override, so ask the site. This is only safe because the two filters
		 * above answer from `forced()` and never call back into this method — an
		 * earlier version had them return `uses_block_editor()`, which recursed
		 * through core until PHP exhausted memory. Do not "simplify" them back.
		 */
		if ( function_exists( 'use_block_editor_for_post_type' ) ) {
			return (bool) use_block_editor_for_post_type( Post_Type::POST_TYPE );
		}

		return true;
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

		/*
		 * `forced()`, never `uses_block_editor()`. The latter asks core when nobody
		 * has forced an answer, and core asks this filter — so answering with it
		 * recurses until PHP runs out of memory. That is not hypothetical: it took
		 * the test suite down with a 48 GB allocation the first time these two were
		 * wired together.
		 *
		 * Passing the incoming value through when nothing is forced is also the
		 * correct answer on its own terms: no opinion means leave it alone.
		 */
		return self::forced() ?? (bool) $use_block_editor;
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
