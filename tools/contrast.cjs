/**
 * WCAG 2.2 contrast arithmetic.
 *
 * Used by the theme tests, which read *computed* colors out of a rendered
 * popup and check them here. That direction matters: a check that read the
 * stylesheet's token declarations would be testing what the author typed, and
 * would keep passing after a cascade change made the rendered color something
 * else entirely.
 *
 * Not part of the shipped bundle. Nothing in `src/` imports it.
 *
 * ## The thresholds
 *
 * | What | Ratio |
 * |---|---|
 * | Body text (1.4.3) | 4.5 |
 * | Large text — 24px, or 18.66px bold (1.4.3) | 3 |
 * | UI component boundaries and icons (1.4.11) | 3 |
 *
 * The close button is checked against both 4.5 and 3: its visible glyph is a
 * non-text component, but its accessible name is real text, and a theme that
 * passed only the looser bar would be relying on the label being clipped.
 *
 * ## Alpha
 *
 * A foreground with alpha is composited over the supplied background before the
 * ratio is taken, because that is what a reader sees. A *background* with alpha
 * is refused rather than guessed at: correct compositing would need every layer
 * beneath it, and returning a plausible number computed from an assumed white
 * page is exactly the sort of quietly-wrong result these tests exist to catch.
 */

/**
 * Parses a CSS color into RGBA, with channels 0–255 and alpha 0–1.
 *
 * Handles what `getComputedStyle()` actually returns — `rgb(r, g, b)`,
 * `rgba(r, g, b, a)`, and the space-separated `rgb(r g b / a)` form — plus hex,
 * so a token can be checked straight out of the stylesheet in a unit test.
 *
 * Returns null for anything else, including named colors and `color(...)`
 * spaces. Null is a refusal to guess: a caller that treated an unparsed color
 * as black would report a passing ratio for a color it never read.
 *
 * @param {string} value CSS color.
 * @return {{r: number, g: number, b: number, a: number}|null} Parsed color, or null.
 */
const parseColor = ( value ) => {
	if ( 'string' !== typeof value ) {
		return null;
	}

	const text = value.trim().toLowerCase();

	if ( text.startsWith( '#' ) ) {
		const hex = text.slice( 1 );

		const expand =
			3 === hex.length || 4 === hex.length
				? [ ...hex ].map( ( c ) => c + c ).join( '' )
				: hex;

		if ( 6 !== expand.length && 8 !== expand.length ) {
			return null;
		}

		if ( ! /^[0-9a-f]+$/.test( expand ) ) {
			return null;
		}

		return {
			r: parseInt( expand.slice( 0, 2 ), 16 ),
			g: parseInt( expand.slice( 2, 4 ), 16 ),
			b: parseInt( expand.slice( 4, 6 ), 16 ),
			a:
				8 === expand.length
					? parseInt( expand.slice( 6, 8 ), 16 ) / 255
					: 1,
		};
	}

	const match = text.match( /^rgba?\(([^)]+)\)$/ );

	if ( ! match ) {
		return null;
	}

	// Both `r, g, b, a` and `r g b / a` reduce to the same four numbers.
	const parts = match[ 1 ]
		.replace( /\//g, ' ' )
		.split( /[\s,]+/ )
		.filter( Boolean );

	if ( 3 !== parts.length && 4 !== parts.length ) {
		return null;
	}

	const channels = parts.map( ( part ) =>
		part.endsWith( '%' )
			? Number( part.slice( 0, -1 ) ) / 100
			: Number( part )
	);

	if ( channels.some( ( channel ) => ! Number.isFinite( channel ) ) ) {
		return null;
	}

	return {
		r: channels[ 0 ],
		g: channels[ 1 ],
		b: channels[ 2 ],
		a: 4 === channels.length ? channels[ 3 ] : 1,
	};
};

/**
 * Relative luminance, per WCAG 2.x.
 *
 * @param {{r: number, g: number, b: number}} color Opaque color.
 * @return {number} Luminance, 0–1.
 */
const luminance = ( { r, g, b } ) => {
	const channel = ( value ) => {
		const scaled = value / 255;

		return 0.03928 >= scaled
			? scaled / 12.92
			: Math.pow( ( scaled + 0.055 ) / 1.055, 2.4 );
	};

	return (
		0.2126 * channel( r ) + 0.7152 * channel( g ) + 0.0722 * channel( b )
	);
};

/**
 * Composites a possibly-transparent color over an opaque one.
 *
 * @param {{r: number, g: number, b: number, a: number}} fg Foreground.
 * @param {{r: number, g: number, b: number}}            bg Opaque background.
 * @return {{r: number, g: number, b: number}} Composited color.
 */
const composite = ( fg, bg ) => {
	return {
		r: fg.r * fg.a + bg.r * ( 1 - fg.a ),
		g: fg.g * fg.a + bg.g * ( 1 - fg.a ),
		b: fg.b * fg.a + bg.b * ( 1 - fg.a ),
	};
};

/**
 * Contrast ratio between a foreground and a background.
 *
 * Throws rather than returns a number when either color cannot be read, or
 * when the background is itself translucent. A contrast check that silently
 * degrades to a default is worse than no check: it reports a ratio for a
 * rendering nobody verified.
 *
 * @param {string} foreground CSS color, alpha permitted.
 * @param {string} background CSS color, must be opaque.
 * @return {number} Ratio between 1 and 21.
 */
const contrastRatio = ( foreground, background ) => {
	const fg = parseColor( foreground );
	const bg = parseColor( background );

	if ( ! fg ) {
		throw new Error( `Unreadable foreground color: ${ foreground }` );
	}

	if ( ! bg ) {
		throw new Error( `Unreadable background color: ${ background }` );
	}

	if ( 1 !== bg.a ) {
		throw new Error(
			`Background color is translucent (${ background }); compositing it correctly needs every layer beneath it, and assuming one would report a ratio nobody verified.`
		);
	}

	const a = luminance( composite( fg, bg ) );
	const b = luminance( bg );

	const lighter = Math.max( a, b );
	const darker = Math.min( a, b );

	return ( lighter + 0.05 ) / ( darker + 0.05 );
};

/**
 * WCAG 2.2 minimum ratios.
 *
 * @type {Object<string, number>}
 */
const AA = {
	TEXT: 4.5,
	LARGE_TEXT: 3,
	NON_TEXT: 3,
};

/**
 * Rounds a ratio for a failure message.
 *
 * @param {number} ratio Contrast ratio.
 * @return {string} Ratio to two decimal places, as `12.34:1`.
 */
const formatRatio = ( ratio ) => {
	return `${ Math.round( ratio * 100 ) / 100 }:1`;
};

module.exports = { parseColor, contrastRatio, formatRatio, AA };
