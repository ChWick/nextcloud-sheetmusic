/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { usePdfPageNavigation } from '../../../src/composables/usePdfPageNavigation'

function createNav(overrides: Partial<Parameters<typeof usePdfPageNavigation>[0]> = {}) {
	const onRerender = vi.fn()
	const nav = usePdfPageNavigation({
		pdfContainer: ref<HTMLElement | undefined>(undefined),
		loadedPdf: ref<any>(null),
		totalPages: ref(4),
		currentPage: ref(1),
		rotation: ref(0),
		pageOrder: ref<string | null | undefined>(null),
		initialPageLayout: 'single',
		initialDoublePageTurnMode: 'half',
		interleaveSongs: ref(false),
		nextFilePath: ref<string | undefined>(undefined),
		onRerender,
		...overrides,
	})
	return { nav, onRerender }
}

describe('usePdfPageNavigation', () => {
	it('navigates single pages within bounds and triggers rerender', () => {
		const currentPage = ref(1)
		const { nav, onRerender } = createNav({ currentPage, totalPages: ref(3), initialPageLayout: 'single' })

		expect(nav.canGoPrev.value).toBe(false)
		expect(nav.canGoNext.value).toBe(true)
		expect(nav.currentPageDisplay.value).toBe('1')
		expect(nav.prevPage()).toBe(false)

		expect(nav.nextPage()).toBe(true)
		expect(currentPage.value).toBe(2)
		expect(onRerender).toHaveBeenCalledTimes(1)

		nav.goToPage(99)
		expect(currentPage.value).toBe(3)
		expect(nav.canGoNext.value).toBe(false)
		expect(nav.nextPage()).toBe(false)
	})

	it('handles double-page spreads and interleaved next-song display', () => {
		const currentPage = ref(3)
		const { nav, onRerender } = createNav({
			currentPage,
			totalPages: ref(3),
			initialPageLayout: 'double',
			initialDoublePageTurnMode: 'step',
			interleaveSongs: ref(true),
			nextFilePath: ref('/music/next.pdf'),
		})
		nav.doubleSpread.value = [3, 4]

		expect(nav.canInterleaveWithNext.value).toBe(true)
		expect(nav.effectiveDoubleTotalPages.value).toBe(4)
		expect(nav.isShowingNextSongFirstPage.value).toBe(true)
		expect(nav.currentPageDisplay.value).toBe('3 / →1')

		expect(nav.prevPage()).toBe(true)
		expect(nav.doubleSpread.value).toEqual([2, 3])
		expect(currentPage.value).toBe(2)
		expect(onRerender).toHaveBeenCalledTimes(1)
	})

	it('scrolls to placeholder in continuous mode instead of rerendering', () => {
		const scrollIntoView = vi.fn()
		const placeholder = document.createElement('div')
		placeholder.dataset.pageIndex = '2'
		placeholder.scrollIntoView = scrollIntoView
		const container = document.createElement('div')
		container.appendChild(placeholder)

		const currentPage = ref(1)
		const { nav, onRerender } = createNav({
			pdfContainer: ref(container),
			currentPage,
			totalPages: ref(4),
			initialPageLayout: 'continuous',
		})

		expect(nav.currentPageDisplay.value).toBe('1–4')
		expect(nav.canGoPrev.value).toBe(false)
		expect(nav.canGoNext.value).toBe(false)

		nav.goToPage(2)
		expect(currentPage.value).toBe(2)
		expect(scrollIntoView).toHaveBeenCalledTimes(1)
		expect(onRerender).not.toHaveBeenCalled()
	})

	it('resolves auto layout to single for landscape pages even in a landscape container', async () => {
		const container = document.createElement('div')
		Object.defineProperty(container, 'clientWidth', { value: 1200 })
		Object.defineProperty(container, 'clientHeight', { value: 800 })

		const mockPdf = {
			numPages: 2,
			getPage: vi.fn().mockResolvedValue({
				rotate: 0,
				getViewport: () => ({ width: 1000, height: 700 }),
				cleanup: vi.fn(),
			}),
		}

		const { nav } = createNav({
			pdfContainer: ref(container),
			loadedPdf: ref(mockPdf),
			initialPageLayout: 'double',
		})

		expect(await nav.resolveAutoLayoutForPage(1)).toBe('single')
		await nav.setAutoLayout(true)
		expect(nav.autoLayout.value).toBe(true)
		expect(nav.pageLayout.value).toBe('single')
	})
})
