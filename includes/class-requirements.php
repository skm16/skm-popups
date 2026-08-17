<?php
/**
 * Environment requirements check.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Reports whether the hosting environment can run popkit.
 *
 * The PHP half of this check is duplicated inline in `popkit.php`, which gates
 * loading this file at all. That duplication is intentional: this class uses
 * PHP 8.1 syntax and cannot be parsed on the versions it reports on. This class
 * is the single source of truth for every *other* consumer — the activation
 * routine, the admin notice, and the test suite.
 *
 * @since 0.1.0
 */
final class Requirements {

	/**
	 * Minimum PHP version, used when POPKIT_MIN_PHP is unavailable.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const FALLBACK_MIN_PHP = '8.1';

	/**
	 * Minimum WordPress version, used when POPKIT_MIN_WP is unavailable.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const FALLBACK_MIN_WP = '6.5';

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Determines whether every requirement is satisfied.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when PHP and WordPress both meet the minimum versions.
	 */
	public static function met(): bool {
		return self::php_met() && self::wp_met();
	}

	/**
	 * Determines whether the running PHP version is supported.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when PHP is at least the minimum version.
	 */
	public static function php_met(): bool {
		return version_compare( PHP_VERSION, self::min_php(), '>=' );
	}

	/**
	 * Determines whether the running WordPress version is supported.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when WordPress is at least the minimum version.
	 */
	public static function wp_met(): bool {
		return version_compare( self::wp_version(), self::min_wp(), '>=' );
	}

	/**
	 * Returns one complete, translated sentence per unmet requirement.
	 *
	 * Sentences are whole strings rather than assembled fragments, so they stay
	 * translatable in isolation.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Empty when every requirement is met.
	 */
	public static function failures(): array {
		$failures = array();

		if ( ! self::php_met() ) {
			$failures[] = sprintf(
				/* translators: 1: minimum required PHP version, 2: PHP version currently in use. */
				__( 'popkit requires PHP %1$s or newer. This site is running PHP %2$s.', 'popkit' ),
				self::min_php(),
				PHP_VERSION
			);
		}

		if ( ! self::wp_met() ) {
			$failures[] = sprintf(
				/* translators: 1: minimum required WordPress version, 2: WordPress version currently in use. */
				__( 'popkit requires WordPress %1$s or newer. This site is running WordPress %2$s.', 'popkit' ),
				self::min_wp(),
				self::wp_version()
			);
		}

		return $failures;
	}

	/**
	 * Prints an admin notice describing every unmet requirement.
	 *
	 * Hooked to `admin_notices` by `popkit.php` when requirements are not met.
	 * Prints nothing when they are.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function notice(): void {
		$failures = self::failures();

		if ( array() === $failures ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'popkit has not been loaded.', 'popkit' ),
			esc_html( implode( ' ', $failures ) )
		);
	}

	/**
	 * Returns the minimum supported PHP version.
	 *
	 * @since 0.1.0
	 *
	 * @return string Version string.
	 */
	public static function min_php(): string {
		return defined( 'POPKIT_MIN_PHP' ) ? (string) POPKIT_MIN_PHP : self::FALLBACK_MIN_PHP;
	}

	/**
	 * Returns the minimum supported WordPress version.
	 *
	 * @since 0.1.0
	 *
	 * @return string Version string.
	 */
	public static function min_wp(): string {
		return defined( 'POPKIT_MIN_WP' ) ? (string) POPKIT_MIN_WP : self::FALLBACK_MIN_WP;
	}

	/**
	 * Returns the running WordPress version.
	 *
	 * Read from `$GLOBALS['wp_version']` rather than `get_bloginfo( 'version' )`
	 * so the value is neither filterable nor dependent on `$wp_locale`, and
	 * without the `global` keyword.
	 *
	 * @since 0.1.0
	 *
	 * @return string Version string, or '0' when it cannot be determined.
	 */
	public static function wp_version(): string {
		return isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '0';
	}
}
