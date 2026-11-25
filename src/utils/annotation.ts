/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { NormalizedPoint, SheetAnnotation } from '../types/annotation'
import { getStampDefinition } from './musicSymbols'

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

import {
	generateArrowPath,
	generateBracketPath,
	generateHairpinPath,
	generateSlurPath,
	getShapeHeightHandle,
} from './annotationShapes'

export {
	generateArrowPath,
	generateBracketPath,
	generateHairpinPath,
	generateSlurPath,
	getShapeHeightHandle,
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
 * Get the SMuFL display symbol for a stamp annotation.
 */
export function getAnnotationDisplaySymbol(item: SheetAnnotation): string {
	return (item.data?.symbol || '').trim()
}

/**
 * Compute the effective SVG font-size for a stamp annotation.
 * In SMuFL (Bravura), all glyphs — including fingerings (U+ED10..ED15), organ pedals,
 * string bowings, dynamics, and notes — are engraved at unified proportional scale.
 */
export function getStampRenderFontSize(item: SheetAnnotation): number {
	return Number(item.style?.fontSize) || 40
}

/**
 * Compute a proportional visual radius (in SVG units) for selection circles and hit targets.
 */
export function getStampVisualRadius(item: SheetAnnotation): number {
	const fontSize = getStampRenderFontSize(item)
	const isFingering = getStampDefinition(getAnnotationDisplaySymbol(item))?.fontKind === 'fingering'
	return isFingering ? fontSize * 0.2 : fontSize * 0.4
}

/**
 * Compute exact SVG <text> (x, y) and dominant-baseline so the glyph's ink
 * bounding box (or notehead anchor via canvasOffset) is centered at the stamp's target center.
 */
export function getStampRenderPosition(
	item: SheetAnnotation,
	vbHeight: number = VIEWBOX_WIDTH,
): { x: number; y: number; dominantBaseline: 'alphabetic' | 'central' } {
	const cx = (item.x + (item.width || 0) / 2) * VIEWBOX_WIDTH
	const cy = (item.y + (item.height || 0) / 2) * vbHeight
	const fontSize = getStampRenderFontSize(item)
	const sym = getAnnotationDisplaySymbol(item)
	const def = getStampDefinition(sym)
	const offset = def?.canvasOffset ?? def?.inkOffset

	if (offset) {
		return {
			x: Math.round((cx + offset.ox * fontSize) * 10) / 10,
			y: Math.round((cy + offset.oy * fontSize) * 10) / 10,
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
