/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { config, mount } from '@vue/test-utils'
import SongPageOrderZoomModal from '../../../src/components/song-pdf/SongPageOrderZoomModal.vue'

config.global.stubs = { teleport: true }

describe('SongPageOrderZoomModal.vue', () => {
	it('renders zoomed page preview with high-res url when available', async () => {
		const wrapper = mount(SongPageOrderZoomModal, {
			props: {
				open: true,
				pageNum: 2,
				totalPages: 5,
				thumbnailUrl: 'data:image/jpeg;base64,lowResThumbnail',
				highResUrl: 'data:image/jpeg;base64,highResImage',
			},
		})

		expect(wrapper.text()).toContain('Page 2 of 5')
		const img = wrapper.find('[data-test="zoom-image"]')
		expect(img.exists()).toBe(true)
		// Should prioritize highResUrl over thumbnailUrl
		expect(img.attributes('src')).toBe('data:image/jpeg;base64,highResImage')

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

	it('shows high-res rendering loading badge when isLoadingHighRes is true and no highResUrl yet', () => {
		const wrapper = mount(SongPageOrderZoomModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 3,
				thumbnailUrl: 'data:image/jpeg;base64,thumb',
				isLoadingHighRes: true,
			},
			global: {
				stubs: {
					NcLoadingIcon: true,
				},
			},
		})

		expect(wrapper.find('[data-test="loading-high-res"]').exists()).toBe(true)
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

	it('hides Add to Order when showAppendAction is false and applies rotationDeg style', () => {
		const wrapper = mount(SongPageOrderZoomModal, {
			props: {
				open: true,
				pageNum: 1,
				totalPages: 3,
				thumbnailUrl: 'data:image/jpeg;base64,thumb',
				showAppendAction: false,
				rotationDeg: 90,
			},
		})

		expect(wrapper.find('button[aria-label="Add page 1 to order"]').exists()).toBe(false)
		const img = wrapper.find('[data-test="zoom-image"]')
		expect(img.attributes('style')).toContain('rotate(90deg)')
	})
})
