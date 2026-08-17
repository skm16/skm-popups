/**
 * popkit — block editor entry point, built by wp-scripts (webpack).
 *
 * Phase 0 ships this file intentionally inert. It exists so the editor half of
 * the dual toolchain is verifiable: webpack resolves it and the WordPress
 * dependency-extraction plugin emits the companion `.asset.php` alongside the
 * bundle.
 *
 * Phase 5 replaces the body with the popup sidebar — panels for conditions,
 * triggers, schedule, frequency, and theme, rendered from the shared control
 * map against the registry schema fetched over REST. Registering a new
 * condition must then produce working UI with zero changes to this bundle.
 *
 * Nothing is imported yet, so the emitted asset file declares an empty
 * dependency array. That is correct for an entry with no WordPress
 * dependencies, and it is the baseline the Phase 5 exit criterion is asserted
 * against: `@wordpress/*` imports must resolve to `wp-*` script handles in
 * that dependency list, never to a second copy of React inside the bundle.
 *
 * This bundle is admin-only and unbudgeted (docs/CLAUDE.md -> Weight), so the
 * frontend ban on `@wordpress/*` imports does not apply here. The rest of the
 * constitution does: every string translatable with the `popkit` text domain,
 * and no jQuery anywhere, admin included.
 *
 * @see docs/CLAUDE.md
 * @see docs/build-plan.md
 */

( () => {
	// Phase 5 writes the sidebar registration into this scope. Until then the
	// entry is deliberately a no-op: it registers no plugin, adds no panel, and
	// renders nothing. Terser proves this IIFE side-effect free and drops it, so
	// the emitted chunk is currently empty — that is expected, and the companion
	// .asset.php is still emitted, which is what Phase 0 needs to verify.
} )();
