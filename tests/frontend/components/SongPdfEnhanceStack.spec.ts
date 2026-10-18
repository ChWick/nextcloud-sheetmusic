import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfEnhanceStack from '../../../src/components/song-pdf/SongPdfEnhanceStack.vue'
import type { ScanOperation } from '../../../src/components/song-pdf/scan-types'

describe('SongPdfEnhanceStack.vue', () => {
	const sampleOps: ScanOperation[] = [
		{ id: '1', type: 'gaussian', size: 3, enabled: true },
		{ id: '2', type: 'grayLevelMapping', lowerCutoff: 40, upperCutoff: 220, colorMode: 'color', enabled: true },
	]

	it('renders stack title with operation count and preset chips', async () => {
		const wrapper = mount(SongPdfEnhanceStack, {
			props: { operations: sampleOps },
		})

		expect(wrapper.text()).toContain('Operation Stack (2)')

		await wrapper.find('[data-test="preset-standard"]').trigger('click')
		expect(wrapper.emitted('applyPreset')?.[0]).toEqual(['standard'])

		await wrapper.find('[data-test="preset-colorClean"]').trigger('click')
		expect(wrapper.emitted('applyPreset')?.[1]).toEqual(['colorClean'])

		await wrapper.find('[data-test="preset-contrast"]').trigger('click')
		expect(wrapper.emitted('applyPreset')?.[2]).toEqual(['contrast'])

		await wrapper.find('[data-test="preset-binarize"]').trigger('click')
		expect(wrapper.emitted('applyPreset')?.[3]).toEqual(['binarize'])
	})

	it('shows empty stack message when no operations are present', () => {
		const wrapper = mount(SongPdfEnhanceStack, {
			props: { operations: [] },
		})

		expect(wrapper.find('[data-test="empty-stack"]').exists()).toBe(true)
	})

	it('emits addOperation when add buttons are clicked', async () => {
		const wrapper = mount(SongPdfEnhanceStack, {
			props: { operations: [] },
		})

		await wrapper.find('[data-test="add-gaussian"]').trigger('click')
		expect(wrapper.emitted('addOperation')?.[0]).toEqual(['gaussian'])

		await wrapper.find('[data-test="add-curves"]').trigger('click')
		expect(wrapper.emitted('addOperation')?.[1]).toEqual(['grayLevelMapping'])

		await wrapper.find('[data-test="add-threshold"]').trigger('click')
		expect(wrapper.emitted('addOperation')?.[2]).toEqual(['adaptiveThreshold'])
	})
})
