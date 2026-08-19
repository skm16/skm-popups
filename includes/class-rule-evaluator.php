<?php
/**
 * Server-side evaluation of stored targeting rule sets.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which rule groups survive the cacheable half of the request.
 *
 * Groups are OR'd, rules within a group are AND'd, and an empty `groups` array
 * means "always match". A group that fails here is never emitted, so the client
 * cannot resurrect it.
 *
 * The invariant this class exists to hold:
 *
 * > {@see Rule_Evaluator::group_passes_server()} never returns false because of
 * > a `Context::Client` rule or an unregistered rule type — whatever values
 * > those rules carry, and whatever their `negate` flag says.
 *
 * Server-side, indeterminate means *deferred*, never *satisfied*. A page cache
 * serves one response to many visitors, so a rule that depends on the visitor
 * has no answer at cache-fill time; the group is preserved because the rule
 * genuinely cannot be judged here, not because it passed. The rule travels to
 * the client, which fails it closed if it still cannot be resolved there. The
 * asymmetry is deliberate: deferring on the server and denying on the client is
 * what stops a deactivated extension from turning "members only" into
 * "everybody".
 *
 * Exactly one method can produce a false — {@see Rule_Evaluator::decide_rule_server()} —
 * and it is written as a run of guard clauses that each return null. Only a rule
 * that survives all of them can fail a group: array-shaped, of a registered
 * type, whose condition declares `Context::Server`, judged by an evaluator that
 * returned a real boolean. That is also the only rule `negate` is ever applied
 * to, and the flag is read strictly after the decision exists — negating an
 * indeterminate rule would resurrect it, which is a real bug class rather than a
 * hypothetical one.
 *
 * Until Phase 4 registers the built-in server conditions and their evaluators,
 * callers pass no evaluator and every server rule is therefore indeterminate.
 * The evaluator argument deliberately has no fallback behavior of its own:
 * treating an unjudged rule as true would silently pass targeting that was never
 * checked.
 *
 * @since 0.1.0
 */
final class Rule_Evaluator {

	/**
	 * Rule type emitted in place of a rule the server cannot represent honestly.
	 *
	 * Nothing registers this key, so the client denies it through the same
	 * fail-closed path it uses for any unknown rule, and never applies `negate`
	 * to it. It exists because "this group must not match" is otherwise
	 * unrepresentable in the emitted config: an empty group matches everything.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	public const UNSATISFIABLE_RULE_TYPE = 'popkit_unsatisfiable';

	/**
	 * Reports whether a stored rule set can still match, server-side.
	 *
	 * Groups are OR'd, so one surviving group is enough. A rule set with no
	 * groups is unconstrained and matches every page — that is the shape a popup
	 * carries when its author configured no targeting at all. A `groups` value
	 * that is not an array is read the same way, because it carries no
	 * expressible targeting either.
	 *
	 * @since 0.1.0
	 *
	 * @param array         $rule_set             Stored rule set, as described in `data-model.md`.
	 * @param callable|null $evaluate_server_rule Optional. Server-rule evaluator. See
	 *                                            {@see Rule_Evaluator::group_passes_server()}
	 *                                            for its contract. Default null.
	 * @return bool True when at least one group survives server-side evaluation.
	 */
	public static function rule_set_passes_server( array $rule_set, ?callable $evaluate_server_rule = null ): bool {
		$groups = $rule_set['groups'] ?? null;

		if ( ! is_array( $groups ) || array() === $groups ) {
			return true;
		}

		foreach ( $groups as $group ) {
			/*
			 * A malformed group expresses no targeting and is skipped. Groups are
			 * OR'd, so dropping one can only ever reduce the set of pages a popup
			 * matches on.
			 */
			if ( ! is_array( $group ) ) {
				continue;
			}

			if ( self::group_passes_server( $group, $evaluate_server_rule ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reports whether one group can still pass, server-side.
	 *
	 * Rules within a group are AND'd, so the loop looks for a reason to fail.
	 * There is exactly one: a rule that {@see Rule_Evaluator::decide_rule_server()}
	 * resolved to false. Everything it could not resolve — a client-context rule,
	 * an unregistered type, a malformed entry, a server rule left unjudged
	 * because no evaluator was supplied — is skipped, and the group survives.
	 *
	 * Skipping is not passing. The rule is still carried to the client by
	 * {@see Rule_Evaluator::strip_server_rules()}, which is where an unresolvable
	 * rule is finally denied.
	 *
	 * The evaluator, when supplied, is called as:
	 *
	 *     ( array $rule, Condition $condition ): ?bool
	 *
	 * It receives the rule and the registered {@see Condition} its `type` names,
	 * so it need not look the condition up again, and returns true or false to
	 * decide the rule, or null to declare that it cannot. Any other return value
	 * is read as null: an evaluator that hands back a `WP_Error`, a string, or
	 * anything else has not decided anything, and casting that to a boolean would
	 * turn a failure into a pass. `negate` is applied here and nowhere else.
	 *
	 * @since 0.1.0
	 *
	 * @param array         $group                Group, expected to carry a `rules` array.
	 * @param callable|null $evaluate_server_rule Optional. Decides one resolved server rule.
	 *                                            Phase 4 supplies the real evaluator; while it
	 *                                            is null every server rule is indeterminate.
	 *                                            Default null.
	 * @return bool True when nothing in the group ruled it out.
	 */
	public static function group_passes_server( array $group, ?callable $evaluate_server_rule = null ): bool {
		foreach ( self::rules_of( $group ) as $rule ) {
			$decision = self::decide_rule_server( $rule, $evaluate_server_rule );

			// Indeterminate: the rule is deferred to the client, not satisfied.
			if ( null === $decision ) {
				continue;
			}

			if ( false === $decision ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns the rule set as it should be emitted to the client.
	 *
	 * Three things happen, and each of them is a documented requirement rather
	 * than an optimization:
	 *
	 * - Groups that failed server-side are removed. `data-model.md` requires that
	 *   the client never sees them, so it cannot resurrect one.
	 * - Resolved `Context::Server` rules are removed. They have already been
	 *   decided, and shipping them leaks the site's targeting configuration into
	 *   the page source.
	 * - Unregistered rules are kept, tagged `unknown => true`, with their type
	 *   and values unchanged, so the client can fail them closed and the editor
	 *   can flag them. The tag is recomputed against the registry as it stands at
	 *   this moment, and a tag found in the stored rule is discarded before that
	 *   happens. See {@see Rule_Evaluator::retag_unknown()}.
	 *
	 * A server rule the evaluator did *not* resolve is kept rather than stripped.
	 * Stripping it would delete a rule nobody has checked and leave a group that
	 * passes unconditionally in the browser — the exact widening this class
	 * exists to prevent. Kept, it reaches a client that has no evaluator for a
	 * server-context type and is denied there.
	 *
	 * Callers are expected to have already asked
	 * {@see Rule_Evaluator::rule_set_passes_server()} and to emit nothing when it
	 * said no. If one does not, the guard at the end of this method still refuses
	 * to hand back an empty `groups` array for a rule set whose every group
	 * failed, because an empty groups array means "always match" and would turn a
	 * fully rejected rule set into a site-wide popup.
	 *
	 * @since 0.1.0
	 *
	 * @param array         $rule_set             Stored rule set.
	 * @param callable|null $evaluate_server_rule Optional. Server-rule evaluator. See
	 *                                            {@see Rule_Evaluator::group_passes_server()}
	 *                                            for its contract. Default null.
	 * @return array Rule set carrying only the groups and rules the client must judge.
	 */
	public static function strip_server_rules( array $rule_set, ?callable $evaluate_server_rule = null ): array {
		$groups = $rule_set['groups'] ?? null;

		if ( ! is_array( $groups ) || array() === $groups ) {
			return array( 'groups' => array() );
		}

		$emitted = array();

		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}

			$stripped = self::strip_group( $group, $evaluate_server_rule );

			// Null means the group failed server-side, so it is not emitted at all.
			if ( null === $stripped ) {
				continue;
			}

			$emitted[] = $stripped;
		}

		if ( array() === $emitted ) {
			/*
			 * Every group failed, yet something is asking for the emitted shape.
			 * Returning no groups here would read as "always match", so return a
			 * group the client cannot satisfy instead.
			 */
			return array( 'groups' => array( self::unsatisfiable_group() ) );
		}

		return array( 'groups' => array_values( $emitted ) );
	}

	/**
	 * Reports whether a group references a condition type nothing has registered.
	 *
	 * The editor uses this to surface an unavailable condition, and the emitter
	 * to decide whether a group needs the `unknown` tag applied to any of its
	 * rules. A malformed rule counts as unknown: it names no resolvable condition
	 * either, and hiding it from the author would hide the reason their popup
	 * never shows.
	 *
	 * @since 0.1.0
	 *
	 * @param array $group Group, expected to carry a `rules` array.
	 * @return bool True when at least one rule cannot be resolved to a registered condition.
	 */
	public static function group_has_unknown( array $group ): bool {
		foreach ( self::rules_of( $group ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				return true;
			}

			if ( null === self::lookup_condition( self::rule_type( $rule ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decides a single rule server-side, or declines to.
	 *
	 * This is the only method in the plugin that can fail a group on the server,
	 * and every path that is not a fully resolved `Context::Server` rule returns
	 * null before reaching that point. Read the guards in order: a rule has to
	 * clear all five to be capable of failing anything.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed         $rule                 Stored rule. Anything that is not an array is indeterminate.
	 * @param callable|null $evaluate_server_rule Optional. Server-rule evaluator, or null while
	 *                                            none exists. Default null.
	 * @return bool|null True or false once the rule is resolved, null when it is indeterminate.
	 */
	private static function decide_rule_server( $rule, ?callable $evaluate_server_rule = null ): ?bool {
		// Malformed entry: nothing to judge.
		if ( ! is_array( $rule ) ) {
			return null;
		}

		$condition = self::lookup_condition( self::rule_type( $rule ) );

		// Unregistered type: indeterminate here, denied on the client.
		if ( null === $condition ) {
			return null;
		}

		// Client context: indeterminate by design. This is the cache-safety rule.
		if ( Context::Server !== $condition->context ) {
			return null;
		}

		// No evaluator yet. Phase 4 supplies one; until then nothing is decided.
		if ( null === $evaluate_server_rule ) {
			return null;
		}

		$result = $evaluate_server_rule( $rule, $condition );

		// The evaluator declined, or returned something that is not a decision.
		if ( ! is_bool( $result ) ) {
			return null;
		}

		/*
		 * Reached only by a resolved server rule, which is the only kind of rule
		 * `negate` may be applied to.
		 */
		return self::rule_is_negated( $rule ) ? ! $result : $result;
	}

	/**
	 * Returns one group's client-facing rules, or null when the group failed.
	 *
	 * Emission is decided per rule, after {@see Rule_Evaluator::decide_rule_server()}
	 * has had its say, so the judgement that fails a group and the judgement that
	 * strips a rule can never disagree.
	 *
	 * @since 0.1.0
	 *
	 * @param array         $group                Group to strip.
	 * @param callable|null $evaluate_server_rule Optional. Server-rule evaluator. Default null.
	 * @return array|null The group with its rules replaced, or null when it failed server-side.
	 */
	private static function strip_group( array $group, ?callable $evaluate_server_rule = null ): ?array {
		$emitted = array();

		foreach ( self::rules_of( $group ) as $rule ) {
			$decision = self::decide_rule_server( $rule, $evaluate_server_rule );

			if ( false === $decision ) {
				return null;
			}

			// Resolved true: already accounted for, and shipping it leaks targeting.
			if ( true === $decision ) {
				continue;
			}

			/*
			 * Indeterminate. A malformed entry cannot be emitted as itself — the
			 * client would have to read a `type` off a value that may not even be
			 * an object — so it becomes a rule that can never be satisfied. That
			 * keeps a corrupt rule from quietly disappearing and widening the
			 * group it was constraining.
			 */
			if ( ! is_array( $rule ) ) {
				$emitted[] = self::unsatisfiable_rule();

				continue;
			}

			/*
			 * Client-context rules travel verbatim. Unregistered ones travel
			 * verbatim too, plus the tag the client and the editor look for,
			 * decided by the registry as it stands right now rather than by
			 * whatever the stored rule claims.
			 */
			$emitted[] = self::retag_unknown( $rule );
		}

		$group['rules'] = array_values( $emitted );

		return $group;
	}

	/**
	 * Returns a rule tagged `unknown` if, and only if, its type is unregistered now.
	 *
	 * The tag is a statement about the registry at the moment of emission, so it
	 * is computed fresh every time and a stored one is never trusted. Any tag the
	 * rule arrives with is removed first.
	 *
	 * That order matters because sanitization writes `unknown => true` into the
	 * database for a rule whose type nothing had registered at save time. The
	 * stored tag therefore records what was true when the author last saved, not
	 * what is true on this request. Reactivate the extension that supplies the
	 * condition and the rule is registered, evaluable, and perfectly ordinary —
	 * but a tag carried over from that earlier save would keep the client failing
	 * it closed on every pageview. The popup would stop showing, nothing would
	 * report an error anywhere, and reactivating the very plugin that should have
	 * fixed it would change nothing.
	 *
	 * Removing the tag first also puts it in last position on a rule that is
	 * still unregistered, so the stored keys keep their order and their values,
	 * which `data-model.md` requires of an unknown rule.
	 *
	 * @since 0.1.0
	 *
	 * @param array $rule Rule about to be emitted.
	 * @return array The rule, carrying the tag its type currently warrants and no other.
	 */
	private static function retag_unknown( array $rule ): array {
		unset( $rule['unknown'] );

		if ( null === self::lookup_condition( self::rule_type( $rule ) ) ) {
			$rule['unknown'] = true;
		}

		return $rule;
	}

	/**
	 * Returns a group's rules, always as an array.
	 *
	 * Entries are not filtered. A malformed one is handled where it matters — as
	 * indeterminate when deciding, as an unsatisfiable rule when emitting —
	 * rather than dropped here, because dropping a rule silently widens the group
	 * that contained it.
	 *
	 * @since 0.1.0
	 *
	 * @param array $group Group to read.
	 * @return array The group's rules, or an empty array when it carries none.
	 */
	private static function rules_of( array $group ): array {
		$rules = $group['rules'] ?? null;

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Returns a rule's condition key.
	 *
	 * @since 0.1.0
	 *
	 * @param array $rule Rule to read.
	 * @return string The rule's type, or an empty string when it carries no usable one.
	 */
	private static function rule_type( array $rule ): string {
		$type = $rule['type'] ?? '';

		return is_string( $type ) ? $type : '';
	}

	/**
	 * Returns the registered condition a rule type names, if any.
	 *
	 * Null is the ordinary answer for a rule whose implementing plugin is
	 * deactivated. It is also the answer before the registry exists at all:
	 * `includes/functions.php` declares no class, so no autoloader can reach it
	 * and the bootstrap has to require it explicitly. A caller that runs before
	 * that happens sees every rule as unregistered, every group is deferred
	 * whole, and the client then denies what it cannot resolve — the popup is
	 * suppressed rather than shown to everybody, which is the direction this
	 * plugin fails in by design.
	 *
	 * The accessor is resolved by name rather than called directly so that this
	 * class never fatals on a request where that require has not happened yet.
	 * It is declared in the global namespace, as a WordPress plugin's public API
	 * should be; the namespaced name is accepted as a fallback so that moving it
	 * under `Popkit\` would not silently turn every rule into an unknown.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type Condition key from a rule.
	 * @return Condition|null The registered condition, or null when there is none.
	 */
	private static function lookup_condition( string $type ): ?Condition {
		if ( '' === $type ) {
			return null;
		}

		$accessor = 'popkit_conditions';

		if ( ! function_exists( $accessor ) ) {
			$accessor = __NAMESPACE__ . '\\popkit_conditions';
		}

		if ( ! function_exists( $accessor ) ) {
			return null;
		}

		$conditions = $accessor();

		return $conditions instanceof Conditions ? $conditions->get( $type ) : null;
	}

	/**
	 * Reports whether a rule asks for its result to be inverted.
	 *
	 * Only ever consulted for a rule that has already been resolved. Sanitization
	 * stores a real boolean, so the tolerant read here is for hand-written meta
	 * and for meta restored from an older export: `1`, `'1'`, `'true'`, and
	 * `'yes'` all mean the author asked for negation, and reading one of them as
	 * "not negated" would invert the audience of the popup.
	 *
	 * @since 0.1.0
	 *
	 * @param array $rule Rule to read.
	 * @return bool True when the rule's result must be inverted.
	 */
	private static function rule_is_negated( array $rule ): bool {
		if ( ! isset( $rule['negate'] ) ) {
			return false;
		}

		return (bool) filter_var( $rule['negate'], FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Returns a group the client can never satisfy.
	 *
	 * @since 0.1.0
	 *
	 * @return array A single-rule group whose rule always fails closed.
	 */
	private static function unsatisfiable_group(): array {
		return array(
			'rules' => array( self::unsatisfiable_rule() ),
		);
	}

	/**
	 * Returns a rule the client can never satisfy.
	 *
	 * Shaped like any other emitted rule so the client's loop needs no special
	 * case: an unregistered type carrying the `unknown` tag is denied by the
	 * fail-closed path, which does not apply `negate`. `negate` is stated as
	 * false regardless, so that nothing about this rule reads as an instruction
	 * to invert anything.
	 *
	 * @since 0.1.0
	 *
	 * @return array Emitted rule that no client can resolve.
	 */
	private static function unsatisfiable_rule(): array {
		return array(
			'type'    => self::UNSATISFIABLE_RULE_TYPE,
			'negate'  => false,
			'values'  => array(),
			'unknown' => true,
		);
	}
}
