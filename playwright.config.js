/**
 * Playwright configuration for popkit end-to-end tests.
 *
 * Follows the conventions of `@wordpress/e2e-test-utils-playwright`: the same
 * environment variables (`WP_BASE_URL`, `WP_USERNAME`, `WP_PASSWORD`,
 * `WP_ARTIFACTS_PATH`, `STORAGE_STATE_PATH`) are read and defaulted here, so a
 * spec can import that package's fixtures without further wiring.
 *
 * There is deliberately no `globalSetup` and no config-level `storageState`:
 * a `storageState` path that does not exist yet makes every test fail before it
 * runs. Specs that need an authenticated admin session use the `requestUtils`
 * fixture, which creates the storage state on demand at
 * `STORAGE_STATE_PATH`. See `tests/e2e/README.md`.
 *
 * The WordPress environment is expected to be running already
 * (`npx wp-env start`). No `webServer` block is declared, matching WordPress
 * core/Gutenberg practice — starting Docker implicitly from a test runner hides
 * infrastructure failures inside test failures.
 *
 */

const path = require( 'path' );
const { defineConfig, devices } = require( '@playwright/test' );

/**
 * The wp-env *tests* environment listens on 8889; development is 8888.
 * E2E runs against the tests environment so it can be reset freely.
 */
const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8889';

/** Artifacts (traces, screenshots, storage state) land outside the report dir. */
const ARTIFACTS_PATH =
	process.env.WP_ARTIFACTS_PATH || path.join( __dirname, 'artifacts' );

// Defaults consumed by @wordpress/e2e-test-utils-playwright fixtures.
process.env.WP_BASE_URL = BASE_URL;
process.env.WP_ARTIFACTS_PATH = ARTIFACTS_PATH;
process.env.WP_USERNAME = process.env.WP_USERNAME || 'admin';
process.env.WP_PASSWORD = process.env.WP_PASSWORD || 'password';
process.env.STORAGE_STATE_PATH =
	process.env.STORAGE_STATE_PATH ||
	path.join( ARTIFACTS_PATH, 'storage-states', 'admin.json' );

const IS_CI = !! process.env.CI;

/**
 * Pixel 7 was added to Playwright's device registry relatively recently; fall
 * back to Pixel 5 so an older Playwright does not spread `undefined` and throw.
 */
const MOBILE_DEVICE = devices[ 'Pixel 7' ] || devices[ 'Pixel 5' ];

module.exports = defineConfig( {
	testDir: './tests/e2e',
	testMatch: '**/*.spec.js',
	outputDir: path.join( ARTIFACTS_PATH, 'test-results' ),
	snapshotPathTemplate:
		'{testDir}/{testFileDir}/__snapshots__/{arg}-{projectName}{ext}',

	// A WordPress page load is slow, but not this slow — a 30s ceiling keeps a
	// hung dialog or an unresolved fetch from stalling the suite.
	timeout: 30 * 1000,
	expect: {
		timeout: 10 * 1000,
	},

	fullyParallel: true,
	forbidOnly: IS_CI,
	retries: IS_CI ? 1 : 0,
	// One worker on CI: the suite shares a single WordPress install, and
	// parallel workers race on options, posts, and the block editor.
	workers: IS_CI ? 1 : undefined,

	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: 'playwright-report', open: 'never' } ],
	],

	use: {
		baseURL: BASE_URL,
		headless: true,
		ignoreHTTPSErrors: true,
		locale: 'en-US',
		actionTimeout: 10 * 1000,
		navigationTimeout: 20 * 1000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'on-first-retry',
		contextOptions: {
			// Accessibility specs emulate `prefers-reduced-motion` explicitly;
			// the default must therefore be the un-emulated state.
			reducedMotion: 'no-preference',
			strictSelectors: true,
		},
	},

	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				viewport: { width: 1280, height: 800 },
			},
		},
		{
			// Touch, small-viewport, and 44x44 hit-area assertions.
			name: 'mobile',
			use: {
				...MOBILE_DEVICE,
			},
		},
	],
} );
