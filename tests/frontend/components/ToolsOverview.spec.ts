/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ToolsOverview from '../../../src/components/tools/ToolsOverview.vue'
import type { ToolDefinition } from '../../../src/types/tools'

describe('ToolsOverview', () => {
	const mockTools: ToolDefinition[] = [
		{ id: 'page-order-reset', name: 'Reset Page Ordering', description: 'Reset ordering of multi-page songs' },
		{ id: 'another-tool', name: 'Another Tool', description: 'Another tool description' },
	]

	it('renders overview with banner, chips and tool cards', () => {
		const wrapper = mount(ToolsOverview, {
			props: { tools: mockTools },
			global: {
				stubs: {
					NcButton: {
						template: '<button class="nc-button"><slot /></button>',
					},
				},
			},
		})

		expect(wrapper.find('[data-test="tools-overview"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="tools-grid"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="tool-chips"]').exists()).toBe(true)
		expect(wrapper.findAll('[data-test^="tool-card-"]')).toHaveLength(2)
		expect(wrapper.text()).toContain('Reset Page Ordering')
		expect(wrapper.text()).toContain('Another Tool')
	})

	it('emits select when a tool chip is clicked', async () => {
		const wrapper = mount(ToolsOverview, {
			props: { tools: mockTools },
			global: {
				stubs: {
					NcButton: true,
				},
			},
		})

		await wrapper.find('[data-test="tool-chip-another-tool"]').trigger('click')
		expect(wrapper.emitted('select')).toBeTruthy()
		expect(wrapper.emitted('select')?.[0]).toEqual(['another-tool'])
	})

	it('emits select when a tool card is clicked', async () => {
		const wrapper = mount(ToolsOverview, {
			props: { tools: mockTools },
			global: {
				stubs: {
					NcButton: true,
				},
			},
		})

		await wrapper.find('[data-test="tool-card-page-order-reset"]').trigger('click')
		expect(wrapper.emitted('select')).toBeTruthy()
		expect(wrapper.emitted('select')?.[0]).toEqual(['page-order-reset'])
	})
})
