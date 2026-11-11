/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ToolChips from '../../../src/components/tools/ToolChips.vue'
import type { ToolDefinition } from '../../../src/types/tools'

describe('ToolChips', () => {
	const mockTools: ToolDefinition[] = [
		{ id: 'tool-a', name: 'Tool A', description: 'Description A' },
		{ id: 'tool-b', name: 'Tool B', description: 'Description B' },
	]

	it('renders all tool chips', () => {
		const wrapper = mount(ToolChips, {
			props: {
				tools: mockTools,
				activeToolId: 'tool-a',
			},
		})

		const chips = wrapper.findAll('button')
		expect(chips).toHaveLength(2)
		expect(chips[0].text()).toContain('Tool A')
		expect(chips[1].text()).toContain('Tool B')
		expect(chips[0].attributes('aria-pressed')).toBe('true')
		expect(chips[1].attributes('aria-pressed')).toBe('false')
	})

	it('emits select when a chip is clicked', async () => {
		const wrapper = mount(ToolChips, {
			props: {
				tools: mockTools,
				activeToolId: null,
			},
		})

		await wrapper.find('[data-test="tool-chip-tool-b"]').trigger('click')
		expect(wrapper.emitted('select')).toBeTruthy()
		expect(wrapper.emitted('select')?.[0]).toEqual(['tool-b'])
	})
})
