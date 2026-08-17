# End-to-end tests

Playwright against a real WordPress install provided by `@wordpress/env`.
**Docker Desktop must be running** — wp-env is a Docker orchestrator, and every
command here fails immediately without it.

## Run

```bash
npx wp-env start        # first run pulls images and installs WordPress 6.5
npm run test:e2e        # Playwright, both projects
```

Useful variations:

```bash
npx playwright test --project=chromium     # desktop only
npx playwright test --project=mobile       # Pixel 7, touch enabled
npx playwright test --headed --debug       # step through a failure
npx playwright show-report                 # open the last HTML report
npx wp-env stop                            # free the ports
npx wp-env destroy                         # wipe the database and start over
```

## Environment

| | Development | Tests |
|---|---|---|
| URL | http://localhost:8888 | http://localhost:8889 |
| Purpose | manual poking | Playwright and PHPUnit |
| Reset | `wp-env clean development` | `wp-env clean tests` |

E2E runs against **8889**, the tests environment, because it can be wiped
without destroying anything you were looking at in the browser. Override with
`WP_BASE_URL` to point the suite at any other install.

Both environments pin the floor of the support matrix — **WordPress 6.5 and PHP
8.1** — on purpose. Testing against the minimum is what catches an accidental
call to a 6.6 API or PHP 8.2 syntax. If wp-env cannot resolve the git ref,
`"core": "https://wordpress.org/wordpress-6.5.zip"` is an equivalent pin.

`WP_DEBUG` and `WP_DEBUG_LOG` are on, `WP_DEBUG_DISPLAY` is off, and the fatal
error handler is disabled, so notices never corrupt the HTML under assertion but
still land in `wp-content/debug.log` inside the container:

```bash
npx wp-env run tests-cli "cat wp-content/debug.log"
```

The Plugin Check plugin is installed in both environments so `npm run
plugin-check` has something to run against.

Admin credentials are the wp-env defaults, `admin` / `password`.

## Layout and output

- Specs: `tests/e2e/*.spec.js`
- HTML report: `playwright-report/`
- Traces, failure screenshots, storage state: `artifacts/`

Both output directories are build artifacts and belong in `.gitignore`.
Traces are kept only for failures (`retain-on-failure`); open one with
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

`smoke.spec.js` stays deliberately anonymous: it asserts the front page returns
200 and that no script, stylesheet, markup, or network request mentions popkit
when no popup matches. That zero-footprint property is a Phase 0 exit criterion
and every later phase must preserve it.

Accessibility assertions are not optional extras here — per `docs/CLAUDE.md`,
any new UI surface ships its Playwright assertions in the same commit.
