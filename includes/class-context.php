<?php
/**
 * Evaluation context for a registered condition.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Where a condition is decided: in PHP, or in the visitor's browser.
 *
 * This enum is the mechanical expression of the cache-safety rule in
 * docs/CLAUDE.md: server-side code may never make a rendering decision that
 * depends on the visitor rather than the URL. A page cache serves one HTML
 * response to many visitors, so anything varying by visitor — login state,
 * device width, referrer, UTM parameters, visit history — must be decided after
 * that response has been delivered.
 *
 * Declaring the context on the condition rather than inferring it at evaluation
 * time means the pipeline never has to guess. `Context::Server` conditions are
 * resolved while the response is generated; `Context::Client` conditions are
 * emitted into the config and resolved by the frontend controller.
 *
 * The two contexts are also asymmetric in how they treat an answer they cannot
 * produce, and that asymmetry is deliberate:
 *
 * - The server treats a client-context rule as **indeterminate**. Its group
 *   survives so the client can decide. Indeterminate means deferred, never
 *   satisfied.
 * - The client treats a rule it cannot evaluate as **false**, failing its group
 *   closed. A condition whose implementing plugin was deactivated must narrow
 *   the audience to nobody, never widen it to everybody.
 *
 * Backed by string so the value round-trips through the registry REST route,
 * the emitted frontend config, and JSON generally, without a lookup table on
 * either side.
 *
 * @since 0.1.0
 */
enum Context: string {

	/**
	 * Decided in PHP, while the cacheable response is generated.
	 *
	 * Only conditions that are a pure function of the URL and of site settings
	 * may declare this. Anything reading a cookie, a session, a request header,
	 * or the current time belongs in Context::Client instead.
	 *
	 * @since 0.1.0
	 */
	case Server = 'server';

	/**
	 * Decided in the browser, after the cached response has been served.
	 *
	 * The rule is emitted into the frontend config and evaluated by the
	 * controller. It never influences the markup, so two visitors requesting the
	 * same URL still receive byte-identical HTML.
	 *
	 * @since 0.1.0
	 */
	case Client = 'client';

	/**
	 * Whether the server must defer this condition to the browser.
	 *
	 * Server-side evaluation calls this to distinguish "the rule failed" from
	 * "the rule cannot be judged here". A deferred rule leaves its group intact:
	 * {@see \Popkit\Rule_Evaluator} must never fail a group on the strength of a
	 * rule whose context is this one.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when evaluation belongs to the client.
	 */
	public function is_deferred_to_client(): bool {
		/*
		 * The annotation below suppresses a false positive, not a real finding.
		 * PHPCompatibility 9.3.5 predates PHP 8.1 and does not recognize an enum
		 * method as an object context, so it reads the line as $this inside a
		 * plain function. Enum methods are instance methods on the case and
		 * $this is valid inside them, and the comparison cannot be expressed
		 * without it. Remove the annotation when PHPCompatibility ships PHP 8.1
		 * support.
		 */
		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- $this is valid in a PHP 8.1 enum method; see above.
		return self::Client === $this;
	}
}
