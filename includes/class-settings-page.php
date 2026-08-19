<?php
/**
 * The one settings screen popkit has.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Renders **Popups → Settings**, which arms the destructive uninstall opt-in.
 *
 * ## Why this exists
 *
 * {@see Settings} registered the option, gave it a REST representation, wrote
 * the label and the description, and named a settings group "for
 * `register_setting()` and `settings_fields()`". `settings_fields()` is a form
 * function and had no caller anywhere in the plugin. The setting was reachable
 * only over REST, through WP-CLI, or by editing an option row by hand — while
 * `readme.txt` told site owners to "enable the setting" as though a screen
 * existed. It did not.
 *
 * That gap mattered more than an ordinary missing screen would, because the
 * missing surface was the *keep your content* decision. A site owner who wanted
 * a clean uninstall had no supported way to ask for one, and the plugin's own
 * documentation described a control nobody could find.
 *
 * ## Under Popups, not under Settings
 *
 * `add_submenu_page()` hangs this off the popup post type's menu. Somebody
 * deciding whether removing popkit should take their popups with it is already
 * looking at their popups, and the top-level Settings menu is where a site's
 * plugins go to be lost.
 *
 * ## `manage_options`, not a popkit capability
 *
 * The checkbox authorizes permanently destroying every popup on the site. An
 * editor who can write popups holds `edit_popkit_popups` and must not be able to
 * arm that, so the gate is deliberately *not* the capability that governs the
 * content it would delete. It is the capability that governs the site.
 *
 * `options.php` reaches the same conclusion independently: it derives the save
 * capability from `option_page_capability_{$group}`, which defaults to
 * `manage_options`. Read gate and write gate therefore agree without this class
 * having to configure anything.
 *
 * ## What is deliberately not here
 *
 * No settings for layout, theme, triggers or targeting. Those are per popup and
 * live in the block editor sidebar, where the popup they belong to is. This
 * screen holds the one decision that is about the site rather than about a
 * popup, and it should stay that way — a settings screen that accumulates
 * per-popup defaults is how two sources of truth start.
 *
 * @since 0.1.0
 */
final class Settings_Page {

	/**
	 * Screen slug, as `add_submenu_page()` and `$_GET['page']` know it.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const PAGE_SLUG = 'popkit-settings';

	/**
	 * Capability required to see or save this screen.
	 *
	 * See the class docblock: this authorizes irreversible, site-wide content
	 * deletion, so it is not the capability that governs popups.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Field id, shared by the checkbox and the `for` of its label.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const FIELD_ID = 'popkit-delete-data';

	/**
	 * Id of the element `aria-describedby` points at.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const DESCRIPTION_ID = 'popkit-delete-data-description';

	/**
	 * Not instantiable. Every member is static.
	 */
	private function __construct() {}

	/**
	 * Hooks the screen onto `admin_menu`.
	 *
	 * The callback is an array callable rather than a first-class callable, for
	 * the reason given on {@see Rest_Schema::init()}: a stable identity, so
	 * WordPress deduplicates it, a test can assert it by name, and a site owner
	 * can remove it.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_page' ) );
	}

	/**
	 * Registers the submenu entry.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_submenu_page(
			'edit.php?post_type=' . Post_Type::POST_TYPE,
			__( 'PopKit settings', 'popkit' ),
			__( 'Settings', 'popkit' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Renders the screen.
	 *
	 * The capability is re-checked here even though `add_submenu_page()` already
	 * gated the menu entry. This is a public callback on a public class, and
	 * everything else in this plugin fails closed rather than trusting that it
	 * was reached the way it was meant to be.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to change PopKit settings.', 'popkit' ),
				'',
				array( 'response' => 403 )
			);
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'PopKit settings', 'popkit' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( Settings::OPTION_GROUP ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Uninstalling', 'popkit' ); ?></th>
							<td>
								<?php
								/*
								 * The hidden companion is what makes unchecking mean
								 * "off". An unchecked box posts nothing at all, so
								 * without this the submitted array would simply lack
								 * the key — and a missing key must never be able to
								 * read as a value for a setting that authorizes
								 * deleting a site's content.
								 */
								?>
								<input
									type="hidden"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[<?php echo esc_attr( Settings::DELETE_DATA_ON_UNINSTALL ); ?>]"
									value="0"
								/>
								<label for="<?php echo esc_attr( self::FIELD_ID ); ?>">
									<input
										type="checkbox"
										id="<?php echo esc_attr( self::FIELD_ID ); ?>"
										name="<?php echo esc_attr( Settings::OPTION ); ?>[<?php echo esc_attr( Settings::DELETE_DATA_ON_UNINSTALL ); ?>]"
										value="1"
										aria-describedby="<?php echo esc_attr( self::DESCRIPTION_ID ); ?>"
										<?php checked( Settings::delete_data_on_uninstall() ); ?>
									/>
									<?php echo esc_html( Settings::delete_data_label() ); ?>
								</label>
								<p class="description" id="<?php echo esc_attr( self::DESCRIPTION_ID ); ?>">
									<?php echo esc_html( Settings::delete_data_description() ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
