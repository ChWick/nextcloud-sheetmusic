/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { NormalizedPoint, ShapeType, SheetAnnotation } from '../types/annotation'
import type { EditorTool, EditorToolStyle } from '../composables/useAnnotationEditor'
import { isShapeTool } from '../composables/useAnnotationEditor'
import { computeBoundingBoxFromPoints, VIEWBOX_WIDTH } from './annotation'
import { getStampDefinition } from './musicSymbols'

export function roundNorm(val: number): number {
	return Math.round(val * 10000) / 10000
}

export function applyResizeMove(
	b: SheetAnnotation,
	handle: string,
	pt: NormalizedPoint,
	vbHeight: number,
): SheetAnnotation {
	let x1 = b.x
	let y1 = b.y
	let x2 = b.x + b.width
	let y2 = b.y + b.height
	if (handle.includes('w')) x1 = Math.min(x2 - 0.01, pt.x)
	if (handle.includes('e')) x2 = Math.max(x1 + 0.01, pt.x)
	if (handle.includes('n')) y1 = Math.min(y2 - 0.01, pt.y)
	if (handle.includes('s')) y2 = Math.max(y1 + 0.01, pt.y)

	const next: SheetAnnotation = JSON.parse(JSON.stringify(b))
	next.x = roundNorm(x1)
	next.y = roundNorm(y1)
	next.width = roundNorm(x2 - x1)
	next.height = roundNorm(y2 - y1)
	if (next.type === 'stamp') {
		next.style.fontSize = Math.round(next.height * vbHeight * 0.85)
	}
	return next
}

export function applyEndpointMove(
	b: SheetAnnotation,
	handle: string,
	pt: NormalizedPoint,
): SheetAnnotation | null {
	const next: SheetAnnotation = JSON.parse(JSON.stringify(b))
	const pts = next.data.points ?? []
	const idx = handle === 'p0' ? 0 : 1
	if (!pts[idx]) return null
	pts[idx] = pt
	const box = computeBoundingBoxFromPoints(pts, 0.01)
	next.x = box.x
	next.y = box.y
	next.width = box.width
	next.height = box.height
	return next
}

export function applyHeightMove(
	b: SheetAnnotation,
	pt: NormalizedPoint,
	vbHeight: number,
): SheetAnnotation | null {
	const next: SheetAnnotation = JSON.parse(JSON.stringify(b))
	const pts = next.data.points ?? []
	if (pts.length < 2) return null

	const x1 = pts[0].x * VIEWBOX_WIDTH
	const y1 = pts[0].y * vbHeight
	const x2 = pts[1].x * VIEWBOX_WIDTH
	const y2 = pts[1].y * vbHeight
	const dx = x2 - x1
	const dy = y2 - y1
	const len = Math.hypot(dx, dy) || 1.0
	const nx = -dy / len
	const ny = dx / len

	const px = pt.x * VIEWBOX_WIDTH
	const py = pt.y * vbHeight
	const st = next.data.shapeType
	const baseX = st === 'crescendo' ? x2 : st === 'decrescendo' || st === 'bracket' ? x1 : (x1 + x2) / 2
	const baseY = st === 'crescendo' ? y2 : st === 'decrescendo' || st === 'bracket' ? y1 : (y1 + y2) / 2
	const proj = (px - baseX) * nx + (py - baseY) * ny

	if (st === 'slur') {
		next.data.curvature = Math.round(Math.max(-180, Math.min(180, proj * 2)) * 10) / 10
	} else if (st === 'bracket') {
		const clamped = Math.round(Math.max(-120, Math.min(120, proj)) * 10) / 10
		next.data.aperture = Math.abs(clamped) < 4 ? (clamped < 0 ? -4 : 4) : clamped
	} else {
		next.data.aperture = Math.round(Math.max(4, Math.min(120, Math.abs(proj))) * 10) / 10
	}
	return next
}

export function buildAnnotationDraft(
	tool: EditorTool,
	s: EditorToolStyle,
	pageNumber: number,
	p1: NormalizedPoint,
	p2: NormalizedPoint,
	strokePoints: NormalizedPoint[],
): Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> | null {
	if (tool === 'drawing' || tool === 'highlight') {
		const pts = strokePoints.length >= 2 ? strokePoints : [p1, { x: Math.min(1, p1.x + 0.002), y: p1.y }]
		const box = computeBoundingBoxFromPoints(pts)
		return {
			pageNumber,
			layerIndex: 0,
			type: tool,
			...box,
			style: {
				stroke: tool === 'highlight' ? s.highlightColor : s.color,
				strokeWidth: tool === 'highlight' ? s.highlightWidth : s.strokeWidth,
				opacity: tool === 'highlight' ? s.highlightOpacity : s.opacity,
				blendMode: tool === 'highlight' ? 'multiply' : 'normal',
				lineStyle: tool === 'highlight' ? 'solid' : s.lineStyle,
			},
			data: { points: pts, smooth: true },
		}
	}

	if (isShapeTool(tool)) {
		if (tool === 'rect' || tool === 'ellipse') {
			return {
				pageNumber,
				layerIndex: 0,
				type: 'shape',
				x: roundNorm(Math.min(p1.x, p2.x)),
				y: roundNorm(Math.min(p1.y, p2.y)),
				width: roundNorm(Math.max(0.01, Math.abs(p2.x - p1.x))),
				height: roundNorm(Math.max(0.01, Math.abs(p2.y - p1.y))),
				style: {
					stroke: s.color,
					strokeWidth: s.strokeWidth,
					opacity: s.opacity,
					lineStyle: s.lineStyle,
					fill: s.fill,
				},
				data: { shapeType: tool as ShapeType },
			}
		}

		const box = computeBoundingBoxFromPoints([p1, p2], 0.01)
		const shapeType: ShapeType = tool === 'bracket-up' ? 'bracket' : (tool as ShapeType)
		const shapeData: SheetAnnotation['data'] = {
			shapeType,
			points: [p1, p2],
		}
		if (tool === 'bracket-up') {
			shapeData.aperture = -Math.abs(s.shapeHeight || 18)
		} else if ((tool === 'crescendo' || tool === 'decrescendo' || tool === 'bracket') && s.shapeHeight) {
			shapeData.aperture = s.shapeHeight
		} else if (tool === 'slur' && s.shapeHeight) {
			shapeData.curvature = -Math.abs(s.shapeHeight)
		}
		return {
			pageNumber,
			layerIndex: 0,
			type: 'shape',
			...box,
			style: {
				stroke: s.color,
				strokeWidth: s.strokeWidth,
				opacity: s.opacity,
				lineStyle: s.lineStyle,
			},
			data: shapeData,
		}
	}

	if (tool === 'stamp') {
		const def = getStampDefinition(s.stampSymbol)
		return {
			pageNumber,
			layerIndex: 0,
			type: 'stamp',
			x: roundNorm(p2.x),
			y: roundNorm(p2.y),
			width: 0,
			height: 0,
			style: {
				color: s.color,
				fontSize: s.stampSize || 40,
				opacity: s.opacity,
			},
			data: {
				symbol: def?.symbol ?? s.stampSymbol,
				category: def?.category,
			},
		}
	}

	return null
}
