/**
 * ESLint flat configuration for popkit.
 *
 * ESLint 10 removed `.eslintrc.*` support entirely, and `wp-scripts lint-js`
 * only detects `eslint.config.{js,mjs,cjs,ts,mts,cts}` — when none is present it
 * warns and silently substitutes its own bundled default config. An `.eslintrc`
 * file in this project is therefore not merely legacy, it is inert. This file is
 * the single source of lint truth, which matters because the hard constraints in
 * `docs/CLAUDE.md` are enforced here and nowhere else in the JS toolchain.
 *
 * `@wordpress/eslint-plugin` v25 exports flat-config *arrays* from its package
 * root, so the shareable config is spread rather than extended. Later objects
 * win, so the ordering below is: ignores, base, project-wide rules, then the
 * narrowing overrides.
 *
 * This file is CommonJS. `package.json` declares no `"type"`, so a bare `.js`
 * extension means CommonJS and `require()` is correct here.
 */

const wordpress = require( '@wordpress/eslint-plugin' );

/**
 * Pointer appended to every frontend-bundle restriction so a developer who trips
 * one lands on the rule that motivated it rather than on a bare rule name.
 */
const CONSTITUTION = 'docs/CLAUDE.md -> Hard constraints';

/**
 * Modules that may never be imported by the frontend bundle.
 *
 * The bundle is vanilla JS with no externals and a hard 8 KB gzipped budget; any
 * of these blows through it, and a framework runtime is a non-goal regardless of
 * size.
 */
const FRONTEND_RESTRICTED_PATHS = [
	{
		name: 'jquery',
		message: `No jQuery. Ever — not in the frontend, not in the admin. Use document.querySelector / querySelectorAll (${ CONSTITUTION }).`,
	},
	{
		name: 'react',
		message: `No framework in the frontend bundle. React belongs in src/editor only (${ CONSTITUTION }).`,
	},
	{
		name: 'react-dom',
		message: `No framework in the frontend bundle. React belongs in src/editor only (${ CONSTITUTION }).`,
	},
	{
		name: 'preact',
		message: `No framework in the frontend bundle (${ CONSTITUTION }).`,
	},
	{
		name: 'vue',
		message: `No framework in the frontend bundle (${ CONSTITUTION }).`,
	},
	{
		name: 'alpinejs',
		message: `No framework in the frontend bundle (${ CONSTITUTION }).`,
	},
];

/**
 * Import patterns banned in the frontend bundle.
 *
 * `@wordpress/*` covers the top-level packages and `@wordpress/**` the deep
 * paths, because the single-star form does not match a slash.
 */
const FRONTEND_RESTRICTED_PATTERNS = [
	{
		group: [ '@wordpress/*', '@wordpress/**' ],
		message: `No @wordpress/* imports in the frontend bundle (${ CONSTITUTION }). That includes the Interactivity API, which exceeds the frontend budget on its own. Put shared code in src/shared or inline the few lines you need.`,
	},
];

/**
 * Globals the frontend bundle may not reference.
 *
 * `wp` is declared readonly by the WordPress base config, so without this rule a
 * reference to it would lint clean and then fail at runtime: the frontend bundle
 * is enqueued without `wp-*` dependencies and the global is simply absent.
 */
const FRONTEND_RESTRICTED_GLOBALS = [
	{
		name: 'jQuery',
		message: `No jQuery. Ever — not in the frontend, not in the admin (${ CONSTITUTION }).`,
	},
	{
		name: '$',
		message: `No jQuery. Use document.querySelector / querySelectorAll (${ CONSTITUTION }).`,
	},
	{
		name: 'wp',
		message: `The global \`wp\` object is not available to the frontend bundle. Read configuration from the #popkit-config JSON script instead (${ CONSTITUTION }).`,
	},
];

/**
 * The `definedTypes` list `@wordpress/eslint-plugin` already declares.
 *
 * Read out of the shareable config rather than copied, because it is roughly a
 * thousand entries long, it changes between releases, and a stale copy would
 * fail in the direction that looks like a code problem: a type the config knows
 * about being reported as undefined in a file nobody touched.
 *
 * Two configs in the array name this rule — one sets the bare severity `'warn'`,
 * the other supplies the options — so the search is for a value carrying
 * `definedTypes` rather than for the first mention. Taking the first mention
 * yields the string, `[1]` is `undefined`, and the list silently comes back
 * empty, which is the same breakage as hardcoding it wrong.
 *
 * Falls back to an empty list if the shape ever changes, which degrades to the
 * rule's own defaults instead of throwing while ESLint loads its config.
 */
const INHERITED_JSDOC_TYPES =
	wordpress.configs.recommended
		.map( ( config ) => config?.rules?.[ 'jsdoc/no-undefined-types' ] )
		.filter( ( rule ) => Array.isArray( rule ) && rule[ 1 ]?.definedTypes )
		.pop()?.[ 1 ]?.definedTypes ?? [];

module.exports = [
	// Global ignores. Build output, vendored code, and test artifacts are not
	// authored source and must never be linted.
	{
		ignores: [
			'dist/**',
			'build/**',
			'node_modules/**',
			'vendor/**',
			'languages/**',
			// Prefixed with `**/` so the Playwright output directories are
			// ignored wherever WP_ARTIFACTS_PATH puts them, which by default is
			// `artifacts/test-results` rather than the repository root.
			'**/playwright-report/**',
			'**/test-results/**',
		],
	},

	// WordPress base: jsx-a11y, custom WP rules, React, ESNext, i18n, import
	// resolution, and — because prettier is installed — Prettier formatting.
	...wordpress.configs.recommended,

	// Project-wide rule adjustments.
	{
		rules: {
			// Every translatable string in this plugin uses the `popkit` text
			// domain. Without the explicit allowlist this rule only checks that
			// *a* domain is present, not that it is the right one.
			'@wordpress/i18n-text-domain': [
				'error',
				{
					allowedTextDomain: [ 'popkit' ],
				},
			],

			/*
			 * JSX pragmas are real Babel directives, not documentation, but they
			 * are written as docblock tags and `jsdoc/check-tag-names` rejects
			 * every tag it does not recognize. Declaring them here is what lets
			 * an editor file opt into `@wordpress/element`'s JSX runtime without
			 * three lint errors at the top of the file — and without anyone
			 * reaching for an inline disable comment, which is the workaround
			 * that spreads.
			 */
			'jsdoc/check-tag-names': [
				'error',
				{
					definedTags: [
						'jsx',
						'jsxFrag',
						'jsxRuntime',
						'jsxImportSource',
					],
				},
			],

			/*
			 * `JSX.Element` is the return type of every component in the editor
			 * bundle and is the type React's own ecosystem uses, but the
			 * namespace comes from TypeScript's global scope and this project
			 * ships no TypeScript for the rule to find it in.
			 *
			 * The existing list is spread rather than replaced, which is the
			 * whole point of computing it above. `definedTypes` is not merged by
			 * ESLint — the last config to name the rule supplies its options
			 * outright — so writing `{ definedTypes: [ 'JSX' ] }` here would
			 * silently drop every browser global, TypeScript utility type and
			 * WordPress internal type the shareable config declares, and turn
			 * `{Element}` and `{PageTransitionEvent}` in the *frontend* bundle
			 * into errors. It did exactly that once.
			 */
			'jsdoc/no-undefined-types': [
				'error',
				{ definedTypes: [ ...INHERITED_JSDOC_TYPES, 'JSX' ] },
			],
		},
	},

	// The frontend bundle. These two rules are the machine-checkable half of
	// `docs/CLAUDE.md` -> Hard constraints; `npm run size` is the other half.
	{
		files: [ 'src/frontend/**' ],
		rules: {
			'no-restricted-imports': [
				'error',
				{
					paths: FRONTEND_RESTRICTED_PATHS,
					patterns: FRONTEND_RESTRICTED_PATTERNS,
				},
			],
			'no-restricted-globals': [
				'error',
				...FRONTEND_RESTRICTED_GLOBALS,
			],
		},
	},

	// CommonJS sources.
	//
	// The WordPress base config pins `parserOptions.sourceType` to `module`, and
	// ESLint gives `languageOptions.parserOptions` precedence over
	// `languageOptions.sourceType` when handing options to a custom parser (see
	// `eslint/lib/languages/js/index.js` -> `parse()`). Setting only the latter
	// would be silently ignored. `@babel/eslint-parser` accepts `script`,
	// `module`, or `unambiguous` — never `commonjs` — so the two keys carry
	// deliberately different values: `commonjs` for ESLint's own scope handling,
	// `script` for Babel.
	{
		files: [ '*.config.js', '**/*.cjs', 'tests/e2e/**/*.js' ],
		languageOptions: {
			sourceType: 'commonjs',
			parserOptions: {
				sourceType: 'script',
			},
		},
	},

	// End-to-end specs. Playwright's fixtures are destructured from the test
	// callback rather than documented, and these files export nothing.
	{
		files: [ 'tests/e2e/**' ],
		rules: {
			'jsdoc/require-param': 'off',
			'jsdoc/require-returns': 'off',
		},
	},
];
