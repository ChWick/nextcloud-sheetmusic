/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPageOrderGrid from '../../../src/components/song-pdf/SongPageOrderGrid.vue'

describe('SongPageOrderGrid.vue', () => {
	it('renders resulting order sequence with repeated pages', () => {
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

		// Renders 3 cards in resulting sequence
		const seqCards = wrapper.findAll('[data-test^="seq-card-"]')
		expect(seqCards.length).toBe(3)
		// Position 1 and 3 are both Page 1
		expect(wrapper.find('[data-test="seq-card-0-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="seq-card-1-2"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="seq-card-2-1"]').exists()).toBe(true)
	})

	it('switches to all source pages mode when clicking tab', async () => {
		const wrapper = mount(SongPageOrderGrid, {
			props: {
				pageSequence: [1, 2],
				totalPages: 4,
				cardWidth: 220,
			},
		})

		const sourceTab = wrapper.find('[data-test="mode-source-btn"]')
		await sourceTab.trigger('click')

		const sourceCards = wrapper.findAll('[data-test^="page-card-"]')
		expect(sourceCards.length).toBe(4)
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
		await zoomBtns[1].trigger('click')
		expect(wrapper.emitted('zoom')).toEqual([[2]])
	})
})
