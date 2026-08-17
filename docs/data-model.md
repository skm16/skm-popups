# Data model

Authoritative definition of stored and emitted shapes. Everything else in the
plugin references this document. Changing any shape after Phase 1 requires a
versioned migration.

---

## Post type

```
popkit_popup
```

| Property | Value |
|---|---|
| `public` | `false` |
| `show_ui` | `true` |
| `show_in_rest` | `true` |
| `supports` | `title`, `editor`, `custom-fields`, `revisions` |
| `capability_type` | `array( 'popkit_popup', 'popkit_popups' )` |
| `map_meta_cap` | `true` — required, see `CLAUDE.md` → Capabilities |
| `capabilities` | Explicit primitive map, granted to roles on activation |
| `menu_icon` | dashicon |

Content is authored in the block editor. There is no custom content field.
Post slug doubles as the public identifier used by `window.popkit.open(slug)`
and the `?popkit=` deep link.

## Meta keys

All registered with `show_in_rest`, an explicit `schema`, `single => true`, and
an `auth_callback` checking `edit_post` on the object.

| Key | Shape |
|---|---|
| `_popkit_conditions` | Rule set (below) |
| `_popkit_triggers` | Array of trigger configs |
| `_popkit_schedule` | Schedule object |
| `_popkit_frequency` | Frequency object |
| `_popkit_display` | Display object |
| `_popkit_schema_version` | Integer, currently `1` |

---

## Rule set

Groups are OR'd. Rules within a group are AND'd. An empty `groups` array means
"always match".

```json
{
  "groups": [
    {
      "rules": [
        { "type": "post_type",  "negate": false, "values": { "types": ["post"] } },
        { "type": "user_state", "negate": true,  "values": { "state": "logged_in" } }
      ]
    },
    {
      "rules": [
        { "type": "url_path", "negate": false, "values": { "match": "prefix", "value": "/campaigns/" } }
      ]
    }
  ]
}
```

**Rule fields**

| Field | Type | Notes |
|---|---|---|
| `type` | string | Must match a registered condition key. Unknown types are preserved and treated as indeterminate on the server, then fail closed on the client. |
| `negate` | boolean | Inverts the result. Default `false`. Never applied to an indeterminate or failed-closed rule. |
| `values` | object | Validated against the condition's registered field schema. When the type is unregistered, values are preserved *semantically* within the structural bounds below. |

### Evaluation semantics

Server-side, per group:

- `Context::Server` rule evaluates false → group fails. The rule set may still
  pass via another group.
- `Context::Client` rule → **indeterminate**. The group survives and the rule is
  emitted for the client to decide.
- Unknown rule type → **indeterminate**. The group survives and the rule is
  emitted, tagged `"unknown": true`.
- All groups fail → popup omitted from the response entirely.

Indeterminate means *deferred*, not *satisfied*. The server preserves the group
because it cannot judge the rule, not because the rule passed.

Client-side, per surviving group, with the same AND/OR semantics:

- Rule's `evaluate` function is registered and context is available → evaluate
  normally, then apply `negate`.
- Rule type is unknown, its `evaluate` function is missing, or it needs context
  the client could not obtain → **the rule evaluates false and its group fails.**
  `negate` is **not** applied — negating an unknown would resurrect it.

> Fail-closed on the client is deliberate and asymmetric with the server. A
> condition whose implementing plugin was deactivated must narrow the audience to
> nobody, never widen it to everybody. A popup targeted at one segment silently
> becoming site-wide is the worst failure this plugin can have.

### Bounds on unknown rule values

"Preserved" means *semantically* preserved, not "stored unexamined". Sanitization
cannot validate an unregistered type against a schema it does not have, but it
must still bound what lands in the database — otherwise a deactivated extension,
or a forged REST write, can persist an arbitrarily large payload under a rule
type nothing will ever read.

Unknown `values` are accepted subject to structural limits only:

| Limit | Value |
|---|---|
| Permitted types | string, number, boolean, null, and arrays/objects thereof |
| Maximum nesting depth | 5 |
| Maximum keys or array items per level | 50 |
| Maximum string length | 2,000 characters |
| Maximum serialized size of one rule's `values` | 8 KB |
| Maximum rules per group / groups per set | 50 / 20 |

Anything outside these bounds means the whole rule is rejected at save time with
an error surfaced in the editor. A rule is never silently truncated — a partially
preserved targeting rule is more dangerous than a refused one, because it looks
intact.

Within the bounds, values are stored unmodified: no key reordering, no type
coercion, no stripping of unrecognized keys. A registered condition must see byte-
identical values after a deactivate/reactivate cycle.

Objects and resources are rejected outright. Nothing is ever unserialized.

> A group that failed server-side is not emitted at all. The client never sees
> it and cannot resurrect it.

---

## Built-in conditions

| Key | Context | Group | Needs context | Fields |
|---|---|---|---|---|
| `post_type` | Server | content | — | `types: string[]` |
| `post_ids` | Server | content | — | `ids: int[]` |
| `taxonomy_term` | Server | content | — | `taxonomy: string`, `terms: int[]` |
| `is_front_page` | Server | content | — | — |
| `is_404` | Server | content | — | — |
| `template` | Server | content | — | `templates: string[]` |
| `url_path` | Server | content | — | `match: "exact" \| "prefix" \| "contains" \| "glob"`, `value: string` |
| `device` | Client | visitor | no | `max_width: int` |
| `user_state` | Client | visitor | **yes** | `state: "logged_in" \| "logged_out"` |
| `referrer` | Client | visitor | no | `match: "exact" \| "prefix" \| "contains" \| "glob"`, `value: string` |
| `utm` | Client | visitor | no | `param: string`, `value: string` |
| `visit_history` | Client | visitor | no | `state: "first_time" \| "returning"` |

`url_path` and `referrer` use the constrained match language defined in
`CLAUDE.md` → Security. No regular expressions: `value` is a literal capped at
255 characters, and `glob` supports only `*` and `?`. `url_path` matches the
normalized path only — no scheme, host, query string, or fragment.

`user_state` is client-context deliberately: evaluating it in PHP would vary the
cached response by cookie. It is the only condition that requires the context
endpoint. When a page has any surviving popup carrying a `user_state` rule, the
emitted config sets `needsContext: true` and the client fetches
`/wp-json/popkit/v1/context` once. If that fetch fails, every rule depending on
it fails closed.

`document.body.classList` is **not** an acceptable source for login state. Body
classes are part of the cached HTML and therefore reflect whichever visitor
happened to fill the cache.

### Deferred to 1.1

`user_role` is not in v1. Role data is more sensitive than a login boolean, needs
a considered privacy contract before it crosses the wire, and role-targeted
popups are not required by the launch use case. The context endpoint's `user`
object is shaped to accept a `roles` array in 1.1 without a schema version bump.

---

## Triggers

```json
[
  { "type": "page_load",    "values": { "delay_ms": 3000 } },
  { "type": "scroll_depth", "values": { "percent": 50 } }
]
```

Multiple triggers on one popup are independent. First to fire wins; the rest are
torn down.

| Key | Fields |
|---|---|
| `page_load` | `delay_ms: int` (0 = immediate) |
| `scroll_depth` | `percent: int (1–100)` |
| `click_selector` | `selector: string` |
| `deep_link` | — (matches `?popkit={slug}` or `#popkit-{slug}`) |

### Deferred to 1.1

`time_on_page`, `element_visible`, `idle`, and `exit_intent`.

`page_load` with `delay_ms` covers the common case that `time_on_page` served,
so the two are redundant in v1.

`exit_intent` is deferred rather than shipped desktop-only: a trigger that works
on one device class and not another is a support burden and an honesty problem
in the editor UI. When it does ship, its mobile fallback will be scroll-direction
detection only. **History interception is rejected permanently** — hijacking the
back button to show a popup breaks the browser's most-used control, is defeated
by every modern browser's intervention heuristics, and is precisely the pattern
that gives popup plugins their reputation.

---

## Schedule

```json
{
  "enabled": true,
  "timezone": "site",
  "start": "2026-11-30T00:00:00Z",
  "end":   "2026-12-03T04:59:59Z",
  "recurrence": {
    "days": [1, 2, 3, 4, 5],
    "windows": [{ "from": "09:00", "to": "17:00" }]
  }
}
```

| Field | Notes |
|---|---|
| `timezone` | `"site"` or `"visitor"`. Default `"site"`. |
| `start` / `end` | ISO 8601 UTC. `null` means open-ended. |
| `recurrence.days` | ISO 8601 weekdays, 1 = Monday. Empty array means all days. |
| `recurrence.windows` | Local `HH:MM`, inclusive of `from`, exclusive of `to`. Empty array means all day. A window where `to < from` crosses midnight — see below. |

### Windows crossing midnight

**A window belongs to its starting day.** A window of `22:00`–`02:00` on
`days: [1]` (Monday) is active from Monday 22:00 through Tuesday 01:59, and is
**not** active during Tuesday's own 00:00–02:00 unless Tuesday is also listed.

Evaluation, given the weekday and local time of `now`:

- `from < to` — normal window. Matches when the weekday is listed **and**
  `from <= time < to`.
- `to < from` — crossing window. Matches when either:
  - the weekday is listed and `time >= from` (the evening portion), **or**
  - *the previous day* is listed and `time < to` (the morning portion).
- `from == to` — zero-length. Never matches. The editor warns.

The alternative reading — that the window applies to both calendar days it
touches — would make "Friday night, 22:00–02:00" silently include Friday
00:00–02:00, showing a popup roughly a day before the author intended.

Worked example, `days: [5]` (Friday), window `22:00`–`02:00`:

| Moment | Active |
|---|---|
| Friday 21:59 | no |
| Friday 22:00 | yes |
| Saturday 01:59 | yes |
| Saturday 02:00 | no |
| Friday 01:00 | **no** — that is Thursday's window, and Thursday is not listed |

Visible when `now` falls within `[start, end]` **AND** (`recurrence` is absent
**OR** weekday matches **AND** (`windows` is absent or empty **OR** time falls
within a window)).

**An absent or empty `windows` list means the whole day**, not "no time at all".
The empty list is the default stored shape, so the opposite reading would
silently suppress every plain date-range campaign — one that sets `start` and
`end` and nothing else. A window is a narrowing of the day, and a list of none
narrows nothing. Only a zero-length window (`from == to`), which is an explicit
entry, never matches.

Evaluated once at page load, never on an interval — a popup must not vanish
mid-interaction.

### Where `now` comes from

An enabled schedule sets `needsContext: true` on the emitted config. The client
fetches `/wp-json/popkit/v1/context?fields=time`, then computes a one-time
offset against the monotonic clock:

```js
const sentAt     = performance.timeOrigin + performance.now();   // before fetch
// … await the response …
const receivedAt = performance.timeOrigin + performance.now();   // after parse

// Assume symmetric latency: the server's clock reading corresponds to the
// midpoint of the round trip, not to the moment the response was parsed.
const clientAtResponse = ( sentAt + receivedAt ) / 2;
const offset           = context.time - clientAtResponse;

const authoritativeNow = () =>
    performance.timeOrigin + performance.now() + offset;
```

**`Date.now()` appears nowhere** — not in the offset, and not in the comparison.
`performance.now()` is monotonic and unaffected by device clock adjustments, so
both sides of the calculation stay on the same time base. Mixing the two would
reintroduce exactly the failure the offset exists to correct: a visitor whose
clock changes after initialization would see schedules jump by the same amount.

The round-trip midpoint corrects for network latency. Without it the computed
clock runs late by the response leg — a few hundred milliseconds at worst.
Irrelevant for windows measured in minutes, but the midpoint is one line and
removes the question.

`performance.timeOrigin` is fixed at document creation and does not change, so it
is safe on both sides of the subtraction; it is included so the value is a real
epoch timestamp comparable against `start` and `end`.

> This offset is calibrated for schedule evaluation, where windows are minutes or
> hours wide. It absorbs latency asymmetry and is not suitable for anything
> requiring sub-second precision. Do not repurpose it.

**A timestamp is never embedded in the cached HTML.** Doing so would freeze the
value at cache-fill time: a page cached at 09:00 and served at 17:00 reports
09:00 to every visitor, and adding elapsed script time recovers none of the eight
hours spent sitting in the cache. There is no `serverTime` field in the emitted
config.

If the context fetch fails, scheduled popups **do not show**. Showing an expired
campaign is worse than showing nothing, and a live campaign that misses a few
impressions during an outage is recoverable.

`timezone: "site"` compares against `siteTimezone` from the config, which is a
site setting and therefore cache-safe. `timezone: "visitor"` uses the browser's
resolved zone with the corrected clock.

Only one campaign window is supported. Multiple OR'd schedule blocks are out of
scope for v1.

---

## Frequency

```json
{
  "mode": "once_per_days",
  "days": 7,
  "on_convert": "suppress_forever"
}
```

| Mode | Storage | Behavior |
|---|---|---|
| `always` | none | No cap. Shows on every pageview that passes all other stages. |
| `once_per_session` | `sessionStorage` | Shows at most once per browser session. |
| `once_per_days` | `localStorage` | Shows at most once per rolling `days` window, measured from the last recorded open. `days` required, 1–365. |
| `once_ever` | `localStorage` | Shows at most once, permanently. |

`until_dismissed` has been **removed**. The name did not describe a single
behavior — it could plausibly mean "keep showing until the visitor dismisses it",
"stop showing once dismissed", or "suppress only while a dismissal is on record",
and the three differ on every subsequent pageview. `once_ever` expresses the
intended case unambiguously; the "keep nagging until dismissed" reading is not a
behavior this plugin will implement.

### When a popup counts as seen

A record is written **only after a successful, non-cancelled open**: after
`popkit:before-open` completed without `preventDefault()`, and after the dialog
is actually displayed. It is never written when a popup is merely eligible,
armed, or triggered.

This matters because an aborted open is invisible to the visitor. Recording it
would consume the visitor's single impression on something they never saw.

### Conversion behavior

| `on_convert` | Transition |
|---|---|
| `suppress_forever` | On `popkit:convert`, the record is upgraded to permanent suppression regardless of `mode`. The popup never shows again in that browser. |
| `none` | Conversion is recorded for integrations but does not alter the cap. `mode` continues to govern. |

Default is `suppress_forever`. Conversion is the strongest possible signal that
the popup has done its job — a visitor who just donated should not be asked
again next week.

This replaces `reset_on_convert`, whose name described the inverse of the useful
behavior. "Reset" would clear the suppression and make a just-converted visitor
immediately eligible again.

`until_converted` as a mode is also removed: it was `once_ever` with extra steps,
and is now expressed as any mode plus `on_convert: "suppress_forever"`.

### Storage

Key: `popkit:seen:{popup_id}`

```json
{ "at": 1766000000000, "converted": false }
```

**Browser storage only** — `sessionStorage` for `once_per_session`,
`localStorage` for every other capped mode. No cookies: cookies vary the cached
response and land you in consent-banner scope in the EU.

All storage access is wrapped in try/catch. Private browsing modes and
storage-disabled browsers throw on access; when storage is unavailable the cap is
treated as unmet and the popup shows. This is the one deliberate fail-open in the
system — frequency capping is a courtesy, not a targeting boundary, and silently
suppressing every popup for privacy-mode visitors is worse than showing one more
than intended.

---

## Display

```json
{
  "layout": "modal",
  "theme": "inherit",
  "size": "medium",
  "position": "center",
  "overlay": true,
  "close_on_overlay_click": true,
  "animation": "fade"
}
```

| Field | Values |
|---|---|
| `layout` | `modal` \| `banner` |
| `theme` | `inherit` \| `light` \| `dark` \| `bordered` |
| `size` | `small` \| `medium` \| `large` \| `full` |
| `position` | modal: `center` \| `top`; banner: `top` \| `bottom` |
| `overlay` | boolean — modal only, ignored for banner |
| `close_on_overlay_click` | boolean — modal only, requires `overlay: true` |
| `animation` | `none` \| `fade` \| `slide` — all become `none` under `prefers-reduced-motion` |

**There is no `close_button` field.** Every popup renders a visible close button
in every layout and theme; it is not configurable. The previous rule — permitting
`close_button: false` when overlay-click dismissal was enabled — contradicted the
constitution's requirement for a visible dismissal affordance. An overlay click
is not an affordance: it is invisible, has no accessible name, is not reachable
by keyboard, and is not discoverable by screen reader users.

`close_on_overlay_click` and Escape remain available as *additional* dismissal
paths. Escape is native `<dialog>` behavior and is never suppressed.

A migration is not required — v1 has not shipped, so no stored config contains
the field. Should a `close_button` key be encountered, sanitization discards it.

---

## Emitted frontend config

Printed once per page as `<script type="application/json" id="popkit-config">`.
Contains only popups that survived server-side evaluation.

```json
{
  "version": 1,
  "siteTimezone": "America/New_York",
  "needsContext": true,
  "restUrl": "https://example.org/wp-json/popkit/v1/context",
  "popups": [
    {
      "id": 42,
      "slug": "giving-tuesday",
      "conditions": { "groups": [ { "rules": [ /* client-context only */ ] } ] },
      "triggers":   [ /* … */ ],
      "schedule":   { /* … */ },
      "frequency":  { /* … */ },
      "display":    { /* … */ }
    }
  ]
}
```

| Field | Notes |
|---|---|
| `siteTimezone` | Site setting, identical for all visitors, cache-safe. |
| `needsContext` | `true` when at least one emitted popup has an enabled schedule or a `user_state` rule. The client fetches `/wp-json/popkit/v1/context` only when this is `true`. |
| `restUrl` | Absolute URL of the context route, from `rest_url()`. Derived from a site setting, identical for all visitors, cache-safe. The client reads it rather than assembling a path, so a site on a non-default REST prefix or a subdirectory install still resolves. |

**There is no `serverTime`.** Every value in this document is a function of the
URL and of site settings, never of the request. That is what makes the response
byte-identical across visitors and safe to cache indefinitely — a per-request
timestamp would have broken both properties at once.

Server-context rules are stripped before emission — they have already been
decided and shipping them leaks targeting configuration to the page source.

An unregistered rule is emitted with `"unknown": true` and its original `values`,
so the client can fail it closed and the editor can flag it. Its `type` is
retained; nothing is silently dropped.

Markup for each popup renders inline as a `<dialog>` (or `<div>` for banner)
adjacent to the config script, hidden until opened. No AJAX fetch on open —
the round trip is more expensive than the markup.

---

## Context endpoint payload

```
GET /wp-json/popkit/v1/context?fields=time,user_state
Cache-Control: no-store, private
Vary: Cookie
```

```json
{
  "time": 1766000000000,
  "user": { "state": "logged_out" }
}
```

| Requested field | Response key | Notes |
|---|---|---|
| `time` | `time` | Server epoch milliseconds at response generation. |
| `user_state` | `user.state` | `"logged_in"` or `"logged_out"`. |

The endpoint has no way to know which page called it — a REST request carries no
reliable page identity, and trusting a client-supplied one would be pointless
since the client already decides what to ask for. So the contract is explicit:
**the client names the fields it needs and the server returns exactly those.**

- The client derives `fields` from the emitted config — `time` if any surviving
  popup has an enabled schedule, `user_state` if any has a `user_state` rule.
- The server allowlists `time` and `user_state`. Unrecognized names are ignored
  rather than erroring, so a future client asking for a field an older server
  does not know about degrades to a partial response instead of a failure.
- An empty or absent `fields` returns `{}`. The client treats a missing requested
  key as context-unavailable and fails those popups closed.

Login state is determined by reading the logged-in cookie directly, **not** via
`is_user_logged_in()` — see `CLAUDE.md` → The context endpoint for why that call
would report `logged_out` for everyone.

The payload carries no user ID, display name, email, capability list, or role.
`user.roles` is reserved for 1.1 and absent in v1. Adding any field requires the
sign-off listed in `CLAUDE.md` → Ask before doing.

The route is deliberately minimal so that a site owner reading their access logs
sees nothing sensitive, and so that a misconfigured cache in front of it leaks a
timestamp and a boolean rather than identity.

---

## Migrations

`_popkit_schema_version` is compared on load. A version below current runs the
migration chain in `includes/migrations/`, each migration idempotent and
individually unit tested. Never mutate a shape in place without incrementing the
version and writing the migration in the same commit.
