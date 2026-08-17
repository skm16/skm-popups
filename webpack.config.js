/**
 * webpack configuration for the popkit editor bundle.
 *
 * `wp-scripts build` defaults to `src/index.js` -> `build/index.js`. Passing
 * `--webpack-src-dir=src/editor --output-path=dist/editor` moves the directory
 * but not the *file* name, so it emits `dist/editor/index.js` and
 * `dist/editor/index.asset.php`. The build plan requires `dist/editor.js` and
 * `dist/editor.asset.php` — a flat pair of files, not a directory. Naming the
 * webpack entry `editor` is the only way to get there: the default config emits
 * `output.filename = '[name].js'`, and
 * `@wordpress/dependency-extraction-webpack-plugin` derives the asset file by
 * swapping the chunk's `.js` suffix for `.asset.php`. Entry `editor` in
 * `dist/` therefore yields exactly the two required paths.
 *
 * The frontend bundle is *not* built here. It is esbuild's (see
 * `esbuild.config.mjs`) precisely so that no `@wordpress/*` runtime can reach it.
 *
 * This file is CommonJS: `package.json` declares no `"type"`, so a bare `.js`
 * extension means CommonJS, which is also what webpack expects.
 */

const path = require( 'path' );

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,

	// Replaces wp-scripts' `getWebpackEntryPoints( 'script' )` discovery, which
	// globs for block.json files and falls back to `src/index.js`. popkit has a
	// single, explicitly named editor entry and no blocks.
	entry: {
		editor: path.resolve( __dirname, 'src/editor/index.js' ),
	},

	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'dist' ),

		// `dist/` is shared with the esbuild frontend build, and `npm run build`
		// runs the frontend first. wp-scripts' default `output.clean` would wipe
		// `dist/frontend.js` and `dist/frontend.css` on every editor build,
		// breaking `npm run size` in a way that only reproduces when the two
		// builds run in sequence. Stale editor output is still cleaned; the
		// esbuild artifacts are preserved by name.
		clean: {
			keep: /^(fonts\/|images\/|frontend\.)/,
		},
	},
};
