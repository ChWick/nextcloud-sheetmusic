/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPageOrderGrid from '../../../src/components/song-pdf/SongPageOrderGrid.vue'

describe('SongPageOrderGrid.vue', () => {
	it('renders both original PDF pages and resulting order by default in stacked view', () => {
		const wrapper = mount(SongPageOrderGrid, {
			props: {
				pageSequence: [1, 2, 1],
				totalPages: 3,
				pageThumbnails: {
					1: 'thumb1',
					2: 'thumb2',
					3: 'thumb3',
				},
				pageOccurrences: {
					1: 2,
					2: 1,
					3: 0,
				},
				cardWidth: 200,
			},
		})

		// Both sections exist
		expect(wrapper.find('[data-test="source-pages-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="resulting-order-section"]').exists()).toBe(true)

		// Source pages has 3 cards with counters (Page 1 has 2x, Page 3 has Unused)
		const sourceCards = wrapper.findAll('[data-test^="page-card-"]')
		expect(sourceCards.length).toBe(3)
		expect(wrapper.find('[data-test="page-card-1"]').text()).toContain('2×')
		expect(wrapper.find('[data-test="page-card-3"]').text()).toContain('Unused')

		// Resulting sequence has 3 cards: #1 Page 1, #2 Page 2, #3 Page 1
		const seqCards = wrapper.findAll('[data-test^="seq-card-"]')
		expect(seqCards.length).toBe(3)
		expect(wrapper.find('[data-test="seq-card-0-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="seq-card-1-2"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="seq-card-2-1"]').exists()).toBe(true)
	})

	it('switches between view modes (sequence only, source only, both)', async () => {
		const wrapper = mount(SongPageOrderGrid, {
			props: {
				pageSequence: [1, 2],
				totalPages: 4,
				cardWidth: 220,
			},
		})

		// Initially both
		expect(wrapper.find('[data-test="source-pages-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="resulting-order-section"]').exists()).toBe(true)

		// Switch to Resulting Order only
		const seqTab = wrapper.find('[data-test="mode-sequence-btn"]')
		await seqTab.trigger('click')
		expect(wrapper.find('[data-test="source-pages-section"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="resulting-order-section"]').exists()).toBe(true)

		// Switch to All Source Pages only
		const sourceTab = wrapper.find('[data-test="mode-source-btn"]')
		await sourceTab.trigger('click')
		expect(wrapper.find('[data-test="source-pages-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="resulting-order-section"]').exists()).toBe(false)

		// Switch back to Both
		const bothTab = wrapper.find('[data-test="mode-both-btn"]')
		await bothTab.trigger('click')
		expect(wrapper.find('[data-test="source-pages-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="resulting-order-section"]').exists()).toBe(true)
	})

	it('propagates remove-sequence-index and zoom events', async () => {
		const wrapper = mount(SongPageOrderGrid, {
			props: {
				pageSequence: [1, 2, 1],
				totalPages: 3,
			},
		})

		const removeBtns = wrapper.findAll('[data-test="card-remove-btn"]')
		expect(removeBtns.length).toBe(3)
		await removeBtns[2].trigger('click')
		expect(wrapper.emitted('remove-sequence-index')).toEqual([[2]])

		const zoomBtns = wrapper.findAll('[data-test="card-zoom-btn"]')
		// zoomBtns exist for both source and sequence cards
		expect(zoomBtns.length).toBeGreaterThan(0)
		await zoomBtns[0].trigger('click')
		expect(wrapper.emitted('zoom')).toBeTruthy()
	})

	it('emits insert-at when source page card starts dragging and drops on sequence card', async () => {
		const wrapper = mount(SongPageOrderGrid, {
			props: {
				pageSequence: [1, 2],
				totalPages: 3,
			},
		})

		const sourceCard1 = wrapper.find('[data-test="page-card-3"]')
		expect(sourceCard1.exists()).toBe(true)

		const dataStore: Record<string, string> = {}
		const mockDataTransfer = {
			effectAllowed: '',
			dropEffect: '',
			setData: vi.fn((k: string, v: string) => { dataStore[k] = v }),
			getData: vi.fn((k: string) => dataStore[k] || ''),
		}

		// Drag start on source page 3
		await sourceCard1.trigger('dragstart', {
			dataTransfer: mockDataTransfer,
		})

		// Drop on sequence card index 0 (left half)
		const seqCard0 = wrapper.find('[data-test="seq-card-0-1"]')
		await seqCard0.trigger('dragover', {
			clientX: 10,
			dataTransfer: mockDataTransfer,
		})
		await seqCard0.trigger('drop', {
			dataTransfer: mockDataTransfer,
		})

		expect(wrapper.emitted('insert-at')).toEqual([[3, 0]])
	})

	it('emits reorder-sequence when sequence card is dragged and dropped onto another card', async () => {
		const wrapper = mount(SongPageOrderGrid, {
			props: {
				pageSequence: [1, 2, 3],
				totalPages: 3,
			},
		})

		const dataStore: Record<string, string> = {}
		const mockDataTransfer = {
			effectAllowed: '',
			dropEffect: '',
			setData: vi.fn((k: string, v: string) => { dataStore[k] = v }),
			getData: vi.fn((k: string) => dataStore[k] || ''),
		}

		// Drag start on sequence card index 2 (Page 3)
		const seqCard2 = wrapper.find('[data-test="seq-card-2-3"]')
		await seqCard2.trigger('dragstart', {
			dataTransfer: mockDataTransfer,
		})

		// Drop on sequence card index 0 (left half)
		const seqCard0 = wrapper.find('[data-test="seq-card-0-1"]')
		await seqCard0.trigger('dragover', {
			clientX: 5,
			dataTransfer: mockDataTransfer,
		})
		await seqCard0.trigger('drop', {
			dataTransfer: mockDataTransfer,
		})

		expect(wrapper.emitted('reorder-sequence')).toEqual([[2, 0]])
	})

	it('emits insert-at when source page is dropped on end drop slot', async () => {
		const wrapper = mount(SongPageOrderGrid, {
			props: {
				pageSequence: [1, 2],
				totalPages: 4,
			},
		})

		const dataStore: Record<string, string> = {}
		const mockDataTransfer = {
			effectAllowed: '',
			dropEffect: '',
			setData: vi.fn((k: string, v: string) => { dataStore[k] = v }),
			getData: vi.fn((k: string) => dataStore[k] || ''),
		}

		// Drag start on source page 4
		const sourceCard4 = wrapper.find('[data-test="page-card-4"]')
		await sourceCard4.trigger('dragstart', {
			dataTransfer: mockDataTransfer,
		})

		// Drop on end drop slot
		const endSlot = wrapper.find('[data-test="sequence-end-drop-slot"]')
		expect(endSlot.exists()).toBe(true)
		await endSlot.trigger('drop', {
			dataTransfer: mockDataTransfer,
		})

		expect(wrapper.emitted('insert-at')).toEqual([[4, 2]])
	})
})
