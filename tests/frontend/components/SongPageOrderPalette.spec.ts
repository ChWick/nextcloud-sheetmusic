/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPageOrderPalette from '../../../src/components/song-pdf/SongPageOrderPalette.vue'

describe('SongPageOrderPalette.vue', () => {
	it('renders clickable chips for each page when totalPages > 1', async () => {
		const wrapper = mount(SongPageOrderPalette, {
			props: {
				totalPages: 3,
				disabled: false,
			},
		})

		const chips = wrapper.findAll('button')
		expect(chips.length).toBe(3)
		expect(chips[0].text()).toContain('Page 1')
		expect(chips[1].text()).toContain('Page 2')
		expect(chips[2].text()).toContain('Page 3')

		await chips[1].trigger('click')
		expect(wrapper.emitted('append')).toEqual([[2]])
	})

	it('does not render palette when totalPages <= 1', () => {
		const wrapper = mount(SongPageOrderPalette, {
			props: {
				totalPages: 1,
				disabled: false,
			},
		})

		expect(wrapper.findAll('button').length).toBe(0)
	})

	it('disables chips when disabled prop is true', () => {
		const wrapper = mount(SongPageOrderPalette, {
			props: {
				totalPages: 3,
				disabled: true,
			},
		})

		const chips = wrapper.findAll('button')
		for (const chip of chips) {
			expect(chip.attributes('disabled')).toBeDefined()
		}
	})
})
