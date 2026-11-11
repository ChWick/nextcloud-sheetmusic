/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ToolCard from '../../../src/components/tools/ToolCard.vue'
import type { ToolDefinition } from '../../../src/types/tools'

describe('ToolCard', () => {
	const mockTool: ToolDefinition = {
		id: 'test-tool',
		name: 'Test Tool',
		description: 'A test tool description',
		badge: 'Popular',
	}

	it('renders tool information correctly', () => {
		const wrapper = mount(ToolCard, {
			props: { tool: mockTool },
			global: {
				stubs: {
					NcButton: {
						template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>',
					},
				},
			},
		})

		expect(wrapper.find('[data-test="tool-card-test-tool"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Test Tool')
		expect(wrapper.text()).toContain('A test tool description')
		expect(wrapper.text()).toContain('Popular')
	})

	it('emits select when card is clicked', async () => {
		const wrapper = mount(ToolCard, {
			props: { tool: mockTool },
			global: {
				stubs: {
					NcButton: true,
				},
			},
		})

		await wrapper.trigger('click')
		expect(wrapper.emitted('select')).toBeTruthy()
		expect(wrapper.emitted('select')?.[0]).toEqual(['test-tool'])
	})

	it('emits select when Enter key is pressed', async () => {
		const wrapper = mount(ToolCard, {
			props: { tool: mockTool },
			global: {
				stubs: {
					NcButton: true,
				},
			},
		})

		await wrapper.trigger('keydown.enter')
		expect(wrapper.emitted('select')).toBeTruthy()
		expect(wrapper.emitted('select')?.[0]).toEqual(['test-tool'])
	})

	it('emits select when open button is clicked', async () => {
		const wrapper = mount(ToolCard, {
			props: { tool: mockTool },
			global: {
				stubs: {
					NcButton: {
						template: '<button class="nc-button" @click="$emit(\'click\', $event)"><slot /></button>',
					},
				},
			},
		})

		const button = wrapper.find('button.nc-button')
		await button.trigger('click')
		expect(wrapper.emitted('select')).toBeTruthy()
		expect(wrapper.emitted('select')?.[0]).toEqual(['test-tool'])
	})
})
