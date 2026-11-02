/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPageOrderPresets from '../../../src/components/song-pdf/SongPageOrderPresets.vue'

describe('SongPageOrderPresets.vue', () => {
	it('renders preset buttons and handles clicks', async () => {
		const wrapper = mount(SongPageOrderPresets, {
			props: {
				totalPages: 5,
				disabled: false,
			},
		})

		const buttons = wrapper.findAll('button')
		// "All Pages (1-5)", "Repeat All (1-5, 1-5)", "Reverse Order"
		expect(buttons.length).toBe(3)
		expect(buttons[0].text()).toContain('All Pages (1-5)')
		expect(buttons[1].text()).toContain('Repeat All')
		expect(buttons[2].text()).toContain('Reverse Order')

		await buttons[0].trigger('click')
		expect(wrapper.emitted('select')).toEqual([['all']])

		await buttons[1].trigger('click')
		expect(wrapper.emitted('select')![1]).toEqual(['repeat'])

		await buttons[2].trigger('click')
		expect(wrapper.emitted('select')![2]).toEqual(['reverse'])
	})

	it('hides reverse order button when totalPages <= 1', () => {
		const wrapper = mount(SongPageOrderPresets, {
			props: {
				totalPages: 1,
				disabled: false,
			},
		})

		const buttons = wrapper.findAll('button')
		expect(buttons.length).toBe(2)
		expect(wrapper.text()).not.toContain('Reverse Order')
	})

	it('disables buttons when disabled prop is true', () => {
		const wrapper = mount(SongPageOrderPresets, {
			props: {
				totalPages: 4,
				disabled: true,
			},
		})

		const buttons = wrapper.findAll('button')
		for (const btn of buttons) {
			expect(btn.attributes('disabled')).toBeDefined()
		}
	})
})
