/**
 * popkit — the `referrer` client condition, and the bundle's URL match language.
 *
 * Matches `document.referrer` against a literal `value` under one of four
 * modes. The referrer varies by visitor, so it can never be read in PHP during
 * emission (`docs/CLAUDE.md` -> Cache safety); this is the browser half of the
 * match language that `Popkit\Url_Matcher` implements for `url_path`.
 *
 * ## One matcher in the bundle, not two
 *
 * {@link urlMatches} is exported and is the **only** implementation of the four
 * modes in `dist/frontend.js`. Anything that later needs `exact`, `prefix`,
 * `contains` or `glob` imports it from here rather than copying it. A second
 * copy would not merely cost bytes against the 8 KB budget — it would be a
 * second place for the glob walk to acquire a backtracking bug, in a matcher
 * whose bounded running time is a documented security property.
 *
 * Its home is this module because this module is its only consumer today. If a
 * second one appears, move it to its own file in the same commit; do not leave a
 * condition module exporting a utility that its name does not advertise.
 *
 * ## Fidelity to `Popkit\Url_Matcher`
 *
 * Same four modes, same two metacharacters, same single-resume-point glob walk,
 * same 255-byte cap, same case-insensitive comparison, and the subject decoded
 * exactly once and last. Two differences remain, and both are stated plainly
 * here because one of them is a real difference in what a pattern means:
 *
 * - **The unit `?` consumes.** PHP walks bytes, JavaScript walks UTF-16 code
 *   units, and `?` consumes exactly one of whichever its engine walks. That is
 *   one character in both only while the subject stays ASCII. Past it the two
 *   counts separate, and neither of them is a character: `café` needs `caf?`
 *   here and `caf??` in PHP, and a subject ending in an emoji needs `??` here
 *   (one astral character is two code units) and `????` in PHP (four bytes).
 *   `*`, `exact`, `prefix` and `contains` are unaffected — `*` consumes a run,
 *   so its unit cannot be observed, and UTF-8 and UTF-16 are both
 *   self-synchronizing, so a substring can only ever be found on a character
 *   boundary in either.
 * - **The length cap.** {@link MAX_VALUE_LENGTH} is compared against
 *   `String.length`, which is code units rather than bytes. A value that passed
 *   PHP's save-time byte check can never fail this one, since a string's UTF-16
 *   length never exceeds its UTF-8 byte length. This is the runtime backstop for
 *   a value that reached the database by some other route, exactly as the PHP
 *   check is.
 *
 * ### What the `?` difference does and does not reach
 *
 * No single subject is ever judged twice, so no visitor is targeted
 * inconsistently: `url_path` matches a WordPress path decided in PHP, `referrer`
 * matches a third party's URL decided in the browser, and neither engine sees
 * the other's subject. What does cross over is the author. One match language is
 * documented, one editor control offers it, and someone who learns `?` against
 * a path and reuses it against a referrer is counting in a different unit
 * without being told. So the divergence is in the language, not in any result,
 * and that is the level it has to be documented at. `docs/data-model.md` ->
 * Built-in conditions carries the author-facing version: `?` matches one unit of
 * the subject's encoding, so write it only against ASCII and reach for `*`
 * otherwise.
 *
 * ### Why `?` is not aligned to bytes
 *
 * Encoding both strings to UTF-8 and walking the result would fit the byte
 * budget comfortably. It is not done, for three reasons. It would buy
 * "`?` matches one byte" in both engines, which is further from the "one
 * character" an author expects than the code unit they get here today, so the
 * language would become consistent by getting worse. Unifying upward instead —
 * one *code point* in both — is not available, because the PHP side cannot count
 * them without mbstring, which `Popkit\Url_Matcher::fold_case()` rejects for a
 * documented reason that applies just as hard here: an extension that may be
 * absent means two folding paths and therefore two match semantics. And it would
 * put an allocation and an encoder inside the one function whose bounded running
 * time is a security property this plugin claims in writing, to change answers
 * for no input either condition receives.
 *
 * Case folding is ASCII-only and spelled out rather than delegated to
 * `toLowerCase()`. See {@link foldCase}.
 *
 * ## What the subject is
 *
 * The referrer's **host and path** — `docs/CLAUDE.md` -> URL match language —
 * so `https://www.google.com/search?q=popups#top` is matched as
 * `www.google.com/search`. No scheme, no port normalization beyond what `URL`
 * does, no query string, no fragment. A path always begins with `/`, and a
 * referrer with no path reads as `example.org/`, so an author writing
 * `example.org` needs `prefix` rather than `exact`. The query string is
 * deliberately out of scope: campaign parameters are the `utm` condition's job.
 *
 * ## An empty referrer is a real answer, not a failure
 *
 * A direct visit, a bookmark, an `https` page linking to an `http` one, and any
 * site sending `Referrer-Policy: no-referrer` all produce `''`. That is common
 * enough that treating it as unresolvable would quietly suppress popups for a
 * large share of real traffic, so it is evaluated as the empty subject and the
 * modes answer for themselves:
 *
 * | Mode | Against an empty referrer |
 * |---|---|
 * | `exact` | Matches only an empty `value`. |
 * | `prefix` | Matches only an empty `value` — every string starts with `''`. |
 * | `contains` | Matches only an empty `value`, for the same reason. |
 * | `glob` | Matches `''` and any pattern of nothing but `*`. |
 *
 * So `contains: "facebook.com"` is `false` for a direct visit, and the same rule
 * with `negate: true` is `true` — "not from Facebook" includes people who came
 * from nowhere. That is a decision the module made, so `negate` legitimately
 * applies to it; contrast the unreadable-mode path below, which is not.
 *
 * @see docs/CLAUDE.md -> Security -> URL match language
 * @see includes/class-url-matcher.php
 */

/**
 * Longest match value the walk will accept.
 *
 * Matches `Popkit\Url_Matcher::MAX_VALUE_LENGTH`, which is where the cap is
 * declared and enforced at save time. Bounding it again here is what keeps the
 * glob walk's worst case a product of two known lengths.
 *
 * @type {number}
 */
const MAX_VALUE_LENGTH = 255;

/**
 * Folds `A-Z` to `a-z` and leaves every other character exactly as it was.
 *
 * `toLowerCase()` is deliberately not used. It applies the full Unicode default
 * case conversion, so `É` folds to `é` and `İ` folds to two code units — where
 * `Popkit\Url_Matcher::fold_case()` folds twenty-six bytes and nothing else. The
 * documented match language says case-insensitive; it does not say
 * case-insensitive in one condition and Unicode-case-insensitive in the other,
 * and the direction the difference runs in is the wrong one: `toLowerCase()`
 * makes a rule match strictly more referrers than the same rule matches paths.
 *
 * A widening that depends on which half of the plugin is evaluating is precisely
 * the class of surprise the PHP file spends thirty lines ruling out on its own
 * side. Twenty-six characters is a small enough alphabet to fold by hand.
 *
 * The loop is used in preference to a `/[A-Z]/g` replacement so that this
 * repository contains no pattern matching on author-supplied input in either
 * language, which is the property `docs/CLAUDE.md` -> Security asks a reviewer
 * to be able to confirm by looking.
 *
 * @param {string} value Text to fold.
 * @return {string} Text with ASCII uppercase letters lowercased.
 */
function foldCase( value ) {
	let folded = '';

	for ( let index = 0; index < value.length; index++ ) {
		const code = value.charCodeAt( index );

		folded +=
			65 <= code && 90 >= code
				? String.fromCharCode( code + 32 )
				: value[ index ];
	}

	return folded;
}

/**
 * Matches a glob pattern against a subject with an iterative two-pointer walk.
 *
 * `*` matches any run of characters including none; `?` matches exactly one
 * UTF-16 code unit, which is one character for every ASCII subject and for every
 * subject on the basic multilingual plane, and half of an astral one. The unit
 * is where this walk and `Popkit\Url_Matcher::glob_matches()` part company — see
 * the file header. Nothing else carries meaning — `[`, `]`, `{`, `}`, `(`, `)`,
 * `.`, `+` and `\` are literals — and nothing is compiled or translated to a
 * regular expression.
 *
 * Four integers and no recursion: the two positions, the position of the most
 * recent `*`, and the subject offset that `*` was last resumed from. On a
 * mismatch the walk does not explore alternatives; it rewinds to the one
 * remembered `*`, gives it exactly one more character, and continues. Every
 * earlier `*` is permanently committed, so there is no backtracking tree and
 * `a*a*a*a*a*b` against a long run of `a` completes in bounded time rather than
 * hanging the way a naive glob-to-regex translation does.
 *
 * Reading past the end of the pattern yields `undefined`, which equals neither
 * metacharacter nor any character of the subject, so the exhausted-pattern case
 * falls through to the rewind branch with no test of its own.
 *
 * @param {string} pattern Case-folded glob pattern.
 * @param {string} subject Case-folded subject.
 * @return {boolean} True when the pattern matches the whole subject.
 */
function globMatches( pattern, subject ) {
	let patternIndex = 0;
	let subjectIndex = 0;

	// -1 means no '*' has been seen, so there is nothing to rewind to.
	let starIndex = -1;
	let resumeIndex = 0;

	while ( subjectIndex < subject.length ) {
		const character = pattern[ patternIndex ];

		if ( '*' === character ) {
			starIndex = patternIndex;
			resumeIndex = subjectIndex;
			patternIndex++;
		} else if (
			'?' === character ||
			character === subject[ subjectIndex ]
		) {
			patternIndex++;
			subjectIndex++;
		} else if ( -1 !== starIndex ) {
			patternIndex = starIndex + 1;
			resumeIndex++;
			subjectIndex = resumeIndex;
		} else {
			return false;
		}
	}

	// The subject is exhausted; any trailing stars may still match nothing.
	while ( '*' === pattern[ patternIndex ] ) {
		patternIndex++;
	}

	return patternIndex === pattern.length;
}

/**
 * Tests a subject against a literal match value under one of the four modes.
 *
 * The single implementation of the match language in this bundle. Case is
 * folded once, before any walk begins: folding inside the glob loop would turn
 * a bounded comparison budget into the same number of folding calls.
 *
 * An over-length value is a non-match rather than a throw, matching
 * `Popkit\Url_Matcher::matches()` — a targeting miss during evaluation must
 * never become an exception. An unrecognized mode returns `undefined`, because
 * an unreadable rule is not a rule that failed; the caller turns that into a
 * fail-closed denial.
 *
 * @param {*}      mode    One of `exact`, `prefix`, `contains`, `glob`.
 * @param {string} value   Literal match value, at most MAX_VALUE_LENGTH long.
 * @param {string} subject Subject to test.
 * @return {boolean|undefined} Whether the subject matches, or undefined when the
 *                             mode is not one of the four.
 */
export function urlMatches( mode, value, subject ) {
	if ( MAX_VALUE_LENGTH < value.length ) {
		return false;
	}

	const pattern = foldCase( value );
	const haystack = foldCase( subject );

	if ( 'exact' === mode ) {
		return haystack === pattern;
	}

	if ( 'prefix' === mode ) {
		return haystack.startsWith( pattern );
	}

	if ( 'contains' === mode ) {
		return haystack.includes( pattern );
	}

	if ( 'glob' === mode ) {
		return globMatches( pattern, haystack );
	}

	return undefined;
}

/**
 * Reduces `document.referrer` to the host and path that rules are matched on.
 *
 * The order is the security-relevant part and it is the same order
 * `Popkit\Url_Matcher::normalize_path()` uses: every structural decision — where
 * the host ends, where the query begins, where the fragment begins — is taken
 * against the raw, still-encoded string by the `URL` parser, and percent-decoding
 * happens last and exactly once. Decoding first would let `%23` and `%3F`
 * masquerade as delimiters; decoding twice is the classic filter bypass. A
 * decoded `/`, `?` or `#` in the result is an ordinary literal character.
 *
 * `host` rather than `hostname`, so a referrer from a non-default port is
 * matched as the origin it actually is instead of silently equaling the
 * default-port site of the same name.
 *
 * A referrer the parser rejects, and a malformed percent escape in the path,
 * both read as no referrer. Both are somebody else's malformed URL; neither is
 * worth throwing over, and reading them as `''` is the narrow direction — see
 * the empty-referrer table in the file header for what the modes then answer.
 *
 * @return {string} Host and path, decoded once, or `''` when there is no usable
 *                  referrer.
 */
function referrerSubject() {
	try {
		const url = new window.URL( window.document.referrer );

		return decodeURIComponent( url.host + url.pathname );
	} catch {
		return '';
	}
}

export default {
	key: 'referrer',
	needsContext: false,

	/**
	 * Matches the referrer's host and path against the stored value.
	 *
	 * A `value` that is not a string is unreadable rather than empty, so it
	 * returns `undefined` and stage 7 fails the rule closed without applying
	 * `negate`. An empty string is a different case entirely and is matched as
	 * written — the modes disagree about what it means and each answer is
	 * deliberate.
	 *
	 * @param {Object} [values]       Stored rule values.
	 * @param {string} [values.match] One of `exact`, `prefix`, `contains`, `glob`.
	 * @param {string} [values.value] Literal to match against.
	 * @return {boolean|undefined} Whether the referrer matches, or undefined when
	 *                             the rule cannot be read.
	 */
	evaluate( values ) {
		const value = values?.value;

		return 'string' === typeof value
			? urlMatches( values.match, value, referrerSubject() )
			: undefined;
	},
};
