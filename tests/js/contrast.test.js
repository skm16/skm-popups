/**
 * The contrast arithmetic the theme tests are measured with.
 *
 * This file exists because `tools/contrast.cjs` is load bearing in a way that is
 * easy to miss: every WCAG assertion in `tests/e2e/themes.spec.js` is only as
 * true as the ratio computed here. A transposed coefficient or a missing
 * linearisation step would not throw — it would return a plausible number, and
 * twenty-one theme tests would go green against a palette nobody had actually
 * checked.
 *
 * So the expectations below are external reference values, not values this
 * implementation produced. Black on white is 21:1 by definition; `#767676` on
 * white is the grey the WCAG working group cites as the boundary case for 4.5:1;
 * the rest are checkable against any published contrast calculator.
 *
 * @see tools/contrast.cjs
 */

/* global describe, it, expect */

const {
	AA,
	contrastRatio,
	formatRatio,
	parseColor,
} = require( '../../tools/contrast.cjs' );

/**
 * Rounds for comparison against published figures, which are quoted to two
 * decimal places.
 *
 * @param {number} value Ratio.
 * @return {number} Ratio to two decimal places.
 */
const round = ( value ) => Math.round( value * 100 ) / 100;

describe( 'parseColor', () => {
	it.each( [
		[ '#fff', { r: 255, g: 255, b: 255, a: 1 } ],
		[ '#ffffff', { r: 255, g: 255, b: 255, a: 1 } ],
		[ '#1e1e1e', { r: 30, g: 30, b: 30, a: 1 } ],
		[ 'rgb(30, 30, 30)', { r: 30, g: 30, b: 30, a: 1 } ],
		[ 'rgba(30, 30, 30, 0.5)', { r: 30, g: 30, b: 30, a: 0.5 } ],
		[ 'rgb(30 30 30 / 50%)', { r: 30, g: 30, b: 30, a: 0.5 } ],
		[ 'RGB(30, 30, 30)', { r: 30, g: 30, b: 30, a: 1 } ],
	] )( 'parses %s', ( input, expected ) => {
		expect( parseColor( input ) ).toEqual( expected );
	} );

	/*
	 * Every one of these has to come back null rather than a guess. A parser
	 * that fell back to black would report a passing ratio for a colour it never
	 * read, which is the failure this whole file is guarding.
	 */
	it.each( [
		[ 'a named colour', 'rebeccapurple' ],
		[ 'a system colour', 'CanvasText' ],
		[ 'a modern colour space', 'color(display-p3 1 0 0)' ],
		[ 'an lab colour', 'lab(50% 40 59.5)' ],
		[ 'a malformed hex', '#12345' ],
		[ 'a non-hex hex', '#gggggg' ],
		[ 'an empty string', '' ],
		[ 'a number', 255 ],
		[ 'null', null ],
		[ 'undefined', undefined ],
	] )( 'refuses %s', ( _label, input ) => {
		expect( parseColor( input ) ).toBeNull();
	} );
} );

describe( 'contrastRatio', () => {
	/*
	 * External reference values. 21:1 and 1:1 are definitional; the others are
	 * what any published WCAG calculator returns for the same pair.
	 */
	it.each( [
		[ 'black on white', '#000000', '#ffffff', 21 ],
		[ 'white on white', '#ffffff', '#ffffff', 1 ],
		[ 'the WCAG boundary grey on white', '#767676', '#ffffff', 4.54 ],
		[ 'pure blue on white', '#0000ff', '#ffffff', 8.59 ],
		[ 'pure red on white', '#ff0000', '#ffffff', 4 ],
		[ 'popkit light body text', '#1e1e1e', '#ffffff', 16.67 ],
		[ 'popkit dark body text', '#f0f0f0', '#1e1e1e', 14.63 ],
	] )( 'computes %s', ( _label, fg, bg, expected ) => {
		expect( round( contrastRatio( fg, bg ) ) ).toBeCloseTo( expected, 1 );
	} );

	it( 'is symmetric', () => {
		expect( contrastRatio( '#123456', '#abcdef' ) ).toBeCloseTo(
			contrastRatio( '#abcdef', '#123456' ),
			10
		);
	} );

	/*
	 * A translucent foreground is composited over the background before
	 * measuring, because that is what a reader sees. Half-opacity black over
	 * white is mid-grey, which is nowhere near black-on-white's 21:1.
	 *
	 * The expected value is worked rather than quoted, because the first draft of
	 * this test quoted a remembered one and was wrong by a third:
	 *
	 *   composite  0 x 0.5 + 255 x 0.5      = 127.5, i.e. #808080
	 *   linearise  ((0.5 + 0.055) / 1.055)^2.4 = 0.2140
	 *   ratio      (1.0 + 0.05) / (0.2140 + 0.05) = 3.98
	 *
	 * Being caught by this is the point of the file. An invented expectation that
	 * happened to sit near the truth would have passed and quietly certified the
	 * arithmetic every theme assertion depends on.
	 */
	it( 'composites a translucent foreground over the background', () => {
		const solid = contrastRatio( '#000000', '#ffffff' );
		const half = contrastRatio( 'rgba(0, 0, 0, 0.5)', '#ffffff' );

		expect( half ).toBeLessThan( solid );
		expect( round( half ) ).toBeCloseTo( 3.98, 1 );
	} );

	it( 'treats a fully transparent foreground as invisible', () => {
		expect( contrastRatio( 'rgba(0, 0, 0, 0)', '#ffffff' ) ).toBeCloseTo(
			1,
			10
		);
	} );

	/*
	 * The refusals. Each of these could return a number, and each of those
	 * numbers would be a lie about a rendering nobody verified.
	 */
	it( 'refuses a translucent background rather than assuming what is behind it', () => {
		expect( () =>
			contrastRatio( '#000000', 'rgba(255, 255, 255, 0.5)' )
		).toThrow( /translucent/i );
	} );

	it( 'refuses an unreadable foreground', () => {
		expect( () => contrastRatio( 'CanvasText', '#ffffff' ) ).toThrow(
			/foreground/i
		);
	} );

	it( 'refuses an unreadable background', () => {
		expect( () => contrastRatio( '#000000', 'Canvas' ) ).toThrow(
			/background/i
		);
	} );
} );

describe( 'AA thresholds', () => {
	it( 'matches WCAG 2.2', () => {
		expect( AA.TEXT ).toBe( 4.5 );
		expect( AA.LARGE_TEXT ).toBe( 3 );
		expect( AA.NON_TEXT ).toBe( 3 );
	} );
} );

describe( 'formatRatio', () => {
	it( 'reads as a ratio', () => {
		expect( formatRatio( 4.5 ) ).toBe( '4.5:1' );
		expect( formatRatio( 21 ) ).toBe( '21:1' );
		expect( formatRatio( 4.539_2 ) ).toBe( '4.54:1' );
	} );
} );
