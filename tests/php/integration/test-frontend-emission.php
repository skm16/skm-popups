<?php
/**
 * Integration tests for the cacheable half of the pipeline: config emission.
 *
 * Everything popkit prints into a page is a function of the URL and of site
 * settings. Nothing else. That single rule is what makes the plugin safe in
 * front of a page cache, and this file is where it is held.
 *
 * ## The exit criterion
 *
 * {@see Test_Popkit_Frontend_Emission::test_the_emitted_html_is_byte_identical_across_visitors()}
 * renders the same URL twice under different cookies, different user agents,
 * different referrers and different signed-in users, and compares the bytes. A
 * page cache serves one stored response to everyone who asks for that URL, so
 * anything that differs between those two renders is something a visitor would
 * eventually receive that was computed for somebody else. Login state and device
 * targeting are client conditions precisely so that this comparison can hold.
 *
 * ## No timestamp, ever
 *
 * There is no `serverTime` field and there will not be one. A timestamp printed
 * into the response is frozen at cache-fill time: a page cached at 09:00 and
 * served at 17:00 reports 09:00 to every visitor who reads it, and adding
 * elapsed script time recovers none of the eight hours the entry spent sitting
 * in the cache. Authoritative time comes from the uncached context route.
 *
 * ## needsContext
 *
 * The context route costs a round trip, so it is fetched only when something on
 * the page actually needs it — an enabled schedule, or a `user_state` rule. A
 * page with no popups, or with only device, referrer, UTM or visit-history
 * targeting, must set the flag false and cost nothing. That is asserted in both
 * directions here, because a flag that is always true is invisible in
 * production and a flag that is always false silently fails every scheduled
 * popup closed.
 *
 * ## Escaping
 *
 * The config is printed inside `<script type="application/json">`, where the
 * HTML tokenizer is still watching for `</script`. Stored rule values are
 * author-supplied and survive sanitization verbatim when their condition is
 * unregistered, so a closing-script sequence reaching that element is not
 * hypothetical. It must not be able to end the element early.
 *
 * @package Popkit
 */

use Popkit\Condition;
use Popkit\Conditions;
use Popkit\Context;
use Popkit\Frontend;
use Popkit\Meta;
use Popkit\Post_Type;
use Popkit\Rule_Evaluator;

/**
 * Integration coverage for Popkit\Frontend.
 */
final class Test_Popkit_Frontend_Emission extends WP_UnitTestCase {

	/**
	 * Registry key of a client-context condition, registered as an extension would.
	 *
	 * @var string
	 */
	public const CLIENT_CONDITION = 'popkit_emission_client';

	/**
	 * Registry key of a server-context condition, registered as an extension would.
	 *
	 * @var string
	 */
	public const SERVER_CONDITION = 'popkit_emission_server';

	/**
	 * A rule type nothing registers, standing in for a deactivated extension.
	 *
	 * @var string
	 */
	private const UNREGISTERED_CONDITION = 'popkit_emission_absent';

	/**
	 * Condition key the emitter must recognize without consulting the registry.
	 *
	 * `user_state` is matched as a literal by the emitter, on purpose: a rule
	 * belonging to a deactivated extension still has to be failed closed by a
	 * client that fetched context in order to judge it.
	 *
	 * @var string
	 */
	private const USER_STATE_CONDITION = 'user_state';

	/**
	 * A stored value that tries to end the config element early.
	 *
	 * Written out here rather than assembled, so what the test feeds in and what
	 * it expects back are the same literal.
	 *
	 * @var string
	 */
	private const SCRIPT_BREAKOUT = '</script><script>window.popkitEscaped = true;</script><!-- <script';

	/**
	 * Whether Plugin::boot() had already attached the emitter before this class ran.
	 *
	 * @var bool
	 */
	private static $booted_by_plugin = false;

	/**
	 * Superglobals as they were before a test started rewriting the visitor.
	 *
	 * @var array<string, mixed>
	 */
	private $saved_globals = array();

	/**
	 * Registers the two test conditions.
	 *
	 * Attached at the bottom of this file rather than from `set_up()`. Both
	 * registries are built lazily and dispatch their registration action exactly
	 * once per PHP process, tied to construction, so a callback added once a test
	 * is already running can easily arrive after the registry was built.
	 *
	 * @param Conditions $registry Registry to add conditions to.
	 * @return void
	 */
	public static function register_test_conditions( Conditions $registry ) {
		$registry->register(
			new Condition(
				self::CLIENT_CONDITION,
				Context::Client,
				'visitor',
				'Emission test client condition',
				array(
					'level' => array(
						'type'    => 'enum',
						'enum'    => array( 'bronze', 'gold' ),
						'default' => 'bronze',
						'label'   => 'Level',
						'control' => 'select',
					),
				)
			)
		);

		$registry->register(
			new Condition(
				self::SERVER_CONDITION,
				Context::Server,
				'content',
				'Emission test server condition',
				array(
					'section' => array(
						'type'    => 'string',
						'default' => '',
						'label'   => 'Section',
						'control' => 'text',
					),
				)
			)
		);
	}

	/**
	 * Records whether the plugin itself attached the emitter.
	 *
	 * Captured before any set_up() could attach it as a fixture, so the assertion
	 * measures the plugin rather than this test file.
	 *
	 * @param WP_UnitTest_Factory $factory Shared fixture factory. Unused.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		unset( $factory );

		self::$booted_by_plugin = false !== has_action( 'wp_footer', array( Frontend::class, 'render' ) )
			&& false !== has_action( 'wp_enqueue_scripts', array( Frontend::class, 'enqueue_assets' ) );
	}

	/**
	 * Clears the per-request memoization and the asset registries.
	 *
	 * `Frontend::init()` is called as a fixture guarantee. WordPress keys a hook
	 * by callable identity and both callbacks are static array callables, so
	 * calling it where `Plugin::boot()` already did replaces the entries rather
	 * than adding a second pair.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->saved_globals = array(
			'cookie'   => $_COOKIE,
			'agent'    => $_SERVER['HTTP_USER_AGENT'] ?? null,
			'referrer' => $_SERVER['HTTP_REFERER'] ?? null,
		);

		Frontend::init();
		Frontend::reset();

		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	/**
	 * Restores the request environment.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_COOKIE = $this->saved_globals['cookie'];

		unset( $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_REFERER'] );

		if ( null !== $this->saved_globals['agent'] ) {
			$_SERVER['HTTP_USER_AGENT'] = $this->saved_globals['agent'];
		}

		if ( null !== $this->saved_globals['referrer'] ) {
			$_SERVER['HTTP_REFERER'] = $this->saved_globals['referrer'];
		}

		Frontend::reset();

		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		parent::tear_down();
	}

	/**
	 * The plugin, not this test file, attaches the emitter.
	 *
	 * @return void
	 */
	public function test_the_plugin_attaches_the_frontend_emitter() {
		$this->assertTrue(
			self::$booted_by_plugin,
			'Plugin::boot() does not attach Frontend::init(). Nothing is emitted on any real pageview: no config element, no popup markup, and no assets — the whole front end is inert while every test that calls Frontend::render() by hand still passes.'
		);
	}

	/**
	 * The config element is printed exactly once when a popup survives.
	 *
	 * Twice matters: a handful of themes call `wp_footer()` more than once, and a
	 * duplicated config element means duplicated popup markup, duplicated element
	 * ids, and two dialogs claiming the same `aria-labelledby` target. So
	 * `render()` is deliberately called twice here without an intervening reset.
	 *
	 * @return void
	 */
	public function test_the_config_element_is_printed_exactly_once_when_a_popup_survives() {
		$this->create_popup();

		$this->go_to( home_url( '/' ) );
		Frontend::reset();

		ob_start();
		Frontend::render();
		Frontend::render();
		$html = (string) ob_get_clean();

		$this->assertSame(
			1,
			$this->count_config_elements( $html ),
			'The config element was not printed exactly once. A page that renders it twice duplicates every popup on it — duplicate ids, and two dialogs claiming the same accessible name — and a page that renders it never leaves the bundle nothing to read.'
		);
	}

	/**
	 * Nothing is printed on a page where no popup survives.
	 *
	 * The fixtures are a draft and a password-protected popup rather than an
	 * empty database, so the query genuinely runs and genuinely returns nothing
	 * usable. An empty table would pass against an emitter that never filtered
	 * anything.
	 *
	 * @return void
	 */
	public function test_nothing_is_printed_when_no_popup_survives() {
		$this->create_popup( array( 'post_status' => 'draft' ) );
		$this->create_popup( array( 'post_password' => 'members-only' ) );

		$this->go_to( home_url( '/' ) );
		Frontend::reset();

		$this->assertSame(
			array(),
			Frontend::matched_popups(),
			'Fixture: neither a draft nor a password-protected popup may survive server evaluation. A password gate reads a cookie, so honoring it would vary the cached response by visitor; dropping the popup is what fails closed.'
		);

		ob_start();
		Frontend::render();
		$html = (string) ob_get_clean();

		$this->assertSame(
			'',
			$html,
			'Markup was printed on a page where no popup matched. The element must be absent rather than empty, so a visitor on such a page pays no bytes at all.'
		);
	}

	/**
	 * No assets are enqueued on a page where no popup matches.
	 *
	 * Asserted with a positive control in the same test. Without one this would
	 * pass against an emitter that never enqueued anything anywhere, which is a
	 * different bug with an identical symptom here.
	 *
	 * @return void
	 */
	public function test_assets_are_enqueued_only_when_a_popup_survives() {
		$this->create_popup( array( 'post_status' => 'draft' ) );

		$this->go_to( home_url( '/' ) );
		Frontend::reset();
		Frontend::enqueue_assets();

		$this->assertFalse( wp_script_is( Frontend::HANDLE, 'enqueued' ), 'The front-end script was enqueued on a page where no popup matched. A page that matches nothing must cost a visitor no bytes.' );
		$this->assertFalse( wp_style_is( Frontend::HANDLE, 'enqueued' ), 'The front-end stylesheet was enqueued on a page where no popup matched.' );

		$this->create_popup();

		Frontend::reset();
		Frontend::enqueue_assets();

		$this->assertTrue( wp_script_is( Frontend::HANDLE, 'enqueued' ), 'Positive control: the front-end script must be enqueued once a popup survives, or the negative assertion above proves nothing.' );
		$this->assertTrue( wp_style_is( Frontend::HANDLE, 'enqueued' ), 'Positive control: the front-end stylesheet must be enqueued once a popup survives.' );
	}

	/**
	 * The context flag is set only when something on the page actually needs it.
	 *
	 * Both directions, and the false cases carry real targeting rather than none
	 * at all — a flag that is false only for an unconfigured popup would be false
	 * for nothing that ships.
	 *
	 * @dataProvider data_needs_context
	 *
	 * @param string $meta_key Meta key to write on the fixture popup.
	 * @param array  $value    Value to write.
	 * @param bool   $expected Expected needsContext.
	 * @param string $message  Why this case matters.
	 * @return void
	 */
	public function test_needs_context_is_set_only_when_a_popup_needs_it( $meta_key, $value, $expected, $message ) {
		$popup_id = $this->create_popup();

		update_post_meta( $popup_id, $meta_key, $value );

		$this->go_to( home_url( '/' ) );
		Frontend::reset();

		$config = Frontend::config();

		$this->assertCount( 1, $config['popups'], 'Fixture: the popup must survive, or needsContext is answering about an empty page.' );
		$this->assertSame( $expected, $config['needsContext'], $message );
	}

	/**
	 * Cases for the context flag.
	 *
	 * @return array<string, array{0: string, 1: array, 2: bool, 3: string}>
	 */
	public function data_needs_context() {
		return array(
			'a disabled schedule needs nothing'       => array(
				Meta::SCHEDULE,
				array( 'enabled' => false ),
				false,
				'needsContext was set for a popup whose schedule is switched off. The client would then spend a round trip on an answer nothing reads, on every page of the site.',
			),
			'device targeting needs nothing'          => array(
				Meta::CONDITIONS,
				array(
					'groups' => array(
						array(
							'rules' => array(
								array(
									'type'   => 'device',
									'values' => array( 'max_width' => 600 ),
								),
							),
						),
					),
				),
				false,
				'needsContext was set for a popup targeting device width. The browser can answer that on its own; only authoritative time and login state require the route.',
			),
			'an enabled schedule needs time'          => array(
				Meta::SCHEDULE,
				array(
					'enabled'  => true,
					'timezone' => 'site',
				),
				true,
				'needsContext was not set for a popup with an enabled schedule. The client then has no authoritative clock, so every scheduled popup fails closed and never shows — correct behavior applied to a question that should never have been asked. There is no serverTime in the config to fall back on, and there must not be.',
			),
			'a user_state rule needs the login state' => array(
				Meta::CONDITIONS,
				array(
					'groups' => array(
						array(
							'rules' => array(
								array(
									'type'   => self::USER_STATE_CONDITION,
									'values' => array( 'state' => 'logged_in' ),
								),
							),
						),
					),
				),
				true,
				'needsContext was not set for a popup carrying a user_state rule. Login state cannot be read from cached HTML — document.body.classList describes whichever visitor happened to fill the cache — so without the fetch the rule fails closed and the popup never shows.',
			),
		);
	}

	/**
	 * The emitted config carries no serverTime, at any depth.
	 *
	 * The popup here has an enabled schedule, which is the one configuration that
	 * makes a timestamp look useful. It is the case where the field would be
	 * added, so it is the case to assert against.
	 *
	 * @return void
	 */
	public function test_the_emitted_config_carries_no_server_time() {
		$popup_id = $this->create_popup();

		update_post_meta( $popup_id, Meta::SCHEDULE, array( 'enabled' => true ) );

		$this->go_to( home_url( '/' ) );
		Frontend::reset();

		$config = Frontend::config();

		$this->assertTrue( $config['needsContext'], 'Fixture: the scheduled popup must be the kind that would tempt a timestamp into the config.' );

		$keys = array();

		$this->collect_keys( $config, $keys );

		foreach ( $keys as $key ) {
			$this->assertStringNotContainsStringIgnoringCase(
				'servertime',
				$key,
				sprintf( 'The emitted config carries "%s". A timestamp printed into the response is frozen at cache-fill time: a page cached at 09:00 and served at 17:00 reports 09:00 to everybody, and adding elapsed script time recovers none of the eight hours the entry spent in the cache. Authoritative time comes from the uncached context route.', $key )
			);
		}

		$this->assertStringNotContainsStringIgnoringCase(
			'servertime',
			(string) Frontend::config_json(),
			'The encoded config mentions serverTime.'
		);

		$this->assertArrayHasKey( 'siteTimezone', $config, 'Fixture: the config must still carry the site timezone, which is an option and therefore identical for every visitor.' );
	}

	/**
	 * The emitted conditions are recomputed against the live registry.
	 *
	 * The stored meta is the author's last save; the emitted config is a
	 * statement about this request. The two differ, and this is where that is
	 * asserted rather than assumed.
	 *
	 * The fixture is the scenario `Rule_Evaluator::retag_unknown()` was written
	 * for. A rule saved while its condition was unregistered carries
	 * `unknown => true` in the database. Reactivate the plugin supplying that
	 * condition and the rule is registered, evaluable and perfectly ordinary — but
	 * a tag carried over from the earlier save would keep the client failing it
	 * closed on every pageview, the popup would stop showing, nothing would report
	 * an error, and reactivating the very plugin that should have fixed it would
	 * change nothing.
	 *
	 * The stale tag is written past the sanitizer on purpose: sanitization cannot
	 * produce this state, only time can, and a fixture that went through the
	 * sanitizer would be asserting the sanitizer instead.
	 *
	 * @return void
	 */
	public function test_the_emitted_conditions_are_recomputed_rather_than_copied_from_storage() {
		$stored = array(
			'groups' => array(
				array(
					'rules' => array(
						array(
							'type'    => self::CLIENT_CONDITION,
							'negate'  => false,
							'values'  => array( 'level' => 'gold' ),
							'unknown' => true,
						),
						array(
							'type'   => self::UNREGISTERED_CONDITION,
							'negate' => true,
							'values' => array( 'anything' => 'at all' ),
						),
					),
				),
			),
		);

		$popup_id = $this->create_popup();

		$this->write_raw_conditions( $popup_id, $stored );

		$this->assertSame(
			$stored,
			get_post_meta( $popup_id, Meta::CONDITIONS, true ),
			'Fixture: the stale tag must actually reach the database, or there is nothing stale to recompute.'
		);

		$this->go_to( home_url( '/' ) );
		Frontend::reset();

		$emitted = Frontend::config()['popups'][0]['conditions']['groups'][0]['rules'];

		$this->assertArrayNotHasKey(
			'unknown',
			$emitted[0],
			'A rule whose condition is registered right now was emitted still tagged unknown, which means the emitter shipped the stored meta rather than recomputing it. The client fails an unknown rule closed, so the popup would never show again and reactivating the plugin that supplies the condition would not fix it.'
		);
		$this->assertSame(
			array( 'level' => 'gold' ),
			$emitted[0]['values'],
			'The rule values changed on their way out. A registered condition must see byte-identical values across a deactivate and reactivate cycle.'
		);

		$this->assertTrue(
			$emitted[1]['unknown'],
			'A rule whose type nothing registers was emitted without the unknown tag. The tag is how the client knows to fail it closed and how the editor knows to flag it as unavailable; without it a deactivated extension turns "members only" into "everybody".'
		);
		$this->assertTrue(
			$emitted[1]['negate'],
			'The stored negate flag was lost. It travels with the rule; the client is what declines to apply it to something it could not evaluate.'
		);
	}

	/**
	 * A resolved server-context rule never reaches the page source.
	 *
	 * Server rules are decided on the server. Shipping them afterwards leaks the
	 * site's targeting configuration into markup anybody can read, and gives the
	 * client a rule it has no evaluator for and would therefore fail closed —
	 * suppressing a popup the server already said should show.
	 *
	 * Asserted against a real popup's real stored meta, read back from the
	 * database after a real sanitized write, with the emitted shape written out
	 * literally rather than derived from the call that produced it.
	 *
	 * One thing this does not assert, and cannot yet: {@see Frontend} supplies no
	 * evaluator, because no built-in server conditions are registered until Phase
	 * 4, and `Rule_Evaluator` deliberately has no fallback of its own — treating
	 * an unjudged rule as satisfied would pass targeting nothing had checked. So
	 * every server rule is currently indeterminate and travels to the client
	 * untouched. This test pins the contract at the boundary Frontend passes
	 * through, so that the Phase 4 change which supplies the evaluator inherits an
	 * assertion instead of needing one written for it.
	 *
	 * @return void
	 */
	public function test_a_resolved_server_context_rule_never_reaches_the_page_source() {
		$popup_id = $this->create_popup();

		update_post_meta(
			$popup_id,
			Meta::CONDITIONS,
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => self::SERVER_CONDITION,
								'negate' => false,
								'values' => array( 'section' => 'campaigns' ),
							),
							array(
								'type'   => self::CLIENT_CONDITION,
								'negate' => false,
								'values' => array( 'level' => 'gold' ),
							),
						),
					),
				),
			)
		);

		$stored = get_post_meta( $popup_id, Meta::CONDITIONS, true );

		$this->assertCount( 2, $stored['groups'][0]['rules'], 'Fixture: both rules must survive the write, or there is nothing to strip.' );

		$emitted = Rule_Evaluator::strip_server_rules(
			$stored,
			static function ( $rule ) {
				return self::SERVER_CONDITION === $rule['type'];
			}
		);

		$this->assertSame(
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => self::CLIENT_CONDITION,
								'negate' => false,
								'values' => array( 'level' => 'gold' ),
							),
						),
					),
				),
			),
			$emitted,
			'A server-context rule the server had already resolved was emitted to the client. It leaks the site\'s targeting configuration into the page source, and the client has no evaluator for it and would fail it closed — suppressing a popup the server said should show.'
		);
	}

	/**
	 * The same URL produces byte-identical HTML for any two visitors.
	 *
	 * The cache-safety exit criterion, and the reason login state and device
	 * width are client conditions rather than server ones. A page cache stores one
	 * response per URL: every byte that differs between these two renders is a
	 * byte some visitor would eventually receive that was computed for somebody
	 * else.
	 *
	 * The two visitors differ in everything a server-side rendering decision could
	 * plausibly be built on — signed-in user, auth cookie, user agent and
	 * referrer — and the popup under test carries targeting, a schedule and
	 * content, so the compared output is a full config rather than an empty one.
	 *
	 * @return void
	 */
	public function test_the_emitted_html_is_byte_identical_across_visitors() {
		$popup_id = $this->create_popup(
			array(
				'post_title'   => 'Support our work',
				'post_content' => '<h2>Support our work</h2><p>Your gift is matched this week.</p>',
			)
		);

		update_post_meta( $popup_id, Meta::SCHEDULE, array( 'enabled' => true ) );
		update_post_meta(
			$popup_id,
			Meta::CONDITIONS,
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => self::USER_STATE_CONDITION,
								'values' => array( 'state' => 'logged_out' ),
							),
						),
					),
				),
			)
		);

		$member = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$signed_in = $this->emit_as_visitor(
			array(
				'user'     => $member,
				'cookies'  => array(
					LOGGED_IN_COOKIE        => wp_generate_auth_cookie( $member, time() + DAY_IN_SECONDS, 'logged_in' ),
					'wordpress_test_cookie' => 'WP Cookie check',
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
				'referrer' => 'https://search.example/?q=donate',
			)
		);

		$this->assertNotSame( '', $signed_in, 'Fixture: the page must actually emit something, or two empty strings would compare equal and this test would assert nothing.' );
		$this->assertSame( 1, $this->count_config_elements( $signed_in ), 'Fixture: the compared output must contain the config element.' );
		$this->assertStringContainsString( 'Support our work', $signed_in, 'Fixture: the compared output must contain the popup markup as well as the config.' );

		$this->assertSame(
			$signed_in,
			$anonymous,
			'Two requests to the same URL produced different HTML. A page cache stores one response per URL, so every differing byte is one that some visitor eventually receives having been computed for somebody else. Nothing emitted may read the current user, a cookie, a referrer, a user agent or the clock: login state and device width are client conditions for exactly this reason.'
		);
	}

	/**
	 * A closing-script sequence in a stored value cannot end the config element.
	 *
	 * A rule whose condition is unregistered has its values preserved verbatim, by
	 * design — dropping them would destroy the author's targeting and widen the
	 * audience on reactivation. So author-supplied bytes really do reach the
	 * config element, and the element really is parsed by a tokenizer still
	 * watching for `</script`.
	 *
	 * Both halves are asserted: the sequence cannot break out, *and* the value
	 * survives the round trip unchanged. An encoder that escaped by mangling
	 * would pass the first half and quietly corrupt every targeting rule that
	 * contained an angle bracket.
	 *
	 * @return void
	 */
	public function test_a_closing_script_sequence_in_a_stored_value_cannot_break_out_of_the_config_element() {
		$popup_id = $this->create_popup();

		update_post_meta(
			$popup_id,
			Meta::CONDITIONS,
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => self::UNREGISTERED_CONDITION,
								'negate' => false,
								'values' => array( 'note' => self::SCRIPT_BREAKOUT ),
							),
						),
					),
				),
			)
		);

		$this->assertSame(
			self::SCRIPT_BREAKOUT,
			get_post_meta( $popup_id, Meta::CONDITIONS, true )['groups'][0]['rules'][0]['values']['note'],
			'Fixture: the hostile value must reach the database intact, or the emitter is never handed anything dangerous.'
		);

		$this->go_to( home_url( '/' ) );
		Frontend::reset();

		ob_start();
		Frontend::render();
		$html = (string) ob_get_clean();

		$this->assertSame(
			1,
			$this->count_script_elements( $html ),
			'The emitted page contains more than one script element. The config payload broke out of its own element and the injected markup is now live script on every page this popup is emitted on.'
		);

		$raw = $this->raw_config_payload( $html );

		$this->assertStringNotContainsString( '<', $raw, 'The encoded config contains a literal "<". JSON_HEX_TAG is what makes an early "</script" unrepresentable, and it is the load-bearing flag on this payload.' );
		$this->assertStringNotContainsString( '>', $raw, 'The encoded config contains a literal ">".' );

		$decoded = json_decode( $this->config_element_text( $html ), true );

		$this->assertIsArray( $decoded, 'The config element does not contain parseable JSON, which is what a payload that ended its own element early looks like from the parser\'s side.' );
		$this->assertSame(
			self::SCRIPT_BREAKOUT,
			$decoded['popups'][0]['conditions']['groups'][0]['rules'][0]['values']['note'],
			'The stored value did not survive encoding unchanged. Escaping the payload with an HTML escaper rather than the JSON encoder is the usual cause, and it corrupts every rule value containing an angle bracket while still looking safe.'
		);
	}

	/**
	 * Creates a published popup.
	 *
	 * @param array $args Optional. Overrides for the post fixture.
	 * @return int Popup ID.
	 */
	private function create_popup( array $args = array() ) {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => 'Emission fixture',
					'post_content' => '<p>Fixture content.</p>',
				),
				$args
			)
		);
	}

	/**
	 * Writes a rule set past the registered sanitizer.
	 *
	 * Used for one fixture only: a rule carrying a stale `unknown` tag, which is a
	 * state sanitization cannot produce and only the passage of time can. The
	 * override is added at a later priority than the registered callback and
	 * removed immediately, so nothing else in the process sees it.
	 *
	 * @param int   $popup_id Popup to write to.
	 * @param array $rule_set Rule set to store verbatim.
	 * @return void
	 */
	private function write_raw_conditions( $popup_id, array $rule_set ) {
		$filter      = 'sanitize_post_meta_' . Meta::CONDITIONS . '_for_' . Post_Type::POST_TYPE;
		$passthrough = static function () use ( $rule_set ) {
			return $rule_set;
		};

		add_filter( $filter, $passthrough, 20 );
		update_post_meta( $popup_id, Meta::CONDITIONS, $rule_set );
		remove_filter( $filter, $passthrough, 20 );
	}

	/**
	 * Renders the front page as a given visitor and returns what popkit printed.
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

		$this->go_to( home_url( '/' ) );
		Frontend::reset();

		ob_start();
		Frontend::render();

		return (string) ob_get_clean();
	}

	/**
	 * Parses emitted markup the way a browser would.
	 *
	 * @param string $html Emitted markup.
	 * @return DOMXPath Query interface over the parsed document.
	 */
	private function parse( $html ) {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );

		$document->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>' );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return new DOMXPath( $document );
	}

	/**
	 * Counts config elements in emitted markup.
	 *
	 * @param string $html Emitted markup.
	 * @return int Number of matching elements.
	 */
	private function count_config_elements( $html ) {
		return $this->parse( $html )->query( '//script[@id="' . Frontend::CONFIG_ELEMENT_ID . '"]' )->length;
	}

	/**
	 * Counts every script element in emitted markup.
	 *
	 * @param string $html Emitted markup.
	 * @return int Number of script elements the parser found.
	 */
	private function count_script_elements( $html ) {
		return $this->parse( $html )->query( '//script' )->length;
	}

	/**
	 * Text content of the config element, as the parser sees it.
	 *
	 * @param string $html Emitted markup.
	 * @return string Payload text, empty when the element is absent.
	 */
	private function config_element_text( $html ) {
		$nodes = $this->parse( $html )->query( '//script[@id="' . Frontend::CONFIG_ELEMENT_ID . '"]' );

		return 0 === $nodes->length ? '' : $nodes->item( 0 )->textContent;
	}

	/**
	 * The encoded payload as it appears in the emitted bytes.
	 *
	 * Read out of the raw markup rather than out of the parsed document, because
	 * the assertions using it are about what the tokenizer is handed rather than
	 * about what it made of it.
	 *
	 * @param string $html Emitted markup.
	 * @return string Bytes between the config element's tags.
	 */
	private function raw_config_payload( $html ) {
		$element = strpos( $html, 'id="' . Frontend::CONFIG_ELEMENT_ID . '"' );

		$this->assertNotFalse( $element, 'The config element is absent from the emitted markup.' );

		$start = strpos( $html, '>', $element );
		$end   = strpos( $html, '</script>', (int) $start );

		$this->assertNotFalse( $end, 'The config element is never closed.' );

		return substr( $html, (int) $start + 1, (int) $end - (int) $start - 1 );
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
}

/*
 * Attached at include time. See the note on register_test_conditions(): the
 * registration action fires once per process, tied to the construction of the
 * registry rather than to any call, so a hook added from set_up() can arrive
 * after the only registry it could have joined was already built.
 */
add_action( 'popkit_register_conditions', array( Test_Popkit_Frontend_Emission::class, 'register_test_conditions' ) );
