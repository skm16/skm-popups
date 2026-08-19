/**
 * popkit — frequency capping.
 *
 * Pipeline stage 9 (docs/CLAUDE.md -> Architecture invariants): how often a
 * visitor may see a popup they are otherwise eligible for.
 *
 * **Browser storage only.** No cookies, no user meta, no server-side counters.
 * A cookie would vary the cached response and drag the plugin into
 * consent-banner scope in the EU; a server-side counter would mean a database
 * write on a frontend pageview, which is on the ask-first list.
 *
 * ## The one deliberate fail-open
 *
 * Every storage access here is wrapped in try/catch, and when storage is
 * unavailable the cap is treated as **unmet** so the popup shows. Private
 * browsing modes, storage-disabled browsers, and blocked third-party contexts
 * throw on the *property access* itself, not merely on read and write, so the
 * getter is inside the try as well.
 *
 * This is the single place in a system that otherwise fails closed where an
 * unknown answer widens rather than narrows the outcome, and the asymmetry is
 * intentional. Frequency capping is a courtesy, not a targeting boundary:
 * getting it wrong shows a visitor a popup one more time than the author
 * intended. Failing closed here would instead suppress *every* popup for every
 * privacy-mode visitor — silently, sitewide, and indistinguishably from the
 * plugin being broken. Conditions fail closed because getting those wrong
 * exposes targeted content to the wrong audience; nothing of the sort is at
 * stake in a seen-count.
 *
 * The same reasoning governs malformed input: an unrecognized mode, a missing
 * `days` value, or an unreadable stored record all leave the cap unmet.
 *
 * @see docs/data-model.md -> Frequency
 */

/**
 * Prefix for the per-popup storage key. The full key is `popkit:seen:{id}`.
 *
 * @type {string}
 */
const KEY_PREFIX = 'popkit:seen:';

/**
 * Milliseconds in a day, for the rolling `once_per_days` window.
 *
 * @type {number}
 */
const MS_PER_DAY = 86400000;

/**
 * Reads the device clock.
 *
 * The corrected clock from clock.js is deliberately **not** used here, and this
 * is the one part of the runtime where the device clock is the right answer.
 * The corrected clock only exists when the context endpoint was fetched, and
 * `needsContext` is false for any page whose popups have no schedule and no
 * `user_state` rule — so a capped popup frequently has no corrected clock
 * available at all. Making frequency capping depend on a network round trip
 * would trade a courtesy feature for a request on every pageview.
 *
 * A stored timestamp is also compared across sessions and page loads, where a
 * monotonic reading has no meaning: `performance.now()` restarts at zero on
 * every navigation.
 *
 * Nothing here is ever used for schedule evaluation, which is the only place
 * clock skew can change what a visitor is shown. See schedule.js.
 *
 * @return {number} Epoch milliseconds from the device clock.
 */
function deviceNow() {
	return Date.now();
}

/**
 * Returns a Web Storage area, or null when the browser refuses access.
 *
 * The property access is inside the try because that is what throws: in a
 * blocked context, `window.localStorage` raises a SecurityError before any
 * method is called on it.
 *
 * @param {boolean} persistent True for localStorage, false for sessionStorage.
 * @return {Storage|null} The storage area, or null when unavailable.
 */
function storageArea( persistent ) {
	try {
		return persistent ? window.localStorage : window.sessionStorage;
	} catch {
		return null;
	}
}

/**
 * Reads and validates a popup's stored record.
 *
 * Anything unreadable — storage unavailable, absent key, invalid JSON, or a
 * payload without a finite `at` — is reported as "no record", which leaves the
 * cap unmet. Another script sharing the origin can write arbitrary values under
 * this key, so the parsed shape is checked rather than trusted.
 *
 * @param {boolean}       persistent True to read localStorage, false for
 *                                   sessionStorage.
 * @param {number|string} popupId    Popup identifier.
 * @return {Object|null} `{ at, converted }`, or null when there is no usable
 *                       record.
 */
function readRecord( persistent, popupId ) {
	const area = storageArea( persistent );

	if ( null === area ) {
		return null;
	}

	try {
		const raw = area.getItem( KEY_PREFIX + popupId );

		if ( ! raw ) {
			return null;
		}

		const parsed = JSON.parse( raw );

		if (
			! parsed ||
			'object' !== typeof parsed ||
			! Number.isFinite( parsed.at )
		) {
			return null;
		}

		return {
			at: parsed.at,
			converted: true === parsed.converted,
		};
	} catch {
		return null;
	}
}

/**
 * Writes a popup's record, swallowing any storage failure.
 *
 * A quota error, a disabled storage area, or a serialization failure must never
 * surface: the visitor has already been shown the popup by the time this runs,
 * and an exception here would abort the caller mid-open.
 *
 * @param {boolean}       persistent True to write localStorage, false for
 *                                   sessionStorage.
 * @param {number|string} popupId    Popup identifier.
 * @param {Object}        record     `{ at, converted }` to store.
 * @return {void}
 */
function writeRecord( persistent, popupId, record ) {
	const area = storageArea( persistent );

	if ( null === area ) {
		return;
	}

	try {
		area.setItem( KEY_PREFIX + popupId, JSON.stringify( record ) );
	} catch {
		// Deliberately silent — see the fail-open note at the top of the file.
	}
}

/**
 * Reads a popup's record from whichever storage area holds one.
 *
 * Records are written to both areas (see `recordSeen`), so this normally reads
 * the persistent copy; the session copy is the fallback for a browser that
 * permits sessionStorage while blocking localStorage.
 *
 * @param {number|string} popupId Popup identifier.
 * @return {Object} `{ local, session }`, either of which may be null.
 */
function readRecords( popupId ) {
	return {
		local: readRecord( true, popupId ),
		session: readRecord( false, popupId ),
	};
}

/**
 * Reports whether a popup's frequency cap currently suppresses it.
 *
 * | Mode               | Storage        | Behavior                             |
 * |--------------------|----------------|---------------------------------------|
 * | `always`           | none           | Never suppressed by the mode itself.   |
 * | `once_per_session` | sessionStorage | Suppressed once seen this session.     |
 * | `once_per_days`    | localStorage   | Suppressed for a rolling `days` window |
 * |                    |                | measured from the last recorded open.  |
 * | `once_ever`        | localStorage   | Suppressed permanently once seen.      |
 *
 * The storage column is a contract in both directions: `always` reads nothing
 * here, and `recordSeen` writes nothing for it.
 *
 * `on_convert: "suppress_forever"` — the default, and the reading applied when
 * the key is absent — is checked before the mode and overrides it: a visitor
 * who has just converted is suppressed permanently regardless of mode, because
 * conversion is the strongest possible signal the popup has done its job and
 * somebody who just donated should not be asked again next week.
 * `on_convert: "none"` records the conversion for integrations and lets the
 * mode continue to govern.
 *
 * The keys read from `frequency` stay snake_case: this object is the stored
 * shape defined in `data-model.md`, emitted verbatim by PHP, not a JS
 * identifier.
 *
 * `until_dismissed` and `until_converted` are not modes and are not handled —
 * both were removed from the spec, the first for describing three incompatible
 * behaviors under one name and the second for being `once_ever` with extra
 * steps. An unrecognized mode leaves the cap unmet.
 *
 * @param {number|string}         popupId   Popup identifier.
 * @param {Object|null|undefined} frequency Frequency object from the emitted
 *                                          config.
 * @return {boolean} True when the popup must not be shown.
 */
export function isSuppressed( popupId, frequency ) {
	const mode = frequency?.mode ?? 'always';
	const onConvert = frequency?.on_convert ?? 'suppress_forever';
	const { local, session } = readRecords( popupId );

	if (
		'none' !== onConvert &&
		( true === local?.converted || true === session?.converted )
	) {
		return true;
	}

	if ( 'once_per_session' === mode ) {
		return null !== session;
	}

	if ( 'once_ever' === mode ) {
		return null !== local;
	}

	if ( 'once_per_days' === mode ) {
		if ( null === local ) {
			return false;
		}

		const days = Number( frequency?.days );

		if ( ! Number.isFinite( days ) || 1 > days ) {
			return false;
		}

		const elapsed = deviceNow() - local.at;

		// A record dated in the future means the device clock moved backwards
		// since it was written. The window cannot be measured against a clock
		// that disagrees with the record, so the cap is left unmet rather than
		// suppressing the popup until the phantom date arrives.
		return 0 <= elapsed && elapsed < days * MS_PER_DAY;
	}

	return false;
}

/**
 * Records that a popup was seen.
 *
 * **Call this only after a successful, non-canceled open** — after
 * `popkit:before-open` completed without `preventDefault()` and after the
 * dialog is actually displayed. Never on eligibility, never on arming, never on
 * trigger fire. This is a spec rule, not an implementation detail: an aborted
 * open is invisible to the visitor, so recording one would spend their single
 * impression on something they never saw.
 *
 * ## `always` writes nothing
 *
 * `data-model.md` gives that mode a storage of "none", and it is the only mode
 * whose branch in `isSuppressed` never reads a seen record. Writing one is work
 * on every open that nothing ever reads, and the entry outlives the setting
 * that produced it: an author who later switches an uncapped popup to
 * `once_ever` would find it already suppressed for every visitor who saw it
 * while it was uncapped, by impressions the cap was never meant to count.
 *
 * The mode has to arrive from the caller — the storage key carries no copy of
 * it — so this takes the whole frequency object rather than a mode string,
 * matching `isSuppressed` and leaving the reading of it in one file.
 *
 * ## Why the two defaults differ
 *
 * `isSuppressed` reads an absent mode as `always`; this function does not, and
 * writes. The defaults disagree on purpose, because each one is the harmless
 * direction for its own side: an unnecessary record only ever leaves a cap
 * unmet, while a missing record under `once_per_session` uncaps the popup
 * outright. A call that omits `frequency` therefore behaves exactly as this
 * function did before the argument existed.
 *
 * ## Everything else
 *
 * The record is written to both storage areas. The mode decides which area
 * `isSuppressed` reads, and an author may change it after a visitor has already
 * been counted; the duplicate costs one small key and keeps the cap correct
 * across that edit.
 *
 * `at` is refreshed on every open, which is what makes the `once_per_days`
 * window roll from the *last* open. An existing conversion is preserved: a
 * later impression must never downgrade a converted record and undo permanent
 * suppression.
 *
 * @param {number|string}         popupId   Popup identifier.
 * @param {Object|null|undefined} frequency Frequency object from the emitted
 *                                          config. Omitting it writes.
 * @return {void}
 */
export function recordSeen( popupId, frequency ) {
	if ( 'always' === frequency?.mode ) {
		return;
	}

	const { local, session } = readRecords( popupId );

	const record = {
		at: deviceNow(),
		converted: true === local?.converted || true === session?.converted,
	};

	writeRecord( true, popupId, record );
	writeRecord( false, popupId, record );
}

/**
 * Records that a popup converted.
 *
 * Called from the `popkit:convert` handler. The record is upgraded in place so
 * that `isSuppressed` can apply `on_convert` — under the default
 * `suppress_forever` this is permanent suppression regardless of mode, which is
 * why the flag is written to localStorage and not only to the session.
 *
 * The original `at` is kept: it timestamps the impression, and moving it
 * forward would extend a `once_per_days` window from the conversion rather than
 * from the open. A conversion arriving with no record on file — an integration
 * dispatching the event on a later pageview, say — writes one, so that
 * `suppress_forever` still holds.
 *
 * This deliberately takes no `frequency` and skips nothing for `always`, unlike
 * `recordSeen`. `on_convert` is checked *before* the mode and overrides it, so a
 * conversion suppresses an `always` popup exactly as it suppresses a capped one
 * — the conversion flag is read under every mode, and a mode-based skip here
 * would quietly disable `suppress_forever` on the uncapped default.
 *
 * @param {number|string} popupId Popup identifier.
 * @return {void}
 */
export function recordConvert( popupId ) {
	const { local, session } = readRecords( popupId );

	const record = {
		at: local?.at ?? session?.at ?? deviceNow(),
		converted: true,
	};

	writeRecord( true, popupId, record );
	writeRecord( false, popupId, record );
}
