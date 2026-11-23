/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { NormalizedPoint, SheetAnnotation } from '../types/annotation'
import { VIEWBOX_WIDTH } from './annotation'

/**
 * Determine whether an annotation has per-point pressure data and should be rendered
 * with variable stroke width. Only applies to solid freehand drawings.
 */
export function hasPressurePoints(item: SheetAnnotation): boolean {
	if (item.type !== 'drawing') return false
	if (item.style?.lineStyle && item.style.lineStyle !== 'solid') return false
	const hasInList = (pts?: NormalizedPoint[]) =>
		Array.isArray(pts) && pts.length > 0 && pts.some((pt) => typeof pt.p === 'number' && Number.isFinite(pt.p))
	if (Array.isArray(item.data?.strokes) && item.data.strokes.length > 0) {
		return item.data.strokes.some((s) => hasInList(s))
	}
	return hasInList(item.data?.points)
}

/**
 * Generate a closed filled SVG outline path for a pressure-sensitive stroke.
 * Neutral pressure (p = 0.5) matches baseStrokeWidth; lower/higher p tapers/swells smoothly.
 */
export function generatePressureStrokePath(
	points: NormalizedPoint[],
	baseStrokeWidth: number,
	vbHeight: number = VIEWBOX_WIDTH,
): string {
	if (!points || points.length === 0) return ''

	const filtered: Array<{ x: number; y: number; p: number }> = []
	for (const pt of points) {
		const sx = pt.x * VIEWBOX_WIDTH
		const sy = pt.y * vbHeight
		const p = typeof pt.p === 'number' && Number.isFinite(pt.p)
			? Math.min(1, Math.max(0, pt.p))
			: 0.5
		const last = filtered[filtered.length - 1]
		if (!last || Math.hypot(sx - last.x, sy - last.y) > 0.25) {
			filtered.push({ x: sx, y: sy, p })
		}
	}

	const count = filtered.length
	if (count === 0) return ''

	const base = Math.max(0.5, baseStrokeWidth || 2.5)
	const radiusForPressure = (p: number) => Math.max(0.35, base * 0.5 * (0.28 + 1.44 * p))

	if (count === 1) {
		const { x, y, p } = filtered[0]
		const r = radiusForPressure(p)
		const rStr = r.toFixed(2)
		return `M ${(x - r).toFixed(1)} ${y.toFixed(1)} A ${rStr} ${rStr} 0 1 0 ${(x + r).toFixed(1)} ${y.toFixed(1)} A ${rStr} ${rStr} 0 1 0 ${(x - r).toFixed(1)} ${y.toFixed(1)} Z`
	}

	// Smooth pressure values with a 3-point kernel to avoid jitter
	const smoothedP: number[] = filtered.map((pt, i) => {
		if (i === 0 || i === count - 1) return pt.p
		return filtered[i - 1].p * 0.25 + pt.p * 0.5 + filtered[i + 1].p * 0.25
	})

	const left: Array<{ x: number; y: number }> = []
	const right: Array<{ x: number; y: number }> = []

	for (let i = 0; i < count; i++) {
		const cur = filtered[i]
		let tx = 0
		let ty = 0
		if (i === 0) {
			tx = filtered[1].x - cur.x
			ty = filtered[1].y - cur.y
		} else if (i === count - 1) {
			tx = cur.x - filtered[i - 1].x
			ty = cur.y - filtered[i - 1].y
		} else {
			const dx1 = cur.x - filtered[i - 1].x
			const dy1 = cur.y - filtered[i - 1].y
			const len1 = Math.hypot(dx1, dy1) || 1
			const dx2 = filtered[i + 1].x - cur.x
			const dy2 = filtered[i + 1].y - cur.y
			const len2 = Math.hypot(dx2, dy2) || 1
			tx = dx1 / len1 + dx2 / len2
			ty = dy1 / len1 + dy2 / len2
			if (Math.hypot(tx, ty) < 0.001) {
				tx = filtered[i + 1].x - filtered[i - 1].x
				ty = filtered[i + 1].y - filtered[i - 1].y
			}
		}
		const tLen = Math.hypot(tx, ty) || 1
		tx /= tLen
		ty /= tLen

		const nx = -ty
		const ny = tx
		const r = radiusForPressure(smoothedP[i])
		left.push({ x: cur.x + nx * r, y: cur.y + ny * r })
		right.push({ x: cur.x - nx * r, y: cur.y - ny * r })
	}

	const appendSmoothChain = (pts: Array<{ x: number; y: number }>): string => {
		if (pts.length <= 2) {
			let seg = ''
			for (let i = 1; i < pts.length; i++) {
				seg += ` L ${pts[i].x.toFixed(1)} ${pts[i].y.toFixed(1)}`
			}
			return seg
		}
		let seg = ''
		for (let i = 1; i < pts.length - 1; i++) {
			const midX = ((pts[i].x + pts[i + 1].x) / 2).toFixed(1)
			const midY = ((pts[i].y + pts[i + 1].y) / 2).toFixed(1)
			seg += ` Q ${pts[i].x.toFixed(1)} ${pts[i].y.toFixed(1)}, ${midX} ${midY}`
		}
		const last = pts[pts.length - 1]
		seg += ` L ${last.x.toFixed(1)} ${last.y.toFixed(1)}`
		return seg
	}

	const rStart = radiusForPressure(smoothedP[0]).toFixed(2)
	const rEnd = radiusForPressure(smoothedP[count - 1]).toFixed(2)
	const rightReversed = [...right].reverse()

	let d = `M ${left[0].x.toFixed(1)} ${left[0].y.toFixed(1)}`
	d += appendSmoothChain(left)
	d += ` A ${rEnd} ${rEnd} 0 0 0 ${rightReversed[0].x.toFixed(1)} ${rightReversed[0].y.toFixed(1)}`
	d += appendSmoothChain(rightReversed)
	d += ` A ${rStart} ${rStart} 0 0 0 ${left[0].x.toFixed(1)} ${left[0].y.toFixed(1)} Z`

	return d
}

/**
 * Compute the filled variable-width SVG path `d` attribute for pressure-bearing drawing annotations.
 */
export function getAnnotationPressurePath(item: SheetAnnotation, vbHeight: number = VIEWBOX_WIDTH): string {
	const sw = Number(item.style?.strokeWidth) || 2.5
	const strokes = item.data?.strokes
	if (Array.isArray(strokes) && strokes.length > 0) {
		return strokes
			.map((s) => generatePressureStrokePath(s, sw, vbHeight))
			.filter(Boolean)
			.join(' ')
	}
	if (item.data?.points && item.data.points.length > 0) {
		return generatePressureStrokePath(item.data.points, sw, vbHeight)
	}
	return ''
}
