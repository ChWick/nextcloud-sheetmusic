import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfRotateControls from '../../../src/components/song-pdf/SongPdfRotateControls.vue'

describe('SongPdfRotateControls.vue', () => {
	it('renders angle number input and emits update:angle', async () => {
		const wrapper = mount(SongPdfRotateControls, {
			props: {
				angle: 2.5,
				cropToOriginalSize: false,
			},
		})

		const numberInput = wrapper.find('[data-test="angle-number-input"]')
		expect(numberInput.exists()).toBe(true)
		expect((numberInput.element as HTMLInputElement).value).toBe('2.5')

		await numberInput.setValue('4.2')
		await numberInput.trigger('input')
		expect(wrapper.emitted('update:angle')?.[0]).toEqual([4.2])
	})

	it('toggles slider range between ±45° and ±180°', async () => {
		const wrapper = mount(SongPdfRotateControls, {
			props: {
				angle: 0,
				cropToOriginalSize: false,
			},
		})

		const slider = wrapper.find('[data-test="angle-slider"]')
		expect(slider.attributes('min')).toBe('-45')
		expect(slider.attributes('max')).toBe('45')

		const expandBtn = wrapper.find('[data-test="expand-range-btn"]')
		await expandBtn.trigger('click')
		expect(slider.attributes('min')).toBe('-180')
		expect(slider.attributes('max')).toBe('180')

		await expandBtn.trigger('click')
		expect(slider.attributes('min')).toBe('-45')
		expect(slider.attributes('max')).toBe('45')
	})

	it('emits adjustAngle on fine-tuning and 90° turn buttons', async () => {
		const wrapper = mount(SongPdfRotateControls, {
			props: {
				angle: 0,
				cropToOriginalSize: false,
			},
		})

		await wrapper.find('[data-test="fine-btn-p01"]').trigger('click')
		expect(wrapper.emitted('adjustAngle')?.[0]).toEqual([0.1])

		await wrapper.find('[data-test="fine-btn-m1"]').trigger('click')
		expect(wrapper.emitted('adjustAngle')?.[1]).toEqual([-1])

		await wrapper.find('[data-test="turn-p90"]').trigger('click')
		expect(wrapper.emitted('adjustAngle')?.[2]).toEqual([90])

		await wrapper.find('[data-test="turn-m90"]').trigger('click')
		expect(wrapper.emitted('adjustAngle')?.[3]).toEqual([-90])
	})

	it('emits resetAngle when 0° button is clicked', async () => {
		const wrapper = mount(SongPdfRotateControls, {
			props: {
				angle: 15,
				cropToOriginalSize: false,
			},
		})

		const resetBtn = wrapper.find('[data-test="reset-angle-btn"]')
		await resetBtn.trigger('click')
		expect(wrapper.emitted('resetAngle')).toHaveLength(1)
	})

	it('emits update:cropToOriginalSize when checkbox is toggled', async () => {
		const wrapper = mount(SongPdfRotateControls, {
			props: {
				angle: 0,
				cropToOriginalSize: false,
			},
		})

		const checkbox = wrapper.find('[data-test="crop-to-original-checkbox"]')
		await checkbox.setValue(true)
		expect(wrapper.emitted('update:cropToOriginalSize')?.[0]).toEqual([true])
	})
})
