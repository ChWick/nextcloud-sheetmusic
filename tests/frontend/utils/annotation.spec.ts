/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { SheetAnnotation } from '../../../src/types/annotation'
import { describe, expect, it } from 'vitest'
import {
	computeBoundingBoxFromPoints,
	DEFAULT_VIEWBOX_HEIGHT,
	generateArrowPath,
	generateBracketPath,
	generateHairpinPath,
	generateSlurPath,
	generateSmoothSvgPath,
	getAnnotationDashArray,
	getAnnotationDisplaySymbol,
	getAnnotationFontFamily,
	getAnnotationPath,
	getAnnotationTextLayout,
	getShapeHeightHandle,
	getStampRenderFontSize,
	getStampRenderPosition,
	getStampInkBounds,
	getViewBoxHeight,
	isPathShape,
	translateAnnotation,
	VIEWBOX_WIDTH,
} from '../../../src/utils/annotation'
import { getStampDefinition, MUSIC_FONT_FAMILY } from '../../../src/utils/musicSymbols'
import { buildAnnotationDraft } from '../../../src/utils/annotationTransform'
import { createDefaultToolStyle } from '../../../src/utils/annotationToolStyle'

function makeAnn(overrides: Partial<SheetAnnotation> = {}): SheetAnnotation {
	return {
		uuid: 'test-uuid',
		songId: 1,
		fileId: 1,
		pageNumber: 0,
		type: 'drawing',
		x: 0.1,
		y: 0.1,
		width: 0.2,
		height: 0.2,
		style: {},
		data: {},
		...overrides,
	}
}

describe('annotation utils', () => {
	describe('getViewBoxHeight', () => {
		it('computes isotropic viewBox height from page dimensions', () => {
			expect(getViewBoxHeight(595, 842)).toBeCloseTo(1415.13, 1)
			expect(getViewBoxHeight(842, 595)).toBeCloseTo(706.65, 1)
			expect(getViewBoxHeight(1000, 1000)).toBe(1000)
		})

		it('falls back to A4 default when dimensions are missing', () => {
			expect(getViewBoxHeight()).toBe(DEFAULT_VIEWBOX_HEIGHT)
			expect(getViewBoxHeight(0, 0)).toBe(DEFAULT_VIEWBOX_HEIGHT)
		})
	})

	describe('generateSmoothSvgPath', () => {
		it('returns empty string for empty points array', () => {
			expect(generateSmoothSvgPath([])).toBe('')
		})

		it('returns single move command for 1 point', () => {
			expect(generateSmoothSvgPath([{ x: 0.1, y: 0.2 }])).toBe('M 100.0 200.0')
		})

		it('scales Y by vbHeight for isotropic coordinates', () => {
			expect(generateSmoothSvgPath([{ x: 0.1, y: 0.5 }], true, 1400)).toBe('M 100.0 700.0')
		})

		it('returns line command for 2 points', () => {
			expect(generateSmoothSvgPath([{ x: 0.1, y: 0.2 }, { x: 0.3, y: 0.4 }])).toBe('M 100.0 200.0 L 300.0 400.0')
		})

		it('applies quadratic Bezier curve smoothing for 3+ points when smooth is true', () => {
			const pts = [{ x: 0.1, y: 0.1 }, { x: 0.2, y: 0.3 }, { x: 0.4, y: 0.2 }]
			expect(generateSmoothSvgPath(pts, true)).toBe('M 100.0 100.0 Q 200.0 300.0, 300.0 250.0 L 400.0 200.0')
		})

		it('uses linear segments when smooth is false', () => {
			const pts = [{ x: 0.1, y: 0.1 }, { x: 0.2, y: 0.3 }, { x: 0.4, y: 0.2 }]
			expect(generateSmoothSvgPath(pts, false)).toBe('M 100.0 100.0 L 200.0 300.0 L 400.0 200.0')
		})

		it('filters out consecutive duplicate points', () => {
			const pts = [{ x: 0.1, y: 0.2 }, { x: 0.1, y: 0.2 }, { x: 0.5, y: 0.5 }]
			expect(generateSmoothSvgPath(pts, true)).toBe('M 100.0 200.0 L 500.0 500.0')
		})
	})

	describe('getAnnotationDashArray', () => {
		it('returns dashed pattern for lineStyle dashed', () => {
			const ann = makeAnn({ style: { lineStyle: 'dashed', strokeWidth: 4 } })
			expect(getAnnotationDashArray(ann)).toBe('10, 6')
		})

		it('returns dotted pattern for lineStyle dotted', () => {
			const ann = makeAnn({ style: { lineStyle: 'dotted', strokeWidth: 5 } })
			expect(getAnnotationDashArray(ann)).toBe('1, 11')
		})

		it('scales dashes dynamically based on stroke width', () => {
			const thin = makeAnn({ style: { lineStyle: 'dashed', strokeWidth: 4 } })
			const thick = makeAnn({ style: { lineStyle: 'dashed', strokeWidth: 16 } })
			expect(getAnnotationDashArray(thin)).toBe('10, 6')
			expect(getAnnotationDashArray(thick)).toBe('40, 24')
		})

		it('returns undefined for solid lines', () => {
			expect(getAnnotationDashArray(makeAnn({ style: { lineStyle: 'solid' } }))).toBeUndefined()
			expect(getAnnotationDashArray(makeAnn())).toBeUndefined()
		})
	})

	describe('shape path generators', () => {
		it('generates crescendo (<) and decrescendo (>) hairpins', () => {
			const p1 = { x: 0.1, y: 0.5 }
			const p2 = { x: 0.5, y: 0.5 }
			expect(generateHairpinPath(p1, p2, false, 10, 1000)).toBe('M 500.0 510.0 L 100.0 500.0 L 500.0 490.0')
			expect(generateHairpinPath(p1, p2, true, 10, 1000)).toBe('M 100.0 510.0 L 500.0 500.0 L 100.0 490.0')
		})

		it('generates arrow path with arrowhead at p2', () => {
			const path = generateArrowPath({ x: 0.1, y: 0.1 }, { x: 0.5, y: 0.1 }, 2, 1000)
			expect(path).toContain('M 100.0 100.0 L 500.0 100.0')
			expect(path).toContain('L 500.0 100.0 L')
		})

		it('generates quadratic slur path', () => {
			const path = generateSlurPath({ x: 0.1, y: 0.5 }, { x: 0.5, y: 0.5 }, -30, 1000)
			expect(path).toBe('M 100.0 500.0 Q 300.0 470.0, 500.0 500.0')
		})

		it('generates bracket path with optional hookHeight (down and flipped up)', () => {
			const path = generateBracketPath({ x: 0.1, y: 0.5 }, { x: 0.5, y: 0.5 }, 1000, 35)
			expect(path).toBe('M 100.0 535.0 L 100.0 500.0 L 500.0 500.0 L 500.0 535.0')

			const flippedPath = generateBracketPath({ x: 0.1, y: 0.5 }, { x: 0.5, y: 0.5 }, 1000, -35)
			expect(flippedPath).toBe('M 100.0 465.0 L 100.0 500.0 L 500.0 500.0 L 500.0 465.0')

			const upDraft = buildAnnotationDraft('bracket-up', createDefaultToolStyle(), 0, { x: 0.1, y: 0.5 }, { x: 0.5, y: 0.5 }, [])
			expect(upDraft?.data.shapeType).toBe('bracket')
			expect(upDraft?.data.aperture).toBeLessThan(0)
		})

		it('computes shape height handle for hairpins, slurs, and brackets', () => {
			const cresc = makeAnn({
				type: 'shape',
				data: { shapeType: 'crescendo', points: [{ x: 0.1, y: 0.5 }, { x: 0.5, y: 0.5 }], aperture: 25 },
			})
			expect(getShapeHeightHandle(cresc, 1000)).toEqual({ x: 500, y: 525 })

			const slur = makeAnn({
				type: 'shape',
				data: { shapeType: 'slur', points: [{ x: 0.1, y: 0.5 }, { x: 0.5, y: 0.5 }], curvature: -40 },
			})
			expect(getShapeHeightHandle(slur, 1000)).toEqual({ x: 300, y: 480 })
		})
	})

	describe('getAnnotationPath & isPathShape', () => {
		it('identifies path shapes vs box shapes', () => {
			expect(isPathShape(makeAnn({ type: 'shape', data: { shapeType: 'arrow' } }))).toBe(true)
			expect(isPathShape(makeAnn({ type: 'shape', data: { shapeType: 'crescendo' } }))).toBe(true)
			expect(isPathShape(makeAnn({ type: 'shape', data: { shapeType: 'slur' } }))).toBe(true)
			expect(isPathShape(makeAnn({ type: 'shape', data: { shapeType: 'rect' } }))).toBe(false)
			expect(isPathShape(makeAnn({ type: 'shape', data: { shapeType: 'ellipse' } }))).toBe(false)
		})

		it('computes hairpin path for crescendo shape', () => {
			const ann = makeAnn({
				type: 'shape',
				data: { shapeType: 'crescendo', points: [{ x: 0.2, y: 0.5 }, { x: 0.6, y: 0.5 }], aperture: 12 },
			})
			expect(getAnnotationPath(ann, 1000)).toBe('M 600.0 512.0 L 200.0 500.0 L 600.0 488.0')
		})
	})

	describe('SMuFL music symbols & fonts', () => {
		it('looks up canonical SMuFL stamp definitions directly', () => {
			expect(getStampDefinition('\uE52F')?.label).toContain('ff')
			expect(getStampDefinition('\uE262')?.label).toBe('Sharp')
			expect(getStampDefinition('\uED13')?.category).toBe('fingering')
			expect(getStampDefinition('\uE661')?.label).toContain('Organ heel')
		})

		it('uses single bundled Bravura SMuFL font for all stamps', () => {
			const dyn = makeAnn({ type: 'stamp', data: { symbol: '\uE52F' } })
			expect(getAnnotationDisplaySymbol(dyn)).toBe('\uE52F')
			expect(MUSIC_FONT_FAMILY).toContain('Bravura')

			const fingering = makeAnn({ type: 'stamp', data: { symbol: '\uED13', category: 'fingering' } })
			const dynBounds = getStampInkBounds(dyn, 1000, 1)
			const fingBounds = getStampInkBounds(fingering, 1000, 1)
			expect(fingBounds.width).toBeLessThan(dynBounds.width)
			expect(fingBounds.height).toBeLessThan(dynBounds.height)
		})

		it('uses unified SMuFL font size across musical symbols and fingerings', () => {
			const dyn = makeAnn({ type: 'stamp', style: { fontSize: 36 }, data: { symbol: '\uE522', category: 'dynamic' } })
			const fingering = makeAnn({ type: 'stamp', style: { fontSize: 36 }, data: { symbol: '\uED13', category: 'fingering' } })
			const defaultFingering = makeAnn({ type: 'stamp', style: {}, data: { symbol: '\uED10', category: 'fingering' } })

			expect(getStampRenderFontSize(dyn)).toBe(36)
			expect(getStampRenderFontSize(fingering)).toBe(36)
			expect(getStampRenderFontSize(defaultFingering)).toBe(40)
		})

		it('computes ink-centered render position from Bravura SMuFL metrics', () => {
			const accent = makeAnn({
				type: 'stamp',
				x: 0.5,
				y: 0.5,
				width: 0,
				height: 0,
				style: { fontSize: 40 },
				data: { symbol: '\uE4A0' },
			})
			const pos = getStampRenderPosition(accent, 1000)
			expect(pos.dominantBaseline).toBe('alphabetic')
			expect(pos.x).toBeCloseTo(500, 1)
			expect(pos.y).toBeCloseTo(500 + 0.123 * 40, 1)

			const fingering = makeAnn({
				type: 'stamp',
				x: 0.5,
				y: 0.5,
				width: 0,
				height: 0,
				style: { fontSize: 40 },
				data: { symbol: '\uED12' },
			})
			expect(getStampRenderPosition(fingering, 1000)).toEqual({
				x: 500,
				y: 505,
				dominantBaseline: 'alphabetic',
			})
		})

		it('anchors SMuFL noteheads at center (baseline y=0) with flag horizontal compensation', () => {
			const quarter = makeAnn({ type: 'stamp', x: 0.5, y: 0.5, width: 0, height: 0, style: { fontSize: 40 }, data: { symbol: '\uE1D5' } })
			const eighth = makeAnn({ type: 'stamp', x: 0.5, y: 0.5, width: 0, height: 0, style: { fontSize: 40 }, data: { symbol: '\uE1D7' } })

			expect(getStampRenderFontSize(quarter)).toBe(40)
			expect(getStampRenderFontSize(eighth)).toBe(40)

			expect(getStampRenderPosition(quarter, 1000)).toEqual({
				x: 500,
				y: 500,
				dominantBaseline: 'alphabetic',
			})
			// Eighth notehead centered at (500, 500) -> shifted right by 0.117 * 40 = 4.7 to offset flag
			expect(getStampRenderPosition(eighth, 1000)).toEqual({
				x: 504.7,
				y: 500,
				dominantBaseline: 'alphabetic',
			})
		})
	})

	describe('text layout & geometry helpers', () => {
		it('computes isotropic text layout with alignment and multi-line support', () => {
			const ann = makeAnn({
				type: 'text',
				x: 0.1,
				y: 0.2,
				width: 0.4,
				height: 0.1,
				style: { fontSize: 20, textAlign: 'center', fontFamily: 'serif' },
				data: { text: 'Allegro\nvivace' },
			})
			const layout = getAnnotationTextLayout(ann, 1000)
			expect(layout.x).toBe(300) // (0.1 + 0.4/2) * 1000
			expect(layout.textAnchor).toBe('middle')
			expect(layout.fontFamily).toBe('serif')
			expect(layout.lines).toEqual(['Allegro', 'vivace'])
		})

		it('maps standard text font families cleanly', () => {
			expect(getAnnotationFontFamily('serif')).toBe('serif')
			expect(getAnnotationFontFamily('monospace')).toBe('monospace')
			expect(getAnnotationFontFamily(null)).toBe('sans-serif')
		})

		it('computes bounding box from points and translates annotations', () => {
			const box = computeBoundingBoxFromPoints([{ x: 0.2, y: 0.3 }, { x: 0.5, y: 0.7 }], 0)
			expect(box).toEqual({ x: 0.2, y: 0.3, width: 0.3, height: 0.4 })

			const ann = makeAnn({
				x: 0.2,
				y: 0.3,
				data: { points: [{ x: 0.2, y: 0.3 }, { x: 0.5, y: 0.7 }] },
			})
			const moved = translateAnnotation(ann, 0.1, -0.1)
			expect(moved.x).toBe(0.3)
			expect(moved.y).toBe(0.2)
			expect(moved.data.points).toEqual([{ x: 0.3, y: 0.2 }, { x: 0.6, y: 0.6 }])
			expect(VIEWBOX_WIDTH).toBe(1000)
		})
	})
})
