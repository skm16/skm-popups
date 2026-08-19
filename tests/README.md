# Running the test suites

Four suites, three of which need no Docker.

| Suite | Command | Needs |
|---|---|---|
| PHP unit | `composer run test:unit` | nothing |
| PHP integration | `composer run test:integration` | MySQL + the WordPress test suite |
| JS unit | `npm run test:unit:js` | nothing |
| E2E | `npm run test:e2e` | wp-env (Docker) |

## PHP unit — no WordPress required

```bash
composer run test:unit
```

Pure-logic classes only: the capability map, the URL matcher, sanitization
bounds. `tests/php/bootstrap-unit.php` loads a small set of WordPress function
stubs from `tests/php/stubs/`, so these run in milliseconds with no database and
no container. Anything that genuinely needs WordPress belongs in the integration
suite instead — do not widen the stubs to make a WordPress-coupled test fit here.

## PHP integration — the normal path

```bash
npm run env:start
composer run test:integration
```

## PHP integration — without Docker

`wp-env` builds its images by running `composer global require phpunit/phpunit`
inside the container. That download is unauthenticated, so a rate-limited network
fails the build with `exit code 100` and an HTTP 429 from `codeload.github.com`.
Nothing about the plugin is wrong when this happens, and retrying often does not
help.

The suite only needs a MySQL server and a checkout of the WordPress test
framework, both of which can live outside Docker.

**1. A MySQL server.** If you develop in Local, the site's MySQL is already
running. Local exposes it over TCP on a per-site port — there is no unix socket
on Windows. The port is in the site's `wp-cli.local.php` as `DB_HOST`, or in
`%APPDATA%\Local\sites.json`.

**2. An isolated database.**

```sql
CREATE DATABASE popkit_tests
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

> **The WordPress test suite drops every table in its database on each run.**
> Point it at a dedicated database. Never at the database backing a real site —
> in Local that is `local`, and it holds the site you are working on.

**3. The WordPress test framework.**

```bash
gh api repos/WordPress/wordpress-develop/tarball/6.5.5 > wp-develop.tar.gz
tar xzf wp-develop.tar.gz
```

`gh` authenticates the request, which is what avoids the 429 that breaks wp-env.
Tag `6.5.5` is deliberate: it is the minimum supported version, and testing
against the floor catches accidental use of newer APIs.

**4. `wp-tests-config.php`** at the root of that checkout:

```php
define( 'ABSPATH', __DIR__ . '/src/' );
define( 'DB_NAME', 'popkit_tests' );   // never a real site database
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', '127.0.0.1:PORT' ); // the per-site port from step 1
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'popkit integration tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
```

Plus the eight `AUTH_KEY` / `*_SALT` constants — any fixed values are fine for an
ephemeral database.

**5. Run it.**

```bash
export WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit
vendor/bin/phpunit --testsuite integration \
  --bootstrap tests/php/bootstrap-integration.php
```

`tests/php/bootstrap-integration.php` mounts the plugin on `muplugins_loaded`, so
the plugin does not need to sit inside the checkout's `wp-content/plugins`. It
also locates the main plugin file by reading plugin headers rather than assuming
a filename, which is what lets the repository directory and the plugin slug
differ.

## A note on the two sentinels

`tests/php/integration/test-uninstall.php` seeds a probe option and a probe
transient before every simulated uninstall and asserts both are gone afterwards.

This is not redundant paranoia. The suite loads `uninstall.php` once per PHP
process and then re-invokes a captured entry point; an earlier revision resolved
that entry point by substring match and landed on a getter. Every "your popups
survive by default" assertion passed while the destructive code never ran — they
would have passed unchanged if uninstall deleted every popup on the site.

If a sentinel survives, the test fails with a message naming the resolved entry
point. **Fix the resolution, never the assertion.**
