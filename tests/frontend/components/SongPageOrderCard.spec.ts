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

	it('renders sequence badge and remove button in sequence mode', async () => {
		const wrapper = mount(SongPageOrderCard, {
			props: {
				pageNum: 1,
				sequenceIndex: 2,
				sequenceTotal: 3,
				showRemove: true,
			},
		})

		expect(wrapper.find('[data-test="sequence-badge"]').text()).toBe('#3')
		const removeBtn = wrapper.find('[data-test="card-remove-btn"]')
		expect(removeBtn.exists()).toBe(true)
		await removeBtn.trigger('click')
		expect(wrapper.emitted('remove')).toEqual([[2]])
	})

	it('renders drag handle and draggable attribute when draggable is true', () => {
		const wrapper = mount(SongPageOrderCard, {
			props: {
				pageNum: 1,
				draggable: true,
				disabled: false,
			},
		})

		expect(wrapper.attributes('draggable')).toBe('true')
		expect(wrapper.find('[data-test="card-drag-handle"]').exists()).toBe(true)
	})

	it('does not allow dragging when disabled is true', () => {
		const wrapper = mount(SongPageOrderCard, {
			props: {
				pageNum: 1,
				draggable: true,
				disabled: true,
			},
		})

		expect(wrapper.attributes('draggable')).toBe('false')
		expect(wrapper.find('[data-test="card-drag-handle"]').exists()).toBe(false)
	})

	it('applies drop indicator classes', () => {
		const leftWrapper = mount(SongPageOrderCard, {
			props: {
				pageNum: 1,
				dropIndicator: 'left',
			},
		})
		expect(leftWrapper.classes().some((c) => c.includes('dropIndicatorLeft'))).toBe(true)

		const rightWrapper = mount(SongPageOrderCard, {
			props: {
				pageNum: 1,
				dropIndicator: 'right',
			},
		})
		expect(rightWrapper.classes().some((c) => c.includes('dropIndicatorRight'))).toBe(true)
	})

	it('emits append when Add to Order button is clicked', async () => {
		const wrapper = mount(SongPageOrderCard, {
			props: {
				pageNum: 4,
			},
		})

		const addBtn = wrapper.find('[data-test="card-add-btn"]')
		expect(addBtn.exists()).toBe(true)
		await addBtn.trigger('click')
		expect(wrapper.emitted('append')).toEqual([[4]])
	})

	it('emits zoom when zoom button is clicked', async () => {
		const wrapper = mount(SongPageOrderCard, {
			props: {
				pageNum: 3,
			},
		})

		const zoomBtn = wrapper.find('[data-test="card-zoom-btn"]')
		expect(zoomBtn.exists()).toBe(true)
		await zoomBtn.trigger('click')
		expect(wrapper.emitted('zoom')).toEqual([[3]])
	})
})
