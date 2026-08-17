# Build plan

Phases are sequential. Each ends in something runnable — a passing command, not a
subjective judgement. Do not begin a phase until the previous phase's exit
criteria pass.

Read `CLAUDE.md` before starting any phase. The authoritative shapes live in
`data-model.md`.

---

## Scope

v1 is a first submission to the WordPress.org repository, not a feature-complete
competitor. The goal is a small reviewable plugin that is genuinely excellent at
accessible modals and banners, shipped, and then extended.

**In v1**

- Modal and inline banner layouts
- Triggers: page load (with delay), scroll depth, click selector, deep link
- Server conditions: post type, post IDs, taxonomy term, front page, 404,
  template, URL match
- Client conditions: device width, login state, referrer, UTM params, visit
  history
- Schedule and frequency caps
- Context endpoint for authoritative time and login state
- Themes, editor warnings, public extension APIs

**Deferred to 1.1 or later**

| Deferred | Reason |
|---|---|
| `user_role` targeting | Needs a privacy contract for role data crossing the wire. The context endpoint is shaped to carry it without a schema change. |
| `time_on_page`, `element_visible`, `idle` triggers | Additive; `page_load` with a delay covers the common case. |
| `exit_intent` | Ships only when its mobile behavior is honest. Desktop-only would be a support burden. |
| Form and donation conversion detection | Six host-plugin integrations is a large untested surface for a first review. `popkit:convert` is dispatched in v1 so integrations can be built externally. |
| GA4 `dataLayer` push | Trivial to add once `popkit:open` / `close` / `convert` are stable and public. |
| WP-CLI import/export | Nothing to migrate from on first release. |

Deferred does not mean deleted. Each item stays in this document so the decision
is not relitigated.

---

## Phase 0 — Scaffold and harness

Infrastructure before features. This phase produces no user-facing behavior and
that is correct.

**Build**

- `package.json` with dual toolchain:
  - `build:editor` → `wp-scripts build` (React sidebar, dependency extraction,
    `.asset.php` emission)
  - `build:frontend` → `esbuild` (vanilla, single entry, no externals)
  - `build` runs both
- `@wordpress/env` for the local WordPress + PHPUnit environment
- `size-limit` configured against the budgets in `CLAUDE.md`
- PHPCS with WordPress ruleset, ESLint with `@wordpress/eslint-plugin`, Stylelint
- Playwright with `@wordpress/e2e-test-utils-playwright`
- GitHub Actions running all six verification commands on push

**Plugin skeleton**

- Main plugin file with header, `POPKIT_VERSION`, activation guard for
  WP 6.5 / PHP 8.1
- Composer PSR-4 autoload under `Popkit\`
- Activation: register capabilities and assign them to `administrator` and
  `editor` per `CLAUDE.md` → Capabilities. Idempotent, re-runs on version change.
- Uninstall: removes options, transients, and capabilities. Removes authored
  `popkit_popup` posts **only** when `popkit_delete_data_on_uninstall` is `true`.
  That setting defaults to `false`.

**Exit criteria**

- `npm run build` produces `dist/frontend.js`, `dist/frontend.css`,
  `dist/editor.js`, `dist/editor.asset.php`
- All six verification commands pass on an empty plugin
- CI is green
- Plugin activates on a clean WP 6.5 / PHP 8.1 install with no notices at
  `WP_DEBUG = true`
- **Capability lifecycle matrix.** For each of `administrator`, `editor`,
  `subscriber`, assert the expected allow/deny for: create draft, edit own
  draft, publish, **edit own published**, **edit own private**, trash, restore
  from trash, delete permanently, edit another user's popup, delete another
  user's popup. Draft-only coverage is explicitly insufficient — the
  `edit_published_posts` omission is invisible until a post is published.
- Test: `map_meta_cap` is `true` and all fourteen capability map keys resolve to
  a non-empty capability name — a typo'd key silently falls back to core's
  default and grants `post` capabilities instead
- Test: running the activation routine twice produces the same capability set
- Test: uninstall with the default setting leaves `popkit_popup` posts intact
  and removes options and capabilities
- Test: uninstall with the setting enabled removes posts and their meta

---

## Phase 1 — Data model and registries

PHP only. No frontend output yet.

- `popkit_popup` custom post type, `show_in_rest: true`, block editor enabled,
  full capability map with `map_meta_cap => true`
- Meta registration per `data-model.md`, each with explicit schema and
  `auth_callback`
- `Popkit\Conditions` registry — `register()`, `get()`, `all()`,
  `group_passes_server()`
- `Popkit\Triggers` registry — registration and schema only, no runtime
- `Context` enum, readonly `Condition` and `Trigger` value objects
- Schema-derived sanitization: a field's declared `type` determines its
  sanitizer. No hand-written per-field sanitization.
- Unknown rule preservation: sanitization retains unregistered rule types and
  their `values` semantically intact, within the structural bounds in
  `data-model.md`. Over-bounds payloads are rejected whole, never truncated.
- URL match language implementation — `exact`, `prefix`, `contains`, `glob` —
  as a linear matcher. No `preg_match()` on user input anywhere in the codebase.
- `popkit_register_conditions` / `popkit_register_triggers` action hooks
- REST route exposing the registry schema for the editor to consume

**Exit criteria**

- PHPUnit covers: rule group AND/OR semantics, `negate` handling, the
  indeterminate-passthrough rule for client-context conditions, unknown rule
  types preserved and marked indeterminate rather than dropped
- A test asserts `group_passes_server()` never returns `false` on a
  `Context::Client` rule, regardless of its values
- A test asserts an unregistered rule type survives a full save → load → save
  round trip with `values` byte-identical — no key reordering, no type coercion
- Bounds tests on unknown `values`: nesting beyond depth 5, more than 50 keys or
  items at a level, a string over 2,000 characters, a rule over 8 KB, and a
  payload containing a PHP object each **reject the whole rule at save time**
  with an editor-visible error. Assert nothing is silently truncated — a
  half-preserved targeting rule is more dangerous than a refused one.
- Glob matcher unit tests: `*` and `?` semantics, leading and trailing
  wildcards, consecutive wildcards, 255-character cap, and a set of inputs that
  would cause catastrophic backtracking under a naive regex translation,
  asserted to complete in linear time
- A repository-wide grep asserts zero occurrences of `preg_match` against
  user-supplied patterns
- Registry schema is fetchable over REST and validates against `data-model.md`
- Fuzz test: malformed meta payloads sanitize without fatals

---

## Phase 2 — Context endpoint and frontend runtime core

The controller, with no triggers or conditions wired yet. Opens on load only.

- `GET /wp-json/popkit/v1/context?fields=…` with a server-side allowlist of
  `time` and `user_state`, sending `Cache-Control: no-store, private` and
  `Vary: Cookie`, registered with the named `popkit_context_is_public()`
  permission callback
- Login detection via `wp_validate_auth_cookie( $_COOKIE[ LOGGED_IN_COOKIE ],
  'logged_in' )`. **Not** `is_user_logged_in()` — see `CLAUDE.md`. The returned
  user ID is discarded; `wp_set_current_user()` is never called.
- Server-side matching (all popups pass in this phase), config emission,
  conditional asset enqueue
- `needsContext` computation: set only when a surviving popup has an enabled
  schedule or a `user_state` rule; client derives `fields` from the same config
- Client context fetch — conditional, non-blocking, fail-closed, one per pageview
- Monotonic clock offset using round-trip midpoint. `Date.now()` appears nowhere
  in the schedule path.
- Controller implementing pipeline stages 6–10 with stubs for 7
- `<dialog>` rendering, `showModal()`, focus management, focus return
- Always-rendered close button, 44×44 minimum, with accessible name
- Schedule evaluation against the corrected clock
- Frequency capping via browser storage, wrapped in try/catch
- `popkit:before-open` (cancelable), `popkit:open`, `popkit:close` events
- Seen recorded only after a successful, non-cancelled open
- `window.popkit.open(slug)` / `.close(slug)` programmatic API

**Exit criteria**

- Playwright: popup opens, Escape closes, focus returns to trigger element,
  focus is not placed in a form field on open, `aria-labelledby` resolves
- Playwright: focus is contained — Tab from the last focusable element returns
  to the first, Shift+Tab from the first wraps to the last
- Playwright: background content is inert while a modal is open
- Playwright: ten open/close cycles leave listener count unchanged and restore
  focus correctly each time
- Playwright: removing the dialog from the DOM while open restores focus to the
  document body rather than losing it
- Playwright: two eligible popups never produce two simultaneously open modals
- Playwright: close button has a non-empty accessible name in every theme
- Playwright: banner layout is not exposed as a dialog and uses correct landmark
  semantics
- Playwright: `prefers-reduced-motion` emulation produces instant transitions
- Playwright: preventing `popkit:before-open` suppresses the popup **and**
  writes no seen record
- Playwright: no assets enqueued on a page where zero popups match
- Playwright: `needsContext: false` produces zero network requests to the
  context route
- Playwright: context route returning 500, timing out, or returning malformed
  JSON suppresses every scheduled popup

**Context endpoint authentication** — these four are the gate on `user_state`
being correct at all:

- Test: an authenticated request carrying only the logged-in cookie and **no
  `X-WP-Nonce` header** returns `logged_in`. This is the regression test for the
  `rest_cookie_check_errors()` behavior that makes `is_user_logged_in()`
  unusable here; without it the endpoint reports `logged_out` for every visitor
  and every "logged-out only" popup shows to members.
- Test: an expired cookie, a cookie with a tampered HMAC, and a cookie for a
  deleted user each return `logged_out`
- Test: the response carries no `Access-Control-Allow-Origin` header, and a
  cross-origin `fetch` cannot read the body
- Test: the response body contains no user ID, login, display name, email,
  capability, or role key — asserted by walking the decoded payload, not by
  string matching
- Test: `?fields=user_state` omits `time`; `?fields=time` omits `user`;
  `?fields=nonsense` returns `{}`; omitting `fields` returns `{}`
- Test: a request with `fields=user_state` does not call `wp_set_current_user()`
  — assert via a `set_current_user` action spy that the request stays anonymous

**Clock correctness**

- Playwright: with the device clock set eight hours fast at page load, a popup
  scheduled for the current server hour still shows and one scheduled for the
  device's apparent hour does not
- Playwright: **advance the device clock by six hours *after* context
  initialization** and assert schedule evaluation is unaffected. This is the
  test that catches a `Date.now()` creeping back into the comparison — the
  monotonic offset is worthless without it.
- Unit: the offset uses the round-trip midpoint; a simulated 400 ms round trip
  yields an offset within 200 ms of truth, not 400 ms
- A repository-wide grep asserts `Date.now()` does not appear in the schedule
  evaluation module
- Test: two requests to the same URL, one authenticated and one not, with
  different user agents, produce byte-identical HTML
- Test: the context route response carries `no-store` and is absent from the
  page cache after a warm-up request
- Schedule unit tests cover timezone handling, DST boundaries, and open-ended
  start/end
- Midnight-crossing recurrence: for `days: [5]` with window `22:00`–`02:00`,
  assert active at Friday 22:00 and Saturday 01:59, inactive at Friday 21:59,
  Saturday 02:00, and **Friday 01:00** — the window belongs to its starting day,
  so Friday morning is Thursday's window and Thursday is not listed
- Zero-length window (`from == to`) never matches and raises an editor warning
- Storage-unavailable simulation: popups still show, no exception reaches the
  console
- `npm run size` passes

---

## Phase 3 — Triggers

- Trigger controller: arming, first-fire-wins, sibling teardown, `pagehide`
  cleanup
- Built-in triggers: page load (with delay), scroll depth, click selector,
  deep link
- `api.fire()`, `api.abort()`, `api.popup`

**Exit criteria**

- Playwright coverage for every built-in trigger
- Test: two triggers on one popup, first fire tears down the second
- Test: teardown runs on `pagehide` with no listener leaks
- Test: `?popkit=slug` and `#popkit-slug` deep links open the named popup
- Test: a deep link to a popup whose conditions fail does **not** open it —
  deep linking is a trigger, not a bypass
- `npm run size` passes with all triggers included

---

## Phase 4 — Conditions

- Built-in server conditions: post type, specific post IDs, taxonomy term,
  front page, 404, URL match, template
- Built-in client conditions: device width, login state, referrer, UTM params,
  first-time vs returning visitor
- Client-side rule evaluation (pipeline stage 7) with fail-closed semantics

**Exit criteria**

- Integration test: a server-context rule that fails means the popup markup is
  absent from the response entirely
- Integration test: a client-context rule that fails means markup is present but
  the popup never opens
- Test: a rule whose `evaluate` function is unregistered fails its group closed;
  the popup does not open
- Test: `negate: true` on an unknown rule does **not** cause it to pass
- Test: a `user_state` rule with the context fetch failing suppresses the popup
- Test: page cache safety — two requests to the same URL with different cookies
  and user agents produce byte-identical HTML
- URL match integration tests across all four modes, including a path that
  differs only in query string

---

## Phase 5 — Editor UI

- Block editor sidebar panels: conditions, triggers, schedule, frequency, theme
- Schema-driven control rendering from the Phase 1 REST endpoint
- Shared control map: text, number, range, toggle, select, multiselect,
  post-type-select, taxonomy-select, date-time, url-match
- Registering a new condition must produce working UI with zero JS changes
- Editor warnings:
  - missing accessible name (no heading and no `aria-label`)
  - schedule already expired
  - condition set that can never match
  - **unavailable condition** — a stored rule whose type is not registered,
    shown with its raw values, an explanation that the popup will not display to
    anyone while the rule is unresolvable, and no control that would silently
    overwrite it

**Exit criteria**

- E2E: register a condition in a test mu-plugin, confirm its control appears and
  round-trips through save with zero JS added
- E2E: deactivate that mu-plugin, reopen the popup, confirm the unavailable-
  condition warning appears, the stored values are still visible, and saving
  from the editor preserves them unchanged
- E2E: every editor warning triggers on its condition and clears when resolved
- Editor bundle contains no second copy of React (assert against
  `editor.asset.php` dependencies)

---

## Phase 6 — Themes and styles

- CSS custom properties for every themeable value
- Inherit mode consuming `--wp--preset--color--*` and
  `--wp--preset--font-family--*` from the active theme's `theme.json`
- Three shipped themes plus inherit
- Modal and inline banner layouts

**Exit criteria**

- Automated contrast check on every shipped theme, both layouts, meets WCAG 2.2 AA
- Close button meets contrast and 44×44 hit area in all themes and both layouts
- Visual regression snapshots across the three themes
- Inherit mode verified against Twenty Twenty-Four and Twenty Twenty-Five
- `npm run size` passes with all themes

---

## Phase 7 — Repository submission

- `readme.txt` in WP.org format, tested up to current WP
- Accessibility statement in the readme, stating the a11y-ready criteria met
- Privacy statement covering the context endpoint: what it returns, that it
  reads the logged-in cookie solely to derive a boolean and retains no identity,
  that it writes nothing, and that it contacts no external service
- Cache configuration notes for common hosts, documenting the exclusion of
  `/wp-json/popkit/v1/context`
- Complete `.pot`, all strings verified translatable
- Screenshots, banner, icon
- Freshly generated `languages/` and a build artifact excluding dev files
- Trademark audit: no "Popup Maker" or other marks in slug, name, or readme

**Exit criteria**

- `npm run plugin-check` clean, zero errors and zero warnings
- PHPCS clean with no ignores lacking justification comments
- Install from the built zip on a clean site, full smoke test passes
- Uninstall from the zip install leaves authored content intact by default
- Bundle sizes published in the readme and matching `npm run size` output
