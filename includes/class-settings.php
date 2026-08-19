<?php
/**
 * Plugin settings.
 *
 * @package Popkit
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Registers, reads and sanitizes the plugin's settings.
 *
 * Every setting lives in one option — an associative array stored under
 * self::OPTION. A single option keeps the settings shape authoritative in one
 * place, keeps the REST surface to one key, and means uninstall has one row to
 * find rather than an open-ended family of prefixed options.
 *
 * The only setting in v1 is the uninstall opt-in described in
 * docs/CLAUDE.md -> Uninstall. It defaults to false, and nothing in this class
 * may change that default: deleting authored content because a plugin was
 * removed during routine maintenance is not acceptable behavior.
 */
final class Settings {

	/**
	 * Option name holding every plugin setting.
	 *
	 * @var string
	 */
	public const OPTION = 'popkit_settings';

	/**
	 * Settings group, for register_setting() and settings_fields().
	 *
	 * @var string
	 */
	public const OPTION_GROUP = 'popkit';

	/**
	 * Settings key: opt in to destroying authored content on uninstall.
	 *
	 * @var string
	 */
	public const DELETE_DATA_ON_UNINSTALL = 'delete_data_on_uninstall';

	/**
	 * Standalone option name documented in docs/CLAUDE.md -> Uninstall.
	 *
	 * Canonical storage is self::OPTION. This standalone option is honored only
	 * while self::OPTION has never been written, so a site owner or a WP-CLI
	 * script that set the documented name directly still gets the documented
	 * behavior. Once self::OPTION exists it is the sole source of truth, so the
	 * admin UI and uninstall can never disagree about whether content is
	 * scheduled for deletion.
	 *
	 * @var string
	 */
	public const DELETE_DATA_OPTION_ALIAS = 'popkit_delete_data_on_uninstall';

	/**
	 * Sentinel distinguishing "option absent" from "option stored as an array".
	 *
	 * Calling get_option( self::OPTION ) alone cannot tell the two apart once
	 * register_setting() has installed a default_option_ filter returning the
	 * defaults array. Passing an explicit sentinel makes core return the sentinel
	 * for a missing option, so "never written" is detectable — and it stays
	 * distinguishable from a corrupt non-array value too.
	 *
	 * @var string
	 */
	private const UNSET_SENTINEL = '__popkit_settings_unset__';

	/**
	 * Hooks setting registration.
	 *
	 * Registration runs on `init` so the setting is present for both the admin
	 * screens and the `/wp/v2/settings` REST route, which is built during
	 * `rest_api_init`. If `init` has already fired by the time the plugin calls
	 * this, registration happens immediately instead of never.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( did_action( 'init' ) ) {
			self::register();

			return;
		}

		add_action( 'init', self::register( ... ) );
	}

	/**
	 * Registers the settings option with WordPress.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION,
			array(
				'type'              => 'object',
				'description'       => __( 'PopKit plugin settings.', 'popkit' ),
				'default'           => self::defaults(),
				'sanitize_callback' => self::sanitize( ... ),
				'show_in_rest'      => array(
					'name'   => 'popkit',
					'schema' => array(
						'type'                 => 'object',
						'properties'           => array(
							self::DELETE_DATA_ON_UNINSTALL => array(
								'type'        => 'boolean',
								'default'     => false,
								'title'       => self::delete_data_label(),
								'description' => self::delete_data_description(),
							),
						),
						'additionalProperties' => false,
					),
				),
			)
		);
	}

	/**
	 * Default value for every known setting.
	 *
	 * @return array<string, mixed> Setting key => default value.
	 */
	public static function defaults(): array {
		return array(
			self::DELETE_DATA_ON_UNINSTALL => false,
		);
	}

	/**
	 * Reads every setting, with defaults applied and types normalized.
	 *
	 * Unknown keys found in storage are not returned: self::defaults() defines
	 * the shape.
	 *
	 * @return array<string, mixed> Setting key => current value.
	 */
	public static function all(): array {
		$settings = self::defaults();
		$stored   = get_option( self::OPTION, self::UNSET_SENTINEL );

		if ( is_array( $stored ) ) {
			foreach ( array_keys( $settings ) as $key ) {
				if ( array_key_exists( $key, $stored ) ) {
					$settings[ $key ] = $stored[ $key ];
				}
			}
		} elseif ( self::UNSET_SENTINEL === $stored ) {
			// The settings option has never been written. See the docblock on
			// self::DELETE_DATA_OPTION_ALIAS for why the standalone option is
			// consulted here and only here.
			$settings[ self::DELETE_DATA_ON_UNINSTALL ] = get_option( self::DELETE_DATA_OPTION_ALIAS, false );
		}

		$settings[ self::DELETE_DATA_ON_UNINSTALL ] = self::to_bool( $settings[ self::DELETE_DATA_ON_UNINSTALL ] );

		return $settings;
	}

	/**
	 * Reads one setting.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Returned when the key is not a known setting.
	 * @return mixed Setting value, or $default_value for an unknown key.
	 */
	public static function get( string $key, $default_value = null ) {
		$settings = self::all();

		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}

		return $default_value;
	}

	/**
	 * Writes settings, merging over the current values.
	 *
	 * @param array<string, mixed> $settings Partial or complete settings array.
	 * @return bool Whether the option was updated.
	 */
	public static function update( array $settings ): bool {
		return update_option( self::OPTION, self::sanitize( array_merge( self::all(), $settings ) ) );
	}

	/**
	 * Whether the site owner opted in to destroying authored content on uninstall.
	 *
	 * The uninstall.php routine re-implements this rule rather than calling it,
	 * because the plugin is not loaded during uninstall. The two must stay in
	 * step.
	 *
	 * @return bool
	 */
	public static function delete_data_on_uninstall(): bool {
		return (bool) self::get( self::DELETE_DATA_ON_UNINSTALL, false );
	}

	/**
	 * Sanitizes the settings array against the known shape.
	 *
	 * @param mixed $value Raw value from the options API or the REST route.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public static function sanitize( $value ): array {
		$settings = self::defaults();

		if ( ! is_array( $value ) ) {
			return $settings;
		}

		if ( array_key_exists( self::DELETE_DATA_ON_UNINSTALL, $value ) ) {
			$settings[ self::DELETE_DATA_ON_UNINSTALL ] = self::to_bool( $value[ self::DELETE_DATA_ON_UNINSTALL ] );
		}

		return $settings;
	}

	/**
	 * Short label for the uninstall opt-in.
	 *
	 * @return string
	 */
	public static function delete_data_label(): string {
		return __( 'Delete all PopKit data when the plugin is deleted', 'popkit' );
	}

	/**
	 * Description of the uninstall opt-in, for the admin UI and the REST schema.
	 *
	 * @return string
	 */
	public static function delete_data_description(): string {
		return __( 'When this is enabled, deleting the popkit plugin permanently deletes every popup you have created, along with their content, targeting rules and settings. This cannot be undone and there is no backup. Leave it off to keep your popups if the plugin is ever removed.', 'popkit' );
	}

	/**
	 * Coerces a stored or submitted value to a boolean.
	 *
	 * Coercion runs through filter_var() rather than rest_sanitize_boolean()
	 * because filter_var() treats anything it does not recognize as false. For a
	 * setting that authorizes irreversible deletion, an unrecognized value must
	 * not mean "yes".
	 *
	 * @param mixed $value Value to coerce.
	 * @return bool
	 */
	private static function to_bool( $value ): bool {
		return (bool) filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}
}
