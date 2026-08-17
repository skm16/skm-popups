<?php
/**
 * The uncached REST route that answers what cached HTML cannot.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Serves authoritative time and login state, and nothing else.
 *
 * ```
 * GET /wp-json/popkit/v1/context?fields=time,user_state
 * ```
 *
 * Every other decision popkit makes on the front end is a function of the URL
 * and is therefore baked into cacheable HTML. Two are not: what time it is, and
 * whether the visitor is signed in. A page cache serves one response to many
 * visitors, so neither can be printed into the markup — a timestamp embedded at
 * cache-fill time is stale for every subsequent hit, and a login flag embedded
 * at cache-fill time is simply wrong for everyone but the visitor who filled it.
 * This route is the one place either question is asked, and it is never cached.
 *
 * ## Why this route is public
 *
 * docs/CLAUDE.md -> Security requires a capability check on every REST route and
 * carves out exactly one exception: this one. It is public because the answer it
 * gives is the visitor's own, and because a capability check would make it
 * useless — logged-out visitors are precisely the audience most of the targeting
 * built on it describes.
 *
 * The permission callback is the named {@see popkit_context_is_public()} rather
 * than `__return_true` so that the decision is greppable and reviewable, and so
 * that a deliberate choice does not read as an oversight.
 *
 * ## Why bypassing the REST nonce is safe here, and nowhere else
 *
 * The route is `GET`. It writes nothing — no option, no meta, no post, no user
 * session, no transient. It returns a timestamp and one boolean. CSRF is an
 * attack that makes a victim's browser perform a *state change* on their behalf;
 * there is no state to change here, so there is nothing for a nonce to protect.
 *
 * That reasoning is specific to this route and extends to no other. Any route
 * that writes, or that returns anything the visitor could not already see, needs
 * a nonce and a capability check. Adding a second public route requires the
 * sign-off listed in docs/CLAUDE.md -> Ask before doing.
 *
 * ## Why it must not call is_user_logged_in()
 *
 * WordPress's `rest_cookie_check_errors()` calls `wp_set_current_user( 0 )` on
 * any REST request that arrives without a valid `X-WP-Nonce` and then returns
 * success: the request proceeds as anonymous rather than failing. A nonce cannot
 * be supplied to this route, because a nonce is per-user and the HTML that would
 * have to carry it is shared by every visitor — printing one would poison the
 * cache with exactly the visitor-varying value this route exists to avoid.
 *
 * So `is_user_logged_in()` would report `logged_out` for every visitor including
 * signed-in ones, and every "logged-out visitors only" popup would show to
 * members. The failure is silent: HTTP 200, well-formed JSON, plausible answer,
 * wrong for everybody. {@see self::user_state()} reads the logged-in cookie
 * directly instead.
 *
 * ## What never leaves this route
 *
 * A user ID, login, display name, email address, capability or role, in v1 or
 * after. `wp_validate_auth_cookie()` returns a user ID and this route discards
 * it; only the boolean survives. `wp_set_current_user()` is never called, so the
 * request stays anonymous for every other purpose and no other code path can
 * come to depend on this one having run.
 *
 * ## Cache and transport posture
 *
 * - `Cache-Control: no-store, private` and `Vary: Cookie` on every response.
 * - No `Access-Control-Allow-Origin`. WordPress reflects the request's `Origin`
 *   back by default; {@see self::get_context()} removes that, so same-origin
 *   policy stops a cross-origin page reading whether the visitor is signed in.
 * - Page caches must be configured to exclude `/wp-json/popkit/v1/context`. The
 *   readme documents this for the common hosts; the headers above are correct
 *   regardless of whether anyone read it.
 *
 * @since 0.1.0
 */
final class Rest_Context {

	/**
	 * Route path, relative to the namespace.
	 *
	 * The namespace itself is {@see Rest_Schema::REST_NAMESPACE}, reused rather
	 * than repeated so every popkit route is registered under one string.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const ROUTE = '/context';

	/**
	 * Requestable field: server epoch milliseconds.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const FIELD_TIME = 'time';

	/**
	 * Requestable field: whether the visitor is signed in.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const FIELD_USER_STATE = 'user_state';

	/**
	 * Every field this route knows how to answer.
	 *
	 * This is an allowlist, and it is the whole of the route's input handling.
	 * A name outside it is ignored rather than rejected, so a newer client
	 * asking an older server for a field it has never heard of degrades to a
	 * partial response instead of a failure. The consequence worth stating
	 * plainly: a malformed or hostile `fields` value can only ever *narrow* the
	 * response, never widen it.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	public const FIELDS = array( self::FIELD_TIME, self::FIELD_USER_STATE );

	/**
	 * Response value for a visitor holding a valid logged-in cookie.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const STATE_LOGGED_IN = 'logged_in';

	/**
	 * Response value for every other visitor.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const STATE_LOGGED_OUT = 'logged_out';

	/**
	 * Hooks route registration onto `rest_api_init`.
	 *
	 * Called from `Plugin::boot()` on `plugins_loaded`, which always runs before
	 * `rest_api_init`. The callback is an array callable rather than a
	 * first-class callable so the hook keeps a stable identity: WordPress
	 * deduplicates it, a test can assert it by name, and a site owner can remove
	 * it.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'rest_jsonp_enabled', array( self::class, 'disable_jsonp' ) );
	}

	/**
	 * Turns off the JSONP transport for this route only.
	 *
	 * WordPress serves any REST route as JSONP when the request carries `_jsonp`:
	 * `rest_jsonp_enabled` defaults to true, the callback name is read straight
	 * from `$_GET`, and the response goes out as `application/javascript`. That
	 * makes this route readable by a cross-origin `<script src>`:
	 *
	 *     <script src="https://example.org/wp-json/popkit/v1/context
	 *                  ?fields=user_state&_jsonp=steal"></script>
	 *
	 * The browser attaches the visitor's cookies, the response executes in the
	 * attacking page, and `steal()` receives the login state. **Script tags are
	 * not subject to CORS**, so declining to send `Access-Control-Allow-Origin`
	 * — which this class also does — prevents `fetch()` but does nothing here.
	 * Same-origin policy is not a defence against a transport that was designed
	 * to cross origins.
	 *
	 * The filter is attached at `init()` rather than inside the route handler,
	 * because `WP_REST_Server::serve_request()` reads `rest_jsonp_enabled` and
	 * fixes the content type well before it dispatches. A filter added during
	 * dispatch would already be too late, which is the trap this docblock exists
	 * to keep the next reader out of.
	 *
	 * Scoped to this route so a site relying on JSONP elsewhere keeps it.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $enabled Whether JSONP is enabled for this request.
	 * @return bool False for the context route, otherwise unchanged.
	 */
	public static function disable_jsonp( $enabled ) {
		$route = '';

		if ( isset( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		}

		if ( '' === $route && isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check on a public GET route; no state is changed.
			$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		}

		if ( '' === $route ) {
			return $enabled;
		}

		if ( untrailingslashit( $route ) === self::route_path() ) {
			return false;
		}

		return $enabled;
	}

	/**
	 * Registers the context route.
	 *
	 * `WP_REST_Server::READABLE` is `GET` alone. That is load-bearing rather than
	 * conventional: the argument for serving this route without a nonce rests on
	 * it being a read that changes nothing, so the route must not be reachable by
	 * any other method.
	 *
	 * `fields` declares a `sanitize_callback` and deliberately no
	 * `validate_callback`. Validation would turn an unrecognized value into a
	 * 400, and the contract in docs/data-model.md is that unknown names are
	 * ignored. {@see self::requested_fields()} is the real gate, and it accepts
	 * any input at all while returning only allowlisted names.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			Rest_Schema::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_context' ),
					'permission_callback' => __NAMESPACE__ . '\\popkit_context_is_public',
					'args'                => array(
						'fields' => array(
							'description'       => __( 'Comma-separated list of context fields to return. Names outside the allowlist are ignored rather than rejected.', 'popkit' ),
							'type'              => 'string',
							'default'           => '',
							'required'          => false,

							/*
							 * Belt to the allowlist's braces. sanitize_text_field()
							 * returns an empty string for an array or object value,
							 * so `fields[]=time` narrows to nothing rather than
							 * reaching the parser as a non-string.
							 */
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				'schema' => array( self::class, 'get_schema' ),
			)
		);
	}

	/**
	 * Whether this route is public.
	 *
	 * Always true. See the class docblock for the full argument; in short, this
	 * is the single public exception docs/CLAUDE.md -> Security permits, it is a
	 * `GET` that writes nothing, and it returns a timestamp and one boolean about
	 * the visitor's own session.
	 *
	 * The implementation lives here and {@see popkit_context_is_public()} calls
	 * it, so there is one decision rather than two that could drift apart.
	 *
	 * @since 0.1.0
	 *
	 * @return true Always. Never narrow this to a capability check without
	 *              understanding that logged-out visitors are the audience most
	 *              of popkit's targeting describes.
	 */
	public static function is_public(): bool {
		return true;
	}

	/**
	 * Builds the context response.
	 *
	 * Keys appear only when their field was requested. The two vocabularies are
	 * deliberately not the same: the client asks for `user_state` and receives
	 * `user.state`, so the `user` object can grow a sibling — `roles` is reserved
	 * for 1.1 — without either name having to change.
	 *
	 * Nothing here is memoized or filtered. The response is a fact about this
	 * request and this instant, and a filter that could rewrite it would be a
	 * filter that could report the wrong login state.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response Context payload, with cache headers attached.
	 */
	public static function get_context( \WP_REST_Request $request ): \WP_REST_Response {
		/*
		 * WordPress hooks rest_send_cors_headers() onto `rest_pre_serve_request`
		 * for every REST response, and that function echoes the request's own
		 * Origin back as Access-Control-Allow-Origin alongside
		 * Access-Control-Allow-Credentials: true. On this route that would be a
		 * login-state oracle: any page anywhere could fetch it with credentials
		 * included and read whether the visitor is signed in to this site.
		 *
		 * Removing the filter — rather than sending a header of our own, or
		 * unsetting one after the fact — is what actually prevents it, and it is
		 * observable in a test. The removal happens inside the handler, so it
		 * applies only to a request that dispatched this route and cannot affect
		 * any other response.
		 */
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

		$fields  = self::requested_fields( $request->get_param( 'fields' ) );
		$payload = array();

		if ( in_array( self::FIELD_TIME, $fields, true ) ) {
			$payload['time'] = self::server_time_ms();
		}

		if ( in_array( self::FIELD_USER_STATE, $fields, true ) ) {
			$payload['user'] = array( 'state' => self::user_state() );
		}

		if ( array() === $payload ) {
			add_filter( 'rest_pre_echo_response', array( self::class, 'encode_empty_payload' ), 10, 3 );
		}

		/*
		 * Constructed directly rather than through rest_ensure_response() so the
		 * return type is a response object by construction and the headers below
		 * cannot be attached to something that later turns out to be an array.
		 *
		 * Cache-Control and Vary are set on the response rather than with
		 * header(), so they travel with the object through `rest_post_dispatch`
		 * and are emitted by WP_REST_Server::send_headers() like any other
		 * response header.
		 *
		 * One WordPress behavior worth knowing about: when a request does carry a
		 * valid nonce, core replaces Cache-Control with its own no-cache header
		 * set. That set is `no-cache, must-revalidate, max-age=0, no-store,
		 * private`, so the no-store and private directives hold either way.
		 */
		$response = new \WP_REST_Response( $payload );

		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Vary', 'Cookie' );

		return $response;
	}

	/**
	 * Encodes an empty payload as `{}` rather than `[]`.
	 *
	 * PHP cannot tell an empty map from an empty list, so a request that asked
	 * for no recognized field would serialize as `[]` and break the object shape
	 * documented in docs/data-model.md -> Context endpoint payload.
	 *
	 * The obvious fix — handing `WP_REST_Response` a `stdClass` — is not safe
	 * here. `WP_REST_Server::embed_links()` reads `$data['_links']` on the
	 * response data whenever the request carries `_embed`, and array access on a
	 * `stdClass` is a fatal error in PHP 8. Correcting the shape at the last
	 * moment instead keeps the response data an array for the whole pipeline,
	 * which is what every other part of that pipeline expects.
	 *
	 * The filter is added by {@see self::get_context()} only on a request that
	 * actually produced an empty payload, and it re-checks the route, so it
	 * cannot reshape another route's response.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed            $result  Response data about to be encoded.
	 * @param \WP_REST_Server  $server  Server instance. Unused.
	 * @param \WP_REST_Request $request Request that produced the response.
	 * @return mixed Response data, as an empty object when this route returned nothing.
	 */
	public static function encode_empty_payload( $result, $server, $request ) {
		if ( array() !== $result
			|| ! $request instanceof \WP_REST_Request
			|| self::route_path() !== $request->get_route()
		) {
			return $result;
		}

		return new \stdClass();
	}

	/**
	 * Full route path, namespace included.
	 *
	 * Matches what `WP_REST_Request::get_route()` reports, so route comparisons
	 * are made against one derived string rather than a literal repeated in
	 * several places.
	 *
	 * @since 0.1.0
	 *
	 * @return string Route path, currently `/popkit/v1/context`.
	 */
	public static function route_path(): string {
		return '/' . Rest_Schema::REST_NAMESPACE . self::ROUTE;
	}

	/**
	 * Resolves a raw `fields` value to the allowlisted names it asked for.
	 *
	 * Total by design: any input, of any type, yields an array containing only
	 * names from {@see self::FIELDS}. There is nothing to reject and nothing to
	 * fail on, which is what makes "unknown names are ignored" implementable
	 * without a second code path.
	 *
	 * The loop walks the allowlist and tests membership in the request, not the
	 * other way round. That gives a canonical order and free deduplication, and
	 * it means the number of comparisons is bounded by the number of fields
	 * popkit knows about rather than by anything the caller sent.
	 *
	 * Splitting is `explode()` and `trim()`, never a pattern: docs/CLAUDE.md ->
	 * Security bans regular expressions on user input throughout the plugin, and
	 * that includes the trivial-looking ones.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $raw Raw `fields` parameter, comma separated.
	 * @return string[] Requested allowlisted field names, in the order declared
	 *                  by self::FIELDS. Empty when nothing recognizable was asked for.
	 */
	public static function requested_fields( mixed $raw ): array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$requested = array_map( 'trim', explode( ',', $raw ) );
		$fields    = array();

		foreach ( self::FIELDS as $field ) {
			if ( in_array( $field, $requested, true ) ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Server time as epoch milliseconds.
	 *
	 * Milliseconds because the client compares this against
	 * `performance.timeOrigin + performance.now()` to derive a one-time clock
	 * offset, and second resolution would put a floor under that offset's
	 * accuracy for no reason.
	 *
	 * Deliberately not `current_time()`. That function adds the site's UTC offset
	 * to produce a "local" timestamp, which is not an epoch value at all and
	 * would shift the client's clock by the offset. Schedules are compared in the
	 * site's timezone by the client, from `siteTimezone` in the emitted config;
	 * the wire value stays plain UTC epoch milliseconds.
	 *
	 * @since 0.1.0
	 *
	 * @return int Epoch milliseconds, UTC.
	 */
	private static function server_time_ms(): int {
		return (int) round( microtime( true ) * 1000 );
	}

	/**
	 * Whether the visitor holds a valid logged-in cookie.
	 *
	 * This is the only place in popkit that reads an auth cookie directly, and it
	 * exists because `is_user_logged_in()` cannot answer the question on a
	 * nonce-less REST request — see the class docblock.
	 *
	 * `wp_validate_auth_cookie()` does the whole job: HMAC verification against
	 * the user's password fragment, expiry checking, and session-token
	 * validation. A forged cookie, an expired one, one whose user has been
	 * deleted, and one whose session was destroyed by a logout elsewhere all
	 * fail. It returns a user ID; using it in a boolean context is how that ID is
	 * discarded, and it is the only thing this method does with it.
	 *
	 * `wp_unslash()` and `sanitize_text_field()` are applied in that order to
	 * satisfy the input-handling rule in docs/CLAUDE.md -> Security. Neither can
	 * alter a cookie WordPress generated: `sanitize_user()` has already stripped
	 * every character they act on — tags, whitespace runs, percent-encoded
	 * octets, quotes and backslashes — from `user_login` before the cookie was
	 * signed, and the remaining three segments are an integer and two hashes.
	 *
	 * @since 0.1.0
	 *
	 * @return string Either self::STATE_LOGGED_IN or self::STATE_LOGGED_OUT.
	 */
	private static function user_state(): string {
		/*
		 * wp_cookie_constants() runs in wp-settings.php long before any route is
		 * dispatched, so the constant is always defined on a real request. The
		 * guard is here so that calling this outside a full WordPress load is a
		 * wrong answer rather than a fatal error.
		 */
		if ( ! defined( 'LOGGED_IN_COOKIE' ) || ! isset( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return self::STATE_LOGGED_OUT;
		}

		$cookie = sanitize_text_field( wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ) );

		if ( '' === $cookie || ! wp_validate_auth_cookie( $cookie, 'logged_in' ) ) {
			return self::STATE_LOGGED_OUT;
		}

		return self::STATE_LOGGED_IN;
	}

	/**
	 * JSON Schema describing the response, for the route's `schema` callback.
	 *
	 * Makes the route self-documenting: an `OPTIONS` request returns this, so a
	 * site owner auditing what popkit sends about their visitors can read the
	 * complete answer from the API rather than from PHP.
	 *
	 * `additionalProperties` is `false` on both the payload and the `user`
	 * object. That is the privacy contract expressed as schema: there is no
	 * shape here in which a user ID, login, display name, email address,
	 * capability or role could appear. Adding one requires the sign-off in
	 * docs/CLAUDE.md -> Ask before doing.
	 *
	 * Neither property is required. Each is present only when the client asked
	 * for it, and a client that asked for nothing recognizable receives `{}`.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> JSON Schema for the context response.
	 */
	public static function get_schema(): array {
		return array(
			'$schema'              => 'http://json-schema.org/draft-04/schema#',
			'title'                => 'popkit-context',
			'description'          => __( 'Authoritative time and login state, for decisions that cannot be made in cached HTML.', 'popkit' ),
			'type'                 => 'object',
			'properties'           => array(
				'time' => array(
					'description' => __( 'Server time when the response was generated, as epoch milliseconds in UTC. Present only when requested.', 'popkit' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'user' => array(
					'description'          => __( 'What is known about the visitor, which is whether they are signed in and nothing else. Present only when requested.', 'popkit' ),
					'type'                 => 'object',
					'readonly'             => true,
					'properties'           => array(
						'state' => array(
							'description' => __( 'Whether the visitor holds a valid logged-in cookie.', 'popkit' ),
							'type'        => 'string',
							'enum'        => array( self::STATE_LOGGED_IN, self::STATE_LOGGED_OUT ),
							'readonly'    => true,
						),
					),
					'additionalProperties' => false,
				),
			),
			'additionalProperties' => false,
		);
	}
}

/*
 * The permission callback docs/CLAUDE.md -> Security names by hand.
 *
 * It belongs in includes/functions.php, which is where popkit's global functions
 * live and where it would be a genuinely global `popkit_context_is_public()`
 * rather than a namespaced one. It is declared here instead so that the route
 * and the callback that opens it ship in one reviewable file, and because
 * declaring a global function from a namespaced file requires curly-brace
 * namespace syntax, which the WordPress standard forbids outright. Moving it is
 * a one-line change on both sides whenever functions.php is next opened.
 *
 * Universal.Files.SeparateFunctionsFromOO is suppressed for exactly that reason:
 * the constitution names this function, and this is the only file that may
 * declare it.
 */
// phpcs:disable Universal.Files.SeparateFunctionsFromOO -- The constitution names this callback; see the note above.
if ( ! function_exists( __NAMESPACE__ . '\\popkit_context_is_public' ) ) {
	/**
	 * Permission callback for the context route: this route is public.
	 *
	 * Every REST route in this plugin checks a capability, per docs/CLAUDE.md ->
	 * Security, which permits one deliberate exception. This is it. The route is
	 * public because the question it answers — what time is it, and is *this*
	 * visitor signed in — is the visitor's own, and because gating it on a
	 * capability would make it useless: logged-out visitors are the audience most
	 * of popkit's targeting describes.
	 *
	 * Serving it without a nonce is safe specifically because it is a `GET`, it
	 * writes nothing, and it returns a timestamp and one boolean. CSRF protects
	 * against state changes made on a victim's behalf, and there is no state here
	 * to change. **That reasoning does not extend to any other route.**
	 *
	 * This is a named function rather than `__return_true` so the decision is
	 * greppable, reviewable, and unmistakably deliberate. A second route wanting
	 * the same exemption needs the sign-off in docs/CLAUDE.md -> Ask before
	 * doing, not a copy of this line.
	 *
	 * @since 0.1.0
	 *
	 * @return true Always.
	 */
	function popkit_context_is_public(): bool {
		return Rest_Context::is_public();
	}
}
// phpcs:enable Universal.Files.SeparateFunctionsFromOO
