/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RenderPagePlan } from './pdf-layout'

/**
 * Applies stage container grid/flex layout styles when swapping rendered PDF children.
 */
export function applyStageLayoutStyles(
	stage: HTMLElement,
	opts: {
		gap: string
		isDoubleTwoPage: boolean
		isCustomZoom: boolean
	},
): void {
	stage.style.gap = opts.gap
	if (opts.isDoubleTwoPage) {
		stage.style.display = 'grid'
		stage.style.gridTemplateColumns = 'repeat(2, minmax(0, 1fr))'
		stage.style.alignItems = 'center'
		stage.style.justifyItems = 'center'
		stage.style.width = opts.isCustomZoom ? 'max-content' : '100%'
	} else {
		stage.style.display = ''
		stage.style.gridTemplateColumns = ''
		stage.style.alignItems = ''
		stage.style.justifyItems = ''
		stage.style.width = ''
	}
}

/**
 * Creates a page placeholder element tagged with physical pageNum and logical pageIndex.
 */
export function createPagePlaceholder(
	item: Pick<RenderPagePlan, 'pageNum' | 'index'>,
	className?: string,
): HTMLDivElement {
	const placeholder = document.createElement('div')
	if (className) {
		placeholder.className = className
	}
	placeholder.dataset.pageNum = String(item.pageNum)
	placeholder.dataset.pageIndex = String(item.index)
	return placeholder
}

/**
 * Creates a styled continuous-mode placeholder with estimated height to stabilize scrollbars.
 */
export function createContinuousPagePlaceholder(
	item: Pick<RenderPagePlan, 'pageNum' | 'index'>,
	effectiveScale: number,
): HTMLDivElement {
	const placeholder = createPagePlaceholder(item, 'sheetmusic-page-placeholder')
	placeholder.style.minWidth = '200px'
	placeholder.style.minHeight = `${Math.round(800 * (effectiveScale / 1.25))}px`
	placeholder.style.display = 'flex'
	placeholder.style.justifyContent = 'center'
	placeholder.style.alignItems = 'center'
	placeholder.style.backgroundColor = '#ffffff'
	placeholder.style.boxShadow = '0 2px 8px rgba(0, 0, 0, 0.15)'
	return placeholder
}

/**
 * Creates a double-page half-slot wrapper containing a page placeholder so each page
 * occupies half the stage without shifting across turns.
 */
export function createDoublePageSlot(
	item: Pick<RenderPagePlan, 'pageNum' | 'index'>,
	opts: {
		doubleSlotW: number
		isTwoPageSpread: boolean
		isCustomZoom: boolean
	},
): { slot: HTMLDivElement; placeholder: HTMLDivElement } {
	const slot = document.createElement('div')
	slot.className = 'sheetmusic-double-slot'
	slot.style.display = 'flex'
	slot.style.justifyContent = 'center'
	slot.style.alignItems = 'center'
	slot.style.boxSizing = 'border-box'
	slot.style.minWidth = '0'

	if (opts.isTwoPageSpread) {
		slot.style.width = '100%'
		if (!opts.isCustomZoom) {
			slot.style.maxWidth = `${opts.doubleSlotW}px`
			slot.style.overflow = 'hidden'
		}
	} else if (opts.isCustomZoom) {
		slot.style.minWidth = `${opts.doubleSlotW}px`
		slot.style.flex = '0 0 auto'
	} else {
		slot.style.width = `${opts.doubleSlotW}px`
		slot.style.maxWidth = `${opts.doubleSlotW}px`
		slot.style.flex = `0 0 ${opts.doubleSlotW}px`
		slot.style.overflow = 'hidden'
	}

	const placeholder = createPagePlaceholder(item)
	slot.appendChild(placeholder)
	return { slot, placeholder }
}
