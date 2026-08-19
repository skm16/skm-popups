<?php
/**
 * Integration tests for GET /popkit/v1/context.
 *
 * This file is the security gate on the whole of Phase 2. Every client-side
 * decision popkit makes about login state rests on one boolean this route
 * returns, and the route is the only code in the plugin allowed to look at the
 * visitor at all.
 *
 * ## The regression this file exists for
 *
 * `rest_cookie_check_errors()` is hooked onto `rest_authentication_errors` at
 * priority 100 by core. On a request carrying a valid logged-in cookie and no
 * `X-WP-Nonce`, it calls `wp_set_current_user( 0 )` and returns `true` — the
 * request proceeds, anonymously. popkit cannot supply a nonce here: a nonce is
 * per-user, and the HTML that would have to carry it is shared by every visitor,
 * so printing one would poison the cache with exactly the visitor-varying value
 * this route exists to avoid.
 *
 * An implementation calling `is_user_logged_in()` would therefore answer
 * `logged_out` for every visitor on earth, and every "logged-out visitors only"
 * popup would show to members. Nothing about that failure is loud: HTTP 200,
 * well-formed JSON, a plausible answer, wrong for everybody.
 *
 * {@see Test_Popkit_Rest_Context::test_a_nonceless_request_with_only_the_logged_in_cookie_reports_logged_in()}
 * reproduces the core behavior rather than describing it — it authenticates the
 * way `wp-load.php` does, serves the request through the real
 * `WP_REST_Server::serve_request()` so `rest_cookie_check_errors()` genuinely
 * runs, asserts that core did zero the current user, and only then asserts the
 * answer. Delete the cookie reading from the route and this test fails; there is
 * no way to make it pass with `is_user_logged_in()`.
 *
 * ## What else is pinned here
 *
 * - Forged, expired and orphaned cookies all answer `logged_out`.
 * - The payload carries no identity, asserted by walking the decoded structure
 *   rather than by searching its text. A string search would pass on an integer
 *   user ID and on a key nested one level down.
 * - `time` is epoch **milliseconds**. A seconds value is a 1000x error that
 *   would put every calibrated clock roughly fifty-four years in the past and
 *   silently break every schedule on the site.
 * - The field allowlist narrows and never widens.
 * - `Cache-Control: no-store, private` and `Vary: Cookie`, so an intermediary
 *   that ignores the readme still cannot share one visitor's answer with
 *   another.
 * - No `Access-Control-Allow-Origin`, so a cross-origin page cannot use the
 *   route as an oracle for whether the visitor is signed in to this site.
 * - JSONP is refused for this route and left alone for every other one, because
 *   declining `Access-Control-Allow-Origin` is not what stops a `<script src>`.
 * - The request stays anonymous: popkit never calls `wp_set_current_user()`.
 *
 * @package Popkit
 */

use Popkit\Rest_Context;
use Popkit\Rest_Schema;

/**
 * Integration coverage for Popkit\Rest_Context.
 */
final class Test_Popkit_Rest_Context extends WP_UnitTestCase {

	/**
	 * Route path, pinned literally.
	 *
	 * The front-end bundle fetches this URL and site owners exclude it from their
	 * page cache by hand, so it is a public contract in two directions at once.
	 * The constants are asserted against this literal rather than used to build
	 * it.
	 *
	 * @var string
	 */
	private const ROUTE = '/popkit/v1/context';

	/**
	 * A core route, used to prove the JSONP refusal is not site-wide.
	 *
	 * @var string
	 */
	private const CORE_ROUTE = '/wp/v2/posts';

	/**
	 * The other popkit route, used to prove the refusal is not a prefix sweep.
	 *
	 * @var string
	 */
	private const REGISTRY_ROUTE = '/popkit/v1/registry';

	/**
	 * Every key the payload is permitted to contain, at any depth.
	 *
	 * An allowlist rather than a list of forbidden names, because the property
	 * being defended is "nothing about the visitor beyond one boolean" and a
	 * denylist can only defend against the leaks somebody thought of. A new key
	 * added to the response — however innocuous — fails this and has to be
	 * argued for, which is the sign-off `docs/CLAUDE.md` -> Ask before doing
	 * requires.
	 *
	 * @var string[]
	 */
	private const PERMITTED_KEYS = array( 'time', 'user', 'state' );

	/**
	 * Identity-bearing key names, asserted absent in their own right.
	 *
	 * Redundant against PERMITTED_KEYS by construction, and kept anyway: this is
	 * the list a reviewer reads to see what the privacy contract actually
	 * promises, and it keeps failing usefully if the allowlist is ever widened.
	 *
	 * @var string[]
	 */
	private const IDENTITY_KEYS = array(
		'id',
		'user_id',
		'userid',
		'login',
		'user_login',
		'username',
		'name',
		'display_name',
		'displayname',
		'nickname',
		'first_name',
		'last_name',
		'email',
		'user_email',
		'user_nicename',
		'nicename',
		'user_url',
		'user_pass',
		'cap',
		'caps',
		'capabilities',
		'allcaps',
		'cap_key',
		'role',
		'roles',
		'nonce',
		'token',
		'session',
	);

	/**
	 * Login of the fixture user, chosen to be unmistakable in a payload.
	 *
	 * @var string
	 */
	private const USER_LOGIN = 'popkit_context_fixture';

	/**
	 * Whether Plugin::boot() had already attached the route before this class ran.
	 *
	 * Captured once, before any set_up() has had the chance to attach it as a
	 * fixture. Without that ordering the wiring assertion would be measuring this
	 * test file rather than the plugin.
	 *
	 * @var bool
	 */
	private static $booted_by_plugin = false;

	/**
	 * Fixture user, the one the logged-in cookies in this file are issued for.
	 *
	 * @var int
	 */
	private $user_id = 0;

	/**
	 * Session token backing the fixture user's cookies.
	 *
	 * @var string
	 */
	private $session_token = '';

	/**
	 * Times `set_current_user` fired while the spy was attached.
	 *
	 * @var int
	 */
	private $set_current_user_calls = 0;

	/**
	 * Records whether the plugin itself registered the route.
	 *
	 * @param WP_UnitTest_Factory $factory Shared fixture factory. Unused.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		unset( $factory );

		self::$booted_by_plugin = false !== has_action(
			'rest_api_init',
			array( Rest_Context::class, 'register_routes' )
		);
	}

	/**
	 * Builds the fixture user, a fresh REST server, and a clean request environment.
	 *
	 * `Rest_Context::init()` is called here as a fixture guarantee. WordPress keys
	 * a hook by callable identity, and the callback is a static array callable, so
	 * calling it again where `Plugin::boot()` already did replaces the entry
	 * instead of adding a second one. That keeps every behavioral test in this
	 * file about the route rather than about the boot sequence, which
	 * {@see Test_Popkit_Rest_Context::test_the_plugin_registers_the_context_route()}
	 * covers on its own.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->user_id = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'user_login'   => self::USER_LOGIN,
				'user_email'   => 'popkit-context-fixture@example.org',
				'display_name' => 'Popkit Context Fixture',
			)
		);

		$manager             = WP_Session_Tokens::get_instance( $this->user_id );
		$this->session_token = $manager->create( time() + DAY_IN_SECONDS );

		$this->reset_request_environment();

		Rest_Context::init();

		$GLOBALS['wp_rest_server'] = new Spy_REST_Server();
		do_action( 'rest_api_init', $GLOBALS['wp_rest_server'] );
	}

	/**
	 * Returns the request environment and the REST server to a known state.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->reset_request_environment();

		$GLOBALS['wp_rest_server'] = null;

		parent::tear_down();
	}

	/**
	 * The plugin, not this test file, registers the context route.
	 *
	 * Asserted from a flag captured before any fixture ran. A route this file
	 * attaches for itself would answer every other test in here perfectly while
	 * being absent from every real pageview on the site.
	 *
	 * @return void
	 */
	public function test_the_plugin_registers_the_context_route() {
		$this->assertTrue(
			self::$booted_by_plugin,
			'Plugin::boot() does not attach Rest_Context::init(). The front-end bundle fetches /popkit/v1/context on any page whose config sets needsContext, and a 404 there fails every scheduled and every user_state popup closed — correct behavior applied to a question that should have been answerable.'
		);
	}

	/**
	 * The route is registered at the documented path, as a read.
	 *
	 * `GET` alone is load bearing rather than conventional. The argument for
	 * serving this route without a nonce is that it is a read which changes
	 * nothing, so the route must not be reachable by a method that could.
	 *
	 * @return void
	 */
	public function test_the_route_is_registered_as_a_readable_route_at_the_documented_path() {
		$this->assertSame(
			self::ROUTE,
			'/' . Rest_Schema::REST_NAMESPACE . Rest_Context::ROUTE,
			'The context route moved. Its URL is a public contract twice over: the emitted config points the browser at it, and site owners exclude that exact path from their page cache by hand.'
		);

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes, 'GET /popkit/v1/context is not registered.' );

		$methods = array();

		foreach ( $routes[ self::ROUTE ] as $handler ) {
			$methods = array_merge( $methods, array_keys( (array) ( $handler['methods'] ?? array() ) ) );
		}

		$this->assertSame(
			array( 'GET' ),
			array_values( array_unique( $methods ) ),
			'The context route accepts a method other than GET. Bypassing the REST nonce is defensible here only because this route reads and writes nothing; a writable method on it removes the entire basis for that exception.'
		);
	}

	/**
	 * A nonce-less request carrying only the logged-in cookie reports logged_in.
	 *
	 * The regression test this whole file is built around. Three things happen in
	 * order, and each is asserted rather than assumed:
	 *
	 * 1. The request authenticates the way `wp-load.php` does — the cookie is
	 *    validated, which fires `auth_cookie_valid` and lets core's own
	 *    `rest_cookie_collect_status()` record that cookie authentication was
	 *    used, and the resulting user is set as current.
	 * 2. `WP_REST_Server::serve_request()` runs `check_authentication()`, so
	 *    `rest_cookie_check_errors()` genuinely executes. Finding no nonce, it
	 *    calls `wp_set_current_user( 0 )` and returns success.
	 * 3. The route answers `logged_in` anyway, because it read the cookie rather
	 *    than asking WordPress who the current user is.
	 *
	 * Step 2 is asserted explicitly. Without it a change to core's behavior, or
	 * a mistake in this fixture, would leave the current user set and the test
	 * would pass against an `is_user_logged_in()` implementation — the exact bug
	 * it exists to catch, silently uncovered.
	 *
	 * @return void
	 */
	public function test_a_nonceless_request_with_only_the_logged_in_cookie_reports_logged_in() {
		$this->authenticate_with_cookie( $this->login_cookie() );

		$this->assertTrue(
			is_user_logged_in(),
			'Fixture: the cookie must authenticate before the request is served, exactly as wp-load.php would have done.'
		);
		$this->assertArrayNotHasKey(
			'HTTP_X_WP_NONCE',
			$_SERVER,
			'Fixture: this request must carry no REST nonce. A nonce is per-user and the cached HTML that would have to carry it is shared by every visitor.'
		);

		$body = $this->serve( 'user_state' );

		$this->assertSame(
			0,
			get_current_user_id(),
			'Fixture: core did not zero the current user. rest_cookie_check_errors() is supposed to call wp_set_current_user( 0 ) on a nonce-less REST request, and this test is worthless unless it did — an is_user_logged_in() implementation would pass.'
		);
		$this->assertSame(
			array( 'user' => array( 'state' => Rest_Context::STATE_LOGGED_IN ) ),
			$this->decode( $body ),
			'The context route reported a signed-in visitor as logged out. WordPress zeroes the current user on any REST request without an X-WP-Nonce, which popkit cannot supply, so is_user_logged_in() answers logged_out for everybody — and every "logged-out visitors only" popup then shows to members, with HTTP 200 and well-formed JSON the whole way. Read $_COOKIE[ LOGGED_IN_COOKIE ] through wp_validate_auth_cookie() instead.'
		);
	}

	/**
	 * An expired cookie reports logged_out.
	 *
	 * Genuinely expired rather than malformed: the cookie is signed for a past
	 * expiration, so its HMAC verifies and only the expiry check refuses it. A
	 * test using a corrupt cookie here would pass against an implementation that
	 * had no expiry handling at all.
	 *
	 * @return void
	 */
	public function test_an_expired_cookie_reports_logged_out() {
		$expired = $this->login_cookie( time() - HOUR_IN_SECONDS );

		$this->assertFalse(
			wp_validate_auth_cookie( $expired, 'logged_in' ),
			'Fixture: the cookie must actually be expired.'
		);

		$this->set_cookie( $expired );

		$this->assertSame(
			array( 'user' => array( 'state' => Rest_Context::STATE_LOGGED_OUT ) ),
			$this->decode( $this->serve( 'user_state' ) ),
			'An expired logged-in cookie was accepted as a signed-in visitor. wp_validate_auth_cookie() refuses it; nothing in the route may accept a cookie it did not run through that function.'
		);
	}

	/**
	 * A cookie with a tampered HMAC reports logged_out.
	 *
	 * Everything but the signature is left intact — real username, real
	 * expiration, real session token — so the only thing refusing this cookie is
	 * the HMAC check.
	 *
	 * @return void
	 */
	public function test_a_cookie_with_a_tampered_hmac_reports_logged_out() {
		$forged = $this->tamper( $this->login_cookie() );

		$this->assertFalse(
			wp_validate_auth_cookie( $forged, 'logged_in' ),
			'Fixture: the forged cookie must fail validation.'
		);

		$this->set_cookie( $forged );

		$this->assertSame(
			array( 'user' => array( 'state' => Rest_Context::STATE_LOGGED_OUT ) ),
			$this->decode( $this->serve( 'user_state' ) ),
			'A cookie with a forged signature was accepted as a signed-in visitor. Anyone could then read members-only popups by writing a cookie by hand.'
		);
	}

	/**
	 * A cookie for a user who has since been deleted reports logged_out.
	 *
	 * The cookie was legitimately issued and legitimately signed; only the
	 * account behind it is gone. This is the case an implementation that parsed
	 * the cookie itself, rather than validating it, would get wrong.
	 *
	 * @return void
	 */
	public function test_a_cookie_for_a_deleted_user_reports_logged_out() {
		$cookie = $this->login_cookie();

		$this->assertSame(
			$this->user_id,
			wp_validate_auth_cookie( $cookie, 'logged_in' ),
			'Fixture: the cookie must be valid before the account is removed.'
		);

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->user_id );

		$this->set_cookie( $cookie );

		$this->assertSame(
			array( 'user' => array( 'state' => Rest_Context::STATE_LOGGED_OUT ) ),
			$this->decode( $this->serve( 'user_state' ) ),
			'A cookie belonging to a deleted account was accepted as a signed-in visitor.'
		);
	}

	/**
	 * The payload carries nothing that identifies the visitor.
	 *
	 * Walked, not searched. A text search over the encoded body would miss an
	 * integer user ID that happened to match nothing else, would miss a key
	 * nested inside `user`, and would pass on a payload whose identity leak was
	 * numeric. The walk collects every key and every scalar at every depth and
	 * asserts against both.
	 *
	 * The request is made by a genuinely signed-in visitor, because a payload
	 * built for an anonymous one has no identity available to leak.
	 *
	 * @return void
	 */
	public function test_the_payload_carries_no_identity() {
		$this->authenticate_with_cookie( $this->login_cookie() );

		$payload = $this->decode( $this->serve( 'time,user_state' ) );

		$this->assertSame(
			Rest_Context::STATE_LOGGED_IN,
			$payload['user']['state'],
			'Fixture: the payload must describe a signed-in visitor, or there is no identity available for it to leak.'
		);

		$keys    = array();
		$scalars = array();

		$this->walk( $payload, $keys, $scalars );

		$this->assertSame(
			array(),
			array_values( array_diff( $keys, self::PERMITTED_KEYS ) ),
			'The context payload carries a key outside its documented shape. It answers what time it is and whether this visitor is signed in, and nothing else, in v1 or after — adding a field requires the sign-off in docs/CLAUDE.md -> Ask before doing.'
		);

		foreach ( $keys as $key ) {
			$this->assertNotContains(
				strtolower( $key ),
				self::IDENTITY_KEYS,
				sprintf( 'The context payload carries "%s". A site owner reading their access logs must see a timestamp and a boolean, and a misconfigured cache in front of this route must leak nothing more than that.', $key )
			);
		}

		$user = get_userdata( $this->user_id );

		$identifiers = array(
			(string) $this->user_id,
			$user->user_login,
			$user->user_email,
			$user->display_name,
			$user->user_nicename,
		);

		foreach ( $scalars as $scalar ) {
			$this->assertNotContains(
				(string) $scalar,
				$identifiers,
				'A value in the context payload identifies the visitor. wp_validate_auth_cookie() returns a user ID and this route must discard it; only the boolean may survive.'
			);
		}
	}

	/**
	 * `time` is epoch milliseconds, not seconds.
	 *
	 * Asserted by magnitude as well as by presence, because a seconds value is
	 * still an integer, still plausible, and still passes any check that only
	 * looks for a number. The client calibrates its monotonic clock against this
	 * value; a seconds reading would place the corrected clock in 1970 and every
	 * schedule on the site would evaluate against it without a single error
	 * anywhere.
	 *
	 * @return void
	 */
	public function test_time_is_epoch_milliseconds() {
		$before  = (int) round( microtime( true ) * 1000 );
		$payload = $this->decode( $this->serve( 'time' ) );
		$after   = (int) round( microtime( true ) * 1000 );

		$this->assertArrayHasKey( 'time', $payload, 'A request for the time field returned no time.' );
		$this->assertIsInt( $payload['time'], 'time must travel as a JSON integer. A float would arrive at the client as one and reintroduce the sub-millisecond noise the integer removes.' );

		$this->assertGreaterThan(
			1000000000000,
			$payload['time'],
			'time is below 1e12, which means it is very probably epoch seconds rather than epoch milliseconds. That is a 1000x error: the client subtracts this value from its own monotonic clock to derive an offset, so a seconds reading puts the corrected clock roughly fifty-four years in the past and silently fails every scheduled popup on the site.'
		);
		$this->assertLessThan(
			4000000000000,
			$payload['time'],
			'time is implausibly far in the future, which usually means milliseconds were multiplied twice.'
		);

		$this->assertGreaterThanOrEqual( $before, $payload['time'], 'time predates the request that asked for it.' );
		$this->assertLessThanOrEqual( $after, $payload['time'], 'time postdates the response that carried it.' );
	}

	/**
	 * The field allowlist narrows and never widens.
	 *
	 * Four cases in one test because they are one property: the client names what
	 * it needs, the server answers exactly that, and anything unrecognized is
	 * ignored rather than rejected. Ignoring rather than erroring is what lets a
	 * newer client asking an older server for an unknown field degrade to a
	 * partial response instead of a failure.
	 *
	 * The empty cases assert against the encoded body rather than the decoded
	 * array, because PHP cannot tell an empty map from an empty list and `[]`
	 * would break the object shape every client reads.
	 *
	 * @dataProvider data_field_requests
	 *
	 * @param string|null $fields   Value of the `fields` parameter, or null to omit it.
	 * @param string[]    $expected Keys the response must carry, in order.
	 * @param string      $message  Why this case matters.
	 * @return void
	 */
	public function test_the_response_carries_exactly_the_requested_fields( $fields, $expected, $message ) {
		$body = $this->serve( $fields );

		$this->assertSame( $expected, array_keys( $this->decode( $body ) ), $message );

		if ( array() === $expected ) {
			$this->assertSame(
				'{}',
				$body,
				'A response carrying no fields must encode as an empty JSON object. PHP cannot tell an empty map from an empty list, so an unguarded empty payload serializes as "[]" and every client reading the documented object shape breaks on it.'
			);
		}
	}

	/**
	 * Field request cases.
	 *
	 * @return array<string, array{0: string|null, 1: string[], 2: string}>
	 */
	public function data_field_requests() {
		return array(
			'user_state alone omits time'       => array(
				'user_state',
				array( 'user' ),
				'A request for user_state alone came back carrying time. The client asks for exactly what it needs; a server that answers more than that makes the emitted needsContext computation pointless.',
			),
			'time alone omits user'             => array(
				'time',
				array( 'time' ),
				'A request for time alone came back carrying the visitor login state. Nothing about the visitor may be computed, let alone returned, unless it was asked for.',
			),
			'both, in the documented order'     => array(
				'time,user_state',
				array( 'time', 'user' ),
				'A request for both fields must return both, in the order docs/data-model.md declares.',
			),
			'an unrecognized name is ignored'   => array(
				'nonsense',
				array(),
				'An unrecognized field name must be ignored rather than rejected, so a newer client asking an older server for a field it has never heard of degrades to a partial response instead of a failure.',
			),
			'a hostile name cannot widen'       => array(
				'user_state,roles,capabilities,user_email',
				array( 'user' ),
				'Names outside the allowlist reached the response. A malformed or hostile fields value must only ever be able to narrow what comes back.',
			),
			'an omitted parameter returns none' => array(
				null,
				array(),
				'A request naming no fields must return nothing. The client names what it needs; there is no default set.',
			),
		);
	}

	/**
	 * The response is marked uncacheable and varying by cookie.
	 *
	 * Both directives matter and they defend different things. `no-store` keeps
	 * the answer out of shared caches and browser caches alike; `private` keeps a
	 * shared cache from holding it even if it ignored the first; `Vary: Cookie`
	 * is the fallback for an intermediary that stores it anyway, so at least one
	 * visitor's login state is never handed to another.
	 *
	 * Asserted as substrings rather than as exact values. When a request carries
	 * a valid nonce, core replaces Cache-Control with its own no-cache set —
	 * which also contains both directives — and pinning the exact string would
	 * make this test fail on a behavior that is correct.
	 *
	 * @return void
	 */
	public function test_the_response_is_marked_uncacheable_and_varying_by_cookie() {
		$this->serve( 'time,user_state' );

		$headers = $this->sent_headers();

		$this->assertArrayHasKey( 'Cache-Control', $headers, 'The context response carries no Cache-Control header. It is the one route in popkit whose answer differs per visitor and it must never be stored.' );

		$this->assertStringContainsString( 'no-store', $headers['Cache-Control'], 'Cache-Control does not contain no-store, so this response may be written to a cache.' );
		$this->assertStringContainsString( 'private', $headers['Cache-Control'], 'Cache-Control does not contain private, so a shared cache may hold one visitor\'s login state and serve it to another.' );

		$this->assertArrayHasKey( 'Vary', $headers, 'The context response carries no Vary header.' );
		$this->assertStringContainsString( 'Cookie', $headers['Vary'], 'Vary does not contain Cookie. An intermediary that stores this response despite no-store would then key it by URL alone and hand one visitor the answer computed for another.' );
	}

	/**
	 * No Access-Control-Allow-Origin header is sent.
	 *
	 * WordPress hooks `rest_send_cors_headers()` onto `rest_pre_serve_request`
	 * for every REST response, and that function echoes the request's own Origin
	 * back alongside `Access-Control-Allow-Credentials: true`. On this route that
	 * is a login-state oracle: any page anywhere could fetch it with credentials
	 * included and read whether the visitor is signed in to this site.
	 *
	 * The removal of that filter is what actually prevents it, and it is the
	 * thing asserted. Checking only for the absence of the header would pass
	 * trivially — `rest_send_cors_headers()` calls `header()` directly, which is
	 * invisible to the test server — so the header check is kept as a secondary
	 * guard against the route setting one on the response object itself.
	 *
	 * @return void
	 */
	public function test_no_cross_origin_headers_are_sent() {
		$_SERVER['HTTP_ORIGIN'] = 'https://cross-origin.example';

		$this->assertNotFalse(
			has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ),
			'Fixture: core must have attached rest_send_cors_headers before the request, or there is nothing for the route to remove and this test asserts nothing.'
		);

		$this->serve( 'user_state' );

		$this->assertFalse(
			has_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' ),
			'The context route left rest_send_cors_headers attached. It reflects the request Origin back as Access-Control-Allow-Origin with Access-Control-Allow-Credentials: true, which turns this route into a cross-origin oracle for whether the visitor is signed in to this site. Same-origin policy is the only thing standing between that and any page on the web.'
		);

		$headers = $this->sent_headers();

		$this->assertArrayNotHasKey( 'Access-Control-Allow-Origin', $headers, 'The context route set an Access-Control-Allow-Origin header of its own.' );
		$this->assertArrayNotHasKey( 'Access-Control-Allow-Credentials', $headers, 'The context route set an Access-Control-Allow-Credentials header of its own.' );
	}

	/**
	 * JSONP is refused for the context route.
	 *
	 * ## Why this is not covered by the CORS test above
	 *
	 * WordPress serves any REST route as JSONP when the request carries `_jsonp`.
	 * `rest_jsonp_enabled` defaults to true, the callback name is read straight out
	 * of `$_GET`, and the body goes back as `application/javascript`. That makes
	 * this route readable by a cross-origin script element:
	 *
	 *     <script src="https://example.org/wp-json/popkit/v1/context
	 *                  ?fields=user_state&_jsonp=steal"></script>
	 *
	 * The browser attaches the visitor's cookies, the response executes as code in
	 * the attacking page, and `steal()` is handed the login state.
	 *
	 * **Script tags are not subject to CORS.** Declining to send
	 * `Access-Control-Allow-Origin` — which
	 * {@see Test_Popkit_Rest_Context::test_no_cross_origin_headers_are_sent()}
	 * pins — stops `fetch()` and `XMLHttpRequest` and does nothing whatsoever
	 * about a `<script src>`, because JSONP is a transport that was designed to
	 * cross origins and predates CORS entirely. Same-origin policy is not a defense
	 * against it. Without the refusal asserted here, every page on the web could
	 * read whether a given visitor is signed in to this site, and the two tests
	 * together are what make the claim "this route is not a cross-origin oracle"
	 * true rather than half true.
	 *
	 * The filter is asserted to be attached before it is exercised. `apply_filters`
	 * on a hook nobody listens to returns its default unchanged, so a test that
	 * only called it would report a passing default as a passing defense.
	 *
	 * @return void
	 */
	public function test_jsonp_is_disabled_for_the_context_route() {
		$this->assertNotFalse(
			has_filter( 'rest_jsonp_enabled', array( Rest_Context::class, 'disable_jsonp' ) ),
			'Fixture: Rest_Context::init() must attach the rest_jsonp_enabled filter, or nothing below is being measured. WP_REST_Server::serve_request() reads that filter and fixes the content type before it dispatches, so the filter has to be attached at init time — one added inside the route handler is already too late.'
		);

		$this->set_rest_route( self::ROUTE );

		$this->assertFalse(
			apply_filters( 'rest_jsonp_enabled', true ),
			'JSONP is enabled for the context route. A cross-origin script element pointing at …/popkit/v1/context?fields=user_state&_jsonp=steal then executes in the attacking page with the visitor\'s cookies attached and hands it their login state. Script elements are not subject to CORS, so refusing Access-Control-Allow-Origin prevents fetch() and prevents nothing at all here.'
		);
	}

	/**
	 * JSONP survives everywhere else.
	 *
	 * The refusal is a property of one route, not of the site. A filter that
	 * returned false unconditionally would pass the test above while silently
	 * removing a transport another plugin on the site may depend on — and a filter
	 * matching by prefix would take popkit's own registry route with it, which is
	 * the mistake this second case exists to catch. Exact match on the full route
	 * path is the contract.
	 *
	 * @dataProvider data_routes_that_keep_jsonp
	 *
	 * @param string $route   Route being served.
	 * @param string $message Why this case matters.
	 * @return void
	 */
	public function test_jsonp_stays_enabled_for_every_other_route( $route, $message ) {
		$this->set_rest_route( $route );

		$this->assertTrue( apply_filters( 'rest_jsonp_enabled', true ), $message );
	}

	/**
	 * Routes whose JSONP support popkit must not touch.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_routes_that_keep_jsonp() {
		return array(
			'a core route'       => array(
				self::CORE_ROUTE,
				'popkit disabled JSONP for a core route. The scope is one popkit route, not the site: turning a transport off everywhere is a decision for the site owner, and a plugin that quietly makes it for them breaks integrations it has never heard of.',
			),
			'the registry route' => array(
				self::REGISTRY_ROUTE,
				'popkit disabled JSONP for the registry route as well, which means the route check is matching a prefix rather than the whole path. The registry is capability-gated and returns no visitor data; only the context route answers a question about who is reading it, and only the context route may be narrowed.',
			),
		);
	}

	/**
	 * Points the routing globals at a route, the way a served request would.
	 *
	 * `rest_jsonp_enabled` is handed nothing but the flag, so the only thing that
	 * can tell a listener which route is being served is
	 * `$GLOBALS['wp']->query_vars['rest_route']` — which is exactly where
	 * `rest_api_loaded()` puts it before `serve_request()` runs.
	 *
	 * @param string $route Route path, namespace included.
	 * @return void
	 */
	private function set_rest_route( $route ) {
		if ( ! isset( $GLOBALS['wp'] ) || ! $GLOBALS['wp'] instanceof WP ) {
			$GLOBALS['wp'] = new WP();
		}

		$GLOBALS['wp']->query_vars['rest_route'] = $route;
	}

	/**
	 * Serving the route never calls wp_set_current_user().
	 *
	 * Dispatched rather than served, deliberately. `serve_request()` runs core's
	 * `rest_cookie_check_errors()`, which calls `wp_set_current_user( 0 )` itself
	 * on every nonce-less REST request — that is the core behavior this route
	 * exists to work around, not a popkit call, and counting it would make the
	 * spy meaningless. `dispatch()` skips authentication, so anything the spy
	 * records during it came from popkit.
	 *
	 * The current user is set to the fixture user first. A request that stayed
	 * anonymous by luck, because nobody was signed in to begin with, would prove
	 * nothing; this asserts that the route neither promotes nor demotes whoever
	 * WordPress had already resolved.
	 *
	 * @return void
	 */
	public function test_serving_the_route_never_calls_wp_set_current_user() {
		$this->set_cookie( $this->login_cookie() );

		wp_set_current_user( $this->user_id );

		$this->set_current_user_calls = 0;

		add_action( 'set_current_user', array( $this, 'spy_on_set_current_user' ) );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'fields', 'time,user_state' );

		$response = rest_get_server()->dispatch( $request );

		remove_action( 'set_current_user', array( $this, 'spy_on_set_current_user' ) );

		$this->assertSame( 200, $response->get_status(), 'Fixture: the route must answer, or nothing was measured.' );
		$this->assertSame(
			Rest_Context::STATE_LOGGED_IN,
			$response->get_data()['user']['state'],
			'Fixture: the route must have read the cookie, or it never reached the code the spy is watching.'
		);
		$this->assertSame(
			0,
			$this->set_current_user_calls,
			'The context route called wp_set_current_user(). It must not: the request has to stay anonymous for every other purpose, so that no other code path can come to depend on this one having run and quietly acquire a visitor-varying input.'
		);
		$this->assertSame(
			$this->user_id,
			get_current_user_id(),
			'The context route changed who the current user is. wp_validate_auth_cookie() returns a user ID and this route must discard it, not act on it.'
		);
	}

	/**
	 * Counts calls to wp_set_current_user().
	 *
	 * @return void
	 */
	public function spy_on_set_current_user() {
		++$this->set_current_user_calls;
	}

	/**
	 * The permission callback is the named public one, not `__return_true`.
	 *
	 * `docs/CLAUDE.md` -> Security requires a capability check on every REST route
	 * and permits exactly one exception, which this is. The exception is expressed
	 * as a named function so the decision stays greppable: `__return_true` on a
	 * route would read as an oversight rather than as an argued choice.
	 *
	 * @return void
	 */
	public function test_the_route_is_public_through_a_named_callback() {
		$this->assertTrue(
			function_exists( 'Popkit\\popkit_context_is_public' ),
			'The named permission callback docs/CLAUDE.md calls for does not exist. A public route must say so in a function a reviewer can grep for.'
		);

		$handlers = rest_get_server()->get_routes()[ self::ROUTE ];

		foreach ( $handlers as $handler ) {
			if ( ! isset( $handler['permission_callback'] ) ) {
				continue;
			}

			$this->assertNotSame(
				'__return_true',
				$handler['permission_callback'],
				'The context route is opened with __return_true. It is public by design, but the one deliberate exception in this plugin must be expressed as a named callback so the decision is reviewable rather than looking like a mistake.'
			);
		}

		wp_set_current_user( 0 );

		$this->assertSame(
			200,
			rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) )->get_status(),
			'An anonymous request was refused. Logged-out visitors are the audience most of popkit\'s targeting describes, so a capability check here would make the route useless.'
		);
	}

	/**
	 * Builds a signed logged-in cookie for the fixture user.
	 *
	 * @param int|null $expiration Optional. Expiry as a Unix timestamp. Default one day out.
	 * @return string Cookie value.
	 */
	private function login_cookie( $expiration = null ) {
		$expiration = null === $expiration ? time() + DAY_IN_SECONDS : $expiration;

		return wp_generate_auth_cookie( $this->user_id, $expiration, 'logged_in', $this->session_token );
	}

	/**
	 * Corrupts a cookie's signature and nothing else.
	 *
	 * The username, expiration and session token are left exactly as issued, so
	 * the only reason validation can fail is the HMAC.
	 *
	 * @param string $cookie Cookie to tamper with.
	 * @return string Cookie carrying a signature that does not verify.
	 */
	private function tamper( $cookie ) {
		$parts = explode( '|', $cookie );
		$hash  = array_pop( $parts );

		$first    = substr( $hash, 0, 1 );
		$replaced = ( '0' === $first ? '1' : '0' ) . substr( $hash, 1 );

		$parts[] = $replaced;

		return implode( '|', $parts );
	}

	/**
	 * Places a cookie in the request, without authenticating.
	 *
	 * This is the state a visitor's browser puts WordPress in. Whether the cookie
	 * is any good is the route's problem, which is the point.
	 *
	 * @param string $cookie Cookie value.
	 * @return void
	 */
	private function set_cookie( $cookie ) {
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
	}

	/**
	 * Authenticates the way a real request does, then leaves the cookie in place.
	 *
	 * `wp_validate_auth_cookie()` fires `auth_cookie_valid`, which core's own
	 * `rest_cookie_collect_status()` listens for. That is what records "cookie
	 * authentication was used" in the `$wp_rest_auth_cookie` global, and without
	 * it `rest_cookie_check_errors()` returns early instead of zeroing the
	 * current user — so the regression this file exists for would not reproduce.
	 * Setting the global by hand would work too and would be a fixture asserting
	 * itself; going through the real function is what makes it a fixture
	 * asserting core.
	 *
	 * @param string $cookie Cookie value.
	 * @return void
	 */
	private function authenticate_with_cookie( $cookie ) {
		$this->set_cookie( $cookie );

		wp_set_current_user( (int) wp_validate_auth_cookie( $cookie, 'logged_in' ) );
	}

	/**
	 * Serves the route through the real request pipeline and returns the body.
	 *
	 * `serve_request()` rather than `dispatch()` wherever the answer depends on
	 * authentication, because only the former runs `check_authentication()` and
	 * therefore core's `rest_cookie_check_errors()`.
	 *
	 * @param string|null $fields Optional. Value of the `fields` parameter, or null to omit it.
	 * @return string Response body.
	 */
	private function serve( $fields = null ) {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array();

		if ( null !== $fields ) {
			$_GET['fields'] = $fields;
		}

		$server = rest_get_server();
		$server->serve_request( self::ROUTE );

		return $server->sent_body;
	}

	/**
	 * Headers the test server recorded for the last served request.
	 *
	 * @return array<string, string>
	 */
	private function sent_headers() {
		return rest_get_server()->sent_headers;
	}

	/**
	 * Decodes a response body, failing the test rather than returning null.
	 *
	 * @param string $body Response body.
	 * @return array<string, mixed> Decoded payload.
	 */
	private function decode( $body ) {
		$decoded = json_decode( $body, true );

		$this->assertIsArray(
			$decoded,
			sprintf( 'The context route returned a body that is not a JSON object: %s', $body )
		);

		return $decoded;
	}

	/**
	 * Collects every key and every scalar in a decoded payload, at any depth.
	 *
	 * @param mixed    $value   Value to walk.
	 * @param string[] $keys    Accumulated keys, by reference.
	 * @param mixed[]  $scalars Accumulated scalar values, by reference.
	 * @return void
	 */
	private function walk( $value, array &$keys, array &$scalars ) {
		if ( ! is_array( $value ) ) {
			if ( is_scalar( $value ) ) {
				$scalars[] = $value;
			}

			return;
		}

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$keys[] = $key;
			}

			$this->walk( $item, $keys, $scalars );
		}
	}

	/**
	 * Clears everything a served request reads or leaves behind.
	 *
	 * @return void
	 */
	private function reset_request_environment() {
		unset(
			$_COOKIE[ LOGGED_IN_COOKIE ],
			$_SERVER['HTTP_X_WP_NONCE'],
			$_SERVER['HTTP_ORIGIN'],
			$_REQUEST['_wpnonce']
		);

		$_GET                           = array();
		$_SERVER['REQUEST_METHOD']      = 'GET';
		$GLOBALS['wp_rest_auth_cookie'] = null;

		// Set by set_rest_route(). Left behind, it would answer the JSONP filter
		// for whichever test ran next.
		if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof WP ) {
			unset( $GLOBALS['wp']->query_vars['rest_route'] );
		}

		wp_set_current_user( 0 );
	}
}
