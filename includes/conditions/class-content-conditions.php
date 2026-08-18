<?php
/**
 * Registration and server-side evaluation of the five content conditions.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit\Conditions;

use Popkit\Condition;
use Popkit\Context;
use WP_Post;
use WP_Post_Type;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the content conditions and decides them against the resolved query.
 *
 * Five conditions live here, all `Context::Server`, all in the editor's `content`
 * group: `post_type`, `post_ids`, `taxonomy_term`, `is_front_page` and `is_404`.
 * Each asks a question about *the page being served* — never about the visitor
 * looking at it.
 *
 * ## Cache safety
 *
 * The invariant from docs/CLAUDE.md, restated because this file is where it would
 * be broken: a server condition may depend only on the URL. A page cache serves
 * one HTML response to many visitors, so two requests to the same URL carrying
 * different cookies and different user agents must produce byte-identical HTML.
 *
 * Nothing below calls `is_user_logged_in()`, `wp_get_current_user()`,
 * `get_current_user_id()` or `current_user_can()`, and nothing reads `$_COOKIE`,
 * `HTTP_REFERER`, `HTTP_USER_AGENT` or the clock. A grep of this file for any of
 * them returns nothing, and that is a reviewable property rather than a claim.
 * Every function it does call — `is_singular()`, `is_post_type_archive()`,
 * `is_home()`, `is_tax()`'s underlying queried object, `has_term()`,
 * `is_front_page()`, `is_404()` — reads the main query, which WordPress resolved
 * from the request path, or a site setting such as the page assigned as the front
 * page. Both are identical for every visitor of a given URL.
 *
 * Anything that varies by visitor is a client condition and lives elsewhere.
 *
 * ## Where the evaluate function lives, and why
 *
 * On this class, as one static dispatcher keyed by condition key — not on the
 * {@see Condition} value object and not in the registry.
 *
 * {@see Condition} is readonly and deliberately carries no behaviour: its
 * docblock states that it evaluates nothing, and its constructor takes a fixed
 * five arguments. Adding a callable to it would put a closure inside a value
 * object that is otherwise pure data, JSON-serialised straight to the editor by
 * `to_schema()`, and compared by value in tests. {@see \Popkit\Conditions} is a
 * key-to-object map with the same reason to stay data-only.
 *
 * So evaluation is a separate map, and it sits next to the declarations it
 * evaluates: the field schema and the code reading those fields change together,
 * in one file, and a reviewer can check that `types` is declared as `string[]`
 * and read as `string[]` without opening a second class.
 *
 * {@see Content_Conditions::evaluate()} implements exactly the contract
 * {@see \Popkit\Rule_Evaluator::group_passes_server()} documents:
 *
 *     ( array $rule, Condition $condition ): ?bool
 *
 * True or false decides the rule; null declares that this class cannot. A key it
 * does not own is null, so several such dispatchers compose by first non-null.
 *
 * ## How the decision reaches Rule_Evaluator
 *
 * Two ways, both supported, because they answer different needs:
 *
 * 1. Directly. `Rule_Evaluator::rule_set_passes_server( $set, array( Content_Conditions::class, 'evaluate' ) )`
 *    works as-is for a caller that only cares about these five.
 * 2. Through the `popkit_evaluate_server_rule` filter, which this class attaches
 *    to in {@see Content_Conditions::init()}. A first-party evaluator that runs
 *    `apply_filters( 'popkit_evaluate_server_rule', null, $rule, $condition )`
 *    collects every registered server condition, including one from a third-party
 *    plugin. Without a filter, a plugin could register a `Context::Server`
 *    condition that nothing is able to decide — its rules would be indeterminate
 *    forever, and the registry invariant that registering a condition requires no
 *    core change would hold for the schema and quietly fail for the behaviour.
 *
 * The filter callback decides only when no earlier callback has, and only when it
 * was handed the documented argument shape; anything else passes straight
 * through, so an aggregator that dispatches differently cannot make this class
 * answer the wrong question.
 *
 * ## Semantics, per condition
 *
 * | Key | Matches |
 * |---|---|
 * | `post_type` | The type of content the URL shows: a singular view's own post type, a post type archive's type, or `post` on the blog posts index. |
 * | `post_ids` | The queried post ID, on singular views only. |
 * | `taxonomy_term` | A term archive for one of the listed terms, or a singular post carrying one of them. |
 * | `is_front_page` | Whatever Settings -> Reading calls the front page. |
 * | `is_404` | A request WordPress resolved to nothing. |
 *
 * Each is spelled out on the method that implements it, including the readings
 * that were rejected.
 *
 * **An empty list matches nothing.** A `post_type` rule with no types selected,
 * or a `taxonomy_term` rule with no terms, is unsatisfiable: nothing is a member
 * of the empty set. Negating one therefore matches every page, which is the same
 * statement read the other way round and is not a special case. Phase 5's editor
 * warns about a condition set that can never match; this class does not
 * second-guess what the author stored.
 *
 * ## Testing
 *
 * {@see Content_Conditions::all()} needs no WordPress beyond `__()`, so the field
 * schemas are unit-testable. So are
 * {@see Content_Conditions::post_type_matches()} and
 * {@see Content_Conditions::post_id_matches()}, which are pure functions of a
 * value and a stored rule and hold the empty-list and membership semantics —
 * they are public for that reason.
 *
 * Everything else asks WordPress a question about a resolved `WP_Query` and
 * belongs to the integration suite: the conditional tags read the main query
 * globals, and a faked query context would be testing the fake. `taxonomy_term`,
 * `is_front_page` and `is_404` are integration-only in their entirety.
 *
 * ## Namespace
 *
 * `Popkit\Conditions` is both the registry class and this file's namespace, the
 * same arrangement {@see \Popkit\Triggers\Builtin_Triggers} lives with. It is
 * what the autoloaders expect — `Popkit\Conditions\Content_Conditions` resolves
 * to includes/conditions/class-content-conditions.php — but it means an
 * unqualified `Conditions` here would name `Popkit\Conditions\Conditions`, which
 * does not exist. The registry is therefore always written fully qualified.
 *
 * @since 0.1.0
 */
final class Content_Conditions {

	/**
	 * Key of the condition matching the type of content a URL shows.
	 *
	 * The five keys are constants rather than repeated literals because each one
	 * appears twice in this file — once in the declaration and once in the
	 * evaluation dispatch — and a typo in the second would register a working
	 * control whose rule silently never decides anything.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const POST_TYPE = 'post_type';

	/**
	 * Key of the condition matching specific posts by ID.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const POST_IDS = 'post_ids';

	/**
	 * Key of the condition matching a taxonomy term.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const TAXONOMY_TERM = 'taxonomy_term';

	/**
	 * Key of the condition matching the site's front page.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const IS_FRONT_PAGE = 'is_front_page';

	/**
	 * Key of the condition matching a 404 response.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const IS_404 = 'is_404';

	/**
	 * Filter that collects a decision for one resolved server rule.
	 *
	 * Applied as `apply_filters( self::EVALUATE_FILTER, null, $rule, $condition )`
	 * and expected to return true, false, or null for "not mine". Named here so
	 * the string this class attaches to and the string an evaluator applies are
	 * the same one.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const EVALUATE_FILTER = 'popkit_evaluate_server_rule';

	/**
	 * Longest taxonomy name the `taxonomy` field accepts, in bytes.
	 *
	 * `register_taxonomy()` refuses a name longer than 32 characters, so a stored
	 * value above the cap could never name a taxonomy that exists. The cap is
	 * declared rather than left off because a `string` field without `max_length`
	 * is stored uncapped, and an uncapped field is one more thing that reaches the
	 * database unbounded.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_TAXONOMY_LENGTH = 32;

	/**
	 * Attaches registration and evaluation to their hooks.
	 *
	 * Called from `Plugin::boot()` on `plugins_loaded`. Attaching rather than
	 * registering immediately is the whole point: {@see \popkit_conditions()}
	 * builds its registry lazily and dispatches `popkit_register_conditions`
	 * exactly once, tied to construction, so calling the accessor here to register
	 * directly would fire the action before any extension had attached to it and
	 * every third-party registration would be silently dropped.
	 *
	 * Both callbacks are array callables rather than first-class callables so the
	 * hooks keep a stable identity: WordPress deduplicates them, a test can assert
	 * them by name, and a site owner can remove them. A first-class callable
	 * produces a new closure on every call and would do none of those things — and
	 * a second attachment would re-run {@see \Popkit\Conditions::register()} on
	 * keys that are already taken, which raises.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'popkit_register_conditions', array( self::class, 'register' ) );
		add_filter( self::EVALUATE_FILTER, array( self::class, 'filter_server_rule' ), 10, 3 );
	}

	/**
	 * Registers every content condition.
	 *
	 * Registration order is preserved by the registry and is what the editor
	 * presents, so the order in {@see Content_Conditions::all()} is the order an
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
	 * Builds every content condition, in the order the editor lists them.
	 *
	 * Returned as a list rather than registered inline so that a test can inspect
	 * the declarations without a registry, and so the ordering decision is visible
	 * in one place. The order is the order of docs/data-model.md -> Built-in
	 * conditions, so the table and the sidebar agree.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition[] Content conditions.
	 */
	public static function all(): array {
		return array(
			self::post_type(),
			self::post_ids(),
			self::taxonomy_term(),
			self::front_page(),
			self::not_found(),
		);
	}

	/**
	 * Decides one content rule against the resolved query, or declines to.
	 *
	 * The evaluator contract from {@see \Popkit\Rule_Evaluator::group_passes_server()}:
	 * the rule and the registered {@see Condition} its `type` names come in, and a
	 * boolean decides the rule while null declares that this evaluator cannot.
	 * `negate` is not read here and must not be — `Rule_Evaluator` applies it, and
	 * only to a rule that was actually decided.
	 *
	 * Two things produce null. A key this class does not own, so that several
	 * dispatchers compose by first non-null. And a request whose main query has
	 * not been resolved: every question below is a question about that query, the
	 * conditional tags answer false before it has run rather than answering at
	 * all, and reporting a false nobody checked would fail a group on the strength
	 * of nothing. Deferred instead, the rule travels to a client that has no
	 * evaluate function for a server-context type and is denied there — the popup
	 * is suppressed, which is the direction this plugin fails in.
	 *
	 * Missing or malformed `values` are read as an empty array, which makes every
	 * list empty, which matches nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param array     $rule      Stored rule, as described in docs/data-model.md.
	 * @param Condition $condition Registered condition the rule's `type` names.
	 * @return bool|null True or false once the rule is decided, null when this
	 *                   class cannot decide it.
	 */
	public static function evaluate( array $rule, Condition $condition ): ?bool {
		if ( ! self::query_is_resolved() ) {
			return null;
		}

		$values = self::values_of( $rule );

		return match ( $condition->key ) {
			self::POST_TYPE     => self::post_type_matches( self::queried_post_type(), $values ),
			self::POST_IDS      => self::post_id_matches( self::queried_post_id(), $values ),
			self::TAXONOMY_TERM => self::taxonomy_term_matches( $values ),
			self::IS_FRONT_PAGE => is_front_page(),
			self::IS_404        => is_404(),
			default             => null,
		};
	}

	/**
	 * Supplies this class's decision to an aggregating server-rule evaluator.
	 *
	 * Filter callback for {@see Content_Conditions::EVALUATE_FILTER}. It answers
	 * only when nothing has answered yet, so the first condition class to claim a
	 * key wins and priority is the tie-break, and only when the arguments have the
	 * documented shape. Both guards return the incoming decision untouched rather
	 * than substituting one of their own: a callback that cannot tell what it was
	 * asked has not decided anything, and turning that into a boolean is how a
	 * group passes targeting nobody checked.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $decision  Decision so far: true, false, or null when undecided.
	 * @param mixed $rule      Stored rule, expected to be an array.
	 * @param mixed $condition Registered condition, expected to be a {@see Condition}.
	 * @return mixed The decision, unchanged when this class has nothing to add.
	 */
	public static function filter_server_rule( mixed $decision, mixed $rule, mixed $condition ): mixed {
		if ( null !== $decision ) {
			return $decision;
		}

		if ( ! is_array( $rule ) || ! $condition instanceof Condition ) {
			return $decision;
		}

		return self::evaluate( $rule, $condition );
	}

	/**
	 * Whether a post type is one of those a `post_type` rule lists.
	 *
	 * Pure, and public so the membership semantics can be unit tested without a
	 * WordPress query: reading the current post type is the part that needs one.
	 *
	 * Null means the URL shows no single kind of content — see
	 * {@see Content_Conditions::queried_post_type()} — and matches nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $post_type Post type the URL is showing, or null when it shows none.
	 * @param array       $values    The rule's stored values.
	 * @return bool True when the post type is listed.
	 */
	public static function post_type_matches( ?string $post_type, array $values ): bool {
		$types = self::string_list( $values, 'types' );

		if ( null === $post_type || array() === $types ) {
			return false;
		}

		return in_array( $post_type, $types, true );
	}

	/**
	 * Whether a post ID is one of those a `post_ids` rule lists.
	 *
	 * Pure, and public for the same reason as
	 * {@see Content_Conditions::post_type_matches()}.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $post_id ID of the queried post, or null on a non-singular view.
	 * @param array    $values  The rule's stored values.
	 * @return bool True when the ID is listed.
	 */
	public static function post_id_matches( ?int $post_id, array $values ): bool {
		$ids = self::int_list( $values, 'ids' );

		if ( null === $post_id || array() === $ids ) {
			return false;
		}

		return in_array( $post_id, $ids, true );
	}

	/**
	 * Matches the kind of content a URL shows.
	 *
	 * `types` is a list of post type names. The control is the shared
	 * `post-type-select`, so the editor offers the site's registered types and the
	 * stored value stays a list of names rather than of labels.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function post_type(): Condition {
		return new Condition(
			self::POST_TYPE,
			Context::Server,
			'content',
			__( 'Post type', 'popkit' ),
			array(
				'types' => array(
					'type'    => 'array',
					'items'   => 'string',
					'default' => array(),
					'label'   => __( 'Post types this rule matches', 'popkit' ),
					'control' => 'post-type-select',
				),
			)
		);
	}

	/**
	 * Matches named posts by ID.
	 *
	 * The control map has no post picker in v1, so IDs are chosen through the
	 * generic `multiselect`. Adding a picker means adding it to
	 * {@see Condition::FIELD_CONTROLS} *and* to the shared control map the editor
	 * renders from, which docs/CLAUDE.md makes a deliberately reviewable act
	 * rather than something a registration can do on its own.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function post_ids(): Condition {
		return new Condition(
			self::POST_IDS,
			Context::Server,
			'content',
			__( 'Specific posts', 'popkit' ),
			array(
				'ids' => array(
					'type'    => 'array',
					'items'   => 'integer',
					'default' => array(),
					'label'   => __( 'IDs of the posts this rule matches', 'popkit' ),
					'control' => 'multiselect',
				),
			)
		);
	}

	/**
	 * Matches a taxonomy term.
	 *
	 * `terms` holds term IDs rather than slugs or names. An ID survives a rename;
	 * a slug does not, and a targeting rule that silently stops matching because
	 * an editor tidied a category name is the kind of failure nobody attributes to
	 * the popup plugin.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function taxonomy_term(): Condition {
		return new Condition(
			self::TAXONOMY_TERM,
			Context::Server,
			'content',
			__( 'Taxonomy term', 'popkit' ),
			array(
				'taxonomy' => array(
					'type'       => 'string',
					'default'    => '',
					'label'      => __( 'Taxonomy the terms belong to', 'popkit' ),
					'control'    => 'taxonomy-select',
					'max_length' => self::MAX_TAXONOMY_LENGTH,
				),
				'terms'    => array(
					'type'    => 'array',
					'items'   => 'integer',
					'default' => array(),
					'label'   => __( 'IDs of the terms this rule matches', 'popkit' ),
					'control' => 'multiselect',
				),
			)
		);
	}

	/**
	 * Matches the site's front page.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function front_page(): Condition {
		return new Condition(
			self::IS_FRONT_PAGE,
			Context::Server,
			'content',
			__( 'Front page', 'popkit' ),
			array()
		);
	}

	/**
	 * Matches a request that resolved to nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function not_found(): Condition {
		return new Condition(
			self::IS_404,
			Context::Server,
			'content',
			__( '404 page', 'popkit' ),
			array()
		);
	}

	/**
	 * Returns the post type the current URL is showing, or null.
	 *
	 * What an author means by "post type is post" is *this page shows posts*, and
	 * that reading has to survive three different query shapes:
	 *
	 * - **Singular** — the queried post's own type. Checked first, because a static
	 *   front page is a singular `page` and would otherwise be read as the blog.
	 * - **Post type archive** — the archive's own type. `get_post_type()` alone is
	 *   wrong here: with no argument it reads the global `$post`, which outside the
	 *   loop is whatever a previous query left behind, and popkit's matching runs
	 *   before the theme's loop starts. The queried object is the post type object
	 *   itself and needs no loop.
	 * - **Blog posts index** — `post`. `is_home()` covers both shapes of it: the
	 *   site root when Settings -> Reading shows latest posts, and the separate
	 *   page assigned as the posts page when it does not. Note that the queried
	 *   object there is that assigned *page*, so reading its post type would answer
	 *   `page` for a URL that lists nothing but posts.
	 *
	 * Everything else is null, and a `post_type` rule on it is false: date, author
	 * and search results are not one kind of content. A search covers every
	 * searchable type, an author archive covers whatever that author wrote, and
	 * answering `post` for them would be a guess about the site's query filters
	 * rather than a fact about the URL. Target those with `url_path`.
	 *
	 * Term archives are also null. They are what `taxonomy_term` is for, and a
	 * category archive listing posts is a fact about the theme's loop, not about
	 * the URL.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null Post type name, or null when the URL shows no single type.
	 */
	private static function queried_post_type(): ?string {
		if ( is_singular() ) {
			$queried = get_queried_object();

			return $queried instanceof WP_Post ? $queried->post_type : null;
		}

		if ( is_post_type_archive() ) {
			$queried = get_queried_object();

			return $queried instanceof WP_Post_Type ? $queried->name : null;
		}

		if ( is_home() ) {
			return 'post';
		}

		return null;
	}

	/**
	 * Returns the ID of the post the current URL resolves to, or null.
	 *
	 * Singular views only. On anything else there is no single post the URL is
	 * *about*, and the rule is false rather than an error.
	 *
	 * The blog posts index is deliberately excluded even though it does resolve to
	 * a `WP_Post` — `get_queried_object()` returns the page assigned in Settings ->
	 * Reading. Including it would make "specific posts" mean two different things
	 * on one site: the object being displayed everywhere else, and the object being
	 * *listed from* there. An author who wants a popup on the blog index has
	 * `post_type` and `url_path`, both of which say what they mean.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null Queried post ID, or null on a non-singular view.
	 */
	private static function queried_post_id(): ?int {
		if ( ! is_singular() ) {
			return null;
		}

		$queried = get_queried_object();

		return $queried instanceof WP_Post ? (int) $queried->ID : null;
	}

	/**
	 * Whether the current URL is a listed term, or a post carrying one.
	 *
	 * Both readings match, and that is the decision this method makes. An author
	 * choosing "Category: Fundraising" means *pages about fundraising*: the
	 * category archive and the posts filed under it are the same campaign to them,
	 * and making them add a second rule to cover the other half would be a trap
	 * whose failure mode is a popup that quietly never appears on half its pages.
	 * The two cases cannot conflict, because no URL is both a term archive and a
	 * singular post.
	 *
	 * - **Term archive** — the queried object is a `WP_Term`. It must belong to the
	 *   declared taxonomy and be one of the listed terms. This covers `is_tax()`
	 *   plus categories and tags, which `is_tax()` deliberately excludes, without
	 *   asking three separate questions.
	 * - **Singular** — `has_term()` against the queried post.
	 *
	 * Matching is exact and does **not** descend a hierarchy: a rule naming a
	 * parent category does not match a post filed only under its child, matching
	 * `has_term()`'s own semantics. Expanding to descendants would mean a term
	 * query on every pageview and a rule whose reach changes when somebody
	 * reorganises a taxonomy.
	 *
	 * Three inputs short-circuit to false before WordPress is asked anything: no
	 * taxonomy, no terms, and a taxonomy nothing has registered. The empty-terms
	 * case is not tidiness — `has_term()` with an empty list reports true for a
	 * post carrying *any* term in the taxonomy, so passing it through would turn a
	 * rule that selected nothing into a rule matching most of the site.
	 *
	 * @since 0.1.0
	 *
	 * @param array $values The rule's stored values.
	 * @return bool True when the URL matches one of the listed terms.
	 */
	private static function taxonomy_term_matches( array $values ): bool {
		$taxonomy = self::string_value( $values, 'taxonomy' );
		$terms    = self::int_list( $values, 'terms' );

		if ( '' === $taxonomy || array() === $terms || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$queried = get_queried_object();

		if ( $queried instanceof WP_Term ) {
			return $queried->taxonomy === $taxonomy && in_array( (int) $queried->term_id, $terms, true );
		}

		if ( is_singular() && $queried instanceof WP_Post ) {
			return (bool) has_term( $terms, $taxonomy, $queried );
		}

		return false;
	}

	/**
	 * Whether WordPress has resolved the main query for this request.
	 *
	 * `wp` fires at the end of `WP::main()`, after the query has run and the
	 * conditional tags have something to read. Before it — on `init`, in the admin,
	 * or inside a REST request, which is dispatched from `parse_request` and never
	 * reaches the action — the tags return false and emit a `_doing_it_wrong()`
	 * notice saying so.
	 *
	 * Asking is cheaper than the two failures it prevents: a debug notice on a
	 * request that had no business evaluating page targeting, and a group failed by
	 * a false that describes the absence of a query rather than the page.
	 *
	 * `did_action()` never decrements, which is correct here — a query, once
	 * resolved, stays resolved for the rest of the request.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the conditional tags can be trusted.
	 */
	private static function query_is_resolved(): bool {
		return did_action( 'wp' ) > 0;
	}

	/**
	 * Returns a rule's stored values, always as an array.
	 *
	 * @since 0.1.0
	 *
	 * @param array $rule Stored rule.
	 * @return array The rule's values, or an empty array when it carries none.
	 */
	private static function values_of( array $rule ): array {
		$values = $rule['values'] ?? null;

		return is_array( $values ) ? $values : array();
	}

	/**
	 * Reads one string field off a rule's values.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $values The rule's stored values.
	 * @param string $field  Field name.
	 * @return string The stored string, or an empty string when it is absent or not one.
	 */
	private static function string_value( array $values, string $field ): string {
		$value = $values[ $field ] ?? null;

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Reads one string-list field off a rule's values.
	 *
	 * Sanitization already stores a flat list of strings, so this is for meta
	 * written by hand or restored from an older export. Entries that are not
	 * usable strings are skipped rather than coerced: `array( 'post', 0 )` should
	 * mean "post", not "post and whatever `0` casts to".
	 *
	 * @since 0.1.0
	 *
	 * @param array  $values The rule's stored values.
	 * @param string $field  Field name.
	 * @return string[] The stored list, empty when the field is absent or holds nothing usable.
	 */
	private static function string_list( array $values, string $field ): array {
		$raw = $values[ $field ] ?? null;

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$list = array();

		foreach ( $raw as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$list[] = $item;
			}
		}

		return $list;
	}

	/**
	 * Reads one integer-list field off a rule's values.
	 *
	 * Numeric strings are accepted because an ID that made a round trip through
	 * JSON or through an export may arrive as `"42"`, and reading that as no ID at
	 * all would silently empty the rule — which matches nothing. `ctype_digit()`
	 * rather than a pattern, per the no-regex invariant, and it also rejects
	 * negatives and decimals on its own.
	 *
	 * Non-positive values are dropped: no post or term carries one, so keeping them
	 * would only pad the list.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $values The rule's stored values.
	 * @param string $field  Field name.
	 * @return int[] The stored IDs, empty when the field is absent or holds nothing usable.
	 */
	private static function int_list( array $values, string $field ): array {
		$raw = $values[ $field ] ?? null;

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$list = array();

		foreach ( $raw as $item ) {
			if ( is_string( $item ) && '' !== $item && ctype_digit( $item ) ) {
				$item = (int) $item;
			}

			if ( is_int( $item ) && 0 < $item ) {
				$list[] = $item;
			}
		}

		return $list;
	}
}
