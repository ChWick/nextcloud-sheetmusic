/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ToolsView from '../../../src/components/ToolsView.vue'

vi.mock('vue-router', () => ({
	useRoute: () => ({
		query: {},
	}),
	useRouter: () => ({
		replace: vi.fn(),
	}),
}))

describe('ToolsView', () => {
	it('renders tools view with active page-order-reset tool', () => {
		const wrapper = mount(ToolsView, {
			props: { selectedLibrary: 1 },
			global: {
				stubs: {
					ViewHeader: {
						template: '<div data-test="view-header"><slot /></div>',
					},
					PageOrderResetTool: {
						template: '<div data-test="mock-page-order-tool" />',
					},
				},
			},
		})

		expect(wrapper.find('[data-test="tools-view"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="mock-page-order-tool"]').exists()).toBe(true)
	})
})
