<?php
/**
 * Registration and server-side evaluation of the `template` and `url_path` conditions.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit\Conditions;

use Popkit\Condition;
use Popkit\Context;
use Popkit\Url_Matcher;
use Popkit\Vocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the two server conditions that describe *where* a request landed.
 *
 * Both are `Context::Server`, which is a promise about their inputs rather than
 * a note about where the code runs: each is a pure function of the URL, of the
 * query WordPress resolved from it, and of stored content. Two requests to the
 * same URL therefore reach the same verdict, whatever they carry with them, and
 * the emitted HTML stays byte-identical — the invariant in `docs/CLAUDE.md` ->
 * Cache safety. Nothing here reads a cookie, a request header, a session, or the
 * clock, and a reviewer grepping this file for those inputs is meant to come away
 * empty-handed.
 *
 * | Key | Fields | Answers |
 * |---|---|---|
 * | `template` | `templates: string[]` | Which page template the queried post was assigned. |
 * | `url_path` | `match`, `value` | Whether the normalized request path matches a literal. |
 *
 * Field schemas only, plus the evaluation each one needs. There is no
 * `Abstract_Condition` to inherit from and no sanitization here: a field's `type`
 * drives its sanitizer, per `docs/CLAUDE.md` -> Registry invariants.
 *
 * ## What `template` means, and what it does not
 *
 * It matches the **page template assigned to the queried post** — the value
 * behind the "Template" control an author sees while editing that post, read
 * through `get_page_template_slug()`. That is the one reading an editor can act
 * on: they picked a template from a list on the post, and this condition asks
 * whether they picked one of the ones named here.
 *
 * A stored value is compared verbatim and case-sensitively, so it must be written
 * the way the theme names it:
 *
 * - Classic theme — a template file name relative to the theme root, such as
 *   `template-wide.php` or `page-templates/full-width.php`.
 * - Block theme — a block template slug with no extension, such as
 *   `page-no-title`. The control is the same control and the value lands in the
 *   same place, so this condition works on a block theme; the strings an author
 *   selects simply differ, and a rule written against a classic theme does not
 *   survive a switch to a block theme. That is a retargeting job, not something
 *   this condition can paper over.
 *
 * `default` — {@see Url_Conditions::DEFAULT_TEMPLATE} — names "no template
 * assigned". It is the value core's own template dropdown submits for "Default
 * template", and `is_page_template( 'default' )` reads it the same way, so an
 * author selecting it in the editor and this condition agree on what it means.
 *
 * **The template *hierarchy* is deliberately not what this matches.** A rule
 * naming `single.php`, `archive.php` or `index.php` matches nothing, because
 * nothing assigns those to a post. Resolving them would mean reading the
 * `template_include` filter, and that value is untrustworthy for this purpose in
 * exactly the situation it would be most relied on: under a block theme, every
 * front-end request resolves to the one canvas file that renders block templates,
 * so a hierarchy-based condition would compare every page against a single
 * constant and silently match all of them or none. A targeting condition that
 * quietly means something else on half the themes in the directory is worse than
 * one with a stated limit. Hierarchy-shaped targeting is expressed with
 * `post_type`, `is_front_page`, `is_404` and `url_path` instead.
 *
 * A non-singular request — an archive, a search, a 404, the blog posts index —
 * carries no assignment at all, so it matches no template, `default` included.
 * `is_page_template()` behaves the same way, for the same reason.
 *
 * The stored value is only ever compared. It is never resolved to a path, never
 * passed to a file function, and never printed, so it cannot reach the
 * filesystem or the response however it was written.
 *
 * ## What `url_path` matches
 *
 * The normalized path of the current request, through
 * {@see Url_Matcher::normalize_path()} and then {@see Url_Matcher::matches()}.
 * That class is the plugin's only matcher and this condition adds nothing to it:
 * no second normalizer, no second glob, and no pattern language. Matching is
 * linear and case-insensitive, the query string and the fragment are already gone
 * by the time a comparison happens, and the path is decoded exactly once —
 * inside `normalize_path()`, which is why nothing here decodes it again.
 *
 * Two limits worth stating plainly, because both are visible to an author:
 *
 * - The path is the one the request carried, so on an installation in a
 *   subdirectory it includes that subdirectory. A rule for a site served from
 *   `/blog/` is written `/blog/campaigns/`, not `/campaigns/`.
 * - A trailing slash is significant. `normalize_path()` preserves it, so `/about`
 *   and `/about/` are different paths, and reconciling them is a decision for the
 *   author rather than a rewrite this plugin performs on their behalf.
 *
 * ## Why an unusable rule declines instead of failing
 *
 * {@see \Popkit\Rule_Evaluator} applies `negate` to whatever boolean an evaluator
 * hands back, and only to that. So `false` is not the safe answer for a rule this
 * class cannot make sense of: a rule carrying an unknown match mode, an
 * over-length value, or values of the wrong shape entirely would fail, `negate`
 * would invert it, and a popup targeted at one path would show on every page.
 * Every such rule returns null instead — indeterminate. The group survives, the
 * rule is emitted, and the client fails it closed without applying `negate`,
 * which is the documented asymmetry in `docs/data-model.md` -> Evaluation
 * semantics.
 *
 * `false` is reserved for questions that genuinely have an answer: this request
 * has a template and it is not one of the named ones; this path exists and it
 * does not match. Those are the answers `negate` is allowed to invert.
 *
 * ## Namespace
 *
 * `Popkit\Conditions` is both the registry class and this file's namespace, the
 * same arrangement {@see \Popkit\Triggers\Builtin_Triggers} lives with. An
 * unqualified `Conditions` here would resolve to `Popkit\Conditions\Conditions`,
 * which does not exist, so the registry is always written fully qualified.
 *
 * @since 0.1.0
 */
final class Url_Conditions {

	/**
	 * Registry key of the page template condition.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const KEY_TEMPLATE = 'template';

	/**
	 * Registry key of the URL path condition.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const KEY_URL_PATH = 'url_path';

	/**
	 * Stored value meaning "this post has no page template assigned".
	 *
	 * `get_page_template_slug()` reports both an absent `_wp_page_template` meta
	 * value and a literal `default` as an empty string, so an author who wants to
	 * target unassigned posts has nothing to name. This constant is that name, and
	 * it is `default` rather than something popkit invented because that is the
	 * value core's own template dropdown submits for "Default template" and the
	 * value `is_page_template()` accepts for the same question.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const DEFAULT_TEMPLATE = 'default';

	/**
	 * Attaches registration to `popkit_register_conditions`.
	 *
	 * Called from `Plugin::boot()` on `plugins_loaded`. Attaching rather than
	 * registering immediately is the point: {@see \popkit_conditions()} builds its
	 * registry lazily and dispatches that action exactly once, tied to
	 * construction, so calling the accessor here would fire the action before any
	 * extension had attached to it and every third-party registration would be
	 * silently dropped.
	 *
	 * The callback is an array callable rather than a first-class callable so the
	 * hook keeps a stable identity: WordPress deduplicates it, a test can assert it
	 * by name, and a site owner can remove it. A first-class callable produces a
	 * new closure on every call and would do none of those things — and a second
	 * attachment would re-run {@see \Popkit\Conditions::register()} on keys that
	 * are already taken, which raises.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'popkit_register_conditions', array( self::class, 'register' ) );
	}

	/**
	 * Registers both conditions.
	 *
	 * Registration order is preserved by the registry and is what the editor
	 * presents, so the order in {@see Url_Conditions::all()} is the order an author
	 * sees.
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
	 * Builds both conditions, in the order the editor lists them.
	 *
	 * Returned as a list rather than registered inline so a test can inspect the
	 * declarations without a registry.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition[] The conditions this class owns.
	 */
	public static function all(): array {
		return array(
			self::template(),
			self::url_path(),
		);
	}

	/**
	 * Decides one rule of a type this class owns.
	 *
	 * Shaped as the evaluator {@see \Popkit\Rule_Evaluator::group_passes_server()}
	 * expects — `( array $rule, Condition $condition ): ?bool` — so it can be
	 * passed straight through, or chained behind another registration's evaluator
	 * with `??`, without an adapter.
	 *
	 * A key this class did not register returns null, which is the same answer it
	 * gives for a rule it cannot read: not "this rule failed", but "this evaluator
	 * has no verdict". The distinction is the whole of
	 * {@see \Popkit\Rule_Evaluator}'s contract — a boolean fails a group and is
	 * subject to `negate`, null defers.
	 *
	 * @since 0.1.0
	 *
	 * @param array     $rule      Stored rule, as described in `docs/data-model.md`.
	 * @param Condition $condition Registered condition the rule's type names.
	 * @return bool|null True or false once the rule is decided, null when it is not.
	 */
	public static function evaluate( array $rule, Condition $condition ): ?bool {
		return match ( $condition->key ) {
			self::KEY_TEMPLATE => self::evaluate_template( $rule ),
			self::KEY_URL_PATH => self::evaluate_url_path( $rule ),
			default            => null,
		};
	}

	/**
	 * Matches the page template assigned to the queried post.
	 *
	 * The control is `multiselect` because the field is a list. The field schema
	 * vocabulary carries no option list — see {@see Condition} — so the editor
	 * sources the theme's templates itself and an author may also type a value the
	 * active theme does not offer, which is what keeps a rule readable after a
	 * theme switch instead of quietly emptying.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function template(): Condition {
		return new Condition(
			self::KEY_TEMPLATE,
			Context::Server,
			'content',
			__( 'Page template', 'popkit' ),
			array(
				'templates' => array(
					'type'    => 'array',
					'items'   => 'string',
					'default' => array(),
					'label'   => __( 'Page templates to match, as the theme names them. Use "default" for a page with no template assigned.', 'popkit' ),
					'control' => 'multiselect',
				),
			)
		);
	}

	/**
	 * Matches the normalized path of the current request.
	 *
	 * The two fields are the constrained match language in `docs/CLAUDE.md` ->
	 * Security -> URL match language, declared from {@see Url_Matcher} rather than
	 * restated: the mode list is `Url_Matcher::modes()` and the length cap is
	 * `Url_Matcher::MAX_VALUE_LENGTH`, so the editor's selector, save-time
	 * validation, and the runtime walk can never drift apart. There is no regex
	 * mode and there will not be one.
	 *
	 * The cap is declared once, here, as `max_length`. Neither the registration nor
	 * the evaluation below re-states the number.
	 *
	 * @since 0.1.0
	 *
	 * @return Condition
	 */
	private static function url_path(): Condition {
		return new Condition(
			self::KEY_URL_PATH,
			Context::Server,
			'content',
			__( 'URL path', 'popkit' ),
			array(
				'match' => array(
					'type'        => 'enum',
					'enum'        => Url_Matcher::modes(),
					'enum_labels' => Vocabulary::url_match_modes(),
					'default'     => 'exact',
					'label'       => __( 'How the path is compared', 'popkit' ),
					'control'     => 'select',
				),
				'value' => array(
					'type'       => 'string',
					'default'    => '',
					'label'      => __( 'Path to match, beginning with a slash. The query string and fragment are ignored.', 'popkit' ),
					'control'    => 'url-match',
					'max_length' => Url_Matcher::MAX_VALUE_LENGTH,
				),
			)
		);
	}

	/**
	 * Decides a `template` rule.
	 *
	 * A rule naming no usable template is declined rather than failed. "The
	 * template is one of nothing" has an obvious reading — it matches nothing — but
	 * that reading is a boolean, `negate` would invert it, and an author who had
	 * not finished filling the control in would have written "show this everywhere"
	 * without meaning to. Declining suppresses the popup through the client's
	 * fail-closed path instead, which `negate` never touches.
	 *
	 * Entries that are not non-empty strings are ignored rather than compared. The
	 * sanitizer stores a flat list of strings, so this only arises for meta written
	 * outside this plugin, and an entry that could not name a template cannot be
	 * the template this request resolved.
	 *
	 * A request that queried nothing singular is answered `false`, not declined.
	 * "There is no assigned template here" is a fact about the request, so an
	 * author writing "not the wide template" is entitled to have it hold on an
	 * archive. A *singular* request whose queried object cannot be resolved to a
	 * post is a different thing — a contradiction rather than an answer — and is
	 * declined.
	 *
	 * @since 0.1.0
	 *
	 * @param array $rule Stored rule.
	 * @return bool|null Whether the request's template is named, or null when the rule is unusable.
	 */
	private static function evaluate_template( array $rule ): ?bool {
		$values = self::values_of( $rule );

		if ( null === $values || ! is_array( $values['templates'] ?? null ) ) {
			return null;
		}

		$wanted = self::string_list( $values['templates'] );

		if ( array() === $wanted ) {
			return null;
		}

		/*
		 * An archive, a search, a 404, the blog posts index: no post is queried,
		 * so no post carries an assignment and no named template can match.
		 * is_page_template() opens with the same guard, for the same reason.
		 */
		if ( ! is_singular() ) {
			return false;
		}

		$assigned = self::assigned_template();

		// Singular, yet no post to read: nothing to compare, so nothing is decided.
		if ( null === $assigned ) {
			return null;
		}

		return in_array( $assigned, $wanted, true );
	}

	/**
	 * Decides a `url_path` rule.
	 *
	 * Both guards below return null where {@see Url_Matcher::matches()} would have
	 * returned false, and the difference matters. That method's runtime refusals —
	 * an unrecognized mode, a value past the cap — are documented as non-matches so
	 * a page render never fatals on stored data. A non-match is a decision, and
	 * `negate` inverts a decision, so passing those cases straight through would
	 * let a rule that should never have reached the database widen a popup's
	 * audience to every URL. Declining hands them to the client's fail-closed path
	 * instead. Both are unreachable through the editor: the mode is an enum and the
	 * cap is declared as `max_length` on the field.
	 *
	 * @since 0.1.0
	 *
	 * @param array $rule Stored rule.
	 * @return bool|null Whether the request path matches, or null when the rule is unusable.
	 */
	private static function evaluate_url_path( array $rule ): ?bool {
		$values = self::values_of( $rule );

		if ( null === $values ) {
			return null;
		}

		$mode  = $values['match'] ?? null;
		$value = $values['value'] ?? null;

		if ( ! is_string( $mode ) || ! is_string( $value ) ) {
			return null;
		}

		if ( ! Url_Matcher::is_valid_mode( $mode ) || ! Url_Matcher::is_valid_value( $value ) ) {
			return null;
		}

		$path = self::request_path();

		if ( null === $path ) {
			return null;
		}

		return Url_Matcher::matches( $mode, $value, $path );
	}

	/**
	 * Returns the page template assigned to the queried post.
	 *
	 * Only ever called for a singular request; the caller holds that guard, because
	 * a non-singular request has a real answer and this method's null does not mean
	 * that. Null here means the queried object could not be resolved to a post at
	 * all, which is a contradiction on a singular request rather than a fact about
	 * the page.
	 *
	 * `get_page_template_slug()` is asked with an explicit post ID rather than
	 * being left to default. Given `0` it would fall back to the global post, which
	 * on a front-end request is whatever the loop last touched — a different answer
	 * depending on where in the template this ran, which is not a property a
	 * cacheable decision may have.
	 *
	 * Reading post meta of the queried object is URL-derived: the URL selects the
	 * post, the post carries the value, and no part of that consults the visitor.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null Template value, {@see Url_Conditions::DEFAULT_TEMPLATE} when
	 *                     none is assigned, or null when no post could be read.
	 */
	private static function assigned_template(): ?string {
		$queried_id = (int) get_queried_object_id();

		if ( 1 > $queried_id ) {
			return null;
		}

		$slug = get_page_template_slug( $queried_id );

		// False when the ID resolves to no post at all.
		if ( ! is_string( $slug ) ) {
			return null;
		}

		return '' === $slug ? self::DEFAULT_TEMPLATE : $slug;
	}

	/**
	 * Returns the normalized path of the current request.
	 *
	 * The request target is URL-derived and therefore cache-safe — two requests to
	 * the same URL agree on it, whoever sends them — and it is also untrusted
	 * input, so it is unslashed and sanitized before anything reads it.
	 *
	 * `FILTER_SANITIZE_URL` is the sanitizer, and the choice is deliberate on two
	 * counts. It removes exactly the bytes that cannot appear in a request target —
	 * control characters, whitespace, and everything outside the ASCII set RFC 1738
	 * permits — while leaving percent-encoding untouched, which
	 * {@see Url_Matcher::normalize_path()} requires: that method decodes once, on
	 * purpose, having already decided where the query and fragment begin. A
	 * sanitizer that stripped `%41` or decoded it early would either corrupt the
	 * path or hand the normalizer a string whose structure had already been
	 * rewritten. And it is a byte-table filter rather than a pattern match, so no
	 * expression is compiled against request input anywhere on this path.
	 *
	 * Its one visible limit: a raw multibyte byte in a request target is dropped
	 * rather than compared. Browsers percent-encode non-ASCII characters in a path,
	 * so a path from a browser arrives unaffected.
	 *
	 * A request that carries no target at all — a command-line invocation that
	 * reached this far, say — yields null rather than a fabricated `/`. Claiming
	 * the site root would answer a question nobody can answer here, and every
	 * caller of this method treats null as "no verdict".
	 *
	 * Nothing about the path is emitted. It is reduced to a boolean here and the
	 * boolean is what the rest of the pipeline sees.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null Normalized path, or null when the request carries none.
	 */
	private static function request_path(): ?string {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}

		$target = filter_var( wp_unslash( $_SERVER['REQUEST_URI'] ), FILTER_SANITIZE_URL );

		if ( ! is_string( $target ) || '' === $target ) {
			return null;
		}

		return Url_Matcher::normalize_path( $target );
	}

	/**
	 * Returns a rule's stored values, or null when it carries none usable.
	 *
	 * @since 0.1.0
	 *
	 * @param array $rule Stored rule.
	 * @return array|null The rule's values, or null when they are not an array.
	 */
	private static function values_of( array $rule ): ?array {
		$values = $rule['values'] ?? null;

		return is_array( $values ) ? $values : null;
	}

	/**
	 * Reduces a stored list to its non-empty string entries.
	 *
	 * Keys are discarded and order is preserved. Order carries no meaning to
	 * {@see in_array()}, but preserving it keeps a debugging dump of the rule
	 * readable against what the author stored.
	 *
	 * @since 0.1.0
	 *
	 * @param array $values Stored list.
	 * @return string[] Usable entries, possibly empty.
	 */
	private static function string_list( array $values ): array {
		$strings = array();

		foreach ( $values as $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$strings[] = $value;
			}
		}

		return $strings;
	}
}
