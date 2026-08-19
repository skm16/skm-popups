/**
 * Unit tests for frequency capping.
 *
 * Frequency capping is the one deliberate fail-open in a system that otherwise
 * fails closed. Every path here that cannot get an answer — storage refusing
 * access, storage refusing a read, a corrupt record, a mode nobody registered —
 * must leave the cap **unmet** so the popup shows, and must do it without
 * letting an exception escape. Failing closed instead would silently suppress
 * every popup for every private-browsing visitor, which is indistinguishable
 * from the plugin being broken.
 *
 * ## How the storage assertions are framed
 *
 * `recordSeen()` mirrors its record into *both* storage areas, and
 * `isSuppressed()` reads both, so counting calls alone cannot prove which area
 * governs a mode. The tests below seed exactly one area at a time: if
 * `once_per_session` is governed by `sessionStorage`, a record present only in
 * `localStorage` must not suppress it, and vice versa for the persistent modes.
 * Call assertions on the stand-in storage objects then pin down the exact key.
 *
 * @see docs/data-model.md -> Frequency
 * @see docs/CLAUDE.md -> Cache safety
 */

/* global describe, it, expect, jest, beforeEach, afterEach */

import {
	isSuppressed,
	recordConvert,
	recordSeen,
} from '../../src/frontend/frequency.js';

/**
 * Popup identifier used throughout.
 *
 * @type {number}
 */
const POPUP_ID = 42;

/**
 * The storage key the module is contracted to use for that popup.
 *
 * @type {string}
 */
const KEY = 'popkit:seen:42';

/**
 * Milliseconds in a day.
 *
 * @type {number}
 */
const DAY = 86400000;

/**
 * Fixed device-clock reading for each test.
 *
 * @type {number}
 */
const NOW = 1766000000000;

/**
 * Property descriptors jsdom installed, captured before anything is replaced.
 *
 * @type {Object}
 */
const ORIGINAL_DESCRIPTORS = {
	localStorage: Object.getOwnPropertyDescriptor( window, 'localStorage' ),
	sessionStorage: Object.getOwnPropertyDescriptor( window, 'sessionStorage' ),
};

/**
 * Stand-in for `window.localStorage`.
 *
 * @type {Object}
 */
let local;

/**
 * Stand-in for `window.sessionStorage`.
 *
 * @type {Object}
 */
let session;

/**
 * Current value reported by the mocked device clock.
 *
 * @type {number}
 */
let clockMs = NOW;

/**
 * Creates an in-memory stand-in for a Web Storage area.
 *
 * The methods are spies so a test can assert the exact key touched, and the
 * backing map is exposed so a test can seed or inspect a record without going
 * through the module under test.
 *
 * @return {Object} Storage-like object with an `entries` map attached.
 */
function createStorage() {
	const entries = new Map();

	return {
		entries,
		getItem: jest.fn( ( key ) => {
			return entries.has( key ) ? entries.get( key ) : null;
		} ),
		setItem: jest.fn( ( key, value ) => {
			entries.set( key, String( value ) );
		} ),
		removeItem: jest.fn( ( key ) => {
			entries.delete( key );
		} ),
		clear: jest.fn( () => {
			entries.clear();
		} ),
	};
}

/**
 * Installs a storage stand-in on `window` under the given name.
 *
 * @param {string} name Either `localStorage` or `sessionStorage`.
 * @param {Object} area Stand-in to expose.
 * @return {void}
 */
function useStorage( name, area ) {
	Object.defineProperty( window, name, {
		configurable: true,
		get: () => area,
	} );
}

/**
 * Makes the storage *property access itself* throw.
 *
 * This is what private-browsing and storage-disabled browsers actually do: the
 * SecurityError is raised on `window.localStorage`, before any method is called
 * on it, which is why the module's try/catch has to wrap the getter.
 *
 * @param {string} name Either `localStorage` or `sessionStorage`.
 * @return {void}
 */
function useThrowingStorage( name ) {
	Object.defineProperty( window, name, {
		configurable: true,
		get() {
			throw new Error( 'SecurityError: storage is not available' );
		},
	} );
}

/**
 * Restores the storage property jsdom originally installed.
 *
 * @param {string} name Either `localStorage` or `sessionStorage`.
 * @return {void}
 */
function restoreStorage( name ) {
	const descriptor = ORIGINAL_DESCRIPTORS[ name ];

	if ( descriptor ) {
		Object.defineProperty( window, name, descriptor );
		return;
	}

	delete window[ name ];
}

/**
 * Writes a record straight into a storage stand-in, bypassing the module.
 *
 * @param {Object} area      Storage stand-in.
 * @param {Object} record    Record to store.
 * @param {string} [rawJson] Raw string to store instead, for corruption tests.
 * @return {void}
 */
function seed( area, record, rawJson ) {
	area.entries.set(
		KEY,
		undefined === rawJson ? JSON.stringify( record ) : rawJson
	);
}

/**
 * Reads back what the module wrote to a storage stand-in.
 *
 * @param {Object} area Storage stand-in.
 * @return {Object|null} Parsed record, or null when nothing was written.
 */
function stored( area ) {
	const raw = area.entries.get( KEY );

	return undefined === raw ? null : JSON.parse( raw );
}

beforeEach( () => {
	local = createStorage();
	session = createStorage();
	clockMs = NOW;

	useStorage( 'localStorage', local );
	useStorage( 'sessionStorage', session );

	jest.spyOn( Date, 'now' ).mockImplementation( () => clockMs );
} );

afterEach( () => {
	jest.restoreAllMocks();

	restoreStorage( 'localStorage' );
	restoreStorage( 'sessionStorage' );
} );

describe( 'isSuppressed -> always', () => {
	it( 'never suppresses, with or without a record on file', () => {
		expect( isSuppressed( POPUP_ID, { mode: 'always' } ) ).toBe( false );

		seed( local, { at: NOW, converted: false } );
		seed( session, { at: NOW, converted: false } );

		expect( isSuppressed( POPUP_ID, { mode: 'always' } ) ).toBe( false );
	} );

	it.each( [
		[ 'a null frequency', null ],
		[ 'an undefined frequency', undefined ],
		[ 'an empty frequency object', {} ],
		[ 'an unregistered mode', { mode: 'until_dismissed' } ],
	] )( 'leaves the cap unmet given %s', ( label, frequency ) => {
		seed( local, { at: NOW, converted: false } );
		seed( session, { at: NOW, converted: false } );

		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
	} );
} );

describe( 'isSuppressed -> once_per_session', () => {
	/**
	 * Frequency config under test.
	 *
	 * @type {Object}
	 */
	const frequency = { mode: 'once_per_session', on_convert: 'none' };

	it( 'does not suppress before anything has been seen', () => {
		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
	} );

	it( 'is governed by sessionStorage', () => {
		seed( session, { at: NOW, converted: false } );

		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( true );
		expect( session.getItem ).toHaveBeenCalledWith( KEY );
	} );

	it( 'ignores a record that exists only in localStorage', () => {
		// The decisive assertion: a persistent record must not satisfy a
		// per-session cap, or the mode would behave as `once_ever`.
		seed( local, { at: NOW, converted: false } );

		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
	} );

	it( 'suppresses after the module records an open', () => {
		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );

		recordSeen( POPUP_ID );

		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( true );
	} );

	it( 'still works when only sessionStorage is reachable', () => {
		useThrowingStorage( 'localStorage' );
		seed( session, { at: NOW, converted: false } );

		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( true );
	} );
} );

describe( 'isSuppressed -> once_ever', () => {
	/**
	 * Frequency config under test.
	 *
	 * @type {Object}
	 */
	const frequency = { mode: 'once_ever', on_convert: 'none' };

	it( 'does not suppress before anything has been seen', () => {
		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
	} );

	it( 'is governed by localStorage', () => {
		seed( local, { at: NOW - 400 * DAY, converted: false } );

		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( true );
		expect( local.getItem ).toHaveBeenCalledWith( KEY );
	} );

	it( 'ignores a record that exists only in sessionStorage', () => {
		// A per-session record must not satisfy a permanent cap, or the mode
		// would silently reset every time the browser was restarted.
		seed( session, { at: NOW, converted: false } );

		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
	} );
} );

describe( 'isSuppressed -> once_per_days', () => {
	/**
	 * Frequency config under test — a rolling seven day window.
	 *
	 * @type {Object}
	 */
	const frequency = {
		mode: 'once_per_days',
		days: 7,
		on_convert: 'none',
	};

	it( 'suppresses inside the rolling window', () => {
		seed( local, { at: NOW - 3 * DAY, converted: false } );

		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( true );
		expect( local.getItem ).toHaveBeenCalledWith( KEY );
	} );

	it( 'stops suppressing outside the rolling window', () => {
		seed( local, { at: NOW - 8 * DAY, converted: false } );

		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
	} );

	it( 'treats the far edge of the window as exclusive', () => {
		seed( local, { at: NOW - 7 * DAY + 1, converted: false } );
		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( true );

		seed( local, { at: NOW - 7 * DAY, converted: false } );
		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
	} );

	it( 'is governed by localStorage', () => {
		seed( session, { at: NOW - 3 * DAY, converted: false } );

		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
	} );

	it( 'rolls the window forward from the most recent open', () => {
		seed( local, { at: NOW - 6 * DAY, converted: false } );
		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( true );

		recordSeen( POPUP_ID );
		clockMs = NOW + 6 * DAY;

		// Six days on from the *second* open, still inside the window; had the
		// window kept measuring from the first, it would have lapsed by now.
		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( true );
	} );

	it.each( [
		[ 'a missing days value', { mode: 'once_per_days' } ],
		[ 'a zero days value', { mode: 'once_per_days', days: 0 } ],
		[ 'a negative days value', { mode: 'once_per_days', days: -7 } ],
		[ 'a non-numeric days value', { mode: 'once_per_days', days: 'week' } ],
		[ 'a null days value', { mode: 'once_per_days', days: null } ],
	] )( 'leaves the cap unmet given %s', ( label, config ) => {
		seed( local, { at: NOW, converted: false } );

		expect(
			isSuppressed( POPUP_ID, { ...config, on_convert: 'none' } )
		).toBe( false );
	} );

	it( 'leaves the cap unmet when the record is dated in the future', () => {
		// The device clock moved backwards since the record was written, so the
		// window cannot be measured. Showing the popup once more beats
		// suppressing it until a phantom date arrives.
		seed( local, { at: NOW + DAY, converted: false } );

		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
	} );
} );

describe( 'isSuppressed -> on_convert', () => {
	it.each( [
		[ 'the explicit setting', 'suppress_forever' ],
		[ 'the default when the key is absent', undefined ],
	] )(
		'upgrades a converted record to permanent suppression, with %s',
		( label, onConvert ) => {
			seed( local, { at: NOW - 400 * DAY, converted: true } );

			// Every mode, including the uncapped one, is overridden.
			for ( const mode of [
				'always',
				'once_per_session',
				'once_per_days',
				'once_ever',
			] ) {
				expect(
					isSuppressed( POPUP_ID, {
						mode,
						days: 1,
						on_convert: onConvert,
					} )
				).toBe( true );
			}
		}
	);

	it( 'leaves the mode governing when on_convert is none', () => {
		seed( local, { at: NOW - 400 * DAY, converted: true } );

		expect(
			isSuppressed( POPUP_ID, { mode: 'always', on_convert: 'none' } )
		).toBe( false );
		expect(
			isSuppressed( POPUP_ID, {
				mode: 'once_per_days',
				days: 7,
				on_convert: 'none',
			} )
		).toBe( false );

		// `once_ever` still suppresses, but because a record exists at all —
		// not because of the conversion.
		expect(
			isSuppressed( POPUP_ID, { mode: 'once_ever', on_convert: 'none' } )
		).toBe( true );
	} );

	it( 'honors a conversion recorded only in sessionStorage', () => {
		seed( session, { at: NOW, converted: true } );

		expect( isSuppressed( POPUP_ID, { mode: 'always' } ) ).toBe( true );
	} );

	it( 'survives a conversion recorded through the module', () => {
		recordConvert( POPUP_ID );

		// The flag has to land in localStorage, or "forever" would end with the
		// browser session.
		session.entries.clear();

		expect( isSuppressed( POPUP_ID, { mode: 'always' } ) ).toBe( true );
	} );
} );

describe( 'isSuppressed -> unreachable storage fails open', () => {
	/**
	 * Every mode, so no cap can accidentally survive a storage failure.
	 *
	 * @type {Array}
	 */
	const modes = [
		[ 'always', { mode: 'always' } ],
		[ 'once_per_session', { mode: 'once_per_session' } ],
		[ 'once_per_days', { mode: 'once_per_days', days: 7 } ],
		[ 'once_ever', { mode: 'once_ever' } ],
	];

	it.each( modes )(
		'leaves %s unmet when the storage getter throws',
		( label, frequency ) => {
			useThrowingStorage( 'localStorage' );
			useThrowingStorage( 'sessionStorage' );

			expect( () => isSuppressed( POPUP_ID, frequency ) ).not.toThrow();
			expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
		}
	);

	it.each( modes )(
		'leaves %s unmet when getItem itself throws',
		( label, frequency ) => {
			local.getItem.mockImplementation( () => {
				throw new Error( 'SecurityError' );
			} );
			session.getItem.mockImplementation( () => {
				throw new Error( 'SecurityError' );
			} );

			expect( () => isSuppressed( POPUP_ID, frequency ) ).not.toThrow();
			expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
		}
	);

	it( 'lets recordSeen and recordConvert fail silently', () => {
		useThrowingStorage( 'localStorage' );
		useThrowingStorage( 'sessionStorage' );

		expect( () => recordSeen( POPUP_ID ) ).not.toThrow();
		expect( () => recordConvert( POPUP_ID ) ).not.toThrow();
	} );

	it( 'swallows a write that the storage area rejects', () => {
		// A full quota, which throws on setItem rather than on access.
		local.setItem.mockImplementation( () => {
			throw new Error( 'QuotaExceededError' );
		} );
		session.setItem.mockImplementation( () => {
			throw new Error( 'QuotaExceededError' );
		} );

		expect( () => recordSeen( POPUP_ID ) ).not.toThrow();
		expect( () => recordConvert( POPUP_ID ) ).not.toThrow();
		expect( isSuppressed( POPUP_ID, { mode: 'once_ever' } ) ).toBe( false );
	} );
} );

describe( 'isSuppressed -> corrupt records', () => {
	it.each( [
		[ 'text that is not JSON', 'not json at all' ],
		[ 'an empty string', '' ],
		[ 'a JSON string', '"seen"' ],
		[ 'a JSON number', '1766000000000' ],
		[ 'JSON null', 'null' ],
		[ 'an empty object', '{}' ],
		[ 'an array', '[]' ],
		[ 'a record whose at is a string', '{"at":"yesterday"}' ],
		[ 'a record whose at is null', '{"at":null}' ],
		[ 'a record whose at is NaN-ish', '{"at":"NaN"}' ],
		[ 'a truncated object', '{"at":' ],
	] )( 'treats %s as no record at all', ( label, raw ) => {
		seed( local, null, raw );
		seed( session, null, raw );

		const frequency = { mode: 'once_ever' };

		expect( () => isSuppressed( POPUP_ID, frequency ) ).not.toThrow();
		expect( isSuppressed( POPUP_ID, frequency ) ).toBe( false );
		expect( isSuppressed( POPUP_ID, { mode: 'once_per_session' } ) ).toBe(
			false
		);
	} );

	it( 'coerces a non-boolean converted flag rather than trusting it', () => {
		// Another script on the origin can write anything under this key.
		seed( local, null, '{"at":1766000000000,"converted":"yes"}' );

		expect( isSuppressed( POPUP_ID, { mode: 'always' } ) ).toBe( false );
	} );
} );

describe( 'recordSeen', () => {
	it( 'writes the contracted key and shape to both storage areas', () => {
		recordSeen( POPUP_ID );

		expect( local.setItem ).toHaveBeenCalledWith(
			KEY,
			expect.any( String )
		);
		expect( session.setItem ).toHaveBeenCalledWith(
			KEY,
			expect.any( String )
		);
		expect( stored( local ) ).toEqual( { at: NOW, converted: false } );
		expect( stored( session ) ).toEqual( { at: NOW, converted: false } );
	} );

	it( 'refreshes the timestamp on every open', () => {
		recordSeen( POPUP_ID );

		clockMs = NOW + 3 * DAY;
		recordSeen( POPUP_ID );

		expect( stored( local ).at ).toBe( NOW + 3 * DAY );
	} );

	it( 'never downgrades a converted record', () => {
		// A later impression must not undo permanent suppression.
		seed( local, { at: NOW - DAY, converted: true } );

		recordSeen( POPUP_ID );

		expect( stored( local ).converted ).toBe( true );
		expect( stored( session ).converted ).toBe( true );
	} );

	it( 'writes to the reachable area when the other one throws', () => {
		useThrowingStorage( 'localStorage' );

		recordSeen( POPUP_ID );

		expect( stored( session ) ).toEqual( { at: NOW, converted: false } );
	} );

	it( 'writes nothing at all under the always mode', () => {
		// `data-model.md` -> Frequency gives that mode a storage of "none", and
		// it is the only mode `isSuppressed` never reads a seen record for. The
		// entry would also outlive the setting: switching the popup to
		// `once_ever` later would find it already suppressed by impressions the
		// cap was never meant to count.
		recordSeen( POPUP_ID, {
			mode: 'always',
			days: 7,
			on_convert: 'suppress_forever',
		} );

		expect( local.setItem ).not.toHaveBeenCalled();
		expect( session.setItem ).not.toHaveBeenCalled();
		expect( stored( local ) ).toBeNull();
		expect( stored( session ) ).toBeNull();
	} );

	it.each( [
		[ 'once_per_session' ],
		[ 'once_per_days' ],
		[ 'once_ever' ],
		[ 'a mode nobody registered' ],
	] )( 'still writes under %s', ( mode ) => {
		recordSeen( POPUP_ID, { mode } );

		expect( stored( local ) ).toEqual( { at: NOW, converted: false } );
		expect( stored( session ) ).toEqual( { at: NOW, converted: false } );
	} );

	it.each( [
		[ 'the frequency object is omitted', undefined ],
		[ 'it is null', null ],
		[ 'it carries no mode', {} ],
	] )( 'writes when %s', ( label, frequency ) => {
		// The defaults on the two sides disagree deliberately: `isSuppressed`
		// reads an absent mode as `always`, this writes anyway. A needless record
		// only leaves a cap unmet, while a missing one under `once_per_session`
		// uncaps the popup outright.
		recordSeen( POPUP_ID, frequency );

		expect( stored( local ) ).toEqual( { at: NOW, converted: false } );
	} );
} );

describe( 'recordConvert', () => {
	it( 'upgrades an existing record without moving its timestamp', () => {
		// Moving `at` forward would extend a once_per_days window from the
		// conversion rather than from the open that produced it.
		seed( local, { at: NOW - 2 * DAY, converted: false } );
		seed( session, { at: NOW - 2 * DAY, converted: false } );

		recordConvert( POPUP_ID );

		expect( stored( local ) ).toEqual( {
			at: NOW - 2 * DAY,
			converted: true,
		} );
		expect( stored( session ) ).toEqual( {
			at: NOW - 2 * DAY,
			converted: true,
		} );
	} );

	it( 'writes a record when a conversion arrives with none on file', () => {
		recordConvert( POPUP_ID );

		expect( stored( local ) ).toEqual( { at: NOW, converted: true } );
		expect( local.setItem ).toHaveBeenCalledWith(
			KEY,
			expect.any( String )
		);
	} );

	it( 'records under the always mode, which recordSeen writes nothing for', () => {
		// `on_convert` is checked before the mode and overrides it, so the flag
		// has to reach storage even for an uncapped popup. Skipping the write
		// here for symmetry with `recordSeen` would quietly disable
		// `suppress_forever` on the default mode.
		recordSeen( POPUP_ID, { mode: 'always' } );
		recordConvert( POPUP_ID );

		expect(
			isSuppressed( POPUP_ID, {
				mode: 'always',
				on_convert: 'suppress_forever',
			} )
		).toBe( true );
	} );

	it( 'makes suppress_forever outlast the session', () => {
		recordSeen( POPUP_ID );
		recordConvert( POPUP_ID );

		// A new browser session: sessionStorage starts empty.
		session.entries.clear();

		expect(
			isSuppressed( POPUP_ID, {
				mode: 'once_per_days',
				days: 1,
				on_convert: 'suppress_forever',
			} )
		).toBe( true );
	} );
} );

describe( 'storage keys', () => {
	it.each( [
		[ 7, 'popkit:seen:7' ],
		[ '13', 'popkit:seen:13' ],
	] )( 'namespaces popup %s under %s', ( popupId, key ) => {
		recordSeen( popupId );

		expect( local.setItem ).toHaveBeenCalledWith(
			key,
			expect.any( String )
		);
		expect( session.setItem ).toHaveBeenCalledWith(
			key,
			expect.any( String )
		);
	} );

	it( 'keeps one popup from suppressing another', () => {
		recordSeen( 7 );

		expect( isSuppressed( 7, { mode: 'once_ever' } ) ).toBe( true );
		expect( isSuppressed( 8, { mode: 'once_ever' } ) ).toBe( false );
	} );
} );
