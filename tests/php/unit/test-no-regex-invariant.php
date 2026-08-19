<?php
/**
 * Enforcement of the no-regex invariant across the plugin's PHP source.
 *
 * No user-supplied regular expression is compiled anywhere in this plugin. PHP
 * offers no dependable per-match wall-clock timeout — `pcre.backtrack_limit`
 * narrows the exposure but does not bound it — so a plugin that compiles
 * author-supplied patterns cannot honestly claim that matching a URL
 * terminates. URL targeting is therefore a closed four-mode match language
 * (`exact`, `prefix`, `contains`, `glob`) run by a linear two-pointer walk, and
 * `Popkit\Url_Matcher` is its only implementation. See docs/CLAUDE.md ->
 * Security -> URL match language.
 *
 * That is a property of the whole tree, not of one class. A single
 * `preg_match()` added anywhere in includes/ — in a sanitizer normalizing a
 * path, in a REST argument validator, in a helper that looked like it needed
 * "just a quick pattern" — reintroduces an unbounded matcher on request-shaped
 * input and quietly voids the guarantee, while every existing test stays green.
 * docs/build-plan.md Phase 1 names the missing check outright: "a
 * repository-wide grep asserts zero occurrences of `preg_match` against
 * user-supplied patterns". This file is that check.
 *
 * **Why the tokenizer and not a text search.** The obvious implementation is a
 * substring search for `preg_`, and it is the wrong one, because it also fires
 * on the paragraph above. A guard that forbids *explaining* the invariant is a
 * guard whose first maintenance action is deleting the explanation. So the scan
 * runs each file through `token_get_all()` and considers only call-shaped
 * occurrences: an identifier token naming a PCRE function, followed by `(`, not
 * preceded by `->`, `?->`, `::`, `function` or `new`. Comments, docblocks and
 * string literals are separate token types and are never even examined. Prose
 * about regular expressions is free; calling one is not.
 *
 * **Why this scan does not violate the invariant it enforces.** There is no
 * `preg_*` call below. The tokenizer is PHP's own lexer, its input is this
 * repository's source rather than anything a site visitor or an author can
 * supply, and it runs at test time, never in a request.
 *
 * **Scope.** includes/ — every class the plugin loads at runtime. The bootstrap
 * files popkit.php and uninstall.php sit outside it and are not scanned here;
 * neither performs matching of any kind, and widening the scan is the right move
 * the moment either one grows past its present job.
 *
 * The last three tests below scan the scanner. They feed it a snippet that does
 * call PCRE and a snippet that only talks about it, and assert it separates the
 * two. Without them, a scan that had silently stopped tokenizing — a renamed
 * constant, an empty file list, a swallowed parse error — would report an empty
 * offense list and look exactly like a clean tree.
 *
 * @package Popkit
 */

use PHPUnit\Framework\TestCase;

/**
 * Asserts that no PCRE or POSIX regex function is called anywhere under includes/.
 */
final class Test_Popkit_No_Regex_Invariant extends TestCase {

	/**
	 * Function names that may not be called from includes/, lowercased.
	 *
	 * The PCRE family in full, plus the removed POSIX `ereg` family and the
	 * multibyte `mb_ereg` that outlived it, plus `fnmatch` — which is not a regex
	 * but is a pattern language with the same problem: it is implemented by a
	 * backtracking matcher with no bound, and it is the shortcut somebody reaches
	 * for when reimplementing glob targeting.
	 *
	 * PHP resolves function names case-insensitively, so candidates are lowercased
	 * before they are looked up here.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_FUNCTIONS = array(
		'preg_match',
		'preg_match_all',
		'preg_replace',
		'preg_replace_callback',
		'preg_replace_callback_array',
		'preg_split',
		'preg_quote',
		'preg_grep',
		'preg_last_error',
		'preg_last_error_msg',
		'fnmatch',
		'ereg',
		'eregi',
		'ereg_replace',
		'eregi_replace',
		'mb_ereg',
		'mb_eregi',
		'mb_ereg_match',
		'mb_ereg_replace',
		'mb_eregi_replace',
		'mb_ereg_replace_callback',
	);

	/**
	 * Token types that, immediately before an identifier, mean it is not a function call.
	 *
	 * `$object->preg_match()`, `$object?->preg_match()` and `Klass::preg_match()`
	 * call a method that merely shares a name with a PHP function; `function
	 * preg_match()` declares one; `new preg_match()` instantiates a class. None of
	 * them reaches PCRE, and treating them as offenses would make the guard fire
	 * on code that is not the thing being guarded against.
	 *
	 * @var int[]
	 */
	private const NON_CALL_PREFIXES = array(
		T_OBJECT_OPERATOR,
		T_NULLSAFE_OBJECT_OPERATOR,
		T_DOUBLE_COLON,
		T_FUNCTION,
		T_NEW,
		T_CONST,
	);

	/**
	 * The tokenizer extension is present.
	 *
	 * Every assertion in this file is worthless without it, and a scan that cannot
	 * tokenize would otherwise return an empty offense list — indistinguishable
	 * from a clean tree. Assert rather than skip: a security invariant that
	 * silently opts out of being checked is worse than one that was never written.
	 *
	 * @return void
	 */
	public function test_the_tokenizer_is_available() {
		$this->assertTrue(
			function_exists( 'token_get_all' ),
			'The tokenizer extension is missing, so the no-regex invariant cannot be checked at all. Enable ext-tokenizer; do not skip this file.'
		);
	}

	/**
	 * The scan actually has files to scan.
	 *
	 * A mistyped directory, a moved includes/ or a filter that matches nothing all
	 * produce a passing scan over zero files. This pins the scan to a tree that
	 * demonstrably exists and demonstrably contains the classes it claims to cover.
	 *
	 * @return void
	 */
	public function test_the_scan_covers_the_includes_directory() {
		$files = self::php_files_under_includes();

		$this->assertNotEmpty(
			$files,
			'No PHP files were found under includes/. The no-regex scan is passing because it examined nothing.'
		);

		$this->assertContains(
			'includes/class-url-matcher.php',
			$files,
			'includes/class-url-matcher.php is the class the whole invariant exists to protect, and the scan did not reach it. Either the file moved or the scan is looking in the wrong place.'
		);
	}

	/**
	 * No PCRE or POSIX regex function is called anywhere under includes/.
	 *
	 * This is the invariant. Everything else in the file exists to make this
	 * assertion trustworthy.
	 *
	 * @return void
	 */
	public function test_no_regex_function_is_called_under_includes() {
		$offenses = array();

		foreach ( self::php_files_under_includes() as $relative_path ) {
			$absolute_path = self::plugin_dir() . '/' . $relative_path;
			$source        = file_get_contents( $absolute_path );

			$this->assertIsString(
				$source,
				sprintf( '%s could not be read, so it was never scanned for regex calls.', $relative_path )
			);

			$offenses = array_merge( $offenses, self::offenses_in_source( $source, $relative_path ) );
		}

		$this->assertSame(
			array(),
			$offenses,
			"A regex function is called under includes/, which voids popkit's bounded-matching guarantee. URL targeting uses the constrained match language in docs/CLAUDE.md -> Security -> URL match language; extend Popkit\\Url_Matcher instead of reaching for PCRE. Offenses:"
				. PHP_EOL . implode( PHP_EOL, $offenses )
		);
	}

	/**
	 * The scan recognizes a real call, and reports where it is.
	 *
	 * A guard nobody has watched fail is a guess. This drives the scanner with a
	 * snippet that does call PCRE and asserts both halves of a useful failure: that
	 * it is detected at all, and that the message names the file and the line, so
	 * the person who broke the build is not left grepping for it.
	 *
	 * @return void
	 */
	public function test_the_scan_detects_a_regex_call() {
		$snippet = "<?php\n"
			. "\$normalized = trim( \$path );\n"
			. "\$hit        = preg_match( \$pattern, \$normalized );\n";

		$offenses = self::offenses_in_source( $snippet, 'includes/class-fixture.php' );

		$this->assertCount(
			1,
			$offenses,
			'The scanner did not detect a plain preg_match() call. It is not enforcing anything.'
		);

		$this->assertStringContainsString( 'includes/class-fixture.php', $offenses[0], 'A failure must name the offending file.' );
		$this->assertStringContainsString( ':3', $offenses[0], 'A failure must name the offending line.' );
		$this->assertStringContainsString( 'preg_match', $offenses[0], 'A failure must name the offending function.' );
	}

	/**
	 * Every forbidden name is detected, however it is written.
	 *
	 * The three spellings that matter are the bare call, the fully-qualified call
	 * — which is the natural way to write one inside a namespaced file, and the
	 * spelling a naive check misses — and an upper-case call, which PHP resolves to
	 * the same function because function names are case-insensitive.
	 *
	 * @return void
	 */
	public function test_every_forbidden_name_is_detected_however_it_is_written() {
		foreach ( self::FORBIDDEN_FUNCTIONS as $function_name ) {
			$spellings = array(
				$function_name . '( $a, $b )',
				'\\' . $function_name . '( $a, $b )',
				strtoupper( $function_name ) . '( $a, $b )',
			);

			foreach ( $spellings as $spelling ) {
				$offenses = self::offenses_in_source( "<?php\n\$r = " . $spelling . ";\n", 'includes/class-fixture.php' );

				$this->assertCount(
					1,
					$offenses,
					sprintf( 'The scanner missed `%s`. A name it cannot see is a name that can be reintroduced.', $spelling )
				);
			}
		}
	}

	/**
	 * Comments, string literals and same-named methods are not offenses.
	 *
	 * This is the half that keeps the invariant documentable. If explaining why
	 * regex is forbidden trips the guard, the explanation gets deleted and the
	 * reason is lost long before the rule is.
	 *
	 * @return void
	 */
	public function test_the_scan_ignores_prose_and_same_named_methods() {
		$snippet = "<?php\n"
			. "/**\n"
			. " * Matching is linear. Never preg_match() the author's value: PCRE gives no\n"
			. " * wall-clock bound, so preg_replace( \$pattern, '', \$subject ) cannot be\n"
			. " * shown to terminate. fnmatch() has the same defect.\n"
			. " */\n"
			. "// Rejected during review: preg_split( '/,/', \$value ).\n"
			. "# Also rejected: preg_quote( \$value ).\n"
			. "\$documented = 'preg_match';\n"
			. "\$explained  = \"call preg_replace( ) here and the build fails\";\n"
			. "\$heredoc    = <<<TXT\npreg_match( \$a, \$b )\nTXT;\n"
			. "\$a = \$matcher->preg_match( \$value );\n"
			. "\$b = \$matcher?->preg_match( \$value );\n"
			. "\$c = Matcher::preg_match( \$value );\n"
			. "\$d = \$flags & PREG_SPLIT_NO_EMPTY;\n"
			. "\$e = popkit_preg_match( \$value );\n";

		$this->assertSame(
			array(),
			self::offenses_in_source( $snippet, 'includes/class-fixture.php' ),
			'The scanner flagged prose, a string literal or a method that merely shares a name with a PHP function. A guard that punishes documenting the invariant guarantees the documentation is what gets removed.'
		);
	}

	/**
	 * Returns the call-shaped regex occurrences in one PHP source string.
	 *
	 * Only `token_get_all()` decides what is code here. An identifier is an offense
	 * when it names a forbidden function, is followed by `(`, and is not preceded
	 * by one of the operators in NON_CALL_PREFIXES. Qualified names are reduced to
	 * their final segment, so `\preg_match()` and `Vendor\preg_match()` are both
	 * caught — the second is not the PHP function, but a namespaced shim wearing
	 * its name is worth a human look either way.
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

			if ( ! in_array( $token[0], array( T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
				continue;
			}

			$name = self::leaf_name( $token[1] );

			if ( ! in_array( $name, self::FORBIDDEN_FUNCTIONS, true ) ) {
				continue;
			}

			$previous = self::significant_index( $tokens, $index, -1 );

			if ( null !== $previous && is_array( $tokens[ $previous ] ) && in_array( $tokens[ $previous ][0], self::NON_CALL_PREFIXES, true ) ) {
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
	 * Returns every PHP file under includes/, as forward-slashed relative paths.
	 *
	 * Sorted, so a failure lists offenses in the same order on every machine.
	 *
	 * @return string[] Paths relative to the plugin root, e.g. `includes/class-url-matcher.php`.
	 */
	private static function php_files_under_includes() {
		$root = self::plugin_dir() . '/includes';

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

			$files[] = 'includes/' . substr( $path, strlen( str_replace( '\\', '/', $root ) ) + 1 );
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
}
