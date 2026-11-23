/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SongAnnotationLogSelect from '../../../src/components/song-viewer/SongAnnotationLogSelect.vue'

describe('SongAnnotationLogSelect.vue', () => {
	it('renders trigger label and value, opens popover on click, and emits selected preset', async () => {
		const wrapper = mount(SongAnnotationLogSelect, {
			props: {
				modelValue: 22,
				presets: [16, 22, 30, 42],
				minValue: 8,
				maxValue: 128,
				inputLabel: 'Size',
				sliderLabel: 'Custom size',
				selectTestId: 'size-select',
				sliderTestId: 'size-slider',
			},
		})

		const trigger = wrapper.find('[data-test="size-select"]')
		expect(trigger.exists()).toBe(true)
		expect(trigger.attributes('aria-label')).toBe('Size: 22')
		expect(trigger.attributes('aria-expanded')).toBe('false')

		await trigger.trigger('click')
		expect(trigger.attributes('aria-expanded')).toBe('true')

		const option30 = wrapper.find('[data-test="size-select-option-30"]')
		expect(option30.exists()).toBe(true)
		await option30.trigger('click')

		expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([30])
		expect(trigger.attributes('aria-expanded')).toBe('false')
	})

	it('displays custom non-preset values in the trigger button', () => {
		const wrapper = mount(SongAnnotationLogSelect, {
			props: {
				modelValue: 19,
				presets: [16, 22, 30, 42],
				minValue: 8,
				maxValue: 128,
				inputLabel: 'Size',
				sliderLabel: 'Custom size',
				selectTestId: 'size-select',
			},
		})

		expect(wrapper.find('[data-test="size-select"]').attributes('aria-label')).toBe('Size: 19')
		expect(wrapper.find('[data-test="size-select"]').text()).toContain('19')
	})

	it('maps logarithmic slider input to integer values when allowHalfStep is false', async () => {
		const wrapper = mount(SongAnnotationLogSelect, {
			props: {
				modelValue: 16,
				presets: [16, 22, 30, 42],
				minValue: 8,
				maxValue: 128,
				allowHalfStep: false,
				inputLabel: 'Size',
				sliderLabel: 'Custom size',
				selectTestId: 'size-select',
				sliderTestId: 'size-slider',
			},
		})

		await wrapper.find('[data-test="size-select"]').trigger('click')
		const slider = wrapper.find('[data-test="size-slider"]')
		expect(Number(slider.attributes('min'))).toBeCloseTo(3, 5) // log2(8)
		expect(Number(slider.attributes('max'))).toBeCloseTo(7, 5) // log2(128)
		expect(Number((slider.element as HTMLInputElement).value)).toBeCloseTo(4, 5) // log2(16)

		await slider.setValue('5') // 2^5 = 32
		expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([32])
	})

	it('supports 0.5 rounding below 4 when allowHalfStep is true and clamps to [minValue, maxValue]', async () => {
		const wrapper = mount(SongAnnotationLogSelect, {
			props: {
				modelValue: 2.5,
				presets: [1.5, 2.5, 4, 6.5],
				minValue: 1,
				maxValue: 64,
				allowHalfStep: true,
				inputLabel: 'Width',
				sliderLabel: 'Custom width',
				selectTestId: 'width-select',
				sliderTestId: 'width-slider',
			},
		})

		await wrapper.find('[data-test="width-select"]').trigger('click')
		const slider = wrapper.find('[data-test="width-slider"]')

		// 2^0.6 ≈ 1.515 -> rounds to 1.5
		await slider.setValue('0.6')
		expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([1.5])

		// Out of bounds high -> clamps to 64
		await slider.setValue('10')
		expect(wrapper.emitted('update:modelValue')?.[1]).toEqual([64])
	})
})
