# CLAUDE.md

Project constitution. These are invariants, not preferences. If a task appears to
require violating one, stop and ask rather than working around it.

---

## What this is

`popkit` — a WordPress popup plugin. Lean, accessible, open source, targeted at
non-profit sites in the rare disease space. Positioned against Popup Maker on
weight and accessibility, not on feature count.

Slug: `popkit` (placeholder — confirm availability before first commit).
All prefixes derive from it: `popkit_`, `POPKIT_`, `Popkit\`, `--popkit-`, `.popkit-`.

**Minimum supported:** WordPress 6.5, PHP 8.1.
Use PHP 8.1 language features freely — enums, readonly properties, first-class
callable syntax, `never`, pure intersection types.

## Non-goals

Do not build these. If asked, confirm first — they are excluded deliberately.

- A/B testing or split testing
- A built-in analytics dashboard or impression logging to the database
- A drag-and-drop visual builder
- Slide-ins, sticky bars, fullscreen takeovers, notification bars
  (v1 ships modal and inline banner only)
- Any call to an external service

---

## Hard constraints

### Weight

| Bundle | Budget (gzipped) |
|---|---|
| `dist/frontend.js` | 8 KB |
| `dist/frontend.css` | 4 KB |
| `dist/editor.*` | unbudgeted (admin only) |

`npm run size` asserts these and must pass in CI. Exceeding a budget fails the
build. Do not raise a budget to make a feature fit — ask.

- No jQuery. Ever. Not in the frontend, not in the admin.
- No framework in the frontend bundle. No React, Preact, Alpine, Vue.
- No polyfills for browsers older than the WordPress 6.5 support matrix.
- No `@wordpress/*` imports in the frontend bundle.
- Do not use the Interactivity API. It exceeds the frontend budget on its own.

### Cache safety

The single most important architectural rule:

> **Server-side code may never make a rendering decision that depends on the
> visitor rather than the URL.**

A page cache serves one HTML response for many visitors. Anything that varies by
visitor — login state, device, referrer, UTM params, visit history, current time —
is evaluated in the browser.

Concretely:

- Conditions declare `Context::Server` or `Context::Client`. Server evaluation
  skips client-context rules; it never rejects on them.
- Frequency capping is **browser storage only** — `localStorage` or
  `sessionStorage` depending on mode. No cookies, no user meta, no server-side
  counters.
- Never call `is_user_logged_in()`, `wp_get_current_user()`, or read
  `$_SERVER['HTTP_REFERER']` in any code path that affects emitted markup. The
  context route is the sole visitor-aware code path, it emits no markup, and it
  is never cached — and note that it must not use `is_user_logged_in()` either,
  for a different reason given below.
- Nothing that varies by visitor or by request is ever printed into the cached
  response. That includes the current time. A timestamp embedded at cache-fill
  time is stale for every subsequent hit and silently decays as the entry ages.

### The context endpoint

Two things genuinely cannot be answered from cached HTML or from the visitor's
device: **authoritative time** and **login state**. Both come from one uncached
same-origin REST route.

```
GET /wp-json/popkit/v1/context?fields=time,user_state
```

| Property | Value |
|---|---|
| Cache headers | `Cache-Control: no-store, private`, `Vary: Cookie` |
| `permission_callback` | `popkit_context_is_public()` — a named callback returning `true`. See the exception note below. |
| `fields` | Comma-separated allowlist: `time`, `user_state`. Unknown names are ignored, not errors. |
| Payload | `{ "time": <epoch_ms>, "user": { "state": "logged_in" \| "logged_out" } }` — each key present only if requested |

The client derives `fields` from the emitted config: it requests `time` when any
surviving popup has an enabled schedule, and `user_state` when any has a
`user_state` rule. The server allowlists the names and silently drops anything
else, so a malformed or hostile `fields` value can only ever narrow the response.

### Detecting login state without a REST nonce

**This route must not call `is_user_logged_in()`.** WordPress's
`rest_cookie_check_errors()` calls `wp_set_current_user( 0 )` on any REST request
that arrives without a valid `X-WP-Nonce`, then returns success — the request
proceeds as anonymous rather than failing. A nonce cannot be supplied here,
because it is per-user and would poison the cached HTML that would have to carry
it. Calling `is_user_logged_in()` would therefore report `logged_out` for every
visitor including logged-in ones, and every "logged-out visitors only" popup
would show to members. That failure is silent: the route still returns HTTP 200
with well-formed JSON.

The route instead reads the logged-in cookie directly:

```php
$state  = 'logged_out';
$cookie = $_COOKIE[ LOGGED_IN_COOKIE ] ?? '';

if ( '' !== $cookie && wp_validate_auth_cookie( $cookie, 'logged_in' ) ) {
    $state = 'logged_in';
}
```

`wp_validate_auth_cookie()` performs full HMAC verification, expiry checking, and
session-token validation, so forged and expired cookies both yield `logged_out`.
It returns a user ID, which this route **discards** — only the boolean survives
into the response.

Constraints on this exception:

- It is the **only** place in the codebase that reads an auth cookie directly.
- It returns a boolean and nothing else. No user ID, name, email, capabilities,
  or roles, in v1 or after.
- It never calls `wp_set_current_user()`. The request stays anonymous for every
  other purpose, so no other code path can accidentally depend on it.
- Bypassing the REST nonce is safe **specifically because this route is `GET`,
  writes nothing, and returns one boolean**. CSRF protects against state changes;
  there is no state to change. This reasoning does not extend to any other route.
- **Same-origin policy is not a defence here, and must never be relied on as
  one.** It governs `fetch()` and `XHR`; it does not govern `<script src>`.
  WordPress serves any REST route as JSONP when `_jsonp` is present —
  `rest_jsonp_enabled` defaults to true, the callback name is read straight from
  `$_GET`, and the response is emitted as `application/javascript` — so
  `<script src=".../context?fields=user_state&_jsonp=steal">` on an attacker's
  page executes there with the visitor's cookies attached and hands them the
  login state. Two defences are implemented, and both are needed:
  - `rest_send_cors_headers` is removed from `rest_pre_serve_request` for this
    route, so no `Access-Control-Allow-Origin` is sent and a credentialed
    `fetch()`/`XHR` read is blocked.
  - `rest_jsonp_enabled` is filtered `false` for this route, which blocks the
    `<script src>` read. It is attached in `Rest_Context::init()`, before
    dispatch, because `WP_REST_Server::serve_request()` reads that filter and
    fixes the content type well before it dispatches the route — a filter added
    inside the handler would already be too late. It is scoped by exact route
    match, so JSONP elsewhere on the site is untouched.

### Other rules governing it

- **Conditionally fetched.** The emitted config carries `needsContext`. The
  server sets it `true` only when at least one surviving popup has an enabled
  schedule or a `user_state` rule. When it is `false` the client makes no
  request at all. A page with no popups, or with only device/referrer/UTM
  targeting, costs zero extra round trips.
- **Fetched once per pageview**, during controller initialization, never blocking
  first paint. Popups that need no context proceed independently and may open
  before the response arrives; popups that need it wait for it.
- **Fail closed.** If the request fails, times out, or returns a malformed
  payload, every popup depending on context is suppressed. A network blip
  hiding a popup is correct; showing a members-only popup to the public is not.
- **Monotonic clock.** See the formula in `data-model.md` → Schedule. Evaluation
  never touches `Date.now()`, in either the offset or the comparison.
- Page caches must be configured to exclude `/wp-json/popkit/v1/context`. The
  readme documents this for the common hosts; the route sends correct headers
  regardless.

`user_role` is **not** in v1. The endpoint's `user` object is versioned to carry
`roles` in 1.1 without a schema change.

### Security

- Escape at output, every time: `esc_html()`, `esc_attr()`, `esc_url()`,
  `wp_kses_post()`. There are no exceptions for "known safe" values.
- Sanitize at input against the registered field schema. A condition or trigger
  field declares its type; sanitization is derived from that declaration, not
  written by hand per field.
- Every admin-side write checks a capability **and** verifies a nonce.
- REST routes use a `permission_callback` that checks a capability. Never
  `__return_true`.
  **One deliberate exception:** the context route is public by design and uses a
  named callback, `popkit_context_is_public()`, which returns `true` with a
  docblock stating why. The named function makes the decision greppable and
  reviewable; `__return_true` would make an intentional choice look like an
  oversight. Adding a second such exception requires the sign-off in
  Ask before doing.
- Meta registered with `show_in_rest` must supply an explicit `schema` and
  `auth_callback`.
- No `eval()`, no `create_function()`, no unserializing user input.
- **No user-supplied regular expressions anywhere.** PHP provides no dependable
  per-match wall-clock timeout; `pcre.backtrack_limit` reduces exposure but is
  not a guarantee, and a plugin that accepts arbitrary patterns cannot honestly
  claim bounded matching. URL targeting uses the constrained match language
  below instead.
- Unknown or unavailable conditions **fail closed on the client**. See
  Architecture invariants.

#### URL match language

`url_path` accepts a `match` mode and a literal `value`. There is no pattern
compilation and no backtracking, so matching is linear in the URL length and
safe by construction.

| Mode | Semantics |
|---|---|
| `exact` | Path equals `value` |
| `prefix` | Path starts with `value` |
| `contains` | Path contains `value` |
| `glob` | `*` matches any run of characters, `?` matches one. No other metacharacters. |

Glob is matched by a linear two-pointer walk with backtracking bounded to a
single `*` resume point — not by translating to a regex. `value` is capped at
255 characters. Comparison is case-insensitive, always against the normalized
path only: no scheme, host, query string, or fragment.

The `referrer` client condition uses the same four modes, matched against the
referrer's host and path.

### Accessibility

Non-negotiable. This is the product differentiator, not a polish item.

- Modals use the native `<dialog>` element with `.showModal()`.
- Focus returns to the element that triggered the popup on close. If the trigger
  was not a user interaction, focus returns to where it was before opening.
- Never autofocus a form field on open. Focus the dialog container or its heading.
- `aria-labelledby` points at the popup's heading, and **only ever at an `id`
  popkit minted itself**. An IDREF is a claim about the whole document, and a
  popup printed on `wp_footer` cannot make that claim about an `id` an author
  set: a page element carrying the same `id` resolves first and names the dialog
  with its text. A heading with an author anchor is therefore named by
  `aria-label` carrying that heading's own text, with the anchor left untouched.
  If there is no heading at all, `aria-label` falls back to the post title and
  the editor warns when the popup would be announced as nothing useful.
- Escape closes. This is native `<dialog>` behavior — do not `preventDefault` it.
- Close control is a real `<button>`, minimum 44×44px hit area, with an
  accessible name. Not a `<span>`, not an icon font, not a bare `×`.
- Honor `prefers-reduced-motion: reduce` — animations become instant, not merely
  faster.
- **Every popup has a visible close button. Always, in every layout, with no
  configuration that removes it.** Overlay click and Escape are *additional*
  dismissal mechanisms, never substitutes: an overlay is not a discoverable
  affordance, carries no accessible name, and is unreachable by keyboard and by
  most assistive technology. There is no `close_button: false`.
- Colour contrast in shipped themes meets WCAG 2.2 AA (4.5:1 body, 3:1 large).

Every accessibility invariant above has a corresponding Playwright assertion.
If you add a UI surface, add its assertions in the same commit. The suite covers
at minimum:

- Initial focus lands on the dialog or its heading, never a form field
- Escape closes; focus returns to the triggering element
- Focus containment — Tab and Shift+Tab cycle within the open dialog
- Backdrop content is inert while a modal is open
- Repeated open/close cycles leave no orphaned listeners and restore focus each time
- Dialog removed from the DOM while open restores focus rather than losing it
- Multiple eligible popups never open two modals simultaneously
- Close button exposes an accessible name in every theme and layout
- Banner layout uses correct landmark/role semantics and is not announced as a dialog

---

## Architecture invariants

The evaluation pipeline runs in a fixed order. Do not reorder or merge stages.

```
SERVER (cacheable, varies only by URL)
  1. Query the oldest `Frontend::MAX_POPUPS` (100) published popups. The limit is
     a SQL `LIMIT` applied *before* targeting, so it bounds candidates rather
     than matches; `Post_Type::render_popup_limit_notice()` discloses it.
  2. Evaluate Context::Server rules
       server rule false        → group fails
       client rule              → indeterminate, group survives
       unknown/unregistered     → indeterminate, group survives
  3. Emit config JSON + markup for survivors
  4. Set needsContext if any survivor has a schedule or user_state rule
  5. Enqueue assets only if at least one popup survived

CLIENT (varies by visitor)
  6. Fetch context if needsContext — else skip entirely
  7. Evaluate Context::Client rules
       unknown / unavailable / context missing → FAIL CLOSED
  8. Evaluate schedule against the corrected clock
  9. Check frequency cap
 10. Arm triggers → first fire wins → open, tear down siblings
```

- Schedule and frequency are **not** conditions. Keeping them as separate stages
  makes the cache-poisoning mistake unrepresentable in the condition registry.
- **Indeterminate on the server means "defer", not "pass".** The server preserves
  a group containing rules it cannot judge, because it genuinely cannot judge
  them. It does not mark them satisfied.
- **Unknown on the client means "deny".** If a rule's `evaluate` function is
  absent — an extension was deactivated, a script failed to load, the context
  fetch failed — the rule evaluates false and its group fails. Skipping it would
  silently widen the audience: a deactivated plugin would turn "members only"
  into "everyone". This asymmetry between server and client is deliberate.
- Triggers are armed only after stages 7–9 pass. Nothing binds to the DOM for a
  popup that could never show.
- `api.fire()` is idempotent and first-wins. Firing tears down every sibling
  trigger for that popup.
- Every trigger `setup()` returns a teardown function. This is required, not
  optional — the controller calls it on fire, on abort, and on `pagehide`.
- A popup is recorded as **seen** only after a successful, non-cancelled open —
  after `popkit:before-open` was not prevented and the dialog is actually
  visible. Never on eligibility, arming, or trigger fire.

## Registry invariants

- Field schemas are declared in **PHP only**, including for client-context
  conditions. One source of truth drives admin UI, REST validation, and
  sanitization. JS supplies only the `evaluate` function.
- Registering a condition or trigger must require zero React. If a new field type
  needs a control that does not exist, add it to the shared control map — do not
  let registrations ship their own UI. There are **two** control maps, one per
  authoring surface (`src/editor/controls.js`, `Popkit\Classic_Fields`), and both
  render from the same schema. A control added to `Condition::FIELD_CONTROLS`
  needs a case in each, or it renders on one screen and silently not the other.
- **Two authoring surfaces, exactly one mounted.** The block editor sidebar and
  the classic meta boxes write the same post meta, so both live would make a
  popup's settings depend on which screen saved last. `Popkit\Editor_Mode` is the
  single place that decides, and it decides by *following the site*: it registers
  no editor filter unless `popkit_use_block_editor` returns a boolean.
  - Gate on what WordPress **resolved** for the post, never on what popkit
    *prefers*. Those came apart once — the preference was stated on
    `use_block_editor_for_post_type`, which does not decide, so the Classic Editor
    plugin served the classic screen while the meta boxes stood down believing the
    block editor had won. A popup had no settings interface at all, and nothing
    was logged. Gating on the resolved answer bounds any future disagreement to
    "the panels are on the other screen" instead of "on neither".
  - `use_block_editor_for_post` is the hook that decides. Filtering only the
    post-type hook is not enough.
- Rule negation is the `negate` flag on the rule, never a separate registered
  condition type.
- Rule groups are OR'd; rules within a group are AND'd.
- **Sanitization never discards a rule whose `type` is unregistered.** A
  condition provided by a plugin that happens to be deactivated at save time
  must survive the round trip intact — dropping it would silently destroy the
  author's targeting and, worse, widen the audience on reactivation. Unknown
  rules are preserved *semantically* — no key reordering, no type coercion, no
  stripping of unrecognized keys — subject to the structural bounds in
  `data-model.md` → Bounds on unknown rule values. A payload exceeding those
  bounds is rejected whole at save time, never truncated. The editor surfaces
  unknown rules as unavailable rather than rendering an empty control.

---

## Capabilities and data lifecycle

### Capabilities

`capability_type => 'popkit_popup'` alone grants nobody anything — WordPress
maps meta capabilities to primitives that must actually exist on a role.
Registration declares the full set and activation assigns it.

```php
'capability_type' => array( 'popkit_popup', 'popkit_popups' ),
'map_meta_cap'    => true,
'capabilities'    => array(
    // Meta capabilities — resolved per-object by map_meta_cap().
    'edit_post'              => 'edit_popkit_popup',
    'read_post'              => 'read_popkit_popup',
    'delete_post'            => 'delete_popkit_popup',

    // Primitive capabilities used outside map_meta_cap().
    'edit_posts'             => 'edit_popkit_popups',
    'edit_others_posts'      => 'edit_others_popkit_popups',
    'publish_posts'          => 'publish_popkit_popups',
    'read_private_posts'     => 'read_private_popkit_popups',
    'delete_posts'           => 'delete_popkit_popups',
    'create_posts'           => 'edit_popkit_popups',

    // Primitive capabilities used *within* map_meta_cap().
    // Omitting these is the classic CPT capability bug — see below.
    'edit_private_posts'     => 'edit_private_popkit_popups',
    'edit_published_posts'   => 'edit_published_popkit_popups',
    'delete_private_posts'   => 'delete_private_popkit_popups',
    'delete_published_posts' => 'delete_published_popkit_popups',
    'delete_others_posts'    => 'delete_others_popkit_popups',
),
```

- `map_meta_cap => true` is **required**. Without it, `edit_post` is never
  resolved per-object, the `auth_callback` on every meta key silently
  misbehaves, and the final five primitives above are never even consulted.
- **The last five are not optional.** `map_meta_cap()` routes `edit_post` through
  `edit_published_posts` once a post is published, and through
  `edit_private_posts` once it is private. Grant only `edit_posts` and an editor
  can create and edit a draft, then permanently lose access to it the moment they
  publish — an author locked out of their own popup. Draft-only CRUD tests pass
  clean against this bug, which is why the test matrix below covers the full
  lifecycle.
- `create_posts` deliberately maps to `edit_popkit_popups` rather than a
  separate primitive, matching core's default. Splitting it would let a role
  edit existing popups but not create them, which is not a distinction this
  plugin needs.
- The map has **fourteen keys**, but only the **ten distinct primitives** are
  ever granted to a role — the three meta capabilities are resolved per-object
  by `map_meta_cap()` and must never be stored on one.

**Role assignment on activation**

| Role | Capabilities |
|---|---|
| `administrator` | All ten primitives |
| `editor` | All except `delete_others_popkit_popups` |
| everyone else | None |

- Capability assignment is idempotent and re-runs on version upgrade — a site
  restored from a backup taken before activation must self-heal.
- Deactivation does **not** remove capabilities. Uninstall does.
- The full grid — administrator, editor, subscriber × create, publish,
  edit-published, trash, restore, delete, and edit-others — is asserted in
  Phase 0. Testing only the draft path is what lets the published-post bug ship.

### Uninstall

Uninstall removes plugin **infrastructure** — options, transients, capabilities.
It does **not** delete authored content unless the site owner opts in.

The opt-in **defaults to `false`**. Only when explicitly enabled does uninstall
remove `popkit_popup` posts and their meta, and its description states plainly
that the action is irreversible.

Three names for one decision, which is worth stating precisely because an earlier
version of this section named only the last of them and `readme.txt` inherited
the confusion:

- **Canonical storage** is `popkit_settings[delete_data_on_uninstall]`.
- **The primary surface** is **Popups → Settings**, rendered by
  `Popkit\Settings_Page` and gated on `manage_options` — not on
  `edit_popkit_popups`, because arming this destroys content an author does not
  own.
- **`popkit_delete_data_on_uninstall`** is a standalone-option *fallback*, for a
  site owner or WP-CLI script that set the documented name directly. It is
  honored only while the canonical row has never been written, so it stops being
  read the first time anyone saves the screen. Do not document it as the front
  door.

Deleting a client's authored popups because a plugin was removed during routine
maintenance is not acceptable behavior, and "the docs said it would" is not a
defense.

## Code standards

- WordPress Coding Standards via PHPCS. `composer run lint` must pass clean —
  no inline `phpcs:ignore` without a comment explaining why.
- All strings translatable with the `popkit` text domain. No concatenated
  sentence fragments; use `printf` with placeholders.
- Files: one class per file, PSR-4 autoloaded under `Popkit\`, but WordPress
  file naming (`class-thing.php`) in `includes/`.
- No `global`. No singletons except the two registries, which are accessed via
  accessor functions rather than static properties.
- Prefer composition over inheritance. There is no `Abstract_Condition` base class.

## Verification

Every one of these must pass before a phase is considered complete.

```bash
composer run lint        # PHPCS, WordPress ruleset
composer run test        # PHPUnit via wp-env
npm run lint             # ESLint + Stylelint
npm run test:e2e         # Playwright, includes a11y assertions
npm run size             # bundle budget assertions
npm run plugin-check     # WP.org Plugin Check plugin, must be clean
```

Do not mark a task done on the basis of code reading. Run the command.

---

## Ask before doing

- Raising a bundle budget
- Adding a runtime dependency to the frontend bundle
- Adding a third-party PHP dependency
- Any database write on a frontend pageview
- Changing the stored config schema after Phase 1 (requires a migration)
- Adding a feature from the non-goals list
- Anything that would make a shipped theme fail contrast checks
- Adding any field to the context endpoint payload
- Making the context fetch unconditional, or blocking on it
- Any code path that deletes authored content
- Manipulating browser history (`pushState`, `popstate` interception) for
  trigger purposes
