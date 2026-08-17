<?php
/**
 * WordPress integration bootstrap for popkit.
 *
 * Reached from bootstrap-unit.php when the integration suite is selected, and
 * usable directly as a PHPUnit bootstrap. It loads the WordPress PHPUnit test
 * suite and mounts the plugin on `muplugins_loaded`, which fires before the
 * test suite installs and before any of core's own hooks run — so the plugin
 * registers its post type, meta and routes exactly as it would on a real site.
 *
 * The test suite is located from, in order:
 *
 *   1. WP_TESTS_DIR       — a checkout of wordpress-develop/tests/phpunit
 *   2. WP_PHPUNIT__DIR    — set by the wp-phpunit/wp-phpunit Composer package
 *   3. <temp>/wordpress-tests-lib — the install-wp-tests.sh / wp-env default
 *
 * @package Popkit
 */

declare( strict_types = 1 );

/**
 * Locate the main plugin file.
 *
 * The directory name and the plugin slug differ, so the file is looked up
 * rather than assumed. POPKIT_PLUGIN_FILE overrides everything, then the two
 * expected names, then any root-level PHP file carrying a plugin header.
 *
 * @return string Absolute path, or an empty string when nothing matched.
 */
function popkit_tests_locate_plugin_file(): string {
	$plugin_dir = dirname( __DIR__, 2 );

	$from_env = getenv( 'POPKIT_PLUGIN_FILE' );
	if ( is_string( $from_env ) && '' !== $from_env && is_readable( $from_env ) ) {
		return $from_env;
	}

	foreach ( array( 'popkit.php', 'skm-popups.php' ) as $candidate ) {
		if ( is_readable( $plugin_dir . '/' . $candidate ) ) {
			return $plugin_dir . '/' . $candidate;
		}
	}

	$root_files = glob( $plugin_dir . '/*.php' );

	foreach ( (array) $root_files as $candidate ) {
		// Only the header block is read; 8 KB is well past where a plugin header ends.
		$header = file_get_contents( $candidate, false, null, 0, 8192 );

		if ( is_string( $header ) && false !== stripos( $header, 'Plugin Name:' ) ) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Load the plugin inside the WordPress test environment.
 *
 * @return void
 */
function popkit_tests_load_plugin(): void {
	$plugin_file = popkit_tests_locate_plugin_file();

	if ( '' === $plugin_file ) {
		fwrite(
			STDERR,
			'popkit: could not find the main plugin file. Set POPKIT_PLUGIN_FILE to its absolute path.' . PHP_EOL
		);
		exit( 1 );
	}

	require $plugin_file;
}

/**
 * Resolve the directory holding the WordPress PHPUnit test suite.
 *
 * @return string Absolute path with no trailing separator.
 */
function popkit_tests_locate_suite_dir(): string {
	foreach ( array( 'WP_TESTS_DIR', 'WP_PHPUNIT__DIR' ) as $variable ) {
		$value = getenv( $variable );

		if ( is_string( $value ) && '' !== $value ) {
			return rtrim( $value, '/\\' );
		}
	}

	return rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$popkit_tests_plugin_dir = dirname( __DIR__, 2 );
$popkit_tests_autoload   = $popkit_tests_plugin_dir . '/vendor/autoload.php';

if ( is_readable( $popkit_tests_autoload ) ) {
	require_once $popkit_tests_autoload;
}

$popkit_tests_suite_dir = popkit_tests_locate_suite_dir();

if ( ! is_readable( $popkit_tests_suite_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		sprintf(
			'popkit: the WordPress test suite was not found at %s.' . PHP_EOL
			. 'Set WP_TESTS_DIR, or start the environment with `npx wp-env start`, then run the integration suite again.' . PHP_EOL,
			$popkit_tests_suite_dir
		)
	);
	exit( 1 );
}

/*
 * WordPress 5.9+ requires the Yoast PHPUnit Polyfills and refuses to boot
 * without them. The plugin ships them as a dev dependency, so point core at the
 * local copy rather than relying on one being installed alongside the suite.
 */
$popkit_tests_polyfills_dir = $popkit_tests_plugin_dir . '/vendor/yoast/phpunit-polyfills';

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && is_dir( $popkit_tests_polyfills_dir ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $popkit_tests_polyfills_dir );
}

require_once $popkit_tests_suite_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', 'popkit_tests_load_plugin' );

require $popkit_tests_suite_dir . '/includes/bootstrap.php';
