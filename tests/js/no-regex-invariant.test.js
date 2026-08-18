/**
 * Enforcement of the no-regex invariant across the frontend bundle's source.
 *
 * No regular expression matches author-supplied input anywhere in this plugin,
 * in either language. `docs/CLAUDE.md` -> Security states the rule without
 * naming one — "No user-supplied regular expressions anywhere" — and the reason
 * holds on both sides of the wire: neither PCRE nor a JavaScript engine offers a
 * dependable per-match wall-clock bound, so a plugin that compiles an author's
 * pattern cannot honestly claim that matching a URL terminates. URL targeting is
 * therefore a closed four-mode match language — `exact`, `prefix`, `contains`,
 * `glob` — run by a linear two-pointer walk: `Popkit\Url_Matcher` in
 * `includes/class-url-matcher.php` on the server, `urlMatches()` in
 * `src/frontend/conditions/referrer.js` in the browser.
 *
 * Only the server half was guarded. `tests/php/unit/test-no-regex-invariant.php`
 * scans includes/ with PHP's own tokenizer, and nothing at all scanned the
 * bundle. That gap already has a cost: `referrer.js` folds case by walking
 * twenty-six ASCII characters in a loop, in explicit preference to a one-line
 * replacement, and the only thing holding that decision in place was the
 * paragraph explaining it. Prose is not an invariant. This file is the other
 * half of the guard.
 *
 * ## What counts as usage
 *
 * Two constructs, and between them they are every way a regular expression can
 * come into existence: a literal, and the `RegExp` identifier. Anything reaching
 * `.test()`, `.match()`, `.replace()`, `.split()`, `.search()` or their kin was
 * built by one of the two, so guarding construction closes the door that
 * guarding call sites leaves ajar — `const RE = /x/;` on one line and
 * `RE.test( value )` two hundred lines later is the shape a usage-adjacency rule
 * misses, and it is not an exotic shape. The method name is still reported when
 * one sits beside the literal, on either side, because `value.replace( … )` is
 * what somebody will actually have written and a message naming it is a message
 * they can act on.
 *
 * A pattern applied to a value the plugin itself minted is not obviously
 * dangerous, and no exception is made for it anyway. The bundle has no such use
 * today (see **Scope**), the distinction is not machine-checkable, and "this one
 * only ever sees our own strings" is the sentence that precedes every one of
 * these regressions.
 *
 * ## Why a scanner and not a text search
 *
 * The obvious implementation is `source.includes( 'RegExp' )`, and it is the
 * wrong one, because `referrer.js` says "The loop is used in preference to a
 * `/[A-Z]/g` replacement" — the sentence telling the next maintainer why the
 * loop is there. A guard that forbids *explaining* the invariant is a guard
 * whose first maintenance action is deleting the explanation, and the
 * explanation is worth more than the guard. So each file is first run through a
 * scanner that blanks every comment and every string and template *text*, and
 * only what is left — actual code — is examined. Prose about regular
 * expressions is free; compiling one is not.
 *
 * ## The irony, addressed on purpose
 *
 * A scanner for regular expressions is the one place in this repository where a
 * regular expression would be defensible: it runs at test time, its input is the
 * plugin's own source, and no author or visitor can supply a byte of it. The ban
 * is on patterns applied to *author input*, and there is none here.
 *
 * It nonetheless uses none, and the reason is precision rather than principle. A
 * pattern cannot tell a `/` that opens a literal from a `/` that divides, and
 * `clock.js` and `scroll-depth.js` both divide; it cannot tell code from the
 * docblock above it, which is the one distinction this file exists to make. The
 * character scan below has to strip comments and strings regardless, and once it
 * exists a pattern buys nothing. So: if a later edit does reach for one *here*,
 * that is allowed — say why in a comment, and note that this file sits outside
 * the tree it scans. Do not "fix" the absence of one, and do not cite this file
 * as precedent for src/frontend.
 *
 * ## Why the scan is checked against fail-open
 *
 * A stripper that silently over-consumed — an unterminated literal, a `/`
 * misread as opening a pattern — would blank the rest of the file and report
 * zero offences, which is indistinguishable from clean source. Four defences:
 * template substitutions are scanned as code rather than blanked with the text
 * around them, a candidate literal that does not close on its own line is
 * treated as division instead, every scanned module is asserted to still contain
 * recognisable code after the strip, and the last four tests drive the scanner
 * with source that does compile a pattern and source that only talks about one,
 * and assert it separates the two.
 *
 * ## Scope, and the allowlist that is not here
 *
 * Every `.js` file under src/frontend, found by walking the tree rather than
 * from a list, so a new condition or trigger module is covered the moment it is
 * added. src/editor is out of scope: it is admin-side, it ships in a separate
 * bundle, and `@wordpress/components` brings its own patterns.
 *
 * The tree was surveyed before this file was written and contains no regular
 * expression at all — not one literal, not one `RegExp` — so there is no
 * allowlist, because an empty one is machinery pretending to have a job. If a
 * genuinely safe use ever arrives, add the mechanism then, and make it carry a
 * written justification naming the subject and saying why it cannot be author
 * input. The two divisions in the tree, `clock.js` line 76 and
 * `scroll-depth.js` line 167, are arithmetic and are not offences; a test below
 * pins that.
 *
 * @see docs/CLAUDE.md -> Security -> URL match language
 * @see tests/php/unit/test-no-regex-invariant.php
 * @see src/frontend/conditions/referrer.js
 */

/* global describe, it, expect */

import { readdirSync, readFileSync } from 'fs';
import { join } from 'path';

/**
 * Plugin root, resolved from this file rather than from the working directory.
 *
 * @type {string}
 */
const PLUGIN_ROOT = join( __dirname, '..', '..' );

/**
 * Directory whose JavaScript is scanned, as a repo-relative forward-slashed path.
 *
 * @type {string}
 */
const SCANNED_ROOT = 'src/frontend';

/**
 * Methods that take or receive a regular expression, named in failure messages.
 *
 * Membership here changes nothing about whether an offence is reported — every
 * literal is one — only how the offence reads. `matchAll` and `replaceAll` join
 * the six the constitution names because they are the same call with a longer
 * spelling.
 *
 * @type {string[]}
 */
const REGEX_METHODS = [
	'test',
	'exec',
	'match',
	'matchAll',
	'replace',
	'replaceAll',
	'split',
	'search',
];

/**
 * A fragment of real code expected to survive the strip, per named module.
 *
 * Every scanned module is checked for a generic landmark; these two are checked
 * for a specific one, because they are the modules whose prose is the reason the
 * stripper exists at all.
 *
 * @type {Object}
 */
const CODE_LANDMARKS = {
	'src/frontend/conditions/referrer.js': 'export function urlMatches',
	'src/frontend/schedule.js': 'export function scheduleAllows',
};

/**
 * Keywords after which a `/` opens a regular expression rather than dividing.
 *
 * Everything else that can precede a `/` and still leave it as a division — an
 * identifier, a number, `)` or `]` — is handled positionally.
 *
 * @type {string[]}
 */
const REGEX_PRECEDING_KEYWORDS = [
	'await',
	'case',
	'delete',
	'do',
	'else',
	'in',
	'instanceof',
	'new',
	'of',
	'return',
	'typeof',
	'void',
	'yield',
];

/**
 * Reports whether a character may appear inside a JavaScript identifier.
 *
 * @param {string|undefined} char Single character, or undefined past the end.
 * @return {boolean} True for a letter, a digit, `_` or `$`.
 */
function isIdentifierChar( char ) {
	if ( 'string' !== typeof char || 1 !== char.length ) {
		return false;
	}

	return (
		( 'a' <= char && 'z' >= char ) ||
		( 'A' <= char && 'Z' >= char ) ||
		( '0' <= char && '9' >= char ) ||
		'_' === char ||
		'$' === char
	);
}

/**
 * Reports whether a character is JavaScript whitespace for the scanner's purposes.
 *
 * @param {string|undefined} char Single character, or undefined past the end.
 * @return {boolean} True for a space, tab, carriage return or newline.
 */
function isSpace( char ) {
	return ' ' === char || '\t' === char || '\r' === char || '\n' === char;
}

/**
 * Overwrites a range with spaces, preserving newlines.
 *
 * Newlines survive so that offence line numbers refer to the original file, and
 * the blanked source stays the same length as its input so every index computed
 * from one is valid in the other.
 *
 * @param {string[]} out  Mutable character array being blanked in place.
 * @param {number}   from First index to blank, inclusive.
 * @param {number}   to   Index to stop at, exclusive.
 */
function blankRange( out, from, to ) {
	for (
		let index = Math.max( 0, from );
		index < to && index < out.length;
		index++
	) {
		if ( '\n' !== out[ index ] ) {
			out[ index ] = ' ';
		}
	}
}

/**
 * Blanks a `//` comment and returns the index of its terminating newline.
 *
 * @param {string}   source Original source.
 * @param {string[]} out    Mutable character array being blanked in place.
 * @param {number}   start  Index of the first `/`.
 * @return {number} Index to resume scanning from.
 */
function skipLineComment( source, out, start ) {
	let index = start + 2;

	while ( index < source.length && '\n' !== source[ index ] ) {
		index++;
	}

	blankRange( out, start, index );

	return index;
}

/**
 * Blanks a block comment and returns the index just past its terminator.
 *
 * An unterminated block comment runs to the end of the file, which is what the
 * engine would do with it too.
 *
 * @param {string}   source Original source.
 * @param {string[]} out    Mutable character array being blanked in place.
 * @param {number}   start  Index of the opening `/`.
 * @return {number} Index to resume scanning from.
 */
function skipBlockComment( source, out, start ) {
	const closed = source.indexOf( '*/', start + 2 );
	const end = -1 === closed ? source.length : closed + 2;

	blankRange( out, start, end );

	return end;
}

/**
 * Blanks a single- or double-quoted string and returns the index just past it.
 *
 * A quote that never closes before the end of its line is not a string literal
 * in any source the engine would accept, so the scan stops there rather than
 * swallowing the rest of the file.
 *
 * @param {string}   source Original source.
 * @param {string[]} out    Mutable character array being blanked in place.
 * @param {number}   start  Index of the opening quote.
 * @return {number} Index to resume scanning from.
 */
function skipQuoted( source, out, start ) {
	const quote = source[ start ];
	let index = start + 1;

	while ( index < source.length ) {
		if ( '\\' === source[ index ] ) {
			index += 2;
			continue;
		}

		if ( quote === source[ index ] ) {
			index++;
			break;
		}

		if ( '\n' === source[ index ] ) {
			break;
		}

		index++;
	}

	blankRange( out, start, index );

	return index;
}

/**
 * Decides whether a `/` opens a regular expression literal or divides.
 *
 * Looks back through the already-blanked output, so a comment or a string before
 * the slash cannot influence the decision. `)` and `]` end an expression and
 * therefore divide; a bare identifier or number divides; a keyword such as
 * `return` cannot be divided and so opens a literal.
 *
 * @param {string[]} out     Character array blanked so far.
 * @param {number}   slashAt Index of the `/`.
 * @return {boolean} True when the slash opens a regular expression literal.
 */
function startsRegexLiteral( out, slashAt ) {
	let back = slashAt - 1;

	while ( 0 <= back && isSpace( out[ back ] ) ) {
		back--;
	}

	if ( 0 > back ) {
		return true;
	}

	const previous = out[ back ];

	if ( ')' === previous || ']' === previous ) {
		return false;
	}

	if ( ! isIdentifierChar( previous ) ) {
		return true;
	}

	let wordStart = back;

	while ( 0 <= wordStart && isIdentifierChar( out[ wordStart ] ) ) {
		wordStart--;
	}

	return REGEX_PRECEDING_KEYWORDS.includes(
		out.slice( wordStart + 1, back + 1 ).join( '' )
	);
}

/**
 * Records a regular expression literal, blanks it, and returns the index past it.
 *
 * A literal cannot span a line, so a candidate reaching a newline unclosed was a
 * division after all; nothing is recorded and nothing is blanked. That is the
 * safe direction for the stripper — over-consuming would blank real code and
 * hide an offence — and it costs this guard nothing, because no pattern the
 * engine accepts spans a line either.
 *
 * The literal is blanked along with everything else, so that a later `/` in the
 * same file is judged against code rather than against pattern syntax.
 *
 * @param {string}   source   Original source.
 * @param {string[]} out      Mutable character array being blanked in place.
 * @param {Object[]} literals Mutable list of `{ start, end }` records to append to.
 * @param {number}   start    Index of the opening `/`.
 * @return {number} Index to resume scanning from.
 */
function skipRegexLiteral( source, out, literals, start ) {
	let index = start + 1;
	let inClass = false;

	while ( index < source.length ) {
		const char = source[ index ];

		if ( '\n' === char ) {
			return start + 1;
		}

		if ( '\\' === char ) {
			index += 2;
			continue;
		}

		if ( '[' === char ) {
			inClass = true;
		} else if ( ']' === char ) {
			inClass = false;
		} else if ( '/' === char && ! inClass ) {
			let end = index + 1;

			while ( end < source.length && isIdentifierChar( source[ end ] ) ) {
				end++;
			}

			literals.push( { start, end } );
			blankRange( out, start, end );

			return end;
		}

		index++;
	}

	return start + 1;
}

/**
 * Blanks the text of a template literal, scanning its substitutions as code.
 *
 * Blanking a template whole would let a pattern inside `${ … }` through, so only
 * the literal text between substitutions is removed.
 *
 * @param {string}   source   Original source.
 * @param {string[]} out      Mutable character array being blanked in place.
 * @param {Object[]} literals Mutable list of `{ start, end }` records to append to.
 * @param {number}   start    Index of the opening backtick.
 * @return {number} Index to resume scanning from.
 */
function scanTemplate( source, out, literals, start ) {
	let index = start + 1;
	let textFrom = index;

	while ( index < source.length ) {
		if ( '\\' === source[ index ] ) {
			index += 2;
			continue;
		}

		if ( '`' === source[ index ] ) {
			blankRange( out, textFrom, index );

			return index + 1;
		}

		if ( '$' === source[ index ] && '{' === source[ index + 1 ] ) {
			blankRange( out, textFrom, index );
			index = scanCode( source, out, literals, index + 2, true );
			textFrom = index;
			continue;
		}

		index++;
	}

	blankRange( out, textFrom, source.length );

	return source.length;
}

/**
 * Walks code, delegating every comment and literal to the blanking helpers.
 *
 * @param {string}   source            Original source.
 * @param {string[]} out               Mutable character array being blanked in place.
 * @param {Object[]} literals          Mutable list of `{ start, end }` records to append to.
 * @param {number}   start             Index to start from.
 * @param {boolean}  untilClosingBrace True when scanning a `${ … }` substitution,
 *                                     which ends at its unmatched `}`.
 * @return {number} Index just past the scanned region.
 */
function scanCode( source, out, literals, start, untilClosingBrace ) {
	let index = start;
	let depth = 0;

	while ( index < source.length ) {
		const char = source[ index ];
		const next = source[ index + 1 ];

		if ( untilClosingBrace && '{' === char ) {
			depth++;
			index++;
			continue;
		}

		if ( untilClosingBrace && '}' === char ) {
			if ( 0 === depth ) {
				return index + 1;
			}

			depth--;
			index++;
			continue;
		}

		if ( '/' === char && '/' === next ) {
			index = skipLineComment( source, out, index );
			continue;
		}

		if ( '/' === char && '*' === next ) {
			index = skipBlockComment( source, out, index );
			continue;
		}

		if ( "'" === char || '"' === char ) {
			index = skipQuoted( source, out, index );
			continue;
		}

		if ( '`' === char ) {
			index = scanTemplate( source, out, literals, index );
			continue;
		}

		if ( '/' === char && startsRegexLiteral( out, index ) ) {
			index = skipRegexLiteral( source, out, literals, index );
			continue;
		}

		index++;
	}

	return source.length;
}

/**
 * Strips a source to its code and reports where its pattern literals were.
 *
 * @param {string} source JavaScript source.
 * @return {Object} `code`, a same-length source in which only code characters
 *                  remain, and `literals`, the `{ start, end }` range of every
 *                  regular expression literal found in code position.
 */
function scanSource( source ) {
	const out = source.split( '' );
	const literals = [];

	scanCode( source, out, literals, 0, false );

	return { code: out.join( '' ), literals };
}

/**
 * Returns the source with every comment and literal replaced by spaces.
 *
 * @param {string} source JavaScript source.
 * @return {string} Same-length source in which only code characters remain.
 */
function stripNonCode( source ) {
	return scanSource( source ).code;
}

/**
 * Returns the index of the next character that is not whitespace.
 *
 * @param {string} code  Blanked source.
 * @param {number} start Index to start from.
 * @return {number} Index of the next significant character.
 */
function skipSpaces( code, start ) {
	let index = start;

	while ( index < code.length && isSpace( code[ index ] ) ) {
		index++;
	}

	return index;
}

/**
 * Returns the index of the previous character that is not whitespace.
 *
 * @param {string} code  Blanked source.
 * @param {number} start Index to start from; not itself considered.
 * @return {number} Index of the previous significant character, or -1.
 */
function previousSignificant( code, start ) {
	let index = start - 1;

	while ( 0 <= index && isSpace( code[ index ] ) ) {
		index--;
	}

	return index;
}

/**
 * Reports whether an exact identifier sits at an index, on identifier boundaries.
 *
 * The boundary check keeps `myRegExpCache` and `RegExpish` out of the offence
 * list while `window.RegExp` stays in it: `.` is not an identifier character, so
 * a qualified read is still a boundary-clean occurrence.
 *
 * @param {string} code  Blanked source.
 * @param {number} index Index to test.
 * @param {string} word  Identifier to look for.
 * @return {boolean} True when exactly that identifier starts at that index.
 */
function isWordAt( code, index, word ) {
	if ( ! code.startsWith( word, index ) ) {
		return false;
	}

	return (
		! isIdentifierChar( code[ index - 1 ] ) &&
		! isIdentifierChar( code[ index + word.length ] )
	);
}

/**
 * Reports whether the identifier immediately before an index is `new`.
 *
 * @param {string} code  Blanked source.
 * @param {number} index Index of the identifier being classified.
 * @return {boolean} True when the construct is `new RegExp…`.
 */
function isPrecededByNew( code, index ) {
	const back = previousSignificant( code, index );
	let wordStart = back;

	while ( 0 <= wordStart && isIdentifierChar( code[ wordStart ] ) ) {
		wordStart--;
	}

	return 'new' === code.slice( wordStart + 1, back + 1 );
}

/**
 * Returns the identifier ending at an index, or an empty string.
 *
 * @param {string} code Blanked source.
 * @param {number} end  Index of the identifier's last character.
 * @return {string} The identifier, or `''` when no identifier ends there.
 */
function identifierEndingAt( code, end ) {
	if ( ! isIdentifierChar( code[ end ] ) ) {
		return '';
	}

	let start = end;

	while ( 0 <= start && isIdentifierChar( code[ start ] ) ) {
		start--;
	}

	return code.slice( start + 1, end + 1 );
}

/**
 * Names the regex-consuming method a literal sits beside, if there is one.
 *
 * Both arrangements are looked for, because both get written: the literal as
 * receiver, and the literal as the first argument of a method on a string. A
 * literal with neither neighbour — one assigned to a constant, say — is still an
 * offence; it simply has no method to name yet.
 *
 * @param {string} code    Blanked source.
 * @param {Object} literal `{ start, end }` range of the literal.
 * @return {string|null} Method name, or null when the literal stands alone.
 */
function methodBesideLiteral( code, literal ) {
	const dotAfter = skipSpaces( code, literal.end );

	if ( '.' === code[ dotAfter ] ) {
		const nameStart = skipSpaces( code, dotAfter + 1 );
		let nameEnd = nameStart;

		while ( nameEnd < code.length && isIdentifierChar( code[ nameEnd ] ) ) {
			nameEnd++;
		}

		const receiverMethod = code.slice( nameStart, nameEnd );

		if ( REGEX_METHODS.includes( receiverMethod ) ) {
			return receiverMethod;
		}
	}

	const openParen = previousSignificant( code, literal.start );

	if ( '(' !== code[ openParen ] ) {
		return null;
	}

	const nameEnd = previousSignificant( code, openParen );
	const argumentMethod = identifierEndingAt( code, nameEnd );

	if ( ! REGEX_METHODS.includes( argumentMethod ) ) {
		return null;
	}

	const beforeName = previousSignificant(
		code,
		nameEnd - argumentMethod.length + 1
	);

	return '.' === code[ beforeName ] ? argumentMethod : null;
}

/**
 * Returns the 1-based line number of an index.
 *
 * @param {string} code  Blanked source, whose newlines match the original's.
 * @param {number} index Index to locate.
 * @return {number} Line number, counting from 1.
 */
function lineAt( code, index ) {
	return code.slice( 0, index ).split( '\n' ).length;
}

/**
 * Returns the regular expressions built in one JavaScript source string.
 *
 * Two shapes, and they are exhaustive: a literal in code position, and the
 * `RegExp` identifier, which covers `new RegExp( value )`, the constructor
 * called without `new`, and a qualified `window.RegExp`. Offences are sorted by
 * line so a failure reads down the file.
 *
 * @param {string} source JavaScript source.
 * @param {string} label  Path to name in the message, relative to the plugin root.
 * @return {string[]} One human-readable sentence per offence; empty when there are none.
 */
function offencesInSource( source, label ) {
	const { code, literals } = scanSource( source );
	const offences = [];

	for ( const literal of literals ) {
		const method = methodBesideLiteral( code, literal );
		const line = lineAt( code, literal.start );
		const usage =
			null === method
				? 'builds a regular expression literal'
				: 'matches with a regular expression literal via .' +
				  method +
				  '()';

		offences.push( {
			line,
			message: '  ' + label + ':' + line + ' ' + usage,
		} );
	}

	for ( let index = 0; index < code.length; index++ ) {
		if ( ! isWordAt( code, index, 'RegExp' ) ) {
			continue;
		}

		const line = lineAt( code, index );
		const spelling = isPrecededByNew( code, index )
			? 'new RegExp()'
			: 'RegExp()';

		offences.push( {
			line,
			message:
				'  ' +
				label +
				':' +
				line +
				' compiles a regular expression via ' +
				spelling,
		} );
	}

	return offences
		.sort( ( first, second ) => first.line - second.line )
		.map( ( offence ) => offence.message );
}

/**
 * Returns every `.js` file under a directory, as forward-slashed relative paths.
 *
 * Walking the tree rather than reading a list is deliberate: a module added to
 * src/frontend is covered without anyone remembering to enrol it, which is the
 * failure mode a fixed list has.
 *
 * @param {string} relativeDir Directory relative to the plugin root.
 * @return {string[]} Sorted paths relative to the plugin root.
 */
function jsFilesUnder( relativeDir ) {
	const entries = readdirSync( join( PLUGIN_ROOT, relativeDir ), {
		withFileTypes: true,
	} );
	const files = [];

	for ( const entry of entries ) {
		const child = relativeDir + '/' + entry.name;

		if ( entry.isDirectory() ) {
			files.push( ...jsFilesUnder( child ) );
			continue;
		}

		if ( entry.isFile() && entry.name.endsWith( '.js' ) ) {
			files.push( child );
		}
	}

	return files.sort();
}

/**
 * Reads one scanned module from disk.
 *
 * @param {string} relativePath Path relative to the plugin root.
 * @return {string} File contents.
 */
function readModule( relativePath ) {
	return readFileSync( join( PLUGIN_ROOT, relativePath ), 'utf8' );
}

describe( 'no-regex invariant', () => {
	it( 'covers the whole frontend bundle', () => {
		const files = jsFilesUnder( SCANNED_ROOT );

		// A moved tree, a mistyped directory or a filter matching nothing all
		// produce a passing scan over zero files.
		expect( files.length ).toBeGreaterThan( 0 );

		// The module the invariant most exists to protect, and the module every
		// rule is evaluated through.
		expect( files ).toContain( 'src/frontend/conditions/referrer.js' );
		expect( files ).toContain( 'src/frontend/controller.js' );
	} );

	it( 'leaves real code behind in every scanned module', () => {
		// The fail-open this file most needs to rule out: a stripper that
		// over-consumed reports no offences and looks exactly like clean source.
		for ( const relativePath of jsFilesUnder( SCANNED_ROOT ) ) {
			const code = stripNonCode( readModule( relativePath ) );

			// Every module in an ES module tree imports or exports something, so
			// this holds for any file the tree can legitimately contain.
			expect( {
				relativePath,
				survived:
					code.includes( 'export' ) || code.includes( 'import' ),
			} ).toEqual( { relativePath, survived: true } );
		}

		for ( const relativePath of Object.keys( CODE_LANDMARKS ) ) {
			expect( stripNonCode( readModule( relativePath ) ) ).toContain(
				CODE_LANDMARKS[ relativePath ]
			);
		}
	} );

	it( 'finds no regular expression anywhere in the frontend bundle', () => {
		const offences = jsFilesUnder( SCANNED_ROOT ).flatMap(
			( relativePath ) =>
				offencesInSource( readModule( relativePath ), relativePath )
		);

		// This is the invariant. Everything else in the file exists to make this
		// assertion trustworthy.
		expect( offences ).toEqual( [] );
	} );

	it( 'does not trip on the paragraph in referrer.js that explains the ban', () => {
		const relativePath = 'src/frontend/conditions/referrer.js';
		const source = readModule( relativePath );

		// Both halves matter. The first pins the explanation in place, so a guard
		// that started firing on prose could not be "fixed" by deleting the
		// paragraph; the second is the guard staying quiet about it.
		expect( source ).toContain( '`/[A-Z]/g` replacement' );
		expect( offencesInSource( source, relativePath ) ).toEqual( [] );
	} );

	it( 'detects a regular expression and names the file and the line', () => {
		const snippet =
			'const subject = referrerSubject();\n' +
			'const hit = /popkit/.test( subject );\n';

		const offences = offencesInSource(
			snippet,
			'src/frontend/conditions/referrer.js'
		);

		expect( offences ).toHaveLength( 1 );
		expect( offences[ 0 ] ).toContain(
			'src/frontend/conditions/referrer.js'
		);
		expect( offences[ 0 ] ).toContain( ':2' );
		expect( offences[ 0 ] ).toContain( '.test()' );
	} );

	it( 'detects every shape of regular expression usage', () => {
		const spellings = [
			'const hit = /popkit/.test( value );',
			'const found = /popkit/.exec( value );',
			'const parts = value.split( /,/ );',
			'const cleaned = value.replace( /[A-Z]/g, "" );',
			'const all = value.replaceAll( /[A-Z]/g, "" );',
			'const one = value.match( /popkit/ );',
			'const every = value.matchAll( /popkit/g );',
			'const at = value.search( /popkit/ );',
			'const compiled = new RegExp( pattern );',
			'const called = RegExp( pattern );',
			'const qualified = new window.RegExp( pattern );',
			// The shape a usage-adjacency rule misses: construction and use need
			// not share a line, or a function.
			'const PATTERN = /popkit/;',
			'return /popkit/u.test( value );',
			'const inside = `${ value.match( /popkit/ ) }`;',
		];

		for ( const spelling of spellings ) {
			expect( {
				spelling,
				offences: offencesInSource( spelling, 'fixture.js' ).length,
			} ).toEqual( { spelling, offences: 1 } );
		}
	} );

	it( 'names the method when the literal is an argument', () => {
		const offences = offencesInSource(
			'const cleaned = value.replace( /[A-Z]/g, "" );',
			'fixture.js'
		);

		expect( offences ).toHaveLength( 1 );
		expect( offences[ 0 ] ).toContain( '.replace()' );
	} );

	it( 'ignores prose, literals, division and names that merely contain RegExp', () => {
		const snippet = [
			'/**',
			' * The loop is used in preference to a `/[A-Z]/g` replacement, because',
			' * a RegExp compiled from an author value has no bounded running time.',
			' * Never write value.replace( /[A-Z]/g, "" ) here, and never reach for',
			' * new RegExp( value ) — the glob walk exists so that nobody has to.',
			' */',
			'// Rejected in review: /popkit/.test( referrer ) as a shortcut.',
			"const documented = '/[A-Z]/g';",
			'const explained = `call value.match( /x/ ) here and the build fails`;',
			"const parts = value.split( ':' );",
			"const cleaned = value.replace( needle, '' );",
			'const half = ( sentAtMs + receivedAtMs ) / 2;',
			'const ratio = ( root.scrollTop / distance ) * FULL_PERCENT;',
			'const cached = myRegExpCache.get( key );',
			'const folded = foldCase( value );',
		].join( '\n' );

		expect( offencesInSource( snippet, 'fixture.js' ) ).toEqual( [] );
	} );

	it( 'does not let a division hide the code that follows it', () => {
		// A `/` misread as opening a pattern would blank everything to the next
		// `/` — here, the whole rest of the file — and report a clean module. A
		// literal cannot span a line, so the scanner backs out.
		const snippet =
			'const half = total / 2;\n' +
			'const hit = /popkit/.test( value );\n';

		expect( offencesInSource( snippet, 'fixture.js' ) ).toHaveLength( 1 );
	} );
} );
