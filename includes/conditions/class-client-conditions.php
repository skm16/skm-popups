<?php
/**
 * Registration of the five conditions decided in the visitor's browser.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit\Conditions;

use Popkit\Condition;
use Popkit\Context;
use Popkit\Url_Matcher;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the five `Context::Client` conditions — and deliberately decides none of them.
 *
 * | Key | Fields | Answers |
 * |---|---|---|
 * | `device` | `max_width` | Is the viewport no wider than this many CSS pixels? |
 * | `user_state` | `state` | Is the visitor signed in, or not? |
 * | `referrer` | `match`, `value` | Did the visitor arrive from a matching URL? |
 * | `utm` | `param`, `value` | Does a named query parameter hold this value? |
 * | `visit_history` | `state` | Has this browser been here before? |
 *
 * All five are in the editor's `visitor` group, and all five are the sole reason
 * `Context::Client` exists: every one of them is a fact about the person looking
 * at the page rather than about the page. A page cache serves one HTML response
 * to many visitors, so a rendering decision taken from any of them in PHP would
 * be a decision taken on behalf of whoever happened to fill the cache — the
 * invariant in `docs/CLAUDE.md` -> Cache safety.
 *
 * ## There is no evaluate function here, and that is the design
 *
 * `Popkit\Conditions\Content_Conditions` and `Popkit\Conditions\Url_Conditions`
 * each ship a static `evaluate()` alongside their declarations, and
 * `Popkit\Frontend::evaluate_server_rule()` chains the two. This class ships
 * neither, attaches to no evaluation filter, and must never acquire either.
 *
 * `Popkit\Rule_Evaluator::decide_rule_server()` reads the registered condition's
 * context and returns null — indeterminate — for anything that is not
 * `Context::Server`, before an evaluator is consulted at all. So the group
 * survives server-side, the rule is emitted verbatim into the config, and the
 * browser decides it against the visitor it can actually see. Adding an
 * `evaluate()` here would not merely be dead code: whatever a reviewer later
 * wired it into would be reading a cookie, a header or a viewport during the
 * generation of a cacheable response, which is the one thing this architecture
 * exists to make unrepresentable.
 *
 * The evaluators live in `src/frontend/conditions/`, one module per key, and the
 * `key` each module exports must equal the key registered here. That pairing is
 * the whole contract between the two halves, and it is why the keys below are
 * constants rather than repeated literals.
 *
 * ## What registering them actually buys
 *
 * Three things, and the first two are easy to overlook because the browser code
 * appears to work without any of them:
 *
 * 1. **The rule is not tagged `unknown`.**
 *    `Popkit\Rule_Evaluator::strip_server_rules()` tags every emitted rule whose
 *    `type` no registered condition claims, and the controller denies a tagged
 *    rule before it ever looks up an evaluator. An unregistered client condition
 *    is therefore not "a condition without an editor UI" — it is a condition that
 *    can never match, on any page, with a fully working JavaScript module sitting
 *    unreachable behind the tag.
 * 2. **Stored values are sanitized from a schema.**
 *    `Popkit\Sanitizer::sanitize_rule()` branches on whether the type resolves to
 *    a registered condition. Registered: values are validated field by field
 *    against the schemas below, undeclared keys are dropped, an enum is held to
 *    its list, `max_width` is clamped to its bounds, and an over-length string
 *    refuses the whole rule. Unregistered: the payload is preserved verbatim
 *    through the unknown-rule bounds path, because there is nothing to validate
 *    it against. `docs/CLAUDE.md` -> Registry invariants requires the first of
 *    those for client conditions too — field schemas are declared in PHP only,
 *    and JavaScript supplies nothing but the `evaluate` function.
 * 3. **The editor can render a control.** The registry REST route reports these
 *    schemas, and the sidebar builds its controls from them.
 *
 * ## Every string field declares a length cap
 *
 * `referrer`'s `value`, and both of `utm`'s fields, declare `max_length` as
 * `Popkit\Url_Matcher::MAX_VALUE_LENGTH`. One number, declared in one place, for
 * every literal this plugin compares against a piece of a URL.
 *
 * The cap is not decoration. A `string` field that omits it is stored uncapped —
 * `Popkit\Sanitizer::sanitize_string()` truncates only when a cap is declared —
 * and the unknown-rule path these rules used to travel bounded them at
 * `Popkit\Rule_Bounds::MAX_STRING_LENGTH`. Registering a string field without a
 * cap would therefore have removed a bound rather than added one, which is the
 * opposite of what registration is for.
 *
 * ## Namespace
 *
 * `Popkit\Conditions` is both the registry class and this file's namespace, the
 * arrangement the sibling condition classes already live with. An unqualified
 * `Conditions` here would name `Popkit\Conditions\Conditions`, which does not
 * exist, so the registry is always written fully qualified.
 *
 * ## Testing
 *
 * {@see Client_Conditions::all()} needs no WordPress beyond `__()`, so every
 * schema below is unit-testable: the keys, the enum lists, the bounds and the
 * declared context. A test that matters more than any of those is the one
 * asserting that the *built-in registry* holds all twelve conditions and that
 * each of these five is `Context::Client` — a private fixture registered by a
 * test proves only that the registry works, never that popkit filled it.
 *
 * @since 0.1.0
 */
final class Client_Conditions {

	/**
	 * Registry key of the viewport width condition.
	 *
	 * The five keys are constants because each has to equal the `key` its
	 * JavaScript module exports, and a typo would register a control whose rule is
	 * emitted tagged `unknown` and denied in the browser — a popup that never
	 * shows, with nothing anywhere reporting why.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const KEY_DEVICE = 'device';

	/**
	 * Registry key of the login state condition.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const KEY_USER_STATE = 'user_state';

	/**
	 * Registry key of the referrer condition.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const KEY_REFERRER = 'referrer';

	/**
	 * Registry key of the campaign parameter condition.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const KEY_UTM = 'utm';

	/**
	 * Registry key of the visit history condition.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const KEY_VISIT_HISTORY = 'visit_history';

	/**
	 * Editor group all five conditions belong to.
	 *
	 * Presentational only. The grouping and the context correlate across the
	 * built-in set but do not imply one another — see `Popkit\Condition::GROUPS`.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const GROUP = 'visitor';

	/**
	 * Narrowest viewport a `device` rule may name, in CSS pixels.
	 *
	 * One rather than zero. A width of zero is a media query no viewport can
	 * satisfy, so it stores a rule that matches nobody while looking like a rule
	 * that was filled in, and `src/frontend/conditions/device.js` states that the
	 * 1-and-up range is enforced here rather than clamped in the browser.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MIN_VIEWPORT_WIDTH = 1;

	/**
	 * Widest viewport a `device` rule may name, in CSS pixels.
	 *
	 * Comfortably past the widest panel anyone browses on, so no author is refused
	 * a breakpoint they meant. The cap exists because a bound is what keeps a
	 * hand-written meta value from reaching the database as an arbitrary integer,
	 * not because 5121 would misbehave: any width past the largest real viewport
	 * matches every visitor, which is a rule an author should delete rather than
	 * store.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_VIEWPORT_WIDTH = 5120;

	/**
	 * Viewport width a new `device` rule starts at, in CSS pixels.
	 *
	 * The width WordPress's own responsive styles treat as the boundary between a
	 * small screen and a large one, so an author who has never thought about
	 * breakpoints gets the same answer their admin already gives them. It is also
	 * the worked example in `src/frontend/conditions/device.js`.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const DEFAULT_VIEWPORT_WIDTH = 782;

	/**
	 * Stored `user_state` value meaning the visitor is signed in.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const STATE_LOGGED_IN = 'logged_in';

	/**
	 * Stored `user_state` value meaning the visitor is not signed in.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const STATE_LOGGED_OUT = 'logged_out';

	/**
	 * Stored `visit_history` value meaning this browser has not been here before.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const VISIT_FIRST_TIME = 'first_time';

	/**
	 * Stored `visit_history` value meaning this browser has been here before.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const VISIT_RETURNING = 'returning';

	/**
	 * Attaches registration to `popkit_register_conditions`.
	 *
	 * Called from `Plugin::boot()` on `plugins_loaded`. Attaching rather than
	 * registering immediately is the point: `popkit_conditions()` builds its
	 * registry lazily and dispatches that action exactly once, tied to
	 * construction, so calling the accessor here would fire the action before any
	 * extension had attached to it and every third-party registration would be
	 * silently dropped.
	 *
	 * There is one `add_action()` and nothing else. No evaluation filter is joined
	 * — see the class docblock — so a reviewer can confirm by looking that no
	 * client condition can be decided while a cacheable response is generated.
	 *
	 * The callback is an array callable rather than a first-class callable so the
	 * hook keeps a stable identity: WordPress deduplicates it, a test can assert
	 * it by name, and a site owner can remove it. A first-class callable produces
	 * a new closure on every call and would do none of those things — and a second
	 * attachment would re-run `Popkit\Conditions::register()` on keys that are
	 * already taken, which raises.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'popkit_register_conditions', array( self::class, 'register' ) );
	}

	/**
	 * Registers every client condition.
	 *
	 * Registration order is preserved by the registry and is what the editor
	 * presents, so the order in {@see Client_Conditions::all()} is the order an
	 * author sees.
	 *
	 * @since 0.1.0
	 *
	 * @param \Popkit\Conditions $registry Registry to add conditions to.
	 * @return void
	 */
	public static function register( \Popkit\Conditions $registry ): void {
		foreach ( self::all() as $condition ) {
			$registry->register( $condition );
		}
	}

	/**
	 * Builds every client condition, in the order the editor lists them.
	 *
	 * Returned as a list rather than registered inline so that a test can inspect
	 * the declarations without a registry, and so the ordering decision is visible
	 * in one place. The order is the order of `docs/data-model.md` -> Built-in
	 * conditions, so the table and the sidebar agree.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition[] Client conditions.
	 */
	public static function all(): array {
		return array(
			self::device(),
			self::user_state(),
			self::referrer(),
			self::utm(),
			self::visit_history(),
		);
	}

	/**
	 * Matches a viewport no wider than a given number of CSS pixels.
	 *
	 * The control is `number` rather than `range`. An author sets this field by
	 * reading a breakpoint off their theme's stylesheet and typing it in; a slider
	 * makes an exact value fiddly to land on and hides the number that is the
	 * whole point of the rule.
	 *
	 * `max_width` is the only field, and the comparison is "at most", because a
	 * pair of bounds would invite a rule whose minimum exceeds its maximum and
	 * would double the schema for a question authors ask in one direction. An
	 * author targeting large screens writes the small-screen rule and negates it.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function device(): Condition {
		return new Condition(
			self::KEY_DEVICE,
			Context::Client,
			self::GROUP,
			__( 'Device width', 'popkit' ),
			array(
				'max_width' => array(
					'type'    => 'integer',
					'default' => self::DEFAULT_VIEWPORT_WIDTH,
					'label'   => __( 'Widest viewport that matches, in CSS pixels', 'popkit' ),
					'control' => 'number',
					'min'     => self::MIN_VIEWPORT_WIDTH,
					'max'     => self::MAX_VIEWPORT_WIDTH,
				),
			)
		);
	}

	/**
	 * Matches whether the visitor is signed in.
	 *
	 * The only condition in v1 that needs the context route, and the reason that
	 * route exists: login state is a cookie's worth of information, so reading it
	 * during emission would vary a cached response by visitor.
	 *
	 * The field is an enum of two rather than a boolean, and the difference is
	 * worth the extra bytes. A boolean field forces every reader — the sidebar,
	 * the sanitizer, the browser module — to remember which way round `true`
	 * points, and a stored value that failed to arrive would read as `false`,
	 * which is a valid answer rather than a missing one. Two named states cannot
	 * be misread in either direction, and a third can be added in 1.1 without
	 * changing the shape of anything.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function user_state(): Condition {
		return new Condition(
			self::KEY_USER_STATE,
			Context::Client,
			self::GROUP,
			__( 'Login state', 'popkit' ),
			array(
				'state' => array(
					'type'    => 'enum',
					'enum'    => array( self::STATE_LOGGED_IN, self::STATE_LOGGED_OUT ),
					'default' => self::STATE_LOGGED_IN,
					'label'   => __( 'Whether the visitor is signed in', 'popkit' ),
					'control' => 'select',
				),
			)
		);
	}

	/**
	 * Matches the URL the visitor arrived from.
	 *
	 * Two fields, and they are the same constrained match language `url_path`
	 * declares: the mode list is `Popkit\Url_Matcher::modes()` and the length cap
	 * is `Popkit\Url_Matcher::MAX_VALUE_LENGTH`, so the editor's selector, the
	 * save-time check and the walk in `src/frontend/conditions/referrer.js` cannot
	 * drift apart. There is no regex mode and there will not be one — `docs/CLAUDE.md`
	 * -> Security -> URL match language.
	 *
	 * The default mode is `exact`, matching `url_path`, so one match language has
	 * one default wherever it appears. Worth knowing while writing a rule: the
	 * subject is the referrer's **host and path**, and a referrer with no path
	 * reads as `example.org/`, so a rule naming a bare host wants `prefix`. The
	 * field label says so, because the alternative is an author writing `exact`
	 * against `example.org` and getting a popup that never appears.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function referrer(): Condition {
		return new Condition(
			self::KEY_REFERRER,
			Context::Client,
			self::GROUP,
			__( 'Referrer', 'popkit' ),
			array(
				'match' => array(
					'type'    => 'enum',
					'enum'    => Url_Matcher::modes(),
					'default' => 'exact',
					'label'   => __( 'How the referrer is compared', 'popkit' ),
					'control' => 'select',
				),
				'value' => array(
					'type'       => 'string',
					'default'    => '',
					'label'      => __( 'Referrer to match, as host and path. Use "starts with" for a bare host name, because a referrer with no path ends in a slash.', 'popkit' ),
					'control'    => 'url-match',
					'max_length' => Url_Matcher::MAX_VALUE_LENGTH,
				),
			)
		);
	}

	/**
	 * Matches a query parameter on the URL the visitor is on.
	 *
	 * Named for the campaign parameters it exists to read, but `param` is any
	 * parameter name: the targeting question — did this visitor arrive from the
	 * appeal email — is the same whatever the sending tool called its parameter.
	 * Both fields are plain `text` controls for that reason; a fixed list of `utm_`
	 * names would refuse the tool that spells one differently.
	 *
	 * Comparison is exact and case-sensitive, and there is no match mode here on
	 * purpose. A campaign tag is an identifier that the author pastes into their
	 * mail tool and into this field, and an author who needs pattern matching
	 * against the URL has `url_path`, which owns the match language. See
	 * `src/frontend/conditions/utm.js`, where that reasoning is spelled out.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function utm(): Condition {
		return new Condition(
			self::KEY_UTM,
			Context::Client,
			self::GROUP,
			__( 'Campaign parameter', 'popkit' ),
			array(
				'param' => array(
					'type'       => 'string',
					'default'    => '',
					'label'      => __( 'Query parameter name, such as utm_campaign', 'popkit' ),
					'control'    => 'text',
					'max_length' => Url_Matcher::MAX_VALUE_LENGTH,
				),
				'value' => array(
					'type'       => 'string',
					'default'    => '',
					'label'      => __( 'Value the parameter must hold, matched exactly', 'popkit' ),
					'control'    => 'text',
					'max_length' => Url_Matcher::MAX_VALUE_LENGTH,
				),
			)
		);
	}

	/**
	 * Matches whether this browser has been here before.
	 *
	 * Two named states rather than a boolean, for the reason given on
	 * {@see Client_Conditions::user_state()} plus one specific to this condition:
	 * `src/frontend/conditions/visit-history.js` compares the two by name so that
	 * a stored value which is neither reads as unanswerable and fails the rule
	 * closed, instead of quietly meaning `first_time` and greeting every returning
	 * visitor.
	 *
	 * The record lives in `localStorage` and is a single flag — no count, no
	 * timestamp, no identifier — and never in a cookie, which would be sent with
	 * every request and vary the cached response.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function visit_history(): Condition {
		return new Condition(
			self::KEY_VISIT_HISTORY,
			Context::Client,
			self::GROUP,
			__( 'Visit history', 'popkit' ),
			array(
				'state' => array(
					'type'    => 'enum',
					'enum'    => array( self::VISIT_FIRST_TIME, self::VISIT_RETURNING ),
					'default' => self::VISIT_FIRST_TIME,
					'label'   => __( 'Whether this is the visitor\'s first visit', 'popkit' ),
					'control' => 'select',
				),
			)
		);
	}
}
