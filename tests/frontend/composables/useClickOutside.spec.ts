import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { useClickOutside } from '../../../src/composables/useClickOutside'

describe('useClickOutside', () => {
	it('closes only when clicking outside targetRef while open, and cleans up on unmount', () => {
		const isOpen = ref(true)
		const onClose = vi.fn(() => {
			isOpen.value = false
		})

		const Comp = defineComponent({
			setup() {
				const targetRef = ref<HTMLElement | null>(null)
				useClickOutside(targetRef, isOpen, onClose)
				return { targetRef }
			},
			template: '<div ref="targetRef"><span class="inside">Inside</span></div>',
		})

		const wrapper = mount(Comp, { attachTo: document.body })
		const outside = document.createElement('div')
		document.body.appendChild(outside)

		// Click inside should not close
		wrapper.find('.inside').element.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		expect(onClose).not.toHaveBeenCalled()
		expect(isOpen.value).toBe(true)

		// Click outside while open should close
		outside.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		expect(onClose).toHaveBeenCalledTimes(1)
		expect(isOpen.value).toBe(false)

		// Click outside while already closed should not fire again
		outside.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		expect(onClose).toHaveBeenCalledTimes(1)

		// Unmount removes listener
		isOpen.value = true
		wrapper.unmount()
		outside.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		expect(onClose).toHaveBeenCalledTimes(1)

		outside.remove()
	})
})
