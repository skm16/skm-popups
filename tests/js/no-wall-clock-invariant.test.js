/**
 * Enforcement of the no-wall-clock invariant across the schedule evaluation path.
 *
 * Schedule evaluation never reads the visitor's device clock. Authoritative time
 * arrives from `GET /wp-json/popkit/v1/context?fields=time` and is turned into a
 * reusable "now" by an offset applied to the monotonic clock; a device running
 * eight hours fast, or corrected by NTP mid-session, must not change which
 * campaigns a visitor sees. See docs/data-model.md -> Schedule -> Where `now`
 * comes from.
 *
 * That is a property of two whole modules, not of one expression. A single
 * `Date.now()` added anywhere in clock.js or schedule.js — in a "temporary"
 * fallback for an uncalibrated clock, in a debug line that outlived its debug
 * session, in a helper that only needed a rough timestamp — reintroduces exactly
 * the skew the offset exists to remove, while every existing test stays green,
 * because the fixtures supply the moment explicitly. docs/build-plan.md Phase 2
 * names the missing check outright: "a repository-wide grep asserts `Date.now()`
 * does not appear in the schedule evaluation module". This file is that check.
 *
 * It is modelled on tests/php/unit/test-no-regex-invariant.php, which enforces
 * the no-PCRE invariant over includes/ with PHP's own tokenizer, and it makes the
 * same two design choices for the same two reasons.
 *
 * **Why a scanner and not a substring search.** The obvious implementation is
 * `source.includes( 'Date.now' )`, and it is the wrong one, because clock.js
 * opens with "`Date.now()` appears nowhere in this file and must never be added
 * to it" — the sentence that tells the next maintainer why the rule exists. A
 * guard that forbids *explaining* the invariant is a guard whose first
 * maintenance action is deleting the explanation. So the source is first run
 * through a scanner that blanks every comment, string, template text and regular
 * expression literal, and only what is left — actual code — is examined. Prose
 * about the wall clock is free; reading it is not.
 *
 * **Why the scan is checked against fail-open.** A stripper that silently
 * over-consumed — an unterminated literal, a `/` misread as the start of a
 * regular expression — would blank the rest of the file and report zero offences,
 * which is indistinguishable from a clean module. Three defences: template
 * substitutions are scanned as code rather than blanked with the text around
 * them, a candidate regular expression that does not close on its own line is
 * treated as division instead, and the tests below assert that recognisable code
 * survives the strip in every guarded module.
 *
 * **Scope.** The two modules on the schedule path. `src/frontend/frequency.js`
 * is deliberately excluded and deliberately does call `Date.now()`: frequency
 * capping compares timestamps across page loads, where a monotonic reading has no
 * meaning, and it must work on pages that never fetch context at all. Nothing it
 * reads can change what a visitor is eligible to see. Widening this list to all
 * of src/frontend would flag that call, and the fix somebody reached for would be
 * to weaken the guard rather than to keep the schedule path clean.
 *
 * @see docs/build-plan.md -> Phase 2 -> Clock correctness
 * @see docs/CLAUDE.md -> The context endpoint
 */

/* global describe, it, expect */

import { readFileSync } from 'fs';
import { join } from 'path';

/**
 * Plugin root, resolved from this file rather than from the working directory.
 *
 * @type {string}
 */
const PLUGIN_ROOT = join( __dirname, '..', '..' );

/**
 * Modules that may not read the device wall clock, as repo-relative paths.
 *
 * @type {string[]}
 */
const GUARDED_MODULES = [ 'src/frontend/schedule.js', 'src/frontend/clock.js' ];

/**
 * A fragment of real code expected to survive the strip, per guarded module.
 *
 * These exist so a stripper that had quietly blanked an entire file could not
 * pass as a clean module. Each is a top-level export, so it stays true for any
 * edit short of deleting the module's reason to exist.
 *
 * @type {Object}
 */
const CODE_LANDMARKS = {
	'src/frontend/schedule.js': 'export function scheduleAllows',
	'src/frontend/clock.js': 'export function createClock',
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
 * Looks back through the already-blanked output, so comments before the slash
 * cannot influence the decision. `)` and `]` end an expression and therefore
 * divide; a bare identifier or number divides; a keyword such as `return` cannot
 * be divided and so opens a literal.
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
 * Blanks a regular expression literal and returns the index just past its flags.
 *
 * A literal cannot span a line, so a candidate that reaches a newline unclosed
 * was a division after all. Returning `start + 1` in that case leaves the slash
 * as code and blanks nothing, which is the safe direction: over-consuming would
 * blank real code and hide an offence.
 *
 * @param {string}   source Original source.
 * @param {string[]} out    Mutable character array being blanked in place.
 * @param {number}   start  Index of the opening `/`.
 * @return {number} Index to resume scanning from.
 */
function skipRegexLiteral( source, out, start ) {
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

			blankRange( out, start + 1, end );

			return end;
		}

		index++;
	}

	return start + 1;
}

/**
 * Blanks the text of a template literal, scanning its substitutions as code.
 *
 * Blanking a template whole would let `` `${ Date.now() }` `` through, so only
 * the literal text between substitutions is removed.
 *
 * @param {string}   source Original source.
 * @param {string[]} out    Mutable character array being blanked in place.
 * @param {number}   start  Index of the opening backtick.
 * @return {number} Index to resume scanning from.
 */
function scanTemplate( source, out, start ) {
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
			index = scanCode( source, out, index + 2, true );
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
 * @param {number}   start             Index to start from.
 * @param {boolean}  untilClosingBrace True when scanning a `${ … }` substitution,
 *                                     which ends at its unmatched `}`.
 * @return {number} Index just past the scanned region.
 */
function scanCode( source, out, start, untilClosingBrace ) {
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
			index = scanTemplate( source, out, index );
			continue;
		}

		if ( '/' === char && startsRegexLiteral( out, index ) ) {
			index = skipRegexLiteral( source, out, index );
			continue;
		}

		index++;
	}

	return source.length;
}

/**
 * Returns the source with every comment and literal replaced by spaces.
 *
 * @param {string} source JavaScript source.
 * @return {string} Same-length source in which only code characters remain.
 */
function stripNonCode( source ) {
	const out = source.split( '' );

	scanCode( source, out, 0, false );

	return out.join( '' );
}

/**
 * Reports whether an exact identifier sits at an index, on identifier boundaries.
 *
 * The boundary check is what keeps `myDate.now()` and `Date.nowhere` out of the
 * offence list while `window.Date.now()` stays in it: `.` is not an identifier
 * character, so a qualified read is still a boundary-clean occurrence of `Date`.
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
 * Reports whether the identifier immediately before an index is `new`.
 *
 * @param {string} code  Blanked source.
 * @param {number} index Index of the identifier being classified.
 * @return {boolean} True when the construct is `new Date…`.
 */
function isPrecededByNew( code, index ) {
	let back = index - 1;

	while ( 0 <= back && isSpace( code[ back ] ) ) {
		back--;
	}

	let wordStart = back;

	while ( 0 <= wordStart && isIdentifierChar( code[ wordStart ] ) ) {
		wordStart--;
	}

	return 'new' === code.slice( wordStart + 1, back + 1 );
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
 * Returns the wall-clock reads in one JavaScript source string.
 *
 * Three constructs read the device clock and all three are offences: `Date.now`,
 * which is the one the build plan names and the only one anybody writes on
 * purpose; `new Date()` with no arguments, which is the same read wearing a
 * constructor; and a bare `Date()` call, which returns the current time as a
 * string. `new Date( nowMs )`, `Date.parse()` and `Date.UTC()` are untouched —
 * they convert a moment the caller already has, which is exactly what schedule.js
 * is supposed to do with the authoritative timestamp it is handed.
 *
 * `Date.now` is reported whether or not a `(` follows it, because in code — as
 * opposed to prose, which never reaches this function — there is no reason to
 * name it except to call it, and `const now = Date.now;` would otherwise be a
 * one-line way around the guard.
 *
 * @param {string} source JavaScript source.
 * @param {string} label  Path to name in the message, relative to the plugin root.
 * @return {string[]} One human-readable sentence per offence; empty when there are none.
 */
function offencesInSource( source, label ) {
	const code = stripNonCode( source );
	const offences = [];

	for ( let index = 0; index < code.length; index++ ) {
		if ( ! isWordAt( code, index, 'Date' ) ) {
			continue;
		}

		const after = skipSpaces( code, index + 'Date'.length );
		let spelling = null;

		if ( '.' === code[ after ] ) {
			if ( isWordAt( code, skipSpaces( code, after + 1 ), 'now' ) ) {
				spelling = 'Date.now';
			}
		} else if ( '(' === code[ after ] ) {
			if ( ')' === code[ skipSpaces( code, after + 1 ) ] ) {
				spelling = isPrecededByNew( code, index )
					? 'new Date()'
					: 'Date()';
			}
		}

		if ( null !== spelling ) {
			offences.push(
				`  ${ label }:${ lineAt(
					code,
					index
				) } reads the device wall clock via ${ spelling }`
			);
		}
	}

	return offences;
}

/**
 * Reads one guarded module from disk.
 *
 * @param {string} relativePath Path relative to the plugin root.
 * @return {string} File contents.
 */
function readModule( relativePath ) {
	return readFileSync( join( PLUGIN_ROOT, relativePath ), 'utf8' );
}

describe( 'no-wall-clock invariant', () => {
	it( 'covers the schedule evaluation module', () => {
		// A renamed or moved module would otherwise leave the scan passing over
		// a list of files it never opened.
		expect( GUARDED_MODULES ).toContain( 'src/frontend/schedule.js' );

		for ( const relativePath of GUARDED_MODULES ) {
			expect( readModule( relativePath ).length ).toBeGreaterThan( 0 );
		}
	} );

	it( 'leaves real code behind in every guarded module', () => {
		// The fail-open this file most needs to rule out: a stripper that
		// over-consumed reports no offences and looks exactly like clean source.
		for ( const relativePath of GUARDED_MODULES ) {
			expect( stripNonCode( readModule( relativePath ) ) ).toContain(
				CODE_LANDMARKS[ relativePath ]
			);
		}
	} );

	it( 'finds no wall-clock read in any guarded module', () => {
		const offences = GUARDED_MODULES.flatMap( ( relativePath ) =>
			offencesInSource( readModule( relativePath ), relativePath )
		);

		// This is the invariant. Everything else in the file exists to make this
		// assertion trustworthy.
		expect( offences ).toEqual( [] );
	} );

	it( 'does not trip on the sentence in clock.js that explains the ban', () => {
		const source = readModule( 'src/frontend/clock.js' );

		// Both halves matter. The first pins the explanation in place, so a guard
		// that started firing on prose could not be "fixed" by deleting the
		// paragraph; the second is the guard staying quiet about it.
		expect( source ).toContain(
			'`Date.now()` appears nowhere in this file'
		);
		expect( offencesInSource( source, 'src/frontend/clock.js' ) ).toEqual(
			[]
		);
	} );

	it( 'detects a wall-clock read and names the file and the line', () => {
		const snippet =
			'const schedule = config.schedule;\n' +
			'const nowMs = Date.now();\n';

		const offences = offencesInSource(
			snippet,
			'src/frontend/schedule.js'
		);

		expect( offences ).toHaveLength( 1 );
		expect( offences[ 0 ] ).toContain( 'src/frontend/schedule.js' );
		expect( offences[ 0 ] ).toContain( ':2' );
		expect( offences[ 0 ] ).toContain( 'Date.now' );
	} );

	it( 'detects every spelling of a wall-clock read', () => {
		const spellings = [
			'const t = Date.now();',
			'const t = window.Date.now();',
			'const t = globalThis.Date.now();',
			'const t = Date . now();',
			'const read = Date.now;',
			'const t = new Date();',
			'const t = new Date().getTime();',
			'const t = Date();',
			'const t = `${ Date.now() }`;',
		];

		for ( const spelling of spellings ) {
			expect( {
				spelling,
				offences: offencesInSource( spelling, 'fixture.js' ).length,
			} ).toEqual( { spelling, offences: 1 } );
		}
	} );

	it( 'ignores prose, literals and members that merely share a name', () => {
		const snippet = [
			'/**',
			' * `Date.now()` appears nowhere in this file and must never be added to',
			' * it. Reading Date.now() here would reintroduce the skew, and',
			' * new Date() is the same read wearing a constructor.',
			' */',
			'// Rejected in review: Date.now() as a fallback.',
			"const documented = 'Date.now()';",
			'const explained = `call Date.now() here and the build fails`;',
			'const pattern = /Date\\.now\\(\\)/;',
			'const stamped = new Date( nowMs );',
			'const parsed = Date.parse( value );',
			'const utc = Date.UTC( year, month, day );',
			'const mine = myDate.now();',
			'const theirs = clock.now();',
			'const nested = Date.nowhere;',
			'const midpoint = ( sentAtMs + receivedAtMs ) / 2;',
		].join( '\n' );

		expect( offencesInSource( snippet, 'fixture.js' ) ).toEqual( [] );
	} );

	it( 'does not let a division hide the code that follows it', () => {
		// A `/` misread as opening a regular expression would blank everything to
		// the next `/` — here, the whole rest of the file — and report a clean
		// module. The literal cannot span a line, so the scanner backs out.
		const snippet =
			'const half = total / 2;\n' + 'const nowMs = Date.now();\n';

		expect( offencesInSource( snippet, 'fixture.js' ) ).toHaveLength( 1 );
	} );
} );
