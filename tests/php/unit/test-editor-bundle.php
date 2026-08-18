<?php
/**
 * Guards on what the built editor bundle is allowed to depend on.
 *
 * Two failures this catches, both of which produce an editor screen that looks
 * entirely normal and simply has no popkit panels in it.
 *
 * ## A second copy of React
 *
 * `@wordpress/dependency-extraction-webpack-plugin` rewrites `@wordpress/*`
 * imports to `wp-*` script handles. When it does not — a misconfigured build, an
 * import of `react` by name, a dependency that bundles its own copy — React ends
 * up *inside* `dist/editor.js`. Two Reacts in one page do not throw. They render
 * two component trees with two sets of hooks, and the failure is a sidebar whose
 * state silently stops updating.
 *
 * ## A script handle the minimum WordPress does not register
 *
 * This is not hypothetical: the first build of this bundle depended on
 * `react-jsx-runtime`, which `@wordpress/babel-preset-default` produces from its
 * hardcoded `runtime: 'automatic'` and which WordPress did not register until
 * 6.6. The plugin header says `Requires at least: 6.5`.
 *
 * `WP_Dependencies::do_item()` skips a dependency it cannot resolve without
 * raising anything, so on 6.5 the bundle would enqueue, load, and throw a
 * `ReferenceError` on its first render — with no notice, no PHP error, and no
 * failing test anywhere.
 *
 * The allowlist below is deliberately an allowlist rather than a denylist of
 * known-late handles. A new import should have to be checked against the
 * minimum supported version *once, by a person*, and adding a line here is
 * where that happens. A denylist only catches the mistakes already made.
 *
 * @package Popkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the built editor bundle's declared dependencies.
 *
 * A unit test rather than an integration one: it reads two files off disk and
 * needs no WordPress, no database and no bootstrapped plugin.
 */
final class Test_Popkit_Editor_Bundle extends TestCase {

	/**
	 * Script handles the editor bundle may depend on.
	 *
	 * Every entry was verified present in the WordPress 6.5.5 package manifest
	 * (`wp-includes/assets/script-loader-packages.php`), which is the floor this
	 * plugin supports. `react-jsx-runtime` is absent from that manifest and is
	 * therefore absent from this list — see the class docblock.
	 *
	 * @var string[]
	 */
	private const ALLOWED_HANDLES = array(
		'wp-api-fetch',
		'wp-block-editor',
		'wp-components',
		'wp-core-data',
		'wp-data',
		'wp-edit-post',
		'wp-editor',
		'wp-element',
		'wp-i18n',
		'wp-plugins',
	);

	/**
	 * Handles that would mean a framework runtime shipped inside the bundle.
	 *
	 * `react` and `react-dom` are legitimate *core* handles — `wp-element`
	 * depends on both — but the editor bundle must reach React through
	 * `wp-element` rather than naming it, so seeing either here means an import
	 * escaped extraction.
	 *
	 * @var string[]
	 */
	private const FRAMEWORK_HANDLES = array( 'react', 'react-dom', 'react-jsx-runtime' );

	/**
	 * Plugin root directory, with forward slashes.
	 *
	 * @return string Absolute path.
	 */
	private function plugin_root(): string {
		return rtrim( str_replace( '\\', '/', dirname( __DIR__, 3 ) ), '/' );
	}

	/**
	 * Reads the emitted asset manifest.
	 *
	 * Skips rather than fails when the build has not run. `dist/` is a build
	 * artifact and is not committed, so a fresh checkout legitimately has no
	 * manifest to read — and a test that failed there would fail for everyone who
	 * had not yet run `npm run build`, which trains people to ignore it. The skip
	 * message names the command, so the coverage gap is visible rather than
	 * silent.
	 *
	 * @return array<string, mixed> Manifest.
	 */
	private function manifest(): array {
		$path = $this->plugin_root() . '/dist/editor.asset.php';

		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped(
				'dist/editor.asset.php is absent, so the built bundle cannot be inspected. Run `npm run build` to cover this.'
			);
		}

		$manifest = require $path;

		$this->assertIsArray( $manifest, 'The emitted asset manifest is not an array.' );
		$this->assertArrayHasKey( 'dependencies', $manifest, 'The emitted asset manifest declares no dependencies key.' );
		$this->assertIsArray( $manifest['dependencies'], 'The emitted dependency list is not an array.' );

		return $manifest;
	}

	/**
	 * No framework runtime is named in the dependency list.
	 *
	 * @return void
	 */
	public function test_the_bundle_does_not_carry_its_own_react() {
		$dependencies = $this->manifest()['dependencies'];

		foreach ( self::FRAMEWORK_HANDLES as $handle ) {
			$this->assertNotContains(
				$handle,
				$dependencies,
				sprintf(
					'The editor bundle declares "%s". React must be reached through wp-element, which every supported WordPress registers. A bundle carrying its own React renders a second component tree with a second set of hooks, and the sidebar stops updating without throwing.',
					$handle
				)
			);
		}
	}

	/**
	 * The bundle source contains no inlined React.
	 *
	 * The manifest test above catches a *declared* framework dependency. This one
	 * catches the opposite failure, where extraction did not happen at all and
	 * React was compiled into the chunk — in which case nothing appears in the
	 * dependency list to notice.
	 *
	 * `createElement` is the marker rather than the string "react", which appears
	 * in ordinary minified output. Under the classic JSX runtime the bundle calls
	 * `createElement` through the `wp-element` global, so the bare identifier as a
	 * *definition* would mean a local copy.
	 *
	 * @return void
	 */
	public function test_the_bundle_reaches_react_through_the_wp_element_global() {
		$path = $this->plugin_root() . '/dist/editor.js';

		if ( ! file_exists( $path ) ) {
			$this->markTestSkipped( 'dist/editor.js is absent. Run `npm run build` to cover this.' );
		}

		$source = (string) file_get_contents( $path );

		$this->assertStringContainsString(
			'window.wp.element',
			$source,
			'The bundle never reads the wp-element global, so whatever it renders with is not the WordPress copy of React.'
		);

		$this->assertStringNotContainsString(
			'react.development.js',
			$source,
			'A development build of React is inlined in the shipped bundle.'
		);
	}

	/**
	 * Every declared dependency exists in the minimum supported WordPress.
	 *
	 * @return void
	 */
	public function test_every_dependency_exists_in_the_minimum_supported_wordpress() {
		$dependencies = $this->manifest()['dependencies'];

		$this->assertNotSame(
			array(),
			$dependencies,
			'The editor bundle declares no dependencies at all, which means it imports nothing from WordPress — so it cannot be rendering a sidebar. This assertion exists so the loop below cannot pass vacuously.'
		);

		foreach ( $dependencies as $handle ) {
			$this->assertContains(
				$handle,
				self::ALLOWED_HANDLES,
				sprintf(
					'The editor bundle depends on "%s", which is not on the list of handles verified present in WordPress %s. WordPress skips a dependency it cannot resolve without raising anything, so if this handle is missing there the bundle loads and then throws on first render, with nothing reporting it. Verify the handle exists in that version and add it to ALLOWED_HANDLES, or remove the import.',
					$handle,
					defined( 'POPKIT_MIN_WP' ) ? POPKIT_MIN_WP : '6.5'
				)
			);
		}
	}
}
