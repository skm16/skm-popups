/**
 * esbuild configuration for the popkit frontend bundle.
 *
 * The frontend is deliberately built with esbuild rather than wp-scripts:
 * it has zero dependencies, imports nothing from `@wordpress/*`, and must fit
 * inside the budgets asserted by `npm run size` (see CLAUDE.md -> Weight).
 * The editor bundle is a separate toolchain (`npm run build:editor`) because it
 * needs webpack's WordPress dependency extraction and `.asset.php` emission.
 *
 * Usage:
 *   node esbuild.config.mjs            production build
 *   node esbuild.config.mjs --watch    rebuild on change
 *
 * Rules this file must keep:
 *   - No externals. Nothing is resolved at runtime from the page.
 *   - No polyfills, no transpile target below es2020.
 *   - Two outputs only: dist/frontend.js and dist/frontend.css.
 */

import { existsSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import esbuild from 'esbuild';

const ROOT_DIR = path.dirname( fileURLToPath( import.meta.url ) );
const DIST_DIR = path.join( ROOT_DIR, 'dist' );

const IS_WATCH = process.argv.slice( 2 ).includes( '--watch' );

/**
 * Options shared by every output.
 *
 * `sourcemap` is inline while watching and off for production: a `.map` file
 * shipped to visitors is dead weight against an 8 KB budget.
 */
const sharedOptions = {
	absWorkingDir: ROOT_DIR,
	bundle: true,
	minify: true,
	target: [ 'es2020' ],
	sourcemap: IS_WATCH ? 'inline' : false,
	charset: 'utf8',
	legalComments: 'none',
	logLevel: 'info',
	metafile: false,
	write: true,
};

const builds = [
	{
		label: 'dist/frontend.js',
		entry: 'src/frontend/index.js',
		options: {
			...sharedOptions,
			entryPoints: [ { in: 'src/frontend/index.js', out: 'frontend' } ],
			outdir: 'dist',
			platform: 'browser',
			format: 'iife',
		},
	},
	{
		label: 'dist/frontend.css',
		entry: 'src/styles/frontend.css',
		options: {
			...sharedOptions,
			entryPoints: [ { in: 'src/styles/frontend.css', out: 'frontend' } ],
			outdir: 'dist',
		},
	},
	/*
	 * The classic-editor meta box assets.
	 *
	 * Built here rather than by wp-scripts because they are plain DOM code with
	 * no JSX, no React and no `@wordpress/*` import — the same reasons the front
	 * end is built here. They are also admin-only, so they are outside the front
	 * end's size budget: `npm run size` measures what a *visitor* downloads, and
	 * these are never enqueued on the front end.
	 */
	{
		label: 'dist/classic.js',
		entry: 'src/classic/index.js',
		options: {
			...sharedOptions,
			entryPoints: [ { in: 'src/classic/index.js', out: 'classic' } ],
			outdir: 'dist',
			platform: 'browser',
			format: 'iife',
		},
	},
	{
		label: 'dist/classic.css',
		entry: 'src/styles/classic.css',
		options: {
			...sharedOptions,
			entryPoints: [ { in: 'src/styles/classic.css', out: 'classic' } ],
			outdir: 'dist',
		},
	},
];

/**
 * Fails early with a readable message when an entry point is missing, rather
 * than letting esbuild report a resolution error against a relative path.
 */
function assertEntriesExist() {
	const missing = builds
		.map( ( build ) => build.entry )
		.filter( ( entry ) => ! existsSync( path.join( ROOT_DIR, entry ) ) );

	if ( 0 === missing.length ) {
		return;
	}

	process.stderr.write(
		`popkit: missing entry point(s):\n  ${ missing.join( '\n  ' ) }\n`
	);
	process.exit( 1 );
}

async function run() {
	assertEntriesExist();

	// esbuild creates `outdir` itself, but an explicit mkdir keeps `dist/`
	// present even when a build is interrupted before its first write.
	mkdirSync( DIST_DIR, { recursive: true } );

	if ( ! IS_WATCH ) {
		await Promise.all(
			builds.map( ( build ) => esbuild.build( build.options ) )
		);

		process.stdout.write(
			`popkit: built ${ builds
				.map( ( build ) => build.label )
				.join( ', ' ) }\n`
		);

		return;
	}

	const contexts = await Promise.all(
		builds.map( ( build ) => esbuild.context( build.options ) )
	);

	await Promise.all( contexts.map( ( context ) => context.watch() ) );

	process.stdout.write(
		'popkit: watching frontend sources. Ctrl+C to stop.\n'
	);

	const shutdown = async () => {
		await Promise.all( contexts.map( ( context ) => context.dispose() ) );
		process.exit( 0 );
	};

	process.on( 'SIGINT', shutdown );
	process.on( 'SIGTERM', shutdown );
}

try {
	await run();
} catch ( error ) {
	// esbuild has already printed a formatted diagnostic at logLevel 'info';
	// anything else (a thrown config error) still needs surfacing.
	if ( ! error?.errors ) {
		process.stderr.write( `popkit: ${ error?.message ?? error }\n` );
	}

	process.exit( 1 );
}
