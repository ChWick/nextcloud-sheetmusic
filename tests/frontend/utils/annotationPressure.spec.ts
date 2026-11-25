/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import type { SheetAnnotation } from '../../../src/types/annotation'
import {
	generatePressureStrokePath,
	getAnnotationPressurePath,
	hasPressurePoints,
} from '../../../src/utils/annotationPressure'

function makeDrawing(overrides: Partial<SheetAnnotation> = {}): SheetAnnotation {
	return {
		uuid: 'p-1',
		songId: 1,
		fileId: 2,
		pageNumber: 0,
		layerIndex: 0,
		type: 'drawing',
		x: 0.1,
		y: 0.1,
		width: 0.2,
		height: 0.2,
		style: { stroke: '#000', strokeWidth: 3, lineStyle: 'solid' },
		data: {
			points: [
				{ x: 0.1, y: 0.1, p: 0.2 },
				{ x: 0.2, y: 0.15, p: 0.6 },
				{ x: 0.3, y: 0.2, p: 0.9 },
			],
		},
		...overrides,
	}
}

describe('annotationPressure', () => {
	it('detects pressure data only on solid freehand drawings', () => {
		expect(hasPressurePoints(makeDrawing())).toBe(true)
		expect(hasPressurePoints(makeDrawing({ type: 'highlight' }))).toBe(false)
		expect(hasPressurePoints(makeDrawing({ style: { lineStyle: 'dashed' } }))).toBe(false)
		expect(hasPressurePoints(makeDrawing({ data: { points: [{ x: 0.1, y: 0.1 }] } }))).toBe(false)
		expect(hasPressurePoints(makeDrawing({
			data: {
				strokes: [
					[{ x: 0.1, y: 0.1 }],
					[{ x: 0.2, y: 0.2, p: 0.7 }],
				],
			},
		}))).toBe(true)
	})

	it('generates closed SVG outline paths for single dots and multi-point strokes', () => {
		expect(generatePressureStrokePath([], 3, 1414)).toBe('')

		const dotPath = generatePressureStrokePath([{ x: 0.5, y: 0.5, p: 0.5 }], 4, 1414)
		expect(dotPath.startsWith('M ')).toBe(true)
		expect(dotPath).toContain(' A ')
		expect(dotPath.endsWith(' Z')).toBe(true)

		const strokePath = generatePressureStrokePath(
			[
				{ x: 0.1, y: 0.1, p: 0.1 },
				{ x: 0.2, y: 0.12, p: 0.5 },
				{ x: 0.3, y: 0.15, p: 0.9 },
			],
			4,
			1414,
		)
		expect(strokePath.startsWith('M ')).toBe(true)
		expect(strokePath).toContain(' Q ')
		expect(strokePath.endsWith(' Z')).toBe(true)
	})

	it('combines multi-stroke pressure paths in getAnnotationPressurePath', () => {
		const ann = makeDrawing({
			data: {
				strokes: [
					[{ x: 0.1, y: 0.1, p: 0.5 }, { x: 0.2, y: 0.1, p: 0.8 }],
					[{ x: 0.3, y: 0.3, p: 0.4 }, { x: 0.4, y: 0.3, p: 0.7 }],
				],
			},
		})
		const path = getAnnotationPressurePath(ann, 1414)
		expect(path.split('M ').length - 1).toBe(2)
	})
})
