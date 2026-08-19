<?php
/**
 * Front-end matching, config emission and conditional asset enqueue.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the cacheable half of the evaluation pipeline and emits its result.
 *
 * This class is stages 1 to 5 of `docs/CLAUDE.md` -> Architecture invariants, in
 * that order and no other:
 *
 * 1. Query published popups.
 * 2. Evaluate `Context::Server` rules through
 *    {@see Rule_Evaluator::rule_set_passes_server()}.
 * 3. Emit the config JSON, and the markup, for whatever survived.
 * 4. Set `needsContext` when a survivor has an enabled schedule or a `user_state`
 *    rule.
 * 5. Enqueue `dist/frontend.js` and `dist/frontend.css` only if at least one
 *    popup survived.
 *
 * Stage 2 is a real gate. {@see Frontend::evaluate_server_rule()} is the
 * evaluator {@see Rule_Evaluator} was built to accept, so a stored
 * `Context::Server` rule is genuinely decided while the response is generated: a
 * popup whose every group fails is omitted entirely — no entry in the config, no
 * markup, and no assets at all if it was the only candidate. The same evaluator
 * is passed to {@see Rule_Evaluator::strip_server_rules()}, which is what keeps
 * the judgement that fails a group and the judgement that strips a rule from
 * ever disagreeing.
 *
 * ## Cache safety
 *
 * The one rule this class exists to keep:
 *
 * > Everything emitted is a function of the URL and of site settings. Nothing
 * > here reads anything that varies by visitor or by request.
 *
 * So: no `is_user_logged_in()`, no `wp_get_current_user()`, no `$_COOKIE`, no
 * `$_SERVER['HTTP_REFERER']`, no `$_SERVER['HTTP_USER_AGENT']`, no nonce, and no
 * clock. Two requests to the same URL carrying different cookies and different
 * user agents produce byte-identical HTML, and there is an exit-criterion test
 * asserting exactly that.
 *
 * **There is no `serverTime` field, and there will not be one.** A timestamp
 * printed into the response is frozen at cache-fill time: a page cached at 09:00
 * and served at 17:00 reports 09:00 to everyone who reads it, and adding elapsed
 * script time recovers none of the eight hours the entry spent sitting in the
 * cache. Authoritative time comes from the context route, which is uncached, and
 * is calibrated against the browser's monotonic clock. See `docs/data-model.md`
 * -> Schedule -> Where `now` comes from.
 *
 * Three values emitted here deserve their justification stated, because each one
 * looks request-shaped at a glance:
 *
 * - `siteTimezone` is `wp_timezone_string()`, an option. Identical for every
 *   visitor.
 * - `restUrl` is `rest_url()`, derived from the home URL. Its scheme follows
 *   `is_ssl()`, which is a property of the *URL* being requested rather than of
 *   the visitor requesting it — two requests to the same URL agree on it.
 * - The popup list is decided by the queried object and by stored targeting
 *   only. Login state, device and referrer are client conditions by
 *   construction; {@see Context} is what makes that unrepresentable here.
 *
 * ## Ordering
 *
 * `enqueue_assets()` runs on `wp_enqueue_scripts` and `render()` on `wp_footer`,
 * which means the query and the server-side evaluation happen during `wp_head`,
 * before anything is printed. The matched set is memoised for the rest of the
 * request so the two hooks cannot disagree about which popups survived — a
 * disagreement would either enqueue assets for a page with no popups or print
 * markup no script would ever animate.
 *
 * `render()` claims the default priority, which puts the config element ahead of
 * `wp_print_footer_scripts()` at priority 20. The bundle is additionally enqueued
 * with the `defer` strategy, so it executes after the document has been parsed
 * and the config element is in the DOM either way.
 *
 * @since 0.1.0
 */
final class Frontend {

	/**
	 * `id` of the script element carrying the emitted config.
	 *
	 * Public because the front-end bundle and the Playwright suite both look the
	 * element up by this exact string.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const CONFIG_ELEMENT_ID = 'popkit-config';

	/**
	 * Handle shared by the front-end script and stylesheet.
	 *
	 * One handle for both is deliberate: they are enqueued together, dequeued
	 * together, and a site owner who wants to replace one almost always wants to
	 * replace the other.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const HANDLE = 'popkit-frontend';

	/**
	 * Rule type whose presence in the emitted config requires the context route.
	 *
	 * `user_state` is the only condition in v1 that the browser cannot answer for
	 * itself: `docs/data-model.md` is explicit that `document.body.classList` is
	 * not an acceptable source, because body classes are part of the cached HTML
	 * and therefore describe whichever visitor happened to fill the cache.
	 *
	 * Matched as a literal string rather than resolved through the registry. A
	 * rule's `type` is stored verbatim, so the literal is correct whether or not
	 * the condition is registered on this request — which matters, because a
	 * `user_state` rule belonging to a deactivated extension still has to be
	 * failed closed by a client that fetched context to judge it with. Phase 4
	 * must register the built-in condition under this same key.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const USER_STATE_CONDITION = 'user_state';

	/**
	 * Most popups considered on a single pageview.
	 *
	 * A bound rather than an unlimited query, because this runs on every
	 * front-end request.
	 *
	 * **What it bounds is the candidate set, not the matched set.** The limit is
	 * a SQL `LIMIT` applied by the database in {@see self::query_popups()},
	 * before {@see Rule_Evaluator} has judged a single rule. So the oldest
	 * `MAX_POPUPS` published popups are the only ones targeting ever sees, and a
	 * popup ranked below the cut cannot appear on any URL whatever its rules say
	 * — even on a site where none of the hundred above it match the page being
	 * served.
	 *
	 * That is truncation. An earlier version of this docblock justified the cap
	 * by saying a site with this many published popups "matching one page" has an
	 * authoring problem; nothing implements a cap on popups matching a page, and
	 * the claim described a limit the code does not have. The other half of it
	 * was right and is why the query still works this way: overshooting drops
	 * popups from the response, which fails in the safe direction, because a
	 * popup that is not emitted cannot show.
	 *
	 * Failing closed is not the same as failing visibly, though, and truncation
	 * is only acceptable here because it is announced:
	 * {@see Post_Type::render_popup_limit_notice()} tells an author on the popup
	 * admin screens when their site has crossed it. A site that must raise the
	 * bound can do so through `pre_get_posts`, which reaches this query — see
	 * {@see self::query_popups()}.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_POPUPS = 100;

	/**
	 * Encoding flags for the config payload.
	 *
	 * The payload is printed inside `<script type="application/json">`, where the
	 * HTML tokenizer is still watching for `</script`, and where `<!--` or a
	 * nested `<script` opens the script-data-escaped states and can stop the
	 * *first* closing tag from ending the element. A popup slug or a stored rule
	 * value is author-supplied, so neither sequence is hypothetical.
	 *
	 * `JSON_HEX_TAG` is what makes that unrepresentable, and it is the load-bearing
	 * one: `<` and `>` are encoded as the escape sequences `<` and `>`,
	 * which `JSON.parse()` reads back as the original characters. Nothing about
	 * the data changes, and no value the payload carries can close the element
	 * early or open an escaped state inside it.
	 *
	 * The other three escape `&`, `'` and `"` where they appear inside a string,
	 * which costs a handful of bytes on characters popup targeting rarely
	 * contains and leaves the payload safe in an HTML attribute and under an XHTML
	 * parser, neither of which it is printed into today.
	 *
	 * Unicode is left escaped — the default — so the emitted bytes are pure ASCII
	 * and cannot be misread under a mis-declared charset.
	 *
	 * **These flags do not make the payload safe to pass through `esc_html()`,
	 * and it is not passed through one.** `JSON_HEX_QUOT` escapes a `"` that
	 * appears *within* a string; the quotes delimiting every key and every string
	 * in the document are structure, not content, and are emitted raw. An
	 * `esc_html()` over the result rewrites all of them to `&quot;`, nothing
	 * inside a script element decodes that, and `JSON.parse()` then fails on the
	 * whole config. Safety here comes from the encoder, not from an HTML escaper —
	 * see {@see Frontend::render()}.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

	/**
	 * Priority at which the config and markup are printed on `wp_footer`.
	 *
	 * The default, which matters here: `wp_print_footer_scripts()` runs on the
	 * same hook at priority 20, so the config element is already in the document
	 * when the bundle's script tag is written.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	private const FOOTER_PRIORITY = 10;

	/**
	 * Popups that survived server-side evaluation, or null before the query runs.
	 *
	 * Memoised per request. Null and an empty array mean different things: null is
	 * "not yet asked", the empty array is "asked, nothing matched".
	 *
	 * @since 0.1.0
	 * @var WP_Post[]|null
	 */
	private static ?array $matched = null;

	/**
	 * Whether the config and markup have already been printed this request.
	 *
	 * A handful of themes call `wp_footer()` more than once. Printing twice would
	 * duplicate the `popkit-config` element and every popup's markup, which is an
	 * accessibility failure — duplicate IDs, and two dialogs claiming the same
	 * `aria-labelledby` target — as well as wasted bytes.
	 *
	 * @since 0.1.0
	 * @var bool
	 */
	private static bool $printed = false;

	/**
	 * Not instantiable. Every member is static.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Hooks the enqueue and the footer printer.
	 *
	 * Called from `Plugin::boot()` on `plugins_loaded`, which precedes both hooks.
	 * Neither is registered conditionally: `wp_enqueue_scripts` and `wp_footer`
	 * do not fire in the admin, and everything else is decided by
	 * {@see Frontend::should_run()} at the moment the hook fires, when the query
	 * is actually available to ask.
	 *
	 * Both callbacks are array callables rather than first-class callables, for
	 * the reason given on {@see Rest_Schema::init()}: an array callable has a
	 * stable identity, so WordPress deduplicates it, a test can assert it by name,
	 * and a site owner can remove it. `self::render( ... )` would build a fresh
	 * closure on every call and would do none of those things.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( self::class, 'render' ), self::FOOTER_PRIORITY );
	}

	/**
	 * Enqueues the front-end bundle, but only when a popup survived.
	 *
	 * Pipeline stage 5. A page that matched nothing costs a visitor no bytes at
	 * all — no script, no stylesheet, no config element — and there is a
	 * Playwright assertion holding that.
	 *
	 * The script is registered for the footer *and* with the `defer` strategy.
	 * Either alone would be enough to guarantee the config element is present
	 * before the bundle runs; both together also keep the bundle from blocking
	 * the parser, and cost nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! self::should_run() ) {
			return;
		}

		if ( array() === self::matched_popups() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			POPKIT_URL . 'dist/frontend.css',
			array(),
			POPKIT_VERSION
		);

		wp_enqueue_script(
			self::HANDLE,
			POPKIT_URL . 'dist/frontend.js',
			array(),
			POPKIT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Prints the config element and each surviving popup's markup.
	 *
	 * Pipeline stage 3. Nothing is printed when nothing matched, so the element is
	 * absent rather than empty — a client that finds no config does nothing, which
	 * is also what it should do on a page where the bundle was loaded from a
	 * browser cache but this response carried no popups.
	 *
	 * The config element is printed with `wp_print_inline_script_tag()` rather than
	 * assembled here, and the choice is not cosmetic:
	 *
	 * - It escapes the attributes for us, through `wp_sanitize_script_attributes()`.
	 * - It gives a security plugin the `wp_inline_script_attributes` filter, which
	 *   is how a CSP nonce reaches an inline element it did not write. A
	 *   hand-rolled `printf()` is invisible to that filter and is exactly the
	 *   thing a strict CSP then blocks.
	 * - Its XHTML CDATA wrapping — which *would* corrupt a JSON payload — is
	 *   applied only to scripts whose type is absent or JavaScript-ish. Declaring
	 *   `application/json` skips it by the same condition that makes the element
	 *   data rather than code.
	 *
	 * The payload itself is emitted verbatim, and that is safe because of the
	 * encoder rather than in spite of it: {@see Frontend::JSON_FLAGS} makes `<`
	 * and `>` unrepresentable in the output, so no value a popup carries can close
	 * the element or open an escaped state inside it. Running an HTML escaper over
	 * the result would not add to that and would destroy the JSON — read the note
	 * on `JSON_FLAGS` before reaching for one.
	 *
	 * A `wp_json_encode()` failure — reachable only through meta written outside
	 * this plugin, carrying invalid UTF-8 — prints nothing at all rather than a
	 * malformed element. The popups are then unreachable, which is the direction
	 * this plugin fails in.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( self::$printed || ! self::should_run() ) {
			return;
		}

		$popups = self::matched_popups();

		if ( array() === $popups ) {
			return;
		}

		$json = self::config_json();

		if ( null === $json ) {
			return;
		}

		self::$printed = true;

		wp_print_inline_script_tag(
			$json,
			array(
				'type' => 'application/json',
				'id'   => self::CONFIG_ELEMENT_ID,
			)
		);

		/*
		 * Markup is Renderer's job, not this class's. The guard is what makes the
		 * two safe to build in parallel: until that class exists the config is
		 * emitted on its own, which is a page with nothing to open rather than a
		 * fatal error.
		 */
		if ( ! class_exists( Renderer::class ) ) {
			return;
		}

		foreach ( $popups as $popup ) {
			$display = self::object_meta( $popup->ID, Meta::DISPLAY, Meta::default_display() );

			/*
			 * Not escaped here, and it must not be. Renderer::render() returns
			 * markup it escaped as it built it — every attribute through
			 * esc_attr(), the close button's label through esc_html_x(), and the
			 * authored content through `the_content`, which is the same rendering
			 * path core gives any post. What comes back is real elements: a
			 * <dialog>, an inline <svg>, and the data-* attributes the runtime and
			 * the stylesheet both match on. Running wp_kses_post() over that would
			 * strip the dialog and the close icon and leave a popup nothing could
			 * open, so the annotation below states where the escaping happened
			 * rather than waving the rule away.
			 */
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at construction by Renderer::render(); see the comment above.
			echo Renderer::render( $popup, $display );
		}
	}

	/**
	 * Returns the popups that survived server-side evaluation.
	 *
	 * Pipeline stages 1 and 2. The result is memoised for the request; see
	 * {@see Frontend::reset()} for the one situation in which that has to be
	 * undone.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Post[] Surviving popups, oldest first, drawn from at most
	 *                   MAX_POPUPS candidates. See {@see self::MAX_POPUPS}.
	 */
	public static function matched_popups(): array {
		if ( null !== self::$matched ) {
			return self::$matched;
		}

		$matched = array();

		foreach ( self::query_popups() as $popup ) {
			/*
			 * A password on a popup is not a targeting mechanism and must not
			 * become one: `post_password_required()` reads a cookie, so asking it
			 * would vary the cached response by visitor. And {@see Renderer} prints
			 * the authored content through `the_content` rather than through
			 * `get_the_content()`, which is where core's password gate actually
			 * lives — so a protected popup left in the set would be published to
			 * everybody, the exact inverse of what its author asked for. It is
			 * dropped instead, which fails closed.
			 */
			if ( '' !== $popup->post_password ) {
				continue;
			}

			$conditions = self::object_meta( $popup->ID, Meta::CONDITIONS, Meta::default_conditions() );

			/*
			 * Stage 2. The evaluator is the same one {@see Frontend::popup_config()}
			 * strips with, and it has to be: Rule_Evaluator decides survival and
			 * emission through one method, so handing the two calls different
			 * evaluators would let a group fail here and be emitted there, or the
			 * reverse. Rule_Evaluator still supplies no fallback of its own —
			 * treating an unjudged rule as satisfied would pass targeting nothing
			 * had checked — so a rule this evaluator declines stays indeterminate.
			 */
			if ( ! Rule_Evaluator::rule_set_passes_server( $conditions, self::evaluate_server_rule( ... ) ) ) {
				continue;
			}

			$matched[] = $popup;
		}

		self::$matched = $matched;

		return $matched;
	}

	/**
	 * Builds the config the front-end bundle reads.
	 *
	 * The shape is `docs/data-model.md` -> Emitted frontend config, and the key
	 * order below is that document's order so a diff of the emitted JSON reads
	 * against the spec.
	 *
	 * `version` is {@see Meta::SCHEMA_VERSION} rather than the plugin version.
	 * What the client needs to know is which *shape* it is reading, and the
	 * emitted objects are the stored objects; tying the two together means a
	 * migration that changes a shape cannot forget to tell the browser.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> Config for the emitted script element.
	 */
	public static function config(): array {
		$popups = array();

		foreach ( self::matched_popups() as $popup ) {
			$popups[] = self::popup_config( $popup );
		}

		return array(
			'version'      => Meta::SCHEMA_VERSION,
			'siteTimezone' => wp_timezone_string(),
			'needsContext' => self::needs_context( $popups ),
			'restUrl'      => self::context_url(),
			'popups'       => $popups,
		);
	}

	/**
	 * Encodes the config for printing.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null JSON payload, or null when it could not be encoded.
	 */
	public static function config_json(): ?string {
		$json = wp_json_encode( self::config(), self::JSON_FLAGS );

		return is_string( $json ) ? $json : null;
	}

	/**
	 * Absolute URL of the context route.
	 *
	 * A site setting rather than a request property, and therefore safe to print
	 * into a cached response: `rest_url()` is built from the home URL, and the
	 * only part of it that varies at all — the scheme, which follows `is_ssl()` —
	 * varies with the URL being requested and not with who is requesting it.
	 *
	 * The path is {@see Rest_Context::route_path()} rather than a literal, so the
	 * URL emitted here and the route that answers it are the same string by
	 * construction. Emitting a path nothing serves would fail every popup that
	 * depends on context, silently and only in the browser.
	 *
	 * Passed through `esc_url_raw()` because `rest_url()` is filterable and the
	 * emitted value is handed straight to `fetch()`. Escaping a URL destined for
	 * JSON is not about the JSON — the encoder handles that — it is about not
	 * shipping a scheme the client should not follow.
	 *
	 * Note for the client: on a site with plain permalinks this is
	 * `…/index.php?rest_route=/popkit/v1/context`, so `fields` has to be added as
	 * a query parameter on the parsed URL rather than by appending `?fields=…`.
	 *
	 * @since 0.1.0
	 *
	 * @return string URL of `GET /wp-json/popkit/v1/context`.
	 */
	public static function context_url(): string {
		return esc_url_raw( rest_url( Rest_Context::route_path() ) );
	}

	/**
	 * Forgets the memoised match and the printed flag.
	 *
	 * A real pageview never needs this: the query runs once, the footer prints
	 * once, and the process ends. It exists for the test suite, which serves many
	 * "requests" inside one PHP process — including the exit-criterion test that
	 * renders the same URL twice and compares the two outputs byte for byte, which
	 * the printed-once guard would otherwise defeat by printing nothing the second
	 * time.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$matched = null;
		self::$printed = false;
	}

	/**
	 * Whether this response is one a popup could appear in.
	 *
	 * Deliberately shallow. Every question about *which* popup shows belongs to
	 * the targeting rules; the only thing decided here is whether the response is
	 * an HTML document a visitor reads at all.
	 *
	 * A 404 is not excluded — `is_404` is a documented targeting condition, and a
	 * "lost? here is what you were looking for" popup is one of the better uses of
	 * this plugin.
	 *
	 * Every check reads the resolved query, which is a function of the URL.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when matching and emission should proceed.
	 */
	private static function should_run(): bool {
		if ( is_admin() ) {
			return false;
		}

		// A feed is not a document, and an oEmbed iframe is somebody else's page.
		return ! is_feed() && ! is_embed();
	}

	/**
	 * Queries published popups.
	 *
	 * Pipeline stage 1. Five of these arguments are load bearing:
	 *
	 * - `post_status => 'publish'` and nothing else. `'readable'` and `'any'` both
	 *   resolve against the current user, which would make the matched set — and
	 *   therefore the emitted HTML — vary by visitor. A draft popup is invisible
	 *   to its own author on the front end, and that is correct.
	 * - `orderby => ID`, ascending. The emitted order has to be identical across
	 *   two requests to the same URL for the byte-identical exit criterion to
	 *   mean anything, and ordering by anything with ties — `post_date`, say —
	 *   leaves the tie-break to the database.
	 * - `suppress_filters => false`, matching `WP_Query`'s own default rather than
	 *   `get_posts()`'s. It is what lets a multilingual plugin scope popups to the
	 *   language of the URL being served. popkit adds no query filter of its own.
	 * - `update_post_term_cache => false`. Nothing here reads a popup's terms; the
	 *   `taxonomy_term` condition asks about the *queried* object, not about the
	 *   popup.
	 * - `posts_per_page => MAX_POPUPS`. A `LIMIT` the database applies *before*
	 *   any targeting runs, so it selects candidates rather than winners: with
	 *   more published popups than the cap, the ones past it are invisible to
	 *   this request no matter what their rules would have said. Combined with
	 *   the ascending ID order above, the popups that lose are the most recently
	 *   published — which is why {@see Post_Type::render_popup_limit_notice()}
	 *   exists to say so on the screen the author publishes from.
	 *
	 * A site that must consider more can raise the bound through `pre_get_posts`,
	 * which reaches this query because `suppress_filters` is false. A filter that
	 * does so has to preserve the other two invariants or it breaks cache safety
	 * rather than extending it: publish-only status, and a total order with no
	 * ties.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_Post[] At most MAX_POPUPS published popups, oldest first.
	 */
	private static function query_popups(): array {
		$popups = get_posts(
			array(
				'post_type'              => Post_Type::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX_POPUPS,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		return is_array( $popups ) ? $popups : array();
	}

	/**
	 * Decides one `Context::Server` rule, or declines to.
	 *
	 * Pipeline stage 2's actual judgement, and the evaluator
	 * {@see Rule_Evaluator} was built to accept. That parameter shipped with no
	 * fallback behaviour of its own so that a condition nobody had implemented
	 * could not silently pass; this method is what supplies the real thing, and
	 * {@see Frontend::matched_popups()} and {@see Frontend::popup_config()} pass
	 * this same one so survival and emission are decided identically.
	 *
	 * Each built-in registration answers for the keys it registered and returns
	 * null for every other key, which is why the chain below is `??` and the first
	 * real verdict wins. No key can be claimed twice — the registry raises on a
	 * duplicate — so only the class that registered a key ever returns a verdict
	 * for it, and the order of the chain cannot change an answer.
	 *
	 * The {@see Condition} is passed through rather than looked up again, and
	 * dispatch happens on `$condition->key` rather than on the rule's stored
	 * `type`. {@see Rule_Evaluator} has already resolved that type against the
	 * registry and already confirmed the condition declares `Context::Server`, so
	 * a rule cannot be judged by a condition it does not name.
	 *
	 * **Null is not false.** A rule this chain cannot read — unusable values, or a
	 * server-context condition registered by an extension popkit knows nothing
	 * about — is indeterminate. Its group survives, the rule is emitted, and the
	 * client denies it without applying `negate`. Answering false instead would
	 * hand `negate` a boolean to invert, and an unreadable "members of this
	 * section only" rule would become "everybody" — the failure
	 * `docs/data-model.md` names as the worst this plugin can have.
	 *
	 * ## Cache safety
	 *
	 * Everything reachable from here is a function of the URL, of the query
	 * WordPress resolved from it, and of stored content. {@see Context} is what
	 * makes that structural rather than a habit: a condition needing a cookie, a
	 * request header or the clock cannot honestly declare `Context::Server`, and
	 * {@see Rule_Evaluator} never calls this method for one that declares
	 * `Context::Client`. Nothing here reads the current user, and nothing here is
	 * printed — a rule is reduced to a boolean and the boolean is all that reaches
	 * the response.
	 *
	 * @since 0.1.0
	 *
	 * @param array     $rule      Stored rule whose type names $condition.
	 * @param Condition $condition Registered condition, guaranteed server-context.
	 * @return bool|null True or false once the rule is decided, null when it is not.
	 */
	private static function evaluate_server_rule( array $rule, Condition $condition ): ?bool {
		return Conditions\Content_Conditions::evaluate( $rule, $condition )
			?? Conditions\Url_Conditions::evaluate( $rule, $condition );
	}

	/**
	 * Builds one popup's entry in the emitted config.
	 *
	 * `conditions` is passed through {@see Rule_Evaluator::strip_server_rules()}
	 * rather than emitted as stored. Groups that failed server-side are removed so
	 * the client cannot resurrect one, and resolved server rules are removed
	 * because they have already been decided and shipping them would leak the
	 * site's targeting configuration into the page source. What is left is exactly
	 * what the browser still has to judge.
	 *
	 * It is handed {@see Frontend::evaluate_server_rule()}, the same evaluator
	 * {@see Frontend::matched_popups()} used to decide that this popup survived at
	 * all. Passing it here is not optional: without it every server rule would
	 * read as indeterminate and be emitted to a client that has no evaluator for a
	 * server-context type, which would fail the rule closed and suppress a popup
	 * the server had already said should show.
	 *
	 * Nothing is escaped here. This is data on its way to `wp_json_encode()`, and
	 * escaping belongs at output.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Post $popup Surviving popup.
	 * @return array<string, mixed> Config entry for one popup.
	 */
	private static function popup_config( WP_Post $popup ): array {
		$conditions = self::object_meta( $popup->ID, Meta::CONDITIONS, Meta::default_conditions() );

		return array(
			'id'         => (int) $popup->ID,
			'slug'       => (string) $popup->post_name,
			'conditions' => Rule_Evaluator::strip_server_rules( $conditions, self::evaluate_server_rule( ... ) ),
			'triggers'   => self::trigger_list( $popup->ID ),
			'schedule'   => self::object_meta( $popup->ID, Meta::SCHEDULE, Meta::default_schedule() ),
			'frequency'  => self::object_meta( $popup->ID, Meta::FREQUENCY, Meta::default_frequency() ),
			'display'    => self::object_meta( $popup->ID, Meta::DISPLAY, Meta::default_display() ),
		);
	}

	/**
	 * Whether any emitted popup needs the context route.
	 *
	 * Pipeline stage 4. Two things, and only two things, require it: an enabled
	 * schedule needs authoritative time, and a `user_state` rule needs login
	 * state. `device`, `referrer`, `utm` and `visit_history` are the other four
	 * client conditions and every one of them is answerable from the browser
	 * alone, so a page carrying only those — or carrying no popup at all — costs
	 * zero extra round trips. See `docs/CLAUDE.md` -> Other rules governing it,
	 * and the "Needs context" column of `docs/data-model.md` -> Built-in
	 * conditions, which this method is the implementation of.
	 *
	 * Because the test is a single literal match on the rule's `type`, adding a
	 * client condition cannot accidentally opt a page into the fetch: a new type
	 * is simply not `user_state`. The cost of getting this wrong runs both ways,
	 * which is why it is spelled out rather than inferred — a false positive
	 * spends a round trip on every page of the site for an answer nothing reads,
	 * and a false negative leaves the client with no login state, so every
	 * `user_state` rule fails closed and its popup never shows.
	 *
	 * The rules are read from the *emitted* config rather than from storage, which
	 * is the correct source and not merely a convenient one. A `user_state` rule
	 * inside a group that already failed server-side is not emitted, the client
	 * will never evaluate it, and fetching context on its account would be a round
	 * trip nobody uses. That case is genuinely reachable now that
	 * {@see Frontend::evaluate_server_rule()} resolves server rules: a group
	 * pairing `post_type` with `user_state` disappears entirely on a page the post
	 * type rule excludes, and this method must not see it.
	 *
	 * `enabled` is read loosely, and the looseness leans the way it does on
	 * purpose. Answering "yes" when the schedule is in fact off costs one request
	 * that goes unread. Answering "no" when it is on leaves the client with no
	 * authoritative clock, and every scheduled popup then fails closed and never
	 * shows — correct behaviour applied to a question that should never have been
	 * asked.
	 *
	 * @since 0.1.0
	 *
	 * @param array $popups Emitted popup entries.
	 * @return bool True when the client must fetch `/popkit/v1/context`.
	 */
	private static function needs_context( array $popups ): bool {
		foreach ( $popups as $popup ) {
			if ( ! empty( $popup['schedule']['enabled'] ) ) {
				return true;
			}

			if ( self::rule_set_needs_context( $popup['conditions'] ?? array() ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an emitted rule set carries a rule the context route answers.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $rule_set Emitted rule set.
	 * @return bool True when a `user_state` rule is present.
	 */
	private static function rule_set_needs_context( $rule_set ): bool {
		$groups = is_array( $rule_set ) ? ( $rule_set['groups'] ?? null ) : null;

		if ( ! is_array( $groups ) ) {
			return false;
		}

		foreach ( $groups as $group ) {
			$rules = is_array( $group ) ? ( $group['rules'] ?? null ) : null;

			if ( ! is_array( $rules ) ) {
				continue;
			}

			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}

				if ( self::USER_STATE_CONDITION === ( $rule['type'] ?? null ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Reads one of the object-shaped meta keys.
	 *
	 * Registered defaults already cover a popup that never wrote the key. The
	 * fallback here is for the other case: a row written outside this plugin
	 * holding something that is not an array at all. Emitting that verbatim would
	 * hand the client a config it cannot read, so the documented default stands in
	 * instead.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $popup_id Popup to read.
	 * @param string $meta_key Meta key.
	 * @param array  $fallback Value to use when storage holds something unusable.
	 * @return array<string, mixed> Stored object, or the fallback.
	 */
	private static function object_meta( int $popup_id, string $meta_key, array $fallback ): array {
		$stored = get_post_meta( $popup_id, $meta_key, true );

		return is_array( $stored ) ? $stored : $fallback;
	}

	/**
	 * Reads the trigger list.
	 *
	 * Separate from {@see Frontend::object_meta()} because this one has to encode
	 * as a JSON array. A stored list with a hole in its keys — again, only
	 * reachable through a write that did not come through {@see Meta} — would
	 * otherwise encode as an object, and the client's loop would find nothing to
	 * arm. Re-indexing changes no trigger and drops none.
	 *
	 * @since 0.1.0
	 *
	 * @param int $popup_id Popup to read.
	 * @return array Trigger configurations, as a list.
	 */
	private static function trigger_list( int $popup_id ): array {
		$stored = get_post_meta( $popup_id, Meta::TRIGGERS, true );

		if ( ! is_array( $stored ) ) {
			return Meta::default_triggers();
		}

		return array_is_list( $stored ) ? $stored : array_values( $stored );
	}
}
