import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SplitPopoverButton from '../../../src/components/SplitPopoverButton.vue'

function waitMs(ms: number): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, ms))
}

describe('SplitPopoverButton (real timers, no mocks)', () => {
	it('emits click on main button and opens/closes popover via side arrow', async () => {
		const wrapper = mount(SplitPopoverButton, {
			props: {
				popoverTitle: 'Options',
				popoverTestId: 'test-popover',
				mainLabel: 'Run',
				mainTestId: 'test-main',
				menuLabel: 'More options',
				menuTestId: 'test-menu',
			},
			slots: {
				default: '<div data-test="popover-body">Hello</div>',
			},
		})

		await wrapper.find('[data-test="test-main"]').trigger('click')
		expect(wrapper.emitted('click')).toHaveLength(1)
		expect(wrapper.find('[data-test="test-popover"]').exists()).toBe(false)

		await wrapper.find('[data-test="test-menu"]').trigger('click')
		expect(wrapper.find('[data-test="test-popover"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="popover-body"]').text()).toBe('Hello')
		expect(wrapper.emitted('update:open')?.[0]).toEqual([true])

		// Escape inside the popover closes it
		await wrapper.find('[data-test="test-popover"]').trigger('keydown.esc')
		expect(wrapper.find('[data-test="test-popover"]').exists()).toBe(false)
		expect(wrapper.emitted('update:open')?.[1]).toEqual([false])
	})

	it('opens popover on real long-press without firing click', async () => {
		const wrapper = mount(SplitPopoverButton, {
			props: {
				popoverTitle: 'Options',
				popoverTestId: 'test-popover',
				mainLabel: 'Run',
				mainTestId: 'test-main',
				menuLabel: 'More options',
				menuTestId: 'test-menu',
				longPressMs: 40,
			},
		})

		const btn = wrapper.find('[data-test="test-main"]')
		await btn.trigger('pointerdown')
		await waitMs(70)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-test="test-popover"]').exists()).toBe(true)

		await btn.trigger('pointerup')
		await btn.trigger('click')
		expect(wrapper.emitted('click')).toBeUndefined()
	})
})
