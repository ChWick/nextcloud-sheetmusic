/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { generateSmoothSvgPath, getAnnotationDashArray } from '../../../src/utils/annotation'
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

		it('applies Catmull-Rom cubic Bezier curve smoothing for 3+ points when smooth is true', () => {
			const points = [
				{ x: 0.1, y: 0.1 },
				{ x: 0.2, y: 0.2 },
				{ x: 0.3, y: 0.25 },
			]
			const path = generateSmoothSvgPath(points, true)
			expect(path).toContain(' C ')
			expect(path).toBe('M 100.0 100.0 C 116.7 116.7, 166.7 175.0, 200.0 200.0 C 233.3 225.0, 283.3 241.7, 300.0 250.0')
		})

		it('uses linear segments for 3+ points when smooth is false', () => {
			const points = [
				{ x: 0.1, y: 0.1 },
				{ x: 0.2, y: 0.2 },
				{ x: 0.3, y: 0.25 },
			]
			const path = generateSmoothSvgPath(points, false)
			expect(path).not.toContain(' C ')
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
			expect(getAnnotationDashArray(ann)).toBe('16, 12')
		})

		it('returns dotted pattern for lineStyle dotted', () => {
			const ann: SheetAnnotation = {
				...baseAnnotation,
				style: { lineStyle: 'dotted' },
			}
			expect(getAnnotationDashArray(ann)).toBe('4, 8')
		})

		it('returns dashed pattern for penMode 3', () => {
			const ann: SheetAnnotation = {
				...baseAnnotation,
				data: { penMode: 3 },
			}
			expect(getAnnotationDashArray(ann)).toBe('16, 12')
		})

		it('returns dotted pattern for penMode 2', () => {
			const ann: SheetAnnotation = {
				...baseAnnotation,
				data: { penMode: 2 },
			}
			expect(getAnnotationDashArray(ann)).toBe('4, 8')
		})

		it('returns undefined for solid lines (penMode 1 or default)', () => {
			const ann: SheetAnnotation = {
				...baseAnnotation,
				data: { penMode: 1 },
			}
			expect(getAnnotationDashArray(ann)).toBeUndefined()
		})
	})
})
