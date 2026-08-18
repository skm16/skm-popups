<?php
/**
 * Registration of the four triggers shipped in v1.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit\Triggers;

use Popkit\Trigger;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the built-in triggers and hands them to the trigger registry.
 *
 * This class holds schema only. A trigger has no server-side behaviour at all —
 * arming, first-fire-wins and teardown are entirely a front-end concern, and the
 * runtime keys off the same string this file registers. What lives here is the
 * declaration that lets the block editor render a working control for each
 * trigger, and that lets `_popkit_triggers` meta be sanitized against a declared
 * type rather than by hand. See docs/CLAUDE.md -> Registry invariants: a field's
 * `type` drives its sanitizer, so nothing in this file sanitizes anything.
 *
 * ## The four triggers
 *
 * | Key | Fields | Fires when |
 * |---|---|---|
 * | `page_load` | `delay_ms` | The delay elapses. Zero means as soon as the popup is eligible. |
 * | `scroll_depth` | `percent` | The visitor has scrolled that far down the document. |
 * | `click_selector` | `selector` | A click lands on an element matching the selector. |
 * | `deep_link` | none | The URL carries `?popkit={slug}` or `#popkit-{slug}`. |
 *
 * `time_on_page`, `element_visible`, `idle` and `exit_intent` are deferred to
 * 1.1 and are deliberately absent — see docs/data-model.md -> Triggers.
 *
 * A deep link is a trigger, not a bypass. It arms alongside every other trigger,
 * after the whole eligibility pipeline has passed, so a link to a popup whose
 * conditions, schedule or frequency cap exclude the visitor opens nothing.
 *
 * ## Why no per-field help text
 *
 * The field-schema vocabulary on {@see \Popkit\Condition} has no `description`
 * key, and a schema declaring one is rejected at registration. The registry
 * route also advertises `additionalProperties => false` over that vocabulary, so
 * an undeclared key would make the route emit a payload failing its own schema.
 * Every explanation an author needs therefore lives in the `label`, which is why
 * the labels below name their units.
 *
 * ## Namespace
 *
 * `Popkit\Triggers` is both the registry class and this file's namespace. That
 * is legal and is what the plugin's autoloaders expect — `Popkit\Sub\Thing`
 * resolves to `includes/sub/class-thing.php` — but it means an unqualified
 * `Triggers` inside this file would resolve to `Popkit\Triggers\Triggers`, which
 * does not exist. The registry is therefore always written fully qualified.
 *
 * @since 0.1.0
 */
final class Builtin_Triggers {

	/**
	 * Longest delay `page_load` accepts, in milliseconds.
	 *
	 * Ten minutes. A popup armed for longer than a plausible visit is a setting
	 * that silently never fires, so the bound exists to make that unrepresentable
	 * rather than to protect anything at runtime.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_DELAY_MS = 600000;

	/**
	 * Shallowest scroll depth `scroll_depth` accepts, as a percentage.
	 *
	 * One rather than zero: a depth of zero is satisfied before the visitor has
	 * done anything, which is `page_load` with no delay wearing another name.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MIN_SCROLL_PERCENT = 1;

	/**
	 * Deepest scroll depth `scroll_depth` accepts, as a percentage.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_SCROLL_PERCENT = 100;

	/**
	 * Longest selector `click_selector` accepts, in bytes.
	 *
	 * Matches {@see \Popkit\Url_Matcher::MAX_VALUE_LENGTH} so that the plugin's
	 * two bounded free-text fields are capped at the same number, measured the
	 * same way. A CSS selector an author types into a sidebar is far shorter than
	 * this; the cap bounds what reaches the database, nothing more.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_SELECTOR_LENGTH = 255;

	/**
	 * Attaches registration to `popkit_register_triggers`.
	 *
	 * Called from `Plugin::boot()` on `plugins_loaded`. Attaching rather than
	 * registering immediately is the whole point: {@see \popkit_triggers()} builds
	 * its registry lazily and dispatches that action exactly once, tied to
	 * construction, so calling the accessor here to register directly would fire
	 * the action before any extension had attached to it and every third-party
	 * registration would be silently dropped.
	 *
	 * The callback is an array callable rather than a first-class callable so the
	 * hook keeps a stable identity: WordPress deduplicates it, a test can assert
	 * it by name, and a site owner can remove it. A first-class callable produces
	 * a new closure on every call and would do none of those things — and a second
	 * attachment would re-run {@see \Popkit\Triggers::register()} on keys that are
	 * already taken, which raises.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'popkit_register_triggers', array( self::class, 'register' ) );
	}

	/**
	 * Registers every built-in trigger.
	 *
	 * Registration order is preserved by the registry and is what the editor
	 * presents, so the order in {@see Builtin_Triggers::all()} is the order an
	 * author sees.
	 *
	 * @since 0.1.0
	 *
	 * @param \Popkit\Triggers $registry Registry to add triggers to.
	 * @return void
	 */
	public static function register( \Popkit\Triggers $registry ): void {
		foreach ( self::all() as $trigger ) {
			$registry->register( $trigger );
		}
	}

	/**
	 * Builds every built-in trigger, in the order the editor lists them.
	 *
	 * Returned as a list rather than registered inline so that a test can inspect
	 * the declarations without a registry, and so the ordering decision is visible
	 * in one place.
	 *
	 * @since 0.1.0
	 *
	 * @return Trigger[] Built-in triggers.
	 */
	public static function all(): array {
		return array(
			self::page_load(),
			self::scroll_depth(),
			self::click_selector(),
			self::deep_link(),
		);
	}

	/**
	 * Opens the popup once a delay has elapsed.
	 *
	 * @since 0.1.0
	 *
	 * @return Trigger
	 */
	private static function page_load(): Trigger {
		return new Trigger(
			'page_load',
			__( 'Page load', 'popkit' ),
			array(
				'delay_ms' => array(
					'type'    => 'integer',
					'default' => 0,
					'label'   => __( 'Delay before opening, in milliseconds (0 opens immediately)', 'popkit' ),
					'control' => 'number',
					'min'     => 0,
					'max'     => self::MAX_DELAY_MS,
				),
			)
		);
	}

	/**
	 * Opens the popup once the visitor has scrolled far enough down the page.
	 *
	 * @since 0.1.0
	 *
	 * @return Trigger
	 */
	private static function scroll_depth(): Trigger {
		return new Trigger(
			'scroll_depth',
			__( 'Scroll depth', 'popkit' ),
			array(
				'percent' => array(
					'type'    => 'integer',
					'default' => 50,
					'label'   => __( 'Percent of the page scrolled', 'popkit' ),
					'control' => 'range',
					'min'     => self::MIN_SCROLL_PERCENT,
					'max'     => self::MAX_SCROLL_PERCENT,
				),
			)
		);
	}

	/**
	 * Opens the popup when a click lands on a matching element.
	 *
	 * @since 0.1.0
	 *
	 * @return Trigger
	 */
	private static function click_selector(): Trigger {
		return new Trigger(
			'click_selector',
			__( 'Element click', 'popkit' ),
			array(
				'selector' => array(
					'type'       => 'string',
					'default'    => '',
					'label'      => __( 'CSS selector of the element to watch for clicks', 'popkit' ),
					'control'    => 'text',
					'max_length' => self::MAX_SELECTOR_LENGTH,
				),
			)
		);
	}

	/**
	 * Opens the popup when the URL names it.
	 *
	 * Takes no configuration: the popup's own slug is the link target, so there
	 * is nothing for an author to fill in. The runtime matches `?popkit={slug}`
	 * and `#popkit-{slug}` against the slug already carried in the emitted config.
	 *
	 * @since 0.1.0
	 *
	 * @return Trigger
	 */
	private static function deep_link(): Trigger {
		return new Trigger(
			'deep_link',
			__( 'Deep link', 'popkit' ),
			array()
		);
	}
}
