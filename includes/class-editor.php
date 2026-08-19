<?php
/**
 * Block editor asset enqueue for the popup sidebar.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

use WP_Screen;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the editor bundle on the popup editing screen, and nowhere else.
 *
 * This class is the whole PHP side of the block editor UI. It enqueues
 * `dist/editor.js`, points the translation loader at it, and refuses to do
 * either when the build output is not there. Everything the sidebar renders is
 * decided in JavaScript from `GET /popkit/v1/registry`, so there is nothing else
 * for this class to do.
 *
 * ## Nothing is printed into the page
 *
 * No `wp_localize_script()`, no `wp_add_inline_script()`, no settings object.
 * That is deliberate and it is the registry invariant in `docs/CLAUDE.md`
 * expressed in the one place it would be easiest to break: field schemas are
 * declared in PHP and served over REST, and the editor fetches them at runtime.
 * Printing the registry here as well would create a second copy of it — one
 * frozen at page render, one live — and the two would disagree the moment a
 * plugin registered a condition. The editor would then render controls for a
 * vocabulary the server no longer has, or miss ones it does, with nothing
 * anywhere reporting a mismatch.
 *
 * There is also nothing visitor-varying to leak. This runs on an authenticated
 * admin screen that no page cache serves, so the cache-safety rules that shape
 * `Frontend` do not bind here — but the absence of emitted configuration means
 * the question never arises either.
 *
 * ## Scope
 *
 * `enqueue_block_editor_assets` fires for every block editor WordPress has: the
 * post editor for any post type, the site editor, the block-based widgets
 * screen, and the widgets panel in the customizer. A bundle enqueued from that
 * hook without a screen check loads a popup sidebar into somebody else's editor,
 * where it is visible, useless and reported as a bug against this plugin.
 * {@see Editor::is_popup_editor_screen()} is the gate, and it fails closed: an
 * unavailable or unrecognised screen enqueues nothing.
 *
 * ## A missing build is an error, not a quiet absence
 *
 * `dist/` is a build artifact and is not committed. A checkout where
 * `npm run build` never ran, or a release archive assembled without it, has an
 * editor screen that looks completely normal and simply has no popkit panels in
 * it. That is close to unreportable — the author has no way to tell a missing
 * bundle from a plugin that was never supposed to add anything. So the absence
 * is stated: {@see Editor::render_missing_build_notice()} prints an admin error
 * naming the file and the command that produces it, on the same screen the
 * panels are missing from.
 *
 * @since 0.1.0
 */
final class Editor {

	/**
	 * Handle shared by the editor script and its stylesheet.
	 *
	 * Public because a site owner replacing or extending the sidebar needs a name
	 * to depend on, dequeue, or hang an inline script off.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const HANDLE = 'popkit-editor';

	/**
	 * Editor bundle, relative to the plugin directory.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const SCRIPT_PATH = 'dist/editor.js';

	/**
	 * Editor stylesheet, relative to the plugin directory.
	 *
	 * Emitted by webpack only when the entry imports a stylesheet, which is why
	 * every use of it is guarded — see {@see Editor::enqueue_assets()}.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const STYLE_PATH = 'dist/editor.css';

	/**
	 * Dependency manifest webpack emits beside the bundle.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const ASSET_PATH = 'dist/editor.asset.php';

	/**
	 * Directory holding the plugin's translations, relative to the plugin.
	 *
	 * The same directory `Plugin::load_textdomain()` hands to
	 * `load_plugin_textdomain()`, and the one named in the plugin header's
	 * `Domain Path`. JSON catalogues for the editor bundle are built into it
	 * alongside the PHP ones.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const LANGUAGES_PATH = 'languages';

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Hooks the enqueue and the missing-build notice.
	 *
	 * Called from `Plugin::boot()` on `plugins_loaded`, which precedes both
	 * hooks. Neither is registered conditionally, and the notice in particular is
	 * attached independently rather than from inside the enqueue callback. The
	 * ordering is what makes that matter: `wp-admin/edit-form-blocks.php` fires
	 * `enqueue_block_editor_assets` and only afterwards requires
	 * `admin-header.php`, where `admin_notices` fires — so adding the notice from
	 * within the enqueue would happen to work on the post editor and quietly stop
	 * working anywhere the two run in the other order. Two independent callbacks,
	 * each deciding for itself when it fires, cannot develop that dependency.
	 *
	 * Both are array callables rather than first-class callables, for the reason
	 * given on `Rest_Schema::init()`: an array callable has a stable identity, so
	 * WordPress deduplicates it, a test can assert it by name, and a site owner
	 * can remove it.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( self::class, 'render_missing_build_notice' ) );
	}

	/**
	 * Enqueues the editor bundle on the popup editing screen.
	 *
	 * The dependency array and the version both come out of
	 * `dist/editor.asset.php` rather than being written here, and that is the
	 * mechanism keeping a second copy of React out of the bundle:
	 * `@wordpress/dependency-extraction-webpack-plugin` rewrites every
	 * `@wordpress/*` import to the matching `wp-*` global and records the script
	 * handle it needs in that file. Hand-writing the list would leave a genuine
	 * import unlisted — the module would still be externalised, the global would
	 * not be there yet, and the sidebar would fail with a reference error on a
	 * screen where nothing else looked wrong.
	 *
	 * The version is the content hash from the same file, not `POPKIT_VERSION`.
	 * The bundle changes on every build; the plugin version changes on release.
	 * Using the release version would serve a stale bundle from the browser cache
	 * to anyone who had opened the editor before an update that did not bump it.
	 *
	 * `wp_set_script_translations()` is called after the enqueue, which is the
	 * order it requires: it records the domain against a registered handle and
	 * adds `wp-i18n` to that handle's dependencies. Without it every
	 * `__( …, 'popkit' )` in the bundle returns its English source string, and
	 * nothing reports that — an untranslated string looks exactly like a
	 * translated one to a reviewer reading English.
	 *
	 * The stylesheet is enqueued only when the build emitted one. webpack writes
	 * `dist/editor.css` only if the entry imports a stylesheet, and the panels are
	 * built from `@wordpress/components`, whose styles the block editor screen has
	 * already loaded through `wp-edit-post`. Enqueueing the path unconditionally
	 * would put a 404 on every popup editor screen for a file that is correctly
	 * absent.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! self::is_popup_editor_screen() ) {
			return;
		}

		/*
		 * On a site whose popups are authored in the classic editor,
		 * {@see Classic_Editor} owns this popup and the sidebar must not load —
		 * two surfaces writing the same meta is how a popup's settings start
		 * depending on which screen saved last.
		 *
		 * Asked here rather than at boot, because `Plugin::boot()` runs on
		 * `plugins_loaded` and a theme's `functions.php` is read afterwards. And
		 * asked of the *resolved* editor rather than of popkit's preference: see
		 * Editor_Mode for what happened the one time those two disagreed.
		 */
		if ( ! Editor_Mode::resolved_for_post( get_post() ) ) {
			return;
		}

		$asset = self::asset_manifest();

		if ( null === $asset ) {
			return;
		}

		/*
		 * The final `true` puts the script in the footer.
		 * `enqueue_block_editor_assets` already fires late enough that the
		 * position is largely academic, but leaving the argument off is what
		 * PHPCS flags: an omitted default reads as an oversight rather than a
		 * decision, and the next reader cannot tell which it was.
		 */
		wp_enqueue_script(
			self::HANDLE,
			POPKIT_URL . self::SCRIPT_PATH,
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( self::HANDLE, 'popkit', POPKIT_DIR . self::LANGUAGES_PATH );

		if ( ! file_exists( POPKIT_DIR . self::STYLE_PATH ) ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			POPKIT_URL . self::STYLE_PATH,
			array( 'wp-components' ),
			$asset['version']
		);
	}

	/**
	 * Says so, in the admin, when there is no editor bundle to load.
	 *
	 * Prints nothing on any screen the sidebar would not have loaded on, and
	 * nothing at all once the build is present — so on a working install this
	 * callback is a screen comparison and a `file_exists()`.
	 *
	 * The notice names the file and the command, because the two audiences who
	 * see it need different halves of that: a developer who has just cloned the
	 * repository needs the command, and someone looking at a broken deploy needs
	 * to know which artifact is missing from it.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render_missing_build_notice(): void {
		if ( ! self::is_popup_editor_screen() ) {
			return;
		}

		if ( null !== self::asset_manifest() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'The PopKit editor panels could not be loaded.', 'popkit' ),
			esc_html(
				sprintf(
					/* translators: 1: build command to run, 2: path of the missing file, relative to the plugin directory. */
					__( 'This popup can still be edited, but its targeting, triggers, schedule, frequency and display settings are unavailable until the plugin is built. Run %1$s in the plugin directory to generate %2$s, then reload this screen.', 'popkit' ),
					'`npm run build`',
					self::ASSET_PATH
				)
			)
		);
	}

	/**
	 * Whether this request is editing a popup in the block editor.
	 *
	 * Both halves of the test earn their place. `post_type` is what keeps the
	 * bundle out of every other post type's editor; `is_block_editor()` is what
	 * keeps it off the popup list table, the classic editor fallback, and any
	 * other popup screen a future phase adds — all of which share the post type
	 * and none of which have a block editor sidebar to mount into.
	 *
	 * The site editor, the block-based widgets screen and the customizer widgets
	 * panel all fire `enqueue_block_editor_assets` too, and all three are excluded
	 * by the post type: none of them has one.
	 *
	 * A screen that cannot be resolved returns false rather than guessing. The
	 * function is loaded and the screen is set well before either hook this class
	 * uses — `wp-admin/post.php` calls `set_current_screen()` and
	 * `edit-form-blocks.php` marks the screen as a block editor before firing —
	 * so in practice the guard never fires on the screen that matters. Where it
	 * would fire is somewhere unforeseen, and there the honest answer is that
	 * nothing has established this is a popup screen. Loading the bundle on an
	 * unknown screen is the failure this method exists to prevent.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True on the block editor screen for a `popkit_popup`.
	 */
	private static function is_popup_editor_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		if ( ! $screen instanceof WP_Screen ) {
			return false;
		}

		return Post_Type::POST_TYPE === $screen->post_type && $screen->is_block_editor();
	}

	/**
	 * Reads and validates the manifest webpack emits beside the bundle.
	 *
	 * Returns null for every way the build can be absent or unusable, so the two
	 * callers share one definition of "there is no editor to load" and cannot
	 * drift into enqueueing a bundle the notice says is missing, or the reverse.
	 * The script file is checked as well as the manifest: they are written
	 * together, so one without the other is a partial copy, and enqueueing a URL
	 * that 404s produces exactly the silently absent sidebar this class exists to
	 * make impossible.
	 *
	 * `require` and not `require_once`, which is load bearing rather than
	 * stylistic. The manifest is a file whose entire body is `return array( … );`,
	 * and `require_once` returns `true` — not the array — on every call after the
	 * first. Both callers run in a single request on the popup editor screen, so
	 * `require_once` would hand the second one a boolean, and depending on which
	 * ran first the result would be either an enqueue with no dependencies or a
	 * missing-build notice on an install that is built and working.
	 *
	 * The shape is checked rather than trusted. The file is generated, but it is
	 * also plain PHP sitting in a directory a deploy tool writes to, and a
	 * truncated or half-written copy reaching `wp_enqueue_script()` would raise a
	 * type error on an admin screen instead of the notice this returns null to
	 * produce.
	 *
	 * @since 0.1.0
	 *
	 * @return array{dependencies: string[], version: string}|null Manifest, or null when the build is missing or unusable.
	 */
	private static function asset_manifest(): ?array {
		if ( ! file_exists( POPKIT_DIR . self::SCRIPT_PATH ) || ! file_exists( POPKIT_DIR . self::ASSET_PATH ) ) {
			return null;
		}

		$manifest = require POPKIT_DIR . self::ASSET_PATH;

		if ( ! is_array( $manifest ) ) {
			return null;
		}

		$dependencies = $manifest['dependencies'] ?? null;
		$version      = $manifest['version'] ?? null;

		if ( ! is_array( $dependencies ) || ! is_string( $version ) || '' === $version ) {
			return null;
		}

		foreach ( $dependencies as $handle ) {
			if ( ! is_string( $handle ) || '' === $handle ) {
				return null;
			}
		}

		/*
		 * Re-indexed because wp_enqueue_script() expects a list. The generated
		 * file always emits one; a hand-edited copy might not, and a dependency
		 * array with string keys is silently ignored rather than rejected.
		 */
		return array(
			'dependencies' => array_values( $dependencies ),
			'version'      => $version,
		);
	}
}
