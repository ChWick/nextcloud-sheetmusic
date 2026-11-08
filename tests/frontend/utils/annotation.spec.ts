/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import {
	generateHairpinPath,
	generateSmoothSvgPath,
	getAnnotationDashArray,
	getAnnotationDisplaySymbol,
	getAnnotationFontFamily,
	getAnnotationPath,
} from '../../../src/utils/annotation'
import type { SheetAnnotation } from '../../../src/types/annotation'

describe('annotation utils', () => {
	describe('generateSmoothSvgPath', () => {
		it('returns empty string for empty points array', () => {
			expect(generateSmoothSvgPath([])).toBe('')
		})

		it('returns single move command for 1 point', () => {
			expect(generateSmoothSvgPath([{ x: 0.1, y: 0.2 }])).toBe('M 100.0 200.0')
		})

		it('returns line command for 2 points', () => {
			expect(generateSmoothSvgPath([
				{ x: 0.1, y: 0.2 },
				{ x: 0.3, y: 0.4 },
			])).toBe('M 100.0 200.0 L 300.0 400.0')
		})

		it('applies quadratic Bezier curve smoothing for 3+ points when smooth is true', () => {
			const points = [
				{ x: 0.1, y: 0.1 },
				{ x: 0.2, y: 0.2 },
				{ x: 0.3, y: 0.25 },
			]
			const path = generateSmoothSvgPath(points, true)
			expect(path).toContain(' Q ')
			expect(path).toBe('M 100.0 100.0 Q 200.0 200.0, 250.0 225.0 L 300.0 250.0')
		})

		it('uses linear segments for 3+ points when smooth is false', () => {
			const points = [
				{ x: 0.1, y: 0.1 },
				{ x: 0.2, y: 0.2 },
				{ x: 0.3, y: 0.25 },
			]
			const path = generateSmoothSvgPath(points, false)
			expect(path).not.toContain(' Q ')
			expect(path).toBe('M 100.0 100.0 L 200.0 200.0 L 300.0 250.0')
		})

		it('filters out consecutive duplicate points', () => {
			const points = [
				{ x: 0.1, y: 0.1 },
				{ x: 0.1, y: 0.1 },
				{ x: 0.5, y: 0.5 },
			]
			expect(generateSmoothSvgPath(points, false)).toBe('M 100.0 100.0 L 500.0 500.0')
		})
	})

	describe('getAnnotationDashArray', () => {
		const baseAnnotation: SheetAnnotation = {
			id: 1,
			pageNumber: 1,
			layerIndex: 0,
			type: 'drawing',
			x: 0,
			y: 0,
			width: 1,
			height: 1,
		}

		it('prefers explicit strokeDashArray', () => {
			const ann: SheetAnnotation = {
				...baseAnnotation,
				style: { strokeDashArray: '10, 5, 2, 5' },
			}
			expect(getAnnotationDashArray(ann)).toBe('10, 5, 2, 5')
		})

		it('returns dashed pattern for lineStyle dashed', () => {
			const ann: SheetAnnotation = {
				...baseAnnotation,
				style: { lineStyle: 'dashed' },
			}
			expect(getAnnotationDashArray(ann)).toBe('36, 20')
		})

		it('returns dotted pattern for lineStyle dotted', () => {
			const ann: SheetAnnotation = {
				...baseAnnotation,
				style: { lineStyle: 'dotted' },
			}
			expect(getAnnotationDashArray(ann)).toBe('1, 12')
		})

		it('returns dashed pattern for penMode 3', () => {
			const ann: SheetAnnotation = {
				...baseAnnotation,
				data: { penMode: 3 },
			}
			expect(getAnnotationDashArray(ann)).toBe('36, 20')
		})

		it('returns dotted pattern for penMode 2', () => {
			const ann: SheetAnnotation = {
				...baseAnnotation,
				data: { penMode: 2 },
			}
			expect(getAnnotationDashArray(ann)).toBe('1, 12')
		})

		it('scales dashes and gaps dynamically based on stroke width', () => {
			const ann: SheetAnnotation = {
				...baseAnnotation,
				style: { lineStyle: 'dashed', strokeWidth: 16 },
			}
			expect(getAnnotationDashArray(ann)).toBe('40, 24')
		})

		it('returns undefined for solid lines (penMode 1 or default)', () => {
			const ann: SheetAnnotation = {
				...baseAnnotation,
				data: { penMode: 1 },
			}
			expect(getAnnotationDashArray(ann)).toBeUndefined()
		})
	})

	describe('generateHairpinPath', () => {
		it('generates crescendo hairpin (<) from p1 to p2', () => {
			const p1 = { x: 0.1, y: 0.5 }
			const p2 = { x: 0.3, y: 0.5 }
			const path = generateHairpinPath(p1, p2, false)
			expect(path).toMatch(/^M 300\.0 \d+\.\d+ L 100\.0 500\.0 L 300\.0 \d+\.\d+$/)
		})

		it('generates decrescendo hairpin (>) when isDecrescendo is true', () => {
			const p1 = { x: 0.1, y: 0.5 }
			const p2 = { x: 0.3, y: 0.5 }
			const path = generateHairpinPath(p1, p2, true)
			expect(path).toMatch(/^M 100\.0 \d+\.\d+ L 300\.0 500\.0 L 100\.0 \d+\.\d+$/)
		})
	})

	describe('getAnnotationFontFamily', () => {
		it('maps numeric or 0 to sans-serif', () => {
			expect(getAnnotationFontFamily('0')).toBe('sans-serif')
			expect(getAnnotationFontFamily(0)).toBe('sans-serif')
			expect(getAnnotationFontFamily(null)).toBe('sans-serif')
			expect(getAnnotationFontFamily(undefined)).toBe('sans-serif')
		})

		it('maps 1 to serif and 2 to monospace', () => {
			expect(getAnnotationFontFamily('1')).toBe('serif')
			expect(getAnnotationFontFamily(1)).toBe('serif')
			expect(getAnnotationFontFamily('2')).toBe('monospace')
			expect(getAnnotationFontFamily(2)).toBe('monospace')
		})

		it('preserves named font families', () => {
			expect(getAnnotationFontFamily('Arial')).toBe('Arial')
			expect(getAnnotationFontFamily('Times New Roman')).toBe('Times New Roman')
		})
	})

	describe('getAnnotationDisplaySymbol', () => {
		it('returns displaySymbol if present', () => {
			const ann: SheetAnnotation = {
				id: 1,
				pageNumber: 0,
				layerIndex: 0,
				type: 'stamp',
				x: 0,
				y: 0,
				width: 0.1,
				height: 0.1,
				data: { displaySymbol: '𝄋', symbol: '∫' },
			}
			expect(getAnnotationDisplaySymbol(ann)).toBe('𝄋')
		})

		it('maps known symbols when displaySymbol is missing', () => {
			const ann: SheetAnnotation = {
				id: 1,
				pageNumber: 0,
				layerIndex: 0,
				type: 'stamp',
				x: 0,
				y: 0,
				width: 0.1,
				height: 0.1,
				data: { symbol: 'q' },
			}
			expect(getAnnotationDisplaySymbol(ann)).toBe('♩')

			// Double flat and double sharp
			expect(getAnnotationDisplaySymbol({ ...ann, data: { symbol: 'bb' } })).toBe('𝄫')
			expect(getAnnotationDisplaySymbol({ ...ann, data: { symbol: 'BB' } })).toBe('𝄫')
			expect(getAnnotationDisplaySymbol({ ...ann, data: { symbol: '##' } })).toBe('𝄪')
			expect(getAnnotationDisplaySymbol({ ...ann, data: { symbol: 'x' } })).toBe('𝄪')
			expect(getAnnotationDisplaySymbol({ ...ann, data: { displaySymbol: 'bb' } })).toBe('𝄫')
		})
	})

	describe('getAnnotationPath', () => {
		it('generates hairpin path for drawMode 4 shape', () => {
			const ann: SheetAnnotation = {
				id: 1,
				pageNumber: 0,
				layerIndex: 0,
				type: 'shape',
				x: 0.1,
				y: 0.5,
				width: 0.2,
				height: 0.03,
				data: {
					drawMode: 4,
					points: [{ x: 0.1, y: 0.5 }, { x: 0.3, y: 0.5 }],
				},
			}
			const path = getAnnotationPath(ann)
			expect(path).toContain('L 100.0 500.0')
		})
	})
})
