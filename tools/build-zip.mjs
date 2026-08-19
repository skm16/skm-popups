/**
 * Builds the distributable plugin archive.
 *
 *   npm run build:zip
 *
 * Produces `build/popkit.zip`, containing a single top-level `popkit/`
 * directory, which is the shape WordPress expects from an uploaded archive and
 * the shape the .org repository serves.
 *
 * ## An allowlist, not an ignore list
 *
 * The set of files that ship is written out below and nothing else is copied.
 * The alternative — copy everything, then exclude — was tried first and is the
 * wrong way round: Plugin Check flagged sixty-six findings against the working
 * tree, and every single one was a development file. Tests, `.github`,
 * `.gitignore`, `phpunit.xml.dist`, a stray Playwright trace. None of it was
 * plugin code, and none of it belonged in a release.
 *
 * With an ignore list, a new development directory ships by default and someone
 * has to notice. With an allowlist, it does not ship until someone says it
 * should. The failure modes are "a new asset directory was forgotten", which a
 * smoke test catches immediately, versus "a `.env` shipped to fifty thousand
 * sites", which nothing catches.
 *
 * ## What is deliberately absent
 *
 * - `vendor/` — the Composer autoloader is a convenience for a git checkout.
 *   `popkit.php` falls back to its own PSR-4-ish loader when it is missing, and
 *   the only Composer dependencies are development tools.
 * - `src/` — sources for `dist/`. Shipping both doubles the download to no end.
 * - `node_modules/`, `tests/`, `docs/`, `tools/`, `artifacts/` — development.
 * - Every dotfile. WordPress.org rejects hidden files outright.
 *
 * ## The build has to be current
 *
 * `dist/` is a build artifact and is not committed, so an archive assembled
 * without building first would ship whatever happened to be on disk — or
 * nothing. This runs the production build itself rather than trusting that
 * someone ran it, and refuses to package a tree where the expected outputs are
 * missing afterwards.
 *
 * ## `--no-zip`
 *
 * Stops after staging and verifying `build/popkit`, skipping compression.
 *
 * `tools/plugin-check.mjs` uses it. Plugin Check has to inspect the bytes that
 * ship, and the only tree that is definitionally those bytes is the one this
 * script assembles — so rather than describing the allowlist twice, the checker
 * runs this and scans what it produced.
 *
 * Compression is also the one step here that is not portable: it shells into
 * `powershell.exe`, which does not exist on the Linux runner CI uses. Staging
 * is pure Node and runs anywhere, so `--no-zip` is what makes this script
 * usable as a CI build step rather than only as a local release step.
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const ROOT = path.dirname( path.dirname( fileURLToPath( import.meta.url ) ) );
const BUILD = path.join( ROOT, 'build' );
const SLUG = 'popkit';
const STAGE = path.join( BUILD, SLUG );
const ZIP = path.join( BUILD, `${ SLUG }.zip` );

/** Stage and verify, then stop. See the `--no-zip` note above. */
const STAGE_ONLY = process.argv.slice( 2 ).includes( '--no-zip' );

/**
 * Everything that ships, relative to the plugin root.
 *
 * Directories are copied whole. Files are copied individually. Anything not
 * named here is absent from the archive by construction.
 */
const SHIPPED = [
	'popkit.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE',
	'includes',
	'dist',
	'languages',
];

/**
 * Files that must exist after the build, or the archive is not shippable.
 *
 * `dist/editor.asset.php` is included because `Popkit\Editor` refuses to enqueue
 * without it and prints a "could not be loaded" notice instead — an archive
 * missing it installs cleanly and has no sidebar.
 */
const REQUIRED = [
	'popkit.php',
	'readme.txt',
	'dist/frontend.js',
	'dist/frontend.css',
	'dist/editor.js',
	'dist/editor.asset.php',
	// The classic-editor meta boxes enqueue these unconditionally on the popup
	// screens. Absent, a site running the classic editor gets a 404 for both and
	// a targeting box whose Add rule button does nothing.
	'dist/classic.js',
	'dist/classic.css',
	'languages/popkit.pot',
];

/**
 * Prints a progress line.
 *
 * @param {string} message Message.
 * @return {void}
 */
const say = ( message ) => process.stdout.write( `  ${ message }\n` );

/**
 * Prints an error and exits non-zero.
 *
 * @param {string} message Message.
 * @return {void}
 */
const fail = ( message ) => {
	process.stderr.write( `ERROR: ${ message }\n` );
	process.exit( 1 );
};

/**
 * Copies a file or directory into the staging tree.
 *
 * Dotfiles are skipped wherever they appear, including inside a shipped
 * directory — WordPress.org rejects hidden files, and a stray `.DS_Store` in
 * `dist/` is exactly the kind of thing that gets a submission bounced.
 *
 * @param {string} from Absolute source path.
 * @param {string} to   Absolute destination path.
 * @return {number} Number of files copied.
 */
const copyInto = ( from, to ) => {
	const stat = fs.statSync( from );

	if ( ! stat.isDirectory() ) {
		fs.mkdirSync( path.dirname( to ), { recursive: true } );
		fs.copyFileSync( from, to );

		return 1;
	}

	fs.mkdirSync( to, { recursive: true } );

	let copied = 0;

	for ( const entry of fs.readdirSync( from, { withFileTypes: true } ) ) {
		if ( entry.name.startsWith( '.' ) ) {
			say(
				`skipped hidden: ${ path.relative(
					ROOT,
					path.join( from, entry.name )
				) }`
			);
			continue;
		}

		copied += copyInto(
			path.join( from, entry.name ),
			path.join( to, entry.name )
		);
	}

	return copied;
};

say( 'building production assets…' );

/*
 * Both builds are invoked through `process.execPath` — this very Node — rather
 * than through `npm run build`.
 *
 * Node 22 refuses to `spawn` a `.cmd` without a shell (the CVE-2024-27980 fix),
 * so `npm.cmd` fails with `EINVAL`, and the obvious repair is `shell: true`,
 * which reintroduces exactly the injection surface that change closed. Calling
 * the JavaScript entry points directly needs neither: no shell, no `.cmd`, and
 * an argument vector nothing interpolates into.
 */
const BUILD_STEPS = [
	{ label: 'frontend', args: [ 'esbuild.config.mjs' ] },
	{
		label: 'editor',
		args: [ 'node_modules/@wordpress/scripts/bin/wp-scripts.js', 'build' ],
	},
];

for ( const step of BUILD_STEPS ) {
	try {
		execFileSync( process.execPath, step.args, {
			cwd: ROOT,
			stdio: 'pipe',
		} );

		say( `built ${ step.label }` );
	} catch ( error ) {
		fail(
			`the ${ step.label } build failed:\n${
				error.stdout ?? error.stderr ?? error.message
			}`
		);
	}
}

if ( fs.existsSync( BUILD ) ) {
	fs.rmSync( BUILD, { recursive: true, force: true } );
}

fs.mkdirSync( STAGE, { recursive: true } );

let total = 0;

for ( const entry of SHIPPED ) {
	const source = path.join( ROOT, entry );

	if ( ! fs.existsSync( source ) ) {
		fail(
			`"${ entry }" is on the shipping list and does not exist. Either build it or remove it from SHIPPED — an archive quietly missing a listed path is how a release ships without its translations.`
		);
	}

	total += copyInto( source, path.join( STAGE, entry ) );
}

say( `staged ${ total } files` );

for ( const required of REQUIRED ) {
	if ( ! fs.existsSync( path.join( STAGE, required ) ) ) {
		fail(
			`"${ required }" is missing from the staged tree. The archive would install and then fail at runtime.`
		);
	}
}

if ( STAGE_ONLY ) {
	say( `staged tree verified at ${ path.relative( ROOT, STAGE ) }; skipping zip.` );
	process.exit( 0 );
}

/*
 * PowerShell's Compress-Archive rather than a zip dependency: it is present on
 * every supported Windows and adding a packaging library to devDependencies to
 * produce one file is not a trade worth making. `-Path <dir>` includes the
 * directory itself, which is the top-level `popkit/` the archive needs.
 */
say( 'compressing…' );

try {
	execFileSync(
		'powershell.exe',
		[
			'-NoProfile',
			'-NonInteractive',
			'-Command',
			`Compress-Archive -Path '${ STAGE }' -DestinationPath '${ ZIP }' -Force`,
		],
		{ stdio: 'pipe' }
	);
} catch ( error ) {
	fail( `compression failed:\n${ error.stderr ?? error.message }` );
}

if ( ! fs.existsSync( ZIP ) ) {
	fail( 'the archive was not written.' );
}

const bytes = fs.statSync( ZIP ).size;

say(
	`wrote ${ path.relative( ROOT, ZIP ) } (${ Math.round( bytes / 1024 ) } KB)`
);
say( 'staged tree left in build/popkit for inspection.' );
