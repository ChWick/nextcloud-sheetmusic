/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { NormalizedPoint, SheetAnnotation } from '../types/annotation'
import { VIEWBOX_WIDTH } from './annotation'

interface SegmentFrame {
	x1: number
	y1: number
	x2: number
	y2: number
	len: number
	ux: number
	uy: number
	nx: number
	ny: number
}

function computeSegmentFrame(p1: NormalizedPoint, p2: NormalizedPoint, vbHeight: number): SegmentFrame {
	const x1 = p1.x * VIEWBOX_WIDTH
	const y1 = p1.y * vbHeight
	const x2 = p2.x * VIEWBOX_WIDTH
	const y2 = p2.y * vbHeight
	const dx = x2 - x1
	const dy = y2 - y1
	const len = Math.hypot(dx, dy) || 1.0
	const ux = dx / len
	const uy = dy / len
	return { x1, y1, x2, y2, len, ux, uy, nx: -uy, ny: ux }
}

function resolveHairpinAperture(len: number, aperture?: number): number {
	return aperture && aperture > 0
		? Math.min(120, Math.max(4, aperture))
		: Math.min(20, Math.max(9, len * 0.06))
}

function resolveSlurOffset(len: number, curvature?: number): number {
	return typeof curvature === 'number' && curvature !== 0
		? curvature
		: -Math.min(48, Math.max(14, len * 0.22))
}

function resolveBracketHook(len: number, hookHeight?: number): number {
	return typeof hookHeight === 'number' && hookHeight !== 0
		? Math.max(-120, Math.min(120, hookHeight))
		: Math.min(18, Math.max(8, len * 0.08))
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
	const { x1, y1, x2, y2, len, nx, ny } = computeSegmentFrame(p1, p2, vbHeight)
	const h = resolveHairpinAperture(len, aperture)
	const openX = isDecrescendo ? x1 : x2
	const openY = isDecrescendo ? y1 : y2
	const tipX = isDecrescendo ? x2 : x1
	const tipY = isDecrescendo ? y2 : y1

	return `M ${(openX + nx * h).toFixed(1)} ${(openY + ny * h).toFixed(1)} L ${tipX.toFixed(1)} ${tipY.toFixed(1)} L ${(openX - nx * h).toFixed(1)} ${(openY - ny * h).toFixed(1)}`
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
	const { x1, y1, x2, y2, len, ux, uy, nx, ny } = computeSegmentFrame(p1, p2, vbHeight)
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
	const { x1, y1, x2, y2, len, nx, ny } = computeSegmentFrame(p1, p2, vbHeight)
	const offset = resolveSlurOffset(len, curvature)
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
	const { x1, y1, x2, y2, len, nx, ny } = computeSegmentFrame(p1, p2, vbHeight)
	const hook = resolveBracketHook(len, hookHeight)

	const h1x = (x1 + nx * hook).toFixed(1)
	const h1y = (y1 + ny * hook).toFixed(1)
	const h2x = (x2 + nx * hook).toFixed(1)
	const h2y = (y2 + ny * hook).toFixed(1)

	return `M ${h1x} ${h1y} L ${x1.toFixed(1)} ${y1.toFixed(1)} L ${x2.toFixed(1)} ${y2.toFixed(1)} L ${h2x} ${h2y}`
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

	const { x1, y1, x2, y2, len, nx, ny } = computeSegmentFrame(points[0], points[1], vbHeight)

	if (shapeType === 'crescendo' || shapeType === 'decrescendo') {
		const h = resolveHairpinAperture(len, item.data?.aperture)
		const baseX = shapeType === 'crescendo' ? x2 : x1
		const baseY = shapeType === 'crescendo' ? y2 : y1
		return {
			x: Math.round((baseX + nx * h) * 10) / 10,
			y: Math.round((baseY + ny * h) * 10) / 10,
		}
	}

	if (shapeType === 'slur') {
		const offset = resolveSlurOffset(len, item.data?.curvature)
		return {
			x: Math.round(((x1 + x2) / 2 + nx * (offset * 0.5)) * 10) / 10,
			y: Math.round(((y1 + y2) / 2 + ny * (offset * 0.5)) * 10) / 10,
		}
	}

	if (shapeType === 'bracket') {
		const hook = resolveBracketHook(len, item.data?.aperture)
		return {
			x: Math.round((x1 + nx * hook) * 10) / 10,
			y: Math.round((y1 + ny * hook) * 10) / 10,
		}
	}

	return null
}
