<?php
/**
 * PHPUnit bootstrap for popkit.
 *
 * This is the bootstrap named in phpunit.xml.dist for *both* suites, because
 * PHPUnit allows only one. It resolves which suite is running and builds the
 * matching environment:
 *
 * - `integration` — hands off to bootstrap-integration.php, which loads the
 *   WordPress test suite and the plugin. Nothing in this file's unit path runs.
 * - anything else — the default. Loads Composer's autoloader, the WordPress
 *   function stubs, and an autoloader that maps `Popkit\` onto includes/ using
 *   WordPress file naming. No WordPress, no database, no Docker.
 *
 * The suite is taken from the POPKIT_TEST_SUITE environment variable when it is
 * set, and otherwise from `--testsuite` on the command line. The command-line
 * fallback exists because there is no cross-platform way to export an
 * environment variable from a Composer script on both Windows and POSIX.
 *
 * @package Popkit
 */

declare( strict_types = 1 );

/**
 * Resolve the test suite being run.
 *
 * @return string Lowercased suite name; 'unit' when nothing indicates otherwise.
 */
function popkit_tests_resolve_suite(): string {
	$from_env = getenv( 'POPKIT_TEST_SUITE' );

	if ( is_string( $from_env ) && '' !== $from_env ) {
		return strtolower( trim( $from_env ) );
	}

	// CLI arguments, not request input: this file only ever runs under PHPUnit.
	$argv = array();
	if ( isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ) {
		$argv = $_SERVER['argv']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- CLI argv, compared against a fixed allowlist below.
	}

	$total = count( $argv );

	for ( $i = 0; $i < $total; $i++ ) {
		$argument = (string) $argv[ $i ];

		if ( str_starts_with( $argument, '--testsuite=' ) ) {
			$value = substr( $argument, strlen( '--testsuite=' ) );
		} elseif ( '--testsuite' === $argument && isset( $argv[ $i + 1 ] ) ) {
			$value = (string) $argv[ $i + 1 ];
		} else {
			continue;
		}

		foreach ( explode( ',', strtolower( $value ) ) as $name ) {
			if ( 'integration' === trim( $name ) ) {
				return 'integration';
			}
		}
	}

	return 'unit';
}

$popkit_tests_plugin_dir = dirname( __DIR__, 2 );
$popkit_tests_autoload   = $popkit_tests_plugin_dir . '/vendor/autoload.php';

if ( ! is_readable( $popkit_tests_autoload ) ) {
	fwrite(
		STDERR,
		'popkit: vendor/autoload.php was not found. Run `composer install` before running the tests.' . PHP_EOL
	);
	exit( 1 );
}

require_once $popkit_tests_autoload;

if ( 'integration' === popkit_tests_resolve_suite() ) {
	require __DIR__ . '/bootstrap-integration.php';
	return;
}

/*
 * From here down is the unit environment.
 *
 * Plugin files conventionally open with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.
 * Without ABSPATH defined, requiring any of them silently terminates the test
 * run with a zero exit code, which looks exactly like a passing suite. The
 * value is arbitrary — nothing in the unit suite reads it — but it must exist.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $popkit_tests_plugin_dir . '/' );
}

require_once __DIR__ . '/stubs/wp-stubs.php';

/*
 * Resolve `Popkit\` classes straight out of includes/, using WordPress file
 * naming: Popkit\Rule_Set -> includes/class-rule-set.php, and a sub-namespace
 * becomes a sub-directory (Popkit\Migrations\Version_2 ->
 * includes/migrations/class-version-2.php).
 *
 * Composer's classmap covers the same ground, but only for files that existed
 * when the autoloader was last dumped. This registers behind it so a class
 * added since the last `composer dump-autoload` still loads, and so the unit
 * suite never depends on a build step. Enums, interfaces and traits use their
 * own WordPress file prefixes, so all four are tried.
 */
spl_autoload_register(
	static function ( string $class_name ) use ( $popkit_tests_plugin_dir ): void {
		if ( ! str_starts_with( $class_name, 'Popkit\\' ) ) {
			return;
		}

		$segments = explode( '\\', substr( $class_name, strlen( 'Popkit\\' ) ) );
		$leaf     = array_pop( $segments );

		if ( null === $leaf || '' === $leaf ) {
			return;
		}

		$directory = $popkit_tests_plugin_dir . '/includes/';

		foreach ( $segments as $segment ) {
			$directory .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
		}

		$file_name = strtolower( str_replace( '_', '-', $leaf ) );

		foreach ( array( 'class-', 'enum-', 'interface-', 'trait-', '' ) as $prefix ) {
			$candidate = $directory . $prefix . $file_name . '.php';

			if ( is_readable( $candidate ) ) {
				require_once $candidate;
				return;
			}
		}
	}
);
