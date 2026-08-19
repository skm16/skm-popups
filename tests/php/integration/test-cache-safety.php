<?php
/**
 * The exit criterion that guards popkit's architecture: server output varies only by URL.
 *
 * A page cache stores one HTML response per URL and replays it to everybody who
 * asks. So every byte popkit prints must be a function of the URL and of site
 * settings, and of nothing else. If it is not, some visitor eventually receives
 * a response computed for somebody else — a members-only popup shown to the
 * public, a mobile popup on a desktop, a campaign that expired eight hours ago.
 *
 * That single rule is why `user_state`, `device`, `referrer`, `utm` and
 * `visit_history` are `Context::Client` conditions rather than server ones, and
 * why there is no `serverTime` in the emitted config. This file holds it in
 * three independent ways, because each one alone has a blind spot:
 *
 * 1. **Behavioral.** Render the same URL for several visitors who differ in
 *    every input a rendering decision could plausibly be built on, and compare
 *    the emitted bytes. This catches a violation wherever it lives, including in
 *    code this file has never heard of — but only if a fixture happens to
 *    exercise it.
 * 2. **Structural.** Walk the emitted config for a timestamp: a `serverTime`-ish
 *    key, or any value shaped like the current epoch. A timestamp is the one
 *    visitor-invariant value that is still cache-unsafe, because it is frozen at
 *    cache-fill time rather than varying per visitor — a page cached at 09:00 and
 *    served at 17:00 reports 09:00 to everyone, and no amount of elapsed script
 *    time recovers the eight hours it spent sitting in the cache. The comparison
 *    in (1) cannot see this: two renders a millisecond apart agree.
 * 3. **Source-level.** Tokenize every file under includes/conditions/ and assert
 *    that none of them *calls* the visitor-varying inputs. This catches a
 *    violation on a code path no fixture reached, which is the usual way one
 *    ships.
 *
 * ## Why the source scan tokenizes rather than greps
 *
 * The obvious implementation is a substring search, and it is the wrong one,
 * because it also fires on the paragraph above — and on the docblocks in
 * includes/conditions/, which name every banned input precisely in order to
 * explain why it is banned. A guard that forbids documenting the invariant is a
 * guard whose first maintenance action is deleting the documentation, and the
 * reason is lost long before the rule is. So the scan runs `token_get_all()` and
 * considers only call-shaped and read-shaped occurrences. Comments, docblocks and
 * string literals are separate token types and are never examined.
 *
 * The precedent is tests/php/unit/test-no-regex-invariant.php, which enforces the
 * no-regex rule the same way and for the same reason.
 *
 * `$_SERVER` is the one input that is partly allowed: `REQUEST_URI` is the URL,
 * which is exactly what a server condition may depend on, while `HTTP_REFERER`,
 * `HTTP_USER_AGENT` and their neighbors are the visitor. The scan therefore
 * reads the index rather than the variable, and a non-literal index — which could
 * resolve to anything at runtime — is an offense in its own right.
 *
 * @package Popkit
 */

use Popkit\Condition;
use Popkit\Conditions;
use Popkit\Conditions\Content_Conditions;
use Popkit\Context;
use Popkit\Frontend;
use Popkit\Meta;
use Popkit\Post_Type;

/**
 * Cache-safety coverage for everything popkit emits, and for the conditions source.
 */
final class Test_Popkit_Cache_Safety extends WP_UnitTestCase {

	/**
	 * Registry key of a client-context condition, registered as an extension would.
	 *
	 * Registered here rather than borrowed from the built-in visitor conditions so
	 * that the emission assertions describe the `Context::Client` contract itself
	 * and not one particular implementation of it.
	 *
	 * @var string
	 */
	public const CLIENT_CONDITION = 'popkit_cache_client';

	/**
	 * Slug of the popup carrying targeting, a schedule and content.
	 *
	 * @var string
	 */
	private const TARGETED_SLUG = 'cache-safety-targeted';

	/**
	 * Slug of the popup carrying no targeting at all.
	 *
	 * @var string
	 */
	private const SITEWIDE_SLUG = 'cache-safety-sitewide';

	/**
	 * Slug of the post the compared requests are made against.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'support-our-work';

	/**
	 * Permalink structure these tests run under.
	 *
	 * @var string
	 */
	private const PERMALINKS = '/%postname%/';

	/**
	 * Directory the source scan covers, relative to the plugin root.
	 *
	 * @var string
	 */
	private const SCANNED_DIR = 'includes/conditions';

	/**
	 * Functions a file under includes/conditions/ may not call.
	 *
	 * Two families, banned for two different reasons.
	 *
	 * **Visitor identity.** Each of these answers a question about who is asking
	 * rather than about what was asked for. A server condition consulting one
	 * would make the emitted HTML vary by cookie, and a page cache would then
	 * serve the first visitor's answer to everybody. `wp_validate_auth_cookie()`
	 * is included: it is the context route's tool and the route is uncached and
	 * emits no markup, but a condition reaching for it would be doing the exact
	 * thing the route exists to avoid doing here.
	 *
	 * **The clock.** A timestamp is not visitor-varying, which is what makes it
	 * easy to miss, but it is request-varying — and a cached response freezes it
	 * at fill time. Authoritative time comes from the uncached context route and
	 * is calibrated against the browser's monotonic clock; see docs/data-model.md
	 * -> Schedule -> Where `now` comes from.
	 *
	 * PHP resolves function names case-insensitively, so candidates are lowercased
	 * before they are looked up here.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_FUNCTIONS = array(
		'is_user_logged_in',
		'wp_get_current_user',
		'get_current_user_id',
		'current_user_can',
		'current_user_can_for_blog',
		'wp_get_current_commenter',
		'wp_validate_auth_cookie',
		'is_user_member_of_blog',
		'time',
		'mktime',
		'gmmktime',
		'date',
		'gmdate',
		'idate',
		'strtotime',
		'microtime',
		'hrtime',
		'getdate',
		'localtime',
		'date_i18n',
		'current_time',
		'current_datetime',
		'wp_date',
		'date_create',
		'date_create_immutable',
	);

	/**
	 * Classes a file under includes/conditions/ may not instantiate.
	 *
	 * Constructed with no argument, each reads the system clock — the same offense
	 * as calling `time()`, written as an object.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_CLASSES = array(
		'datetime',
		'datetimeimmutable',
	);

	/**
	 * Superglobals a file under includes/conditions/ may not read at all.
	 *
	 * Both describe the visitor rather than the URL, and neither has an index that
	 * would make it acceptable.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_SUPERGLOBALS = array(
		'$_COOKIE',
		'$_SESSION',
	);

	/**
	 * `$_SERVER` indices a file under includes/conditions/ may not read.
	 *
	 * `$_SERVER` is the one partly-permitted superglobal: `REQUEST_URI` is the URL
	 * itself, which is precisely what a server condition is allowed to depend on.
	 * The entries below are the visitor and the clock wearing the same array.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_SERVER_KEYS = array(
		'HTTP_REFERER',
		'HTTP_USER_AGENT',
		'HTTP_COOKIE',
		'HTTP_ACCEPT_LANGUAGE',
		'REMOTE_ADDR',
		'REQUEST_TIME',
		'REQUEST_TIME_FLOAT',
	);

	/**
	 * Emitted config keys that would mean a timestamp reached the response.
	 *
	 * Compared case-insensitively against every key at every depth. Written as a
	 * list of whole key names rather than as a substring search for "time",
	 * because `siteTimezone` is a legitimate key — it is an option, identical for
	 * every visitor — and a substring rule would forbid it.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_CONFIG_KEYS = array(
		'servertime',
		'server_time',
		'timestamp',
		'generatedat',
		'generated_at',
		'renderedat',
		'rendered_at',
		'cachedat',
		'cached_at',
		'now',
	);

	/**
	 * Token types that, immediately before an identifier, mean it is not a function call.
	 *
	 * @var int[]
	 */
	private const NON_CALL_PREFIXES = array(
		T_OBJECT_OPERATOR,
		T_NULLSAFE_OBJECT_OPERATOR,
		T_DOUBLE_COLON,
		T_FUNCTION,
		T_CONST,
	);

	/**
	 * Registers the client-context condition the emission assertions use.
	 *
	 * Attached at the bottom of this file rather than from `set_up()`. The registry
	 * is built lazily and dispatches `popkit_register_conditions` exactly once per
	 * process, tied to construction, so a callback added once a test is already
	 * running can easily arrive after the only registry it could have joined was
	 * built.
	 *
	 * @param Conditions $registry Registry to add the condition to.
	 * @return void
	 */
	public static function register_test_condition( Conditions $registry ) {
		$registry->register(
			new Condition(
				self::CLIENT_CONDITION,
				Context::Client,
				'visitor',
				'Cache safety test client condition',
				array(
					'max_width' => array(
						'type'    => 'integer',
						'default' => 600,
						'label'   => 'Maximum viewport width',
						'control' => 'number',
						'min'     => 1,
						'max'     => 5000,
					),
				)
			)
		);
	}

	/**
	 * Builds the request environment and remembers the visitor state to restore.
	 *
	 * `Meta::register()` runs because the WordPress test case unregisters every
	 * meta key in `tear_down()`; without it the fixtures below would bypass
	 * sanitization on every test after the first.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Meta::register();

		$this->saved_cookie   = $_COOKIE;
		$this->saved_agent    = $_SERVER['HTTP_USER_AGENT'] ?? null;
		$this->saved_referrer = $_SERVER['HTTP_REFERER'] ?? null;

		$this->set_permalink_structure( self::PERMALINKS );

		Frontend::init();
		Frontend::reset();
	}

	/**
	 * Restores everything a rewritten visitor touched.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_COOKIE = $this->saved_cookie;

		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_REFERER'] );

		if ( null !== $this->saved_agent ) {
			$_SERVER['HTTP_USER_AGENT'] = $this->saved_agent;
		}

		if ( null !== $this->saved_referrer ) {
			$_SERVER['HTTP_REFERER'] = $this->saved_referrer;
		}

		Frontend::reset();

		$this->set_permalink_structure( '' );

		parent::tear_down();
	}

	/**
	 * Cookies as they were before a test rewrote the visitor.
	 *
	 * @var array
	 */
	private $saved_cookie = array();

	/**
	 * User agent as it was before a test rewrote the visitor.
	 *
	 * @var string|null
	 */
	private $saved_agent = null;

	/**
	 * Referrer as it was before a test rewrote the visitor.
	 *
	 * @var string|null
	 */
	private $saved_referrer = null;

	/**
	 * The same URL emits byte-identical HTML to any visitor.
	 *
	 * The exit criterion. Three visitors differ in everything a server-side
	 * rendering decision could plausibly be built on — signed-in user, auth
	 * cookie, user agent and referrer — and one of them is anonymous. Every byte
	 * that differed between two of these renders is a byte some visitor would
	 * eventually receive from a cache having had it computed for somebody else.
	 *
	 * The whole emitted output is compared, not only the config JSON: the popup
	 * markup is where an admin-only edit link, a nonce or a personalized greeting
	 * would appear, and none of those is in the config at all.
	 *
	 * @return void
	 */
	public function test_the_emitted_bytes_are_identical_for_every_visitor() {
		$this->create_fixtures();

		$member     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$administrator = $this->emit_as_visitor(
			array(
				'user'     => $member,
				'cookies'  => array(
					LOGGED_IN_COOKIE        => wp_generate_auth_cookie( $member, time() + DAY_IN_SECONDS, 'logged_in' ),
					'wordpress_test_cookie' => 'WP Cookie check',
					'popkit_probe'          => 'first',
				),
				'agent'    => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15',
				'referrer' => 'https://example.org/newsletter',
			)
		);

		$anonymous = $this->emit_as_visitor(
			array(
				'user'     => 0,
				'cookies'  => array(),
				'agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120',
				'referrer' => '',
			)
		);

		$member_two = $this->emit_as_visitor(
			array(
				'user'     => $subscriber,
				'cookies'  => array(
					LOGGED_IN_COOKIE => wp_generate_auth_cookie( $subscriber, time() + DAY_IN_SECONDS, 'logged_in' ),
					'popkit_probe'   => 'second',
				),
				'agent'    => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/121',
				'referrer' => 'https://search.example/?q=donate',
			)
		);

		$this->assertNotSame( '', $anonymous, 'Fixture: the page must emit something, or three empty strings would compare equal and this test would assert nothing.' );
		$this->assertStringContainsString(
			'id="' . Frontend::CONFIG_ELEMENT_ID . '"',
			$anonymous,
			'Fixture: the compared output must contain the config element.'
		);
		$this->assertStringContainsString( self::TARGETED_SLUG, $anonymous, 'Fixture: the compared output must contain the targeted popup, which is the one carrying conditions and a schedule.' );
		$this->assertStringContainsString( self::SITEWIDE_SLUG, $anonymous, 'Fixture: the compared output must contain a second popup, so the comparison covers more than one entry.' );
		$this->assertStringContainsString( 'Your gift is matched', $anonymous, 'Fixture: the compared output must contain rendered popup markup, not only the config JSON.' );

		$this->assertSame(
			$administrator,
			$anonymous,
			'A signed-in administrator and an anonymous visitor received different HTML for the same URL. A page cache stores one response per URL, so whichever of them filled the cache is what everybody else receives. Nothing emitted may read the current user, a cookie, a referrer, a user agent or the clock: login state, device width, referrer, UTM and visit history are client conditions for exactly this reason.'
		);

		$this->assertSame(
			$administrator,
			$member_two,
			'Two signed-in visitors with different roles and different cookies received different HTML for the same URL. Capability or role checks in the emission path are the usual cause, and they turn the cache into a privilege-escalation surface: the first administrator to load the page fills it for every subscriber behind them.'
		);
	}

	/**
	 * The emitted config carries no timestamp, by key or by value.
	 *
	 * The popup under test has an enabled schedule, which is the one configuration
	 * that makes a timestamp look useful, so this is the case where the field
	 * would be added.
	 *
	 * Both halves are needed. A key scan misses a timestamp smuggled in under an
	 * innocent name; a value scan misses an empty or zeroed field that a later
	 * change would fill in. Neither is caught by the byte comparison above,
	 * because two renders a millisecond apart agree on the clock.
	 *
	 * @return void
	 */
	public function test_the_emitted_config_carries_no_timestamp() {
		$this->create_fixtures();

		$this->go_to( home_url( '/' . self::PAGE_SLUG . '/' ) );
		Frontend::reset();

		$config = Frontend::config();

		$this->assertTrue( $config['needsContext'], 'Fixture: the scheduled popup must be the kind that would tempt a timestamp into the config.' );
		$this->assertArrayHasKey( 'siteTimezone', $config, 'Fixture: the config must still carry the site timezone, which is an option and therefore identical for every visitor.' );

		$keys = array();
		$this->collect_keys( $config, $keys );

		foreach ( $keys as $key ) {
			$this->assertNotContains(
				strtolower( $key ),
				self::FORBIDDEN_CONFIG_KEYS,
				sprintf(
					'The emitted config carries "%s". A timestamp printed into the response is frozen at cache-fill time: a page cached at 09:00 and served at 17:00 reports 09:00 to everybody, and adding elapsed script time recovers none of the eight hours the entry spent in the cache. Authoritative time comes from the uncached context route.',
					$key
				)
			);
		}

		$scalars = array();
		$this->collect_scalars( $config, $scalars );

		foreach ( $scalars as $path => $value ) {
			$this->assertFalse(
				$this->looks_like_a_clock_reading( $value ),
				sprintf(
					'The emitted config value at %s looks like a reading of the server clock. There is no serverTime field in the config and there must not be one, whatever it is called: the value would be stale for every hit after the first and would decay silently as the cache entry aged.',
					$path
				)
			);
		}
	}

	/**
	 * The timestamp scan recognizes a clock reading, and leaves ordinary values alone.
	 *
	 * The scan above walks a config that is expected to be clean, so on its own it
	 * would pass identically against a detector that recognized nothing at all —
	 * and against a walker that never descended past the first level. Both halves
	 * are pinned here: the detector is driven with values it must catch and values
	 * it must not, and the walker is asserted to reach a key nested three levels
	 * down inside a real emitted config.
	 *
	 * The negative cases are the ones that keep this guard usable. A detector that
	 * flagged a popup ID or a viewport width would fail on every honest config,
	 * and the fix somebody reaches for is deleting the test.
	 *
	 * @return void
	 */
	public function test_the_timestamp_scan_is_not_vacuous() {
		$caught = array(
			'epoch seconds'        => time(),
			'epoch milliseconds'   => time() * 1000,
			'a float epoch'        => microtime( true ),
			'an ISO render of now' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);

		foreach ( $caught as $label => $value ) {
			$this->assertTrue(
				$this->looks_like_a_clock_reading( $value ),
				sprintf( 'The timestamp scan does not recognize %s, so it would not notice one reaching the emitted config.', $label )
			);
		}

		$ignored = array(
			'a popup ID'              => 42,
			'the schema version'      => Meta::SCHEMA_VERSION,
			'a viewport width'        => 600,
			'a scroll percent'        => 50,
			'a timezone string'       => 'America/New_York',
			'a future schedule bound' => '2027-01-01T00:00:00Z',
			'a popup slug'            => self::TARGETED_SLUG,
		);

		foreach ( $ignored as $label => $value ) {
			$this->assertFalse(
				$this->looks_like_a_clock_reading( $value ),
				sprintf( 'The timestamp scan flags %s. A guard that fails on every honest config is a guard somebody deletes.', $label )
			);
		}

		$this->create_fixtures();
		$this->go_to( home_url( '/' . self::PAGE_SLUG . '/' ) );
		Frontend::reset();

		$keys = array();
		$this->collect_keys( Frontend::config(), $keys );

		$this->assertContains(
			'popups',
			$keys,
			'The key walk did not reach the top level of the emitted config.'
		);
		$this->assertContains(
			'conditions',
			$keys,
			'The key walk did not descend into a popup entry. A timestamp added anywhere below the first level would be invisible to it.'
		);
		$this->assertContains(
			'rules',
			$keys,
			'The key walk did not descend as far as a rule. It has to reach every depth, because a stored rule value is where an unregistered condition\'s payload lands.'
		);
	}

	/**
	 * Client-context rules are emitted; resolved server-context rules are stripped.
	 *
	 * Two failures with one shape. A server rule left in the config leaks the
	 * site's targeting configuration into the page source and hands the browser a
	 * rule it has no evaluator for, which fails closed and suppresses a popup the
	 * server already said should show. A client rule stripped out leaves a group
	 * that passes unconditionally in the browser, which turns targeted at one
	 * segment into visible to everybody.
	 *
	 * @return void
	 */
	public function test_a_client_rule_is_emitted_and_a_resolved_server_rule_is_stripped() {
		$popup_id = $this->create_popup( self::TARGETED_SLUG );

		update_post_meta(
			$popup_id,
			Meta::CONDITIONS,
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => Content_Conditions::POST_TYPE,
								'negate' => false,
								'values' => array( 'types' => array( 'post' ) ),
							),
							array(
								'type'   => self::CLIENT_CONDITION,
								'negate' => false,
								'values' => array( 'max_width' => 600 ),
							),
						),
					),
				),
			)
		);

		$stored = get_post_meta( $popup_id, Meta::CONDITIONS, true );

		$this->assertCount( 2, $stored['groups'][0]['rules'], 'Fixture: both rules must survive the write, or there is nothing to strip.' );

		$this->go_to( get_permalink( $this->create_page() ) );
		Frontend::reset();

		$config = Frontend::config();

		$this->assertCount( 1, $config['popups'], 'Fixture: the popup must survive, since its server rule matches a singular post.' );

		$emitted = $config['popups'][0]['conditions']['groups'][0]['rules'];
		$types   = wp_list_pluck( $emitted, 'type' );

		$this->assertContains(
			self::CLIENT_CONDITION,
			$types,
			'A Context::Client rule was not emitted. The server cannot judge it — that is what client context means — so dropping it leaves the browser a group with nothing left to check, and a popup targeted at one segment becomes visible to everybody.'
		);

		$this->assertNotContains(
			Content_Conditions::POST_TYPE,
			$types,
			'A Context::Server rule the server had already resolved was emitted to the client. It leaks the site\'s targeting configuration into the page source, and the browser has no evaluator for a server-context type so it would fail the rule closed and suppress a popup the server said should show.'
		);
	}

	/**
	 * The tokenizer extension is present.
	 *
	 * Every source assertion below is worthless without it, and a scan that cannot
	 * tokenize returns an empty offense list — indistinguishable from a clean
	 * tree. Assert rather than skip: a cache-safety invariant that silently opts
	 * out of being checked is worse than one that was never written.
	 *
	 * @return void
	 */
	public function test_the_tokenizer_is_available() {
		$this->assertTrue(
			function_exists( 'token_get_all' ),
			'The tokenizer extension is missing, so the cache-safety source scan cannot run at all. Enable ext-tokenizer; do not skip this file.'
		);
	}

	/**
	 * The scan actually has the conditions directory to scan.
	 *
	 * A moved directory, a renamed file or a filter that matches nothing all
	 * produce a passing scan over zero files.
	 *
	 * @return void
	 */
	public function test_the_scan_covers_the_conditions_directory() {
		$files = self::php_files_under_conditions();

		$this->assertNotEmpty(
			$files,
			'No PHP files were found under ' . self::SCANNED_DIR . '/. The cache-safety scan is passing because it examined nothing.'
		);

		$this->assertContains(
			self::SCANNED_DIR . '/class-content-conditions.php',
			$files,
			'The scan did not reach the content conditions, which are five of the seven conditions the invariant covers. Either the file moved or the scan is looking in the wrong place.'
		);

		$this->assertContains(
			self::SCANNED_DIR . '/class-url-conditions.php',
			$files,
			'The scan did not reach the URL conditions. That file is the only one that reads $_SERVER at all, so it is the one whose scanning matters most.'
		);
	}

	/**
	 * No condition reads the visitor or the clock.
	 *
	 * This is the invariant. Everything else in the source half of this file
	 * exists to make this assertion trustworthy.
	 *
	 * @return void
	 */
	public function test_no_condition_reads_the_visitor_or_the_clock() {
		$offenses = array();

		foreach ( self::php_files_under_conditions() as $relative_path ) {
			$source = file_get_contents( self::plugin_dir() . '/' . $relative_path );

			$this->assertIsString(
				$source,
				sprintf( '%s could not be read, so it was never scanned.', $relative_path )
			);

			$offenses = array_merge( $offenses, self::offenses_in_source( $source, $relative_path ) );
		}

		$this->assertSame(
			array(),
			$offenses,
			'A condition reads something that varies by visitor or by request. A page cache serves one response per URL, so a server condition may depend only on the URL: everything about the visitor — login state, device, referrer, UTM, visit history — is a Context::Client condition evaluated in the browser, and authoritative time comes from the uncached context route. See docs/CLAUDE.md -> Cache safety. Offenses:'
				. PHP_EOL . implode( PHP_EOL, $offenses )
		);
	}

	/**
	 * The scan recognizes each banned construct, and says where it is.
	 *
	 * A guard nobody has watched fail is a guess. Each snippet below is a real
	 * violation written the way somebody would actually write it, and the
	 * assertion checks both that it is detected and that the message names the
	 * file and line — so whoever broke the build is not left grepping for it.
	 *
	 * @dataProvider data_detectable_violations
	 *
	 * @param string $snippet PHP source, including the opening tag.
	 * @param string $message Why this construct must be detected.
	 * @return void
	 */
	public function test_the_scan_detects_every_banned_construct( $snippet, $message ) {
		$offenses = self::offenses_in_source( $snippet, 'includes/conditions/class-fixture.php' );

		$this->assertCount( 1, $offenses, $message );
		$this->assertStringContainsString( 'includes/conditions/class-fixture.php', $offenses[0], 'A failure must name the offending file.' );
		$this->assertStringContainsString( ':2', $offenses[0], 'A failure must name the offending line.' );
	}

	/**
	 * One snippet per banned construct.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_detectable_violations() {
		return array(
			'login state'        => array(
				"<?php\n\$visible = is_user_logged_in();\n",
				'The scan missed is_user_logged_in(). It is the single call that would make every cached page report the first visitor\'s login state to everyone behind them.',
			),
			'current user'       => array(
				"<?php\n\$user = wp_get_current_user();\n",
				'The scan missed wp_get_current_user().',
			),
			'current user id'    => array(
				"<?php\n\$id = get_current_user_id();\n",
				'The scan missed get_current_user_id().',
			),
			'capability'         => array(
				"<?php\n\$allowed = current_user_can( 'edit_posts' );\n",
				'The scan missed current_user_can(). A capability check in the emission path turns the page cache into a privilege-escalation surface.',
			),
			'qualified call'     => array(
				"<?php\n\$visible = \\is_user_logged_in();\n",
				'The scan missed a fully-qualified call. That is the natural way to write one inside a namespaced file, which every condition class is.',
			),
			'upper case call'    => array(
				"<?php\n\$visible = IS_USER_LOGGED_IN();\n",
				'The scan missed an upper-case call. PHP resolves function names case-insensitively, so it reaches the same function.',
			),
			'clock'              => array(
				"<?php\n\$now = time();\n",
				'The scan missed time(). A timestamp is frozen at cache-fill time, so it is stale for every hit after the first and decays silently as the entry ages.',
			),
			'formatted clock'    => array(
				"<?php\n\$today = gmdate( 'Y-m-d' );\n",
				'The scan missed gmdate().',
			),
			'wordpress clock'    => array(
				"<?php\n\$now = current_time( 'timestamp' );\n",
				'The scan missed current_time().',
			),
			'clock as an object' => array(
				"<?php\n\$now = new DateTimeImmutable();\n",
				'The scan missed a clock read written as an object. Constructed with no argument it reads the system clock, which is the same offense as time() in a different shape.',
			),
			'cookie'             => array(
				"<?php\n\$token = \$_COOKIE['wordpress_logged_in'] ?? '';\n",
				'The scan missed a $_COOKIE read. Cookies are the visitor, and reading one is how the cached response starts varying by who filled it.',
			),
			'session'            => array(
				"<?php\n\$state = \$_SESSION['popkit'] ?? null;\n",
				'The scan missed a $_SESSION read.',
			),
			'referrer'           => array(
				"<?php\n\$from = \$_SERVER['HTTP_REFERER'] ?? '';\n",
				'The scan missed $_SERVER[\'HTTP_REFERER\']. Referrer targeting is a client condition precisely because the header is per-request.',
			),
			'user agent'         => array(
				"<?php\n\$agent = \$_SERVER['HTTP_USER_AGENT'] ?? '';\n",
				'The scan missed $_SERVER[\'HTTP_USER_AGENT\']. Device targeting is a client condition for the same reason.',
			),
			'dynamic server key' => array(
				"<?php\n\$value = \$_SERVER[ \$key ] ?? '';\n",
				'The scan missed a $_SERVER read with a computed index. The allowlist is by index, so an index nobody can read at scan time defeats it entirely.',
			),
		);
	}

	/**
	 * Prose, string literals and same-named methods are not offenses.
	 *
	 * This is the half that keeps the invariant documentable. The docblocks in
	 * includes/conditions/ name every banned input in order to explain why it is
	 * banned — that is the reviewable property the whole rule rests on — and a
	 * guard that punished writing them would guarantee they were what got deleted.
	 *
	 * `$_SERVER['REQUEST_URI']` appears here too, and its absence from the offense
	 * list is not an oversight: the request target *is* the URL, which is the one
	 * thing a server condition is allowed to depend on. A guard that forbade it
	 * would forbid `url_path`.
	 *
	 * The string literal below is a **nowdoc**, not a heredoc, and the difference
	 * is real rather than stylistic. A heredoc interpolates, so `$_COOKIE` written
	 * inside one is a genuine read of the cookie and the scan is right to flag it;
	 * a nowdoc is one opaque token and is genuinely prose.
	 *
	 * @return void
	 */
	public function test_the_scan_ignores_prose_permitted_reads_and_same_named_methods() {
		$snippet = "<?php\n"
			. "/**\n"
			. " * Nothing below calls is_user_logged_in(), wp_get_current_user(),\n"
			. " * get_current_user_id() or current_user_can(), and nothing reads \$_COOKIE,\n"
			. " * HTTP_REFERER, HTTP_USER_AGENT or the clock. A grep of this file for any of\n"
			. " * them returns nothing, and that is a reviewable property rather than a claim.\n"
			. " */\n"
			. "// Rejected during review: \$_SERVER['HTTP_REFERER'] and time().\n"
			. "# Also rejected: current_user_can( 'read' ).\n"
			. "\$documented = 'is_user_logged_in';\n"
			. "\$explained  = \"call gmdate( 'Y-m-d' ) here and the response goes stale\";\n"
			. "\$nowdoc     = <<<'TXT'\n\$_COOKIE['x'] and time()\nTXT;\n"
			. "\$path = \$_SERVER['REQUEST_URI'];\n"
			. "\$also = isset( \$_SERVER['REQUEST_URI'] ) ? \$_SERVER['REQUEST_URI'] : '';\n"
			. "\$a = \$clock->time();\n"
			. "\$b = \$clock?->time();\n"
			. "\$c = Clock::time();\n"
			. "\$d = popkit_current_user_can( 'read' );\n"
			. "\$e = \$zone = wp_timezone_string();\n";

		$this->assertSame(
			array(),
			self::offenses_in_source( $snippet, 'includes/conditions/class-fixture.php' ),
			'The scan flagged prose, a string literal, a permitted $_SERVER read, or a method that merely shares a name with a banned function. A guard that punishes documenting the invariant guarantees the documentation is what gets removed, and one that forbids reading REQUEST_URI forbids the url_path condition.'
		);
	}

	/**
	 * Returns the offending constructs in one PHP source string.
	 *
	 * Only `token_get_all()` decides what is code. Three shapes are offenses:
	 *
	 * - an identifier naming a forbidden function, followed by `(`, not preceded
	 *   by `->`, `?->`, `::`, `function` or `const`
	 * - `new` followed by an identifier naming a forbidden class
	 * - a forbidden superglobal, or `$_SERVER` indexed by a forbidden literal key
	 *   or by anything that is not a literal at all
	 *
	 * Qualified names are reduced to their final segment, so `\time()` and
	 * `Vendor\time()` are both caught — the second is not the PHP function, but a
	 * namespaced shim wearing its name is worth a human look either way.
	 *
	 * @param string $source PHP source, including the opening tag.
	 * @param string $label  Path to name in the message, relative to the plugin root.
	 * @return string[] One human-readable sentence per offense; empty when there are none.
	 */
	private static function offenses_in_source( $source, $label ) {
		$offenses = array();
		$tokens   = token_get_all( $source );
		$total    = count( $tokens );

		for ( $index = 0; $index < $total; $index++ ) {
			$token = $tokens[ $index ];

			if ( ! is_array( $token ) ) {
				continue;
			}

			if ( T_VARIABLE === $token[0] ) {
				$offense = self::superglobal_offense( $tokens, $index, $label );

				if ( '' !== $offense ) {
					$offenses[] = $offense;
				}

				continue;
			}

			if ( ! in_array( $token[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
				continue;
			}

			$name     = self::leaf_name( $token[1] );
			$previous = self::significant_index( $tokens, $index, -1 );
			$prefix   = null !== $previous && is_array( $tokens[ $previous ] ) ? $tokens[ $previous ][0] : null;

			if ( T_NEW === $prefix ) {
				if ( in_array( $name, self::FORBIDDEN_CLASSES, true ) ) {
					$offenses[] = sprintf( '  %1$s:%2$d instantiates %3$s, which reads the system clock', $label, (int) $token[2], $name );
				}

				continue;
			}

			if ( null !== $prefix && in_array( $prefix, self::NON_CALL_PREFIXES, true ) ) {
				continue;
			}

			if ( ! in_array( $name, self::FORBIDDEN_FUNCTIONS, true ) ) {
				continue;
			}

			$next = self::significant_index( $tokens, $index, 1 );

			if ( null === $next || '(' !== $tokens[ $next ] ) {
				continue;
			}

			$offenses[] = sprintf( '  %1$s:%2$d calls %3$s()', $label, (int) $token[2], $name );
		}

		return $offenses;
	}

	/**
	 * Returns the offense a superglobal read represents, or an empty string.
	 *
	 * `$_SERVER` is the only partly-permitted one, so it is the only one whose
	 * index is examined. An index that is not a literal string is an offense in
	 * itself: the allowlist works by index, and an index resolved at runtime
	 * cannot be checked at scan time.
	 *
	 * @param array  $tokens Tokens as returned by token_get_all().
	 * @param int    $index  Index of the T_VARIABLE token.
	 * @param string $label  Path to name in the message.
	 * @return string One human-readable sentence, or an empty string when the read is permitted.
	 */
	private static function superglobal_offense( array $tokens, $index, $label ) {
		$name = (string) $tokens[ $index ][1];
		$line = (int) $tokens[ $index ][2];

		if ( in_array( $name, self::FORBIDDEN_SUPERGLOBALS, true ) ) {
			return sprintf( '  %1$s:%2$d reads %3$s, which describes the visitor rather than the URL', $label, $line, $name );
		}

		if ( '$_SERVER' !== $name ) {
			return '';
		}

		$bracket = self::significant_index( $tokens, $index, 1 );

		if ( null === $bracket || '[' !== $tokens[ $bracket ] ) {
			return sprintf( '  %1$s:%2$d uses $_SERVER without a literal index, so no allowlist can vouch for what it reads', $label, $line );
		}

		$key = self::significant_index( $tokens, $bracket, 1 );

		if ( null === $key || ! is_array( $tokens[ $key ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $key ][0] ) {
			return sprintf( '  %1$s:%2$d indexes $_SERVER with something other than a literal string, so no allowlist can vouch for what it reads', $label, $line );
		}

		$index_name = strtoupper( trim( (string) $tokens[ $key ][1], "'\"" ) );

		if ( in_array( $index_name, self::FORBIDDEN_SERVER_KEYS, true ) ) {
			return sprintf( '  %1$s:%2$d reads $_SERVER[\'%3$s\'], which varies by visitor or by request', $label, $line, $index_name );
		}

		return '';
	}

	/**
	 * Reduces a possibly qualified identifier to its lowercased final segment.
	 *
	 * @param string $identifier Identifier as the tokenizer reported it.
	 * @return string Lowercased final segment.
	 */
	private static function leaf_name( $identifier ) {
		$segments = explode( '\\', (string) $identifier );

		return strtolower( (string) end( $segments ) );
	}

	/**
	 * Returns the index of the nearest token that is not whitespace or a comment.
	 *
	 * @param array $tokens    Tokens as returned by token_get_all().
	 * @param int   $from      Index to start from; not itself considered.
	 * @param int   $direction 1 to look forwards, -1 to look backwards.
	 * @return int|null Index of the neighboring significant token, or null at the end of the run.
	 */
	private static function significant_index( array $tokens, $from, $direction ) {
		$skippable = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );

		for ( $index = $from + $direction; isset( $tokens[ $index ] ); $index += $direction ) {
			if ( is_array( $tokens[ $index ] ) && in_array( $tokens[ $index ][0], $skippable, true ) ) {
				continue;
			}

			return $index;
		}

		return null;
	}

	/**
	 * Returns every PHP file under the scanned directory, as forward-slashed relative paths.
	 *
	 * Sorted, so a failure lists offenses in the same order on every machine.
	 *
	 * @return string[] Paths relative to the plugin root.
	 */
	private static function php_files_under_conditions() {
		$root = self::plugin_dir() . '/' . self::SCANNED_DIR;

		if ( ! is_dir( $root ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$path = str_replace( '\\', '/', $file->getPathname() );

			$files[] = self::SCANNED_DIR . '/' . substr( $path, strlen( str_replace( '\\', '/', $root ) ) + 1 );
		}

		sort( $files );

		return $files;
	}

	/**
	 * Returns the plugin root, forward-slashed and without a trailing slash.
	 *
	 * @return string
	 */
	private static function plugin_dir() {
		return rtrim( str_replace( '\\', '/', dirname( __DIR__, 3 ) ), '/' );
	}

	/**
	 * Whether a scalar looks like a reading of the server clock.
	 *
	 * Two shapes. A number inside the plausible epoch range, in seconds or in
	 * milliseconds — the bounds are wide enough that no popup ID, viewport width,
	 * schema version or scroll percentage can wander into them. And a string
	 * carrying today's date, which is what an ISO-8601 render of `now` looks like.
	 *
	 * @param mixed $value Scalar from the emitted config.
	 * @return bool True when the value could be a timestamp.
	 */
	private function looks_like_a_clock_reading( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			$number = (float) $value;

			if ( $number >= 1000000000.0 && $number <= 4000000000.0 ) {
				return true;
			}

			return $number >= 1000000000000.0 && $number <= 4000000000000.0;
		}

		if ( ! is_string( $value ) ) {
			return false;
		}

		return str_contains( $value, gmdate( 'Y-m-d' ) ) || str_contains( $value, (string) time() );
	}

	/**
	 * Collects every string key in a decoded structure, at any depth.
	 *
	 * @param mixed    $value Value to walk.
	 * @param string[] $keys  Accumulated keys, by reference.
	 * @return void
	 */
	private function collect_keys( $value, array &$keys ) {
		if ( ! is_array( $value ) ) {
			return;
		}

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) ) {
				$keys[] = $key;
			}

			$this->collect_keys( $item, $keys );
		}
	}

	/**
	 * Collects every scalar in a decoded structure, keyed by its path.
	 *
	 * The path is carried so a failure names the field rather than only its value.
	 *
	 * @param mixed  $value   Value to walk.
	 * @param array  $scalars Accumulated path => scalar pairs, by reference.
	 * @param string $path    Optional. Path prefix for the current level. Default 'config'.
	 * @return void
	 */
	private function collect_scalars( $value, array &$scalars, $path = 'config' ) {
		if ( ! is_array( $value ) ) {
			$scalars[ $path ] = $value;

			return;
		}

		foreach ( $value as $key => $item ) {
			$this->collect_scalars( $item, $scalars, $path . '.' . $key );
		}
	}

	/**
	 * Builds the page and the two popups the emission assertions compare.
	 *
	 * The targeted popup carries server conditions that pass, a client condition,
	 * an enabled schedule and real content, so the compared output is a full
	 * config plus rendered markup rather than an empty shell. The schedule's dates
	 * are fixed and in the future: a fixture written against today's date would
	 * make the timestamp scan below trip on its own fixture.
	 *
	 * @return void
	 */
	private function create_fixtures() {
		$page_id = $this->create_page();

		$targeted_id = $this->create_popup(
			self::TARGETED_SLUG,
			array(
				'post_title'   => 'Support our work',
				'post_content' => '<h2>Support our work</h2><p>Your gift is matched this week.</p>',
			)
		);

		$this->create_popup(
			self::SITEWIDE_SLUG,
			array(
				'post_title'   => 'Newsletter',
				'post_content' => '<h2>Newsletter</h2><p>One email a month.</p>',
			)
		);

		update_post_meta(
			$targeted_id,
			Meta::CONDITIONS,
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => Content_Conditions::POST_IDS,
								'negate' => false,
								'values' => array( 'ids' => array( $page_id ) ),
							),
							array(
								'type'   => self::CLIENT_CONDITION,
								'negate' => false,
								'values' => array( 'max_width' => 600 ),
							),
						),
					),
				),
			)
		);

		update_post_meta(
			$targeted_id,
			Meta::SCHEDULE,
			array(
				'enabled'  => true,
				'timezone' => 'site',
				'start'    => '2027-01-01T00:00:00Z',
				'end'      => '2027-01-08T00:00:00Z',
			)
		);
	}

	/**
	 * Creates the post the compared requests are made against.
	 *
	 * @return int Post ID.
	 */
	private function create_page() {
		return self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_name'    => self::PAGE_SLUG,
				'post_title'   => 'Support our work',
				'post_content' => '<p>Every gift is matched.</p>',
			)
		);
	}

	/**
	 * Creates a published popup.
	 *
	 * @param string $slug Post slug.
	 * @param array  $args Optional. Overrides for the post fixture.
	 * @return int Popup ID.
	 */
	private function create_popup( $slug, array $args = array() ) {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_name'    => $slug,
					'post_title'   => 'Cache safety fixture',
					'post_content' => '<p>Fixture content.</p>',
				),
				$args
			)
		);
	}

	/**
	 * Renders the fixture page as one visitor and returns what popkit printed.
	 *
	 * Only popkit's own output is captured. The rest of `wp_footer` belongs to
	 * core and the theme, which legitimately vary the admin bar by visitor; this
	 * plugin's contribution is the part that must not.
	 *
	 * @param array $visitor Visitor description: user, cookies, agent, referrer.
	 * @return string Emitted markup.
	 */
	private function emit_as_visitor( array $visitor ) {
		$_COOKIE                    = $visitor['cookies'];
		$_SERVER['HTTP_USER_AGENT'] = $visitor['agent'];
		$_SERVER['HTTP_REFERER']    = $visitor['referrer'];

		wp_set_current_user( $visitor['user'] );

		$this->go_to( home_url( '/' . self::PAGE_SLUG . '/' ) );
		Frontend::reset();

		ob_start();
		Frontend::render();

		return (string) ob_get_clean();
	}
}

/*
 * Attached at include time. See the note on register_test_condition(): the
 * registration action fires once per process, tied to the construction of the
 * registry rather than to any call, so a hook added from set_up() can arrive
 * after the only registry it could have joined was already built.
 */
add_action( 'popkit_register_conditions', array( Test_Popkit_Cache_Safety::class, 'register_test_condition' ) );
