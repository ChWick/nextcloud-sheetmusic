/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { config, mount } from '@vue/test-utils'
import SongPageOrderZoomModal from '../../../src/components/song-pdf/SongPageOrderZoomModal.vue'

config.global.stubs = { teleport: true }

describe('SongPageOrderZoomModal.vue', () => {
	it('renders zoomed page preview and handles navigation and append', async () => {
		const wrapper = mount(SongPageOrderZoomModal, {
			props: {
				open: true,
				pageNum: 2,
				totalPages: 5,
				thumbnailUrl: 'data:image/jpeg;base64,largePreview',
			},
		})

		expect(wrapper.text()).toContain('Page 2 of 5')
		const img = wrapper.find('img')
		expect(img.exists()).toBe(true)
		expect(img.attributes('src')).toBe('data:image/jpeg;base64,largePreview')

		const prevBtn = wrapper.find('button[aria-label="Previous page"]')
		const nextBtn = wrapper.find('button[aria-label="Next page"]')
		const addBtn = wrapper.find('button[aria-label="Add page 2 to order"]')

		await prevBtn.trigger('click')
		expect(wrapper.emitted('prev')).toBeDefined()

		await nextBtn.trigger('click')
		expect(wrapper.emitted('next')).toBeDefined()

		await addBtn.trigger('click')
		expect(wrapper.emitted('append')).toEqual([[2]])
	})

	it('disables previous button on first page', () => {
		const wrapper = mount(SongPageOrderZoomModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 3,
			},
		})

		const prevBtn = wrapper.find('button[aria-label="Previous page"]')
		expect(prevBtn.attributes('disabled')).toBeDefined()
	})

	it('disables next button on last page', () => {
		const wrapper = mount(SongPageOrderZoomModal, {
			props: {
				open: true,
				pageNum: 3,
				totalPages: 3,
			},
		})

		const nextBtn = wrapper.find('button[aria-label="Next page"]')
		expect(nextBtn.attributes('disabled')).toBeDefined()
	})
})
