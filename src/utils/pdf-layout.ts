/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DoubleSpread } from './page-turn'

export type PageLayoutMode = 'continuous' | 'single' | 'double'

export interface RenderPagePlan {
	pageNum: number
	index: number
	isNextFile?: boolean
}

export const MIN_FIT_SCALE = 0.01
export const MAX_FIT_SCALE = 6.0
export const MIN_CUSTOM_SCALE = 0.2
export const MAX_CUSTOM_SCALE = 4.0

/**
 * Computes the PDF.js scale factor needed to fit a page into `(targetW, targetH)`.
 */
export function computeFitScale(
	unscaledWidth: number,
	unscaledHeight: number,
	targetW: number,
	targetH: number,
	mode: 'fit-width' | 'fit-page',
): number {
	const safeW = Math.max(1, unscaledWidth)
	const safeH = Math.max(1, unscaledHeight)
	const raw = mode === 'fit-width'
		? targetW / safeW
		: Math.min(targetW / safeW, targetH / safeH)
	return Math.max(MIN_FIT_SCALE, Math.min(raw, MAX_FIT_SCALE))
}

/**
 * Computes the per-page scale in double-page layout so each page fits its half-slot
 * or scales proportionally relative to the document reference fit scale in custom zoom.
 */
export function computeDoublePageScale(opts: {
	unscaledWidth: number
	unscaledHeight: number
	doubleSlotW: number
	containerH: number
	zoomMode: 'fit-width' | 'fit-page' | 'custom'
	effectiveScale: number
	docRefFitScale: number
}): number {
	const { unscaledWidth, unscaledHeight, doubleSlotW, containerH, zoomMode, effectiveScale, docRefFitScale } = opts
	if (zoomMode === 'fit-width' || zoomMode === 'fit-page') {
		return computeFitScale(unscaledWidth, unscaledHeight, doubleSlotW, containerH, zoomMode)
	}
	const pageFit = computeFitScale(unscaledWidth, unscaledHeight, doubleSlotW, containerH, 'fit-page')
	const ratio = docRefFitScale > 0 ? pageFit / docRefFitScale : 1
	return Math.max(MIN_FIT_SCALE, Math.min(effectiveScale * ratio, MAX_FIT_SCALE))
}

/**
 * Resolves the list of logical/physical pages to render for the current layout mode,
 * including optional interleaved first page of the next song in double-page mode.
 */
export function resolvePagesToRender(opts: {
	layout: PageLayoutMode
	currentPage: number
	doubleSpread: DoubleSpread
	orderedPages: number[]
	canInterleaveWithNext: boolean
	nextTargetPage?: number
}): RenderPagePlan[] {
	const { layout, currentPage, doubleSpread, orderedPages, canInterleaveWithNext, nextTargetPage } = opts
	const total = orderedPages.length

	if (layout === 'single') {
		const idx = Math.min(Math.max(1, currentPage), total) - 1
		return [{ pageNum: orderedPages[idx] || 1, index: idx + 1 }]
	}

	if (layout === 'double') {
		const [leftLogical, rightLogical] = doubleSpread
		const leftIdx = Math.min(Math.max(1, leftLogical), total) - 1
		const pages: RenderPagePlan[] = [{ pageNum: orderedPages[leftIdx] || 1, index: leftIdx + 1 }]
		if (rightLogical !== null && rightLogical >= 1) {
			if (rightLogical <= total) {
				const rightIdx = rightLogical - 1
				pages.push({ pageNum: orderedPages[rightIdx] || 1, index: rightIdx + 1 })
			} else if (rightLogical === total + 1 && canInterleaveWithNext) {
				pages.push({
					pageNum: nextTargetPage && nextTargetPage >= 1 ? nextTargetPage : 1,
					index: total + 1,
					isNextFile: true,
				})
			}
		}
		return pages
	}

	return orderedPages.map((p, idx) => ({ pageNum: p, index: idx + 1 }))
}
