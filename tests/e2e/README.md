# End-to-end tests

Playwright against a real WordPress install. **Not wp-env** — see
[Why not wp-env](#why-not-wp-env). The instance is a stock WordPress 6.5.5
served by PHP's built-in server, provisioned and seeded by scripts that live
outside this repository.

## Run

```bash
npm run test:e2e
```

That is the whole thing. Playwright's `webServer` block starts the instance if
nothing is answering on the port, provisioning it first if it does not exist
yet, and stops it when the run finishes. Nothing needs to be started by hand.

Useful variations:

```bash
npx playwright test --project=chromium     # desktop only
npx playwright test --project=mobile       # Pixel 7, touch enabled
npx playwright test --headed --debug       # step through a failure
npx playwright show-report                 # open the last HTML report
```

## The instance

| | |
|---|---|
| URL | `http://127.0.0.1:8899` |
| WordPress | 6.5.5, stock release build |
| Theme | Twenty Twenty-Four |
| Admin | `admin` / `password` |
| Database | `popkit_e2e` on `127.0.0.1:10240`, prefix `pke2e_` |
| Permalinks | `/%postname%/` |
| Site timezone | `America/New_York` |
| Harness | `<scratchpad>/e2e-site/` |
| Docroot | `C:/tmp/popkit-e2e/wordpress` |

The harness directory is:

```
C:/Users/srskm/AppData/Local/Temp/claude/c--Users-srskm-Local-Sites-family-ties-of-westchester-app-public-wp-content-plugins-skm-popups/a171f5dd-f837-424f-a965-804c9b126faf/scratchpad/e2e-site
```

`playwright.config.js` hardcodes that path and accepts `POPKIT_E2E_DIR` as an
override. If the directory is missing the config declares no `webServer` at all
and the suite runs against `WP_BASE_URL`, so the file stays loadable on a
machine that has never provisioned the instance.

**MySQL comes from Local.** Port 10240 is the Local app's MySQL for the
family-ties site. Local has to be running or the database is unreachable — the
same dependency the PHPUnit integration suite already has. Only the server is
shared: e2e uses its own `popkit_e2e` database and its own table prefix, and
never touches `local` or `popkit_tests`.

## Commands

Run these from the harness directory. `$E2E` below is that path.

```bash
php "$E2E/setup.php"                # provision or repair. Idempotent.
node "$E2E/serve.mjs"               # provision if needed, then serve
node "$E2E/serve.mjs" --no-setup    # serve only, skip provisioning
node "$E2E/stop.mjs"                # kill whatever is listening on 8899
php "$E2E/reset.php"                # drop the database and provision from scratch
php "$E2E/env.php"                  # print resolved paths and URLs as JSON
```

Seeding on its own, without touching anything else:

```bash
php "$E2E/seed.php"                 # purge every popup, recreate the seed set
php "$E2E/seed.php" --unpublish     # draft every popup (the zero-footprint state)
php "$E2E/seed.php" --publish       # publish the fixtures seed.php owns
php "$E2E/seed.php" --inventory     # print what is there; change nothing
```

**`seed.php` is authoritative over fixture content, and destructive by design.**
A plain run deletes every `popkit_popup` post first — in every status, including
`trash` and `auto-draft` — and then creates the seed set from nothing. It does
not merge with what it finds, and `--publish` will not republish a popup it did
not create.

That matters more than it sounds. Because no built-in server condition is
registered yet, every stored rule is indeterminate and any *published* popup
matches every URL, so one popup abandoned by a crashed spec silently changes the
meaning of every "exactly N popups" assertion in the suite. When seeding merely
upserted by slug, that leftover survived into the next run and `--publish` put it
back on screen. Three consecutive full runs disagreed with each other before the
cause was found.

Every mode prints an inventory afterwards — count, slugs, statuses, stored meta,
and a `FOREIGN` marker against anything that is not a seed fixture. A run's
starting state is in the log rather than assumed.

`reset.php` is authoritative over the *database*: it drops `popkit_e2e` and has
`setup.php` rebuild it, reaching `seed.php` as the final stage. Use `reset.php`
when the install or the schema is suspect; use `seed.php` when only the content
is. Both refuse to run against any database but `popkit_e2e`, and `seed.php`
additionally checks the connection it is actually holding — including `siteurl`
read from the connected database, which is the only check whose expected value
is not derived from the same constant it is testing.

To force a fresh WordPress tree as well as a fresh database, delete
`C:/tmp/popkit-e2e` and run `setup.php`. The release archive is cached at
`$E2E/wordpress-6.5.5.zip`, so that costs no network.

### What `setup.php` does

1. Unpacks WordPress 6.5.5 into the docroot if it is absent, downloading the
   release archive once and caching it.
2. Creates the `popkit_e2e` database if it is absent.
3. Writes a three-line `wp-config.php` into the docroot that requires
   `$E2E/wp-config-e2e.php`, where the real configuration lives.
4. Writes `wp-content/mu-plugins/popkit-e2e.php` — see
   [the admin bar](#the-admin-bar-and-byte-identical-html).
5. Junctions the plugin working tree to `wp-content/plugins/popkit`.
6. Installs WordPress by calling `wp_install()` directly.
7. Activates popkit and pins Twenty Twenty-Four.
8. Seeds the fixtures, purging every existing popup first, and prints an
   inventory of what the instance ends up holding.

Steps 6, 7 and 8 each run in their own PHP process. They have to: `WP_INSTALLING`
makes `wp_get_active_and_valid_plugins()` return nothing, so the process that
installs WordPress cannot be a process in which popkit is loaded, and the process
that activates the plugin loaded before it was active, so its `plugins_loaded`
hook never fired there either.

Every step is idempotent and re-runnable, in the sense that running it twice
leaves the same state. Steps 1 to 7 on a healthy instance are a no-op that prints
what they found; step 8 always does its work, because it reaches that same state
by rebuilding the fixtures rather than by inspecting them.

### The plugin is linked, not copied

`wp-content/plugins/popkit` is a Windows directory **junction** pointing at the
working tree, so e2e always tests the files you are editing — no build or copy
step between an edit and a test run. A junction needs no elevation, unlike a
symbolic link.

PHP resolves the junction, so `__FILE__` inside the plugin reports the working
tree path rather than the docroot path. That is fine and expected: WordPress
registers linked plugin directories through `wp_register_plugin_realpath()`, so
`plugin_dir_url()` still resolves. Verified:

```
POPKIT_DIR = C:\Users\...\plugins\skm-popups\
POPKIT_URL = http://127.0.0.1:8899/wp-content/plugins/popkit/
```

If the junction cannot be created, `setup.php` falls back to copying the tree
and says so loudly on stderr. A copy is a snapshot — re-run `setup.php` after
every plugin change if you are ever in that state.

## Fixtures

Seeded by `seed.php`. The popup is purged and recreated on every run, so its
post ID changes; the pages are updated in place, so theirs do not. Address
fixtures by slug, never by ID.

| Slug | Type | Notes |
|---|---|---|
| `popkit-e2e` | page | Fixture page. Heading, paragraph, two buttons, a link — enough for focus containment, focus return, and background-inert assertions. |
| `popkit-e2e-clean` | page | Prose only. |
| `e2e-modal` | `popkit_popup` | Published. Modal, `page_load` trigger with `delay_ms: 0`, schedule disabled, frequency `always`, default display. Content carries an `<h2>` for `aria-labelledby` to resolve to, two links, and a button. |

Elements inside the fixture page and popup carry `data-testid` attributes:
`popkit-e2e-trigger`, `popkit-e2e-background-link`, `popkit-e2e-second-trigger`,
`popkit-e2e-inside-one`, `popkit-e2e-inside-two`, `popkit-e2e-convert`.

`e2e-modal` targets `/popkit-e2e/` with a `url_path` rule. **That rule does
nothing yet** — Phase 4 registers the built-in server conditions, and until then
every rule is indeterminate, so the popup survives server-side matching on every
URL. The rule is seeded now so the fixture narrows to its page automatically when
Phase 4 lands, without the fixture changing.

`e2e-modal` always holds the **lowest post ID of any popup**, which
`accessibility.spec.js` relies on to reason about which popup is considered
first. The purge-and-recreate order is what preserves that: seeding empties the
post type and recreates the fixture before any spec runs, so every popup a spec
creates afterwards is necessarily later. Do not create popups outside a spec.

Anything beyond these three, create per-spec through `requestUtils` and delete
afterwards with `force: true`, which bypasses the trash — a trashed popup is
invisible to `post_status => 'any'` and is exactly the leftover that used to
survive seeding. Shared mutable fixtures are how a suite starts depending on test
order. Creating a popup with meta over REST is verified working:

```js
const popup = await requestUtils.rest( {
	method: 'POST',
	path: '/wp/v2/popkit_popup',
	data: {
		title: 'Scheduled popup',
		status: 'publish',
		slug: 'spec-scheduled',
		content: '<!-- wp:heading --><h2>Hello</h2><!-- /wp:heading -->',
		meta: { _popkit_schedule: { enabled: true, timezone: 'site' } },
	},
} );
```

## Known constraints

Read these before writing a spec. Each one has already cost time.

### Exactly one agent, or one person, runs e2e at a time

This is not a style preference. There is **one** WordPress install, on **one**
port, backed by **one** database, writing to **one** set of artifact
directories. Nothing about the harness is per-run isolated:

| Shared thing | Where | What a second run does to it |
|---|---|---|
| Database | `popkit_e2e` | Deletes and republishes the other run's popups mid-assertion |
| Port | `127.0.0.1:8899` | Second `webServer` finds 8899 answering, `reuseExistingServer` attaches to the first run's instance |
| Docroot | `C:/tmp/popkit-e2e/wordpress` | A concurrent `setup.php` rewrites config and the mu-plugin under the running server |
| Artifacts | `artifacts/`, `playwright-report/` | Traces, screenshots and the HTML report overwrite each other |
| Storage state | `artifacts/storage-states/admin.json` | Two runs write the same login artifact |

The database is the one that actually bites. Specs create popups over REST and
delete them in `afterEach`; a second run seeding, drafting or purging at the same
moment makes both runs read a post count neither of them caused. The failures
that result are not reproducible and do not point at the code — they look like
flakes, and they are not.

**This has now been diagnosed twice from scratch**, both times after several
hours spent hunting a race in the runtime that was never there. If work is being
parallelised across agents, exactly one of them runs Playwright, and it runs when
the others have finished editing. Everything else — PHPCS, PHPUnit, esbuild,
size-limit, Jest — is safely concurrent, because none of it touches this
instance.

Before starting a run, confirm nobody else is mid-run:

```bash
netstat -ano | grep 8899          # is an instance already up, and whose?
php "$E2E/seed.php" --inventory   # what does the database actually contain?
```

An inventory showing anything marked `FOREIGN`, or more popups than the one
fixture, means either a previous run crashed or another one is live right now.
`php "$E2E/seed.php"` clears the first case; only waiting clears the second.

### One worker

`php -S` serves one request at a time on Windows. `PHP_CLI_SERVER_WORKERS` does
not exist there, because it forks. A second Playwright worker does not run in
parallel — it queues behind the first and spends its budget waiting, which turns
a slow assertion into a timeout. `playwright.config.js` therefore pins
`workers: 1`, overridable with `PW_WORKERS` if you want to prove the point.

For the same reason `wp-config-e2e.php` sets `DISABLE_WP_CRON` and disables
update checks. A request that fires a loopback request at this server waits for a
server that is busy waiting for it.

### Traces are recorded without DOM snapshots

`trace: 'retain-on-failure'` crashes the Chromium renderer on this machine.
Every spec dies with `page.evaluate: Target crashed`, and the same spec passes
with tracing off. The config uses
`{ mode: 'retain-on-failure', snapshots: false }`, which keeps actions, network,
console and screenshots, and loses only the time-travel DOM viewer. Re-test the
plain string after a Playwright or Chromium bump.

### A published popup is emitted on every page

Until Phase 4 registers the built-in server conditions, every stored rule is
indeterminate, so every published popup survives server-side matching on every
URL. The emitted-nothing assertions therefore hold only when **no popup is
published at all**, not when the visited page is one the popup does not target.

Still true as of Phase 3, and easy to see for yourself — the seeded fixture is
served on the front page, which it does not target:

```console
$ curl -s http://127.0.0.1:8899/ | grep -o 'data-popkit-slug="[^"]*"'
data-popkit-slug="e2e-modal"
```

`Frontend::init()` **is** wired into `Plugin::boot()` as of Phase 2, and
`Rest_Context::init()` with it, so popkit really does emit markup, config and
assets here. An earlier version of this section said the opposite and predicted
that wiring would break `smoke.spec.js`. It did, and the spec was fixed: it now
drafts every published popup for the duration of the test and republishes them in
a `finally`, so "zero popups match" is a fact it establishes rather than a
property of the URL it visits. `seed.php --unpublish` remains available for the
same purpose from the command line.

When Phase 4 lands, that drafting becomes unnecessary and the spec can assert
against `/popkit-e2e-clean/` with the fixtures left published. Do not "fix" any
of this by deleting the fixture — the other exit criteria need it.

### The admin bar, and byte-identical HTML

`setup.php` writes one mu-plugin, `wp-content/mu-plugins/popkit-e2e.php`. It
hides the admin bar on the front end and does nothing else.

Without it the cache-safety exit criterion — two requests to one URL, one
authenticated and one not, produce byte-identical HTML — fails before popkit
contributes anything, on roughly seven kilobytes of core's own per-user markup
plus two stylesheets. Hiding the admin bar is an ordinary site setting, not a
test-only fiction.

One difference survives and cannot honestly be removed: core adds `logged-in` to
the `<body>` class list. Re-measured in Phase 3 on `/popkit-e2e/`, anonymous
versus authenticated, with different user agents — the popup the page now serves
is byte-identical across both, and the ten-byte delta is exactly `logged-in `:

```
anonymous     70753 bytes
authenticated 70763 bytes
1134c1134
< <body class="page-template-default page page-id-4 wp-embed-responsive">
---
> <body class="page-template-default page page-id-4 logged-in wp-embed-responsive">
```

A byte-comparison spec has to normalise the body class list, or compare the
popkit subtree rather than the whole document. This is the same fact
`docs/data-model.md` states from the other side when it rules out body classes as
a source of login state.

If an assertion only passes with the mu-plugin present, and it is about popkit
rather than about core chrome, the assertion is wrong.

### `requestUtils` needs `WP_BASE_URL`

`@wordpress/e2e-test-utils-playwright` defaults its internal REST base URL to
`http://localhost:8889` — wp-env's tests environment. `playwright.config.js` sets
`process.env.WP_BASE_URL` before the fixtures load, so specs are fine. A
standalone script that imports `RequestUtils` directly is not, and fails with
`ECONNREFUSED ::1:8889`.

### Docroot is outside the harness directory

WordPress is unpacked to `C:/tmp/popkit-e2e/wordpress` rather than beside the
scripts, because the scratchpad path is 191 characters before WordPress adds any
of its own. `wp-content/themes/twentytwentythree/patterns/hidden-404.php` alone
lands on 260 — exactly Windows MAX_PATH — and files under `wp-includes/blocks/`
overshoot it. Extraction fails midway, and PHP could not read the files even if
it did not.

Everything authored — config, scripts, fixtures, logs — stays in the harness
directory. Only the third-party tree lives at the short path.

## Why not wp-env

`npx wp-env start` cannot build its images on this network. The wp-env Dockerfile
runs an unauthenticated `composer global require`, which returns HTTP 429 from
`codeload.github.com`. Retrying does not help.

`.wp-env.json` is still in the repository and is still correct; the
`npm run env:start` and `env:stop` scripts simply do not work here. If the
network situation changes, point `WP_BASE_URL` at `http://localhost:8889` and the
suite runs against wp-env unchanged — the config prefers an explicit
`WP_BASE_URL` over its own default, and `reuseExistingServer` leaves a running
environment alone.

What is lost by not using wp-env, and worth knowing:

- **The version floor is 6.5.5, not 6.5.** Close enough to catch a 6.6 API call.
- **PHP is 8.3, not 8.1.** So this suite does *not* catch a PHP 8.2+ syntax or
  API slip. PHPCS's `PHPCompatibilityWP` sniffs are the only thing standing
  between the plugin and that class of bug — keep `composer run lint` green.
- **Plugin Check is not installed.** `npm run plugin-check` has its own
  arrangement; it does not read this instance.

## Layout and output

- Specs: `tests/e2e/*.spec.js`
- HTML report: `playwright-report/`
- Traces, failure screenshots, storage state: `artifacts/`
- Server log: `$E2E/server.log` when started by hand
- WordPress debug log: `$E2E/debug.log`

`WP_DEBUG` and `WP_DEBUG_LOG` are on, `WP_DEBUG_DISPLAY` is off, and the fatal
error handler is disabled, so a notice never corrupts the HTML under assertion —
which would otherwise break the byte-identical comparison for a reason that is
not the plugin — and still lands in the log:

```bash
tail -50 "$E2E/debug.log"
```

Both output directories are build artifacts and belong in `.gitignore`.
Open a trace with
`npx playwright show-trace artifacts/test-results/<test>/trace.zip`.

## Writing tests

Specs are CommonJS (`require`), matching the rest of the toolchain.

`playwright.config.js` defaults the environment variables that
`@wordpress/e2e-test-utils-playwright` reads — `WP_BASE_URL`, `WP_USERNAME`,
`WP_PASSWORD`, `WP_ARTIFACTS_PATH`, `STORAGE_STATE_PATH` — so a spec can import
its fixtures directly:

```js
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
```

There is **no `globalSetup` and no config-level `storageState`**. Pointing
`storageState` at a file that does not exist yet fails every test before it
runs, so the login artifact is created on demand by the `requestUtils` fixture
instead. When a spec needs the browser itself to be logged in, log in inside the
test (`admin.visitAdminPage()`), or add a `globalSetup` that saves storage state
to `STORAGE_STATE_PATH` and set `use.storageState` alongside it.

Two behaviours of this instance are worth knowing when writing context-endpoint
specs, because both are load-bearing for popkit and both are reproduced
faithfully here:

- A REST request carrying a valid logged-in cookie but **no `X-WP-Nonce`** is
  treated as anonymous. `GET /wp-json/wp/v2/users/me` with cookies and no nonce
  returns `rest_not_logged_in`. That is precisely the
  `rest_cookie_check_errors()` behaviour that makes `is_user_logged_in()`
  unusable in the context route.
- Salts in `wp-config-e2e.php` are fixed, so a saved storage state survives a
  server restart and the logged-in cookie is reproducible across runs.

Accessibility assertions are not optional extras here — per `docs/CLAUDE.md`,
any new UI surface ships its Playwright assertions in the same commit.

## Verifying the instance by hand

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8899/
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8899/popkit-e2e/
curl -s http://127.0.0.1:8899/wp-json/ | grep -o 'popkit/v1'
curl -s "http://127.0.0.1:8899/wp-json/popkit/v1/context?fields=time,user_state"
curl -s -o /dev/null -w "%{http_code}\n" \
  http://127.0.0.1:8899/wp-content/plugins/popkit/dist/frontend.js
```

Last observed in Phase 3, fixtures seeded and published:

| Check | Result |
|---|---|
| `/` | 200, 98965 bytes, WordPress rendered |
| `/popkit-e2e/` | 200, 70753 bytes anonymous |
| `/popkit-e2e-clean/` | 200, 70413 bytes |
| `/wp-login.php` POST | 302, `wordpress_logged_in` cookie set |
| `/wp-admin/` authenticated | 200 |
| `dist/frontend.js` through the junction | 200, `application/javascript`, byte count matching the working tree |
| `/wp-json/` namespaces | `oembed/1.0`, **`popkit/v1`**, `wp/v2`, `wp-site-health/v1`, `wp-block-editor/v1` |
| `/popkit/v1/context` | 200, `{"time":…,"user":{"state":"logged_out"}}` |

The `popkit/v1` namespace exposes `/popkit/v1/registry` and `/popkit/v1/context`.
Both answer. An earlier version of this section recorded context as **404**
because Phase 2 had not yet wired `Rest_Context::init()` into `Plugin::boot()`;
it has since, and nothing in the harness needed to change for it to start
answering.

Those three page sizes are larger than the figures recorded in Phase 0 because
popkit now emits config, markup and asset tags on every page — see
[A published popup is emitted on every page](#a-published-popup-is-emitted-on-every-page).
Treat them as a sanity check on the order of magnitude, not as a fixture: any
change to the popup's content or the front-end bundle moves them.
