<?php
/**
 * Plugin bootstrap for popkit — accessible, lightweight popups for WordPress.
 *
 * This file is the plugin's entry point and is deliberately written in
 * PHP 7.0-compatible syntax. It must *parse* on unsupported PHP versions so an
 * administrator sees a requirements notice rather than a fatal error. Every
 * modern language feature lives behind the version gate below, in `includes/`.
 *
 * Do not add typed properties, arrow functions, union types, first-class
 * callable syntax, `never`, enums, or any other PHP 7.1+ construct to this file.
 *
 * @package Popkit
 * @author  Sean Roberts
 * @license GPL-2.0-or-later
 * @link    https://github.com/skm16/skm-popups
 *
 * The display name is "PopKit". The text domain, the slug, the option and meta
 * keys, the CSS class prefix and the PHP namespace all stay lowercase `popkit`
 * and must not be renamed to match: WordPress.org slugs are lowercase, Plugin
 * Check requires the text domain to equal the slug, and every one of those names
 * is already written into stored data or into translations. Capitalization here
 * is presentation.
 *
 * @wordpress-plugin
 * Plugin Name:       PopKit
 * Plugin URI:        https://github.com/skm16/skm-popups
 * Description:       Accessible, lightweight popups. Native dialogs, full keyboard and screen reader support, cache-safe targeting, and no jQuery.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Sean Roberts
 * Author URI:        https://SKM.digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       popkit
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'POPKIT_VERSION', '0.2.0' );
define( 'POPKIT_FILE', __FILE__ );
define( 'POPKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'POPKIT_URL', plugin_dir_url( __FILE__ ) );
define( 'POPKIT_BASENAME', plugin_basename( __FILE__ ) );
define( 'POPKIT_MIN_PHP', '8.1' );
define( 'POPKIT_MIN_WP', '6.5' );

if ( ! function_exists( 'popkit_php_requirement_notice' ) ) {
	/**
	 * Renders the admin notice shown when PHP is too old to load the plugin.
	 *
	 * This duplicates part of Popkit\Requirements deliberately: that class uses
	 * PHP 8.1 syntax and therefore cannot be loaded on the very PHP versions
	 * this notice exists to report.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	function popkit_php_requirement_notice() {
		$message = sprintf(
			/* translators: 1: minimum required PHP version, 2: PHP version currently in use. */
			__( 'popkit requires PHP %1$s or newer. This site is running PHP %2$s, so the plugin has not been loaded.', 'popkit' ),
			POPKIT_MIN_PHP,
			PHP_VERSION
		);

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}

/*
 * Hard gate. Nothing below this point may be loaded on an unsupported PHP
 * version, because everything below this point may use PHP 8.1 syntax.
 */
if ( version_compare( PHP_VERSION, POPKIT_MIN_PHP, '<' ) ) {
	add_action( 'admin_notices', 'popkit_php_requirement_notice' );

	return;
}

if ( ! function_exists( 'popkit_autoload' ) ) {
	/**
	 * Fallback autoloader for the Popkit namespace.
	 *
	 * Composer's classmap autoloader is preferred and is loaded first when a
	 * `vendor/` directory is present. This function stays registered as a safety
	 * net so the plugin works from a git checkout with no `composer install`, and
	 * so a stale classmap cannot hide a newly added class.
	 *
	 * Mapping, matching WordPress file naming rather than PSR-4:
	 *
	 *     Popkit\Some_Thing  ->  includes/class-some-thing.php
	 *     Popkit\Sub\Thing   ->  includes/sub/class-thing.php
	 *
	 * Interfaces, traits and enums are resolved from the same path using the
	 * `interface-`, `trait-` and `enum-` prefixes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $class_name Fully qualified class, interface, trait or enum name.
	 * @return void
	 */
	function popkit_autoload( $class_name ) {
		$prefix = 'Popkit\\';

		if ( 0 !== strncmp( $prefix, $class_name, strlen( $prefix ) ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );

		// Defensive: a legitimate class name contains no dots or slashes.
		if ( '' === $relative || false !== strpos( $relative, '.' ) || false !== strpos( $relative, '/' ) ) {
			return;
		}

		$segments = explode( '\\', $relative );
		$leaf     = array_pop( $segments );
		$path     = POPKIT_DIR . 'includes/';

		foreach ( $segments as $segment ) {
			if ( '' === $segment ) {
				return;
			}

			$path .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
		}

		$leaf       = strtolower( str_replace( '_', '-', $leaf ) );
		$candidates = array( 'class-', 'interface-', 'trait-', 'enum-' );

		foreach ( $candidates as $candidate ) {
			$file = $path . $candidate . $leaf . '.php';

			if ( is_readable( $file ) ) {
				require_once $file;

				return;
			}
		}
	}
}

if ( ! function_exists( 'popkit_boot' ) ) {
	/**
	 * Boots the plugin on `plugins_loaded`.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	function popkit_boot() {
		Popkit\Plugin::instance()->boot();
	}
}

if ( file_exists( POPKIT_DIR . 'vendor/autoload.php' ) ) {
	require_once POPKIT_DIR . 'vendor/autoload.php';
}

spl_autoload_register( 'popkit_autoload' );

/*
 * WordPress version gate. Safe to express through the Requirements class: PHP is
 * already known to be supported by the time execution reaches this line.
 */
if ( ! Popkit\Requirements::met() ) {
	add_action( 'admin_notices', array( 'Popkit\Requirements', 'notice' ) );

	return;
}

register_activation_hook( POPKIT_FILE, array( 'Popkit\Activator', 'activate' ) );
register_deactivation_hook( POPKIT_FILE, array( 'Popkit\Activator', 'deactivate' ) );

add_action( 'plugins_loaded', 'popkit_boot' );
