import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongFilterBar from '../../../src/components/SongFilterBar.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
}))

describe('SongFilterBar.vue', () => {
	it('renders select dropdowns with keepOpen dynamically controlled by Control key', async () => {
		const wrapper = mount(SongFilterBar, {
			props: {
				composers: [{ id: 1, name: 'Bach' }],
			},
		})

		const composerSelect = wrapper.findComponent({ name: 'NcSelect' })
		expect(composerSelect.exists()).toBe(true)

		// Initially without Control, keepOpen should be false
		expect(composerSelect.props('keepOpen')).toBe(false)

		// Trigger keydown with Control
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Control', ctrlKey: true }))
		await wrapper.vm.$nextTick()
		expect(composerSelect.props('keepOpen')).toBe(true)

		// Trigger keyup
		window.dispatchEvent(new KeyboardEvent('keyup', { key: 'Control', ctrlKey: false }))
		await wrapper.vm.$nextTick()
		expect(composerSelect.props('keepOpen')).toBe(false)
	})
})
