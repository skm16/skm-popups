/**
 * popkit — reading and writing the popup's meta from the editor.
 *
 * Five meta keys hold everything the sidebar edits. Each is registered in PHP
 * with `show_in_rest`, an explicit schema and an `auth_callback`, so the editor
 * reaches them through the ordinary entity plumbing and the server validates
 * every write against the same schema a REST client would face.
 *
 * ## There are no defaults in this file
 *
 * `Popkit\Meta` registers each key with a `default`, so WordPress serves a fully
 * populated object for a popup that has never stored one — `_popkit_frequency`
 * arrives as `{mode: 'always', days: 7, on_convert: 'suppress_forever'}` on a
 * post created a moment ago. There is nothing for the editor to substitute.
 *
 * An earlier revision of this file carried its own copy of all five defaults.
 * It was wrong within the hour: `on_convert` was written as `'none'` where PHP
 * declares `'suppress_forever'`, which would have shown every author the
 * opposite of the behaviour they were about to save. That is the whole argument
 * against the copy — not that it was hard to keep in step, but that when it
 * drifted nothing failed. The editor showed one value, the server stored
 * another, and both were internally consistent.
 *
 * Panels still carry render-time fallbacks (`display?.theme ?? 'inherit'`) for
 * the window before the entity record resolves. Those are local to a control and
 * cannot be saved on their own, which is a different and much smaller risk than
 * a table of record values.
 *
 * @see includes/class-meta.php
 * @see docs/data-model.md -> Meta keys
 */

import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useCallback } from '@wordpress/element';

/**
 * Meta keys the sidebar edits, matching `Popkit\Meta`.
 *
 * @type {Object<string, string>}
 */
export const META = {
	CONDITIONS: '_popkit_conditions',
	TRIGGERS: '_popkit_triggers',
	SCHEDULE: '_popkit_schedule',
	FREQUENCY: '_popkit_frequency',
	DISPLAY: '_popkit_display',
};

/**
 * Reads and writes one popkit meta key.
 *
 * The post type is read from the editor store rather than hardcoded, so this
 * hook cannot be the thing that breaks if the post type is ever filtered.
 *
 * The setter spreads the whole `meta` object rather than writing the single key,
 * which is what `useEntityProp` requires: it replaces the property it is given,
 * so handing it one key would drop every other popkit key on the post.
 *
 * @param {string} key Meta key, from {@link META}.
 * @return {[*, Function]} Current value and a setter.
 */
export function useMeta( key ) {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);

	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );

	const setValue = useCallback(
		( next ) => setMeta( { ...meta, [ key ]: next } ),
		[ meta, setMeta, key ]
	);

	return [ meta?.[ key ], setValue ];
}
