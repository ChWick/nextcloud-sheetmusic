/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { EditorToolStyle } from '../composables/useAnnotationEditor'
import type { SheetAnnotation } from '../types/annotation'
import { MUSIC_STAMPS } from './musicSymbols'

/**
 * Returns a fresh copy of the default annotation tool style.
 */
export function createDefaultToolStyle(): EditorToolStyle {
	return {
		color: '#e53935',
		highlightColor: '#ffeb3b',
		strokeWidth: 2.5,
		highlightWidth: 18,
		opacity: 1.0,
		highlightOpacity: 0.4,
		lineStyle: 'solid',
		fontSize: 22,
		fontFamily: 'sans-serif',
		textAlign: 'left',
		fill: 'transparent',
		shapeHeight: 18,
		stampSymbol: MUSIC_STAMPS.find((s) => s.symbol === '\uE52F')?.symbol ?? '\uE52F',
		stampSize: 40,
		snapToAxis: false,
	}
}

/**
 * Computes the next stroke width when stepping up or down via `+` / `-` shortcuts.
 */
export function stepStrokeWidth(cur: number, deltaSign: 1 | -1): number {
	const step = cur < 4 || (deltaSign < 0 && cur <= 4) ? 0.5 : 1
	return Math.max(1, Math.min(64, Math.round((cur + deltaSign * step) * 2) / 2))
}

/**
 * Adopts relevant style properties from a selected annotation into `toolStyle`,
 * so switching tools preserves the user's chosen color/size/width.
 */
export function mergeAnnotationStyleIntoToolStyle(
	current: EditorToolStyle,
	ann: SheetAnnotation,
): EditorToolStyle {
	const next: EditorToolStyle = { ...current }
	if (ann.type === 'highlight') {
		if (ann.style?.stroke) next.highlightColor = ann.style.stroke
		if (typeof ann.style?.strokeWidth === 'number') next.highlightWidth = ann.style.strokeWidth
		if (typeof ann.style?.opacity === 'number') next.highlightOpacity = ann.style.opacity
		return next
	}

	const color = ann.type === 'text' || ann.type === 'stamp' ? ann.style?.color : ann.style?.stroke
	if (color) next.color = color
	if (typeof ann.style?.opacity === 'number') next.opacity = ann.style.opacity

	if (ann.type === 'drawing' || ann.type === 'shape') {
		if (typeof ann.style?.strokeWidth === 'number') next.strokeWidth = ann.style.strokeWidth
		if (ann.style?.lineStyle) next.lineStyle = ann.style.lineStyle
		if (ann.type === 'shape') {
			if (typeof ann.data?.aperture === 'number') next.shapeHeight = Math.abs(ann.data.aperture)
			else if (typeof ann.data?.curvature === 'number') next.shapeHeight = Math.abs(ann.data.curvature)
		}
	} else if (ann.type === 'stamp') {
		if (typeof ann.style?.fontSize === 'number') next.stampSize = ann.style.fontSize
		if (ann.data?.symbol) next.stampSymbol = ann.data.symbol
	} else if (ann.type === 'text') {
		if (typeof ann.style?.fontSize === 'number') next.fontSize = ann.style.fontSize
		if (ann.style?.fontFamily) next.fontFamily = ann.style.fontFamily
		if (ann.style?.textAlign) next.textAlign = ann.style.textAlign
	} else if (ann.type === 'link') {
		if (typeof ann.style?.fontSize === 'number') next.fontSize = ann.style.fontSize
	}

	return next
}
