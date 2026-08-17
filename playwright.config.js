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
 * The WordPress under test is a standalone install served by PHP's built-in
 * server, not wp-env. wp-env cannot build its images on this network — its
 * Dockerfile runs an unauthenticated `composer global require` that returns
 * HTTP 429 from codeload.github.com, and retrying does not help. The
 * replacement, and the reasons behind every part of it, are documented in
 * `tests/e2e/README.md`.
 *
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { defineConfig, devices } = require( '@playwright/test' );

/**
 * Where the harness that stands up the WordPress instance lives.
 *
 * It sits outside the repository on purpose: it unpacks a WordPress release and
 * writes a database, neither of which belongs in a plugin's working tree.
 * Override with `POPKIT_E2E_DIR`. When the directory is absent — a fresh
 * checkout on another machine — no `webServer` is declared and the suite simply
 * runs against `WP_BASE_URL`, so this file stays loadable everywhere.
 */
const E2E_DIR =
	process.env.POPKIT_E2E_DIR ||
	'C:/Users/srskm/AppData/Local/Temp/claude/c--Users-srskm-Local-Sites-family-ties-of-westchester-app-public-wp-content-plugins-skm-popups/a171f5dd-f837-424f-a965-804c9b126faf/scratchpad/e2e-site';

const SERVE_SCRIPT = path.join( E2E_DIR, 'serve.mjs' );
const HAS_HARNESS = fs.existsSync( SERVE_SCRIPT );

/** 8899 avoids wp-env's 8888/8889 and the MySQL port the harness uses. */
const BASE_URL = process.env.WP_BASE_URL || 'http://127.0.0.1:8899';

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

	/*
	 * One worker, everywhere, and not only for the usual reason.
	 *
	 * The usual reason still applies: the suite shares a single WordPress
	 * install, and parallel workers race on options, posts, and the editor.
	 *
	 * The new one is the server. `php -S` handles one request at a time on
	 * Windows, where `PHP_CLI_SERVER_WORKERS` does not exist because it forks.
	 * A second worker does not run in parallel; it queues behind the first and
	 * spends its budget waiting, which turns a slow assertion into a timeout.
	 */
	workers: Number( process.env.PW_WORKERS ) || 1,

	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: 'playwright-report', open: 'never' } ],
	],

	/*
	 * Start the instance if nothing is answering on the port, and reuse it if
	 * something is. `reuseExistingServer` is on in CI too: the harness is a
	 * local process rather than a container, so a CI job that starts it in a
	 * prior step is the normal arrangement rather than a mistake.
	 *
	 * `serve.mjs` runs the idempotent provisioning script before it listens, so
	 * a cold machine reaches a seeded, plugin-activated install with no extra
	 * step. That costs a second when everything already exists and about a
	 * minute when the WordPress archive has to be downloaded, hence the timeout.
	 */
	...( HAS_HARNESS
		? {
				webServer: {
					command: `node "${ SERVE_SCRIPT }"`,
					url: BASE_URL,
					reuseExistingServer: true,
					timeout: 180 * 1000,
					stdout: 'pipe',
					stderr: 'pipe',
				},
		  }
		: {} ),

	use: {
		baseURL: BASE_URL,
		headless: true,
		ignoreHTTPSErrors: true,
		locale: 'en-US',
		actionTimeout: 10 * 1000,
		navigationTimeout: 20 * 1000,
		/*
		 * Traces without DOM snapshots.
		 *
		 * Snapshotting crashes the renderer on this machine — every spec dies
		 * with `page.evaluate: Target crashed`, and turning tracing off makes
		 * the same spec pass. Actions, network, console and screenshots are all
		 * still recorded, so a trace remains readable; what is lost is the
		 * time-travel DOM viewer. Re-test `trace: 'retain-on-failure'` after a
		 * Playwright or Chromium bump and put it back if it holds.
		 */
		trace: { mode: 'retain-on-failure', snapshots: false },
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
