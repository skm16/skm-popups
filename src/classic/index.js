/**
 * Repeater behaviour for the classic-editor targeting meta box.
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
 *
 * @package Popkit
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

		if ( ! value.includes( GROUP_TOKEN ) && ! value.includes( RULE_TOKEN ) ) {
			return;
		}

		element.setAttribute(
			attribute.name,
			value.split( GROUP_TOKEN ).join( group ).split( RULE_TOKEN ).join( rule )
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
 * @param {Element} row      Row to remove.
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

	if ( ! ( target instanceof Element ) ) {
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
			removeRow( row, group && group.querySelector( '[data-popkit-add-rule]' ) );
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

	if ( target instanceof Element && target.matches( '[data-popkit-rule-type]' ) ) {
		const rule = target.closest( '[data-popkit-rule]' );

		if ( rule ) {
			syncRuleFields( rule );
		}
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
}

if ( 'loading' === document.readyState ) {
	document.addEventListener( 'DOMContentLoaded', start );
} else {
	start();
}
