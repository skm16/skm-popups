/**
 * popkit — fetching the condition and trigger registry.
 *
 * `GET /popkit/v1/registry` is the single source of the editor's vocabulary.
 * Nothing about which conditions exist, what fields they take, or which control
 * renders a field is written in this bundle; it is all read from here at
 * runtime. That is the registry invariant in `docs/CLAUDE.md`, and it is why
 * registering a condition in PHP produces working UI with no JavaScript change.
 *
 * ## One fetch per page load, shared by every panel
 *
 * Four panels want the registry and it does not change while the editor is open.
 * The promise is memoized at module scope rather than per component, so mounting
 * the sidebar makes one request no matter how many panels read from it — and a
 * panel that mounts later attaches to the same promise instead of starting a
 * second round trip.
 *
 * The cache holds the promise, not the resolved value. Caching the value would
 * leave a window where several panels mount before the first response lands,
 * each finds an empty cache, and each fires its own request.
 *
 * ## A failed fetch is a state, not an exception
 *
 * {@link useRegistry} reports `error` rather than throwing, because the panels
 * have something useful to do with it: they render the stored configuration
 * read-only and say the vocabulary could not be loaded. Throwing would unmount
 * the sidebar into an error boundary and take the author's unsaved work with it.
 *
 * @see includes/class-rest-schema.php
 */

import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

/**
 * REST path of the registry route.
 *
 * @type {string}
 */
export const REGISTRY_PATH = '/popkit/v1/registry';

/**
 * In-flight or settled request, shared by every consumer.
 *
 * @type {Promise<Object>|null}
 */
let cached = null;

/**
 * Fetches the registry, reusing a request already in flight.
 *
 * @return {Promise<Object>} Registry payload.
 */
export function fetchRegistry() {
	if ( null === cached ) {
		cached = apiFetch( { path: REGISTRY_PATH } );
	}

	return cached;
}

/**
 * Discards the cached request.
 *
 * Exported for tests, which would otherwise see one test's stubbed response in
 * the next test's assertions — the module-level cache outlives a test case.
 *
 * @return {void}
 */
export function clearRegistryCache() {
	cached = null;
}

/**
 * Subscribes a component to the registry.
 *
 * The `canceled` flag is not ceremony. An author who opens the popup editor and
 * navigates away before the response lands would otherwise have `setState`
 * called on an unmounted component, and React reports that as a warning naming
 * this file rather than the navigation that caused it.
 *
 * @return {{registry: Object|null, isLoading: boolean, error: Object|null}} Fetch state.
 */
export function useRegistry() {
	const [ state, setState ] = useState( {
		registry: null,
		isLoading: true,
		error: null,
	} );

	useEffect( () => {
		let canceled = false;

		fetchRegistry().then(
			( registry ) => {
				if ( ! canceled ) {
					setState( { registry, isLoading: false, error: null } );
				}
			},
			( error ) => {
				if ( ! canceled ) {
					setState( { registry: null, isLoading: false, error } );
				}
			}
		);

		return () => {
			canceled = true;
		};
	}, [] );

	return state;
}

/**
 * Groups registry conditions by their declared editor group.
 *
 * Registration order is preserved inside each group — `Rest_Schema` documents
 * that the response is deterministic, and an author who adds a rule twice should
 * find the vocabulary in the same order both times.
 *
 * @param {Object} [registry] Registry payload.
 * @return {Array<{group: string, conditions: Array<Object>}>} Grouped conditions.
 */
export function conditionsByGroup( registry ) {
	const grouped = new Map();

	for ( const condition of Object.values( registry?.conditions ?? {} ) ) {
		const group = condition?.group ?? '';

		if ( ! grouped.has( group ) ) {
			grouped.set( group, [] );
		}

		grouped.get( group ).push( condition );
	}

	return [ ...grouped.entries() ].map( ( [ group, conditions ] ) => ( {
		group,
		conditions,
	} ) );
}

/**
 * Turns one of the registry's label maps into `SelectControl` options.
 *
 * The maps come from `Popkit\Vocabulary`, which is where both the permitted
 * values and the order they are offered in are decided. Key order survives the
 * trip: PHP writes them in order, `wp_json_encode` preserves it, and JavaScript
 * enumerates string keys in insertion order — so a select rendered from this
 * lists its options exactly as the PHP constant declares them, without either
 * side restating the order.
 *
 * That guarantee is specific to keys that are not integer-like. Every popkit
 * vocabulary is keyed by a snake_case token, so none of them are; a map keyed by
 * numbers would come back sorted numerically instead, which is why weekdays are
 * a list in `schedule.js` rather than a map here.
 *
 * An absent map yields no options rather than throwing. A registry served by an
 * older popkit than this bundle is the case that produces one, and a select with
 * nothing in it is recoverable — the stored value is untouched and reappears the
 * moment the two agree again.
 *
 * @param {Object} [map] Value => label.
 * @return {Array<{label: string, value: string}>} Select options.
 */
export function labelOptions( map ) {
	if ( ! map || 'object' !== typeof map ) {
		return [];
	}

	return Object.entries( map ).map( ( [ value, label ] ) => ( {
		value,
		label: String( label ),
	} ) );
}

/**
 * Builds the default `values` object for a condition or trigger.
 *
 * Reads `default` out of each field schema, so a new rule starts at the values
 * the registration declared rather than at empty. A field with no declared
 * default is left absent rather than given an invented one: `undefined` is what
 * the sanitizer treats as "not set", and inventing `''` or `0` would store a
 * value the author never chose.
 *
 * @param {Object} [entry] Registry entry.
 * @return {Object} Default values.
 */
export function defaultValues( entry ) {
	const values = {};

	for ( const [ name, schema ] of Object.entries( entry?.fields ?? {} ) ) {
		if ( undefined !== schema?.default && null !== schema?.default ) {
			values[ name ] = schema.default;
		}
	}

	return values;
}
