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
 * Generate an SVG path for an arrow with arrowhead pointing at p2.
 */
export function generateArrowPath(
	p1: { x: number; y: number },
	p2: { x: number; y: number },
	strokeWidth?: number,
): string {
	const x1 = p1.x * 1000
	const y1 = p1.y * 1000
	const x2 = p2.x * 1000
	const y2 = p2.y * 1000

	const dx = x2 - x1
	const dy = y2 - y1
	const len = Math.hypot(dx, dy) || 1.0

	const ux = dx / len
	const uy = dy / len
	const nx = -uy
	const ny = ux

	const sw = typeof strokeWidth === 'number' && strokeWidth > 0 ? strokeWidth : 2.0
	const headLen = Math.min(len * 0.4, Math.max(16.0, 10.0 + sw * 2.8))
	const headWidth = headLen * 0.45

	const baseX = x2 - ux * headLen
	const baseY = y2 - uy * headLen

	const sx1 = x1.toFixed(1)
	const sy1 = y1.toFixed(1)
	const sx2 = x2.toFixed(1)
	const sy2 = y2.toFixed(1)
	const w1x = (baseX + nx * headWidth).toFixed(1)
	const w1y = (baseY + ny * headWidth).toFixed(1)
	const w2x = (baseX - nx * headWidth).toFixed(1)
	const w2y = (baseY - ny * headWidth).toFixed(1)

	return `M ${sx1} ${sy1} L ${sx2} ${sy2} M ${w1x} ${w1y} L ${sx2} ${sy2} L ${w2x} ${w2y}`
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
	const isHairpin = item.data?.isHairpin || item.data?.drawMode === 4 || item.data?.drawMode === 5 || item.data?.shapeType === 'crescendo' || item.data?.shapeType === 'decrescendo'
	if (isHairpin && points && points.length >= 2) {
		const isDecrescendo = item.data?.drawMode === 5 || item.data?.shapeType === 'decrescendo'
		const aperture = typeof item.data?.aperture === 'number' ? item.data.aperture : undefined
		return generateHairpinPath(points[0], points[1], isDecrescendo, aperture)
	}

	const isArrow = Boolean(
		item.data?.isArrow ||
		item.data?.shapeType === 'arrow' ||
		((item.type === 'shape' || item.data?.mobilesheetsType === 6) && item.data?.drawMode === 3)
	)
	if (isArrow && points && points.length === 2) {
		const sw = Number(item.style?.strokeWidth) || 2.0
		return generateArrowPath(points[0], points[1], sw)
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

/**
 * Compute the rendered position, dominant baseline, and text anchor for text annotations.
 */
export function getAnnotationTextPosition(ann: SheetAnnotation): {
	x: number
	y: number
	dominantBaseline: string
	textAnchor: string
} {
	const points = ann.data?.points
	const textAlign = ann.data?.textAlign

	let anchor = 'start'
	if (textAlign === 1) {
		anchor = 'middle'
	} else if (textAlign === 2) {
		anchor = 'end'
	}

	if (points && points.length >= 3) {
		// In MobileSheets TextboxAnnotations, point 2 (3rd point) is the text baseline origin (x, y)
		let x = points[2].x
		if (textAlign === 1 && ann.width) {
			x = ann.x + ann.width / 2
		} else if (textAlign === 2 && ann.width) {
			x = ann.x + ann.width - (points[2].x - ann.x)
		}
		return {
			x: Math.round(x * 10000) / 10,
			y: Math.round(points[2].y * 10000) / 10,
			dominantBaseline: 'alphabetic',
			textAnchor: anchor,
		}
	}

	// Fallback when 3 points are not stored:
	// Move down and right into the textbox to match MobileSheets text padding and baseline
	const fontSize = ann.style?.fontSize || ann.data?.scaledFontSize || 24
	const padX = ann.width && ann.width > 0 ? ann.width * 0.08 : 0.008
	const padY = ann.height && ann.height > 0 ? ann.height * 0.83 : (fontSize / 1000) * 0.9

	let x = ann.x + padX
	if (textAlign === 1 && ann.width) {
		x = ann.x + ann.width / 2
	} else if (textAlign === 2 && ann.width) {
		x = ann.x + ann.width - padX
	}

	return {
		x: Math.round(x * 10000) / 10,
		y: Math.round((ann.y + padY) * 10000) / 10,
		dominantBaseline: 'alphabetic',
		textAnchor: anchor,
	}
}
