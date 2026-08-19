<?php
/**
 * Constrained URL match language: exact, prefix, contains, and a two-character glob.
 *
 * @package Popkit
 * @since   0.1.0
 */

namespace Popkit;

defined( 'ABSPATH' ) || exit;

/**
 * Regex-free matcher backing the `url_path` and `referrer` conditions.
 *
 * Popup plugins traditionally let an author paste a regular expression into a
 * targeting field. This one does not, and the omission is the whole point of
 * the class.
 *
 * PHP provides no dependable per-match wall-clock timeout for PCRE.
 * `pcre.backtrack_limit` reduces exposure but is not a guarantee: it counts
 * backtracks rather than elapsed time, and it is routinely raised by hosting
 * `php.ini` files and by other plugins. Worse, when it does trip, the PCRE match
 * function returns `false` — the same value it returns for an ordinary
 * non-match — so code that does not separately consult the PCRE error accessor
 * cannot tell a runaway pattern from a clean miss. A plugin that accepts
 * arbitrary patterns therefore cannot honestly claim that matching is bounded,
 * and a stored pattern is re-evaluated on every front-end pageview, including
 * the ones filling a cache. Hence the invariant in `docs/CLAUDE.md` -> Security:
 * no user-supplied regular expressions anywhere, and no PCRE function in this
 * file at all. The identifiers are not even written here, so a repository-wide
 * grep for them stays clean without needing to special-case comments.
 *
 * What replaces it:
 *
 * - `exact`, `prefix` and `contains` are plain string operations.
 * - `glob` supports exactly two metacharacters, `*` and `?`. Everything else,
 *   including `[`, `]`, `{`, `}`, `(`, `)`, `.`, `+` and `\`, is a literal
 *   character with no special meaning.
 *
 * Glob is matched by an iterative two-pointer walk carrying a single backtrack
 * resume point — the position of the most recent `*` and the subject offset it
 * was last resumed from. Nothing is compiled, nothing is translated to a regex,
 * and there is no recursion, so the classic catastrophic input
 * (`a*a*a*a*a*a*b` against a long run of `a`) that hangs a naive
 * glob-to-regex translation completes here in bounded time. Worst-case work is
 * {@see Url_Matcher::MAX_VALUE_LENGTH} multiplied by the subject length, in
 * single-byte comparisons, with constant memory.
 *
 * Comparison is case-insensitive and byte-oriented. Case is folded once, before
 * either walk begins, and the fold covers `A-Z` and nothing else. See
 * {@see Url_Matcher::fold_case()} for why the fold is spelled out by hand rather
 * than delegated to strtolower().
 *
 * @since 0.1.0
 */
final class Url_Matcher {

	/**
	 * Uppercase ASCII letters: the search half of the case-folding map.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const ASCII_UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	/**
	 * Lowercase ASCII letters: the replacement half of the case-folding map.
	 *
	 * Byte for byte, in the same order as {@see Url_Matcher::ASCII_UPPERCASE}.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private const ASCII_LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';

	/**
	 * Maximum accepted length of a match value, in bytes.
	 *
	 * Enforced twice: at save time by validation, so an author sees an error,
	 * and again here, so a value that reached the database by some other route
	 * cannot widen the worst-case cost of the glob walk. Measured in bytes
	 * rather than characters because the walk itself is byte-oriented; for
	 * multibyte input this is the stricter of the two readings, never the looser
	 * one. Save-time validation must call {@see Url_Matcher::is_valid_value()}
	 * rather than measuring independently, so the two can never disagree.
	 *
	 * @since 0.1.0
	 * @var int
	 */
	public const MAX_VALUE_LENGTH = 255;

	/**
	 * The supported match modes.
	 *
	 * Single source of truth for save-time validation, the registered field
	 * schema, and the editor's mode selector.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Mode identifiers, in the order they are offered to authors.
	 */
	public static function modes(): array {
		return array( 'exact', 'prefix', 'contains', 'glob' );
	}

	/**
	 * Whether a mode identifier is one this matcher understands.
	 *
	 * Intended for save-time validation, where an unrecognized mode is an error
	 * an author can see and fix.
	 *
	 * @since 0.1.0
	 *
	 * @param string $mode Mode identifier to test.
	 * @return bool True when the mode is supported.
	 */
	public static function is_valid_mode( string $mode ): bool {
		return in_array( $mode, self::modes(), true );
	}

	/**
	 * Whether a match value is within the length cap.
	 *
	 * Exposed so that save-time validation and match-time enforcement share one
	 * implementation. A value that fails this check is rejected at save time
	 * with an editor-visible error; if one is encountered at match time anyway,
	 * {@see Url_Matcher::matches()} treats it as a non-match rather than
	 * throwing, because a targeting miss during a page render must never become
	 * a fatal error.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Match value to test.
	 * @return bool True when the value is at most MAX_VALUE_LENGTH bytes.
	 */
	public static function is_valid_value( string $value ): bool {
		return strlen( $value ) <= self::MAX_VALUE_LENGTH;
	}

	/**
	 * Test a subject against a literal match value under one of the four modes.
	 *
	 * Empty-value semantics differ by mode, and each choice is deliberate:
	 *
	 * - `prefix` with an empty value matches everything. Every string starts
	 *   with the empty string, and the alternative — treating it as "match
	 *   nothing" — would make a half-filled targeting field silently suppress a
	 *   popup with no visible cause.
	 * - `contains` with an empty value matches everything, for the same reason.
	 * - `exact` with an empty value matches only an empty subject. Since
	 *   {@see Url_Matcher::normalize_path()} never returns an empty string, an
	 *   `exact` rule with an empty value never matches a normalized path.
	 * - `glob` with an empty pattern matches only an empty subject. An empty
	 *   pattern is not an implicit `*`; an author who wants "any path" writes
	 *   one.
	 *
	 * An unrecognized mode and an over-length value both return false rather
	 * than throwing. Validation is the save-time gate; this is the runtime one.
	 *
	 * @since 0.1.0
	 *
	 * @param string $mode    One of `exact`, `prefix`, `contains`, `glob`.
	 * @param string $value   Literal match value, at most MAX_VALUE_LENGTH bytes.
	 * @param string $subject Subject to test, normally a normalized path.
	 * @return bool True when the subject matches.
	 */
	public static function matches( string $mode, string $value, string $subject ): bool {
		if ( ! self::is_valid_mode( $mode ) || ! self::is_valid_value( $value ) ) {
			return false;
		}

		/*
		 * Fold case exactly once, before any walk begins. Folding inside the glob
		 * loop would turn an O(n*m) comparison budget into O(n*m) folding calls,
		 * which is the difference between a bounded matcher and a slow one.
		 */
		$pattern  = self::fold_case( $value );
		$haystack = self::fold_case( $subject );

		return match ( $mode ) {
			'exact'    => $haystack === $pattern,
			'prefix'   => str_starts_with( $haystack, $pattern ),
			'contains' => str_contains( $haystack, $pattern ),
			'glob'     => self::glob_matches( $pattern, $haystack ),
			// Unreachable: is_valid_mode() gated every arm above. Present so a
			// future mode added to modes() and not to this expression degrades
			// to "no match" instead of an UnhandledMatchError on a page render.
			default    => false,
		};
	}

	/**
	 * Reduce a URL or path to the normalized path used for matching.
	 *
	 * Steps, in this order:
	 *
	 * 1. Strip `scheme://host` when an explicit scheme is present.
	 * 2. Strip the fragment, then the query string.
	 * 3. Ensure exactly one leading slash and collapse runs of slashes.
	 * 4. Percent-decode once.
	 *
	 * The order is the security-relevant part. Every structural decision — what
	 * is a host, where the query begins, what separates two path segments — is
	 * taken against the raw, still-encoded string, and decoding happens last and
	 * exactly once. Decoding first would let `%23` and `%3F` masquerade as
	 * fragment and query delimiters and `%2F` as a path separator; decoding
	 * again afterwards is the classic double-decode filter bypass. A decoded
	 * `/`, `?` or `#` in the result is therefore an ordinary literal character
	 * that was encoded in the URL, and it is treated as one.
	 *
	 * Two further choices worth knowing:
	 *
	 * - A trailing slash is preserved. `/about` and `/about/` are different
	 *   paths here, matching how the request actually arrived; reconciling them
	 *   is a targeting decision for the author, not a silent rewrite.
	 * - A leading `//` is treated as a duplicated separator and collapsed, not
	 *   as the start of a scheme-relative authority. Request paths with doubled
	 *   slashes are common; scheme-relative references reaching this method are
	 *   not, and collapsing keeps the result aligned with the path WordPress
	 *   actually served.
	 *
	 * Dot segments are not resolved. `/a/../b` is matched as written, because a
	 * request for it is served as written by WordPress rather than redirected,
	 * and quietly rewriting it would report a path the visitor never requested.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Absolute URL, path with query and fragment, or bare path.
	 * @return string Normalized path, always beginning with a single slash.
	 */
	public static function normalize_path( string $url ): string {
		$path = self::strip_origin( $url );

		/*
		 * Fragment before query. The fragment is the last component of a URL, so
		 * a '?' appearing inside one is part of the fragment and not a query
		 * delimiter; cutting the query first would leave that fragment behind.
		 */
		$fragment_start = strpos( $path, '#' );

		if ( false !== $fragment_start ) {
			$path = substr( $path, 0, $fragment_start );
		}

		$query_start = strpos( $path, '?' );

		if ( false !== $query_start ) {
			$path = substr( $path, 0, $query_start );
		}

		/*
		 * rawurldecode() rather than urldecode(): '+' is a literal plus sign in a
		 * path, not an encoded space. Called once, and only here.
		 */
		return rawurldecode( self::collapse_slashes( $path ) );
	}

	/**
	 * Remove `scheme://host` from the front of a URL.
	 *
	 * Only an explicit scheme triggers stripping, and the candidate scheme is
	 * verified character by character. A bare `strpos( $url, '://' )` would
	 * mangle a path that merely contains those three characters, such as
	 * `/go/https://example.com`, by discarding everything before them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url URL or path.
	 * @return string Everything from the first slash after the host, or the input unchanged when no scheme was found.
	 */
	private static function strip_origin( string $url ): string {
		$scheme_end = strpos( $url, '://' );

		if ( false === $scheme_end || ! self::is_scheme( substr( $url, 0, $scheme_end ) ) ) {
			return $url;
		}

		$path_start = strpos( $url, '/', $scheme_end + 3 );

		// No slash after the host means the URL carried no path at all.
		return false === $path_start ? '' : substr( $url, $path_start );
	}

	/**
	 * Whether a string is a syntactically valid URI scheme.
	 *
	 * RFC 3986: an initial letter followed by letters, digits, `+`, `-` or `.`.
	 * Checked with a linear character walk rather than a pattern, for the same
	 * reason as everything else in this file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $candidate Text preceding `://` in a URL.
	 * @return bool True when the text is a valid scheme.
	 */
	private static function is_scheme( string $candidate ): bool {
		if ( '' === $candidate || ! ctype_alpha( $candidate[0] ) ) {
			return false;
		}

		$length = strlen( $candidate );

		for ( $index = 1; $index < $length; ++$index ) {
			$character = $candidate[ $index ];

			if ( ctype_alnum( $character ) || '+' === $character || '-' === $character || '.' === $character ) {
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Guarantee one leading slash and collapse every run of slashes to one.
	 *
	 * A single left-to-right pass: each input byte is examined once and either
	 * appended or skipped. An empty input yields `/`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path, possibly empty, possibly with repeated slashes.
	 * @return string Path beginning with exactly one slash.
	 */
	private static function collapse_slashes( string $path ): string {
		$normalized = '/';
		$length     = strlen( $path );

		// True because the leading slash above has just been written.
		$previous_was_slash = true;

		for ( $index = 0; $index < $length; ++$index ) {
			$character = $path[ $index ];

			if ( '/' === $character ) {
				if ( $previous_was_slash ) {
					continue;
				}

				$previous_was_slash = true;
			} else {
				$previous_was_slash = false;
			}

			$normalized .= $character;
		}

		return $normalized;
	}

	/**
	 * Fold `A-Z` to `a-z`, leaving every other byte exactly as it was.
	 *
	 * The built-in strtolower() is deliberately not used. On the PHP 8.1 floor
	 * this plugin declares, it consults `LC_CTYPE`: under a non-C locale, bytes
	 * from 0x80 upward fold according to that locale's table. Any other request
	 * during the same process can change the locale — setlocale() is
	 * process-global — so the same stored rule could match one set of subjects on
	 * one server and a strictly larger set on another, or on the same server
	 * after an unrelated plugin loads. Targeting that quietly widens with the
	 * host's configuration is exactly the failure mode this class exists to rule
	 * out, and PHP 8.2 removing the locale sensitivity does not help a site still
	 * on 8.1.
	 *
	 * mb_strtolower() with an explicit `'UTF-8'` encoding is locale-independent
	 * and was considered. It is rejected because mbstring is a separately
	 * compiled extension that is not guaranteed present, so the matcher would
	 * need a fallback path and would then fold differently depending on which
	 * path ran — reintroducing the same "matches more on some servers" problem
	 * from a different direction. It would also case-fold non-ASCII letters,
	 * which is a change in match semantics rather than a bug fix.
	 *
	 * strtr() with an explicit two-string map is always available, is a single
	 * byte-for-byte pass with no allocation beyond the result, and touches only
	 * the 26 bytes named in the map. Bytes 0x80 and above pass through unchanged,
	 * so a percent-decoded multibyte path is compared as the exact byte sequence
	 * it is. Case-insensitivity is therefore ASCII-only, which is what the
	 * documented match language in docs/CLAUDE.md describes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Text to fold.
	 * @return string Text with ASCII uppercase letters lowercased.
	 */
	private static function fold_case( string $value ): string {
		return strtr( $value, self::ASCII_UPPERCASE, self::ASCII_LOWERCASE );
	}

	/**
	 * Match a glob pattern against a subject with an iterative two-pointer walk.
	 *
	 * `*` matches any run of characters including none; `?` matches exactly one
	 * character. No other character carries meaning.
	 *
	 * The algorithm carries four integers and nothing else:
	 *
	 * - `$pattern_index` and `$subject_index`, the current positions;
	 * - `$star_index`, the position of the most recent `*`, or -1 if none;
	 * - `$resume_index`, the subject offset that `*` was last resumed from.
	 *
	 * On a mismatch the walk does not explore alternatives. It rewinds to the
	 * one remembered `*`, gives it exactly one more character, and continues.
	 * That single resume point is what removes the backtracking: every earlier
	 * `*` is permanently committed, so there is no tree to explode.
	 *
	 * Termination and cost are readable from the loop. Every iteration does one
	 * of four things: advance `$subject_index`; step `$pattern_index` past a
	 * `*`; increment `$resume_index` and reset `$subject_index` to it; or
	 * return. `$resume_index` never decreases and is bounded by the subject
	 * length, and between two increments of it `$pattern_index` advances
	 * monotonically and is bounded by the pattern length. Total iterations are
	 * therefore bounded by the product of the two lengths, with no recursion and
	 * no allocation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $pattern Case-folded glob pattern.
	 * @param string $subject Case-folded subject.
	 * @return bool True when the pattern matches the whole subject.
	 */
	private static function glob_matches( string $pattern, string $subject ): bool {
		$pattern_length = strlen( $pattern );
		$subject_length = strlen( $subject );

		$pattern_index = 0;
		$subject_index = 0;

		// -1 means no '*' has been seen, so there is nothing to rewind to.
		$star_index   = -1;
		$resume_index = 0;

		while ( $subject_index < $subject_length ) {
			$in_pattern        = $pattern_index < $pattern_length;
			$pattern_character = $in_pattern ? $pattern[ $pattern_index ] : '';

			if ( $in_pattern && '*' === $pattern_character ) {
				/*
				 * Remember this '*' and the subject offset it starts from, then
				 * assume it matches nothing. If that assumption fails later, the
				 * rewind branch below hands it one more character at a time.
				 * Overwriting $star_index here is what commits every earlier '*'
				 * permanently, and is the reason there is no backtracking tree.
				 */
				$star_index   = $pattern_index;
				$resume_index = $subject_index;
				++$pattern_index;
				continue;
			}

			if ( $in_pattern && ( '?' === $pattern_character || $pattern_character === $subject[ $subject_index ] ) ) {
				// A literal hit, or '?' consuming exactly one character.
				++$pattern_index;
				++$subject_index;
				continue;
			}

			if ( -1 !== $star_index ) {
				// Widen the remembered '*' by one character and resume after it.
				$pattern_index = $star_index + 1;
				++$resume_index;
				$subject_index = $resume_index;
				continue;
			}

			// Mismatch with no '*' to fall back on.
			return false;
		}

		// The subject is exhausted; any trailing stars may still match nothing.
		while ( $pattern_index < $pattern_length && '*' === $pattern[ $pattern_index ] ) {
			++$pattern_index;
		}

		return $pattern_index === $pattern_length;
	}
}
