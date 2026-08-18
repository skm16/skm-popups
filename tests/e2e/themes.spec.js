/**
 * The shipped themes.
 *
 * Phase 6's exit criteria. Every assertion reads a **computed** colour out of a
 * rendered popup and measures it, rather than reading the stylesheet. That
 * direction is the point: a check against the token declarations would be
 * testing what was typed into `themes.css`, and would keep passing after a
 * cascade change, a specificity accident or a `theme.json` collision made the
 * painted colour something else.
 *
 * ## Inherit is checked differently, on purpose
 *
 * Three themes are checked for a contrast *ratio*. Inherit is not, and leaving
 * it out silently would be the dishonest version of this file — so it is stated
 * here and asserted differently instead.
 *
 * Inherit resolves to the active theme's `--wp--preset--color--base` and
 * `--wp--preset--color--contrast`. A site owner is free to configure a pair that
 * fails WCAG against each other, and popkit cannot correct that without
 * overriding the palette they chose. Asserting a ratio there would mean
 * asserting something about Twenty Twenty-Four rather than about popkit, and it
 * would fail the day a theme shipped a lower-contrast default.
 *
 * What *is* asserted about inherit is the part popkit owns: that it resolves to
 * the theme's presets when they exist, and to the user agent's `Canvas` /
 * `CanvasText` pair when they do not — a pair that cannot fail against itself.
 *
 * ## Why each theme gets its own popup
 *
 * `data-popkit-theme` is not a thing; the theme is a class on the root, written
 * at render time from stored meta. There is no way to restyle a popup in place
 * without editing its meta, so the fixtures are per-theme and per-layout, and
 * they are deleted afterwards for the reason `editor.spec.js` documents at
 * length.
 *
 * @see docs/build-plan.md -> Phase 6
 * @see tools/contrast.cjs
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	contrastRatio,
	formatRatio,
	AA,
} = require( '../../tools/contrast.cjs' );

/**
 * Document root of the isolated e2e WordPress, for reading theme.json.
 *
 * Matches `POPKIT_E2E_DOCROOT` in the harness's `paths.php`.
 */
const DOCROOT = process.env.POPKIT_E2E_DOCROOT || 'C:/tmp/popkit-e2e/wordpress';

/**
 * Reads a theme's declared `base` and `contrast` palette colours.
 *
 * Read from the theme's own `theme.json` rather than written down here, so the
 * test follows a theme that changes its palette instead of asserting a value
 * that used to be true.
 *
 * @param {string} slug Theme directory name.
 * @return {{base: string, contrast: string}|null} Colours, or null when absent.
 */
const themePalette = ( slug ) => {
	const file = path.join( DOCROOT, 'wp-content/themes', slug, 'theme.json' );

	if ( ! fs.existsSync( file ) ) {
		return null;
	}

	const palette =
		JSON.parse( fs.readFileSync( file, 'utf8' ) )?.settings?.color
			?.palette ?? [];

	const find = ( name ) =>
		palette.find( ( entry ) => name === entry.slug )?.color;

	const base = find( 'base' );
	const contrast = find( 'contrast' );

	return base && contrast ? { base, contrast } : null;
};

/** REST collection for the popup post type. */
const POPUP_PATH = '/wp/v2/popkit_popup';

/**
 * Page the fixtures are opened on.
 *
 * The *clean* fixture page, not `popkit-e2e`. The seeded fixture popup carries a
 * `url_path` rule targeting `/popkit-e2e/` and a page-load trigger, so on that
 * page it is eligible too — and popkit opens exactly one popup per pageview, by
 * design. The theme fixture here would render into the DOM and stay closed,
 * which reads as "the theme is broken" rather than "another popup won".
 *
 * `/popkit-e2e-clean/` is the page no seeded fixture targets, so the popup under
 * test is the only eligible one.
 */
const PAGE_SLUG = 'popkit-e2e-clean';

/** Themes that promise a contrast ratio of their own. */
const OPAQUE_THEMES = [ 'light', 'dark', 'bordered' ];

/** Both layouts, because a theme has to hold in each. */
const LAYOUTS = [ 'modal', 'banner' ];

/**
 * Ids created here, removed in `afterEach`.
 *
 * @type {number[]}
 */
const created = [];

/**
 * Creates a published popup in one theme and layout, opening on page load.
 *
 * `page_load` with no delay is the only trigger used: this file is about paint,
 * and every millisecond of trigger latency is a millisecond of flake.
 *
 * @param {Object} requestUtils Playwright fixture.
 * @param {string} theme        Theme name.
 * @param {string} layout       Layout name.
 * @return {Promise<string>} The popup's slug.
 */
const createThemedPopup = async ( requestUtils, theme, layout ) => {
	const slug = `theme-${ theme }-${ layout }`;

	const popup = await requestUtils.rest( {
		method: 'POST',
		path: POPUP_PATH,
		data: {
			title: `Theme ${ theme } ${ layout }`,
			slug,
			status: 'publish',
			content:
				'<!-- wp:heading --><h2>Join the list</h2><!-- /wp:heading -->' +
				'<!-- wp:paragraph --><p>Occasional email, no spam. ' +
				'<a href="/archive/">Read the archive</a>.</p><!-- /wp:paragraph -->',
			meta: {
				_popkit_triggers: [
					{ type: 'page_load', values: { delay_ms: 0 } },
				],
				_popkit_display: {
					layout,
					theme,
					size: 'medium',
					position: 'modal' === layout ? 'center' : 'bottom',
					overlay: true,
					close_on_overlay_click: true,
					animation: 'none',
				},
			},
		},
	} );

	created.push( popup.id );

	return slug;
};

/**
 * Reads the colours a browser actually painted for one popup.
 *
 * Everything comes from `getComputedStyle`, and the background is walked up the
 * ancestor chain until an opaque colour is found. That walk is not defensive
 * padding: `.popkit-popup__content` and `.popkit-popup__close` both declare no
 * background of their own, so their computed `background-color` is
 * `rgba(0, 0, 0, 0)` and measuring text against it would report the contrast of
 * black on transparent — a number, and a meaningless one.
 *
 * @param {Object} page Playwright page.
 * @param {string} slug Popup slug.
 * @return {Promise<Object>} Computed colours.
 */
const readColors = async ( page, slug ) =>
	page.evaluate( ( popupSlug ) => {
		const root = document.querySelector(
			`[data-popkit-slug="${ popupSlug }"]`
		);

		/**
		 * Finds the nearest painted background, walking to the document root.
		 *
		 * @param {Element} start Element to start from.
		 * @return {string} An opaque colour.
		 */
		const backdropOf = ( start ) => {
			let node = start;

			while ( node && node !== document.documentElement ) {
				const color = window.getComputedStyle( node ).backgroundColor;

				if (
					color &&
					'transparent' !== color &&
					! color.includes( ', 0)' ) &&
					! color.endsWith( ' 0)' )
				) {
					return color;
				}

				node = node.parentElement;
			}

			// Nothing painted anything: the page's own canvas is white.
			return 'rgb(255, 255, 255)';
		};

		const container = root.querySelector( '.popkit-popup__container' );
		const content = root.querySelector( '.popkit-popup__content' );
		const close = root.querySelector( '.popkit-popup__close' );
		const heading = content.querySelector( 'h1, h2, h3, h4, h5, h6' );
		const link = content.querySelector( 'a' );

		const closeBox = close.getBoundingClientRect();
		const containerStyle = window.getComputedStyle( container );

		return {
			surface: backdropOf( container ),
			text: window.getComputedStyle( content ).color,
			heading: heading ? window.getComputedStyle( heading ).color : null,
			link: link ? window.getComputedStyle( link ).color : null,
			linkDecoration: link
				? window.getComputedStyle( link ).textDecorationLine
				: null,
			closeColor: window.getComputedStyle( close ).color,
			closeBackdrop: backdropOf( close ),
			closeWidth: closeBox.width,
			closeHeight: closeBox.height,
			borderColor: containerStyle.borderTopColor,
			borderWidth: parseFloat( containerStyle.borderTopWidth ),
		};
	}, slug );

/**
 * Opens the fixture page and waits for the popup to be painted.
 *
 * @param {Object} page Playwright page.
 * @param {string} slug Popup slug.
 * @return {Promise<void>}
 */
const openPopup = async ( page, slug ) => {
	await page.goto( `/${ PAGE_SLUG }/` );

	await expect(
		page.locator(
			`[data-popkit-slug="${ slug }"] .popkit-popup__container`
		)
	).toBeVisible();
};

test.describe( 'popkit themes', () => {
	test.afterEach( async ( { requestUtils } ) => {
		while ( created.length ) {
			const id = created.pop();

			try {
				await requestUtils.rest( {
					method: 'DELETE',
					path: `${ POPUP_PATH }/${ id }`,
					params: { force: true },
				} );
			} catch {
				// Already gone is not a failure worth masking the real one with.
			}
		}
	} );

	for ( const theme of OPAQUE_THEMES ) {
		for ( const layout of LAYOUTS ) {
			test( `${ theme } / ${ layout }: body text meets WCAG 2.2 AA`, async ( {
				page,
				requestUtils,
			} ) => {
				const slug = await createThemedPopup(
					requestUtils,
					theme,
					layout
				);

				await openPopup( page, slug );

				const colors = await readColors( page, slug );
				const ratio = contrastRatio( colors.text, colors.surface );

				expect(
					ratio,
					`${ theme }/${ layout } body text ${ colors.text } on ${
						colors.surface
					} is ${ formatRatio( ratio ) }, below the ${
						AA.TEXT
					}:1 WCAG 2.2 AA minimum for body text.`
				).toBeGreaterThanOrEqual( AA.TEXT );
			} );

			/*
			 * Headings and links, separately from body text.
			 *
			 * This is the assertion whose absence let a real defect ship into a
			 * baseline. Block themes colour headings and links explicitly —
			 * Twenty Twenty-Four uses `:root :where(h1, h2, …)` — and those
			 * declarations beat the colour inherited from the popup's container.
			 * On the dark theme that rendered a `#111111` heading on `#1e1e1e`:
			 * 1.13:1, an invisible headline, while every existing contrast test
			 * passed because nothing styles a `<p>`.
			 *
			 * `readColors()` had been collecting the heading colour all along
			 * and nothing looked at it. Measuring a value and not asserting on it
			 * is indistinguishable from not measuring it.
			 */
			test( `${ theme } / ${ layout }: headings and links meet WCAG 2.2 AA`, async ( {
				page,
				requestUtils,
			} ) => {
				const slug = await createThemedPopup(
					requestUtils,
					theme,
					layout
				);

				await openPopup( page, slug );

				const colors = await readColors( page, slug );

				expect(
					colors.heading,
					'The fixture has no heading, so this test would pass without measuring anything.'
				).not.toBeNull();

				const headingRatio = contrastRatio(
					colors.heading,
					colors.surface
				);

				expect(
					headingRatio,
					`${ theme }/${ layout } heading ${ colors.heading } on ${
						colors.surface
					} is ${ formatRatio(
						headingRatio
					) }. The active theme is colouring headings and the popup is not reclaiming them.`
				).toBeGreaterThanOrEqual( AA.TEXT );

				expect(
					colors.link,
					'The fixture has no link, so this test would pass without measuring anything.'
				).not.toBeNull();

				const linkRatio = contrastRatio( colors.link, colors.surface );

				expect(
					linkRatio,
					`${ theme }/${ layout } link ${ colors.link } on ${
						colors.surface
					} is ${ formatRatio( linkRatio ) }.`
				).toBeGreaterThanOrEqual( AA.TEXT );

				// A link the same colour as its surrounding text needs
				// something other than colour to mark it — WCAG 1.4.1.
				expect(
					colors.linkDecoration,
					`${ theme }/${ layout } link is ${ colors.link } against body text ${ colors.text } with decoration "${ colors.linkDecoration }". A link that matches the text colour and carries no underline is distinguishable only by position.`
				).toContain( 'underline' );
			} );

			/*
			 * The close button carries a clipped text label as its accessible
			 * name and a currentColor glyph as its visible affordance. It is
			 * held to the *text* bar rather than the 3:1 non-text bar: the
			 * label is real text, and a theme that only cleared 3:1 would be
			 * relying on the label staying clipped.
			 */
			test( `${ theme } / ${ layout }: close button meets contrast and 44x44`, async ( {
				page,
				requestUtils,
			} ) => {
				const slug = await createThemedPopup(
					requestUtils,
					theme,
					layout
				);

				await openPopup( page, slug );

				const colors = await readColors( page, slug );
				const ratio = contrastRatio(
					colors.closeColor,
					colors.closeBackdrop
				);

				expect(
					ratio,
					`${ theme }/${ layout } close button ${
						colors.closeColor
					} on ${ colors.closeBackdrop } is ${ formatRatio(
						ratio
					) }, below the ${ AA.TEXT }:1 minimum.`
				).toBeGreaterThanOrEqual( AA.TEXT );

				expect(
					colors.closeWidth,
					`${ theme }/${ layout } close button is ${ colors.closeWidth }px wide; WCAG 2.5.8 wants 44.`
				).toBeGreaterThanOrEqual( 44 );

				expect(
					colors.closeHeight,
					`${ theme }/${ layout } close button is ${ colors.closeHeight }px tall; WCAG 2.5.8 wants 44.`
				).toBeGreaterThanOrEqual( 44 );
			} );

			/*
			 * A border is a non-text UI component boundary, so 3:1 (WCAG 1.4.11)
			 * rather than 4.5. A theme drawing no border at all is exempt: there
			 * is nothing to see and nothing to measure.
			 */
			test( `${ theme } / ${ layout }: any panel border is visible`, async ( {
				page,
				requestUtils,
			} ) => {
				const slug = await createThemedPopup(
					requestUtils,
					theme,
					layout
				);

				await openPopup( page, slug );

				const colors = await readColors( page, slug );

				if ( 0 === colors.borderWidth ) {
					test.skip(
						true,
						`${ theme } draws no panel border, so there is no boundary to measure.`
					);
					return;
				}

				const ratio = contrastRatio(
					colors.borderColor,
					colors.surface
				);

				expect(
					ratio,
					`${ theme }/${ layout } border ${ colors.borderColor } on ${
						colors.surface
					} is ${ formatRatio( ratio ) }, below the ${
						AA.NON_TEXT
					}:1 WCAG 2.2 AA minimum for a UI boundary.`
				).toBeGreaterThanOrEqual( AA.NON_TEXT );
			} );
		}
	}

	/*
	 * Visual regression, one baseline per theme and layout.
	 *
	 * Scoped to the popup's own container rather than the page, and that is the
	 * decision that makes these worth keeping. A full-page baseline changes when
	 * the theme updates, when the fixture page is edited, when a font loads
	 * differently — none of which is a popkit regression, and all of which would
	 * train whoever sees the failure to re-record the baseline without reading
	 * it. The container is the surface popkit is actually responsible for.
	 *
	 * Animation is already `none` on these fixtures, so there is no frame to
	 * catch mid-transition.
	 *
	 * Baselines are per-project (see `snapshotPathTemplate`) because font
	 * rasterisation differs between engines, and a shared baseline would fail in
	 * Firefox for a reason that has nothing to do with the stylesheet. Re-record
	 * with `--update-snapshots` only after looking at the diff.
	 */
	for ( const theme of OPAQUE_THEMES ) {
		for ( const layout of LAYOUTS ) {
			test( `${ theme } / ${ layout }: matches its visual baseline`, async ( {
				page,
				requestUtils,
			} ) => {
				const slug = await createThemedPopup(
					requestUtils,
					theme,
					layout
				);

				await openPopup( page, slug );

				const container = page.locator(
					`[data-popkit-slug="${ slug }"] .popkit-popup__container`
				);

				await expect( container ).toHaveScreenshot(
					`${ theme }-${ layout }.png`,
					{
						/*
						 * An absolute pixel count, not a ratio, and a small one.
						 *
						 * The first version of this used
						 * `maxDiffPixelRatio: 0.01`, which sounds tight and is
						 * not: one percent of a 512x150 panel is about 750
						 * pixels, comfortably more than a recoloured link. It
						 * let exactly that through — a link rendering in the
						 * theme's accent colour instead of the popup's text
						 * colour — and the baseline recorded the wrong result
						 * without failing.
						 *
						 * A ratio scales the tolerance with the panel, which is
						 * backwards: antialiasing noise does not grow with the
						 * box, and the larger the panel the more a percentage
						 * hides.
						 */
						maxDiffPixels: 60,
						animations: 'disabled',
					}
				);
			} );
		}
	}

	/*
	 * Inherit's contract, stated as two assertions rather than a ratio. See the
	 * file docblock for why a ratio would be a claim about the active theme.
	 */
	test( 'inherit resolves to the active theme’s theme.json presets', async ( {
		page,
		requestUtils,
	} ) => {
		const slug = await createThemedPopup(
			requestUtils,
			'inherit',
			'modal'
		);

		await openPopup( page, slug );

		const resolved = await page.evaluate( ( popupSlug ) => {
			const root = document.querySelector(
				`[data-popkit-slug="${ popupSlug }"]`
			);
			const styles = window.getComputedStyle( document.body );

			return {
				base: styles
					.getPropertyValue( '--wp--preset--color--base' )
					.trim(),
				contrast: styles
					.getPropertyValue( '--wp--preset--color--contrast' )
					.trim(),
				surface: window
					.getComputedStyle( root )
					.getPropertyValue( '--popkit-surface' )
					.trim(),
				onSurface: window
					.getComputedStyle( root )
					.getPropertyValue( '--popkit-on-surface' )
					.trim(),
			};
		}, slug );

		// A block theme is active in the harness, so the presets exist and
		// inherit must be using them rather than its own fallback.
		expect(
			resolved.base,
			'The active theme declares no --wp--preset--color--base, so this test cannot tell inherit-from-theme from inherit-from-fallback. Activate a block theme in the e2e harness.'
		).not.toBe( '' );

		expect( resolved.surface ).toBe( resolved.base );
		expect( resolved.onSurface ).toBe( resolved.contrast );

		// And that those presets are the *active theme's*, not some other
		// block theme's, which the assertions above would not catch.
		const declared = themePalette( 'twentytwentyfour' );

		expect(
			declared,
			'Twenty Twenty-Four is not installed in the e2e docroot, so "the active theme\'s presets" cannot be checked against what that theme actually declares.'
		).not.toBeNull();

		expect( resolved.base.toLowerCase() ).toBe(
			declared.base.toLowerCase()
		);
		expect( resolved.contrast.toLowerCase() ).toBe(
			declared.contrast.toLowerCase()
		);
	} );

	/*
	 * Twenty Twenty-Five, by its declared values rather than by activation.
	 *
	 * The harness runs WordPress 6.5.5 on purpose — it is the minimum this
	 * plugin supports, and testing against the floor is what catches accidental
	 * use of a newer API. Twenty Twenty-Five declares `Requires at least: 6.7`,
	 * and `switch_theme()` enforces that: activating it there dies with
	 * "Current WordPress version does not meet minimum requirements".
	 *
	 * So this reproduces what WordPress does rather than asking it to. Global
	 * styles are emitted as a `body { --wp--preset--color--*: … }` rule, which is
	 * exactly what the injected stylesheet below is, carrying Twenty
	 * Twenty-Five's own `theme.json` values read off disk. What is under test is
	 * popkit's side of the contract — that inherit resolves whatever those two
	 * properties hold — and that is fully exercised.
	 *
	 * The values are read rather than written down, so a palette change in a
	 * future Twenty Twenty-Five is picked up rather than asserted away. When the
	 * harness moves to 6.7 or later, this can become a real theme switch and the
	 * injection can go.
	 */
	test( 'inherit resolves Twenty Twenty-Five’s declared presets', async ( {
		page,
		requestUtils,
	} ) => {
		const declared = themePalette( 'twentytwentyfive' );

		expect(
			declared,
			'Twenty Twenty-Five is not installed in the e2e docroot. Install it into wp-content/themes — it does not need to be activatable — so inherit can be verified against a second theme.'
		).not.toBeNull();

		const slug = await createThemedPopup(
			requestUtils,
			'inherit',
			'modal'
		);

		await openPopup( page, slug );

		const resolved = await page.evaluate(
			( { popupSlug, palette } ) => {
				// WordPress emits global styles on `body`; a later rule of the
				// same specificity wins, which is what a theme switch amounts to
				// as far as these two properties are concerned.
				const style = document.createElement( 'style' );

				style.textContent = `body{--wp--preset--color--base:${ palette.base };--wp--preset--color--contrast:${ palette.contrast };}`;
				document.head.append( style );

				const root = document.querySelector(
					`[data-popkit-slug="${ popupSlug }"]`
				);
				const container = root.querySelector(
					'.popkit-popup__container'
				);
				const rootStyles = window.getComputedStyle( root );
				const painted = window.getComputedStyle( container );

				return {
					surface: rootStyles
						.getPropertyValue( '--popkit-surface' )
						.trim(),
					onSurface: rootStyles
						.getPropertyValue( '--popkit-on-surface' )
						.trim(),
					paintedBackground: painted.backgroundColor,
					paintedColor: painted.color,
				};
			},
			{ popupSlug: slug, palette: declared }
		);

		expect( resolved.surface.toLowerCase() ).toBe(
			declared.base.toLowerCase()
		);
		expect( resolved.onSurface.toLowerCase() ).toBe(
			declared.contrast.toLowerCase()
		);

		// The tokens resolving is not the same as the panel being painted with
		// them; assert the rendered result too, which is what a visitor sees.
		const ratio = contrastRatio(
			resolved.paintedColor,
			resolved.paintedBackground
		);

		expect(
			ratio,
			`Twenty Twenty-Five's own palette rendered as ${
				resolved.paintedColor
			} on ${ resolved.paintedBackground }, which is ${ formatRatio(
				ratio
			) }.`
		).toBeGreaterThanOrEqual( AA.TEXT );
	} );

	/*
	 * The fallback half of the same contract. `Canvas` and `CanvasText` are the
	 * user agent's own pair; they follow the platform light/dark preference and
	 * forced-colors mode, and cannot fail a contrast check against each other.
	 * Without them a classic theme — no theme.json, no presets — would resolve
	 * every popkit token to nothing and paint an unstyled panel.
	 */
	test( 'inherit falls back to the user agent pair when a theme declares no presets', async ( {
		page,
		requestUtils,
	} ) => {
		const slug = await createThemedPopup(
			requestUtils,
			'inherit',
			'modal'
		);

		await openPopup( page, slug );

		const painted = await page.evaluate( ( popupSlug ) => {
			const root = document.querySelector(
				`[data-popkit-slug="${ popupSlug }"]`
			);

			// Remove the presets the active theme supplies, which is what a
			// classic theme's page looks like to these declarations.
			root.style.setProperty( '--wp--preset--color--base', 'initial' );
			root.style.setProperty(
				'--wp--preset--color--contrast',
				'initial'
			);

			const container = root.querySelector( '.popkit-popup__container' );
			const styles = window.getComputedStyle( container );

			return {
				background: styles.backgroundColor,
				color: styles.color,
			};
		}, slug );

		// Whatever the platform resolves Canvas/CanvasText to, both must be
		// real painted colours rather than the transparent initial value.
		expect( painted.background ).not.toBe( 'rgba(0, 0, 0, 0)' );
		expect( painted.background ).not.toBe( 'transparent' );
		expect( painted.color ).not.toBe( '' );

		const ratio = contrastRatio( painted.color, painted.background );

		expect(
			ratio,
			`The user agent fallback pair resolved to ${ painted.color } on ${
				painted.background
			}, which is ${ formatRatio(
				ratio
			) }. Canvas and CanvasText are meant to be a legible pair on every platform.`
		).toBeGreaterThanOrEqual( AA.TEXT );
	} );
} );
