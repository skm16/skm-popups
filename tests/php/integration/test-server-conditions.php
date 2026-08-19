<?php
/**
 * Integration coverage for the seven server-context conditions.
 *
 * Every condition here answers a question about *the page being served* and about
 * nothing else. That is what makes the response cacheable, and it is why these
 * tests drive real WordPress query states through `go_to()` and the post,
 * term and user factories rather than faking a `WP_Query`. A faked query would
 * be testing the fake: `is_singular()`, `is_home()`, `is_front_page()` and
 * `has_term()` all read the main query globals that `WP::main()` populates from
 * the request path, so the only honest fixture is a real request.
 *
 * ## What a "match" means here, and how it is distinguished from a deferral
 *
 * Server-side evaluation has three outcomes, not two, and conflating two of them
 * is the failure this file is shaped to catch:
 *
 * | Outcome | Group | Rule in the emitted config |
 * |---|---|---|
 * | Decided true | survives | stripped — it has already been decided |
 * | Decided false | fails, popup omitted | never emitted |
 * | Indeterminate | survives | emitted, for the client to fail closed |
 *
 * A test that only asked "did the popup survive?" would pass identically for
 * *decided true* and for *indeterminate*, so a condition that silently stopped
 * deciding anything — a renamed key, a guard that returns null too eagerly —
 * would look exactly like a condition that works. {@see Test_Popkit_Server_Conditions::verdict()}
 * therefore reads both the surviving popup list and the emitted rule set, and
 * {@see Test_Popkit_Server_Conditions::test_the_harness_tells_a_decided_rule_from_a_deferred_one()}
 * proves all three outcomes are reachable through it before any of them is
 * relied on.
 *
 * ## Why this runs through Frontend rather than calling the evaluators
 *
 * `Frontend::evaluate_server_rule()` is private, and deliberately so: survival
 * and emission are decided by one evaluator precisely so the two can never
 * disagree. Reaching past it would test a chain the plugin does not use. So the
 * fixtures are stored meta on a real published popup, and the assertions read
 * what `Frontend` emitted — the same path a pageview takes.
 *
 * @package Popkit
 */

use Popkit\Conditions\Content_Conditions;
use Popkit\Conditions\Url_Conditions;
use Popkit\Frontend;
use Popkit\Meta;
use Popkit\Post_Type;

/**
 * Integration coverage for the built-in `Context::Server` conditions.
 */
final class Test_Popkit_Server_Conditions extends WP_UnitTestCase {

	/**
	 * Verdict: the rule was decided true, so the group survived and the rule was stripped.
	 *
	 * @var string
	 */
	private const MATCHED = 'decided true';

	/**
	 * Verdict: the rule was decided false, so its group failed and the popup was omitted.
	 *
	 * @var string
	 */
	private const REJECTED = 'decided false';

	/**
	 * Verdict: the rule was not decided, so it traveled to the client to be failed closed.
	 *
	 * @var string
	 */
	private const DEFERRED = 'indeterminate';

	/**
	 * Post type registered per test so archive targeting has something to resolve.
	 *
	 * Sixteen characters, inside `register_post_type()`'s twenty-character cap.
	 *
	 * @var string
	 */
	private const BOOK_TYPE = 'popkit_test_book';

	/**
	 * Archive slug of the test post type.
	 *
	 * @var string
	 */
	private const BOOK_SLUG = 'books';

	/**
	 * Slug of the popup under test, used to look for its markup in the response.
	 *
	 * @var string
	 */
	private const POPUP_SLUG = 'server-conditions-fixture';

	/**
	 * A rule type nothing registers, standing in for a deactivated extension.
	 *
	 * @var string
	 */
	private const UNREGISTERED_TYPE = 'popkit_server_conditions_absent';

	/**
	 * Permalink structure these tests run under.
	 *
	 * Pretty permalinks, because half of what is asserted here is about paths.
	 * Under the default plain structure every request would be `/?p=1` and the
	 * `url_path` cases would be comparing the same string to itself.
	 *
	 * @var string
	 */
	private const PERMALINKS = '/%postname%/';

	/**
	 * The popup under test, or 0 before one has been created.
	 *
	 * @var int
	 */
	private $popup_id = 0;

	/**
	 * Builds the request environment every test in this file assumes.
	 *
	 * The order of the three calls below is load bearing. `register_post_type()`
	 * adds its rewrite rules only when `get_option( 'permalink_structure' )` is
	 * already set, so registering first would give the test post type no archive
	 * rule and `/books/` would resolve to a 404 — a failure that looks like a
	 * broken condition rather than a broken fixture. The structure is therefore
	 * set first, the type registered against it, and the rules flushed afterwards
	 * so the new archive rule is actually in the table.
	 *
	 * `Meta::register()` runs because the WordPress test case unregisters every
	 * meta key in `tear_down()`. Without it the fixtures written below would
	 * bypass sanitization on every test after the first, so what reached the
	 * evaluator would depend on test order.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		Meta::register();

		$this->set_permalink_structure( self::PERMALINKS );

		register_post_type(
			self::BOOK_TYPE,
			array(
				'public'      => true,
				'has_archive' => true,
				'rewrite'     => array( 'slug' => self::BOOK_SLUG ),
				'supports'    => array( 'title', 'editor' ),
			)
		);

		flush_rewrite_rules( false );

		Frontend::init();
		Frontend::reset();

		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	/**
	 * Undoes the registrations the test case does not roll back for a plugin suite.
	 *
	 * `WP_RUN_CORE_TESTS` is undefined here, so post types, taxonomies and the
	 * rewrite structure survive `tear_down()` and would leak into whatever runs
	 * next.
	 *
	 * @return void
	 */
	public function tear_down() {
		Frontend::reset();

		unregister_post_type( self::BOOK_TYPE );

		$this->set_permalink_structure( '' );

		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		parent::tear_down();
	}

	/**
	 * All three server verdicts are reachable and distinguishable.
	 *
	 * Every other test in this file reads {@see Test_Popkit_Server_Conditions::verdict()},
	 * and a harness that could only ever report one value would make all of them
	 * pass for the wrong reason. In particular, "the popup survived" is true both
	 * of a rule decided true and of a rule nobody decided; the difference is
	 * whether the rule was stripped from the emitted config, and this is where
	 * that difference is shown to be observable.
	 *
	 * @return void
	 */
	public function test_the_harness_tells_a_decided_rule_from_a_deferred_one() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Annual report' ) );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'post' ) ) ),
			'A rule the server decided true was not stripped from the emitted config. Either it was never decided, or a resolved rule is being shipped to the browser — which leaks the site\'s targeting configuration and hands the client a rule it has no evaluator for.'
		);

		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'page' ) ) ),
			'A rule the server decided false did not fail its group. The popup is being emitted on a page its own targeting excludes.'
		);

		$this->assertSame(
			self::DEFERRED,
			$this->verdict( self::UNREGISTERED_TYPE, array( 'anything' => 'at all' ) ),
			'A rule whose type nothing registers was not emitted for the client to judge. Server-side it is indeterminate, never satisfied and never failed, and dropping it would leave a group that passes unconditionally in the browser.'
		);
	}

	/**
	 * `post_type` matches the kind of content the URL shows.
	 *
	 * Four query shapes, because "post type is post" has to mean the same thing on
	 * all of them: a singular view reads the queried post's own type, a post type
	 * archive reads the archive's type, and the blog posts index answers `post`
	 * even though its queried object is a page. A search resolves to no single
	 * kind of content at all and is decided false rather than guessed at.
	 *
	 * @return void
	 */
	public function test_post_type_matches_the_content_a_url_shows() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Annual report' ) );
		$book_id = self::factory()->post->create(
			array(
				'post_type'  => self::BOOK_TYPE,
				'post_title' => 'A field guide',
			)
		);

		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( is_singular( 'post' ), 'Fixture: the request must resolve to a singular post.' );
		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'post' ) ) ),
			'A post_type rule naming `post` did not match a singular post.'
		);
		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'page', self::BOOK_TYPE ) ) ),
			'A post_type rule naming other types matched a singular post. Membership is exact; a popup targeted at pages would appear on every post.'
		);

		$this->go_to( get_permalink( $book_id ) );

		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'post', self::BOOK_TYPE ) ) ),
			'A post_type rule listing several types did not match a singular post of one of them. The list is an OR, not an AND.'
		);

		$this->go_to( home_url( '/' . self::BOOK_SLUG . '/' ) );

		$this->assertTrue( is_post_type_archive( self::BOOK_TYPE ), 'Fixture: the request must resolve to the post type archive.' );
		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( self::BOOK_TYPE ) ) ),
			'A post_type rule did not match its own post type archive. Reading the global $post here rather than the queried object is the usual cause, and outside the loop that global holds whatever a previous query left behind.'
		);
		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'post' ) ) ),
			'A post_type rule naming `post` matched an archive of a different type.'
		);

		$this->go_to( home_url( '/?s=fundraising' ) );

		$this->assertTrue( is_search(), 'Fixture: the request must resolve to a search.' );
		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'post' ) ) ),
			'A post_type rule matched a search results page. A search covers every searchable type, so answering `post` for it would be a guess about the site\'s query filters rather than a fact about the URL.'
		);
	}

	/**
	 * `post_type` answers `post` on the blog posts index, in both of its shapes.
	 *
	 * The static posts page is the case worth pinning: its queried object is the
	 * *page* assigned in Settings -> Reading, so a naive read of the queried
	 * object's post type answers `page` for a URL that lists nothing but posts.
	 *
	 * @return void
	 */
	public function test_post_type_answers_post_on_the_blog_posts_index() {
		self::factory()->post->create( array( 'post_title' => 'Annual report' ) );

		$this->go_to( home_url( '/' ) );

		$this->assertTrue( is_home(), 'Fixture: with the default reading settings the site root is the blog posts index.' );
		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'post' ) ) ),
			'A post_type rule naming `post` did not match the blog posts index.'
		);

		$posts_page = $this->use_static_front_page()['posts'];

		$this->go_to( get_permalink( $posts_page ) );

		$this->assertTrue( is_home(), 'Fixture: the assigned posts page must resolve as the blog posts index, not as a page.' );
		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'post' ) ) ),
			'A post_type rule naming `post` did not match the assigned posts page. Its queried object is the page assigned in Settings -> Reading, so reading that object\'s post type answers `page` for a URL that lists nothing but posts.'
		);
		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'page' ) ) ),
			'A post_type rule naming `page` matched the assigned posts page. An author targeting pages does not mean the blog index.'
		);
	}

	/**
	 * An empty `post_type` list matches nothing, and negating it matches everything.
	 *
	 * Nothing is a member of the empty set, so the rule is unsatisfiable — and
	 * that is a decision rather than a deferral, which is what makes `negate` on
	 * it well defined. The editor warns about a set that can never match; the
	 * condition does not second-guess what the author stored.
	 *
	 * @return void
	 */
	public function test_an_empty_post_type_list_matches_nothing() {
		$post_id = self::factory()->post->create();

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array() ) ),
			'A post_type rule selecting no types matched a page. An empty list is unsatisfiable; treating it as "any type" would make a half-filled control target the whole site.'
		);
	}

	/**
	 * `post_ids` matches the queried post, and only on a singular view.
	 *
	 * The blog posts index is the interesting negative: it genuinely resolves to a
	 * `WP_Post` — the page assigned in Settings -> Reading — so a condition that
	 * read the queried object without checking `is_singular()` would match it, and
	 * "specific posts" would mean two different things on one site.
	 *
	 * @return void
	 */
	public function test_post_ids_matches_the_queried_post_on_singular_views_only() {
		$wanted_id = self::factory()->post->create( array( 'post_title' => 'Matched post' ) );
		$other_id  = self::factory()->post->create( array( 'post_title' => 'Other post' ) );

		$this->go_to( get_permalink( $wanted_id ) );

		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::POST_IDS, array( 'ids' => array( $wanted_id ) ) ),
			'A post_ids rule did not match the post the URL resolves to.'
		);
		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::POST_IDS, array( 'ids' => array( $other_id ) ) ),
			'A post_ids rule matched a post the URL does not resolve to.'
		);

		$this->go_to( home_url( '/' ) );

		$this->assertTrue( is_home(), 'Fixture: the site root must be a non-singular view.' );
		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::POST_IDS, array( 'ids' => array( $wanted_id ) ) ),
			'A post_ids rule matched a non-singular view. There is no single post such a URL is about.'
		);

		$pages = $this->use_static_front_page();

		$this->go_to( get_permalink( $pages['posts'] ) );

		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::POST_IDS, array( 'ids' => array( $pages['posts'] ) ) ),
			'A post_ids rule naming the assigned posts page matched it. That page is what the URL lists *from*, not what it displays, and including it would make "specific posts" mean two different things on one site.'
		);
	}

	/**
	 * `taxonomy_term` matches the term archive and a singular post carrying the term.
	 *
	 * Both readings match, and the implementation documents both: an author
	 * choosing "Category: Fundraising" means pages about fundraising, and the
	 * archive and the posts filed under it are one campaign to them. The two
	 * cannot conflict, because no URL is both a term archive and a singular post.
	 *
	 * @return void
	 */
	public function test_taxonomy_term_matches_the_term_archive_and_a_post_carrying_the_term() {
		$wanted   = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Fundraising',
			)
		);
		$other    = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Research',
			)
		);
		$filed_id = self::factory()->post->create( array( 'post_title' => 'Match this week' ) );
		$plain_id = self::factory()->post->create( array( 'post_title' => 'Unfiled' ) );

		wp_set_post_terms( $filed_id, array( $wanted ), 'category' );

		$rule = array(
			'taxonomy' => 'category',
			'terms'    => array( $wanted ),
		);

		$this->go_to( get_term_link( $wanted, 'category' ) );

		$this->assertTrue( is_category( $wanted ), 'Fixture: the request must resolve to the term archive.' );
		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::TAXONOMY_TERM, $rule ),
			'A taxonomy_term rule did not match its own term archive.'
		);

		$this->go_to( get_permalink( $filed_id ) );

		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::TAXONOMY_TERM, $rule ),
			'A taxonomy_term rule did not match a singular post carrying the term. Matching only the archive would leave a popup quietly absent from half the pages its author targeted.'
		);

		$this->go_to( get_permalink( $plain_id ) );

		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::TAXONOMY_TERM, $rule ),
			'A taxonomy_term rule matched a post that does not carry the term.'
		);

		$this->go_to( get_term_link( $other, 'category' ) );

		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::TAXONOMY_TERM, $rule ),
			'A taxonomy_term rule matched a different term\'s archive.'
		);
	}

	/**
	 * A `taxonomy_term` rule selecting nothing usable matches nothing.
	 *
	 * The empty-terms case is not tidiness. `has_term()` with an empty list reports
	 * true for a post carrying *any* term in the taxonomy, so passing it straight
	 * through would turn a rule that selected nothing into a rule matching most of
	 * the site.
	 *
	 * @dataProvider data_unsatisfiable_taxonomy_rules
	 *
	 * @param array  $values  The rule's stored values.
	 * @param string $message Why this case matters.
	 * @return void
	 */
	public function test_an_unsatisfiable_taxonomy_term_rule_matches_nothing( $values, $message ) {
		$term_id = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Fundraising',
			)
		);
		$post_id = self::factory()->post->create();

		wp_set_post_terms( $post_id, array( $term_id ), 'category' );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame( self::REJECTED, $this->verdict( Content_Conditions::TAXONOMY_TERM, $values ), $message );
	}

	/**
	 * Taxonomy rules that cannot select anything.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public function data_unsatisfiable_taxonomy_rules() {
		return array(
			'no terms selected'       => array(
				array(
					'taxonomy' => 'category',
					'terms'    => array(),
				),
				'A taxonomy_term rule selecting no terms matched a post. has_term() with an empty list reports true for a post carrying any term in the taxonomy, so a rule that selected nothing would match most of the site.',
			),
			'no taxonomy named'       => array(
				array(
					'taxonomy' => '',
					'terms'    => array( 1 ),
				),
				'A taxonomy_term rule naming no taxonomy matched a post.',
			),
			'taxonomy not registered' => array(
				array(
					'taxonomy' => 'popkit_absent_taxonomy',
					'terms'    => array( 1 ),
				),
				'A taxonomy_term rule naming a taxonomy nothing has registered matched a post. A deactivated plugin must narrow the audience, never widen it.',
			),
		);
	}

	/**
	 * `is_front_page` follows Settings -> Reading, including where it diverges from `is_home`.
	 *
	 * With a static front page configured the two conditional tags point at
	 * different URLs: `is_front_page()` is the assigned front page and `is_home()`
	 * is the assigned posts page. A condition built on `is_home()` would target
	 * the blog index and call it the front page.
	 *
	 * @return void
	 */
	public function test_is_front_page_follows_the_reading_settings() {
		$post_id = self::factory()->post->create();

		$this->go_to( home_url( '/' ) );

		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::IS_FRONT_PAGE, array() ),
			'An is_front_page rule did not match the site root while Settings -> Reading shows latest posts.'
		);

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::IS_FRONT_PAGE, array() ),
			'An is_front_page rule matched a singular post.'
		);

		$pages = $this->use_static_front_page();

		$this->go_to( get_permalink( $pages['front'] ) );

		$this->assertTrue( is_front_page(), 'Fixture: the assigned front page must resolve as the front page.' );
		$this->assertFalse( is_home(), 'Fixture: with a static front page, the front page is not the blog posts index — that divergence is the point of this test.' );
		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::IS_FRONT_PAGE, array() ),
			'An is_front_page rule did not match the page assigned as the static front page.'
		);

		$this->go_to( get_permalink( $pages['posts'] ) );

		$this->assertTrue( is_home(), 'Fixture: the assigned posts page must resolve as the blog posts index.' );
		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::IS_FRONT_PAGE, array() ),
			'An is_front_page rule matched the assigned posts page. Building the condition on is_home() rather than is_front_page() is the usual cause, and it targets the blog index while claiming to target the front page.'
		);
	}

	/**
	 * `is_404` matches a request that resolved to nothing, and only that.
	 *
	 * A 404 is a legitimate targeting surface rather than an error page popkit
	 * stays off — "lost? here is what you were looking for" is one of the better
	 * uses of this plugin — so the positive case has to reach emission, not just
	 * evaluation.
	 *
	 * @return void
	 */
	public function test_is_404_matches_only_a_request_that_resolved_to_nothing() {
		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'About us',
			)
		);

		$this->go_to( home_url( '/no-such-page-exists/' ) );

		$this->assertTrue( is_404(), 'Fixture: the request must resolve to nothing.' );
		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::IS_404, array() ),
			'An is_404 rule did not match a 404 response.'
		);

		$this->go_to( get_permalink( $page_id ) );

		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::IS_404, array() ),
			'An is_404 rule matched a page that exists.'
		);
	}

	/**
	 * `template` matches the page template assigned to the queried post.
	 *
	 * The assignment, not the template hierarchy. A rule naming `single.php`
	 * matches nothing because nothing assigns that to a post, and resolving the
	 * hierarchy would compare every request on a block theme against one canvas
	 * file.
	 *
	 * @return void
	 */
	public function test_template_matches_the_assignment_on_the_queried_post() {
		$wide_id  = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Wide page',
			)
		);
		$plain_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Plain page',
			)
		);

		update_post_meta( $wide_id, '_wp_page_template', 'template-wide.php' );

		$this->go_to( get_permalink( $wide_id ) );

		$this->assertSame(
			self::MATCHED,
			$this->verdict( Url_Conditions::KEY_TEMPLATE, array( 'templates' => array( 'template-wide.php' ) ) ),
			'A template rule did not match the template assigned to the queried post.'
		);
		$this->assertSame(
			self::REJECTED,
			$this->verdict( Url_Conditions::KEY_TEMPLATE, array( 'templates' => array( 'page-templates/full-width.php' ) ) ),
			'A template rule matched a post assigned a different template.'
		);
		$this->assertSame(
			self::REJECTED,
			$this->verdict( Url_Conditions::KEY_TEMPLATE, array( 'templates' => array( Url_Conditions::DEFAULT_TEMPLATE ) ) ),
			'A template rule naming "default" matched a post that does have a template assigned.'
		);

		$this->go_to( get_permalink( $plain_id ) );

		$this->assertSame(
			self::MATCHED,
			$this->verdict( Url_Conditions::KEY_TEMPLATE, array( 'templates' => array( Url_Conditions::DEFAULT_TEMPLATE ) ) ),
			'A template rule naming "default" did not match a page with no template assigned. That is the value core\'s own template dropdown submits for "Default template", so an author selecting it in the editor and this condition must agree on what it means.'
		);

		$this->go_to( home_url( '/' ) );

		$this->assertSame(
			self::REJECTED,
			$this->verdict( Url_Conditions::KEY_TEMPLATE, array( 'templates' => array( Url_Conditions::DEFAULT_TEMPLATE ) ) ),
			'A template rule matched a non-singular request. No post is queried there, so no post carries an assignment and no named template can match — "default" included.'
		);
	}

	/**
	 * `url_path` matches the request path under all four modes.
	 *
	 * @dataProvider data_url_path_modes
	 *
	 * @param string $mode     Match mode.
	 * @param string $value    Literal to match against.
	 * @param string $expected Expected verdict.
	 * @param string $message  Why this case matters.
	 * @return void
	 */
	public function test_url_path_matches_under_every_mode( $mode, $value, $expected, $message ) {
		$this->go_to( home_url( '/campaigns/spring-appeal/' ) );

		$this->assertSame(
			$expected,
			$this->verdict(
				Url_Conditions::KEY_URL_PATH,
				array(
					'match' => $mode,
					'value' => $value,
				)
			),
			$message
		);
	}

	/**
	 * The four match modes, against the request path `/campaigns/spring-appeal/`.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public function data_url_path_modes() {
		return array(
			'exact hit'             => array( 'exact', '/campaigns/spring-appeal/', self::MATCHED, 'An exact url_path rule did not match the path it names.' ),
			'exact miss'            => array( 'exact', '/campaigns/', self::REJECTED, 'An exact url_path rule matched a path it does not name. Exact is not a prefix.' ),
			'exact ignores case'    => array( 'exact', '/CAMPAIGNS/Spring-Appeal/', self::MATCHED, 'An exact url_path rule failed on case alone. Comparison is case-insensitive, which is what stops a rule from silently missing a link somebody capitalized.' ),
			'trailing slash counts' => array( 'exact', '/campaigns/spring-appeal', self::REJECTED, 'An exact url_path rule matched a path differing by its trailing slash. The normalizer preserves one, so /about and /about/ are different paths and reconciling them is the author\'s decision rather than a silent rewrite.' ),
			'prefix hit'            => array( 'prefix', '/campaigns/', self::MATCHED, 'A prefix url_path rule did not match a path beginning with its value.' ),
			'prefix miss'           => array( 'prefix', '/appeals/', self::REJECTED, 'A prefix url_path rule matched a path that does not begin with its value.' ),
			'contains hit'          => array( 'contains', 'spring', self::MATCHED, 'A contains url_path rule did not match a path holding its value.' ),
			'contains miss'         => array( 'contains', 'winter', self::REJECTED, 'A contains url_path rule matched a path that does not hold its value.' ),
			'glob star'             => array( 'glob', '/campaigns/*', self::MATCHED, 'A glob url_path rule did not match through a trailing star.' ),
			'glob question mark'    => array( 'glob', '/campaigns/spring-appea?/', self::MATCHED, 'A glob url_path rule did not match through a single-character wildcard.' ),
			'glob miss'             => array( 'glob', '/campaigns/*/deeper/', self::REJECTED, 'A glob url_path rule matched a path its pattern does not describe.' ),
			'glob is not implicit'  => array( 'glob', '/campaigns/', self::REJECTED, 'A glob url_path rule with no wildcard behaved as a prefix. Glob without a metacharacter is an exact comparison.' ),
		);
	}

	/**
	 * `url_path` ignores the query string, on both sides of the comparison.
	 *
	 * A campaign link carries UTM parameters, so the same page arrives with and
	 * without a query string constantly. If the query took part in matching, a
	 * popup targeted at `/campaigns/spring-appeal/` would show to visitors who
	 * typed the URL and not to the ones who clicked the campaign email — the exact
	 * inverse of what the author wanted, and invisible in testing because the
	 * author tests by typing the URL.
	 *
	 * The rule value is asserted too: a value carrying a query string matches
	 * nothing, because the subject never has one to compare against.
	 *
	 * @return void
	 */
	public function test_url_path_ignores_the_query_string() {
		$this->go_to( home_url( '/campaigns/spring-appeal/?utm_source=email&utm_campaign=spring' ) );

		$this->assertStringContainsString(
			'utm_source=email',
			(string) ( $_SERVER['REQUEST_URI'] ?? '' ),
			'Fixture: the request must actually carry the query string, or there is nothing for the matcher to ignore.'
		);

		$this->assertSame(
			self::MATCHED,
			$this->verdict(
				Url_Conditions::KEY_URL_PATH,
				array(
					'match' => 'exact',
					'value' => '/campaigns/spring-appeal/',
				)
			),
			'An exact url_path rule stopped matching once the request carried a query string. Campaign links always carry one, so the popup would show to visitors who typed the URL and not to the ones who clicked the email.'
		);

		$this->assertSame(
			self::REJECTED,
			$this->verdict(
				Url_Conditions::KEY_URL_PATH,
				array(
					'match' => 'exact',
					'value' => '/campaigns/spring-appeal/?utm_source=email',
				)
			),
			'A url_path rule whose value carries a query string matched. The subject is the normalized path only — no scheme, host, query string or fragment — so there is nothing for such a value to equal.'
		);
	}

	/**
	 * `negate` inverts a rule the server actually resolved.
	 *
	 * Both directions, because a `negate` that is read but never applied and a
	 * `negate` that is applied unconditionally each pass one half of this.
	 *
	 * @return void
	 */
	public function test_negate_inverts_a_resolved_server_rule() {
		$post_id = self::factory()->post->create();

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'page' ) ), true ),
			'A negated post_type rule that would have failed did not pass. Negation is the only way to express "everywhere except", and without it an author has no way to exclude a section.'
		);

		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'post' ) ), true ),
			'A negated post_type rule that would have passed did not fail. Negation is being ignored, so every "everywhere except" rule targets exactly the section it was meant to exclude.'
		);

		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::IS_FRONT_PAGE, array(), true ),
			'A negated is_front_page rule did not pass on a singular post. A field-less condition must negate like any other.'
		);
	}

	/**
	 * A failing server rule omits the popup from the response entirely.
	 *
	 * Not hidden, not emitted-and-suppressed: absent. The config carries no entry,
	 * the markup is not printed, and no assets are enqueued. A popup that reached
	 * the page and relied on the browser to keep it shut would be readable in view
	 * source, would flash if the bundle failed to load, and would leak the site's
	 * targeting configuration to anybody who looked.
	 *
	 * The positive control is in the same test on purpose. Without it this would
	 * pass against an emitter that printed nothing anywhere.
	 *
	 * @return void
	 */
	public function test_a_failing_server_rule_omits_the_popup_from_the_response_entirely() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Annual report' ) );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertSame(
			self::REJECTED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'page' ) ) ),
			'Fixture: the rule must genuinely fail, or there is nothing to omit.'
		);

		$excluded = $this->emit();

		$this->assertSame(
			'',
			$excluded,
			'A popup whose server targeting failed still printed markup. It must be absent from the response, not hidden in it.'
		);

		Frontend::reset();
		Frontend::enqueue_assets();

		$this->assertFalse(
			wp_script_is( Frontend::HANDLE, 'enqueued' ),
			'The front-end bundle was enqueued on a page where the only popup was excluded by its own targeting. Such a page must cost a visitor no bytes at all.'
		);

		$this->assertSame(
			self::MATCHED,
			$this->verdict( Content_Conditions::POST_TYPE, array( 'types' => array( 'post' ) ) ),
			'Positive control: the same popup must survive once its rule matches, or the assertions above prove only that nothing is ever emitted.'
		);

		$included = $this->emit();

		$this->assertStringContainsString(
			'id="' . Frontend::CONFIG_ELEMENT_ID . '"',
			$included,
			'Positive control: a surviving popup must emit the config element.'
		);
		$this->assertStringContainsString(
			self::POPUP_SLUG,
			$included,
			'Positive control: a surviving popup must emit its own markup.'
		);

		$this->assertStringNotContainsString(
			self::POPUP_SLUG,
			$excluded,
			'The excluded response mentioned the popup\'s slug. Anything naming it — a data attribute, a config entry, a stylesheet hook — is targeting configuration leaked into a page the popup was excluded from.'
		);
		$this->assertStringNotContainsString(
			Frontend::CONFIG_ELEMENT_ID,
			$excluded,
			'The excluded response still carried the config element. With no popups to describe it must be absent rather than empty.'
		);
	}

	/**
	 * Returns the server's verdict on one rule, as the emitter reveals it.
	 *
	 * Written as a single-rule group on a real published popup, stored through the
	 * registered sanitizer, then read back out of what {@see Frontend} emitted:
	 *
	 * - the popup is missing from the config -> the rule was decided false
	 * - the popup is present and the rule was stripped -> decided true
	 * - the popup is present and the rule survives -> indeterminate
	 *
	 * The third case is why this reads the emitted rules rather than only the
	 * surviving popup list. A condition that quietly stopped deciding anything
	 * would leave every popup surviving, which looks identical to a condition that
	 * matched.
	 *
	 * @param string $type   Condition key.
	 * @param array  $values The rule's stored values.
	 * @param bool   $negate Optional. Whether the rule asks for inversion. Default false.
	 * @return string One of the verdict constants.
	 */
	private function verdict( $type, array $values, $negate = false ) {
		$popup_id = $this->popup();

		update_post_meta(
			$popup_id,
			Meta::CONDITIONS,
			array(
				'groups' => array(
					array(
						'rules' => array(
							array(
								'type'   => $type,
								'negate' => $negate,
								'values' => $values,
							),
						),
					),
				),
			)
		);

		Frontend::reset();

		foreach ( Frontend::config()['popups'] as $entry ) {
			if ( $popup_id !== $entry['id'] ) {
				continue;
			}

			$rules = $entry['conditions']['groups'][0]['rules'] ?? null;

			$this->assertIsArray(
				$rules,
				'The emitted config carries no rules array for the surviving popup, so this harness cannot tell a decided rule from a deferred one and every assertion built on it is meaningless.'
			);

			return array() === $rules ? self::MATCHED : self::DEFERRED;
		}

		return self::REJECTED;
	}

	/**
	 * Returns the popup under test, creating it on first use.
	 *
	 * One popup per test, re-targeted between verdicts. A fresh popup per verdict
	 * would leave earlier ones in the query, and a page carrying several popups
	 * would emit the config element whatever the rule under test decided.
	 *
	 * @return int Popup ID.
	 */
	private function popup() {
		if ( 0 === $this->popup_id ) {
			$this->popup_id = self::factory()->post->create(
				array(
					'post_type'    => Post_Type::POST_TYPE,
					'post_status'  => 'publish',
					'post_name'    => self::POPUP_SLUG,
					'post_title'   => 'Server conditions fixture',
					'post_content' => '<p>Give today.</p>',
				)
			);
		}

		return $this->popup_id;
	}

	/**
	 * Renders what popkit prints into the current request.
	 *
	 * @return string Emitted markup, empty when nothing survived.
	 */
	private function emit() {
		Frontend::reset();

		ob_start();
		Frontend::render();

		return (string) ob_get_clean();
	}

	/**
	 * Switches the site to a static front page and a separate posts page.
	 *
	 * This is the configuration in which `is_front_page()` and `is_home()` point at
	 * different URLs, and therefore the only one in which a condition built on the
	 * wrong tag is visible.
	 *
	 * @return array{front: int, posts: int} IDs of the assigned pages.
	 */
	private function use_static_front_page() {
		$front_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Welcome',
			)
		);
		$posts_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'News',
			)
		);

		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front_id );
		update_option( 'page_for_posts', $posts_id );

		return array(
			'front' => $front_id,
			'posts' => $posts_id,
		);
	}
}
