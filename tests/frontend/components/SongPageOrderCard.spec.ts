/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPageOrderCard from '../../../src/components/song-pdf/SongPageOrderCard.vue'

describe('SongPageOrderCard.vue', () => {
	it('renders page number, thumbnail image, and occurrence badge', () => {
		const wrapper = mount(SongPageOrderCard, {
			props: {
				pageNum: 2,
				thumbnailUrl: 'data:image/jpeg;base64,mockThumbnail',
				occurrenceCount: 3,
				cardWidth: 260,
			},
		})

		expect(wrapper.text()).toContain('Page 2')
		expect(wrapper.text()).toContain('3×')
		const img = wrapper.find('img')
		expect(img.exists()).toBe(true)
		expect(img.attributes('src')).toBe('data:image/jpeg;base64,mockThumbnail')
	})

	it('shows Unused badge when occurrenceCount is 0', () => {
		const wrapper = mount(SongPageOrderCard, {
			props: {
				pageNum: 1,
				occurrenceCount: 0,
			},
		})

		expect(wrapper.text()).toContain('Unused')
	})

	it('emits append when Add to Order button is clicked', async () => {
		const wrapper = mount(SongPageOrderCard, {
			props: {
				pageNum: 4,
			},
		})

		const addBtn = wrapper.findAll('button').find((b) => b.text().includes('Add to Order'))
		expect(addBtn).toBeDefined()
		await addBtn!.trigger('click')
		expect(wrapper.emitted('append')).toEqual([[4]])
	})

	it('emits zoom when zoom button is clicked', async () => {
		const wrapper = mount(SongPageOrderCard, {
			props: {
				pageNum: 3,
			},
		})

		const zoomBtn = wrapper.find('button[aria-label="Zoom in on page 3"]')
		expect(zoomBtn.exists()).toBe(true)
		await zoomBtn.trigger('click')
		expect(wrapper.emitted('zoom')).toEqual([[3]])
	})
})
