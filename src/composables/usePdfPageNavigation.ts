/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, type Ref } from 'vue'
import { isPdfFile as checkIsPdfFile } from '../utils/file'
import { parsePageOrder } from '../utils/page-order'
import {
	type DoublePageTurnMode,
	type DoubleSpread,
	formatDoubleSpreadDisplay,
	getDoubleSpreadForPage,
	nextDoubleSpread,
	prevDoubleSpread,
} from '../utils/page-turn'
import type { PageLayoutMode } from '../utils/pdf-layout'
import { cleanupPdfJsPage, getEffectivePageRotation } from '../utils/pdfjs'

export interface UsePdfPageNavigationOptions {
	pdfContainer: Ref<HTMLElement | undefined>
	loadedPdf: Ref<any>
	totalPages: Ref<number>
	currentPage: Ref<number>
	rotation: Ref<number>
	pageOrder: Ref<string | null | undefined>
	initialPageLayout: PageLayoutMode | 'auto'
	initialDoublePageTurnMode: DoublePageTurnMode
	interleaveSongs: Ref<boolean>
	nextFilePath: Ref<string | undefined>
	onRerender: () => void | Promise<void>
}

export function usePdfPageNavigation(options: UsePdfPageNavigationOptions) {
	const {
		pdfContainer,
		loadedPdf,
		totalPages,
		currentPage,
		rotation,
		pageOrder,
		initialPageLayout,
		initialDoublePageTurnMode,
		interleaveSongs,
		nextFilePath,
		onRerender,
	} = options

	function isContainerLandscape(): boolean {
		if (typeof window === 'undefined') return false
		const containerW = pdfContainer.value?.clientWidth || window.innerWidth
		const containerH = pdfContainer.value?.clientHeight || window.innerHeight
		return containerW >= containerH && containerW >= 700
	}

	function detectResponsiveLayout(): PageLayoutMode {
		return isContainerLandscape() ? 'double' : 'single'
	}

	async function isLogicalPageLandscape(logicalPage: number): Promise<boolean> {
		if (!loadedPdf.value) return false
		let page: any = null
		try {
			const ordered = parsePageOrder(pageOrder.value, loadedPdf.value.numPages)
			const idx = Math.min(Math.max(1, logicalPage), ordered.length) - 1
			const pageNum = ordered[idx] || 1
			page = await loadedPdf.value.getPage(pageNum)
			const pageRotation = getEffectivePageRotation(page, rotation.value)
			const viewport = page.getViewport({ scale: 1.0, rotation: pageRotation })
			return viewport.width > viewport.height
		} catch {
			return false
		} finally {
			cleanupPdfJsPage(page)
		}
	}

	async function resolveAutoLayoutForPage(logicalPage: number): Promise<PageLayoutMode> {
		if (!isContainerLandscape()) return 'single'
		if (await isLogicalPageLandscape(logicalPage)) return 'single'
		return 'double'
	}

	const autoLayout = ref(initialPageLayout === 'auto')
	const pageLayout = ref<PageLayoutMode>(
		initialPageLayout === 'auto' ? detectResponsiveLayout() : initialPageLayout,
	)
	const doublePageTurnMode = ref<DoublePageTurnMode>(initialDoublePageTurnMode)
	const doubleSpread = ref<DoubleSpread>([1, null])

	const canInterleaveWithNext = computed(() => {
		return Boolean(interleaveSongs.value && checkIsPdfFile(nextFilePath.value))
	})

	const effectiveDoubleTotalPages = computed(() => {
		if (!canInterleaveWithNext.value) {
			return totalPages.value
		}
		if (doublePageTurnMode.value === 'step') {
			return totalPages.value + 1
		}
		return totalPages.value % 2 === 1 ? totalPages.value + 1 : totalPages.value
	})

	const isShowingNextSongFirstPage = computed(() => {
		return pageLayout.value === 'double'
			&& canInterleaveWithNext.value
			&& doubleSpread.value[1] === totalPages.value + 1
	})

	const currentPageDisplay = computed(() => {
		if (pageLayout.value === 'double') {
			if (isShowingNextSongFirstPage.value) {
				return `${doubleSpread.value[0]} / →1`
			}
			return formatDoubleSpreadDisplay(doubleSpread.value)
		}
		if (pageLayout.value === 'continuous') {
			return `1–${totalPages.value || 1}`
		}
		return `${currentPage.value}`
	})

	const canGoPrev = computed(() => {
		if (pageLayout.value === 'continuous') return false
		if (pageLayout.value === 'double') {
			return prevDoubleSpread(doubleSpread.value, effectiveDoubleTotalPages.value, doublePageTurnMode.value) !== null
		}
		return currentPage.value > 1
	})

	const canGoNext = computed(() => {
		if (pageLayout.value === 'continuous') return false
		if (pageLayout.value === 'double') {
			return nextDoubleSpread(doubleSpread.value, effectiveDoubleTotalPages.value, doublePageTurnMode.value) !== null
		}
		return currentPage.value < totalPages.value
	})

	function prevPage(): boolean {
		if (pageLayout.value === 'double') {
			const prev = prevDoubleSpread(doubleSpread.value, effectiveDoubleTotalPages.value, doublePageTurnMode.value)
			if (!prev) return false
			doubleSpread.value = prev
			currentPage.value = prev[0]
			onRerender()
			return true
		}
		if (pageLayout.value === 'single') {
			if (currentPage.value <= 1) return false
			currentPage.value -= 1
			onRerender()
			return true
		}
		return false
	}

	function nextPage(): boolean {
		if (pageLayout.value === 'double') {
			const next = nextDoubleSpread(doubleSpread.value, effectiveDoubleTotalPages.value, doublePageTurnMode.value)
			if (!next) return false
			doubleSpread.value = next
			currentPage.value = next[0]
			onRerender()
			return true
		}
		if (pageLayout.value === 'single') {
			if (currentPage.value >= totalPages.value) return false
			currentPage.value += 1
			onRerender()
			return true
		}
		return false
	}

	function goToPage(targetPage: number) {
		if (targetPage < 1) targetPage = 1
		if (totalPages.value > 0 && targetPage > totalPages.value) {
			targetPage = totalPages.value
		}
		currentPage.value = targetPage
		doubleSpread.value = getDoubleSpreadForPage(targetPage, totalPages.value, doublePageTurnMode.value)
		if (pageLayout.value === 'continuous') {
			const placeholder = (pdfContainer.value?.querySelector(`[data-page-index="${targetPage}"]`) as HTMLElement | null)
				?? (pdfContainer.value?.querySelector(`[data-page-num="${targetPage}"]`) as HTMLElement | null)
			if (placeholder) {
				placeholder.scrollIntoView({ behavior: 'smooth', block: 'start' })
			}
		} else {
			onRerender()
		}
	}

	function setPageLayout(mode: PageLayoutMode) {
		autoLayout.value = false
		if (pageLayout.value === mode) return
		pageLayout.value = mode
		if (mode === 'double') {
			doubleSpread.value = getDoubleSpreadForPage(currentPage.value, totalPages.value, doublePageTurnMode.value)
		}
		onRerender()
	}

	async function setAutoLayout(enabled: boolean) {
		autoLayout.value = enabled
		if (enabled) {
			const detected = await resolveAutoLayoutForPage(currentPage.value)
			if (pageLayout.value !== detected) {
				pageLayout.value = detected
				if (detected === 'double') {
					doubleSpread.value = getDoubleSpreadForPage(currentPage.value, totalPages.value, doublePageTurnMode.value)
				}
				onRerender()
			}
		}
	}

	function setDoublePageTurnMode(mode: DoublePageTurnMode) {
		if (doublePageTurnMode.value === mode) return
		doublePageTurnMode.value = mode
		doubleSpread.value = getDoubleSpreadForPage(currentPage.value, totalPages.value, mode)
		if (pageLayout.value === 'double') {
			onRerender()
		}
	}

	return {
		autoLayout,
		pageLayout,
		doublePageTurnMode,
		doubleSpread,
		canInterleaveWithNext,
		effectiveDoubleTotalPages,
		isShowingNextSongFirstPage,
		currentPageDisplay,
		canGoPrev,
		canGoNext,
		resolveAutoLayoutForPage,
		prevPage,
		nextPage,
		goToPage,
		setPageLayout,
		setAutoLayout,
		setDoublePageTurnMode,
	}
}
