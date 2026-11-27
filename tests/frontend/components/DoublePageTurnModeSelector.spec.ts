import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DoublePageTurnModeSelector from '../../../src/components/file-preview/DoublePageTurnModeSelector.vue'

describe('DoublePageTurnModeSelector', () => {
	it('shows only the active mode button and opens a dropdown with all three 1/2 -> next spread options', async () => {
		const wrapper = mount(DoublePageTurnModeSelector, {
			props: {
				modelValue: 'half',
			},
		})

		const trigger = wrapper.find('[data-test="double-page-turn-mode-trigger"]')
		expect(trigger.exists()).toBe(true)
		expect(trigger.attributes('data-active-turn-mode')).toBe('half')
		expect(trigger.findAll('text').map((n) => n.text())).toEqual(['1', '2', '3', '2'])
		expect(trigger.find('svg path').exists()).toBe(true)
		expect(wrapper.find('[data-test="double-page-turn-mode-menu"]').exists()).toBe(false)

		await trigger.trigger('click')
		expect(wrapper.find('[data-test="double-page-turn-mode-menu"]').exists()).toBe(true)

		const halfBtn = wrapper.find('button[data-turn-mode="half"]')
		const stepBtn = wrapper.find('button[data-turn-mode="step"]')
		const spreadBtn = wrapper.find('button[data-turn-mode="spread"]')

		expect(halfBtn.attributes('aria-checked')).toBe('true')
		expect(stepBtn.attributes('aria-checked')).toBe('false')
		expect(spreadBtn.attributes('aria-checked')).toBe('false')

		expect(halfBtn.findAll('text').map((n) => n.text())).toEqual(['1', '2', '3', '2'])
		expect(stepBtn.findAll('text').map((n) => n.text())).toEqual(['1', '2', '2', '3'])
		expect(spreadBtn.findAll('text').map((n) => n.text())).toEqual(['1', '2', '3', '4'])

		await spreadBtn.trigger('click')
		expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['spread'])
		expect(wrapper.find('[data-test="double-page-turn-mode-menu"]').exists()).toBe(false)
	})
})
