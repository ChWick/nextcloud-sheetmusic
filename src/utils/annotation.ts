/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { NormalizedPoint, SheetAnnotation } from '../types/annotation'
import {
	getMusicStampFontFamily,
	getStampDefinition,
	normalizeMusicSymbol,
} from './musicSymbols'

export const VIEWBOX_WIDTH = 1000
export const DEFAULT_VIEWBOX_HEIGHT = 1414.21 // A4 portrait aspect ratio (sqrt(2) * 1000)

/**
 * Compute isotropic SVG viewBox height for a page given its width and height.
 * Keeping X and Y in 1:1 physical aspect ratio prevents stretching of circles,
 * stroke widths, arrowheads, hairpins, and music font glyphs.
 */
export function getViewBoxHeight(pageWidth?: number | null, pageHeight?: number | null): number {
	if (pageWidth && pageHeight && pageWidth > 0 && pageHeight > 0) {
		return Math.round(((VIEWBOX_WIDTH * pageHeight) / pageWidth) * 100) / 100
	}
	return DEFAULT_VIEWBOX_HEIGHT
}

export function normToSvg(pt: NormalizedPoint, vbHeight: number = VIEWBOX_WIDTH): { x: number; y: number } {
	return {
		x: pt.x * VIEWBOX_WIDTH,
		y: pt.y * vbHeight,
	}
}

export function svgToNorm(pt: { x: number; y: number }, vbHeight: number = VIEWBOX_WIDTH): NormalizedPoint {
	return {
		x: pt.x / VIEWBOX_WIDTH,
		y: pt.y / (vbHeight || VIEWBOX_WIDTH),
	}
}

/**
 * Generate an SVG path with optional quadratic Bezier midpoint curve smoothing.
 */
export function generateSmoothSvgPath(
	points: NormalizedPoint[],
	smooth = true,
	vbHeight: number = VIEWBOX_WIDTH,
): string {
	if (!points || points.length === 0) {
		return ''
	}

	const filtered: NormalizedPoint[] = []
	for (const pt of points) {
		const last = filtered[filtered.length - 1]
		if (!last || Math.abs(last.x - pt.x) > 0.0001 || Math.abs(last.y - pt.y) > 0.0001) {
			filtered.push(pt)
		}
	}

	const count = filtered.length
	if (count === 0) return ''

	const sx0 = (filtered[0].x * VIEWBOX_WIDTH).toFixed(1)
	const sy0 = (filtered[0].y * vbHeight).toFixed(1)

	if (count === 1) {
		return `M ${sx0} ${sy0}`
	}
	if (count === 2 || !smooth) {
		let d = `M ${sx0} ${sy0}`
		for (let i = 1; i < count; i++) {
			d += ` L ${(filtered[i].x * VIEWBOX_WIDTH).toFixed(1)} ${(filtered[i].y * vbHeight).toFixed(1)}`
		}
		return d
	}

	let d = `M ${sx0} ${sy0}`
	for (let i = 1; i < count - 1; i++) {
		const cx = (filtered[i].x * VIEWBOX_WIDTH).toFixed(1)
		const cy = (filtered[i].y * vbHeight).toFixed(1)
		const endX = (((filtered[i].x + filtered[i + 1].x) / 2) * VIEWBOX_WIDTH).toFixed(1)
		const endY = (((filtered[i].y + filtered[i + 1].y) / 2) * vbHeight).toFixed(1)
		d += ` Q ${cx} ${cy}, ${endX} ${endY}`
	}
	const lastX = (filtered[count - 1].x * VIEWBOX_WIDTH).toFixed(1)
	const lastY = (filtered[count - 1].y * vbHeight).toFixed(1)
	d += ` L ${lastX} ${lastY}`

	return d
}

export {
	generatePressureStrokePath,
	getAnnotationPressurePath,
	hasPressurePoints,
} from './annotationPressure'

/**
 * Determine the stroke-dasharray for an annotation.
 * Dashes scale proportionally with stroke width.
 */
export function getAnnotationDashArray(item: SheetAnnotation): string | undefined {
	const sw = Number(item.style?.strokeWidth) || 2.5
	const lineStyle = item.style?.lineStyle

	if (lineStyle === 'dashed') {
		const dash = Math.max(6, Math.round(sw * 2.5))
		const gap = Math.max(4, Math.round(sw * 1.5))
		return `${dash}, ${gap}`
	}

	if (lineStyle === 'dotted') {
		const gap = Math.max(8, Math.round(sw * 2.2))
		return `1, ${gap}`
	}

	return undefined
}

/**
 * Generate an SVG path for a crescendo (<) or decrescendo (>) hairpin.
 */
export function generateHairpinPath(
	p1: NormalizedPoint,
	p2: NormalizedPoint,
	isDecrescendo = false,
	aperture?: number,
	vbHeight: number = VIEWBOX_WIDTH,
): string {
	const x1 = p1.x * VIEWBOX_WIDTH
	const y1 = p1.y * vbHeight
	const x2 = p2.x * VIEWBOX_WIDTH
	const y2 = p2.y * vbHeight

	const dx = x2 - x1
	const dy = y2 - y1
	const len = Math.hypot(dx, dy) || 1.0

	const nx = -dy / len
	const ny = dx / len

	const h = aperture && aperture > 0
		? Math.min(120, Math.max(4, aperture))
		: Math.min(20, Math.max(9, len * 0.06))

	if (!isDecrescendo) {
		const topX = (x2 + nx * h).toFixed(1)
		const topY = (y2 + ny * h).toFixed(1)
		const botX = (x2 - nx * h).toFixed(1)
		const botY = (y2 - ny * h).toFixed(1)
		return `M ${topX} ${topY} L ${x1.toFixed(1)} ${y1.toFixed(1)} L ${botX} ${botY}`
	}

	const topX = (x1 + nx * h).toFixed(1)
	const topY = (y1 + ny * h).toFixed(1)
	const botX = (x1 - nx * h).toFixed(1)
	const botY = (y1 - ny * h).toFixed(1)
	return `M ${topX} ${topY} L ${x2.toFixed(1)} ${y2.toFixed(1)} L ${botX} ${botY}`
}

/**
 * Generate an SVG path for an arrow with arrowhead pointing at p2.
 */
export function generateArrowPath(
	p1: NormalizedPoint,
	p2: NormalizedPoint,
	strokeWidth?: number,
	vbHeight: number = VIEWBOX_WIDTH,
): string {
	const x1 = p1.x * VIEWBOX_WIDTH
	const y1 = p1.y * vbHeight
	const x2 = p2.x * VIEWBOX_WIDTH
	const y2 = p2.y * vbHeight

	const dx = x2 - x1
	const dy = y2 - y1
	const len = Math.hypot(dx, dy) || 1.0

	const ux = dx / len
	const uy = dy / len
	const nx = -uy
	const ny = ux

	const sw = typeof strokeWidth === 'number' && strokeWidth > 0 ? strokeWidth : 2.5
	const headLen = Math.min(len * 0.4, Math.max(14.0, 8.0 + sw * 2.8))
	const headWidth = headLen * 0.45

	const baseX = x2 - ux * headLen
	const baseY = y2 - uy * headLen

	return `M ${x1.toFixed(1)} ${y1.toFixed(1)} L ${x2.toFixed(1)} ${y2.toFixed(1)} M ${(baseX + nx * headWidth).toFixed(1)} ${(baseY + ny * headWidth).toFixed(1)} L ${x2.toFixed(1)} ${y2.toFixed(1)} L ${(baseX - nx * headWidth).toFixed(1)} ${(baseY - ny * headWidth).toFixed(1)}`
}

/**
 * Generate a musical slur / tie quadratic Bezier path between p1 and p2.
 */
export function generateSlurPath(
	p1: NormalizedPoint,
	p2: NormalizedPoint,
	curvature?: number,
	vbHeight: number = VIEWBOX_WIDTH,
): string {
	const x1 = p1.x * VIEWBOX_WIDTH
	const y1 = p1.y * vbHeight
	const x2 = p2.x * VIEWBOX_WIDTH
	const y2 = p2.y * vbHeight

	const dx = x2 - x1
	const dy = y2 - y1
	const len = Math.hypot(dx, dy) || 1.0

	const nx = -dy / len
	const ny = dx / len

	// Default upward arch (-curvature in screen Y)
	const offset = typeof curvature === 'number' && curvature !== 0
		? curvature
		: -Math.min(48, Math.max(14, len * 0.22))

	const mx = (x1 + x2) / 2 + nx * offset
	const my = (y1 + y2) / 2 + ny * offset

	return `M ${x1.toFixed(1)} ${y1.toFixed(1)} Q ${mx.toFixed(1)} ${my.toFixed(1)}, ${x2.toFixed(1)} ${y2.toFixed(1)}`
}

/**
 * Generate a musical bracket (e.g. pedal/ottava/measure bracket) between p1 and p2.
 */
export function generateBracketPath(
	p1: NormalizedPoint,
	p2: NormalizedPoint,
	vbHeight: number = VIEWBOX_WIDTH,
	hookHeight?: number,
): string {
	const x1 = p1.x * VIEWBOX_WIDTH
	const y1 = p1.y * vbHeight
	const x2 = p2.x * VIEWBOX_WIDTH
	const y2 = p2.y * vbHeight

	const dx = x2 - x1
	const dy = y2 - y1
	const len = Math.hypot(dx, dy) || 1.0
	const nx = -dy / len
	const ny = dx / len
	const hook = typeof hookHeight === 'number' && hookHeight !== 0
		? Math.max(-120, Math.min(120, hookHeight))
		: Math.min(18, Math.max(8, len * 0.08))

	const h1x = (x1 + nx * hook).toFixed(1)
	const h1y = (y1 + ny * hook).toFixed(1)
	const h2x = (x2 + nx * hook).toFixed(1)
	const h2y = (y2 + ny * hook).toFixed(1)

	return `M ${h1x} ${h1y} L ${x1.toFixed(1)} ${y1.toFixed(1)} L ${x2.toFixed(1)} ${y2.toFixed(1)} L ${h2x} ${h2y}`
}

/**
 * Compute the SVG path `d` attribute for path-based annotations.
 */
export function getAnnotationPath(item: SheetAnnotation, vbHeight: number = VIEWBOX_WIDTH): string {
	const points = item.data?.points
	const shapeType = item.data?.shapeType

	if (item.type === 'shape') {
		if ((shapeType === 'crescendo' || shapeType === 'decrescendo') && points && points.length >= 2) {
			return generateHairpinPath(
				points[0],
				points[1],
				shapeType === 'decrescendo',
				item.data?.aperture,
				vbHeight,
			)
		}
		if (shapeType === 'arrow' && points && points.length >= 2) {
			return generateArrowPath(points[0], points[1], item.style?.strokeWidth, vbHeight)
		}
		if (shapeType === 'slur' && points && points.length >= 2) {
			return generateSlurPath(points[0], points[1], item.data?.curvature, vbHeight)
		}
		if (shapeType === 'bracket' && points && points.length >= 2) {
			return generateBracketPath(points[0], points[1], vbHeight, item.data?.aperture)
		}
		if (shapeType === 'line' && points && points.length >= 2) {
			return generateSmoothSvgPath(points, false, vbHeight)
		}
	}

	const isSmooth = item.type !== 'shape' && item.data?.smooth !== false

	const strokes = item.data?.strokes
	if (Array.isArray(strokes) && strokes.length > 0) {
		return strokes
			.map((s) => generateSmoothSvgPath(s, isSmooth, vbHeight))
			.filter(Boolean)
			.join(' ')
	}

	if (points && points.length > 0) {
		return generateSmoothSvgPath(points, isSmooth, vbHeight)
	}

	if (item.data?.path) {
		return item.data.path
	}

	if (item.type === 'shape' && (item.width || item.height)) {
		return `M ${(item.x * VIEWBOX_WIDTH).toFixed(1)} ${(item.y * vbHeight).toFixed(1)} L ${((item.x + item.width) * VIEWBOX_WIDTH).toFixed(1)} ${((item.y + item.height) * vbHeight).toFixed(1)}`
	}

	return ''
}

/**
 * Determine whether a shape annotation should be rendered as a <path> (vs <rect> or <ellipse>).
 */
export function isPathShape(item: SheetAnnotation): boolean {
	if (item.type !== 'shape') return false
	const shapeType = item.data?.shapeType
	if (shapeType === 'rect' || shapeType === 'ellipse') return false
	if (shapeType === 'line' || shapeType === 'arrow' || shapeType === 'crescendo' || shapeType === 'decrescendo' || shapeType === 'slur' || shapeType === 'bracket') {
		return true
	}
	return Boolean(item.data?.path || (item.data?.points && item.data.points.length > 0))
}

/**
 * Map font family names to safe CSS font stacks.
 */
export function getAnnotationFontFamily(family?: string | null): string {
	if (!family || family === 'sans-serif') {
		return 'sans-serif'
	}
	if (family === 'serif') {
		return 'serif'
	}
	if (family === 'monospace') {
		return 'monospace'
	}
	if (family === 'Century Schoolbook') {
		return "'Century Schoolbook', 'TeX Gyre Schola', 'Times New Roman', serif"
	}
	return family
}

/**
 * Get the canonical Unicode display symbol for a stamp annotation.
 */
export function getAnnotationDisplaySymbol(item: SheetAnnotation): string {
	const raw = item.data?.symbol || ''
	return normalizeMusicSymbol(raw)
}

export function isNumericStamp(item: SheetAnnotation): boolean {
	const symbol = getAnnotationDisplaySymbol(item)
	return /^\d+$/.test(symbol.trim())
}

export function isStampItalic(item: SheetAnnotation): boolean {
	return isNumericStamp(item)
}

export function isStampBold(item: SheetAnnotation): boolean {
	return isNumericStamp(item)
}

export function getStampFontFamily(item: SheetAnnotation): string {
	const sym = getAnnotationDisplaySymbol(item)
	const def = getStampDefinition(sym)
	return getMusicStampFontFamily(sym, item.data?.fontKind ?? def?.fontKind)
}

/**
 * Compute exact SVG <text> (x, y) and dominant-baseline so the glyph's ink
 * bounding box is centered at the stamp's target center (x + width/2, y + height/2).
 */
export function getStampRenderPosition(
	item: SheetAnnotation,
	vbHeight: number = VIEWBOX_WIDTH,
): { x: number; y: number; dominantBaseline: 'alphabetic' | 'central' } {
	const cx = (item.x + (item.width || 0) / 2) * VIEWBOX_WIDTH
	const cy = (item.y + (item.height || 0) / 2) * vbHeight
	const fontSize = Number(item.style?.fontSize) || 40
	const sym = getAnnotationDisplaySymbol(item)
	const def = getStampDefinition(sym)

	if (def?.inkOffset) {
		return {
			x: Math.round((cx + def.inkOffset.ox * fontSize) * 10) / 10,
			y: Math.round((cy + def.inkOffset.oy * fontSize) * 10) / 10,
			dominantBaseline: 'alphabetic',
		}
	}

	return {
		x: Math.round(cx * 10) / 10,
		y: Math.round(cy * 10) / 10,
		dominantBaseline: 'central',
	}
}

export interface TextAnnotationLayout {
	x: number
	y: number
	boxX: number
	boxY: number
	boxWidth: number
	boxHeight: number
	fontSize: number
	lineHeight: number
	fontFamily: string
	textAnchor: 'start' | 'middle' | 'end'
	lines: string[]
}

/**
 * Compute isotropic SVG text layout for a text annotation.
 */
export function getAnnotationTextLayout(
	ann: SheetAnnotation,
	vbHeight: number = VIEWBOX_WIDTH,
): TextAnnotationLayout {
	const boxX = ann.x * VIEWBOX_WIDTH
	const boxY = ann.y * vbHeight
	const boxWidth = Math.max(20, (ann.width || 0.15) * VIEWBOX_WIDTH)
	const boxHeight = Math.max(16, (ann.height || 0.04) * vbHeight)
	const fontSize = Number(ann.style?.fontSize) || 22
	const lineHeight = fontSize * 1.25
	const textAlign = ann.style?.textAlign || 'left'

	const padX = Math.min(8, boxWidth * 0.04)
	let x = boxX + padX
	let textAnchor: 'start' | 'middle' | 'end' = 'start'
	if (textAlign === 'center') {
		x = boxX + boxWidth / 2
		textAnchor = 'middle'
	} else if (textAlign === 'right') {
		x = boxX + boxWidth - padX
		textAnchor = 'end'
	}

	const rawText = ann.data?.text ?? ''
	const lines = rawText.split('\n')

	return {
		x: Math.round(x * 10) / 10,
		y: Math.round((boxY + fontSize * 0.92) * 10) / 10,
		boxX,
		boxY,
		boxWidth,
		boxHeight,
		fontSize,
		lineHeight,
		fontFamily: getAnnotationFontFamily(ann.style?.fontFamily),
		textAnchor,
		lines,
	}
}

/**
 * Compute a normalized [0..1] bounding box from a list of points.
 */
export function computeBoundingBoxFromPoints(
	points: NormalizedPoint[],
	padding = 0.005,
): { x: number; y: number; width: number; height: number } {
	if (!points || points.length === 0) {
		return { x: 0, y: 0, width: 0, height: 0 }
	}
	let minX = 1
	let minY = 1
	let maxX = 0
	let maxY = 0
	for (const pt of points) {
		if (pt.x < minX) minX = pt.x
		if (pt.y < minY) minY = pt.y
		if (pt.x > maxX) maxX = pt.x
		if (pt.y > maxY) maxY = pt.y
	}
	const x = Math.max(0, minX - padding)
	const y = Math.max(0, minY - padding)
	const right = Math.min(1, maxX + padding)
	const bottom = Math.min(1, maxY + padding)
	return {
		x: Math.round(x * 10000) / 10000,
		y: Math.round(y * 10000) / 10000,
		width: Math.round(Math.max(0.005, right - x) * 10000) / 10000,
		height: Math.round(Math.max(0.005, bottom - y) * 10000) / 10000,
	}
}

/**
 * Translate an annotation by (dx, dy) in normalized [0..1] coordinates.
 */
export function translateAnnotation(
	ann: SheetAnnotation,
	dx: number,
	dy: number,
): SheetAnnotation {
	const next: SheetAnnotation = {
		...ann,
		x: Math.round((ann.x + dx) * 10000) / 10000,
		y: Math.round((ann.y + dy) * 10000) / 10000,
		style: { ...ann.style },
		data: { ...ann.data },
	}
	if (Array.isArray(ann.data?.points)) {
		next.data.points = ann.data.points.map((pt) => ({
			x: Math.round((pt.x + dx) * 10000) / 10000,
			y: Math.round((pt.y + dy) * 10000) / 10000,
			...(typeof pt.p === 'number' ? { p: pt.p } : {}),
		}))
	}
	if (Array.isArray(ann.data?.strokes)) {
		next.data.strokes = ann.data.strokes.map((stroke) =>
			stroke.map((pt) => ({
				x: Math.round((pt.x + dx) * 10000) / 10000,
				y: Math.round((pt.y + dy) * 10000) / 10000,
				...(typeof pt.p === 'number' ? { p: pt.p } : {}),
			})),
		)
	}
	return next
}

/**
 * Compute the SVG coordinates of the height/arch handle for 2-point shapes
 * (crescendo, decrescendo, slur, bracket), or null if not applicable.
 */
export function getShapeHeightHandle(
	item: SheetAnnotation,
	vbHeight: number = VIEWBOX_WIDTH,
): { x: number; y: number } | null {
	if (item.type !== 'shape') return null
	const shapeType = item.data?.shapeType
	const points = item.data?.points
	if (!points || points.length < 2) return null

	const x1 = points[0].x * VIEWBOX_WIDTH
	const y1 = points[0].y * vbHeight
	const x2 = points[1].x * VIEWBOX_WIDTH
	const y2 = points[1].y * vbHeight

	const dx = x2 - x1
	const dy = y2 - y1
	const len = Math.hypot(dx, dy) || 1.0
	const nx = -dy / len
	const ny = dx / len

	if (shapeType === 'crescendo' || shapeType === 'decrescendo') {
		const aperture = item.data?.aperture
		const h = aperture && aperture > 0
			? Math.min(120, Math.max(4, aperture))
			: Math.min(20, Math.max(9, len * 0.06))
		const baseX = shapeType === 'crescendo' ? x2 : x1
		const baseY = shapeType === 'crescendo' ? y2 : y1
		return {
			x: Math.round((baseX + nx * h) * 10) / 10,
			y: Math.round((baseY + ny * h) * 10) / 10,
		}
	}

	if (shapeType === 'slur') {
		const curvature = item.data?.curvature
		const offset = typeof curvature === 'number' && curvature !== 0
			? curvature
			: -Math.min(48, Math.max(14, len * 0.22))
		const midX = (x1 + x2) / 2
		const midY = (y1 + y2) / 2
		return {
			x: Math.round((midX + nx * (offset * 0.5)) * 10) / 10,
			y: Math.round((midY + ny * (offset * 0.5)) * 10) / 10,
		}
	}

	if (shapeType === 'bracket') {
		const aperture = item.data?.aperture
		const hook = typeof aperture === 'number' && aperture !== 0
			? Math.max(-120, Math.min(120, aperture))
			: Math.min(18, Math.max(8, len * 0.08))
		return {
			x: Math.round((x1 + nx * hook) * 10) / 10,
			y: Math.round((y1 + ny * hook) * 10) / 10,
		}
	}

	return null
}
