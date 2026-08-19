/**
 * Unit tests for the five client-context conditions.
 *
 * `docs/data-model.md` -> Built-in conditions puts `device`, `user_state`,
 * `referrer`, `utm` and `visit_history` in the browser rather than in PHP,
 * because every one of them varies by visitor and a page cache serves one HTML
 * response to many visitors. That placement is the whole architecture, and it is
 * only worth anything if each module actually reads the visitor rather than the
 * cached page — so several assertions here are about the *source* a module
 * consults, not merely about the answer it produced.
 *
 * ## Two kinds of `false`, and why the tests keep them apart
 *
 * `conditions/index.js` gives every evaluator three return values: `true` and
 * `false` are decisions the controller applies `negate` to, and anything else —
 * spelled `undefined` throughout — means the module did not answer, so stage 7
 * denies the rule with `negate` left unapplied.
 *
 * A test that asserted only "did not match" would pass equally against a module
 * that answered `false`, and the difference is not cosmetic: a `negate: true`
 * rule whose evaluator returned `false` *passes*, which is
 * `docs/data-model.md` -> Evaluation semantics's stated worst case, a popup
 * targeted at one segment silently going site-wide. So every unanswerable case
 * below asserts `undefined` specifically, and the cases that genuinely are a
 * decision assert `false` specifically. `stage7.test.js` asserts the other half
 * — that the controller turns the third value into a denial rather than into a
 * pass — across the full negate matrix.
 *
 * ## What is deliberately not here
 *
 * Nothing in this file calls the controller. Group AND/OR semantics, the
 * fail-closed denial, `negate`, and the context payload's availability are
 * stage 7's, and are asserted against `boot()` in `stage7.test.js`. These are the
 * five modules on their own, given values and a context payload directly.
 *
 * An evaluator is handed a rule's `values` and nothing else, so the rest of the
 * emitted rule — `type`, `negate`, and the `unknown` tag PHP sets on a type it
 * has no registration for — cannot reach one and does not appear below. That is
 * a real division of labor and not an omission, but it does mean **no test in
 * this file can tell a working condition from one the client denies before its
 * evaluator is ever called**. Phase 4 shipped exactly that state: five modules
 * that passed every test here, and five missing PHP registrations that had the
 * controller refusing all five rules on the tag alone. `stage7.test.js` is where
 * that is caught, through `boot()`, with the tag in the fixture.
 *
 * The registry at the end of this file is the other half of the same gap. A
 * module left out of `CLIENT_CONDITIONS` behaves precisely like a module that
 * does not exist, and every import above reaches around the registry to the file
 * itself.
 *
 * @see docs/CLAUDE.md -> Cache safety
 * @see docs/data-model.md -> Built-in conditions
 * @see docs/build-plan.md -> Phase 4
 * @see tests/js/stage7.test.js
 */

/* global describe, it, expect, jest, beforeEach, afterEach */

import device from '../../src/frontend/conditions/device.js';
import { CLIENT_CONDITIONS } from '../../src/frontend/conditions/index.js';
import referrer, {
	urlMatches,
} from '../../src/frontend/conditions/referrer.js';
import userState from '../../src/frontend/conditions/user-state.js';
import utm from '../../src/frontend/conditions/utm.js';

/**
 * Storage key `visit_history` records a visit under.
 *
 * Spelled here rather than imported, so that renaming the key in the module is a
 * visible change to a stored contract rather than a silent one — every browser
 * that has already been marked carries the old name.
 *
 * @type {string}
 */
const VISITED_KEY = 'popkit:visited';

/**
 * Property descriptors the environment installed, captured before replacement.
 *
 * `matchMedia` is a stub the jest preset assigns rather than something jsdom
 * implements, and it answers `false` to every query — which would make the
 * device tests pass by accident. `innerWidth` is jsdom's, and is only ever set
 * here to a value that *contradicts* the media query, so that a module reading
 * it gets a different answer from a module asking the breakpoint.
 *
 * @type {Object}
 */
const ORIGINAL_DESCRIPTORS = {
	innerWidth: Object.getOwnPropertyDescriptor( window, 'innerWidth' ),
	localStorage: Object.getOwnPropertyDescriptor( window, 'localStorage' ),
	matchMedia: Object.getOwnPropertyDescriptor( window, 'matchMedia' ),
};

/**
 * Media queries the module under test asked this test's `matchMedia`.
 *
 * @type {string[]}
 */
let mediaQueries = [];

/**
 * Reads the pixel width out of a `(max-width: …px)` query.
 *
 * `parseInt` stops at the first character that is not part of the number and
 * skips leading whitespace, so `782px)` and ` 782px)` both read as 782. That
 * tolerance is deliberate: the assertion about the query's *text* is made once,
 * explicitly, in its own test, and the fake should not also fail whenever
 * somebody adds a space after the colon.
 *
 * @param {string} query Media query text.
 * @return {number} Declared maximum width, or NaN when there is none.
 */
function maxWidthOf( query ) {
	return Number.parseInt( String( query ).split( ':' )[ 1 ], 10 );
}

/**
 * Installs a `matchMedia` that answers for a viewport of the given width.
 *
 * The queries are recorded rather than merely answered, because "the module
 * asked the breakpoint question" is a property in its own right — a module that
 * read `innerWidth` would produce no queries at all, and a test that only
 * checked the boolean would not notice.
 *
 * @param {number} width Viewport width the fake reports, in CSS pixels.
 * @return {void}
 */
function useViewport( width ) {
	mediaQueries = [];

	window.matchMedia = jest.fn( ( query ) => {
		mediaQueries.push( query );

		return {
			matches: width <= maxWidthOf( query ),
			// A `change` subscription would outlive this test and, in the bundle,
			// would let a popup vanish mid-interaction when a phone rotated. The
			// module must query and discard, so these are spies that assert their
			// own absence.
			addEventListener: jest.fn(),
			addListener: jest.fn(),
		};
	} );
}

/**
 * Installs a `matchMedia` that throws, as a hostile or broken environment would.
 *
 * @return {void}
 */
function useThrowingViewport() {
	window.matchMedia = jest.fn( () => {
		throw new Error( 'NotSupportedError' );
	} );
}

/**
 * Reports the viewport width to anything reading `window.innerWidth`.
 *
 * @param {number} width Width to report, in CSS pixels.
 * @return {void}
 */
function useInnerWidth( width ) {
	Object.defineProperty( window, 'innerWidth', {
		configurable: true,
		writable: true,
		value: width,
	} );
}

/**
 * Points `document.referrer` at a URL without navigating.
 *
 * jsdom exposes `referrer` as a getter on `Document.prototype`, so an own
 * property shadows it for the length of the test and `delete` puts the
 * prototype's empty string back.
 *
 * @param {string} url Referring URL, exactly as a browser would report it.
 * @return {void}
 */
function useReferrer( url ) {
	Object.defineProperty( window.document, 'referrer', {
		configurable: true,
		get: () => url,
	} );
}

/**
 * Points the document at a URL without navigating to it.
 *
 * jsdom implements no navigation and none is wanted: `utm` reads
 * `window.location.search`, which `replaceState()` updates in place.
 *
 * @param {string} url URL relative to the document.
 * @return {void}
 */
function visit( url ) {
	window.history.replaceState( null, '', url );
}

/**
 * Creates an in-memory stand-in for `window.localStorage`.
 *
 * @param {Object}  [options]          Failure modes to simulate.
 * @param {boolean} [options.readOnly] Whether `setItem` throws, as a full quota
 *                                     or a locked-down profile makes it.
 * @return {Object} Storage-like object with its backing map attached.
 */
function createStorage( options ) {
	const entries = new Map();

	return {
		entries,
		getItem: jest.fn( ( key ) =>
			entries.has( key ) ? entries.get( key ) : null
		),
		setItem: jest.fn( ( key, value ) => {
			if ( options?.readOnly ) {
				throw new Error( 'QuotaExceededError' );
			}

			entries.set( key, String( value ) );
		} ),
		removeItem: jest.fn( ( key ) => entries.delete( key ) ),
	};
}

/**
 * Exposes a storage stand-in as `window.localStorage`.
 *
 * @param {Object} area Stand-in to expose.
 * @return {void}
 */
function useStorage( area ) {
	Object.defineProperty( window, 'localStorage', {
		configurable: true,
		get: () => area,
	} );
}

/**
 * Makes the storage *property access itself* throw.
 *
 * This is what private-browsing and storage-disabled browsers do: the
 * SecurityError is raised on `window.localStorage`, before any method is called
 * on it, which is why the module's try/catch has to wrap the getter rather than
 * the call.
 *
 * @return {void}
 */
function useThrowingStorage() {
	Object.defineProperty( window, 'localStorage', {
		configurable: true,
		get() {
			throw new Error( 'SecurityError: storage is not available' );
		},
	} );
}

/**
 * Restores every property this file replaces.
 *
 * @return {void}
 */
function restoreEnvironment() {
	for ( const [ name, descriptor ] of Object.entries(
		ORIGINAL_DESCRIPTORS
	) ) {
		if ( descriptor ) {
			Object.defineProperty( window, name, descriptor );
		} else {
			delete window[ name ];
		}
	}

	delete window.document.referrer;
}

/**
 * Loads a fresh copy of the `visit_history` module.
 *
 * That module memoizes its reading for the life of the pageview, deliberately —
 * two popups on one page must agree about whether this visitor is new. A module
 * instance is therefore a pageview, and a test needing a *second* pageview needs
 * a second instance over the same storage.
 *
 * @return {Promise<Object>} The condition module.
 */
async function loadVisitHistory() {
	jest.resetModules();

	return ( await import( '../../src/frontend/conditions/visit-history.js' ) )
		.default;
}

beforeEach( () => {
	useViewport( 1024 );
	useInnerWidth( 1024 );
	useStorage( createStorage() );
	visit( '/' );

	window.document.body.className = '';
} );

afterEach( () => {
	jest.restoreAllMocks();
	restoreEnvironment();

	visit( '/' );

	window.document.body.className = '';
} );

describe( 'device', () => {
	it.each( [
		[ 'far below the maximum', 320, 782, true ],
		[ 'one pixel below it', 781, 782, true ],
		[ 'exactly on it', 782, 782, true ],
		[ 'one pixel above it', 783, 782, false ],
		[ 'far above it', 1600, 782, false ],
	] )( 'matches a viewport %s', ( label, viewport, maxWidth, expected ) => {
		useViewport( viewport );

		// "At most `max_width`" is inclusive at the boundary, which is the
		// only value an author ever types: they enter the breakpoint their
		// theme collapses at, and a device sitting exactly on it belongs to
		// the narrow side in the stylesheet and must belong to it here.
		expect( device.evaluate( { max_width: maxWidth } ) ).toBe( expected );
	} );

	it( 'asks matchMedia the breakpoint question, in the query the theme uses', () => {
		useViewport( 600 );

		device.evaluate( { max_width: 782 } );

		expect( window.matchMedia ).toHaveBeenCalledTimes( 1 );

		/*
		 * Whitespace is normalized, so `(max-width: 782px)` and
		 * `(max-width:782px)` both pass and a reviewer reformatting the template
		 * literal does not get a red build. Everything else is asserted exactly:
		 * `min-width` instead of `max-width` inverts the condition,
		 * `max-device-width` is a different and deprecated measurement, and a
		 * missing `px` makes the query invalid and therefore never matching.
		 */
		expect( mediaQueries ).toHaveLength( 1 );
		expect( mediaQueries[ 0 ].split( ' ' ).join( '' ) ).toBe(
			'(max-width:782px)'
		);
	} );

	it.each( [
		[ 'a narrow viewport and a wide innerWidth', 600, 1600, true ],
		[ 'a wide viewport and a narrow innerWidth', 1600, 320, false ],
	] )(
		'follows the media query and not innerWidth, given %s',
		( label, viewport, innerWidth, expected ) => {
			useViewport( viewport );
			useInnerWidth( innerWidth );

			/*
			 * The two sources are made to disagree on purpose. `innerWidth` is
			 * affected by pinch zoom and by whether a classic scrollbar is
			 * counted, so a visitor on the boundary can get the theme's mobile
			 * layout and the desktop popup at once. A module reading it would
			 * return the opposite of both assertions below.
			 */
			expect( device.evaluate( { max_width: 782 } ) ).toBe( expected );
			expect( mediaQueries ).toHaveLength( 1 );
		}
	);

	it( 'does not subscribe to the media query', () => {
		let list;

		window.matchMedia = jest.fn( () => {
			list = {
				matches: true,
				addEventListener: jest.fn(),
				addListener: jest.fn(),
			};

			return list;
		} );

		expect( device.evaluate( { max_width: 782 } ) ).toBe( true );

		/*
		 * Eligibility is settled once per pageview. A subscription would let a
		 * popup disappear when the visitor rotated their phone or dragged a
		 * window edge, taking its focus trap and its close button with it while
		 * they were reading.
		 */
		expect( list.addEventListener ).not.toHaveBeenCalled();
		expect( list.addListener ).not.toHaveBeenCalled();
	} );

	it( 'reads a numeric string as the number it spells', () => {
		useViewport( 600 );

		expect( device.evaluate( { max_width: '782' } ) ).toBe( true );
		expect( mediaQueries[ 0 ].split( ' ' ).join( '' ) ).toBe(
			'(max-width:782px)'
		);
	} );

	it( 'treats a stored zero as unreadable, not as a rule nobody matches', () => {
		useViewport( 1 );

		/*
		 * `(max-width:0px)` is valid CSS that no viewport satisfies, so the
		 * browser can answer it — but the schema declares 1 and up, so no author
		 * can enter it and its only provenance is a hand-edited row or a forged
		 * write, the same provenance as `-1`. Calling it a decision hands that
		 * provenance a `false`, and `negate: true` turns a `false` into a rule
		 * matching every visitor on the site.
		 */
		expect( device.evaluate( { max_width: 0 } ) ).toBeUndefined();
		expect( window.matchMedia ).not.toHaveBeenCalled();
	} );

	it.each( [
		[ 'no values at all', undefined ],
		[ 'no max_width', {} ],
		[ 'a max_width that is not a number', { max_width: 'wide' } ],
		[ 'a max_width that is an object', { max_width: {} } ],
		[ 'an infinite max_width', { max_width: Infinity } ],
		[ 'a NaN max_width', { max_width: NaN } ],
		[ 'a negative max_width', { max_width: -782 } ],
		[ 'a fractional max_width', { max_width: 782.5 } ],
		/*
		 * The four `Number()` traps, and the reason readability is decided
		 * before the coercion rather than from its result. `Number()` maps
		 * `null`, `''` and `[]` to `0` and `true` to `1`, so a module testing
		 * `Number.isFinite()` on the coerced value calls all four readable and
		 * lets `true` spell a 1px breakpoint.
		 */
		[ 'a null max_width', { max_width: null } ],
		[ 'an empty-string max_width', { max_width: '' } ],
		[ 'an array max_width', { max_width: [] } ],
		[ 'a boolean max_width', { max_width: true } ],
	] )( 'cannot answer for %s', ( label, values ) => {
		/*
		 * `undefined` rather than `false`, and the distinction is the point.
		 * Stage 7 denies both, but it applies `negate` to a `false` — so an
		 * unreadable width on a negated rule would pass for every visitor on the
		 * site rather than for none.
		 */
		expect( device.evaluate( values ) ).toBeUndefined();
		expect( window.matchMedia ).not.toHaveBeenCalled();
	} );

	it( 'cannot answer when matchMedia itself throws', () => {
		useThrowingViewport();

		expect( () => device.evaluate( { max_width: 782 } ) ).toThrow();
	} );

	it( 'declares that it needs no context', () => {
		// Read by the controller, not decoration: a condition declaring `true`
		// here is denied before its evaluator runs whenever the payload is
		// missing. Claiming a need this module does not have would make every
		// device-targeted popup depend on a round trip it never uses.
		expect( device.needsContext ).toBe( false );
		expect( device.key ).toBe( 'device' );
	} );
} );

describe( 'user_state', () => {
	it.each( [
		[ 'logged_in', 'logged_in', true ],
		[ 'logged_in', 'logged_out', false ],
		[ 'logged_out', 'logged_out', true ],
		[ 'logged_out', 'logged_in', false ],
	] )(
		'a rule for %s against a visitor who is %s',
		( wanted, actual, expected ) => {
			expect(
				userState.evaluate(
					{ state: wanted },
					{ user: { state: actual } }
				)
			).toBe( expected );
		}
	);

	it.each( [
		[ 'the payload is null, as a failed fetch leaves it', null ],
		[ 'the payload is undefined', undefined ],
		[ 'the payload carries no user object', { time: 1766000000000 } ],
		[ 'the user object is null', { user: null } ],
		[ 'the user object carries no state', { user: {} } ],
		[ 'the state is not a string', { user: { state: 1 } } ],
	] )( 'cannot answer when %s', ( label, context ) => {
		const result = userState.evaluate( { state: 'logged_in' }, context );

		/*
		 * The single most consequential `undefined` in the plugin. A failed
		 * context request that answered `false` would, on a rule written as
		 * "logged-out visitors only" — which is `state: logged_in` with
		 * `negate: true` — pass for every member on the site the moment the
		 * route had a bad minute.
		 */
		expect( result ).toBeUndefined();
		expect( result ).not.toBe( false );
	} );

	it.each( [
		[ 'no values at all', undefined ],
		[ 'no state', {} ],
		[ 'a hyphenated state', { state: 'logged-in' } ],
		[ 'a camelCase state', { state: 'loggedIn' } ],
		[ 'a state from another vocabulary', { state: 'member' } ],
		[ 'an empty state', { state: '' } ],
		[ 'a state that is not a string', { state: true } ],
	] )( 'cannot answer for a rule with %s', ( label, values ) => {
		/*
		 * The stored side of the same `undefined`, and the worse half of it. A
		 * failed context request answers `false` once; an unreadable stored
		 * state answers `false` on every pageview forever. "Members only" is
		 * `state: logged_in` with `negate: true`, so a `false` here is that
		 * popup shown to every anonymous visitor on the site, with the
		 * targeting still reading correctly in the editor.
		 */
		expect(
			userState.evaluate( values, { user: { state: 'logged_in' } } )
		).toBeUndefined();

		expect(
			userState.evaluate( values, { user: { state: 'logged_out' } } )
		).toBeUndefined();
	} );

	it( 'does not satisfy a rule whose own state is missing either', () => {
		// Without the typeof guard in the module, a payload with no state and a
		// rule with no state would compare undefined with undefined and pass.
		expect( userState.evaluate( {}, { user: {} } ) ).toBeUndefined();
	} );

	it( 'ignores a body class that contradicts the payload', () => {
		// WordPress prints this on the body, and it is part of the cached HTML:
		// a page cached while an editor was signed in reports `logged-in` to
		// every anonymous visitor afterwards.
		window.document.body.className = 'logged-in admin-bar';

		const contains = jest.spyOn(
			window.document.body.classList,
			'contains'
		);

		expect(
			userState.evaluate(
				{ state: 'logged_in' },
				{ user: { state: 'logged_out' } }
			)
		).toBe( false );

		expect(
			userState.evaluate(
				{ state: 'logged_out' },
				{ user: { state: 'logged_out' } }
			)
		).toBe( true );

		expect( contains ).not.toHaveBeenCalled();
	} );

	it( 'does not fall back to the body class when the payload is missing', () => {
		window.document.body.className = 'logged-in';

		/*
		 * The tempting shortcut, in the one situation where it looks helpful:
		 * the fetch failed, and the cached HTML appears to know the answer. It
		 * does not — it knows what was true for whoever filled the cache.
		 */
		expect(
			userState.evaluate( { state: 'logged_in' }, null )
		).toBeUndefined();
	} );

	it( 'declares that it needs context', () => {
		// The only v1 condition that does, and the reason the route exists. The
		// controller reads this to deny the rule before the evaluator runs.
		expect( userState.needsContext ).toBe( true );
		expect( userState.key ).toBe( 'user_state' );
	} );
} );

describe( 'referrer -> the four modes', () => {
	beforeEach( () => {
		useReferrer( 'https://example.org/newsletter' );
	} );

	it.each( [
		[ 'exact', 'example.org/newsletter', true ],
		[ 'exact', 'example.org/newsletters', false ],
		[ 'exact', 'example.org', false ],
		[ 'exact', 'newsletter', false ],
		[ 'prefix', 'example.org', true ],
		[ 'prefix', 'example.org/news', true ],
		[ 'prefix', 'other.org', false ],
		[ 'prefix', 'newsletter', false ],
		[ 'contains', 'org/news', true ],
		[ 'contains', 'newsletter', true ],
		[ 'contains', 'facebook', false ],
		[ 'glob', 'example.org/*', true ],
		[ 'glob', '*org/news*', true ],
		[ 'glob', 'example.org/?ewsletter', true ],
		[ 'glob', 'example.org/??ewsletter', false ],
		[ 'glob', 'other.org/*', false ],
	] )( '%s against %s', ( match, value, expected ) => {
		expect( referrer.evaluate( { match, value } ) ).toBe( expected );
	} );

	it( 'folds ASCII case on both sides', () => {
		useReferrer( 'https://Example.ORG/Newsletter' );

		// The URL parser lowercases the host by itself, so the path is what
		// proves the subject is folded, and the uppercase value is what proves
		// the pattern is.
		expect(
			referrer.evaluate( {
				match: 'exact',
				value: 'EXAMPLE.ORG/NEWSLETTER',
			} )
		).toBe( true );
		expect(
			referrer.evaluate( {
				match: 'exact',
				value: 'example.org/newsletter',
			} )
		).toBe( true );
	} );

	it( 'matches the host and path only', () => {
		useReferrer( 'https://example.org/newsletter?utm_source=appeal#top' );

		expect(
			referrer.evaluate( {
				match: 'exact',
				value: 'example.org/newsletter',
			} )
		).toBe( true );

		// Campaign parameters are the `utm` condition's job. A `contains` rule
		// that reached into the query string would quietly make the two
		// conditions overlap, with different comparison rules each.
		expect(
			referrer.evaluate( { match: 'contains', value: 'utm_source' } )
		).toBe( false );
		expect( referrer.evaluate( { match: 'contains', value: 'top' } ) ).toBe(
			false
		);
	} );

	it( 'keeps a non-default port, which is part of the origin', () => {
		useReferrer( 'https://example.org:8443/a' );

		expect(
			referrer.evaluate( { match: 'exact', value: 'example.org:8443/a' } )
		).toBe( true );
		expect(
			referrer.evaluate( { match: 'exact', value: 'example.org/a' } )
		).toBe( false );
	} );

	it( 'reads a referrer with no path as a trailing slash', () => {
		useReferrer( 'https://example.org' );

		// Stated in the module header, and the reason an author writing
		// `example.org` needs `prefix` rather than `exact`.
		expect(
			referrer.evaluate( { match: 'exact', value: 'example.org/' } )
		).toBe( true );
		expect(
			referrer.evaluate( { match: 'exact', value: 'example.org' } )
		).toBe( false );
		expect(
			referrer.evaluate( { match: 'prefix', value: 'example.org' } )
		).toBe( true );
	} );
} );

describe( 'referrer -> an empty referrer is an answer, not a failure', () => {
	it.each( [
		[ 'no referrer at all', '' ],
		[ 'a referrer the URL parser rejects', 'not a url' ],
		[
			'a referrer with a malformed percent escape',
			'https://e.test/%E0%A4%A',
		],
	] )( 'reads %s as the empty subject', ( label, value ) => {
		useReferrer( value );

		/*
		 * A direct visit, a bookmark, an https page linking to an http one, and
		 * any site sending `Referrer-Policy: no-referrer` all produce ''. That
		 * is a large share of real traffic, so it is answered rather than
		 * treated as unresolvable — a module returning `undefined` here would
		 * suppress every referrer-targeted popup for direct visitors.
		 */
		expect( referrer.evaluate( { match: 'exact', value: '' } ) ).toBe(
			true
		);
		expect( referrer.evaluate( { match: 'prefix', value: '' } ) ).toBe(
			true
		);
		expect( referrer.evaluate( { match: 'contains', value: '' } ) ).toBe(
			true
		);
		expect( referrer.evaluate( { match: 'glob', value: '' } ) ).toBe(
			true
		);
		expect( referrer.evaluate( { match: 'glob', value: '*' } ) ).toBe(
			true
		);
		expect( referrer.evaluate( { match: 'glob', value: '**' } ) ).toBe(
			true
		);

		/*
		 * And "came from Facebook" is false for somebody who came from nowhere.
		 * That is a decision the module made, so the same rule with
		 * `negate: true` legitimately reads as "not from Facebook" and includes
		 * them — contrast the unreadable cases below, which stage 7 must never
		 * negate.
		 */
		expect(
			referrer.evaluate( { match: 'contains', value: 'facebook.com' } )
		).toBe( false );
		expect(
			referrer.evaluate( { match: 'exact', value: 'facebook.com/' } )
		).toBe( false );
		expect( referrer.evaluate( { match: 'glob', value: 'a*' } ) ).toBe(
			false
		);
	} );
} );

describe( 'referrer -> unreadable rules', () => {
	beforeEach( () => {
		useReferrer( 'https://example.org/newsletter' );
	} );

	it.each( [
		[ 'no values at all', undefined ],
		[ 'no value', { match: 'exact' } ],
		[ 'a numeric value', { match: 'exact', value: 7 } ],
		[ 'a null value', { match: 'exact', value: null } ],
		[ 'an array value', { match: 'exact', value: [ 'a' ] } ],
	] )( 'cannot answer given %s', ( label, values ) => {
		expect( referrer.evaluate( values ) ).toBeUndefined();
	} );

	it.each( [
		[ 'regex', 'example.org/.*' ],
		[ 'starts_with', 'example.org' ],
		[ '', 'example.org/newsletter' ],
		[ undefined, 'example.org/newsletter' ],
		[ 'EXACT', 'example.org/newsletter' ],
	] )( 'cannot answer for the unrecognized mode %s', ( match, value ) => {
		/*
		 * `regex` in particular. There is no fifth mode, and a stored rule
		 * naming one must narrow the audience to nobody rather than be
		 * interpreted as the nearest thing — `docs/CLAUDE.md` -> Security is
		 * unconditional that no user-supplied pattern is ever compiled.
		 */
		expect( referrer.evaluate( { match, value } ) ).toBeUndefined();
	} );
} );

describe( 'urlMatches -> the bundle has exactly one match language', () => {
	it.each( [
		[ '.', 'a.c', 'abc', false ],
		[ '.', 'a.c', 'a.c', true ],
		[ '+', 'ab+', 'abbb', false ],
		[ '+', 'ab+', 'ab+', true ],
		[ '[]', '[abc]', 'a', false ],
		[ '[]', '[abc]', '[abc]', true ],
		[ '{}', 'a{1,2}', 'aa', false ],
		[ '{}', 'a{1,2}', 'a{1,2}', true ],
		[ '()', '(a)', 'a', false ],
		[ '|', 'a|b', 'a', false ],
		[ '^ and $', '^abc$', 'abc', false ],
		[ '^ and $', '^abc$', '^abc$', true ],
		[ '\\', 'a\\*c', 'abc', false ],
		[ '\\', 'a\\bc', 'a\\bc', true ],
	] )(
		'treats the regex metacharacter %s as a literal',
		( label, pattern, subject, expected ) => {
			/*
			 * This table is the tripwire. `docs/CLAUDE.md` -> Security forbids
			 * compiling a pattern from author input, and the cheapest way to
			 * reintroduce one is a second glob implementation that translates
			 * `*` and `?` into a regular expression and forgets to escape
			 * everything else. Every row below would flip to the opposite answer
			 * under such a translation — `a.c` would match `abc`, `ab+` would
			 * match `abbb`, `[abc]` would match `a` — so a red row here means a
			 * matcher has grown a compiler, not that a case was missed.
			 *
			 * The backslash rows say the other half of it: `\` is a literal too,
			 * so there is no escape syntax to get wrong, and `a\*c` is "a,
			 * backslash, anything, c".
			 */
			expect( urlMatches( 'glob', pattern, subject ) ).toBe( expected );
		}
	);

	it.each( [
		[ '*', '', true ],
		[ '*', 'anything at all', true ],
		[ '**', 'anything at all', true ],
		[ '*abc', 'zzabc', true ],
		[ 'abc*', 'abczz', true ],
		[ 'abc*', 'abc', true ],
		[ '*abc*', 'abc', true ],
		[ 'a*b*c', 'axxbyyc', true ],
		[ 'a**c', 'ac', true ],
		[ 'a*c', 'abd', false ],
		[ '?', 'a', true ],
		[ '?', '', false ],
		[ '?', 'ab', false ],
		[ 'a?c', 'abc', true ],
		[ 'a?c', 'ac', false ],
		[ 'a?c', 'abbc', false ],
		[ 'a?*', 'ab', true ],
		[ '', '', true ],
		[ '', 'a', false ],
	] )( 'globs %s against %s', ( pattern, subject, expected ) => {
		expect( urlMatches( 'glob', pattern, subject ) ).toBe( expected );
	} );

	it.each( [
		[ 'an ASCII character', 'caf?', 'cafe', 1, true ],
		[ 'a BMP character', 'caf?', 'café', 1, true ],
		[ 'an astral character', 'caf?', 'caf😀', 2, false ],
		[ 'an astral character, given two', 'caf??', 'caf😀', 2, true ],
	] )(
		'consumes one UTF-16 code unit per ? against %s',
		( label, pattern, subject, units, expected ) => {
			/*
			 * The documented divergence from `Popkit\Url_Matcher`, pinned as
			 * behavior rather than left as a claim in a comment. PHP walks bytes
			 * and answers this table differently: `café` is 5 bytes there, so it
			 * needs `caf??`, and `caf😀` is 7, so it needs `caf????`. Neither
			 * engine's `?` is "one character" — see referrer.js -> Fidelity to
			 * `Popkit\Url_Matcher` for why the two are documented apart rather
			 * than aligned downward onto bytes.
			 *
			 * The length assertion is the part that would notice a silent
			 * rewrite: a walk changed to code points would keep every row above
			 * green except the astral pair, which is exactly the change most
			 * likely to be made by accident while "fixing" emoji handling.
			 */
			expect( subject.slice( 3 ) ).toHaveLength( units );
			expect( urlMatches( 'glob', pattern, subject ) ).toBe( expected );
		}
	);

	it( 'completes in bounded time on input that ruins a naive translation', () => {
		const pattern = 'a*'.repeat( 12 ) + 'b';
		const subject = 'a'.repeat( 240 );
		const started = Date.now();

		expect( urlMatches( 'glob', pattern, subject ) ).toBe( false );

		/*
		 * `a*a*a*…b` against a long run of `a` is the textbook catastrophic
		 * backtracking case: translated to `a.*a.*…b` it explores an exponential
		 * number of splits and does not return. The two-pointer walk commits
		 * every star but the most recent one, so this is a few thousand
		 * comparisons. The bound is enormously generous on purpose — it is here
		 * to separate "microseconds" from "never", not to measure anything.
		 */
		expect( Date.now() - started ).toBeLessThan( 1000 );
	} );

	it( 'refuses a value longer than the 255-character cap', () => {
		const subject = 'a'.repeat( 300 );

		expect( urlMatches( 'prefix', 'a'.repeat( 255 ), subject ) ).toBe(
			true
		);

		// Not a throw. `Popkit\Url_Matcher` answers the same way, and a
		// targeting miss during evaluation must never become an exception in
		// somebody's page.
		expect( urlMatches( 'prefix', 'a'.repeat( 256 ), subject ) ).toBe(
			false
		);
		expect( urlMatches( 'glob', '*'.repeat( 256 ), subject ) ).toBe(
			false
		);
	} );

	it( 'applies the same cap through the referrer condition', () => {
		useReferrer( 'https://e.test/' + 'a'.repeat( 248 ) );

		const atCap = 'e.test/' + 'a'.repeat( 248 );

		expect( atCap ).toHaveLength( 255 );
		expect( referrer.evaluate( { match: 'exact', value: atCap } ) ).toBe(
			true
		);

		useReferrer( 'https://e.test/' + 'a'.repeat( 249 ) );

		const overCap = 'e.test/' + 'a'.repeat( 249 );

		expect( overCap ).toHaveLength( 256 );
		expect( referrer.evaluate( { match: 'exact', value: overCap } ) ).toBe(
			false
		);
	} );

	it( 'is what the referrer condition actually calls', () => {
		useReferrer( 'https://example.org/a.c' );

		// Same subject, same modes, same answers. If `referrer.evaluate()` ever
		// disagrees with `urlMatches()` there are two matchers in the bundle,
		// which is the thing the module header promises there are not.
		for ( const match of [ 'exact', 'prefix', 'contains', 'glob' ] ) {
			for ( const value of [ 'example.org/a.c', 'example.org/abc' ] ) {
				expect( referrer.evaluate( { match, value } ) ).toBe(
					urlMatches( match, value, 'example.org/a.c' )
				);
			}
		}
	} );

	it( 'declares that it needs no context', () => {
		expect( referrer.needsContext ).toBe( false );
		expect( referrer.key ).toBe( 'referrer' );
	} );
} );

describe( 'utm', () => {
	it( 'matches a parameter that is present and equal', () => {
		visit( '/appeal?utm_source=newsletter&utm_medium=email' );

		expect(
			utm.evaluate( { param: 'utm_source', value: 'newsletter' } )
		).toBe( true );
		expect( utm.evaluate( { param: 'utm_medium', value: 'email' } ) ).toBe(
			true
		);
	} );

	it( 'does not match a parameter that is absent', () => {
		visit( '/appeal?utm_medium=email' );

		/*
		 * `false` rather than `undefined`, and deliberately: "did not arrive
		 * from this campaign" is a rule an author writes, as `negate: true` on
		 * exactly this rule, and a visitor with no tag at all is the largest
		 * group it is meant to include.
		 */
		expect(
			utm.evaluate( { param: 'utm_source', value: 'newsletter' } )
		).toBe( false );
	} );

	it( 'does not match a different value', () => {
		visit( '/appeal?utm_source=twitter' );

		expect(
			utm.evaluate( { param: 'utm_source', value: 'newsletter' } )
		).toBe( false );
	} );

	it( 'compares case-sensitively, unlike referrer', () => {
		visit( '/appeal?utm_campaign=Spring' );

		// A host name is genuinely case-insensitive; a campaign tag is an
		// identifier the author pasted into two places, and folding case here
		// would hide which of the two is wrong.
		expect(
			utm.evaluate( { param: 'utm_campaign', value: 'Spring' } )
		).toBe( true );
		expect(
			utm.evaluate( { param: 'utm_campaign', value: 'spring' } )
		).toBe( false );
	} );

	it.each( [
		[ 'a plus', '/appeal?utm_campaign=spring+appeal' ],
		[ 'a percent escape', '/appeal?utm_campaign=spring%20appeal' ],
	] )( 'decodes a space written as %s', ( label, url ) => {
		visit( url );

		expect(
			utm.evaluate( { param: 'utm_campaign', value: 'spring appeal' } )
		).toBe( true );
	} );

	it( 'decodes exactly once', () => {
		visit( '/appeal?utm_campaign=spring%2520appeal' );

		// A double decode is the classic filter bypass, and here it would also
		// silently merge two genuinely different campaign tags.
		expect(
			utm.evaluate( { param: 'utm_campaign', value: 'spring%20appeal' } )
		).toBe( true );
		expect(
			utm.evaluate( { param: 'utm_campaign', value: 'spring appeal' } )
		).toBe( false );
	} );

	it( 'resolves a repeated parameter to its first occurrence', () => {
		visit( '/appeal?utm_source=newsletter&utm_source=twitter' );

		expect(
			utm.evaluate( { param: 'utm_source', value: 'newsletter' } )
		).toBe( true );
		expect(
			utm.evaluate( { param: 'utm_source', value: 'twitter' } )
		).toBe( false );
	} );

	it( 'distinguishes a present-but-empty parameter from an absent one', () => {
		visit( '/appeal?utm_source=' );

		expect( utm.evaluate( { param: 'utm_source', value: '' } ) ).toBe(
			true
		);

		visit( '/appeal' );

		expect( utm.evaluate( { param: 'utm_source', value: '' } ) ).toBe(
			false
		);
	} );

	it( 'reads any parameter name, not only the utm_ family', () => {
		visit( '/appeal?ref=board-email' );

		expect( utm.evaluate( { param: 'ref', value: 'board-email' } ) ).toBe(
			true
		);
	} );

	it( 'reads the query string and not the fragment', () => {
		visit( '/appeal#utm_source=newsletter' );

		expect(
			utm.evaluate( { param: 'utm_source', value: 'newsletter' } )
		).toBe( false );
	} );

	it( 'does not match a value that is not a string', () => {
		visit( '/appeal?utm_source=5' );

		// `get()` returns a string or null, so a non-string simply fails to
		// compare. A decision, not an unreadable rule.
		expect( utm.evaluate( { param: 'utm_source', value: 5 } ) ).toBe(
			false
		);
	} );

	it.each( [
		[ 'no values at all', undefined ],
		[ 'no param', { value: 'newsletter' } ],
		[ 'an empty param', { param: '', value: 'newsletter' } ],
		[ 'a numeric param', { param: 5, value: 'newsletter' } ],
		[ 'a null param', { param: null, value: 'newsletter' } ],
	] )( 'cannot answer given %s', ( label, values ) => {
		visit( '/appeal?utm_source=newsletter' );

		expect( utm.evaluate( values ) ).toBeUndefined();
	} );

	it( 'declares that it needs no context', () => {
		expect( utm.needsContext ).toBe( false );
		expect( utm.key ).toBe( 'utm' );
	} );
} );

describe( 'visit_history', () => {
	it( 'reports a clean profile as first_time', async () => {
		const visitHistory = await loadVisitHistory();

		expect( visitHistory.evaluate( { state: 'first_time' } ) ).toBe( true );
	} );

	it( 'does not report the first visitor as returning', async () => {
		const area = createStorage();

		useStorage( area );

		const visitHistory = await loadVisitHistory();

		/*
		 * The ordering rule, asserted from the side that catches the bug. Read
		 * first, then mark: a module that marked the visit before reading it
		 * would answer `true` here, every visitor would be "returning", and the
		 * one the condition exists to find would never match. The bug is
		 * invisible in manual testing, because the second pageview looks right.
		 */
		expect( visitHistory.evaluate( { state: 'returning' } ) ).toBe( false );

		// And the mark was still written, which is what makes the next pageview
		// answer differently.
		expect( area.entries.get( VISITED_KEY ) ).toBeDefined();
	} );

	it( 'reports the same visitor as returning on the next pageview', async () => {
		const area = createStorage();

		useStorage( area );

		const first = await loadVisitHistory();

		expect( first.evaluate( { state: 'first_time' } ) ).toBe( true );
		expect( first.evaluate( { state: 'returning' } ) ).toBe( false );

		// A fresh module instance over the same storage is the next pageview.
		const second = await loadVisitHistory();

		expect( second.evaluate( { state: 'returning' } ) ).toBe( true );
		expect( second.evaluate( { state: 'first_time' } ) ).toBe( false );
	} );

	it( 'gives every rule on one pageview the same answer', async () => {
		const visitHistory = await loadVisitHistory();

		/*
		 * The reading is memoized for the life of the pageview. Without that,
		 * the first `visit_history` rule evaluated would write the mark and the
		 * second would read it back — so two popups on one page would disagree
		 * about whether this visitor is new, and which one won would depend on
		 * emitted order and on whether a popup waited for the context response.
		 */
		expect( visitHistory.evaluate( { state: 'first_time' } ) ).toBe( true );
		expect( visitHistory.evaluate( { state: 'first_time' } ) ).toBe( true );
		expect( visitHistory.evaluate( { state: 'returning' } ) ).toBe( false );
	} );

	it( 'treats presence as the whole record and never parses it', async () => {
		const area = createStorage();

		area.entries.set( VISITED_KEY, '0' );
		useStorage( area );

		const visitHistory = await loadVisitHistory();

		// `'0'` is falsy, `''` is falsy, and neither is a reason to forget a
		// visitor. There is no shape here to be malformed.
		expect( visitHistory.evaluate( { state: 'returning' } ) ).toBe( true );
	} );

	it( 'writes no cookie', async () => {
		const before = window.document.cookie;
		const visitHistory = await loadVisitHistory();

		visitHistory.evaluate( { state: 'first_time' } );

		// A cookie would be sent with every request, vary the cached response,
		// and drag the plugin into consent-banner scope in the EU.
		expect( window.document.cookie ).toBe( before );
	} );

	it( 'does not throw out of evaluate when storage access throws', async () => {
		// Loaded before the getter is poisoned. The module reads storage lazily,
		// on its first evaluation, but jest's own module-registry reset touches
		// `window.localStorage` — so a throwing getter installed first would
		// take out the loader rather than the module under test.
		const visitHistory = await loadVisitHistory();
		let result;

		useThrowingStorage();

		/*
		 * Private browsing and storage-disabled profiles raise on the property
		 * access itself, before any method is called. An exception escaping here
		 * would abort the controller's evaluation loop and take every other
		 * popup on the page down with this one.
		 */
		expect( () => {
			result = visitHistory.evaluate( { state: 'first_time' } );
		} ).not.toThrow();

		expect( result ).toBeUndefined();
		expect(
			visitHistory.evaluate( { state: 'returning' } )
		).toBeUndefined();
	} );

	it( 'cannot answer when storage reads but refuses to write', async () => {
		useStorage( createStorage( { readOnly: true } ) );

		const visitHistory = await loadVisitHistory();

		/*
		 * A profile that permits the read and refuses the write is reported as
		 * unresolvable rather than as first-time. The mark is what makes the
		 * answer true next time, so a reading nothing can follow up would tell
		 * every visit that it was the first one — a `first_time` popup that
		 * shows on every pageview forever.
		 */
		expect(
			visitHistory.evaluate( { state: 'first_time' } )
		).toBeUndefined();
	} );

	it.each( [
		[ 'no values at all', undefined ],
		[ 'no state', {} ],
		[ 'a state that is neither', { state: 'maybe' } ],
		[ 'a numeric state', { state: 1 } ],
	] )( 'cannot answer given %s', async ( label, values ) => {
		const visitHistory = await loadVisitHistory();

		// Compared by name rather than derived from one boolean, so an
		// unrecognized state is unreadable rather than silently `first_time`.
		expect( visitHistory.evaluate( values ) ).toBeUndefined();
	} );

	it( 'declares that it needs no context', async () => {
		const visitHistory = await loadVisitHistory();

		expect( visitHistory.needsContext ).toBe( false );
		expect( visitHistory.key ).toBe( 'visit_history' );
	} );
} );

describe( 'the registry stage 7 resolves a rule against', () => {
	it( 'holds the five v1 conditions and nothing else', () => {
		/*
		 * Sorted, and compared as a whole set rather than key by key. A missing
		 * key is a condition that can never match — the rule resolves to nothing
		 * and the controller denies it — and an extra key is an evaluator running
		 * for a `type` no PHP registration validates values against.
		 */
		expect( [ ...CLIENT_CONDITIONS.keys() ].sort() ).toEqual( [
			'device',
			'referrer',
			'user_state',
			'utm',
			'visit_history',
		] );
	} );

	it.each( [
		[ 'device', device ],
		[ 'user_state', userState ],
		[ 'referrer', referrer ],
		[ 'utm', utm ],
	] )( 'maps %s to the module this file tested', ( key, expected ) => {
		// Identity, not shape. Everything above imports the module directly, so
		// a registry entry pointing at some other object — or at a copy that
		// stopped being updated — would leave all of it green and every popup of
		// that type judged by something no test has seen.
		expect( CLIENT_CONDITIONS.get( key ) ).toBe( expected );
	} );

	it( 'maps visit_history to a module implementing the same contract', () => {
		const registered = CLIENT_CONDITIONS.get( 'visit_history' );

		// Identity is not available for this one: its tests load it through a
		// fresh module registry, deliberately, because a module instance is a
		// pageview. The contract is asserted instead.
		expect( registered.key ).toBe( 'visit_history' );
		expect( registered.needsContext ).toBe( false );
		expect( typeof registered.evaluate ).toBe( 'function' );
	} );

	it( 'does not hold user_role, which is not a v1 condition', () => {
		/*
		 * `docs/data-model.md` -> Deferred to 1.1. Roles are absent from the
		 * context payload, so an evaluator here could only guess, and a guess
		 * about who is a member is the guess this plugin least wants to make. A
		 * stored `user_role` rule is emitted tagged `unknown` and denied, which
		 * `stage7.test.js` asserts from the controller's side.
		 */
		expect( CLIENT_CONDITIONS.has( 'user_role' ) ).toBe( false );
	} );

	it( 'finds nothing for a key inherited from Object.prototype', () => {
		// A rule's `type` is author data arriving over the wire, and this is why
		// the registry is a Map. On an object literal these would resolve to
		// something with no `evaluate`, which the controller denies anyway — but
		// only because it checks twice.
		expect( CLIENT_CONDITIONS.get( 'constructor' ) ).toBeUndefined();
		expect( CLIENT_CONDITIONS.get( 'toString' ) ).toBeUndefined();
		expect( CLIENT_CONDITIONS.get( '__proto__' ) ).toBeUndefined();
	} );
} );
