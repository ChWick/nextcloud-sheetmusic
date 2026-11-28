/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { usePdfPageNavigation } from '../../../src/composables/usePdfPageNavigation'
import { getInterleavedStartDoubleSpread, getLastDoubleSpread } from '../../../src/utils/page-turn'

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

	it('scrolls to placeholder in continuous mode on nextPage, prevPage, and goToPage without rerendering', () => {
		const scrollIntoView1 = vi.fn()
		const scrollIntoView2 = vi.fn()
		const p1 = document.createElement('div')
		p1.dataset.pageIndex = '1'
		p1.scrollIntoView = scrollIntoView1
		const p2 = document.createElement('div')
		p2.dataset.pageIndex = '2'
		p2.scrollIntoView = scrollIntoView2
		const container = document.createElement('div')
		container.appendChild(p1)
		container.appendChild(p2)

		const currentPage = ref(1)
		const { nav, onRerender } = createNav({
			pdfContainer: ref(container),
			currentPage,
			totalPages: ref(4),
			initialPageLayout: 'continuous',
		})

		expect(nav.currentPageDisplay.value).toBe('1–4')
		expect(nav.canGoPrev.value).toBe(false)
		expect(nav.canGoNext.value).toBe(true)

		expect(nav.nextPage()).toBe(true)
		expect(currentPage.value).toBe(2)
		expect(nav.canGoPrev.value).toBe(true)
		expect(scrollIntoView2).toHaveBeenCalledTimes(1)

		expect(nav.prevPage()).toBe(true)
		expect(currentPage.value).toBe(1)
		expect(scrollIntoView1).toHaveBeenCalledTimes(1)
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

	it('preserves slot positions when flipping forward and backward across interleaved songs in half-page mode', () => {
		// Odd-page Song 1 (3 pages) + interleave -> effective total = 4: [1,2] -> [3,2] -> [3,→1]
		const navOdd = createNav({
			currentPage: ref(1),
			totalPages: ref(3),
			initialPageLayout: 'double',
			initialDoublePageTurnMode: 'half',
			interleaveSongs: ref(true),
			nextFilePath: ref('/music/song2.pdf'),
		}).nav
		navOdd.doubleSpread.value = [1, 2]
		expect(navOdd.nextPage()).toBe(true)
		expect(navOdd.doubleSpread.value).toEqual([3, 2])
		expect(navOdd.nextPage()).toBe(true)
		expect(navOdd.doubleSpread.value).toEqual([3, 4])
		expect(navOdd.nextSongInterleaveSlot.value).toBe('right')
		expect(navOdd.currentPageDisplay.value).toBe('3 / →1')
		expect(navOdd.nextPage()).toBe(false)

		// Entering Song 2 after right-slot interleave keeps Song 2 p.1 on the right: [2, 1] -> [2, 3]
		expect(getInterleavedStartDoubleSpread(3, 'half')).toEqual([2, 1])
		// Going back from Song 2 [2, 1] opens Song 1 at [3, 4] (Song 2 p.1 stays on the right)
		expect(getLastDoubleSpread(navOdd.effectiveDoubleTotalPages.value, 'half')).toEqual([3, 4])

		// Even-page Song 1 (2 pages) + interleave -> effective total = 3: [1,2] -> [→1, 2]
		const navEven = createNav({
			currentPage: ref(1),
			totalPages: ref(2),
			initialPageLayout: 'double',
			initialDoublePageTurnMode: 'half',
			interleaveSongs: ref(true),
			nextFilePath: ref('/music/song2.pdf'),
		}).nav
		navEven.doubleSpread.value = [1, 2]
		expect(navEven.nextPage()).toBe(true)
		expect(navEven.doubleSpread.value).toEqual([3, 2])
		expect(navEven.nextSongInterleaveSlot.value).toBe('left')
		expect(navEven.currentPageDisplay.value).toBe('→1 / 2')
		expect(navEven.nextPage()).toBe(false)

		// Going back from Song 2 [1, 2] opens even-page Song 1 at [3, 2] (Song 2 p.1 stays on the left)
		expect(getLastDoubleSpread(navEven.effectiveDoubleTotalPages.value, 'half')).toEqual([3, 2])
	})

	it('computes exact final and interleaved-start spreads across spread, step, and half modes', () => {
		expect(getLastDoubleSpread(1, 'half')).toEqual([1, null])
		expect(getLastDoubleSpread(2, 'half')).toEqual([1, 2])
		expect(getLastDoubleSpread(3, 'half')).toEqual([3, 2])
		expect(getLastDoubleSpread(4, 'half')).toEqual([3, 4])
		expect(getLastDoubleSpread(5, 'half')).toEqual([5, 4])

		expect(getLastDoubleSpread(2, 'step')).toEqual([1, 2])
		expect(getLastDoubleSpread(3, 'step')).toEqual([2, 3])
		expect(getLastDoubleSpread(4, 'step')).toEqual([3, 4])

		expect(getLastDoubleSpread(2, 'spread')).toEqual([1, 2])
		expect(getLastDoubleSpread(3, 'spread')).toEqual([3, null])
		expect(getLastDoubleSpread(4, 'spread')).toEqual([3, 4])

		expect(getInterleavedStartDoubleSpread(1, 'half')).toEqual([1, null])
		expect(getInterleavedStartDoubleSpread(2, 'half')).toEqual([2, 1])
		expect(getInterleavedStartDoubleSpread(2, 'spread')).toEqual([2, null])
		expect(getInterleavedStartDoubleSpread(3, 'spread')).toEqual([2, 3])
		expect(getInterleavedStartDoubleSpread(3, 'step')).toEqual([1, 2])
	})
})
