<?php
/**
 * Meta keys stored on a popup.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the six `_popkit_*` meta keys, with their REST schemas and sanitizers.
 *
 * Everything a popup knows beyond its title and content lives here: who it may
 * show to, what opens it, when it may run, how often, and what it looks like.
 * The shapes are `docs/data-model.md` -> Meta keys, and this class is the only
 * place they are expressed in PHP.
 *
 * ## Two layers, two jobs
 *
 * Each key carries both a REST schema and a `sanitize_callback`, and they are not
 * redundant:
 *
 * - The **schema** is the gate. It documents the shape for the editor, and a REST
 *   write that violates it fails with a message an author can see. That is the
 *   only layer able to *refuse* a payload rather than quietly correct it.
 * - The **sanitizer** is the floor. It runs on every write including a direct
 *   `update_post_meta()` call from other code, where there is nobody to report an
 *   error to, so it always returns a storable value.
 *
 * `_popkit_conditions` carries a third piece, because JSON Schema cannot express
 * the structural limits in `docs/data-model.md` -> Bounds on unknown rule values:
 * {@see Meta::validate_rest_write()} runs {@see Sanitizer::validate_rule_set()}
 * on the submitted rule set and refuses the whole request when it is out of
 * bounds. Without it a REST write would return success while the sanitizer
 * silently swapped the offending rule for the fail-closed marker, and the author
 * would be told nothing.
 *
 * The sanitizer is therefore authoritative for what lands in the database, and
 * the schemas deliberately do **not** set `additionalProperties => false`. Under
 * that keyword an unrecognized key fails the whole request; without it the key
 * passes validation untouched and the sanitizer discards it. Discarding is what
 * `docs/data-model.md` -> Display specifies for a stored `close_button`, and it
 * is the kinder behavior for every other stray key too.
 *
 * The one place values are passed through unexamined is a rule's `values` for an
 * unregistered condition type, which must survive a deactivate/reactivate cycle
 * byte-identically. {@see Sanitizer} and {@see Rule_Bounds} own that contract;
 * the schema here declares `additionalProperties => true` so REST does not coerce
 * or strip anything on the way past.
 *
 * ## Which way sanitization fails
 *
 * Where a submitted value cannot be honored, the replacement is chosen so it
 * cannot widen who sees the popup:
 *
 * - A trigger that cannot be sanitized is dropped. Fewer triggers means fewer
 *   ways to open, never more.
 * - A recurrence window that cannot be sanitized becomes `00:00`–`00:00`. An
 *   empty `windows` list means "any time of day", so dropping the only window
 *   would turn a lunchtime campaign into an all-day one; a zero-length window
 *   never matches, and the editor already warns about one.
 * - A weekday outside 1–7 is dropped rather than clamped. Clamping `0` to `1`
 *   would add Monday to a schedule nobody asked to run on Monday.
 * - `close_on_overlay_click` is forced off when `overlay` is off, because the
 *   dismissal path it describes does not exist without an overlay.
 *
 * There is no `close_button` field, in the schema or in storage. Every popup
 * renders a visible close button in every layout and theme; see
 * `docs/CLAUDE.md` -> Accessibility.
 *
 * @since 0.1.0
 */
final class Meta {

	/**
	 * Meta key holding the targeting rule set.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const CONDITIONS = '_popkit_conditions';

	/**
	 * Meta key holding the list of trigger configurations.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const TRIGGERS = '_popkit_triggers';

	/**
	 * Meta key holding the campaign schedule.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const SCHEDULE = '_popkit_schedule';

	/**
	 * Meta key holding the frequency cap.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const FREQUENCY = '_popkit_frequency';

	/**
	 * Meta key holding the display configuration.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const DISPLAY = '_popkit_display';

	/**
	 * Meta key holding the stored schema version.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const SCHEMA_VERSION_KEY = '_popkit_schema_version';

	/**
	 * Schema version this release of popkit writes and reads.
	 *
	 * Raising it requires a migration in `includes/migrations/` in the same
	 * commit. See `docs/data-model.md` -> Migrations.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Permitted values for `schedule.timezone`.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const SCHEDULE_TIMEZONES = array( 'site', 'visitor' );

	/**
	 * Permitted values for `frequency.mode`.
	 *
	 * `until_dismissed` and `until_converted` were removed from the data model
	 * before v1 shipped and must not come back: the first named three different
	 * behaviors at once, and the second is `once_ever` with extra steps.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const FREQUENCY_MODES = array( 'always', 'once_per_session', 'once_per_days', 'once_ever' );

	/**
	 * Permitted values for `frequency.on_convert`.
	 *
	 * This replaced `reset_on_convert`, whose name described the inverse of the
	 * useful behavior — it would have made a visitor who just converted eligible
	 * again immediately.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const FREQUENCY_ON_CONVERT = array( 'suppress_forever', 'none' );

	/**
	 * Permitted values for `display.layout`.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const DISPLAY_LAYOUTS = array( 'modal', 'banner' );

	/**
	 * Permitted values for `display.theme`.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const DISPLAY_THEMES = array( 'inherit', 'light', 'dark', 'bordered' );

	/**
	 * Permitted values for `display.size`.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const DISPLAY_SIZES = array( 'small', 'medium', 'large', 'full' );

	/**
	 * Permitted values for `display.position`, per layout.
	 *
	 * A banner has no center and a modal has no bottom, so the permitted values
	 * differ by layout. The first entry of each list is that layout's default and
	 * the value a position from the other layout falls back to.
	 *
	 * @since 0.1.0
	 * @var array<string, string[]>
	 */
	public const DISPLAY_POSITIONS = array(
		'modal'  => array( 'center', 'top' ),
		'banner' => array( 'top', 'bottom', 'lower_third' ),
	);

	/**
	 * Permitted values for `display.animation`.
	 *
	 * All three become `none` under `prefers-reduced-motion`, which is a runtime
	 * decision and never a stored one.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const DISPLAY_ANIMATIONS = array( 'none', 'fade', 'slide' );

	/**
	 * Color fields an author may override on a per-popup basis.
	 *
	 * Each holds a hex color or an empty string, and empty means "whatever the
	 * chosen theme says". That is the only representation of *unset* here, which
	 * is why these are strings rather than a nested object with its own presence
	 * flag: an empty string cannot be confused with black, and the sanitizer can
	 * reject anything that is neither.
	 *
	 * They are validated with `sanitize_hex_color()` and nothing else. These
	 * values are printed into a `style` attribute, so accepting the full CSS
	 * color grammar would mean accepting a string that can close a declaration
	 * and open another one. A hex triplet cannot.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const DISPLAY_COLOR_FIELDS = array(
		'custom_background',
		'custom_text',
		'custom_accent',
		'custom_border_color',
	);

	/**
	 * Permitted values for the non-color appearance overrides.
	 *
	 * Scales rather than numbers, and that is deliberate in two ways.
	 *
	 * A scale cannot carry a CSS injection: `thick` is a token this plugin
	 * defines, where `4px` is a string that has to be parsed and trusted. And a
	 * scale keeps the design values in the stylesheet where the rest of the
	 * design lives — PHP stores which step was chosen, CSS decides what the step
	 * means, so a future change to what `large` looks like does not have to
	 * rewrite anybody's stored popups.
	 *
	 * `inherit` is first in each list and is the default: an author who has not
	 * touched these gets exactly the shipped theme, unchanged.
	 *
	 * @since 0.1.0
	 * @var array<string, string[]>
	 */
	public const DISPLAY_SCALE_FIELDS = array(
		'custom_border_width' => array( 'inherit', 'none', 'thin', 'medium', 'thick' ),
		'custom_radius'       => array( 'inherit', 'none', 'small', 'medium', 'large' ),
		'custom_font'         => array( 'inherit', 'system', 'serif', 'sans', 'mono' ),
		'custom_font_size'    => array( 'inherit', 'small', 'medium', 'large' ),
	);

	/**
	 * Smallest permitted value of `frequency.days`.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MIN_FREQUENCY_DAYS = 1;

	/**
	 * Largest permitted value of `frequency.days`.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_FREQUENCY_DAYS = 365;

	/**
	 * ISO 8601 weekday number for Monday.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MIN_WEEKDAY = 1;

	/**
	 * ISO 8601 weekday number for Sunday.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_WEEKDAY = 7;

	/**
	 * Length in characters of a recurrence window time, `HH:MM`.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const TIME_LENGTH = 5;

	/**
	 * Longest accepted `schedule.start` or `schedule.end` string.
	 *
	 * Comfortably clears the longest form popkit accepts,
	 * `2026-11-30T00:00:00.000000+00:00`, and bounds the work a hostile value can
	 * ask the date parser to do.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_DATETIME_LENGTH = 40;

	/**
	 * Lowest schema version popkit will ever store.
	 *
	 * Deliberately separate from self::SCHEMA_VERSION and deliberately permanent.
	 * It is the registered default, so a popup that never wrote the key reads as
	 * the *oldest* version and runs the whole migration chain. Using the current
	 * version as the default instead would make every such popup claim to be
	 * already migrated the moment self::SCHEMA_VERSION is raised, and the
	 * migration would be skipped in silence.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private const MIN_SCHEMA_VERSION = 1;

	/**
	 * Priority at which meta registration runs on `init`.
	 *
	 * One later than the post type, so the subtype the meta is registered against
	 * exists first regardless of the order in which the two `init()` calls were
	 * made.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private const REGISTRATION_PRIORITY = 11;

	/**
	 * Priority at which the targeting gate runs on `rest_pre_insert_popkit_popup`.
	 *
	 * The default, deliberately. The gate reads the submitted rule set and refuses
	 * the request; it has no reason to run before or after anything else on that
	 * filter, and claiming an unusual priority would only make it harder for a
	 * site to order its own callbacks around.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private const GATE_PRIORITY = 10;

	/**
	 * Number of arguments the targeting gate accepts from its filter.
	 *
	 * The prepared post and the request. The request is the one that matters: it
	 * carries the rule set as the editor submitted it.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private const GATE_ARGS = 2;

	/**
	 * Accepted date formats for `schedule.start` and `schedule.end`.
	 *
	 * Tried in order and parsed with `DateTimeImmutable::createFromFormat()`,
	 * which is strict about trailing characters and about impossible dates. This
	 * is not matched with a regular expression: `docs/CLAUDE.md` -> Security bans
	 * PCRE on stored values, and a repository-wide grep for it should find
	 * nothing at all.
	 *
	 * The leading `!` resets every unspecified field, so parsing is a pure
	 * function of the string rather than of the current time. The last format
	 * carries no zone designator and is read as UTC, matching the "ISO 8601 UTC"
	 * contract in `docs/data-model.md` -> Schedule.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	private const DATETIME_FORMATS = array(
		'!Y-m-d\TH:i:s\Z',
		'!Y-m-d\TH:i:sP',
		'!Y-m-d\TH:i:sO',
		'!Y-m-d\TH:i:s.u\Z',
		'!Y-m-d\TH:i:s.uP',
		'!Y-m-d\TH:i:s',
	);

	/**
	 * Canonical stored form of a date, always UTC.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const DATETIME_FORMAT = 'Y-m-d\TH:i:s\Z';

	/**
	 * Time used for both ends of a window that could not be sanitized.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const MIDNIGHT = '00:00';

	/**
	 * Highest permitted hour in a recurrence window.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private const MAX_HOUR = 23;

	/**
	 * Highest permitted minute in a recurrence window.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private const MAX_MINUTE = 59;

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Hooks meta registration onto `init`, and the targeting gate onto REST writes.
	 *
	 * Registration runs after {@see Post_Type::init()} in every ordering: on the
	 * deferred path by priority, and on the immediate path because `init` has
	 * already fired for both. Meta is registered against the post type as its
	 * object subtype, so registering first would attach the keys to a subtype that
	 * does not exist yet.
	 *
	 * The REST gate needs no such ordering — the filter it hangs on does not fire
	 * until a request is dispatched — so it is added here rather than inside
	 * {@see Meta::register()}, which other code calls directly to re-register the
	 * keys.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter(
			'rest_pre_insert_' . Post_Type::POST_TYPE,
			self::validate_rest_write( ... ),
			self::GATE_PRIORITY,
			self::GATE_ARGS
		);

		if ( did_action( 'init' ) ) {
			self::register();

			return;
		}

		add_action( 'init', self::register( ... ), self::REGISTRATION_PRIORITY );
	}

	/**
	 * Registers every popup meta key with WordPress.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::definitions() as $meta_key => $args ) {
			register_post_meta( Post_Type::POST_TYPE, $meta_key, $args );
		}
	}

	/**
	 * Every meta key this plugin registers on a popup.
	 *
	 * Derived from self::definitions() rather than restated, so the two cannot
	 * disagree about what a popup stores.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Meta keys.
	 */
	public static function keys(): array {
		return array_keys( self::definitions() );
	}

	/**
	 * Registration arguments for every popup meta key.
	 *
	 * Each key declares `single => true`, an explicit REST schema, a default that
	 * validates against that schema, a sanitizer, and an auth callback.
	 *
	 * The explicit schema is not optional: every key here is protected — it starts
	 * with an underscore — and `register_meta()` refuses to expose an `object` or
	 * `array` type over REST without one. `show_in_rest => true` would register a
	 * key the editor cannot read, and no error would say so.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>> Meta key => registration arguments.
	 */
	public static function definitions(): array {
		return array(
			self::CONDITIONS         => array(
				'type'              => 'object',
				'description'       => __( 'Targeting rules deciding which pages and visitors this popup may show on.', 'popkit' ),
				'single'            => true,
				'default'           => self::default_conditions(),

				/*
				 * Delegated whole. Rule sanitization has to preserve unregistered
				 * rule types semantically, refuse over-bounds payloads without
				 * truncating them, and replace anything it cannot keep with a
				 * marker that fails closed. That is Sanitizer's job in full;
				 * re-deriving any part of it here would be a second opinion on a
				 * targeting decision.
				 *
				 * This callback is the floor and cannot refuse a write. The
				 * refusal itself lives on `rest_pre_insert_popkit_popup` — see
				 * Meta::validate_rest_write().
				 */
				'sanitize_callback' => Sanitizer::sanitize_rule_set( ... ),
				'auth_callback'     => self::can_edit( ... ),
				'show_in_rest'      => array( 'schema' => self::conditions_schema() ),
			),
			self::TRIGGERS           => array(
				'type'              => 'array',
				'description'       => __( 'Events that open this popup once it is eligible to show.', 'popkit' ),
				'single'            => true,
				'default'           => self::default_triggers(),
				'sanitize_callback' => self::sanitize_triggers( ... ),
				'auth_callback'     => self::can_edit( ... ),
				'show_in_rest'      => array( 'schema' => self::triggers_schema() ),
			),
			self::SCHEDULE           => array(
				'type'              => 'object',
				'description'       => __( 'Dates, days and times of day this popup may run.', 'popkit' ),
				'single'            => true,
				'default'           => self::default_schedule(),
				'sanitize_callback' => self::sanitize_schedule( ... ),
				'auth_callback'     => self::can_edit( ... ),
				'show_in_rest'      => array( 'schema' => self::schedule_schema() ),
			),
			self::FREQUENCY          => array(
				'type'              => 'object',
				'description'       => __( 'How often one visitor may see this popup.', 'popkit' ),
				'single'            => true,
				'default'           => self::default_frequency(),
				'sanitize_callback' => self::sanitize_frequency( ... ),
				'auth_callback'     => self::can_edit( ... ),
				'show_in_rest'      => array( 'schema' => self::frequency_schema() ),
			),
			self::DISPLAY            => array(
				'type'              => 'object',
				'description'       => __( 'Layout, theme and animation used when this popup opens.', 'popkit' ),
				'single'            => true,
				'default'           => self::default_display(),
				'sanitize_callback' => self::sanitize_display( ... ),
				'auth_callback'     => self::can_edit( ... ),
				'show_in_rest'      => array( 'schema' => self::display_schema() ),
			),
			self::SCHEMA_VERSION_KEY => array(
				'type'              => 'integer',
				'description'       => __( 'Version of the stored popup data shape, used to run migrations.', 'popkit' ),
				'single'            => true,
				'default'           => self::MIN_SCHEMA_VERSION,
				'sanitize_callback' => self::sanitize_schema_version( ... ),
				'auth_callback'     => self::can_edit( ... ),
				'show_in_rest'      => array( 'schema' => self::schema_version_schema() ),
			),
		);
	}

	/**
	 * Refuses a REST write whose targeting rules are outside the stored bounds.
	 *
	 * `docs/data-model.md` -> Bounds on unknown rule values requires an
	 * over-bounds rule to be "rejected at save time with an error surfaced in the
	 * editor". {@see Sanitizer::validate_rule_set()} produces that error; this is
	 * what puts it in front of a write.
	 *
	 * ## Why this hook
	 *
	 * Three mechanisms were read in core before this one was chosen, and the other
	 * two cannot do the job:
	 *
	 * - **The `sanitize_callback` cannot refuse anything.** `sanitize_meta()`
	 *   returns whatever the callback hands back and `update_metadata()` stores
	 *   it. A WP_Error returned from there is not an abort; it is a value, and it
	 *   would be serialized into the row.
	 * - **`update_post_metadata` aborts, but too late to explain itself.**
	 *   Returning non-null does genuinely short-circuit `update_metadata()` before
	 *   it touches the database. But `wp-includes/meta.php` fires that filter
	 *   *after* `sanitize_meta()`, so the value it receives has already been
	 *   normalized: the offending rule is by then the fail-closed marker and the
	 *   limit that was hit is gone. It also carries no message —
	 *   `WP_REST_Meta_Fields::update_meta_value()` turns a `false` into
	 *   `rest_meta_database_error`, an HTTP 500 saying only that the database
	 *   could not be updated. `add_post_metadata` has the same ordering.
	 * - **The meta field has no `validate_callback` of its own.**
	 *   `WP_REST_Meta_Fields::update_value()` validates with
	 *   `rest_validate_value_from_schema()`, which reads JSON Schema keywords and
	 *   never calls a callback out of the registered schema. The schema keywords
	 *   it does read — `maxItems` on groups and rules — are already declared in
	 *   {@see Meta::conditions_schema()}; what they cannot express is the depth,
	 *   item, string and byte-size limits {@see Rule_Bounds} enforces.
	 *
	 * `rest_pre_insert_{$post_type}` is the one that works.
	 * `WP_REST_Posts_Controller::prepare_item_for_database()` returns this
	 * filter's value straight to `create_item()` and `update_item()`, and both
	 * return early when it is a WP_Error — before `wp_insert_post()`, and before
	 * `WP_REST_Meta_Fields::update_value()` runs at all. Nothing is written, and
	 * the author is shown the refusal, which names the limit that caused it.
	 *
	 * The rule set read here is the submitted one. Core registers the `meta`
	 * argument with `arg_options => array( 'sanitize_callback' => null )`, so it
	 * reaches this filter unsanitized — which is the point, since validating the
	 * value the sanitizer already repaired would always pass.
	 *
	 * ## What is deliberately not gated
	 *
	 * A direct `update_post_meta()` call never passes through here, and is not
	 * refused. {@see Sanitizer::sanitize_rule_set()} answers it by storing a rule
	 * set that cannot match anybody, which is the safer of the two outcomes:
	 * aborting the write would leave the popup's previous — possibly wider —
	 * targeting in place while the caller believed it had saved.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $prepared Post prepared for the database, or a WP_Error
	 *                                  a filter before this one returned.
	 * @param WP_REST_Request $request  Request being served.
	 * @return mixed The prepared post, or a WP_Error naming the limit that refused
	 *               the write.
	 */
	public static function validate_rest_write( mixed $prepared, WP_REST_Request $request ): mixed {
		if ( $prepared instanceof WP_Error ) {
			return $prepared;
		}

		$meta = $request['meta'];

		// A request that does not submit the key is not asking to change it.
		if ( ! is_array( $meta ) || ! array_key_exists( self::CONDITIONS, $meta ) ) {
			return $prepared;
		}

		$valid = Sanitizer::validate_rule_set( $meta[ self::CONDITIONS ] );

		return $valid instanceof WP_Error ? $valid : $prepared;
	}

	/**
	 * Authorizes reading and writing popup meta.
	 *
	 * Registered as the `auth_post_meta_{$key}_for_popkit_popup` filter. Every key
	 * here is protected, so without this callback `register_meta()` falls back to
	 * `__return_false` and no write ever succeeds — including from the block
	 * editor.
	 *
	 * The check is `edit_post` on the popup itself, which `map_meta_cap()`
	 * resolves per object against the capability map in {@see Capabilities}. That
	 * is the whole point of `map_meta_cap => true` on the post type: authorship
	 * and post status are taken into account, so an editor who may not touch
	 * another author's published popup may not rewrite its targeting either.
	 *
	 * `$user_id` is preferred over the current user when core supplies one.
	 * `map_meta_cap()` is reachable through `user_can( $other_user, … )`, and
	 * answering for the current user in that case would report on the wrong
	 * person. When it is absent the two are equivalent, because
	 * `current_user_can()` is what put a zero there.
	 *
	 * @since 0.1.0
	 *
	 * @param bool   $allowed   Whether the key may be edited, as decided so far.
	 * @param string $meta_key  Meta key being authorized.
	 * @param int    $object_id Post the meta belongs to.
	 * @param int    $user_id   User the check is being made for.
	 * @return bool True when the user may edit this popup's meta.
	 */
	public static function can_edit( bool $allowed, string $meta_key, int $object_id, int $user_id ): bool {
		if ( $object_id <= 0 ) {
			return false;
		}

		if ( $user_id > 0 ) {
			return user_can( $user_id, 'edit_post', $object_id );
		}

		return current_user_can( 'edit_post', $object_id );
	}

	/**
	 * Default rule set: no groups, which `docs/data-model.md` defines as "always match".
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Empty rule set.
	 */
	public static function default_conditions(): array {
		return array( 'groups' => array() );
	}

	/**
	 * Default triggers: none.
	 *
	 * A popup with no trigger never opens by itself, which is the correct starting
	 * point — the author chooses what opens it, and nothing shows to a visitor in
	 * the meantime.
	 *
	 * @since 0.1.0
	 *
	 * @return array Empty trigger list.
	 */
	public static function default_triggers(): array {
		return array();
	}

	/**
	 * Default schedule: disabled, and therefore never consulted.
	 *
	 * A disabled schedule also keeps `needsContext` false, so a popup that does
	 * not need the context endpoint costs no round trip.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Default schedule object.
	 */
	public static function default_schedule(): array {
		return array(
			'enabled'    => false,
			'timezone'   => 'site',
			'start'      => null,
			'end'        => null,
			'recurrence' => array(
				'days'    => array(),
				'windows' => array(),
			),
		);
	}

	/**
	 * Default frequency cap: none, with conversion suppressing the popup for good.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Default frequency object.
	 */
	public static function default_frequency(): array {
		return array(
			'mode'       => 'always',
			'days'       => 7,
			'on_convert' => 'suppress_forever',
		);
	}

	/**
	 * Default display: a centered modal that inherits the theme.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Default display object.
	 */
	public static function default_display(): array {
		$defaults = array(
			'layout'                 => 'modal',
			'theme'                  => 'inherit',
			'size'                   => 'medium',
			'position'               => 'center',
			'overlay'                => true,
			'close_on_overlay_click' => true,
			'animation'              => 'fade',
		);

		// Every override defaults to "leave the theme alone", so a popup created
		// before customization existed and a popup created after it look identical.
		foreach ( self::DISPLAY_COLOR_FIELDS as $field ) {
			$defaults[ $field ] = '';
		}

		foreach ( array_keys( self::DISPLAY_SCALE_FIELDS ) as $field ) {
			$defaults[ $field ] = 'inherit';
		}

		return $defaults;
	}

	/**
	 * REST schema for the targeting rule set.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema for `_popkit_conditions`.
	 */
	public static function conditions_schema(): array {
		return array(
			'type'        => 'object',
			'description' => __( 'Groups of targeting rules. Groups are combined with OR, rules within a group with AND. No groups means the popup matches everywhere.', 'popkit' ),
			'properties'  => array(
				'groups' => array(
					'type'     => 'array',
					'maxItems' => Rule_Bounds::MAX_GROUPS_PER_SET,
					'items'    => array(
						'type'       => 'object',
						'properties' => array(
							'rules' => array(
								'type'     => 'array',
								'maxItems' => Rule_Bounds::MAX_RULES_PER_GROUP,
								'items'    => self::rule_schema(),
							),
						),
						'required'   => array( 'rules' ),
					),
				),
			),
		);
	}

	/**
	 * REST schema for one targeting rule.
	 *
	 * `values` accepts anything an unregistered condition might carry, because a
	 * rule whose plugin is deactivated must round trip unchanged. The structural
	 * limits on that payload are enforced by {@see Rule_Bounds} at sanitization
	 * time, where a violation can refuse the whole rule instead of trimming it.
	 *
	 * `unknown` is declared because it is part of the stored shape for an
	 * unregistered type, and the editor reads it to render an unavailable
	 * condition rather than an empty control.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema for one rule.
	 */
	public static function rule_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'type'    => array(
					'type'        => 'string',
					'description' => __( 'Registered condition key. An unrecognized key is preserved and treated as unavailable.', 'popkit' ),
					'minLength'   => 1,
					'maxLength'   => Rule_Bounds::MAX_STRING_LENGTH,
				),
				'negate'  => array(
					'type'        => 'boolean',
					'description' => __( 'Inverts the result of this rule. Never applied to an unavailable rule.', 'popkit' ),
					'default'     => false,
				),
				'values'  => array(
					'type'                 => 'object',
					'description'          => __( 'Settings for this rule, validated against the registered condition when there is one.', 'popkit' ),
					'additionalProperties' => true,
				),
				'unknown' => array(
					'type'        => 'boolean',
					'description' => __( 'Set when no condition is registered for this rule type.', 'popkit' ),
				),
			),
			'required'   => array( 'type' ),
		);
	}

	/**
	 * REST schema for the trigger list.
	 *
	 * The item cap reuses the one structural per-level limit popkit already
	 * documents rather than inventing a second number for the same purpose.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema for `_popkit_triggers`.
	 */
	public static function triggers_schema(): array {
		return array(
			'type'        => 'array',
			'description' => __( 'Triggers armed for this popup. They are independent: the first to fire opens the popup and the rest are torn down.', 'popkit' ),
			'maxItems'    => Rule_Bounds::MAX_ITEMS_PER_LEVEL,
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'type'   => array(
						'type'        => 'string',
						'description' => __( 'Registered trigger key.', 'popkit' ),
						'minLength'   => 1,
						'maxLength'   => Rule_Bounds::MAX_STRING_LENGTH,
					),
					'values' => array(
						'type'                 => 'object',
						'description'          => __( 'Arming parameters, validated against the registered trigger when there is one.', 'popkit' ),
						'additionalProperties' => true,
					),
				),
				'required'   => array( 'type' ),
			),
		);
	}

	/**
	 * REST schema for the schedule.
	 *
	 * `start` and `end` are nullable by design: null is open-ended, not missing.
	 * They carry `format => date-time` so a malformed date fails the request here,
	 * at the only layer able to tell the author about it — the sanitizer beneath
	 * has no choice but to read an unparseable bound as open-ended.
	 *
	 * The `HH:MM` grammar of a window is enforced by the sanitizer's linear check
	 * rather than by a `pattern` keyword, so this repository contains no regular
	 * expression for a reviewer to audit.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema for `_popkit_schedule`.
	 */
	public static function schedule_schema(): array {
		return array(
			'type'        => 'object',
			'description' => __( 'When this popup may run. Evaluated once per page load against the time reported by the context endpoint.', 'popkit' ),
			'properties'  => array(
				'enabled'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the schedule is applied at all.', 'popkit' ),
					'default'     => false,
				),
				'timezone'   => array(
					'type'        => 'string',
					'description' => __( 'Whether times are read in the site timezone or the visitor timezone.', 'popkit' ),
					'enum'        => self::SCHEDULE_TIMEZONES,
					'default'     => 'site',
				),
				'start'      => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'First moment the popup may run, in ISO 8601 UTC. Null is open-ended.', 'popkit' ),
					'format'      => 'date-time',
					'maxLength'   => self::MAX_DATETIME_LENGTH,
					'default'     => null,
				),
				'end'        => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Last moment the popup may run, in ISO 8601 UTC. Null is open-ended.', 'popkit' ),
					'format'      => 'date-time',
					'maxLength'   => self::MAX_DATETIME_LENGTH,
					'default'     => null,
				),
				'recurrence' => array(
					'type'        => 'object',
					'description' => __( 'Weekly recurrence within the start and end dates.', 'popkit' ),
					'properties'  => array(
						'days'    => array(
							'type'        => 'array',
							'description' => __( 'ISO 8601 weekdays, 1 is Monday. An empty list means every day.', 'popkit' ),
							'maxItems'    => self::MAX_WEEKDAY,
							'uniqueItems' => true,
							'items'       => array(
								'type'    => 'integer',
								'minimum' => self::MIN_WEEKDAY,
								'maximum' => self::MAX_WEEKDAY,
							),
						),
						'windows' => array(
							'type'        => 'array',
							'description' => __( 'Times of day, local to the chosen timezone. A window whose end is before its start crosses midnight and belongs to the day it starts on.', 'popkit' ),
							'maxItems'    => Rule_Bounds::MAX_ITEMS_PER_LEVEL,
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'from' => array(
										'type'        => 'string',
										'description' => __( 'Start of the window as HH:MM, inclusive.', 'popkit' ),
										'minLength'   => self::TIME_LENGTH,
										'maxLength'   => self::TIME_LENGTH,
									),
									'to'   => array(
										'type'        => 'string',
										'description' => __( 'End of the window as HH:MM, exclusive. Equal to the start means the window never matches.', 'popkit' ),
										'minLength'   => self::TIME_LENGTH,
										'maxLength'   => self::TIME_LENGTH,
									),
								),
								'required'   => array( 'from', 'to' ),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * REST schema for the frequency cap.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema for `_popkit_frequency`.
	 */
	public static function frequency_schema(): array {
		return array(
			'type'        => 'object',
			'description' => __( 'How often one visitor may see this popup. Enforced in browser storage only.', 'popkit' ),
			'properties'  => array(
				'mode'       => array(
					'type'        => 'string',
					'description' => __( 'Capping mode.', 'popkit' ),
					'enum'        => self::FREQUENCY_MODES,
					'default'     => 'always',
				),
				'days'       => array(
					'type'        => 'integer',
					'description' => __( 'Length of the rolling window, in days, used by the once per days mode.', 'popkit' ),
					'minimum'     => self::MIN_FREQUENCY_DAYS,
					'maximum'     => self::MAX_FREQUENCY_DAYS,
					'default'     => 7,
				),
				'on_convert' => array(
					'type'        => 'string',
					'description' => __( 'What a conversion does to the cap.', 'popkit' ),
					'enum'        => self::FREQUENCY_ON_CONVERT,
					'default'     => 'suppress_forever',
				),
			),
		);
	}

	/**
	 * REST schema for the display configuration.
	 *
	 * `position` lists the union of both layouts' values because JSON Schema
	 * cannot express "depends on another property" without `oneOf`, which the
	 * editor's control map does not read. The sanitizer resolves it against the
	 * chosen layout.
	 *
	 * There is no `close_button` property. See the class docblock.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema for `_popkit_display`.
	 */
	public static function display_schema(): array {
		$properties = array();

		foreach ( self::DISPLAY_COLOR_FIELDS as $field ) {
			$properties[ $field ] = array(
				'type'        => 'string',
				'description' => __( 'Hex color overriding the chosen theme, or an empty string to keep it.', 'popkit' ),
				'default'     => '',
				'maxLength'   => 7,
			);
		}

		foreach ( self::DISPLAY_SCALE_FIELDS as $field => $scale ) {
			$properties[ $field ] = array(
				'type'        => 'string',
				'description' => __( 'Appearance override, or inherit to keep what the chosen theme says.', 'popkit' ),
				'enum'        => $scale,
				'default'     => 'inherit',
			);
		}

		return array(
			'type'        => 'object',
			'description' => __( 'How this popup looks when it opens.', 'popkit' ),
			'properties'  => $properties + array(
				'layout'                 => array(
					'type'        => 'string',
					'description' => __( 'Modal dialog or inline banner.', 'popkit' ),
					'enum'        => self::DISPLAY_LAYOUTS,
					'default'     => 'modal',
				),
				'theme'                  => array(
					'type'        => 'string',
					'description' => __( 'Shipped theme, or inherit to follow the active site theme.', 'popkit' ),
					'enum'        => self::DISPLAY_THEMES,
					'default'     => 'inherit',
				),
				'size'                   => array(
					'type'        => 'string',
					'description' => __( 'Width of the popup.', 'popkit' ),
					'enum'        => self::DISPLAY_SIZES,
					'default'     => 'medium',
				),
				'position'               => array(
					'type'        => 'string',
					'description' => __( 'Where the popup sits. A modal is centered or at the top; a banner is at the top or the bottom.', 'popkit' ),
					'enum'        => self::display_positions(),
					'default'     => 'center',
				),
				'overlay'                => array(
					'type'        => 'boolean',
					'description' => __( 'Whether a modal dims the page behind it. Ignored for a banner.', 'popkit' ),
					'default'     => true,
				),
				'close_on_overlay_click' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether clicking the overlay closes the popup. This is an additional way to dismiss it, never the only one.', 'popkit' ),
					'default'     => true,
				),
				'animation'              => array(
					'type'        => 'string',
					'description' => __( 'Opening animation. All animations become instant when the visitor prefers reduced motion.', 'popkit' ),
					'enum'        => self::DISPLAY_ANIMATIONS,
					'default'     => 'fade',
				),
			),
		);
	}

	/**
	 * REST schema for the stored schema version.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema for `_popkit_schema_version`.
	 */
	public static function schema_version_schema(): array {
		return array(
			'type'        => 'integer',
			'description' => __( 'Version of the stored popup data shape. Managed by popkit; migrations compare against it on load.', 'popkit' ),
			'minimum'     => self::MIN_SCHEMA_VERSION,
			'default'     => self::MIN_SCHEMA_VERSION,
		);
	}

	/**
	 * Every position value any layout permits, deduplicated and in layout order.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Position values.
	 */
	public static function display_positions(): array {
		$positions = array();

		foreach ( self::DISPLAY_POSITIONS as $layout_positions ) {
			foreach ( $layout_positions as $position ) {
				$positions[ $position ] = $position;
			}
		}

		return array_values( $positions );
	}

	/**
	 * Sanitizes the trigger list.
	 *
	 * A trigger config whose type is registered has its values sanitized against
	 * that trigger's declared field schema. One whose type is not registered is
	 * preserved within {@see Rule_Bounds}, so deactivating the plugin that
	 * provides it and saving the popup does not destroy the author's setup.
	 *
	 * A config that survives neither path is dropped. Triggers are independent and
	 * first-fire-wins, so removing one can only reduce the ways a popup opens —
	 * the opposite of dropping a targeting rule, which would widen its group.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted trigger list.
	 * @return array List of `array( 'type' => string, 'values' => array )`.
	 */
	public static function sanitize_triggers( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$triggers = array();

		foreach ( $raw as $config ) {
			if ( count( $triggers ) >= Rule_Bounds::MAX_ITEMS_PER_LEVEL ) {
				break;
			}

			$trigger = self::sanitize_trigger( $config );

			if ( array() === $trigger ) {
				continue;
			}

			$triggers[] = $trigger;
		}

		return $triggers;
	}

	/**
	 * Sanitizes the schedule.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted schedule.
	 * @return array<string, mixed> Schedule in the stored shape.
	 */
	public static function sanitize_schedule( mixed $raw ): array {
		$values   = is_array( $raw ) ? $raw : array();
		$schedule = self::sanitize_object( $values, self::schedule_fields() );

		$schedule['start']      = self::sanitize_datetime( $values['start'] ?? null );
		$schedule['end']        = self::sanitize_datetime( $values['end'] ?? null );
		$schedule['recurrence'] = self::sanitize_recurrence( $values['recurrence'] ?? null );

		return $schedule;
	}

	/**
	 * Sanitizes the frequency cap.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted frequency object.
	 * @return array<string, mixed> Frequency in the stored shape.
	 */
	public static function sanitize_frequency( mixed $raw ): array {
		return self::sanitize_object( $raw, self::frequency_fields() );
	}

	/**
	 * Sanitizes the display configuration.
	 *
	 * Two rules cannot be expressed in a per-field schema and are applied here:
	 * `position` is resolved against the chosen layout, and
	 * `close_on_overlay_click` is forced off when there is no overlay to click.
	 *
	 * A `close_button` key is discarded along with any other key the schema does
	 * not declare, because this method builds the stored object from the declared
	 * fields rather than editing the submitted one.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted display object.
	 * @return array<string, mixed> Display configuration in the stored shape.
	 */
	public static function sanitize_display( mixed $raw ): array {
		$values  = is_array( $raw ) ? $raw : array();
		$fields  = self::display_fields();
		$layout  = self::field_value( $values, 'layout', $fields['layout'] );
		$overlay = self::field_value( $values, 'overlay', $fields['overlay'] );
		$close   = self::field_value( $values, 'close_on_overlay_click', $fields['close_on_overlay_click'] );

		$display = array(
			'layout'                 => $layout,
			'theme'                  => self::field_value( $values, 'theme', $fields['theme'] ),
			'size'                   => self::field_value( $values, 'size', $fields['size'] ),
			'position'               => self::field_value( $values, 'position', self::position_field( $layout ) ),
			'overlay'                => $overlay,
			'close_on_overlay_click' => $overlay && $close,
			'animation'              => self::field_value( $values, 'animation', $fields['animation'] ),
		);

		/*
		 * The color overrides go through sanitize_color() rather than through
		 * field_value(), because a `string` field's sanitizer only bounds length
		 * and strips tags — neither of which stops `#fff;position:fixed` from
		 * reaching a style attribute intact.
		 */
		foreach ( self::DISPLAY_COLOR_FIELDS as $field ) {
			$display[ $field ] = self::sanitize_color( $values[ $field ] ?? '' );
		}

		foreach ( array_keys( self::DISPLAY_SCALE_FIELDS ) as $field ) {
			$display[ $field ] = self::field_value( $values, $field, $fields[ $field ] );
		}

		return $display;
	}

	/**
	 * Sanitizes the stored schema version.
	 *
	 * Clamped upward to self::MIN_SCHEMA_VERSION and never downward to
	 * self::SCHEMA_VERSION. A value higher than this release understands means the
	 * popup was written by a newer popkit, and rewriting it to the current version
	 * would tell a later downgrade that a migration it never ran is complete.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted version.
	 * @return int Stored schema version.
	 */
	public static function sanitize_schema_version( mixed $raw ): int {
		$version = Sanitizer::sanitize_value(
			$raw,
			array(
				'type'    => 'integer',
				'minimum' => self::MIN_SCHEMA_VERSION,
				'default' => self::MIN_SCHEMA_VERSION,
			)
		);

		return (int) $version;
	}

	/**
	 * Field schemas for the scalar parts of the schedule.
	 *
	 * These are sanitization schemas in the vocabulary {@see Sanitizer} reads, not
	 * registered condition fields: they carry no label and no control, because
	 * nothing renders them from here. `start`, `end` and `recurrence` are absent
	 * because that vocabulary has no date type and no nested object type; they are
	 * handled explicitly in {@see Meta::sanitize_schedule()}.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>> Field name => field schema.
	 */
	private static function schedule_fields(): array {
		return array(
			'enabled'  => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'timezone' => array(
				'type'    => 'enum',
				'enum'    => self::SCHEDULE_TIMEZONES,
				'default' => 'site',
			),
		);
	}

	/**
	 * Field schemas for the frequency cap.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>> Field name => field schema.
	 */
	private static function frequency_fields(): array {
		return array(
			'mode'       => array(
				'type'    => 'enum',
				'enum'    => self::FREQUENCY_MODES,
				'default' => 'always',
			),
			'days'       => array(
				'type'    => 'integer',
				'minimum' => self::MIN_FREQUENCY_DAYS,
				'maximum' => self::MAX_FREQUENCY_DAYS,
				'default' => 7,
			),
			'on_convert' => array(
				'type'    => 'enum',
				'enum'    => self::FREQUENCY_ON_CONVERT,
				'default' => 'suppress_forever',
			),
		);
	}

	/**
	 * Field schemas for the display configuration.
	 *
	 * `position` is present for completeness but is not used directly: its
	 * permitted values depend on the chosen layout, so
	 * {@see Meta::sanitize_display()} resolves it through
	 * {@see Meta::position_field()} instead.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>> Field name => field schema.
	 */
	private static function display_fields(): array {
		$fields = array(
			'layout'                 => array(
				'type'    => 'enum',
				'enum'    => self::DISPLAY_LAYOUTS,
				'default' => 'modal',
			),
			'theme'                  => array(
				'type'    => 'enum',
				'enum'    => self::DISPLAY_THEMES,
				'default' => 'inherit',
			),
			'size'                   => array(
				'type'    => 'enum',
				'enum'    => self::DISPLAY_SIZES,
				'default' => 'medium',
			),
			'position'               => self::position_field( 'modal' ),
			'overlay'                => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'close_on_overlay_click' => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'animation'              => array(
				'type'    => 'enum',
				'enum'    => self::DISPLAY_ANIMATIONS,
				'default' => 'fade',
			),
		);

		foreach ( self::DISPLAY_COLOR_FIELDS as $field ) {
			$fields[ $field ] = array(
				'type'       => 'string',
				'default'    => '',
				'max_length' => 7,
			);
		}

		foreach ( self::DISPLAY_SCALE_FIELDS as $field => $scale ) {
			$fields[ $field ] = array(
				'type'    => 'enum',
				'enum'    => $scale,
				'default' => 'inherit',
			);
		}

		return $fields;
	}

	/**
	 * Reduces a submitted color to a hex triplet, or to nothing.
	 *
	 * `sanitize_hex_color()` returns null for anything that is not `#rgb` or
	 * `#rrggbb`, and null becomes an empty string here — which the renderer reads
	 * as "use the theme". A rejected color therefore falls back to a value that
	 * is known to meet the contrast the shipped themes were measured for, rather
	 * than to a partially-parsed string.
	 *
	 * This is the whole of the defense for these four fields. They are printed
	 * into a `style` attribute, so a value that could contain `;` or `)` could
	 * close the declaration it sits in and start another one.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted color.
	 * @return string Hex color, or an empty string.
	 */
	private static function sanitize_color( mixed $raw ): string {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return '';
		}

		return (string) sanitize_hex_color( trim( $raw ) );
	}

	/**
	 * Field schema for `display.position` under one layout.
	 *
	 * A position belonging to the other layout is not a value this layout can
	 * render, so it falls back to the first value the layout documents: `center`
	 * for a modal, `top` for a banner.
	 *
	 * @since 0.1.0
	 *
	 * @param string $layout Chosen layout.
	 * @return array<string, mixed> Field schema for the position.
	 */
	private static function position_field( string $layout ): array {
		$positions = self::DISPLAY_POSITIONS[ $layout ] ?? self::DISPLAY_POSITIONS['modal'];

		return array(
			'type'    => 'enum',
			'enum'    => $positions,
			'default' => $positions[0],
		);
	}

	/**
	 * Sanitizes an object against a map of field schemas.
	 *
	 * Mirrors what {@see Sanitizer} does for a registered condition's values: a
	 * declared field present in the payload is sanitized from its declared type, a
	 * declared field that is absent falls back to its declared default, and a key
	 * the map does not declare is discarded.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw    Submitted object.
	 * @param array $fields Field name => field schema.
	 * @return array<string, mixed> Sanitized object holding only declared fields.
	 */
	private static function sanitize_object( mixed $raw, array $fields ): array {
		$values    = is_array( $raw ) ? $raw : array();
		$sanitized = array();

		foreach ( $fields as $name => $schema ) {
			if ( ! is_array( $schema ) ) {
				continue;
			}

			$sanitized[ $name ] = self::field_value( $values, $name, $schema );
		}

		return $sanitized;
	}

	/**
	 * Sanitizes one field out of a submitted object.
	 *
	 * The declared default is routed through the sanitizer too, so a default and a
	 * submitted value of the same shape always produce the same stored value.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $values Submitted object.
	 * @param string $name   Field name.
	 * @param array  $schema Field schema.
	 * @return mixed Sanitized value, typed per the schema.
	 */
	private static function field_value( array $values, string $name, array $schema ): mixed {
		if ( array_key_exists( $name, $values ) ) {
			return Sanitizer::sanitize_value( $values[ $name ], $schema );
		}

		return Sanitizer::sanitize_value( $schema['default'] ?? null, $schema );
	}

	/**
	 * Sanitizes one trigger configuration.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted trigger configuration.
	 * @return array Sanitized configuration, or an empty array when it cannot be kept.
	 */
	private static function sanitize_trigger( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$type = $raw['type'] ?? null;

		if ( ! is_string( $type ) ) {
			return array();
		}

		$type = sanitize_text_field( $type );

		if ( '' === $type || strlen( $type ) > Rule_Bounds::MAX_STRING_LENGTH ) {
			return array();
		}

		$values  = $raw['values'] ?? array();
		$trigger = self::registered_trigger( $type );

		if ( $trigger instanceof Trigger ) {
			return array(
				'type'   => $type,
				'values' => self::sanitize_object( $values, $trigger->fields ),
			);
		}

		if ( Rule_Bounds::check( $values ) instanceof WP_Error ) {
			return array();
		}

		return array(
			'type'   => $type,
			'values' => $values,
		);
	}

	/**
	 * Sanitizes a schedule bound.
	 *
	 * Anything unparseable becomes null, which `docs/data-model.md` defines as
	 * open-ended. That is the widest reading, and it is acceptable only because
	 * the REST schema rejects a malformed date before it can reach here: this path
	 * is the floor beneath a direct `update_post_meta()` call, where there is
	 * nobody to show an error to.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted bound.
	 * @return string|null ISO 8601 UTC timestamp, or null for open-ended.
	 */
	private static function sanitize_datetime( mixed $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$value = trim( $raw );

		if ( '' === $value || strlen( $value ) > self::MAX_DATETIME_LENGTH ) {
			return null;
		}

		$utc = new \DateTimeZone( 'UTC' );

		foreach ( self::DATETIME_FORMATS as $format ) {
			$date   = \DateTimeImmutable::createFromFormat( $format, $value, $utc );
			$errors = \DateTimeImmutable::getLastErrors();

			if ( false === $date ) {
				continue;
			}

			// PHP 8.2 returns false here when the parse was clean; 8.1 returns zeroed counts.
			if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
				continue;
			}

			return gmdate( self::DATETIME_FORMAT, $date->getTimestamp() );
		}

		return null;
	}

	/**
	 * Sanitizes the weekly recurrence.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted recurrence.
	 * @return array<string, mixed> Recurrence in the stored shape.
	 */
	private static function sanitize_recurrence( mixed $raw ): array {
		$values = is_array( $raw ) ? $raw : array();

		return array(
			'days'    => self::sanitize_days( $values['days'] ?? null ),
			'windows' => self::sanitize_windows( $values['windows'] ?? null ),
		);
	}

	/**
	 * Sanitizes the list of ISO 8601 weekdays.
	 *
	 * A value outside 1–7 is dropped rather than clamped. The schema-derived
	 * integer sanitizer clamps to the nearest bound, which would turn a `0` into
	 * Monday and quietly add a day the author never selected. Dropping can only
	 * narrow the schedule, and an empty list already means every day.
	 *
	 * Duplicates are collapsed and the list is sorted, so the same selection
	 * always stores the same way.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted weekdays.
	 * @return int[] Weekday numbers, ascending.
	 */
	private static function sanitize_days( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$days = array();

		foreach ( $raw as $day ) {
			if ( is_string( $day ) && ctype_digit( trim( $day ) ) ) {
				$day = (int) trim( $day );
			}

			if ( ! is_int( $day ) ) {
				continue;
			}

			if ( $day < self::MIN_WEEKDAY || $day > self::MAX_WEEKDAY ) {
				continue;
			}

			$days[ $day ] = $day;
		}

		ksort( $days );

		return array_values( $days );
	}

	/**
	 * Sanitizes the list of recurrence windows.
	 *
	 * Windows past the structural limit are ignored rather than refused. Removing
	 * a window narrows the schedule, and the list still holds the limit's worth of
	 * them, so no window is lost in a way that widens the result.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted windows.
	 * @return array List of `array( 'from' => string, 'to' => string )`.
	 */
	private static function sanitize_windows( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$windows = array();

		foreach ( $raw as $window ) {
			if ( count( $windows ) >= Rule_Bounds::MAX_ITEMS_PER_LEVEL ) {
				break;
			}

			$windows[] = self::sanitize_window( $window );
		}

		return $windows;
	}

	/**
	 * Sanitizes one recurrence window.
	 *
	 * A window that cannot be read becomes zero-length, which never matches, and
	 * which the editor already warns about. Dropping it instead would be wrong:
	 * an empty window list means "any time of day", so removing the only window on
	 * a lunchtime campaign would let it run around the clock.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted window.
	 * @return array<string, string> Window in the stored shape.
	 */
	private static function sanitize_window( mixed $raw ): array {
		$closed = array(
			'from' => self::MIDNIGHT,
			'to'   => self::MIDNIGHT,
		);

		if ( ! is_array( $raw ) ) {
			return $closed;
		}

		$from = self::sanitize_time( $raw['from'] ?? null );
		$to   = self::sanitize_time( $raw['to'] ?? null );

		if ( null === $from || null === $to ) {
			return $closed;
		}

		return array(
			'from' => $from,
			'to'   => $to,
		);
	}

	/**
	 * Sanitizes one `HH:MM` time.
	 *
	 * A fixed-length character walk, not a pattern match. The grammar is five
	 * characters wide and there is nothing here for a regular expression to buy.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Submitted time.
	 * @return string|null The time, or null when it is not a valid `HH:MM`.
	 */
	private static function sanitize_time( mixed $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$value = trim( $raw );

		if ( self::TIME_LENGTH !== strlen( $value ) || ':' !== $value[2] ) {
			return null;
		}

		$hours   = substr( $value, 0, 2 );
		$minutes = substr( $value, 3, 2 );

		if ( ! ctype_digit( $hours ) || ! ctype_digit( $minutes ) ) {
			return null;
		}

		if ( (int) $hours > self::MAX_HOUR || (int) $minutes > self::MAX_MINUTE ) {
			return null;
		}

		return $value;
	}

	/**
	 * Looks a trigger up in the registry.
	 *
	 * Guarded rather than assumed, for the reason given on
	 * {@see Sanitizer::sanitize_rule()}: this class is reachable from contexts
	 * where the registries have not been built, and an absent registry must mean
	 * "treat every trigger as unregistered" — preserving the author's values —
	 * rather than a fatal error.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type Trigger type.
	 * @return Trigger|null The registered trigger, or null when there is none.
	 */
	private static function registered_trigger( string $type ): ?Trigger {
		if ( ! function_exists( 'popkit_triggers' ) ) {
			return null;
		}

		$triggers = popkit_triggers();

		if ( ! $triggers instanceof Triggers ) {
			return null;
		}

		return $triggers->get( $type );
	}
}
