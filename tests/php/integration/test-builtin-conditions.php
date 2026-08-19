<?php
/**
 * Integration tests asserting the built-in condition registry is complete and correct.
 *
 * Twelve conditions ship in v1. Seven are `Context::Server` and describe the page;
 * five are `Context::Client` and describe the visitor. `docs/data-model.md` ->
 * Built-in conditions is the list, and this file is the enforcement of it.
 *
 * ## Why this file exists
 *
 * Phase 4 shipped with the five client conditions declared in JavaScript and
 * never registered in PHP. Every gate stayed green — PHPCS, 782 unit tests, 171
 * integration tests, 469 JS unit tests, lint, build and size — while no popup
 * targeted by device, login state, referrer, UTM or visit history could open on
 * any page of any site. Three structural gaps let that happen, and each one has
 * an assertion below:
 *
 * 1. **Nothing asserted the registry was complete.** Every PHP test that needed a
 *    client condition registered a private fixture, so the real ones could be
 *    absent without a single test noticing.
 * 2. **Nothing asserted a PHP key matched its JavaScript module's key.** A
 *    mismatch is the same bug in a quieter form: the condition registers, the
 *    editor renders a control, the rule saves, and the browser finds no evaluator
 *    for the emitted `type` and fails it closed forever.
 * 3. **Nothing asserted an emitted built-in client rule was free of the
 *    `unknown` tag.** That tag is what stage 7 of `src/frontend/controller.js`
 *    denies on, before it ever looks up an evaluator, so a built-in carrying it
 *    is dead on arrival. {@see Test_Popkit_Builtin_Conditions::test_an_emitted_builtin_client_rule_is_not_tagged_unknown()}
 *    is that assertion, and it is the one that would have caught the defect.
 *
 * ## The expectations are written out, never derived
 *
 * The key list, the contexts, the groups and the field schemas below are
 * transcribed from `docs/data-model.md` by hand. Reading any of them back out of
 * `Popkit\Conditions::all()` would compare the registry with itself: a registry
 * missing five conditions would agree with a list derived from it, which is
 * precisely how the original defect stayed invisible.
 *
 * Field schemas are pinned by field name, declared type, and the `enum` and
 * `max_length` the documentation states. Labels and controls are deliberately
 * not pinned — the data model does not specify them, and a test asserting
 * undocumented wording turns copy edits into failures without protecting
 * anything.
 *
 * ## Nothing here registers a condition
 *
 * The integration bootstrap loads the plugin on `muplugins_loaded`, so
 * `Plugin::boot()` has run on `plugins_loaded` long before PHPUnit includes this
 * file. The only attachment to `popkit_register_conditions` in the process is
 * the plugin's own. A fixture registering `device` from here would supply the
 * very thing under test, and every assertion below would pass with the
 * registration call deleted from `Plugin::boot_registries()` — proving only that
 * this file works.
 *
 * @package Popkit
 */

use Popkit\Capabilities;
use Popkit\Condition;
use Popkit\Context;
use Popkit\Frontend;
use Popkit\Meta;
use Popkit\Post_Type;
use Popkit\Rest_Schema;
use Popkit\Url_Matcher;

/**
 * Integration coverage for the twelve conditions popkit registers in v1.
 */
final class Test_Popkit_Builtin_Conditions extends WP_UnitTestCase {

	/**
	 * A rule type nothing registers, standing in for a deactivated extension.
	 *
	 * The control in the emission test: it must be tagged `unknown`, so that the
	 * assertion about built-ins being untagged is distinguishing the two cases
	 * rather than passing because nothing is ever tagged.
	 *
	 * @var string
	 */
	private const ABSENT_CONDITION = 'popkit_builtin_conditions_absent';

	/**
	 * Directory holding the client-context condition modules, relative to the plugin root.
	 *
	 * @var string
	 */
	private const JS_CONDITIONS_DIR = 'src/frontend/conditions/';

	/**
	 * Viewport width stored on the `device` fixture rule, in CSS pixels.
	 *
	 * 782 is the breakpoint WordPress itself collapses at, and the number
	 * `src/frontend/conditions/device.js` uses in its own worked example.
	 *
	 * @var int
	 */
	private const FIXTURE_MAX_WIDTH = 782;

	/**
	 * Fixture user holding the capability the registry route requires.
	 *
	 * @var int
	 */
	private $editor = 0;

	/**
	 * Every `Context::Server` condition key, in the order the editor lists them.
	 *
	 * Transcribed from `docs/data-model.md` -> Built-in conditions. These strings
	 * are stored verbatim as a rule's `type`, so renaming one silently stops every
	 * already-saved popup of that type from ever matching again.
	 *
	 * @return string[]
	 */
	public static function expected_server_keys() {
		return array( 'post_type', 'post_ids', 'taxonomy_term', 'is_front_page', 'is_404', 'template', 'url_path' );
	}

	/**
	 * Every `Context::Client` condition key, in the order the editor lists them.
	 *
	 * These are the five that were never registered. Each is also the `key` its
	 * module in `src/frontend/conditions/` exports, and the two must be the same
	 * string — see
	 * {@see Test_Popkit_Builtin_Conditions::test_each_client_key_matches_its_javascript_module()}.
	 *
	 * @return string[]
	 */
	public static function expected_client_keys() {
		return array( 'device', 'user_state', 'referrer', 'utm', 'visit_history' );
	}

	/**
	 * Every built-in condition key, in the documented order.
	 *
	 * @return string[]
	 */
	public static function expected_keys() {
		return array_merge( self::expected_server_keys(), self::expected_client_keys() );
	}

	/**
	 * The context each built-in must declare.
	 *
	 * Written as its own map rather than inferred from which list a key appears
	 * in, because this is the cache-safety boundary and inferring it would make
	 * the assertion agree with whatever the lists happened to say. A client
	 * condition registered as `Context::Server` would be judged in PHP, on the
	 * request that fills the page cache, and every subsequent visitor of that URL
	 * would receive a targeting decision computed for somebody else.
	 *
	 * @return array<string, Context> Condition key => required context.
	 */
	public static function expected_contexts() {
		return array(
			'post_type'     => Context::Server,
			'post_ids'      => Context::Server,
			'taxonomy_term' => Context::Server,
			'is_front_page' => Context::Server,
			'is_404'        => Context::Server,
			'template'      => Context::Server,
			'url_path'      => Context::Server,
			'device'        => Context::Client,
			'user_state'    => Context::Client,
			'referrer'      => Context::Client,
			'utm'           => Context::Client,
			'visit_history' => Context::Client,
		);
	}

	/**
	 * The editor group each built-in must declare.
	 *
	 * The "Group" column of `docs/data-model.md` -> Built-in conditions. It is
	 * presentational and does not imply a context, which is why it is asserted
	 * separately from one.
	 *
	 * @return array<string, string> Condition key => editor group.
	 */
	public static function expected_groups() {
		return array(
			'post_type'     => 'content',
			'post_ids'      => 'content',
			'taxonomy_term' => 'content',
			'is_front_page' => 'content',
			'is_404'        => 'content',
			'template'      => 'content',
			'url_path'      => 'content',
			'device'        => 'visitor',
			'user_state'    => 'visitor',
			'referrer'      => 'visitor',
			'utm'           => 'visitor',
			'visit_history' => 'visitor',
		);
	}

	/**
	 * The field name, declared type and documented constraints of every built-in.
	 *
	 * The "Fields" column of `docs/data-model.md` -> Built-in conditions, plus the
	 * two constraints the documentation states in prose: the four-mode match
	 * language and its 255-byte literal cap, which `docs/CLAUDE.md` -> Security ->
	 * URL match language applies to `url_path` and to `referrer` alike.
	 *
	 * Only documented keys appear. `label`, `control` and `default` are
	 * implementation choices the data model does not fix, and
	 * {@see Test_Popkit_Builtin_Conditions::test_every_declared_field_is_renderable()}
	 * asserts they are present and usable without pinning their wording.
	 *
	 * @return array<string, array<string, array<string, mixed>>> Condition key => field name => expected schema fragment.
	 */
	public static function expected_fields() {
		return array(
			'post_type'     => array(
				'types' => array(
					'type'  => 'array',
					'items' => 'string',
				),
			),
			'post_ids'      => array(
				'ids' => array(
					'type'  => 'array',
					'items' => 'integer',
				),
			),
			'taxonomy_term' => array(
				'taxonomy' => array( 'type' => 'string' ),
				'terms'    => array(
					'type'  => 'array',
					'items' => 'integer',
				),
			),
			'is_front_page' => array(),
			'is_404'        => array(),
			'template'      => array(
				'templates' => array(
					'type'  => 'array',
					'items' => 'string',
				),
			),
			'url_path'      => array(
				'match' => array(
					'type' => 'enum',
					'enum' => array( 'exact', 'prefix', 'contains', 'glob' ),
				),
				'value' => array(
					'type'       => 'string',
					'max_length' => 255,
				),
			),
			'device'        => array(
				'max_width' => array( 'type' => 'integer' ),
			),
			'user_state'    => array(
				'state' => array(
					'type' => 'enum',
					'enum' => array( 'logged_in', 'logged_out' ),
				),
			),
			'referrer'      => array(
				'match' => array(
					'type' => 'enum',
					'enum' => array( 'exact', 'prefix', 'contains', 'glob' ),
				),
				'value' => array(
					'type'       => 'string',
					'max_length' => 255,
				),
			),
			'utm'           => array(
				'param' => array( 'type' => 'string' ),
				'value' => array( 'type' => 'string' ),
			),
			'visit_history' => array(
				'state' => array(
					'type' => 'enum',
					'enum' => array( 'first_time', 'returning' ),
				),
			),
		);
	}

	/**
	 * Stored values for a rule of each client condition, all of them valid.
	 *
	 * Valid matters: these go through the registered sanitizer, which validates
	 * against the declared field schema, so a value outside an enum would be
	 * replaced by a default and the assertion about what survived would be
	 * asserting the sanitizer instead.
	 *
	 * @return array<string, array<string, mixed>> Condition key => stored values.
	 */
	public static function client_rule_values() {
		return array(
			'device'        => array( 'max_width' => self::FIXTURE_MAX_WIDTH ),
			'user_state'    => array( 'state' => 'logged_in' ),
			'referrer'      => array(
				'match' => 'contains',
				'value' => 'example.org/campaigns',
			),
			'utm'           => array(
				'param' => 'utm_campaign',
				'value' => 'spring-appeal',
			),
			'visit_history' => array( 'state' => 'first_time' ),
		);
	}

	/**
	 * Grants capabilities, creates the fixture user, and drops the REST server.
	 *
	 * The server is dropped rather than reused so it is rebuilt from the hooks in
	 * place right now. One cached in the global by an earlier test holds whichever
	 * routes were registered at the moment it was created.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_roles()->for_site();

		Capabilities::assign();

		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$GLOBALS['wp_rest_server'] = null;

		Frontend::reset();
	}

	/**
	 * Drops the REST server and the memoized match so the next test starts clean.
	 *
	 * @return void
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null;

		Frontend::reset();

		parent::tear_down();
	}

	/**
	 * Every documented built-in is in the registry the plugin actually built.
	 *
	 * The registry read here is the shared one, reached through
	 * `popkit_conditions()` — the same instance the REST route, the meta
	 * sanitizers, `Rule_Evaluator` and the front-end emitter all consult. This
	 * file registers nothing, so a key present here was put there by
	 * `Plugin::boot_registries()` and by nothing else.
	 *
	 * Membership is asserted rather than the whole key list, because other suites
	 * register fixture conditions into this same process-wide registry.
	 *
	 * @return void
	 */
	public function test_every_documented_builtin_condition_is_registered() {
		$registry = popkit_conditions();

		foreach ( self::expected_keys() as $key ) {
			$this->assertTrue(
				$registry->is_registered( $key ),
				sprintf(
					'The built-in condition "%s" is missing from the registry the plugin builds. Nothing registers it, so Rule_Evaluator resolves every rule of that type to null, emits it tagged unknown, and the browser denies it at stage 7 before looking up an evaluator. No popup targeted by "%s" can open on any page, and no other gate in this repository reports it.',
					$key,
					$key
				)
			);

			$this->assertInstanceOf(
				Condition::class,
				$registry->get( $key ),
				sprintf( 'The built-in condition "%s" did not come back as a Condition.', $key )
			);
		}
	}

	/**
	 * All five client conditions are registered, stated as its own assertion.
	 *
	 * Redundant with the test above by construction, and kept anyway. That one
	 * fails on whichever key it reaches first; this one names the five that were
	 * absent as a set, so a partial registration reports as a partial
	 * registration rather than as one missing key.
	 *
	 * @return void
	 */
	public function test_all_five_client_conditions_are_registered() {
		$registry = popkit_conditions();

		$missing = array();

		foreach ( self::expected_client_keys() as $key ) {
			if ( ! $registry->is_registered( $key ) ) {
				$missing[] = $key;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'Client-context conditions are declared in JavaScript but registered in PHP, and these were not registered. Their evaluate functions in src/frontend/conditions/ are unreachable dead code until they are: the emitter tags every rule of an unregistered type "unknown": true, and stage 7 denies a tagged rule before it consults the evaluator map.'
		);
	}

	/**
	 * Every built-in declares the context the data model gives it.
	 *
	 * A client condition registered as `Context::Server` is a cache-safety
	 * violation, not a detail: `Rule_Evaluator` would hand it to PHP for a
	 * verdict on the request that fills the page cache, and every later visitor
	 * of that URL would be served a decision computed for the first one. The
	 * reverse — a server condition registered as `Context::Client` — is safe but
	 * broken, because the browser ships no evaluator for it and would deny it.
	 *
	 * @return void
	 */
	public function test_each_builtin_declares_its_documented_context() {
		$registry = popkit_conditions();

		foreach ( self::expected_contexts() as $key => $context ) {
			$condition = $registry->get( $key );

			$this->assertInstanceOf( Condition::class, $condition, sprintf( 'Fixture: the built-in condition "%s" must be registered before its context can be asserted.', $key ) );

			$this->assertSame(
				$context,
				$condition->context,
				sprintf(
					'The built-in condition "%s" declares the wrong context. Context is what decides whether a rule is judged in PHP while the cacheable response is generated, or deferred to the visitor\'s browser.',
					$key
				)
			);
		}
	}

	/**
	 * Every built-in declares the editor group the data model gives it.
	 *
	 * @return void
	 */
	public function test_each_builtin_declares_its_documented_group() {
		$registry = popkit_conditions();

		foreach ( self::expected_groups() as $key => $group ) {
			$condition = $registry->get( $key );

			$this->assertInstanceOf( Condition::class, $condition, sprintf( 'Fixture: the built-in condition "%s" must be registered before its group can be asserted.', $key ) );

			$this->assertSame(
				$group,
				$condition->group,
				sprintf( 'The built-in condition "%s" is filed under the wrong editor group, so it appears in the wrong panel of the sidebar.', $key )
			);
		}
	}

	/**
	 * Every built-in declares the fields the data model specifies.
	 *
	 * Field names are asserted as an ordered list, because registration order is
	 * what the editor renders, and each field's declared `type` is asserted
	 * individually: sanitization is derived from that declaration, so a `string`
	 * where the model says `int` changes what reaches the database as well as
	 * what control the author sees.
	 *
	 * @return void
	 */
	public function test_each_builtin_declares_the_documented_field_schema() {
		$registry = popkit_conditions();

		foreach ( self::expected_fields() as $key => $expected ) {
			$condition = $registry->get( $key );

			$this->assertInstanceOf( Condition::class, $condition, sprintf( 'Fixture: the built-in condition "%s" must be registered before its fields can be asserted.', $key ) );

			$this->assertSame(
				array_keys( $expected ),
				array_keys( $condition->fields ),
				sprintf(
					'The field names declared by "%s" are not the ones docs/data-model.md specifies. Those names are the keys of a stored rule\'s values object and are read by name in the browser, so renaming one leaves the JavaScript reading a key that is never emitted.',
					$key
				)
			);

			foreach ( $expected as $field => $fragment ) {
				foreach ( $fragment as $schema_key => $value ) {
					$this->assertArrayHasKey(
						$schema_key,
						$condition->fields[ $field ],
						sprintf( 'The field "%s" on the condition "%s" declares no "%s".', $field, $key, $schema_key )
					);

					$this->assertSame(
						$value,
						$condition->fields[ $field ][ $schema_key ],
						sprintf(
							'The "%s" declared for the field "%s" on the condition "%s" is not the one docs/data-model.md specifies. That schema is the single source of truth driving the editor control, the REST validation and the sanitizer at once.',
							$schema_key,
							$field,
							$key
						)
					);
				}
			}
		}
	}

	/**
	 * The two match-language fields are capped from the matcher, not from a literal.
	 *
	 * `docs/CLAUDE.md` -> Security -> URL match language sets one cap for the
	 * whole constrained language, and `Popkit\Url_Matcher` enforces it at runtime.
	 * A declared cap that differs from the enforced one is a field the editor
	 * accepts and the matcher then silently refuses to match.
	 *
	 * @return void
	 */
	public function test_the_match_language_fields_declare_the_matchers_own_bounds() {
		$registry = popkit_conditions();

		foreach ( array( 'url_path', 'referrer' ) as $key ) {
			$condition = $registry->get( $key );

			$this->assertInstanceOf( Condition::class, $condition, sprintf( 'Fixture: the built-in condition "%s" must be registered.', $key ) );

			$this->assertSame(
				Url_Matcher::modes(),
				$condition->fields['match']['enum'],
				sprintf( 'The condition "%s" offers a set of match modes that Url_Matcher does not implement. The editor would present a mode the runtime treats as unrecognized, and every rule using it would be indeterminate forever.', $key )
			);

			$this->assertSame(
				Url_Matcher::MAX_VALUE_LENGTH,
				$condition->fields['value']['max_length'],
				sprintf( 'The condition "%s" declares a literal cap that is not the one Url_Matcher enforces. A value the editor accepts and the matcher rejects produces a rule that never matches and never explains why.', $key )
			);
		}
	}

	/**
	 * Every declared field can actually be rendered by the shared control map.
	 *
	 * A control absent from that map has no renderer, so the editor produces an
	 * empty panel rather than an error, and an unlabeled control is an
	 * accessibility failure. The `Condition` constructor enforces both; this
	 * asserts the enforcement was reached rather than trusting that it was.
	 *
	 * @return void
	 */
	public function test_every_declared_field_is_renderable() {
		$registry = popkit_conditions();

		foreach ( self::expected_keys() as $key ) {
			$condition = $registry->get( $key );

			$this->assertInstanceOf( Condition::class, $condition, sprintf( 'Fixture: the built-in condition "%s" must be registered.', $key ) );

			foreach ( $condition->fields as $field => $schema ) {
				$this->assertArrayHasKey(
					'label',
					$schema,
					sprintf( 'The field "%s" on the condition "%s" declares no label. An unlabeled control is not accessible, and accessibility is this plugin\'s differentiator.', $field, $key )
				);

				$this->assertContains(
					$schema['control'] ?? null,
					Condition::FIELD_CONTROLS,
					sprintf( 'The field "%s" on the condition "%s" names a control that is not in the shared control map, so the editor has nothing to render it with.', $field, $key )
				);
			}
		}
	}

	/**
	 * Each client condition's PHP key is the key its JavaScript module exports.
	 *
	 * The two halves of a client condition are joined by one string and nothing
	 * else. PHP registers the key, the emitter writes it into the config as a
	 * rule's `type`, and `src/frontend/conditions/index.js` builds its lookup map
	 * from the key each module declares for itself. A mismatch reproduces the
	 * critical defect in a subtler form: the condition registers, the editor
	 * renders a working control, the rule saves and is emitted untagged, and the
	 * browser then finds no evaluator for that `type` and fails the rule closed on
	 * every pageview. Nothing else in this repository compares the two.
	 *
	 * @return void
	 */
	public function test_each_client_key_matches_its_javascript_module() {
		foreach ( self::expected_client_keys() as $key ) {
			$file = str_replace( '_', '-', $key ) . '.js';

			$this->assertSame(
				$key,
				$this->javascript_export( $file, "key: '" ),
				sprintf(
					'The key exported by %s%s is not the key PHP registers as "%s". The emitted rule type and the browser\'s lookup map are joined by this one string.',
					self::JS_CONDITIONS_DIR,
					$file,
					$key
				)
			);
		}
	}

	/**
	 * The shipped bundle carries a module for every client condition and no others.
	 *
	 * A sixth module in that directory is either a condition nobody registered —
	 * the defect, arriving from the other side — or dead weight inside an 8 KB
	 * budget. Either way it should be visible.
	 *
	 * @return void
	 */
	public function test_the_condition_modules_on_disk_are_exactly_the_five_client_conditions() {
		$directory = POPKIT_DIR . self::JS_CONDITIONS_DIR;
		$found     = array();

		foreach ( (array) glob( $directory . '*.js' ) as $path ) {
			$name = basename( (string) $path );

			// index.js is the registry the five modules are collected into, not one of them.
			if ( 'index.js' === $name ) {
				continue;
			}

			$found[] = $name;
		}

		$expected = array();

		foreach ( self::expected_client_keys() as $key ) {
			$expected[] = str_replace( '_', '-', $key ) . '.js';
		}

		sort( $found );
		sort( $expected );

		$this->assertSame(
			$expected,
			$found,
			'The condition modules in src/frontend/conditions/ are not the five client conditions PHP registers. A module with no registration is unreachable code inside a budgeted bundle; a registration with no module is a rule the browser can never evaluate.'
		);
	}

	/**
	 * Only `user_state` declares that it needs the context route.
	 *
	 * `Frontend::USER_STATE_CONDITION` is matched as a literal when the emitter
	 * decides `needsContext`, and `src/frontend/conditions/user-state.js` declares
	 * `needsContext: true` for the same condition. The two have to name the same
	 * key: if PHP does not set the flag the browser never fetches context and
	 * every login-state rule fails closed, and if a second module claimed to need
	 * context its rules would be denied on every page that did not happen to
	 * fetch it.
	 *
	 * @return void
	 */
	public function test_only_user_state_declares_that_it_needs_context() {
		$this->assertSame(
			'user_state',
			Frontend::USER_STATE_CONDITION,
			'The emitter matches a literal condition key to decide whether the page fetches context. It is no longer the key the registry and the browser use.'
		);

		foreach ( self::expected_client_keys() as $key ) {
			$file     = str_replace( '_', '-', $key ) . '.js';
			$declared = $this->javascript_export( $file, 'needsContext: ' );
			$expected = Frontend::USER_STATE_CONDITION === $key ? 'true' : 'false';

			$this->assertSame(
				$expected,
				$declared,
				sprintf(
					'%s%s declares needsContext: %s. Only the login-state condition may, because the context route is the only thing that answers a question the browser cannot answer for itself, and every extra fetch is a round trip on every pageview of the site.',
					self::JS_CONDITIONS_DIR,
					$file,
					$declared
				)
			);
		}
	}

	/**
	 * The condition deferred to 1.1 is not registered.
	 *
	 * `user_role` is out of v1 deliberately: role data is more sensitive than a
	 * login boolean and needs a considered privacy contract before it crosses the
	 * wire. Registering it early would put a control in the editor that no runtime
	 * implements and no endpoint answers.
	 *
	 * @return void
	 */
	public function test_user_role_is_not_registered() {
		$this->assertFalse(
			popkit_conditions()->is_registered( 'user_role' ),
			'The condition "user_role" is deferred to 1.1 in docs/data-model.md and must not be registered in v1. The context endpoint returns a boolean and nothing else, so a rule of this type could never be evaluated.'
		);
	}

	/**
	 * An emitted rule of a built-in client condition does not carry the unknown tag.
	 *
	 * **This is the assertion the critical defect needed and nothing in the
	 * codebase made.**
	 *
	 * The chain it holds: `Rule_Evaluator::retag_unknown()` tags any rule whose
	 * `type` resolves to no registered condition at the moment of emission, and
	 * stage 7 of `src/frontend/controller.js` denies a tagged rule *before* it
	 * looks the rule's evaluator up. So an unregistered built-in produces a config
	 * that is well-formed, a page that is byte-identical to a working one, and a
	 * popup that can never open — with every existing gate green, because no test
	 * read the tag on a built-in rule. The one e2e test that inspected an emitted
	 * client rule read its `type` and not its `unknown`.
	 *
	 * All five client conditions are asserted, each in its own group, so the test
	 * reports which of them is unregistered rather than only the first.
	 *
	 * The unregistered control rule in the last group is what stops this test from
	 * passing vacuously: if the emitter ever stopped tagging anything at all, the
	 * built-in assertions would still hold and only the control would fail.
	 *
	 * @return void
	 */
	public function test_an_emitted_builtin_client_rule_is_not_tagged_unknown() {
		$groups = array();

		foreach ( self::client_rule_values() as $key => $values ) {
			$groups[] = array(
				'rules' => array(
					array(
						'type'   => $key,
						'negate' => false,
						'values' => $values,
					),
				),
			);
		}

		$groups[] = array(
			'rules' => array(
				array(
					'type'   => self::ABSENT_CONDITION,
					'negate' => false,
					'values' => array( 'level' => 'gold' ),
				),
			),
		);

		$popup_id = $this->create_popup();

		update_post_meta( $popup_id, Meta::CONDITIONS, array( 'groups' => $groups ) );

		$this->go_to( home_url( '/' ) );
		Frontend::reset();

		$emitted = $this->emitted_rules_by_type( $popup_id );

		foreach ( self::client_rule_values() as $key => $values ) {
			$this->assertArrayHasKey(
				$key,
				$emitted,
				sprintf( 'No rule of type "%s" reached the emitted config at all, so the browser has nothing to evaluate.', $key )
			);

			$this->assertArrayNotHasKey(
				'unknown',
				$emitted[ $key ],
				sprintf(
					'The emitted rule of type "%s" carries "unknown": true. Nothing registered that condition in PHP, so Rule_Evaluator tagged it, and stage 7 of the front-end controller denies a tagged rule before it ever looks up an evaluator. The condition\'s JavaScript module is dead code and no popup targeted by "%s" can open on any page.',
					$key,
					$key
				)
			);

			$this->assertSame(
				$values,
				$emitted[ $key ]['values'],
				sprintf( 'The stored values of the "%s" rule changed on their way to the browser. A registered condition must see byte-identical values.', $key )
			);
		}

		$this->assertArrayHasKey(
			self::ABSENT_CONDITION,
			$emitted,
			'Control: the rule of an unregistered type was not emitted, so this test can no longer tell a tagged rule from an untagged one.'
		);

		$this->assertTrue(
			$emitted[ self::ABSENT_CONDITION ]['unknown'] ?? false,
			'Control: a rule whose type nothing registers was emitted without the unknown tag. The emitter has stopped tagging anything, which means the assertions above pass for the wrong reason and a deactivated extension would turn "members only" into "everybody".'
		);
	}

	/**
	 * Emission through the real footer path produces the same untagged built-in rule.
	 *
	 * The assertion above reads `Frontend::config()`. This one reads the bytes the
	 * page actually carries: the payload is encoded, printed inside
	 * `<script type="application/json">`, parsed back out of the markup and
	 * decoded, which is exactly what the browser does before stage 7 runs.
	 *
	 * @return void
	 */
	public function test_the_printed_config_carries_the_builtin_client_rule_untagged() {
		$popup_id = $this->create_popup();

		update_post_meta(
			$popup_id,
			Meta::CONDITIONS,
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => 'device',
								'negate' => false,
								'values' => array( 'max_width' => self::FIXTURE_MAX_WIDTH ),
							),
						),
					),
				),
			)
		);

		$this->go_to( home_url( '/' ) );
		Frontend::reset();

		ob_start();
		Frontend::render();
		$html = (string) ob_get_clean();

		$decoded = json_decode( $this->config_element_text( $html ), true );

		$this->assertIsArray( $decoded, 'The printed config element does not contain parseable JSON.' );

		$rule = $this->rule_of_popup( $decoded, $popup_id, 'device' );

		$this->assertIsArray( $rule, 'The device rule is absent from the printed config, so the browser has nothing to evaluate.' );
		$this->assertArrayNotHasKey(
			'unknown',
			$rule,
			'The device rule printed into the page carries "unknown": true. The front-end controller denies a tagged rule before it looks up an evaluator, so this popup can never open however the visitor\'s viewport is sized.'
		);
		$this->assertSame(
			self::FIXTURE_MAX_WIDTH,
			$rule['values']['max_width'],
			'The stored max_width did not survive the trip to the page. The browser coerces this value with Number(), so a lost or retyped one changes which viewports match.'
		);
	}

	/**
	 * Every built-in reaches the editor through the registry route, with its context.
	 *
	 * That route is the editor's only source of field schemas, so a condition
	 * missing from the payload has no control and cannot be configured at all. The
	 * context travels with it because the editor and the browser both key
	 * behavior off it.
	 *
	 * @return void
	 */
	public function test_every_builtin_reaches_the_editor_through_the_registry_route() {
		wp_set_current_user( $this->editor );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', '/' . Rest_Schema::REST_NAMESPACE . Rest_Schema::ROUTE )
		);

		$this->assertSame( 200, $response->get_status(), 'The registry route did not answer a user holding the capability it requires.' );

		$conditions = (array) $response->get_data()['conditions'];

		foreach ( self::expected_contexts() as $key => $context ) {
			$this->assertArrayHasKey(
				$key,
				$conditions,
				sprintf( 'The built-in condition "%s" never reached the registry payload. The editor learns field schemas from this route at runtime, so a condition missing here has no control and cannot be configured at all.', $key )
			);

			$this->assertSame(
				$context->value,
				$conditions[ $key ]['context'],
				sprintf( 'The context of "%s" changed on its way to the editor.', $key )
			);

			$this->assertSame(
				array_keys( self::expected_fields()[ $key ] ),
				array_keys( (array) $conditions[ $key ]['fields'] ),
				sprintf( 'The declared field schema for "%s" did not survive the trip to the editor.', $key )
			);
		}
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
					'post_title'   => 'Built-in conditions fixture',
					'post_content' => '<p>Fixture content.</p>',
				),
				$args
			)
		);
	}

	/**
	 * Returns one popup's emitted rules, keyed by rule type.
	 *
	 * Keying by type rather than reading by position means the assertions say
	 * which condition failed rather than which array index did, and they survive a
	 * change in the order groups are emitted.
	 *
	 * @param int $popup_id Popup whose emitted entry is wanted.
	 * @return array<string, array> Rule type => emitted rule.
	 */
	private function emitted_rules_by_type( $popup_id ) {
		$rules = array();

		foreach ( Frontend::config()['popups'] as $popup ) {
			if ( (int) $popup['id'] !== (int) $popup_id ) {
				continue;
			}

			foreach ( $popup['conditions']['groups'] as $group ) {
				foreach ( $group['rules'] as $rule ) {
					$rules[ $rule['type'] ] = $rule;
				}
			}
		}

		return $rules;
	}

	/**
	 * Finds one rule of a given type in a decoded config payload.
	 *
	 * @param array  $config   Decoded config.
	 * @param int    $popup_id Popup whose entry is wanted.
	 * @param string $type     Rule type to look for.
	 * @return array|null The rule, or null when the payload carries none.
	 */
	private function rule_of_popup( array $config, $popup_id, $type ) {
		foreach ( (array) ( $config['popups'] ?? array() ) as $popup ) {
			if ( (int) ( $popup['id'] ?? 0 ) !== (int) $popup_id ) {
				continue;
			}

			foreach ( (array) ( $popup['conditions']['groups'] ?? array() ) as $group ) {
				foreach ( (array) ( $group['rules'] ?? array() ) as $rule ) {
					if ( ! is_array( $rule ) ) {
						continue;
					}

					if ( ( $rule['type'] ?? null ) === $type ) {
						return $rule;
					}
				}
			}
		}

		return null;
	}

	/**
	 * Text content of the emitted config element, as a parser sees it.
	 *
	 * @param string $html Emitted markup.
	 * @return string Payload text, empty when the element is absent.
	 */
	private function config_element_text( $html ) {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );

		$document->loadHTML( '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>' );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$nodes = ( new DOMXPath( $document ) )->query( '//script[@id="' . Frontend::CONFIG_ELEMENT_ID . '"]' );

		return 0 === $nodes->length ? '' : $nodes->item( 0 )->textContent;
	}

	/**
	 * Reads one single-line property off a condition module's default export.
	 *
	 * Deliberately a line scan and not a pattern match. `docs/CLAUDE.md` bans
	 * user-supplied regular expressions and the repository contains no PCRE call
	 * at all, which is a property a reviewer verifies by grepping; adding one here
	 * for a five-line lookup would spend that.
	 *
	 * Only lines whose trimmed text begins with the wanted property are
	 * considered, so the same property named inside a docblock — every one of
	 * those lines begins with `*` — is never read. Everything after the property
	 * name up to the trailing comma is returned verbatim, with a quoted value
	 * unwrapped, so a caller can compare either a string key or a boolean literal.
	 *
	 * @param string $file     File name inside src/frontend/conditions/.
	 * @param string $property Property text to look for, including its colon and space.
	 * @return string The declared value, or an empty string when the module declares none.
	 */
	private function javascript_export( $file, $property ) {
		$path = POPKIT_DIR . self::JS_CONDITIONS_DIR . $file;

		$this->assertFileExists(
			$path,
			sprintf( 'The client condition module %s%s is absent. PHP registers a condition the shipped bundle cannot evaluate.', self::JS_CONDITIONS_DIR, $file )
		);

		$source = (string) file_get_contents( $path );

		foreach ( explode( "\n", $source ) as $line ) {
			$line = trim( $line );

			if ( ! str_starts_with( $line, $property ) ) {
				continue;
			}

			return trim( substr( $line, strlen( $property ) ), " \t\r\n,'" );
		}

		return '';
	}
}
