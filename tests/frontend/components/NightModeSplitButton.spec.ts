import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import NightModeSplitButton from '../../../src/components/song-viewer/NightModeSplitButton.vue'

describe('NightModeSplitButton', () => {
	afterEach(() => {
		vi.useRealTimers()
	})

	it('toggles night mode on click and opens appearance popover via side arrow', async () => {
		const wrapper = mount(NightModeSplitButton, {
			props: {
				scoreColorMode: 'normal',
				scoreBrightness: 100,
				scoreSepia: 0,
				toggleTestId: 'test-night',
			},
		})

		await wrapper.find('[data-test="test-night"]').trigger('click')
		expect(wrapper.emitted('toggle-night-mode')).toHaveLength(1)
		expect(wrapper.find('[data-test="score-appearance-popover"]').exists()).toBe(false)

		await wrapper.find('[data-test="test-night-menu"]').trigger('click')
		expect(wrapper.find('[data-test="score-appearance-popover"]').exists()).toBe(true)
		expect(wrapper.emitted('update:open')?.[0]).toEqual([true])

		await wrapper.find('[data-test="popover-score-mode-night-sepia"]').trigger('click')
		expect(wrapper.emitted('update:scoreColorMode')?.[0]).toEqual(['night-sepia'])

		await wrapper.find('[data-test="popover-score-brightness"]').setValue('75')
		expect(wrapper.emitted('update:scoreBrightness')?.[0]).toEqual([75])

		await wrapper.find('[data-test="popover-score-sepia"]').setValue('50')
		expect(wrapper.emitted('update:scoreSepia')?.[0]).toEqual([50])
	})

	it('opens appearance popover on long press without firing toggle click', async () => {
		vi.useFakeTimers()
		const wrapper = mount(NightModeSplitButton, {
			props: {
				scoreColorMode: 'normal',
				toggleTestId: 'test-night',
			},
		})

		const btn = wrapper.find('[data-test="test-night"]')
		await btn.trigger('pointerdown')
		vi.advanceTimersByTime(500)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-test="score-appearance-popover"]').exists()).toBe(true)

		// Subsequent click event from releasing the long press must not toggle night mode
		await btn.trigger('pointerup')
		await btn.trigger('click')
		expect(wrapper.emitted('toggle-night-mode')).toBeUndefined()
	})
})
