import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DoublePageTurnModeSelector from '../../../src/components/file-preview/DoublePageTurnModeSelector.vue'

describe('DoublePageTurnModeSelector', () => {
	it('renders all three numbered page-pair buttons and emits update:modelValue', async () => {
		const wrapper = mount(DoublePageTurnModeSelector, {
			props: {
				modelValue: 'half',
			},
		})

		const halfBtn = wrapper.find('button[data-turn-mode="half"]')
		const stepBtn = wrapper.find('button[data-turn-mode="step"]')
		const spreadBtn = wrapper.find('button[data-turn-mode="spread"]')

		expect(halfBtn.attributes('aria-pressed')).toBe('true')
		expect(stepBtn.attributes('aria-pressed')).toBe('false')
		expect(spreadBtn.attributes('aria-pressed')).toBe('false')

		await spreadBtn.trigger('click')
		expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['spread'])
	})
})
