<?php
/**
 * Unit tests for the environment requirements check.
 *
 * These run against the stubs bootstrap with no WordPress, no database and no
 * Docker. That is possible because `Popkit\Requirements` reads its inputs from
 * two places and only two: `PHP_VERSION`, and `$GLOBALS['wp_version']`.
 *
 * **The untestable seam.** `Requirements::php_met()` compares against
 * `PHP_VERSION`, a compile-time constant with no injection point — no filter, no
 * parameter, no property. `Requirements` is `final` with a private constructor
 * and every member static, so it cannot be subclassed or stubbed either. There
 * is therefore no honest way to drive the PHP half from a test: the suite runs
 * on one PHP version and that version is whatever it is. Faking a pass by
 * asserting `php_met()` is true on PHP 8.1 would assert nothing about the code.
 *
 * So the PHP half is covered indirectly, by the two properties that must hold
 * on *any* PHP version:
 *
 *   1. `met()` is false whenever WordPress is unsupported, no matter what PHP
 *      reports. That is what distinguishes `&&` from `||`.
 *   2. `php_met()` and `failures()` always agree about the PHP requirement.
 *
 * Changing that would mean introducing a version parameter on the comparison
 * helpers, which would also be the point at which the duplicated inline check in
 * `popkit.php` could be tested. Until then, the gap is named here rather than
 * papered over.
 *
 * The WordPress half is fully injectable and is driven properly below, including
 * the fail-closed behavior when the version cannot be determined at all.
 *
 * @package Popkit
 */

use PHPUnit\Framework\TestCase;
use Popkit\Requirements;

/*
 * Load the class directly. Guarded so a bootstrap that does register an
 * autoloader does not end up loading the file twice.
 */
if ( ! class_exists( Requirements::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/class-requirements.php';
}

/**
 * Asserts the environment gate that decides whether popkit loads at all.
 */
final class Test_Popkit_Requirements extends TestCase {

	/**
	 * The minimum WordPress version declared in docs/CLAUDE.md and the plugin header.
	 *
	 * @var string
	 */
	private const MIN_WP = '6.5';

	/**
	 * The minimum PHP version declared in docs/CLAUDE.md and the plugin header.
	 *
	 * @var string
	 */
	private const MIN_PHP = '8.1';

	/**
	 * The minimums match the platform the plugin claims to support.
	 *
	 * `popkit.php` defines POPKIT_MIN_PHP and POPKIT_MIN_WP, but this class is
	 * also the single source of truth for consumers that load it without the
	 * plugin file — this suite among them. Both paths are asserted, because a
	 * fallback that drifts from the plugin header is a plugin that activates on
	 * an environment its own header rules out.
	 *
	 * @return void
	 */
	public function test_the_minimum_versions_match_the_documented_platform() {
		if ( defined( 'POPKIT_MIN_PHP' ) ) {
			$this->assertSame(
				(string) POPKIT_MIN_PHP,
				Requirements::min_php(),
				'min_php() must defer to POPKIT_MIN_PHP once the plugin file has defined it.'
			);
		} else {
			$this->assertSame(
				self::MIN_PHP,
				Requirements::min_php(),
				'With POPKIT_MIN_PHP undefined the fallback must still be the documented minimum. A fallback that drifts from the plugin header lets the plugin judge itself against a version it never claimed to support.'
			);
		}

		if ( defined( 'POPKIT_MIN_WP' ) ) {
			$this->assertSame(
				(string) POPKIT_MIN_WP,
				Requirements::min_wp(),
				'min_wp() must defer to POPKIT_MIN_WP once the plugin file has defined it.'
			);
		} else {
			$this->assertSame(
				self::MIN_WP,
				Requirements::min_wp(),
				'With POPKIT_MIN_WP undefined the fallback must still be the documented minimum.'
			);
		}
	}

	/**
	 * The WordPress version is read from the global.
	 *
	 * @return void
	 */
	public function test_the_wordpress_version_is_read_from_the_global() {
		$this->with_wp_version(
			'6.7.2',
			function () {
				$this->assertSame(
					'6.7.2',
					Requirements::wp_version(),
					'wp_version() must report $GLOBALS[\'wp_version\']. Reading it through get_bloginfo() would make the check filterable, and a filtered version can be forged into satisfying the gate.'
				);
			}
		);
	}

	/**
	 * An undeterminable WordPress version fails closed.
	 *
	 * @return void
	 */
	public function test_an_undeterminable_wordpress_version_fails_closed() {
		$this->without_wp_version(
			function () {
				$this->assertSame(
					'0',
					Requirements::wp_version(),
					'With no version available the reported version must be "0", which compares below every real release.'
				);
				$this->assertFalse(
					Requirements::wp_met(),
					'An environment whose WordPress version cannot be determined must be treated as unsupported. Failing open here loads PHP 8.1 code and modern core APIs into an environment nothing has vouched for.'
				);
				$this->assertFalse(
					Requirements::met(),
					'met() must be false when the WordPress version cannot be determined.'
				);
			}
		);
	}

	/**
	 * Versions below the minimum are rejected.
	 *
	 * @return void
	 */
	public function test_wordpress_versions_below_the_minimum_are_rejected() {
		/*
		 * '6.5-beta1' is deliberate: version_compare() ranks a pre-release below
		 * the release it precedes, so a beta of the minimum version does not
		 * satisfy the minimum. A numeric comparison would accept it.
		 */
		$versions = array( '0', '4.9', '5.9', '6.4', '6.4.9', '6.5-beta1' );

		foreach ( $versions as $version ) {
			$this->with_wp_version(
				$version,
				function () use ( $version ) {
					$this->assertFalse(
						Requirements::wp_met(),
						sprintf( 'WordPress %1$s is below the minimum of %2$s and must be rejected.', $version, self::MIN_WP )
					);
					$this->assertFalse(
						Requirements::met(),
						sprintf( 'met() must be false on WordPress %s, whatever the PHP half reports. An OR between the two halves would let an unsupported WordPress through on a supported PHP.', $version )
					);
				}
			);
		}
	}

	/**
	 * The minimum version and anything newer are accepted.
	 *
	 * @return void
	 */
	public function test_the_minimum_wordpress_version_and_newer_are_accepted() {
		$versions = array( '6.5', '6.5.0', '6.5.1', '6.6', '6.7.2', '7.0', '7.0.4', '10.0' );

		foreach ( $versions as $version ) {
			$this->with_wp_version(
				$version,
				function () use ( $version ) {
					$this->assertTrue(
						Requirements::wp_met(),
						sprintf( 'WordPress %1$s is at or above the minimum of %2$s and must be accepted.', $version, self::MIN_WP )
					);
				}
			);
		}
	}

	/**
	 * An unmet WordPress requirement produces exactly one whole sentence.
	 *
	 * Sentences are whole strings rather than assembled fragments so they stay
	 * translatable in isolation, and they have to name both versions or the site
	 * owner cannot tell what to change.
	 *
	 * @return void
	 */
	public function test_an_unmet_wordpress_requirement_produces_one_complete_sentence() {
		$this->with_wp_version(
			'6.4',
			function () {
				$failures = $this->wordpress_failures();

				$this->assertCount( 1, $failures, 'One unmet WordPress requirement must produce exactly one sentence.' );
				$this->assertStringContainsString( self::MIN_WP, $failures[0], 'The sentence must name the minimum version the site needs to reach.' );
				$this->assertStringContainsString( '6.4', $failures[0], 'The sentence must name the version the site is actually running.' );
				$this->assertStringEndsWith( '.', trim( $failures[0] ), 'Failure messages are whole sentences, not fragments to be assembled.' );
			}
		);
	}

	/**
	 * A supported WordPress version produces no WordPress failure.
	 *
	 * @return void
	 */
	public function test_a_supported_wordpress_version_produces_no_wordpress_failure() {
		$this->with_wp_version(
			'6.5',
			function () {
				$this->assertTrue( Requirements::wp_met(), 'Fixture: the WordPress half must be satisfied.' );
				$this->assertSame(
					array(),
					$this->wordpress_failures(),
					'A supported WordPress version must not be reported as a failure.'
				);
			}
		);
	}

	/**
	 * Whether every requirement is met agrees with whether anything failed.
	 *
	 * The two are computed independently, so they can disagree — and a
	 * disagreement is what produces the worst outcome available here: an
	 * activation that halts while `failures()` has nothing to explain it, leaving
	 * `wp_die()` to print an empty message.
	 *
	 * @return void
	 */
	public function test_met_agrees_with_failures() {
		foreach ( array( '0', '6.4', '6.5', '7.0' ) as $version ) {
			$this->with_wp_version(
				$version,
				function () use ( $version ) {
					$this->assertSame(
						array() === Requirements::failures(),
						Requirements::met(),
						sprintf( 'met() and failures() disagree on WordPress %s. Activation halts on met() and explains itself with failures(), so a disagreement produces a refusal with no reason given.', $version )
					);
				}
			);
		}
	}

	/**
	 * The two reports of the PHP requirement agree with each other.
	 *
	 * This is the honest half of the untestable seam described in the file
	 * docblock: the PHP version cannot be driven, but the two reports of it can
	 * still be held to each other, and that holds on any PHP version the suite
	 * happens to run on.
	 *
	 * @return void
	 */
	public function test_the_php_requirement_is_reported_consistently() {
		$this->with_wp_version(
			'9.9',
			function () {
				$expected = Requirements::php_met() ? 0 : 1;

				$this->assertCount(
					$expected,
					$this->php_failures(),
					'php_met() and failures() must agree about the PHP requirement. A failure sentence with no corresponding false from php_met() — or the reverse — means one of the two is reading a different version than the other.'
				);
			}
		);
	}

	/**
	 * The admin notice escapes the version it prints.
	 *
	 * The running WordPress version is interpolated straight into the notice.
	 * It comes from `$GLOBALS['wp_version']`, which is a plain global that any
	 * plugin, drop-in or compromised file can write to, so it is not trusted
	 * input just because core usually sets it.
	 *
	 * @return void
	 */
	public function test_the_notice_escapes_the_version_it_prints() {
		$this->with_wp_version(
			'6.4 <script>alert("popkit")</script>',
			function () {
				$output = $this->capture_notice();

				$this->assertStringContainsString( 'notice notice-error', $output, 'An unmet requirement must print an error notice.' );
				$this->assertStringNotContainsString(
					'<script>',
					$output,
					'The running WordPress version reached the notice unescaped. It is read from a writable global and printed into wp-admin, so it must go through esc_html().'
				);
				$this->assertStringContainsString(
					'&lt;script&gt;',
					$output,
					'The version must be escaped rather than stripped, or the notice misreports what the site is running.'
				);
			}
		);
	}

	/**
	 * The notice prints nothing when every requirement is met.
	 *
	 * @return void
	 */
	public function test_the_notice_prints_nothing_when_every_requirement_is_met() {
		if ( ! Requirements::php_met() ) {
			$this->markTestSkipped( 'The suite is running below the minimum PHP version, so no state exists in which every requirement is met.' );
		}

		$this->with_wp_version(
			'6.5',
			function () {
				$this->assertSame(
					array(),
					Requirements::failures(),
					'Fixture: nothing may be unmet before asserting that the notice stays silent.'
				);
				$this->assertSame(
					'',
					$this->capture_notice(),
					'The notice must print nothing at all when every requirement is met. A supported site does not get an empty error box.'
				);
			}
		);
	}

	/**
	 * Returns the failure sentences that concern WordPress.
	 *
	 * @return string[]
	 */
	private function wordpress_failures() {
		return array_values(
			array_filter(
				Requirements::failures(),
				static fn( $message ) => false !== strpos( (string) $message, 'WordPress' )
			)
		);
	}

	/**
	 * Returns the failure sentences that concern PHP.
	 *
	 * @return string[]
	 */
	private function php_failures() {
		return array_values(
			array_filter(
				Requirements::failures(),
				static fn( $message ) => false === strpos( (string) $message, 'WordPress' ) && false !== strpos( (string) $message, 'PHP' )
			)
		);
	}

	/**
	 * Captures whatever the admin notice prints.
	 *
	 * @return string
	 */
	private function capture_notice() {
		ob_start();

		Requirements::notice();

		return (string) ob_get_clean();
	}

	/**
	 * Runs assertions with a given WordPress version in place.
	 *
	 * The global is restored even when an assertion fails, so one failing case
	 * cannot cascade into every test that runs after it.
	 *
	 * @param string   $version    Version to report as the running WordPress version.
	 * @param callable $assertions Assertions to run.
	 * @return void
	 */
	private function with_wp_version( $version, callable $assertions ) {
		$had      = array_key_exists( 'wp_version', $GLOBALS );
		$previous = $had ? $GLOBALS['wp_version'] : null;

		$GLOBALS['wp_version'] = $version;

		try {
			$assertions();
		} finally {
			if ( $had ) {
				$GLOBALS['wp_version'] = $previous;
			} else {
				unset( $GLOBALS['wp_version'] );
			}
		}
	}

	/**
	 * Runs assertions with no WordPress version available at all.
	 *
	 * @param callable $assertions Assertions to run.
	 * @return void
	 */
	private function without_wp_version( callable $assertions ) {
		$had      = array_key_exists( 'wp_version', $GLOBALS );
		$previous = $had ? $GLOBALS['wp_version'] : null;

		unset( $GLOBALS['wp_version'] );

		try {
			$assertions();
		} finally {
			if ( $had ) {
				$GLOBALS['wp_version'] = $previous;
			}
		}
	}
}
