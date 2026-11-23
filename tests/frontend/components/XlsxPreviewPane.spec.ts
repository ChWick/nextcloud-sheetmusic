/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import XlsxPreviewPane from '../../../src/components/file-preview/XlsxPreviewPane.vue'

describe('XlsxPreviewPane', () => {
	it('renders a single sheet without tabs', () => {
		const wrapper = mount(XlsxPreviewPane, {
			props: {
				sheets: [{ name: 'Sheet1', html: '<table><tr><td>A1</td></tr></table>' }],
			},
		})

		expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
		expect(wrapper.html()).toContain('<td>A1</td>')
	})

	it('switches between multiple sheets and resets to first sheet when sheets prop changes', async () => {
		const wrapper = mount(XlsxPreviewPane, {
			props: {
				sheets: [
					{ name: 'Program', html: '<table><tr><td>Op. 1</td></tr></table>' },
					{ name: 'Notes', html: '<table><tr><td>Encore</td></tr></table>' },
				],
			},
		})

		const tabs = wrapper.findAll('[role="tab"]')
		expect(tabs).toHaveLength(2)
		expect(wrapper.html()).toContain('Op. 1')

		await tabs[1].trigger('click')
		expect(tabs[1].attributes('aria-selected')).toBe('true')
		expect(wrapper.html()).toContain('Encore')

		await wrapper.setProps({
			sheets: [
				{ name: 'New1', html: '<table><tr><td>Fresh</td></tr></table>' },
				{ name: 'New2', html: '<table><tr><td>Second</td></tr></table>' },
			],
		})
		expect(wrapper.html()).toContain('Fresh')
	})
})
