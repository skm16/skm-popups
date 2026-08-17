/**
 * popkit — frontend runtime, bundle entry point for dist/frontend.js.
 *
 * Two statements, and that is the whole of the entry point: read the emitted
 * config, hand it to the controller. Everything else lives in a module that can
 * be reasoned about, and tested, without a document around it.
 *
 * On a page with no `#popkit-config` element this file does nothing at all —
 * `readConfig()` returns null and `boot()` returns on it. No listener is bound,
 * no global is defined, no request is made. That matters because the bundle can
 * arrive from a browser cache on a response that emitted no popups, and because
 * `Popkit\Frontend` deliberately prints nothing when nothing matched.
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

import { boot, readConfig } from './controller.js';

boot( readConfig() );
