import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfPageGrid from '../../../src/components/song-pdf/SongPdfPageGrid.vue'
import SongPdfPageCard from '../../../src/components/song-pdf/SongPdfPageCard.vue'

describe('SongPdfPageGrid.vue', () => {
	const defaultProps = {
		totalPages: 3,
		activeMode: 'rotate' as const,
		selectedPages: [2],
		isPageCropped: (p: number) => p === 1,
		isPageEnhanced: (p: number) => p === 2,
		isPageDeleted: (p: number) => p === 3,
		pageRotations: [90, 0, 180],
		getCropIndicatorText: (p: number) => `crop-${p}`,
		getEnhanceIndicatorText: (p: number) => `enhance-${p}`,
		getPageSegmentsSummary: (_p: number) => [],
		remainingPagesCount: 2,
	}

	it('renders a SongPdfPageCard for each page in totalPages', () => {
		const wrapper = mount(SongPdfPageGrid, {
			props: defaultProps,
		})

		const cards = wrapper.findAllComponents(SongPdfPageCard)
		expect(cards).toHaveLength(3)
		expect(cards[0].props('pageNum')).toBe(1)
		expect(cards[1].props('pageNum')).toBe(2)
		expect(cards[2].props('pageNum')).toBe(3)
		expect(cards[0].props('isCropped')).toBe(true)
		expect(cards[1].props('isEnhanced')).toBe(true)
		expect(cards[2].props('isDeleted')).toBe(true)
		expect(cards[0].props('rotationDeg')).toBe(90)
		expect(cards[1].props('isSelected')).toBe(true)
	})

	it('forwards rotate-page event with 0-based page index and delta degrees', async () => {
		const wrapper = mount(SongPdfPageGrid, {
			props: defaultProps,
		})

		const cards = wrapper.findAllComponents(SongPdfPageCard)
		cards[1].vm.$emit('rotate-page', 90)

		expect(wrapper.emitted('rotate-page')).toBeTruthy()
		expect(wrapper.emitted('rotate-page')?.[0]).toEqual([1, 90])
	})

	it('forwards card-click, open-crop-modal, and open-enhance-modal events with pageNum', async () => {
		const wrapper = mount(SongPdfPageGrid, {
			props: defaultProps,
		})

		const cards = wrapper.findAllComponents(SongPdfPageCard)
		cards[0].vm.$emit('card-click', 1)
		cards[0].vm.$emit('open-crop-modal')
		cards[0].vm.$emit('open-enhance-modal')

		expect(wrapper.emitted('card-click')?.[0]).toEqual([1])
		expect(wrapper.emitted('open-crop-modal')?.[0]).toEqual([1])
		expect(wrapper.emitted('open-enhance-modal')?.[0]).toEqual([1])
	})

	it('forwards open-join-modal and toggle-delete-page events', async () => {
		const wrapper = mount(SongPdfPageGrid, {
			props: defaultProps,
		})

		const cards = wrapper.findAllComponents(SongPdfPageCard)
		cards[0].vm.$emit('open-join-modal', 2)
		cards[2].vm.$emit('toggle-delete-page')

		expect(wrapper.emitted('open-join-modal')?.[0]).toEqual([1, 2])
		expect(wrapper.emitted('toggle-delete-page')?.[0]).toEqual([3])
	})
})
