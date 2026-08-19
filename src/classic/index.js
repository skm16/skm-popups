/**
 * Repeater behavior for the classic-editor targeting meta box.
 *
 * Everything this file does is *additive*. The markup PHP renders is a complete,
 * working form on its own: every control that exists is submitted, and a popup
 * whose rules are already set can be edited, saved and re-saved with this script
 * absent. What is lost without it is the ability to add or remove a row, and the
 * showing and hiding of the field set belonging to the chosen condition — the
 * latter meaning an author would see every condition's fields at once rather
 * than none of them.
 *
 * That is why there is no client-side templating of *controls* here. The server
 * renders a field set for every registered condition inside every rule, so this
 * file never has to know what a control looks like. A condition registered by a
 * third party works on this screen without touching this file, which is the same
 * registry invariant the block editor sidebar keeps.
 *
 * ## Cloning, not string building
 *
 * A new row is `template.content.cloneNode( true )` with the index tokens
 * rewritten **in attributes of the cloned nodes**. The obvious alternative —
 * read `innerHTML`, run a string replace, assign it back — would take markup
 * that includes author-supplied stored values and re-parse it as HTML. That is
 * an XSS sink for anything that survived escaping, and it is unnecessary: the
 * browser has already parsed this markup safely once, so cloning keeps that
 * parse and touches only the attributes that carry a row index.
 *
 * Row indices only have to be unique, not contiguous: `Classic_Editor` rebuilds
 * both lists with `[]` on save, so gaps left by a removal close themselves.
 */

/** Tokens the server writes where a row index belongs. */
const GROUP_TOKEN = '__GROUP__';
const RULE_TOKEN = '__RULE__';

/**
 * Monotonic counter for freshly added rows.
 *
 * Seeded past any index the server could have rendered, so a new row cannot
 * collide with an existing one however many were removed first.
 */
let nextIndex = 100000;

/**
 * Rewrites the index tokens in one element's attributes.
 *
 * @param {Element} element Element to rewrite in place.
 * @param {string}  group   Group index to write.
 * @param {string}  rule    Rule index to write.
 * @return {void}
 */
function rewriteAttributes( element, group, rule ) {
	Array.from( element.attributes ).forEach( ( attribute ) => {
		const { value } = attribute;

		if (
			! value.includes( GROUP_TOKEN ) &&
			! value.includes( RULE_TOKEN )
		) {
			return;
		}

		element.setAttribute(
			attribute.name,
			value
				.split( GROUP_TOKEN )
				.join( group )
				.split( RULE_TOKEN )
				.join( rule )
		);
	} );
}

/**
 * Builds a row from a named template, substituting row indices.
 *
 * @param {Document} doc   Owning document.
 * @param {string}   name  Template name, as `data-popkit-template`.
 * @param {string}   group Group index to write in.
 * @param {string}   rule  Rule index to write in.
 * @return {Element|null} The new row, or null when the template is missing.
 */
function buildRow( doc, name, group, rule ) {
	const template = doc.querySelector( `[data-popkit-template="${ name }"]` );

	if ( ! template || ! template.content ) {
		return null;
	}

	const fragment = template.content.cloneNode( true );
	const row = fragment.firstElementChild;

	if ( ! row ) {
		return null;
	}

	rewriteAttributes( row, group, rule );
	row.querySelectorAll( '*' ).forEach( ( element ) =>
		rewriteAttributes( element, group, rule )
	);

	return row;
}

/**
 * Shows the field set belonging to the selected condition, and hides the rest.
 *
 * `hidden` rather than a class: a hidden field set is out of the accessibility
 * tree *and* out of the tab order, which is the point. Fields an author cannot
 * see must not be reachable by keyboard either.
 *
 * The controls stay in the form and are still submitted. That is deliberate and
 * harmless — `Classic_Editor::read_conditions()` reads only the fields belonging
 * to the selected type — and it means switching condition and back does not
 * throw away what was typed.
 *
 * @param {Element} rule Rule row.
 * @return {void}
 */
function syncRuleFields( rule ) {
	const select = rule.querySelector( '[data-popkit-rule-type]' );

	if ( ! select ) {
		return;
	}

	rule.querySelectorAll( '[data-popkit-values]' ).forEach( ( group ) => {
		group.hidden = group.dataset.popkitValues !== select.value;
	} );
}

/**
 * Offers only the positions the chosen layout can express.
 *
 * A modal is centered or near the top; a notification bar is at the top, at the
 * bottom, or a lower third. The server renders both groups and disables the one
 * that does not apply, so this only has to keep that in step as the layout
 * changes.
 *
 * `disabled` on the `<optgroup>` rather than hiding it: a disabled option is
 * still announced as unavailable rather than vanishing from under a screen
 * reader user mid-interaction, and it cannot be selected by keyboard.
 *
 * When the current selection belongs to the group being disabled, the first
 * option of the newly enabled group is selected instead — matching what
 * `Meta::sanitize_display()` would have stored anyway, so the control never
 * shows a value the save would silently change.
 *
 * @param {Document} doc    Owning document.
 * @param {string}   layout Chosen layout.
 * @return {void}
 */
function syncPositions( doc, layout ) {
	const select = doc.querySelector( '[data-popkit-position]' );

	if ( ! select ) {
		return;
	}

	let fallback = null;

	select
		.querySelectorAll( '[data-popkit-for-layout]' )
		.forEach( ( group ) => {
			const applies = group.dataset.popkitForLayout === layout;

			group.disabled = ! applies;

			if ( applies && ! fallback ) {
				fallback = group.querySelector( 'option' );
			}
		} );

	const chosen = select.selectedOptions[ 0 ];

	if ( fallback && chosen && chosen.parentElement.disabled ) {
		fallback.selected = true;
	}
}

/**
 * Shows or hides the targeting rules to match the site-wide choice.
 *
 * The rules are hidden, never removed. `Classic_Editor::read_conditions()`
 * answers the scope question first and returns an empty rule set without reading
 * them, so a hidden rule cannot leak into a site-wide popup — and an author who
 * flips to "everywhere", changes their mind, and flips back before saving finds
 * their rules exactly as they left them.
 *
 * `hidden` rather than a class, for the same reason `syncRuleFields` uses it: it
 * takes the controls out of the tab order as well as out of sight.
 *
 * @param {Document} doc Owning document.
 * @return {void}
 */
function syncScope( doc ) {
	const chosen = doc.querySelector( '[data-popkit-scope]:checked' );
	const scoped = doc.querySelector( '[data-popkit-scoped]' );

	if ( ! chosen || ! scoped ) {
		return;
	}

	const scoping = 'rules' === chosen.value;

	scoped.hidden = ! scoping;

	// Choosing to target something and being shown an empty box is a dead end —
	// the author has to find "Add group" before there is anything to fill in. One
	// group with one rule is the smallest thing that can be edited rather than
	// constructed, and removing it is one click if it was not wanted.
	if ( scoping && ! scoped.querySelector( '[data-popkit-group]' ) ) {
		addGroup( doc );
	}
}

/**
 * Turns the color inputs into WordPress color pickers.
 *
 * Iris ships with core and is pulled in as a dependency of this handle, so there
 * is nothing to load here — but it is still called defensively. The picker is an
 * enhancement over a text input that already works: a site that has dequeued
 * `wp-color-picker`, or a jQuery that failed to load, leaves the author typing a
 * hex value into a field that submits exactly the same name, rather than facing
 * a console error and a control that does nothing.
 *
 * `defaultColor: false` is the load-bearing option. It swaps Iris's "Default"
 * button for a **Clear** one, which empties the input rather than restoring some
 * color — and an empty value is how a popup says "keep the theme", which is the
 * only value in this control whose contrast has actually been measured. With a
 * default set there would be no way back to blank once the picker was opened.
 *
 * @param {Document} doc Owning document.
 * @return {void}
 */
function upgradeColorFields( doc ) {
	const jq = window.jQuery;

	if ( ! jq || ! jq.fn || 'function' !== typeof jq.fn.wpColorPicker ) {
		return;
	}

	doc.querySelectorAll( '[data-popkit-color]' ).forEach( ( input ) => {
		jq( input ).wpColorPicker( {
			defaultColor: false,
			hide: true,
			palettes: true,
		} );
	} );
}

/**
 * Moves focus to the first control of a row that was just added.
 *
 * Without this, activating "Add rule" leaves focus on a button while the thing
 * the author asked for appears somewhere they have to go and find, and a screen
 * reader user gets no indication anything happened at all.
 *
 * @param {Element} row New row.
 * @return {void}
 */
function focusFirstControl( row ) {
	const control = row.querySelector( 'select, input, textarea, button' );

	if ( control ) {
		control.focus();
	}
}

/**
 * Adds a group.
 *
 * @param {Document} doc Owning document.
 * @return {void}
 */
function addGroup( doc ) {
	const list = doc.querySelector( '[data-popkit-groups]' );

	if ( ! list ) {
		return;
	}

	nextIndex += 1;

	const row = buildRow( doc, 'group', String( nextIndex ), '0' );

	if ( row ) {
		list.appendChild( row );
		focusFirstControl( row );
	}
}

/**
 * Adds a rule to the group holding the activated button.
 *
 * @param {Element} button Activated button.
 * @return {void}
 */
function addRule( button ) {
	const group = button.closest( '[data-popkit-group]' );
	const rules = group && group.querySelector( '[data-popkit-rules]' );

	if ( ! rules ) {
		return;
	}

	nextIndex += 1;

	const row = buildRow(
		button.ownerDocument,
		'rule',
		group.dataset.popkitGroup,
		String( nextIndex )
	);

	if ( row ) {
		rules.appendChild( row );
		syncRuleFields( row );
		focusFirstControl( row );
	}
}

/**
 * Removes a row and parks focus somewhere meaningful.
 *
 * Focus is destroyed along with the button that held it, so leaving it to fall
 * back to `<body>` would drop a keyboard user at the top of the document.
 *
 * @param {Element}      row      Row to remove.
 * @param {Element|null} fallback Element to focus afterwards.
 * @return {void}
 */
function removeRow( row, fallback ) {
	row.remove();

	if ( fallback ) {
		fallback.focus();
	}
}

/**
 * Handles every click in the targeting box.
 *
 * Delegated from the document, so rows added after load behave exactly like rows
 * rendered with it.
 *
 * @param {Event} event Click event.
 * @return {void}
 */
function onClick( event ) {
	const target = event.target;

	// Duck-typed rather than `target instanceof Element`, for the reason given in
	// src/frontend/events.js: `Element` is not among the globals this project's
	// ESLint configuration declares, and the capability is what matters anyway.
	if ( ! target || 'function' !== typeof target.closest ) {
		return;
	}

	if ( target.closest( '[data-popkit-add-group]' ) ) {
		addGroup( target.ownerDocument );

		return;
	}

	const rule = target.closest( '[data-popkit-add-rule]' );

	if ( rule ) {
		addRule( rule );

		return;
	}

	const removeRuleButton = target.closest( '[data-popkit-remove-rule]' );

	if ( removeRuleButton ) {
		const row = removeRuleButton.closest( '[data-popkit-rule]' );
		const group = row && row.closest( '[data-popkit-group]' );

		if ( row ) {
			removeRow(
				row,
				group && group.querySelector( '[data-popkit-add-rule]' )
			);
		}

		return;
	}

	const removeGroupButton = target.closest( '[data-popkit-remove-group]' );

	if ( removeGroupButton ) {
		const row = removeGroupButton.closest( '[data-popkit-group]' );

		if ( row ) {
			removeRow(
				row,
				row.ownerDocument.querySelector( '[data-popkit-add-group]' )
			);
		}
	}
}

/**
 * Handles a change of condition on any rule.
 *
 * @param {Event} event Change event.
 * @return {void}
 */
function onChange( event ) {
	const target = event.target;

	if ( ! target || 'function' !== typeof target.matches ) {
		return;
	}

	if ( target.matches( '[data-popkit-rule-type]' ) ) {
		const rule = target.closest( '[data-popkit-rule]' );

		if ( rule ) {
			syncRuleFields( rule );
		}

		return;
	}

	if ( target.matches( '[data-popkit-scope]' ) ) {
		syncScope( target.ownerDocument );

		return;
	}

	if ( target.matches( '[name="popkit_display[layout]"]' ) ) {
		syncPositions( target.ownerDocument, target.value );
	}
}

/**
 * Binds the meta box.
 *
 * @return {void}
 */
function start() {
	document.addEventListener( 'click', onClick );
	document.addEventListener( 'change', onChange );

	document
		.querySelectorAll( '[data-popkit-rule]' )
		.forEach( ( rule ) => syncRuleFields( rule ) );

	upgradeColorFields( document );
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
