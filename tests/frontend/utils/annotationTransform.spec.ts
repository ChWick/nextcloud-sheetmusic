/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import type { EditorToolStyle } from '../../../src/composables/useAnnotationEditor'
import type { SheetAnnotation } from '../../../src/types/annotation'
import {
	applyEndpointMove,
	applyHeightMove,
	applyResizeMove,
	buildAnnotationDraft,
	roundNorm,
} from '../../../src/utils/annotationTransform'

const baseStyle: EditorToolStyle = {
	color: '#e53935',
	highlightColor: '#ffeb3b',
	strokeWidth: 3,
	highlightWidth: 18,
	opacity: 0.9,
	highlightOpacity: 0.4,
	lineStyle: 'dashed',
	fontSize: 22,
	fontFamily: 'sans-serif',
	textAlign: 'left',
	fill: 'transparent',
	shapeHeight: 20,
	stampSymbol: '𝆑',
	stampSize: 44,
}

describe('annotationTransform', () => {
	it('rounds normalized coordinates to 4 decimal places', () => {
		expect(roundNorm(0.123456)).toBe(0.1235)
		expect(roundNorm(0.5)).toBe(0.5)
	})

	it('resizes box annotations without inverting bounds', () => {
		const rect: SheetAnnotation = {
			uuid: 'r1',
			songId: 1,
			fileId: 2,
			pageNumber: 0,
			type: 'shape',
			x: 0.2,
			y: 0.2,
			width: 0.3,
			height: 0.2,
			style: { stroke: '#000' },
			data: { shapeType: 'rect' },
		}

		const grown = applyResizeMove(rect, 'se', { x: 0.65, y: 0.55 }, 1000)
		expect(grown.x).toBe(0.2)
		expect(grown.y).toBe(0.2)
		expect(grown.width).toBe(0.45)
		expect(grown.height).toBe(0.35)

		// Clamps minimum dimension to 0.01 when dragged past opposite edge
		const clamped = applyResizeMove(rect, 'nw', { x: 0.9, y: 0.9 }, 1000)
		expect(clamped.width).toBe(0.01)
		expect(clamped.height).toBe(0.01)
	})

	it('updates 2-point shape endpoints and recomputes bounding box', () => {
		const line: SheetAnnotation = {
			uuid: 'l1',
			songId: 1,
			fileId: 2,
			pageNumber: 0,
			type: 'shape',
			x: 0.1,
			y: 0.2,
			width: 0.3,
			height: 0.01,
			style: { stroke: '#000' },
			data: { shapeType: 'line', points: [{ x: 0.1, y: 0.2 }, { x: 0.4, y: 0.2 }] },
		}

		const moved = applyEndpointMove(line, 'p1', { x: 0.6, y: 0.5 })
		expect(moved?.data.points?.[1]).toEqual({ x: 0.6, y: 0.5 })
		expect(moved?.width).toBeCloseTo(0.52, 2)
		expect(moved?.height).toBeCloseTo(0.32, 2)
	})

	it('adjusts crescendo aperture and slur curvature via height handle', () => {
		const cresc: SheetAnnotation = {
			uuid: 'c1',
			songId: 1,
			fileId: 2,
			pageNumber: 0,
			type: 'shape',
			x: 0.1,
			y: 0.5,
			width: 0.4,
			height: 0.02,
			style: { stroke: '#000' },
			data: { shapeType: 'crescendo', points: [{ x: 0.1, y: 0.5 }, { x: 0.5, y: 0.5 }], aperture: 18 },
		}
		const updatedCresc = applyHeightMove(cresc, { x: 0.5, y: 0.54 }, 1000)
		expect(updatedCresc?.data.aperture).toBe(40)

		const slur: SheetAnnotation = {
			...cresc,
			uuid: 's1',
			data: { shapeType: 'slur', points: [{ x: 0.1, y: 0.5 }, { x: 0.5, y: 0.5 }], curvature: -18 },
		}
		const updatedSlur = applyHeightMove(slur, { x: 0.3, y: 0.46 }, 1000)
		expect(updatedSlur?.data.curvature).toBe(-80)
	})

	it('builds drafts for drawing, highlight, 2-point shapes, rect, and stamp', () => {
		const highlight = buildAnnotationDraft('highlight', baseStyle, 2, { x: 0.1, y: 0.1 }, { x: 0.3, y: 0.1 }, [
			{ x: 0.1, y: 0.1 },
			{ x: 0.3, y: 0.1 },
		])
		expect(highlight?.type).toBe('highlight')
		expect(highlight?.pageNumber).toBe(2)
		expect(highlight?.style.blendMode).toBe('multiply')
		expect(highlight?.style.strokeWidth).toBe(18)

		const slur = buildAnnotationDraft('slur', baseStyle, 0, { x: 0.1, y: 0.2 }, { x: 0.4, y: 0.2 }, [])
		expect(slur?.type).toBe('shape')
		expect(slur?.data.shapeType).toBe('slur')
		expect(slur?.data.curvature).toBe(-20)

		const stamp = buildAnnotationDraft('stamp', baseStyle, 1, { x: 0.1, y: 0.1 }, { x: 0.5, y: 0.6 }, [])
		expect(stamp?.type).toBe('stamp')
		expect(stamp?.x).toBe(0.5)
		expect(stamp?.y).toBe(0.6)
		expect(stamp?.width).toBe(0)
		expect(stamp?.style.fontSize).toBe(44)
		expect(stamp?.data.symbol).toBe('𝆑')

		expect(buildAnnotationDraft('select', baseStyle, 0, { x: 0, y: 0 }, { x: 0, y: 0 }, [])).toBeNull()
	})
})
