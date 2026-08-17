/**
 * popkit — frontend runtime, bundle entry point for dist/frontend.js.
 *
 * Phase 0 ships this file intentionally inert. It exists so the toolchain is
 * verifiable end to end: esbuild.config.mjs resolves it, `npm run build`
 * emits dist/frontend.js, and `npm run size` has an artifact to weigh.
 *
 * Phase 2 replaces the body with the controller — config parsing from the
 * #popkit-config JSON script, the conditional context fetch, the monotonic
 * clock offset, client-context rule evaluation, frequency capping, trigger
 * arming, and <dialog> rendering with focus management. Do not add any of
 * that here ahead of its phase.
 *
 * Hard constraints on everything that ever lands in this bundle
 * (docs/CLAUDE.md -> Hard constraints):
 *
 * - 8 KB gzipped. `npm run size` asserts it against dist/frontend.js and a
 *   budget is never raised to make a feature fit — ask instead.
 * - No framework. No React, Preact, Alpine, or Vue. Not the Interactivity
 *   API, which exceeds the entire budget on its own.
 * - No `@wordpress/*` imports. esbuild bundles with no externals, so such an
 *   import becomes shipped bytes rather than a script handle. ESLint enforces
 *   this for src/frontend/** specifically.
 * - No jQuery, and no reliance on the `jQuery`, `$`, or `wp` globals.
 * - No polyfills for browsers outside the WordPress 6.5 support matrix.
 *
 * @see docs/CLAUDE.md
 * @see docs/build-plan.md
 */

( () => {
	// Phase 2 writes the controller into this scope. Until then the entry is
	// deliberately a no-op: it reads nothing, binds no listener, dispatches no
	// event, and touches no node. The IIFE is here so the bundle has the module
	// scope the controller will be written into, and so the built artifact is
	// valid, non-empty JavaScript that a browser can execute harmlessly.
} )();
