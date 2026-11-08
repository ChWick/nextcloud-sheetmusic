/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { SheetAnnotation } from '../types/annotation'

/**
 * Generate an SVG path with quadratic Bezier midpoint curve smoothing (matching MobileSheets & Android Path.quadTo).
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

	// Quadratic Bezier midpoint smoothing: connects midpoints between successive control points
	let d = `M ${sx0} ${sy0}`
	for (let i = 1; i < count - 1; i++) {
		const cx = (filtered[i].x * 1000).toFixed(1)
		const cy = (filtered[i].y * 1000).toFixed(1)
		const endX = (((filtered[i].x + filtered[i + 1].x) / 2) * 1000).toFixed(1)
		const endY = (((filtered[i].y + filtered[i + 1].y) / 2) * 1000).toFixed(1)
		d += ` Q ${cx} ${cy}, ${endX} ${endY}`
	}
	const lastX = (filtered[count - 1].x * 1000).toFixed(1)
	const lastY = (filtered[count - 1].y * 1000).toFixed(1)
	d += ` L ${lastX} ${lastY}`

	return d
}

/**
 * Determine the stroke-dasharray for an annotation.
 * Dashes scale with stroke width and feature wider dash segments matching MobileSheets.
 */
export function getAnnotationDashArray(item: SheetAnnotation): string | undefined {
	const raw = item.style?.strokeDashArray
	const isDashed = item.style?.lineStyle === 'dashed' || item.data?.penMode === 3 || raw === '16, 12' || raw === '36, 20'
	const isDotted = item.style?.lineStyle === 'dotted' || item.data?.penMode === 2 || raw === '4, 8' || raw === '4, 12'

	const sw = Number(item.style?.strokeWidth) || 2.0

	if (isDashed) {
		const dash = Math.max(36, Math.round(sw * 2.5))
		const gap = Math.max(20, Math.round(sw * 1.5))
		return `${dash}, ${gap}`
	}

	if (isDotted) {
		const gap = Math.max(12, Math.round(sw * 2.0))
		return `1, ${gap}`
	}

	if (raw) {
		return raw
	}

	return undefined
}
