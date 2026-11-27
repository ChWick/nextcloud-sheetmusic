import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import LogRangeSlider from '../../../src/components/LogRangeSlider.vue'

describe('LogRangeSlider', () => {
	it('maps values logarithmically (log2) and clamps to [minValue..maxValue]', async () => {
		const wrapper = mount(LogRangeSlider, {
			props: {
				modelValue: 20,
				minValue: 5,
				maxValue: 200,
				label: 'Speed',
				testId: 'speed-slider',
			},
		})

		const input = wrapper.find('[data-test="speed-slider"]')
		expect(Number(input.attributes('min'))).toBeCloseTo(Math.log2(5), 5)
		expect(Number(input.attributes('max'))).toBeCloseTo(Math.log2(200), 5)
		expect(Number((input.element as HTMLInputElement).value)).toBeCloseTo(Math.log2(20), 4)

		await input.setValue(String(Math.log2(50)))
		expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([50])

		// Out-of-range log position clamps cleanly to min/max
		await input.setValue(String(Math.log2(1000)))
		expect(wrapper.emitted('update:modelValue')?.[1]).toEqual([200])
	})

	it('supports half-step rounding below 4 when allowHalfStep is enabled', async () => {
		const wrapper = mount(LogRangeSlider, {
			props: {
				modelValue: 2,
				minValue: 1,
				maxValue: 32,
				allowHalfStep: true,
				label: 'Stroke width',
				testId: 'stroke-slider',
			},
		})

		const input = wrapper.find('[data-test="stroke-slider"]')
		await input.setValue(String(Math.log2(2.4)))
		expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([2.5])

		await input.setValue(String(Math.log2(10.2)))
		expect(wrapper.emitted('update:modelValue')?.[1]).toEqual([10])
	})
})
