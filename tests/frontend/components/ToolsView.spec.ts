/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ToolsView from '../../../src/components/ToolsView.vue'

const mockRoute = { query: {} as Record<string, any> }
const mockRouter = { replace: vi.fn() }

vi.mock('vue-router', () => ({
	useRoute: () => mockRoute,
	useRouter: () => mockRouter,
}))

describe('ToolsView', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		mockRoute.query = {}
	})

	const mountComponent = () => {
		return mount(ToolsView, {
			props: { selectedLibrary: 1 },
			global: {
				stubs: {
					ViewHeader: {
						props: ['title', 'showBack', 'backTitle'],
						template: `
							<div data-test="view-header">
								<span data-test="header-title">{{ title }}</span>
								<button v-if="showBack" data-test="header-back" @click="$emit('back')">Back</button>
								<slot />
							</div>
						`,
						emits: ['back'],
					},
					PageOrderResetTool: {
						template: '<div data-test="mock-page-order-tool" />',
					},
					NcButton: {
						template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>',
					},
				},
			},
		})
	}

	it('renders tools overview when opened without tool query parameter', () => {
		const wrapper = mountComponent()

		expect(wrapper.find('[data-test="tools-view"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="tools-overview"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="mock-page-order-tool"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="header-title"]').text()).toBe('Library Tools')
		expect(wrapper.find('[data-test="header-back"]').exists()).toBe(false)
	})

	it('renders active tool directly when tool query is present in route', () => {
		mockRoute.query = { tool: 'page-order-reset' }
		const wrapper = mountComponent()

		expect(wrapper.find('[data-test="tools-view"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="tools-overview"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="mock-page-order-tool"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="header-title"]').text()).toBe('Reset Page Ordering')
		expect(wrapper.find('[data-test="header-back"]').exists()).toBe(true)
	})

	it('navigates to tool when selecting it from tools overview', async () => {
		const wrapper = mountComponent()

		expect(wrapper.find('[data-test="tools-overview"]').exists()).toBe(true)

		// Click the tool card
		await wrapper.find('[data-test="tool-card-page-order-reset"]').trigger('click')

		expect(mockRouter.replace).toHaveBeenCalledWith({
			query: { tool: 'page-order-reset' },
		})
		expect(wrapper.find('[data-test="mock-page-order-tool"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="tools-overview"]').exists()).toBe(false)
	})

	it('navigates back to tools overview when clicking back button in header', async () => {
		mockRoute.query = { tool: 'page-order-reset' }
		const wrapper = mountComponent()

		expect(wrapper.find('[data-test="mock-page-order-tool"]').exists()).toBe(true)

		await wrapper.find('[data-test="header-back"]').trigger('click')

		expect(mockRouter.replace).toHaveBeenCalledWith({
			query: {},
		})
		expect(wrapper.find('[data-test="tools-overview"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="mock-page-order-tool"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="header-title"]').text()).toBe('Library Tools')
	})

	it('exposes Metronome, Pitch Pipe, and Tuner tools and renders their reusable panels', async () => {
		const wrapper = mountComponent()

		expect(wrapper.find('[data-test="tool-card-metronome"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="tool-card-pitch-pipe"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="tool-card-tuner"]').exists()).toBe(true)

		await wrapper.find('[data-test="tool-card-metronome"]').trigger('click')
		expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: { tool: 'metronome' } })
		expect(wrapper.find('[data-test="metronome-tool"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="metronome-panel"]').exists()).toBe(true)

		await wrapper.find('[data-test="header-back"]').trigger('click')
		await wrapper.find('[data-test="tool-card-pitch-pipe"]').trigger('click')
		expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: { tool: 'pitch-pipe' } })
		expect(wrapper.find('[data-test="pitch-pipe-tool"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="pitch-pipe-note-grid"]').exists()).toBe(true)

		await wrapper.find('[data-test="header-back"]').trigger('click')
		await wrapper.find('[data-test="tool-card-tuner"]').trigger('click')
		expect(mockRouter.replace).toHaveBeenLastCalledWith({ query: { tool: 'tuner' } })
		expect(wrapper.find('[data-test="tuner-tool"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="tuner-section"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="pitch-a4-value"]').text()).toBe('440 Hz')
	})
})
