/**
 * popkit — block editor entry point, built by wp-scripts (webpack).
 *
 * Registers the popup sidebar: five panels, rendered from the registry fetched
 * over REST, plus the warnings that describe a popup which will save cleanly and
 * then show to nobody.
 *
 * ## Where the panel component comes from
 *
 * `PluginDocumentSettingPanel` moved from `@wordpress/edit-post` to
 * `@wordpress/editor` in WordPress 6.6, and `@wordpress/edit-post` kept a
 * deprecated re-export. This plugin supports 6.5, where only the old location
 * exists, through 7.0, where using the old location logs a deprecation notice on
 * every editor load. Neither import alone covers the range, so both are imported
 * and the modern one is preferred at runtime.
 *
 * The cost is one extra script dependency — `wp-edit-post`, registered in every
 * supported version. The alternative, raising the minimum to 6.6, buys nothing
 * else this plugin needs.
 *
 * ## Nothing here decides what to render
 *
 * No condition, trigger, field or control is named in this file. The panels read
 * the registry and the control map resolves the rest, which is the invariant that
 * makes registering a condition in PHP produce working UI with no change to this
 * bundle. A `switch` on condition key anywhere in `src/editor` would break it.
 *
 * @jsxRuntime classic
 * @jsx createElement
 * @jsxFrag Fragment
 *
 * @see src/editor/controls.js -> Why this file pins the classic JSX runtime
 * @see docs/CLAUDE.md -> Architecture invariants
 */

import { createElement, Fragment } from '@wordpress/element';

import { store as blockEditorStore } from '@wordpress/block-editor';
import { Notice, Spinner } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import * as editPostPackage from '@wordpress/edit-post';
import * as editorPackage from '@wordpress/editor';
import { store as editorStore } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

import { ConditionsPanel } from './panels/conditions.js';
import { DisplayPanel } from './panels/display.js';
import { FrequencyPanel } from './panels/frequency.js';
import { SchedulePanel } from './panels/schedule.js';
import { TriggersPanel } from './panels/triggers.js';
import { useRegistry } from './registry.js';
import { META, useMeta } from './use-meta.js';
import { collectWarnings } from './warnings.js';

/**
 * Post type the sidebar belongs to, matching `Popkit\Post_Type::POST_TYPE`.
 *
 * @type {string}
 */
const POST_TYPE = 'popkit_popup';

/**
 * Plugin name the sidebar registers under.
 *
 * @type {string}
 */
const PLUGIN_NAME = 'popkit-popup-settings';

/**
 * The document settings panel component for whichever WordPress this is.
 *
 * @type {Function|undefined}
 */
const PluginDocumentSettingPanel =
	editorPackage.PluginDocumentSettingPanel ??
	editPostPackage.PluginDocumentSettingPanel;

/**
 * The four warnings, rendered as notices.
 *
 * Shown in the targeting panel rather than a panel of their own, because that is
 * where three of the four are acted on and a separate "problems" panel is a panel
 * authors learn to leave collapsed.
 *
 * @param {Object} props          Props.
 * @param {Object} props.registry Registry payload.
 * @return {JSX.Element|null} Notices, or null when nothing is wrong.
 */
function Warnings( { registry } ) {
	const [ conditions ] = useMeta( META.CONDITIONS );
	const [ schedule ] = useMeta( META.SCHEDULE );

	const { blocks, title } = useSelect(
		( select ) => ( {
			blocks: select( blockEditorStore ).getBlocks(),
			title: select( editorStore ).getEditedPostAttribute( 'title' ),
		} ),
		[]
	);

	const warnings = collectWarnings( {
		blocks,
		title,
		schedule,
		conditions,
		registry,
		now: Date.now(),
	} );

	if ( 0 === warnings.length ) {
		return null;
	}

	return (
		<div className="popkit-warnings">
			{ warnings.map( ( warning ) => (
				<Notice
					key={ warning.id }
					status="warning"
					isDismissible={ false }
					className={ `popkit-warning popkit-warning--${ warning.id }` }
				>
					{ warning.message }
				</Notice>
			) ) }
		</div>
	);
}

/**
 * The targeting panel, including the warnings.
 *
 * @param {Object} props          Props.
 * @param {Object} props.registry Registry payload.
 * @return {JSX.Element} Panel.
 */
function Targeting( { registry } ) {
	const [ conditions, setConditions ] = useMeta( META.CONDITIONS );

	return (
		<PluginDocumentSettingPanel
			name="popkit-conditions"
			title={ __( 'Popup targeting', 'popkit' ) }
			className="popkit-panel"
		>
			<Warnings registry={ registry } />
			<ConditionsPanel
				conditions={ conditions }
				onChange={ setConditions }
				registry={ registry }
			/>
		</PluginDocumentSettingPanel>
	);
}

/**
 * The triggers panel.
 *
 * @param {Object} props          Props.
 * @param {Object} props.registry Registry payload.
 * @return {JSX.Element} Panel.
 */
function Triggers( { registry } ) {
	const [ triggers, setTriggers ] = useMeta( META.TRIGGERS );

	return (
		<PluginDocumentSettingPanel
			name="popkit-triggers"
			title={ __( 'Popup triggers', 'popkit' ) }
			className="popkit-panel"
		>
			<TriggersPanel
				triggers={ triggers }
				onChange={ setTriggers }
				registry={ registry }
			/>
		</PluginDocumentSettingPanel>
	);
}

/**
 * The schedule panel.
 *
 * @return {JSX.Element} Panel.
 */
function Schedule() {
	const [ schedule, setSchedule ] = useMeta( META.SCHEDULE );

	return (
		<PluginDocumentSettingPanel
			name="popkit-schedule"
			title={ __( 'Popup schedule', 'popkit' ) }
			className="popkit-panel"
		>
			<SchedulePanel schedule={ schedule } onChange={ setSchedule } />
		</PluginDocumentSettingPanel>
	);
}

/**
 * The frequency panel.
 *
 * @return {JSX.Element} Panel.
 */
function Frequency() {
	const [ frequency, setFrequency ] = useMeta( META.FREQUENCY );

	return (
		<PluginDocumentSettingPanel
			name="popkit-frequency"
			title={ __( 'Popup frequency', 'popkit' ) }
			className="popkit-panel"
		>
			<FrequencyPanel frequency={ frequency } onChange={ setFrequency } />
		</PluginDocumentSettingPanel>
	);
}

/**
 * The appearance panel.
 *
 * @return {JSX.Element} Panel.
 */
function Display() {
	const [ display, setDisplay ] = useMeta( META.DISPLAY );

	return (
		<PluginDocumentSettingPanel
			name="popkit-display"
			title={ __( 'Popup appearance', 'popkit' ) }
			className="popkit-panel"
		>
			<DisplayPanel display={ display } onChange={ setDisplay } />
		</PluginDocumentSettingPanel>
	);
}

/**
 * The whole sidebar.
 *
 * Renders nothing outside the popup post type. `Editor::is_popup_editor_screen()`
 * already keeps the bundle off every other editor, so this is the second of two
 * independent gates rather than the only one — and it is the one that still holds
 * if someone enqueues the handle by hand.
 *
 * The registry is awaited before any panel mounts. Panels rendering against a
 * null registry would show an author their saved rules as unavailable for as long
 * as the fetch took, which is the most alarming message in the editor appearing
 * routinely on correct configuration.
 *
 * @return {JSX.Element|null} Sidebar.
 */
function PopupSidebar() {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);

	const { registry, isLoading, error } = useRegistry();

	if ( POST_TYPE !== postType || ! PluginDocumentSettingPanel ) {
		return null;
	}

	if ( isLoading ) {
		return (
			<PluginDocumentSettingPanel
				name="popkit-loading"
				title={ __( 'Popup settings', 'popkit' ) }
				className="popkit-panel"
			>
				<Spinner />
			</PluginDocumentSettingPanel>
		);
	}

	if ( error || ! registry ) {
		return (
			<PluginDocumentSettingPanel
				name="popkit-error"
				title={ __( 'Popup settings', 'popkit' ) }
				className="popkit-panel"
			>
				<Notice status="error" isDismissible={ false }>
					{ __(
						'The list of available conditions and triggers could not be loaded, so the popup settings cannot be edited. Everything already saved is left untouched. Reload the screen to try again.',
						'popkit'
					) }
				</Notice>
			</PluginDocumentSettingPanel>
		);
	}

	return (
		<Fragment>
			<Targeting registry={ registry } />
			<Triggers registry={ registry } />
			<Schedule />
			<Frequency />
			<Display />
		</Fragment>
	);
}

registerPlugin( PLUGIN_NAME, { render: PopupSidebar } );
