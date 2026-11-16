import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { useWakeLock } from '../../../src/composables/useWakeLock'

describe('useWakeLock', () => {
	it('requests wake lock on mount and releases it on unmount', async () => {
		const release = vi.fn().mockResolvedValue(undefined)
		const request = vi.fn().mockResolvedValue({ release })
		Object.defineProperty(navigator, 'wakeLock', {
			configurable: true,
			value: { request },
		})

		const Comp = defineComponent({
			setup() {
				useWakeLock()
				return {}
			},
			template: '<div />',
		})

		const wrapper = mount(Comp)
		await Promise.resolve()
		expect(request).toHaveBeenCalledWith('screen')

		wrapper.unmount()
		expect(release).toHaveBeenCalledTimes(1)
	})
})
