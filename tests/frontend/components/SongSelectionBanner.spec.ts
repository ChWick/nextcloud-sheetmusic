import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongSelectionBanner from '../../../src/components/songs/SongSelectionBanner.vue'

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['size', 'variant', 'title'],
		emits: ['click'],
		template: '<button type="button" :title="title" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
	},
}))

describe('SongSelectionBanner.vue', () => {
	it('shows compare/merge, edit, delete, and clear actions when 2 songs selected and canWrite is true', async () => {
		const wrapper = mount(SongSelectionBanner, {
			props: {
				selectedCount: 2,
				canWrite: true,
			},
		})

		expect(wrapper.text()).toContain('2 songs selected')
		expect(wrapper.text()).toContain('Compare / Merge')

		await wrapper.find('button[aria-label="Compare & Merge selected 2 songs"]').trigger('click')
		expect(wrapper.emitted('compareMerge')).toHaveLength(1)

		await wrapper.find('button[aria-label="Edit selected (2)"]').trigger('click')
		expect(wrapper.emitted('editSelected')).toHaveLength(1)

		await wrapper.find('button[aria-label="Delete selected (2)"]').trigger('click')
		expect(wrapper.emitted('deleteSelected')).toHaveLength(1)

		await wrapper.find('button[aria-label="Clear selection"]').trigger('click')
		expect(wrapper.emitted('clearSelection')).toHaveLength(1)
	})

	it('hides compare/merge when selectedCount is not 2', () => {
		const wrapper = mount(SongSelectionBanner, {
			props: {
				selectedCount: 3,
				canWrite: true,
			},
		})

		expect(wrapper.text()).not.toContain('Compare / Merge')
		expect(wrapper.find('button[aria-label="Edit selected (3)"]').exists()).toBe(true)
		expect(wrapper.find('button[aria-label="Delete selected (3)"]').exists()).toBe(true)
	})

	it('hides write actions when canWrite is false', () => {
		const wrapper = mount(SongSelectionBanner, {
			props: {
				selectedCount: 2,
				canWrite: false,
			},
		})

		expect(wrapper.text()).not.toContain('Compare / Merge')
		expect(wrapper.find('button[aria-label="Edit selected (2)"]').exists()).toBe(false)
		expect(wrapper.find('button[aria-label="Delete selected (2)"]').exists()).toBe(false)
		expect(wrapper.find('button[aria-label="Clear selection"]').exists()).toBe(true)
	})
})
