/**
 * Runs the WordPress Plugin Check plugin against popkit inside wp-env and turns
 * its findings into a process exit code.
 *
 * This exists because `wp plugin check` reports findings but always exits 0 — it
 * calls `WP_CLI::success()` when clean and simply prints a table otherwise. Wired
 * straight into an npm script it is a permanent false green on a command that
 * `docs/CLAUDE.md` -> Verification lists as mandatory. So the output is captured,
 * echoed, and interpreted here.
 *
 * `docs/CLAUDE.md` requires zero errors *and* zero warnings, so both fail the
 * run. Findings of any other type are reported but not fatal.
 *
 * Fails closed: output this script cannot interpret is treated as a failure
 * rather than a pass. A verification command that cannot prove success must not
 * report success.
 *
 * Prerequisites: `npm run env:start`. Plugin Check itself is installed and
 * activated by `.wp-env.json`, so no separate install step is needed.
 *
 * Usage:
 *   npm run plugin-check
 *   npm run plugin-check -- --checks=i18n_usage
 *
 * ## When wp-env cannot start
 *
 * On this machine it cannot: the image build runs an unauthenticated
 * `composer global require` and gets HTTP 429 from codeload.github.com, which is
 * `tests/e2e/README.md`'s long-standing note. Plugin Check still has to be run,
 * so it is run against the isolated end-to-end WordPress instead:
 *
 *   php wp-cli.phar --path=<e2e docroot> plugin install plugin-check --activate
 *   php wp-cli.phar --path=<e2e docroot> plugin check popkit --format=csv
 *
 * with `WP_CONFIG_PATH` pointed at `e2e-site/wp-config-e2e.php`, because WP-CLI
 * refuses the generated `wp-config.php` shim — it looks for a direct
 * `wp-settings.php` require and the shim only carries a pointer.
 *
 * ## Check the archive, not the working tree
 *
 * This matters more than the transport. Run against the checkout, Plugin Check
 * reported 38 errors and 28 warnings, and **every one** was a development file:
 * `tests/`, `.github`, `.gitignore`, `phpunit.xml.dist`, a stray Playwright
 * trace. None of it ships. Run against `build/popkit` — what `npm run build:zip`
 * assembles and what a user actually installs — the same plugin reports zero and
 * zero.
 *
 * So the meaningful invocation points at the staged build. Checking the working
 * tree measures the repository, which is not the thing being submitted.
 */

import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import path from 'node:path';
import process from 'node:process';

const require = createRequire( import.meta.url );

/**
 * Path to the plugin *inside the container*, relative to the WordPress root.
 *
 * Deliberately not derived from the host directory name. `@wordpress/env` mounts
 * the project according to `.wp-env.json`, which maps popkit to
 * `wp-content/plugins/popkit` whatever the checkout is called; the CI workflow
 * pins the same value in `POPKIT_WP_ENV_PLUGIN_PATH`. Plugin Check resolves a
 * directory argument to a slug via `basename()`, so this must match the mount,
 * not the repository folder.
 */
const PLUGIN_PATH =
	process.env.POPKIT_WP_ENV_PLUGIN_PATH || 'wp-content/plugins/popkit';

/** The container filesystem root for both wp-env WordPress containers. */
const CONTAINER_WP_ROOT = '/var/www/html';

/**
 * Directories excluded in addition to Plugin Check's own defaults.
 *
 * All are development-only and never present in a release build, so scanning
 * them costs time and can only produce findings about files that do not ship.
 */
const EXCLUDED_DIRECTORIES = [
	'node_modules',
	'vendor',
	'artifacts',
	'playwright-report',
	'test-results',
	'coverage',
];

/**
 * Resolves the `wp-env` entry script so it can be run under the current Node
 * binary. Spawning the `.bin` shim directly is not portable — on Windows it is a
 * `.cmd` file, which needs a shell.
 *
 * @return {string} Absolute path to the wp-env CLI script.
 */
function resolveWpEnvBin() {
	const manifestPath = require.resolve( '@wordpress/env/package.json' );
	const manifest = require( manifestPath );
	const bin =
		typeof manifest.bin === 'string'
			? manifest.bin
			: manifest.bin[ 'wp-env' ];

	return path.join( path.dirname( manifestPath ), bin );
}

/**
 * Extracts Plugin Check findings from captured stdout.
 *
 * `--format=json` does not produce one parseable document: Plugin Check prints an
 * unconditional `FILE: <name>` header before each file's JSON array, and
 * `WP_CLI::success()` adds a trailing line. Each array is emitted on a single
 * line, so the arrays are parsed individually and concatenated.
 *
 * @param {string} stdout Raw standard output from the container.
 * @return {{ items: Object[], arrays: number }} Findings, and how many JSON
 *                                               arrays were successfully read.
 */
function parseFindings( stdout ) {
	const items = [];
	let arrays = 0;

	for ( const line of stdout.split( /\r?\n/ ) ) {
		const trimmed = line.trim();

		if ( ! trimmed.startsWith( '[' ) || ! trimmed.endsWith( ']' ) ) {
			continue;
		}

		let parsed;

		try {
			parsed = JSON.parse( trimmed );
		} catch {
			continue;
		}

		if ( ! Array.isArray( parsed ) ) {
			continue;
		}

		arrays += 1;
		items.push( ...parsed );
	}

	return { items, arrays };
}

/**
 * Counts findings by type, case-insensitively.
 *
 * @param {Object[]} items Findings.
 * @param {string}   type  Type to count, e.g. `ERROR`.
 * @return {number} Number of matching findings.
 */
function countType( items, type ) {
	return items.filter(
		( item ) => String( item?.type ?? '' ).toUpperCase() === type
	).length;
}

/**
 * Writes a failure message and exits non-zero.
 *
 * @param {string} message Reason for failure.
 * @return {never} Does not return; the process exits.
 */
function fail( message ) {
	process.stderr.write( `popkit: plugin-check failed — ${ message }\n` );
	process.exit( 1 );
}

const forwardedArgs = process.argv.slice( 2 );

const result = spawnSync(
	process.execPath,
	[
		resolveWpEnvBin(),
		'run',
		'cli',
		'wp',
		'plugin',
		'check',
		path.posix.join( CONTAINER_WP_ROOT, PLUGIN_PATH ),
		'--format=json',
		`--exclude-directories=${ EXCLUDED_DIRECTORIES.join( ',' ) }`,
		...forwardedArgs,
	],
	{
		// stdout is captured so it can be interpreted; stderr passes straight
		// through so wp-env's progress output and any Docker error stay visible.
		stdio: [ 'ignore', 'pipe', 'inherit' ],
		encoding: 'utf8',
		env: process.env,
	}
);

if ( result.error ) {
	fail( `could not run wp-env (${ result.error.message }).` );
}

const stdout = result.stdout ?? '';

process.stdout.write( stdout );

const { items, arrays } = parseFindings( stdout );

// A non-zero exit from wp-env means the command never ran to completion —
// Docker is down, the environment was not started, or the plugin path does not
// resolve inside the container. Report that rather than an empty result set.
if ( 0 !== result.status && 0 === arrays ) {
	fail(
		`wp-env exited with code ${ result.status }. Is the environment running (npm run env:start), and does ${ PLUGIN_PATH } exist in the container?`
	);
}

const errors = countType( items, 'ERROR' );
const warnings = countType( items, 'WARNING' );
const others = items.length - errors - warnings;

// Neither findings nor a success line: the output format changed under us, or
// Plugin Check is not active. Never fall through to a pass.
if ( 0 === arrays && ! /(^|\n)Success:/.test( stdout ) ) {
	fail(
		'no findings and no success line were recognised in the Plugin Check output. Is the plugin-check plugin active in wp-env?'
	);
}

if ( errors > 0 || warnings > 0 ) {
	fail(
		`${ errors } error(s) and ${ warnings } warning(s). docs/CLAUDE.md requires zero of both before a release.`
	);
}

process.stdout.write(
	`popkit: plugin-check clean — 0 errors, 0 warnings${
		others > 0 ? `, ${ others } informational finding(s)` : ''
	}.\n`
);
