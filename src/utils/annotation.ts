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

/**
 * Generate an SVG path for a crescendo (<) or decrescendo (>) hairpin.
 */
export function generateHairpinPath(
	p1: { x: number; y: number },
	p2: { x: number; y: number },
	isDecrescendo = false,
	aperture?: number,
): string {
	const x1 = p1.x * 1000
	const y1 = p1.y * 1000
	const x2 = p2.x * 1000
	const y2 = p2.y * 1000

	const dx = x2 - x1
	const dy = y2 - y1
	const len = Math.hypot(dx, dy) || 1.0

	// Unit perpendicular vector
	const nx = -dy / len
	const ny = dx / len

	// Half-height of the opening (aperture)
	const h = aperture && aperture > 0
		? Math.min(22, Math.max(7, aperture * 2.0))
		: Math.min(18, Math.max(8, len * 0.05))

	if (!isDecrescendo) {
		// Crescendo (<): closed at p1, open at p2
		const topX = (x2 + nx * h).toFixed(1)
		const topY = (y2 + ny * h).toFixed(1)
		const midX = x1.toFixed(1)
		const midY = y1.toFixed(1)
		const botX = (x2 - nx * h).toFixed(1)
		const botY = (y2 - ny * h).toFixed(1)
		return `M ${topX} ${topY} L ${midX} ${midY} L ${botX} ${botY}`
	}

	// Decrescendo (>): open at p1, closed at p2
	const topX = (x1 + nx * h).toFixed(1)
	const topY = (y1 + ny * h).toFixed(1)
	const midX = x2.toFixed(1)
	const midY = y2.toFixed(1)
	const botX = (x1 - nx * h).toFixed(1)
	const botY = (y1 - ny * h).toFixed(1)
	return `M ${topX} ${topY} L ${midX} ${midY} L ${botX} ${botY}`
}

/**
 * Sanitize and map font family names, converting MobileSheets integer codes to CSS font families.
 */
export function getAnnotationFontFamily(family?: string | number | null): string {
	if (!family || family === '0' || family === 0) {
		return 'sans-serif'
	}
	if (family === '1' || family === 1) {
		return 'serif'
	}
	if (family === '2' || family === 2) {
		return 'monospace'
	}
	const str = String(family).trim()
	if (/^\d+$/.test(str)) {
		return 'sans-serif'
	}
	return str
}

/**
 * Map music symbols to standard Unicode display glyphs.
 */
export function getAnnotationDisplaySymbol(item: SheetAnnotation): string {
	const sym = item.data?.symbol || ''
	const disp = item.data?.displaySymbol || ''

	const map: Record<string, string> = {
		q: '♩',
		e: '♪',
		ee: '♫',
		h: '𝅗𝅥',
		w: '𝅝',
		s: '𝅘𝅥𝅯',
		b: '♭',
		bb: '♭♭',
		BB: '♭♭',
		'𝄫': '♭♭',
		'#': '♯',
		'##': '𝄪',
		x: '𝄪',
		X: '𝄪',
		n: '♮',
		'∫': '♭♭',
		'𝄋': '♭♭',
	}

	if (map[sym]) {
		return map[sym]
	}
	if (map[disp]) {
		return map[disp]
	}
	if (disp) {
		return disp
	}
	return map[sym] || sym
}

/**
 * Compute the SVG path `d` attribute for an annotation.
 */
export function getAnnotationPath(item: SheetAnnotation): string {
	const points = item.data?.points
	const isHairpin = item.data?.isHairpin || item.data?.drawMode === 4 || item.data?.drawMode === 5
	if (isHairpin && points && points.length >= 2) {
		const isDecrescendo = item.data?.drawMode === 5
		const aperture = typeof item.data?.aperture === 'number' ? item.data.aperture : undefined
		return generateHairpinPath(points[0], points[1], isDecrescendo, aperture)
	}

	if (points && points.length > 0) {
		const isSmooth = item.type !== 'shape' && item.data?.smoothMode !== false && item.data?.drawMode !== 1 && item.data?.drawMode !== 4 && item.data?.drawMode !== 5
		return generateSmoothSvgPath(points, isSmooth)
	}

	if (item.data?.path) {
		return item.data.path
	}

	if (item.type === 'shape' && (item.width || item.height)) {
		return `M ${(item.x * 1000).toFixed(1)} ${(item.y * 1000).toFixed(1)} L ${((item.x + item.width) * 1000).toFixed(1)} ${((item.y + item.height) * 1000).toFixed(1)}`
	}

	return ''
}
