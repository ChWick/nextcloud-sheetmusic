/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { SheetAnnotation } from '../types/annotation'

/**
 * Generate an SVG path with Catmull-Rom cubic Bezier curve smoothing for 3+ points.
 */
export function generateSmoothSvgPath(points: Array<{ x: number; y: number }>, smooth = true): string {
	if (!points || points.length === 0) {
		return ''
	}

	const filtered: Array<{ x: number; y: number }> = []
	for (const pt of points) {
		const last = filtered[filtered.length - 1]
		if (!last || Math.abs(last.x - pt.x) > 0.0001 || Math.abs(last.y - pt.y) > 0.0001) {
			filtered.push(pt)
		}
	}

	const count = filtered.length
	if (count === 0) return ''

	const sx0 = (filtered[0].x * 1000).toFixed(1)
	const sy0 = (filtered[0].y * 1000).toFixed(1)

	if (count === 1) {
		return `M ${sx0} ${sy0}`
	}
	if (count === 2 || !smooth) {
		let d = `M ${sx0} ${sy0}`
		for (let i = 1; i < count; i++) {
			d += ` L ${(filtered[i].x * 1000).toFixed(1)} ${(filtered[i].y * 1000).toFixed(1)}`
		}
		return d
	}

	let d = `M ${sx0} ${sy0}`
	for (let i = 0; i < count - 1; i++) {
		const p0 = filtered[Math.max(0, i - 1)]
		const p1 = filtered[i]
		const p2 = filtered[i + 1]
		const p3 = filtered[Math.min(count - 1, i + 2)]

		const cp1x = ((p1.x + (p2.x - p0.x) / 6.0) * 1000).toFixed(1)
		const cp1y = ((p1.y + (p2.y - p0.y) / 6.0) * 1000).toFixed(1)
		const cp2x = ((p2.x - (p3.x - p1.x) / 6.0) * 1000).toFixed(1)
		const cp2y = ((p2.y - (p3.y - p1.y) / 6.0) * 1000).toFixed(1)
		const endX = (p2.x * 1000).toFixed(1)
		const endY = (p2.y * 1000).toFixed(1)

		d += ` C ${cp1x} ${cp1y}, ${cp2x} ${cp2y}, ${endX} ${endY}`
	}

	return d
}

/**
 * Determine the stroke-dasharray for an annotation.
 * Supports explicit strokeDashArray, lineStyle ('dashed' | 'dotted'), and MobileSheets penMode (3 = dashed, 2 = dotted).
 */
export function getAnnotationDashArray(item: SheetAnnotation): string | undefined {
	if (item.style?.strokeDashArray) {
		return item.style.strokeDashArray
	}
	if (item.style?.lineStyle === 'dashed' || item.data?.penMode === 3) {
		return '16, 12'
	}
	if (item.style?.lineStyle === 'dotted' || item.data?.penMode === 2) {
		return '4, 8'
	}
	return undefined
}
