<?php
/**
 * Unit tests for the constrained URL match language.
 *
 * `Popkit\Url_Matcher` is the whole of the plugin's answer to "let the author
 * paste a regular expression". It touches no WordPress function, so it runs
 * against the stubs bootstrap with no database and no container.
 *
 * Two families of assertion below are load bearing rather than routine:
 *
 * 1. **The metacharacter tests.** Every character that means something to PCRE —
 *    `.` `+` `(` `[` `\` `$` `^` `{` `|` — is asserted to be an ordinary literal
 *    in `glob` mode. If any of them behaves like a regex operator, the matcher
 *    has been reimplemented on top of PCRE at some point and the security
 *    invariant in `docs/CLAUDE.md` -> Security is gone. The clearest single case
 *    is `^.*$`, which a regex engine matches against every subject in existence
 *    and which this matcher must match against nothing except the literal four
 *    characters.
 *
 * 2. **The catastrophic-input tests.** Patterns shaped like `*a*a*a…*b` against a
 *    long run of `a` are the classic input that turns a naive glob-to-regex
 *    translation, or a naive recursive glob, exponential. They are asserted to
 *    return the right answer *and* to complete inside a wall-clock budget. The
 *    budget is deliberately loose — a second, against inputs that measure in
 *    fractions of a millisecond — because the failure being guarded against is
 *    not "slower than we would like", it is "does not finish today".
 *
 * The wall-clock assertions use `hrtime()` rather than `microtime()`: it is
 * monotonic, so a clock adjustment mid-suite cannot produce a negative or wildly
 * inflated elapsed time and fail the build for no reason.
 *
 * @package Popkit
 */

use PHPUnit\Framework\TestCase;
use Popkit\Url_Matcher;

/*
 * Load the class directly. Guarded so a bootstrap that does register an
 * autoloader does not end up loading the file twice.
 */
if ( ! class_exists( Url_Matcher::class ) ) {
	require_once dirname( __DIR__, 3 ) . '/includes/class-url-matcher.php';
}

/**
 * Asserts the four match modes, the length cap, and path normalisation.
 */
final class Test_Popkit_Url_Matcher extends TestCase {

	/**
	 * Wall-clock budget for one catastrophic-input match, in seconds.
	 *
	 * Every shape below lands between 0.1 ms and 0.3 ms on a development machine,
	 * so a whole second is roughly three orders of magnitude of headroom. That is
	 * what keeps this from flaking on a loaded CI runner.
	 *
	 * It is still an assertion with teeth. The same patterns fed to a naive
	 * recursive glob — the implementation this one deliberately is not — grow
	 * exponentially in the subject length: on `*a*a*a*a*a*a*a*a*a*b` that
	 * implementation needs about half a second at a subject of 22 characters and
	 * fifteen seconds at 30. The subjects here are 1,500 to 3,000 characters, so
	 * anything with that shape does not merely miss the budget, it never returns.
	 * A generous budget and an unmissable failure are not in tension.
	 *
	 * @var float
	 */
	private const MATCH_TIME_BUDGET_SECONDS = 1.0;

	/**
	 * The mode list is exactly the four documented in the data model.
	 *
	 * Asserted as an ordered list rather than a set: `modes()` is the source the
	 * editor's mode selector is built from, so the order is user visible.
	 *
	 * @return void
	 */
	public function test_the_modes_are_the_four_documented_modes() {
		$this->assertSame(
			array( 'exact', 'prefix', 'contains', 'glob' ),
			Url_Matcher::modes(),
			'The mode list backs save-time validation, the registered field schema and the editor selector. Adding a fifth mode here without implementing it in matches() would offer authors a mode that silently never matches.'
		);
	}

	/**
	 * Only the four documented modes validate.
	 *
	 * @dataProvider data_mode_validity
	 *
	 * @param string $mode     Mode identifier under test.
	 * @param bool   $expected Whether the identifier should validate.
	 * @return void
	 */
	public function test_is_valid_mode_accepts_only_the_documented_modes( string $mode, bool $expected ) {
		$this->assertSame(
			$expected,
			Url_Matcher::is_valid_mode( $mode ),
			sprintf( 'Mode "%s" validated the wrong way.', $mode )
		);
	}

	/**
	 * Mode identifiers and whether each is supported.
	 *
	 * @return array[] Test name => array( mode, expected ).
	 */
	public function data_mode_validity() {
		return array(
			'exact'                => array( 'exact', true ),
			'prefix'               => array( 'prefix', true ),
			'contains'             => array( 'contains', true ),
			'glob'                 => array( 'glob', true ),
			'regex is not a mode'  => array( 'regex', false ),
			'regexp is not a mode' => array( 'regexp', false ),
			'pattern'              => array( 'pattern', false ),
			'wrong case'           => array( 'Exact', false ),
			'shouting'             => array( 'GLOB', false ),
			'empty'                => array( '', false ),
			'leading space'        => array( ' exact', false ),
		);
	}

	/**
	 * An unrecognised mode never matches, however plausible the value looks.
	 *
	 * This is the fail-closed half of the security invariant. A stored rule whose
	 * mode is `regex` must match nothing at all — not fall back to `contains`,
	 * and certainly not be handed to a pattern engine.
	 *
	 * @dataProvider data_unknown_modes
	 *
	 * @param string $mode Unrecognised mode identifier.
	 * @return void
	 */
	public function test_an_unknown_mode_never_matches( string $mode ) {
		$this->assertFalse(
			Url_Matcher::matches( $mode, '/campaigns/', '/campaigns/' ),
			sprintf( 'Mode "%s" is not supported, so even an identical value and subject must not match.', $mode )
		);

		$this->assertFalse(
			Url_Matcher::matches( $mode, '.*', '/anything/at/all' ),
			sprintf( 'Mode "%s" must not fall through to any kind of pattern matching.', $mode )
		);

		$this->assertFalse(
			Url_Matcher::matches( $mode, '', '' ),
			sprintf( 'Mode "%s" must not match even the empty subject.', $mode )
		);
	}

	/**
	 * Mode identifiers this matcher does not implement.
	 *
	 * @return array[] Test name => array( mode ).
	 */
	public function data_unknown_modes() {
		return array(
			'regex'       => array( 'regex' ),
			'regexp'      => array( 'regexp' ),
			'preg'        => array( 'preg' ),
			'wildcard'    => array( 'wildcard' ),
			'empty'       => array( '' ),
			'wrong case'  => array( 'Prefix' ),
			'trailing nl' => array( "glob\n" ),
		);
	}

	/**
	 * Exact mode compares whole strings.
	 *
	 * @dataProvider data_exact
	 *
	 * @param string $value    Match value.
	 * @param string $subject  Subject to test.
	 * @param bool   $expected Expected result.
	 * @return void
	 */
	public function test_exact_mode( string $value, string $subject, bool $expected ) {
		$this->assertSame(
			$expected,
			Url_Matcher::matches( 'exact', $value, $subject ),
			sprintf( 'exact("%1$s", "%2$s") returned the wrong result.', $value, $subject )
		);
	}

	/**
	 * Exact-mode cases.
	 *
	 * @return array[] Test name => array( value, subject, expected ).
	 */
	public function data_exact() {
		return array(
			'identical'                  => array( '/campaigns/', '/campaigns/', true ),
			'root'                       => array( '/', '/', true ),
			'trailing slash differs'     => array( '/about', '/about/', false ),
			'longer subject'             => array( '/about', '/about-us', false ),
			'shorter subject'            => array( '/about-us', '/about', false ),
			'different path'             => array( '/a', '/b', false ),
			'empty value, empty subject' => array( '', '', true ),
			'empty value, real subject'  => array( '', '/about', false ),
			'real value, empty subject'  => array( '/about', '', false ),
			'value is a prefix'          => array( '/camp', '/campaigns', false ),
			'substring in the middle'    => array( 'paign', '/campaigns', false ),
		);
	}

	/**
	 * Prefix mode tests the start of the subject.
	 *
	 * @dataProvider data_prefix
	 *
	 * @param string $value    Match value.
	 * @param string $subject  Subject to test.
	 * @param bool   $expected Expected result.
	 * @return void
	 */
	public function test_prefix_mode( string $value, string $subject, bool $expected ) {
		$this->assertSame(
			$expected,
			Url_Matcher::matches( 'prefix', $value, $subject ),
			sprintf( 'prefix("%1$s", "%2$s") returned the wrong result.', $value, $subject )
		);
	}

	/**
	 * Prefix-mode cases.
	 *
	 * An empty value matching everything is deliberate and documented on
	 * `Url_Matcher::matches()`: every string starts with the empty string, and
	 * the alternative would make a half-filled targeting field suppress a popup
	 * with no visible cause.
	 *
	 * @return array[] Test name => array( value, subject, expected ).
	 */
	public function data_prefix() {
		return array(
			'exact length'                => array( '/campaigns', '/campaigns', true ),
			'longer subject'              => array( '/campaigns', '/campaigns/giving-tuesday', true ),
			'root matches everything'     => array( '/', '/anything', true ),
			'not at the start'            => array( 'campaigns', '/campaigns', false ),
			'subject shorter than value'  => array( '/campaigns', '/camp', false ),
			'different branch'            => array( '/campaigns/', '/donate/', false ),
			'empty value matches'         => array( '', '/about', true ),
			'empty value, empty subject'  => array( '', '', true ),
			'real value, empty subject'   => array( '/about', '', false ),
			'value equal to subject plus' => array( '/about/', '/about', false ),
		);
	}

	/**
	 * Contains mode tests for a substring anywhere.
	 *
	 * @dataProvider data_contains
	 *
	 * @param string $value    Match value.
	 * @param string $subject  Subject to test.
	 * @param bool   $expected Expected result.
	 * @return void
	 */
	public function test_contains_mode( string $value, string $subject, bool $expected ) {
		$this->assertSame(
			$expected,
			Url_Matcher::matches( 'contains', $value, $subject ),
			sprintf( 'contains("%1$s", "%2$s") returned the wrong result.', $value, $subject )
		);
	}

	/**
	 * Contains-mode cases.
	 *
	 * @return array[] Test name => array( value, subject, expected ).
	 */
	public function data_contains() {
		return array(
			'at the start'               => array( '/camp', '/campaigns/x', true ),
			'in the middle'              => array( 'aign', '/campaigns/x', true ),
			'at the end'                 => array( '/x', '/campaigns/x', true ),
			'whole subject'              => array( '/campaigns', '/campaigns', true ),
			'absent'                     => array( '/donate', '/campaigns', false ),
			'longer than subject'        => array( '/campaigns/x', '/campaigns', false ),
			'empty value matches'        => array( '', '/about', true ),
			'empty value, empty subject' => array( '', '', true ),
			'real value, empty subject'  => array( '/about', '', false ),
		);
	}

	/**
	 * Matching folds case in every mode.
	 *
	 * @dataProvider data_case_insensitivity
	 *
	 * @param string $mode    Match mode.
	 * @param string $value   Match value.
	 * @param string $subject Subject to test.
	 * @return void
	 */
	public function test_matching_is_case_insensitive( string $mode, string $value, string $subject ) {
		$this->assertTrue(
			Url_Matcher::matches( $mode, $value, $subject ),
			sprintf( '%1$s("%2$s", "%3$s") must match: comparison is case-insensitive in every mode.', $mode, $value, $subject )
		);
	}

	/**
	 * Case-folding cases, one per mode and in both directions.
	 *
	 * @return array[] Test name => array( mode, value, subject ).
	 */
	public function data_case_insensitivity() {
		return array(
			'exact, upper value'      => array( 'exact', '/About', '/about' ),
			'exact, upper subject'    => array( 'exact', '/about', '/ABOUT' ),
			'exact, mixed both'       => array( 'exact', '/AbOuT', '/aBoUt' ),
			'prefix, upper value'     => array( 'prefix', '/CAMPAIGNS', '/campaigns/x' ),
			'prefix, upper subject'   => array( 'prefix', '/campaigns', '/CAMPAIGNS/X' ),
			'contains, upper value'   => array( 'contains', 'AIGN', '/campaigns' ),
			'contains, upper subject' => array( 'contains', 'aign', '/CAMPAIGNS' ),
			'glob, upper value'       => array( 'glob', '/CAMPAIGNS/*', '/campaigns/giving' ),
			'glob, upper subject'     => array( 'glob', '/campaigns/*', '/CAMPAIGNS/GIVING' ),
			'glob, question mark'     => array( 'glob', '/?BOUT', '/about' ),
		);
	}

	/**
	 * Glob supports exactly two metacharacters and anchors to the whole subject.
	 *
	 * @dataProvider data_glob
	 *
	 * @param string $value    Glob pattern.
	 * @param string $subject  Subject to test.
	 * @param bool   $expected Expected result.
	 * @return void
	 */
	public function test_glob_semantics( string $value, string $subject, bool $expected ) {
		$this->assertSame(
			$expected,
			Url_Matcher::matches( 'glob', $value, $subject ),
			sprintf( 'glob("%1$s", "%2$s") returned the wrong result.', $value, $subject )
		);
	}

	/**
	 * Glob cases covering `*`, `?`, wildcard placement and anchoring.
	 *
	 * @return array[] Test name => array( value, subject, expected ).
	 */
	public function data_glob() {
		return array(
			// A lone star, and the empty pattern it is not.
			'star alone matches a path'      => array( '*', '/campaigns/giving', true ),
			'star alone matches root'        => array( '*', '/', true ),
			'star alone matches empty'       => array( '*', '', true ),
			'empty pattern, empty subject'   => array( '', '', true ),
			'empty pattern is not a star'    => array( '', '/about', false ),

			// Literal patterns anchor to the whole subject, unlike prefix and contains.
			'literal, identical'             => array( '/about', '/about', true ),
			'literal, longer subject'        => array( '/about', '/about-us', false ),
			'literal, shorter subject'       => array( '/about-us', '/about', false ),
			'literal, substring'             => array( 'bout', '/about', false ),

			// Trailing star.
			'trailing star, extra text'      => array( '/campaigns/*', '/campaigns/giving', true ),
			'trailing star, nothing left'    => array( '/campaigns/*', '/campaigns/', true ),
			'trailing star needs the stem'   => array( '/campaigns/*', '/campaigns', false ),
			'trailing star, wrong stem'      => array( '/campaigns/*', '/donate/giving', false ),

			// Leading star.
			'leading star, suffix hit'       => array( '*/donate', '/campaigns/donate', true ),
			'leading star matches nothing'   => array( '*/donate', '/donate', true ),
			'leading star, suffix miss'      => array( '*/donate', '/campaigns/donations', false ),

			// Interior star.
			'interior star'                  => array( '/campaigns/*/donate', '/campaigns/2026/donate', true ),
			'interior star spans slashes'    => array( '/campaigns/*/donate', '/campaigns/2026/spring/donate', true ),
			'interior star matches nothing'  => array( '/a*b', '/ab', true ),
			'interior star, tail mismatch'   => array( '/campaigns/*/donate', '/campaigns/2026/give', false ),

			// Question mark consumes exactly one character.
			'question mark, one character'   => array( '/?bout', '/about', true ),
			'question mark, zero characters' => array( '/?bout', '/bout', false ),
			'question mark, two characters'  => array( '/?bout', '/aabout', false ),
			'question mark on empty subject' => array( '?', '', false ),
			'question mark on one character' => array( '?', 'a', true ),
			'run of question marks'          => array( '????', '/abc', true ),
			'run of question marks, short'   => array( '????', '/ab', false ),

			// Consecutive wildcards.
			'double star behaves as one'     => array( '/a**b', '/ab', true ),
			'double star spans text'         => array( '/a**b', '/axyzb', true ),
			'double star alone'              => array( '**', '/anything', true ),
			'double star matches empty'      => array( '**', '', true ),
			'star question star, one char'   => array( '*?*', 'a', true ),
			'star question star needs one'   => array( '*?*', '', false ),
			'star question star, long'       => array( '*?*', '/campaigns', true ),
			'question between stars'         => array( '/a*?*b', '/axb', true ),
			'question between stars, empty'  => array( '/a*?*b', '/ab', false ),
			'many stars and literals'        => array( '*a*a*a*', '/xaxaxax', true ),
			'many stars, too few literals'   => array( '*a*a*a*', '/xaxax', false ),

			// Both metacharacters together, in a realistic shape.
			'mixed wildcards'                => array( '/campaigns/20??/*/donate', '/campaigns/2026/spring/donate', true ),
			'mixed wildcards, wrong year'    => array( '/campaigns/20??/*/donate', '/campaigns/199/spring/donate', false ),
		);
	}

	/**
	 * Regex metacharacters are literal characters in glob mode.
	 *
	 * This is the test that fails loudly if the matcher is ever reimplemented on
	 * top of a pattern engine. Each case is a pair: the metacharacter must *not*
	 * match what it would mean to a regex, and it *must* match itself.
	 *
	 * @dataProvider data_regex_metacharacters
	 *
	 * @param string $pattern          Glob pattern containing a regex metacharacter.
	 * @param string $regex_subject    Subject the equivalent regular expression would match and this matcher must not.
	 * @param string $matching_subject Subject this matcher must match, reading the metacharacter as a literal.
	 * @return void
	 */
	public function test_regex_metacharacters_are_literal_in_glob( string $pattern, string $regex_subject, string $matching_subject ) {
		$this->assertFalse(
			Url_Matcher::matches( 'glob', $pattern, $regex_subject ),
			sprintf(
				'glob("%1$s", "%2$s") matched. That is regular-expression behaviour: in this match language every character except * and ? is a literal. A true result here means the matcher is compiling patterns, which reintroduces the unbounded matching that docs/CLAUDE.md -> Security forbids.',
				$pattern,
				$regex_subject
			)
		);

		$this->assertTrue(
			Url_Matcher::matches( 'glob', $pattern, $matching_subject ),
			sprintf( 'glob("%1$s", "%2$s") must match: read as literals, the pattern describes exactly this subject.', $pattern, $matching_subject )
		);
	}

	/**
	 * One case per PCRE metacharacter.
	 *
	 * The `star is not a quantifier` case is the one that needs reading: a regex
	 * `xa*` matches `x`, because `*` there means "zero or more of the preceding".
	 * Here `*` means "any run of characters" and the `a` before it is a literal
	 * that must be present, so `x` is a miss and `xa` is a hit.
	 *
	 * @return array[] Test name => array( pattern, regex-only subject, matching subject ).
	 */
	public function data_regex_metacharacters() {
		return array(
			'dot'                      => array( '/a.c', '/abc', '/a.c' ),
			'dot star'                 => array( '.*', '/anything', '.foo' ),
			'anchored dot star'        => array( '^.*$', '/anything', '^.*$' ),
			'plus'                     => array( '/a+', '/aaa', '/a+' ),
			'star is not a quantifier' => array( 'xa*', 'x', 'xa' ),
			'parentheses'              => array( '(ab)', 'ab', '(ab)' ),
			'character class'          => array( '[abc]', 'a', '[abc]' ),
			'negated class'            => array( '[^a]', 'b', '[^a]' ),
			'brace quantifier'         => array( 'a{2}', 'aa', 'a{2}' ),
			'alternation'              => array( 'a|b', 'a', 'a|b' ),
			'backslash class'          => array( '\d', '5', '\d' ),
			'backslash word'           => array( '\w+', 'abc', '\w+' ),
			'dollar'                   => array( '/a$', '/a', '/a$' ),
			'caret'                    => array( '^/a', '/a', '^/a' ),
			'escaped dot'              => array( '\.', '.', '\.' ),
			'lookahead'                => array( '(?=a)', 'a', '(?=a)' ),
			'delimiters'               => array( '#/a#', '/a', '#/a#' ),
		);
	}

	/**
	 * Regex metacharacters are literal in the three non-glob modes too.
	 *
	 * @dataProvider data_metacharacters_in_plain_modes
	 *
	 * @param string $mode     Match mode.
	 * @param string $value    Match value containing metacharacters.
	 * @param string $subject  Subject to test.
	 * @param bool   $expected Expected result.
	 * @return void
	 */
	public function test_regex_metacharacters_are_literal_in_the_plain_modes( string $mode, string $value, string $subject, bool $expected ) {
		$this->assertSame(
			$expected,
			Url_Matcher::matches( $mode, $value, $subject ),
			sprintf( '%1$s("%2$s", "%3$s") returned the wrong result; the value is a literal string.', $mode, $value, $subject )
		);
	}

	/**
	 * Metacharacter cases for exact, prefix and contains.
	 *
	 * @return array[] Test name => array( mode, value, subject, expected ).
	 */
	public function data_metacharacters_in_plain_modes() {
		return array(
			'exact dot star, regex reading' => array( 'exact', '.*', '/anything', false ),
			'exact dot star, literal'       => array( 'exact', '.*', '.*', true ),
			'exact anchors, regex reading'  => array( 'exact', '^/a$', '/a', false ),
			'prefix caret, regex reading'   => array( 'prefix', '^/a', '/a/b', false ),
			'prefix caret, literal'         => array( 'prefix', '^/a', '^/a/b', true ),
			'contains dot, regex reading'   => array( 'contains', '/a.c', '/abc/', false ),
			'contains dot, literal'         => array( 'contains', '/a.c', '/x/a.c/y', true ),
			'contains class, regex reading' => array( 'contains', '[0-9]', '/campaigns/2026', false ),
			'contains class, literal'       => array( 'contains', '[0-9]', '/a[0-9]b', true ),
			'contains backslash, literal'   => array( 'contains', '\d', '/a\db', true ),
		);
	}

	/**
	 * The length cap is enforced, at the documented value.
	 *
	 * @return void
	 */
	public function test_the_value_length_cap_is_enforced() {
		$this->assertSame(
			255,
			Url_Matcher::MAX_VALUE_LENGTH,
			'The cap is 255 in docs/CLAUDE.md -> URL match language. Raising it widens the worst case of the glob walk, which is bounded by the product of the pattern and subject lengths.'
		);

		$at_cap = str_repeat( 'a', Url_Matcher::MAX_VALUE_LENGTH );
		$over   = str_repeat( 'a', Url_Matcher::MAX_VALUE_LENGTH + 1 );

		$this->assertTrue(
			Url_Matcher::is_valid_value( $at_cap ),
			'A value of exactly MAX_VALUE_LENGTH bytes is within the cap.'
		);
		$this->assertFalse(
			Url_Matcher::is_valid_value( $over ),
			'A value one byte over MAX_VALUE_LENGTH is outside the cap.'
		);
		$this->assertTrue(
			Url_Matcher::is_valid_value( '' ),
			'An empty value is trivially within the cap; emptiness is a matching question, not a validation one.'
		);
	}

	/**
	 * An over-length value matches nothing, in any mode.
	 *
	 * Save-time validation is the gate an author sees. This asserts the second
	 * gate: a value that reached the database by some other route still cannot
	 * widen the worst-case cost of a match, and it fails closed rather than
	 * throwing during a page render.
	 *
	 * @dataProvider data_modes
	 *
	 * @param string $mode Match mode.
	 * @return void
	 */
	public function test_an_over_length_value_never_matches( string $mode ) {
		$over = str_repeat( 'a', Url_Matcher::MAX_VALUE_LENGTH + 1 );

		$this->assertFalse(
			Url_Matcher::matches( $mode, $over, $over ),
			sprintf( 'Mode "%s": an over-length value must not match even a subject identical to it.', $mode )
		);

		$this->assertFalse(
			Url_Matcher::matches( $mode, $over, str_repeat( 'a', 5000 ) ),
			sprintf( 'Mode "%s": an over-length value must not match a longer subject either. Under prefix and contains this subject is a match on the string comparison alone, so a true result here means the cap is not being consulted at match time.', $mode )
		);

		$this->assertTrue(
			Url_Matcher::matches( $mode, str_repeat( 'a', Url_Matcher::MAX_VALUE_LENGTH ), str_repeat( 'a', Url_Matcher::MAX_VALUE_LENGTH ) ),
			sprintf( 'Mode "%s": the same value one byte shorter must match, proving the rejections above were the length and nothing else.', $mode )
		);
	}

	/**
	 * The four supported modes, one per case.
	 *
	 * @return array[] Test name => array( mode ).
	 */
	public function data_modes() {
		return array(
			'exact'    => array( 'exact' ),
			'prefix'   => array( 'prefix' ),
			'contains' => array( 'contains' ),
			'glob'     => array( 'glob' ),
		);
	}

	/**
	 * The cap is measured in bytes, which is the stricter reading for multibyte input.
	 *
	 * @return void
	 */
	public function test_the_length_cap_is_measured_in_bytes() {
		// U+00E9, two bytes in UTF-8. 127 of them is 254 bytes; 128 is 256.
		$within = str_repeat( "\xC3\xA9", 127 );
		$beyond = str_repeat( "\xC3\xA9", 128 );

		$this->assertSame( 254, strlen( $within ), 'Fixture drift: the within-cap string must be 254 bytes.' );
		$this->assertSame( 256, strlen( $beyond ), 'Fixture drift: the beyond-cap string must be 256 bytes.' );

		$this->assertTrue(
			Url_Matcher::is_valid_value( $within ),
			'254 bytes is within the cap.'
		);
		$this->assertFalse(
			Url_Matcher::is_valid_value( $beyond ),
			'256 bytes is outside the cap even though it is only 128 characters. The walk is byte-oriented, so bytes are what bound its cost, and the byte reading is never the looser of the two.'
		);
	}

	/**
	 * Inputs that would go exponential under a naive pattern translation complete quickly.
	 *
	 * Both halves matter. The result assertion catches a matcher that got fast by
	 * getting wrong; the elapsed-time assertion catches one that stayed correct
	 * and became unbounded.
	 *
	 * @dataProvider data_catastrophic_inputs
	 *
	 * @param string $pattern  Glob pattern.
	 * @param string $subject  Subject to test.
	 * @param bool   $expected Expected result.
	 * @return void
	 */
	public function test_catastrophic_inputs_complete_in_bounded_time( string $pattern, string $subject, bool $expected ) {
		$this->assertTrue(
			Url_Matcher::is_valid_value( $pattern ),
			'Fixture drift: this shape is meant to exercise the matcher, not the length cap, so the pattern must be within MAX_VALUE_LENGTH.'
		);

		$started = hrtime( true );
		$result  = Url_Matcher::matches( 'glob', $pattern, $subject );
		$elapsed = ( hrtime( true ) - $started ) / 1000000000;

		$this->assertSame(
			$expected,
			$result,
			sprintf( 'A pattern of %1$d characters against a subject of %2$d returned the wrong result.', strlen( $pattern ), strlen( $subject ) )
		);

		$this->assertLessThan(
			self::MATCH_TIME_BUDGET_SECONDS,
			$elapsed,
			sprintf(
				'Matching took %1$01.4f seconds against a budget of %2$01.1f. This shape is the classic catastrophic-backtracking input; a two-pointer walk finishes it in milliseconds, and anything that translates it to a pattern or recurses over its wildcards does not finish at all. Pattern length %3$d, subject length %4$d.',
				$elapsed,
				self::MATCH_TIME_BUDGET_SECONDS,
				strlen( $pattern ),
				strlen( $subject )
			)
		);
	}

	/**
	 * Pattern and subject shapes known to defeat naive glob implementations.
	 *
	 * Every shape pairs many wildcards with a subject built from a single
	 * repeated character, so that each wildcard has the maximum number of
	 * plausible resume points. The non-matching variants are the expensive ones:
	 * a match can stop early, a miss cannot.
	 *
	 * @return array[] Test name => array( pattern, subject, expected ).
	 */
	public function data_catastrophic_inputs() {
		return array(
			'ten stars, never satisfied'      => array(
				'*a*a*a*a*a*a*a*a*a*b',
				str_repeat( 'a', 2000 ),
				false,
			),
			'ten stars, satisfied at the end' => array(
				'*a*a*a*a*a*a*a*a*a*b',
				str_repeat( 'a', 2000 ) . 'b',
				true,
			),
			'alternating star and literal'    => array(
				str_repeat( 'a*', 25 ) . 'b',
				str_repeat( 'a', 2000 ),
				false,
			),
			'stars and question marks'        => array(
				str_repeat( '*?', 20 ) . 'b',
				str_repeat( 'a', 2000 ),
				false,
			),
			'a run of sixty stars'            => array(
				str_repeat( '*', 60 ) . 'b',
				str_repeat( 'a', 3000 ),
				false,
			),
			'pattern at the length cap'       => array(
				str_repeat( 'a*', 127 ) . 'b',
				str_repeat( 'a', 1500 ),
				false,
			),
			'wildcards over a matching run'   => array(
				str_repeat( '*a', 20 ) . '*',
				str_repeat( 'a', 2500 ),
				true,
			),
			'question marks, length mismatch' => array(
				str_repeat( '?', 200 ),
				str_repeat( 'a', 2000 ),
				false,
			),
			'a plausible campaign pattern'    => array(
				'/campaigns/*/*/*/*/*/*/*/*/donate/thanks',
				'/campaigns/' . str_repeat( 'a/', 800 ),
				false,
			),
		);
	}

	/**
	 * Normalisation strips the origin, the query string and the fragment.
	 *
	 * @dataProvider data_normalise_origin_query_fragment
	 *
	 * @param string $url      Input URL or path.
	 * @param string $expected Expected normalised path.
	 * @return void
	 */
	public function test_normalise_path_strips_origin_query_and_fragment( string $url, string $expected ) {
		$this->assertSame(
			$expected,
			Url_Matcher::normalise_path( $url ),
			sprintf( 'normalise_path("%s") returned the wrong path.', $url )
		);
	}

	/**
	 * Origin, query and fragment cases.
	 *
	 * The `/go/https://…` case is the one worth reading twice: a bare search for
	 * `://` would treat `/go/https` as a scheme and discard the real path. The
	 * doubled slash in the surviving path is collapsed like any other, which is
	 * why the expectation reads `https:/` rather than `https://`.
	 *
	 * @return array[] Test name => array( url, expected ).
	 */
	public function data_normalise_origin_query_fragment() {
		return array(
			'absolute url'                    => array( 'https://example.com/foo/bar', '/foo/bar' ),
			'absolute url with query'         => array( 'https://example.com/foo?x=1', '/foo' ),
			'absolute url with fragment'      => array( 'https://example.com/foo#top', '/foo' ),
			'absolute url with both'          => array( 'https://example.com/foo/bar?x=1#frag', '/foo/bar' ),
			'uppercase scheme and host'       => array( 'HTTPS://EXAMPLE.COM/Foo', '/Foo' ),
			'scheme with a plus'              => array( 'web+popkit://host/path', '/path' ),
			'no path at all'                  => array( 'https://example.com', '/' ),
			'no path, query only'             => array( 'https://example.com?x=1', '/' ),
			'port in the authority'           => array( 'http://example.com:8080/foo', '/foo' ),
			'credentials in the authority'    => array( 'https://user:pass@example.com/foo', '/foo' ),
			'bare path with query'            => array( '/foo/bar?x=1&y=2', '/foo/bar' ),
			'bare path with fragment'         => array( '/foo/bar#top', '/foo/bar' ),
			'question mark inside fragment'   => array( '/foo#a?b', '/foo' ),
			'fragment after query'            => array( '/foo?a=1#b', '/foo' ),
			'query is the whole remainder'    => array( '/?x=1', '/' ),
			'fragment is the whole remainder' => array( '/#top', '/' ),
			'scheme-like text inside a path'  => array( '/go/https://example.com/x', '/go/https:/example.com/x' ),
			'digit-led pseudo scheme'         => array( '1http://example.com/x', '/1http:/example.com/x' ),
			'no scheme before the separator'  => array( '://example.com/x', '/:/example.com/x' ),
		);
	}

	/**
	 * Normalisation guarantees one leading slash and collapses runs of slashes.
	 *
	 * @dataProvider data_normalise_slashes
	 *
	 * @param string $url      Input URL or path.
	 * @param string $expected Expected normalised path.
	 * @return void
	 */
	public function test_normalise_path_collapses_slashes( string $url, string $expected ) {
		$this->assertSame(
			$expected,
			Url_Matcher::normalise_path( $url ),
			sprintf( 'normalise_path("%s") returned the wrong path.', $url )
		);
	}

	/**
	 * Slash-handling cases.
	 *
	 * A trailing slash is preserved and dot segments are not resolved: both are
	 * matched as the request actually arrived, because rewriting either would
	 * report a path the visitor never asked for.
	 *
	 * @return array[] Test name => array( url, expected ).
	 */
	public function data_normalise_slashes() {
		return array(
			'already normal'           => array( '/campaigns/giving', '/campaigns/giving' ),
			'missing leading slash'    => array( 'campaigns/giving', '/campaigns/giving' ),
			'empty input'              => array( '', '/' ),
			'a single slash'           => array( '/', '/' ),
			'doubled leading slash'    => array( '//campaigns', '/campaigns' ),
			'many leading slashes'     => array( '/////campaigns', '/campaigns' ),
			'interior run'             => array( '/a///b', '/a/b' ),
			'runs everywhere'          => array( '//a//b//c//', '/a/b/c/' ),
			'trailing slash preserved' => array( '/about/', '/about/' ),
			'trailing run collapsed'   => array( '/about///', '/about/' ),
			'dot segments left alone'  => array( '/a/../b', '/a/../b' ),
			'single dot left alone'    => array( '/a/./b', '/a/./b' ),
			'slashes then a query'     => array( '//a//b?x=1', '/a/b' ),
		);
	}

	/**
	 * Percent-decoding happens exactly once, and last.
	 *
	 * The ordering is the security-relevant part. Decoding before the structural
	 * decisions would let `%23`, `%3F` and `%2F` masquerade as a fragment, a query
	 * and a path separator; decoding a second time afterwards is the classic
	 * double-decode filter bypass. `%252e` is the canonical probe: one decode
	 * yields `%2e`, two yield `.`.
	 *
	 * @dataProvider data_normalise_decoding
	 *
	 * @param string $url      Input URL or path.
	 * @param string $expected Expected normalised path.
	 * @return void
	 */
	public function test_normalise_path_decodes_exactly_once( string $url, string $expected ) {
		$this->assertSame(
			$expected,
			Url_Matcher::normalise_path( $url ),
			sprintf( 'normalise_path("%s") returned the wrong path.', $url )
		);
	}

	/**
	 * Decoding cases, including both double-decode probes.
	 *
	 * @return array[] Test name => array( url, expected ).
	 */
	public function data_normalise_decoding() {
		return array(
			'one decode of a space'           => array( '/a%20b', '/a b' ),
			'one decode of a dot'             => array( '/%2e', '/.' ),
			'one decode of two dots'          => array( '/%2e%2e/admin', '/../admin' ),
			'double-encoded dot stops once'   => array( '/%252e', '/%2e' ),
			'double-encoded slash stops once' => array( '/%252f', '/%2f' ),
			'double-encoded run stops once'   => array( '/a%252e%252e/b', '/a%2e%2e/b' ),
			'plus is not a space'             => array( '/a+b', '/a+b' ),
			'encoded plus'                    => array( '/a%2Bb', '/a+b' ),
			'encoded percent'                 => array( '/100%25', '/100%' ),
			'encoded hash is not a fragment'  => array( '/a%23b?q=1', '/a#b' ),
			'encoded question is not a query' => array( '/a%3Fb', '/a?b' ),
			'uppercase and lowercase octets'  => array( '/%2F%2f', '///' ),
			'encoded slashes survive whole'   => array( '/a%2F%2Fb', '/a//b' ),
			'encoded slashes at the front'    => array( '/%2F%2Fa', '///a' ),
			'lone percent left alone'         => array( '/100%', '/100%' ),
			'truncated escape left alone'     => array( '/a%2', '/a%2' ),
			'encoded scheme is not a scheme'  => array( 'https%3A%2F%2Fexample.com/x', '/https://example.com/x' ),
		);
	}

	/**
	 * Every normalised path begins with a slash.
	 *
	 * `Url_Matcher::matches()` documents that an `exact` rule with an empty value
	 * can never match a normalised path. That claim rests entirely on this
	 * property, so it is asserted directly rather than inferred.
	 *
	 * @return void
	 */
	public function test_normalise_path_always_returns_a_leading_slash() {
		$inputs = array(
			'',
			'/',
			'//',
			'about',
			'/about',
			'?x=1',
			'#top',
			'https://example.com',
			'https://example.com/',
			'https://example.com?x=1',
			'://',
			'%2e',
			'%2F',
			'a://b',
		);

		foreach ( $inputs as $input ) {
			$path = Url_Matcher::normalise_path( $input );

			$this->assertNotSame( '', $path, sprintf( 'normalise_path("%s") returned an empty path.', $input ) );
			$this->assertTrue(
				str_starts_with( $path, '/' ),
				sprintf( 'normalise_path("%1$s") returned "%2$s", which does not begin with a slash.', $input, $path )
			);
			$this->assertFalse(
				Url_Matcher::matches( 'exact', '', $path ),
				sprintf( 'An exact rule with an empty value must never match the normalised path "%s".', $path )
			);
		}
	}

	/**
	 * Normalisation preserves case; only matching folds it.
	 *
	 * Keeping the two responsibilities apart matters because the normalised path
	 * is also what the editor and any future condition will display.
	 *
	 * @return void
	 */
	public function test_normalisation_preserves_case_and_matching_folds_it() {
		$path = Url_Matcher::normalise_path( 'https://Example.com/Campaigns/Giving-Tuesday?x=1' );

		$this->assertSame(
			'/Campaigns/Giving-Tuesday',
			$path,
			'normalise_path() must not lowercase the path. Case folding belongs to matches(), which does it once for both operands.'
		);

		$this->assertTrue(
			Url_Matcher::matches( 'exact', '/campaigns/giving-tuesday', $path ),
			'The matcher folds case, so a lowercase rule matches a mixed-case path.'
		);
	}
}
