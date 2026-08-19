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
 * reports 37 errors and 27 warnings, and **every one** is a development file:
 * `tests/`, `.github`, `.gitignore`, `phpunit.xml.dist`, a stray Playwright
 * trace. None of it ships. Run against `build/popkit` — what `npm run build:zip`
 * assembles and what a user actually installs — the same plugin reports zero and
 * zero.
 *
 * So this points at the staged build, and it did not always. An earlier version
 * of this file argued exactly the above in prose and then scanned the repository
 * root anyway, because `PLUGIN_PATH` defaulted to the `.wp-env.json` mapping of
 * the checkout. The comment was right and the code was wrong, which is the worse
 * way round: the gate `docs/CLAUDE.md` -> Verification calls mandatory could
 * never pass, and the CI job that runs it was red on every commit for findings
 * that do not exist in the artifact being released.
 *
 * The staged tree is built here rather than assumed. `build/popkit` is a build
 * artifact, so a stale one would have this checking bytes that are not the bytes
 * `build:zip` would package — the one direction in which this check and the ZIP
 * can genuinely diverge, and the direction that under-reports. Running the
 * staging step first removes it: `tools/build-zip.mjs` owns the allowlist, so
 * the tree scanned here is the release by construction rather than by a second
 * copy of the same list.
 */

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const require = createRequire( import.meta.url );

/** Repository root — the parent of `tools/`. */
const ROOT = path.dirname( path.dirname( fileURLToPath( import.meta.url ) ) );

/**
 * Path to the tree being checked, *inside the container*, relative to the
 * WordPress root.
 *
 * Deliberately not derived from the host directory name. `@wordpress/env` mounts
 * the project according to `.wp-env.json`, which maps the checkout to
 * `wp-content/plugins/popkit` whatever the folder is called; the CI workflow
 * pins the same value in `POPKIT_WP_ENV_PLUGIN_PATH`. The staged release tree
 * therefore appears one level further down, at `build/popkit`.
 *
 * That trailing `popkit` is load bearing. Plugin Check resolves a directory
 * argument to a slug with `basename()`, and several checks compare the slug
 * against the text domain — so a staging directory named anything else would
 * report i18n errors that describe the staging directory rather than the plugin.
 * `build/popkit` keeps the basename correct while keeping the staged tree out of
 * the way of the checkout.
 */
const MOUNT_PATH =
	process.env.POPKIT_WP_ENV_PLUGIN_PATH || 'wp-content/plugins/popkit';

/** The staged release tree, relative to the mount. See `tools/build-zip.mjs`. */
const STAGED_SUBPATH = 'build/popkit';

const PLUGIN_PATH = path.posix.join( MOUNT_PATH, STAGED_SUBPATH );

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

/*
 * Stage the release tree before checking it, so what is scanned is what would
 * ship rather than whatever `build/` happens to hold. `--no-zip` stops
 * `build-zip.mjs` after staging and verification; compression is the only step
 * in it that needs PowerShell, and this has to run on the Linux CI runner too.
 */
process.stdout.write( 'popkit: staging the release tree…\n' );

const staged = spawnSync(
	process.execPath,
	[ path.join( ROOT, 'tools', 'build-zip.mjs' ), '--no-zip' ],
	{ cwd: ROOT, stdio: [ 'ignore', 'pipe', 'inherit' ], encoding: 'utf8' }
);

if ( 0 !== staged.status ) {
	fail(
		`could not stage the release tree (build-zip.mjs exited ${ staged.status }). Plugin Check has nothing to inspect.\n${
			staged.stdout ?? ''
		}`
	);
}

// Belt and braces: build-zip.mjs already refuses to finish without these, but a
// missing tree here would silently become "wp-env cannot resolve the path".
if ( ! fs.existsSync( path.join( ROOT, 'build', 'popkit', 'popkit.php' ) ) ) {
	fail(
		'the staged tree at build/popkit is missing after staging. Run `npm run build:zip` and read its output.'
	);
}

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
