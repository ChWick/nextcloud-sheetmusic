/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPageOrderGrid from '../../../src/components/song-pdf/SongPageOrderGrid.vue'

describe('SongPageOrderGrid.vue', () => {
	it('renders all page preview cards in parallel', () => {
		const wrapper = mount(SongPageOrderGrid, {
			props: {
				totalPages: 3,
				pageThumbnails: {
					1: 'thumb1',
					2: 'thumb2',
					3: 'thumb3',
				},
				pageOccurrences: {
					1: 1,
					2: 2,
					3: 0,
				},
				cardWidth: 200,
			},
		})

		expect(wrapper.text()).toContain('Page 1')
		expect(wrapper.text()).toContain('Page 2')
		expect(wrapper.text()).toContain('Page 3')
		expect(wrapper.text()).toContain('1×')
		expect(wrapper.text()).toContain('2×')
		expect(wrapper.text()).toContain('Unused')
	})

	it('propagates append and zoom events from cards', async () => {
		const wrapper = mount(SongPageOrderGrid, {
			props: {
				totalPages: 2,
				cardWidth: 220,
			},
		})

		const addButtons = wrapper.findAll('button').filter((b) => b.text().includes('Add to Order'))
		expect(addButtons.length).toBe(2)
		await addButtons[1].trigger('click')
		expect(wrapper.emitted('append')).toEqual([[2]])
	})
})
