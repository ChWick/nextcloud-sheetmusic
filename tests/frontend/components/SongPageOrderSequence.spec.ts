/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPageOrderSequence from '../../../src/components/song-pdf/SongPageOrderSequence.vue'

describe('SongPageOrderSequence.vue', () => {
	it('renders chips for each page in sequence with index and labels', async () => {
		const wrapper = mount(SongPageOrderSequence, {
			props: {
				pages: [1, 3, 2],
				disabled: false,
			},
		})

		expect(wrapper.text()).toContain('3 pages in sequence')
		const removeBtns = wrapper.findAll('button')
		expect(removeBtns.length).toBe(3)

		expect(wrapper.text()).toContain('Page 1')
		expect(wrapper.text()).toContain('Page 3')
		expect(wrapper.text()).toContain('Page 2')

		await removeBtns[1].trigger('click')
		expect(wrapper.emitted('remove')).toEqual([[1]])
	})

	it('disables remove button when sequence has only 1 page', () => {
		const wrapper = mount(SongPageOrderSequence, {
			props: {
				pages: [1],
				disabled: false,
			},
		})

		const removeBtn = wrapper.find('button')
		expect(removeBtn.attributes('disabled')).toBeDefined()
	})

	it('does not render when pages array is empty', () => {
		const wrapper = mount(SongPageOrderSequence, {
			props: {
				pages: [],
				disabled: false,
			},
		})

		expect(wrapper.find('div').exists()).toBe(false)
	})
})
