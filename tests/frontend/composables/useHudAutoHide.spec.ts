import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useHudAutoHide } from '../../../src/composables/useHudAutoHide'

describe('useHudAutoHide', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	function mountComposable(isBlocked = () => false) {
		let api!: ReturnType<typeof useHudAutoHide>
		const Comp = defineComponent({
			setup() {
				api = useHudAutoHide(3000, isBlocked)
				return {}
			},
			template: '<div />',
		})
		const wrapper = mount(Comp)
		return { api, wrapper }
	}

	it('auto-hides after timeout and respects pause/resume', () => {
		const { api, wrapper } = mountComposable()
		api.scheduleHudAutoHide()
		expect(api.isHudVisible.value).toBe(true)

		api.pauseHudAutoHide()
		vi.advanceTimersByTime(5000)
		expect(api.isHudVisible.value).toBe(true)

		api.resumeHudAutoHide()
		vi.advanceTimersByTime(3100)
		expect(api.isHudVisible.value).toBe(false)

		api.toggleHud()
		expect(api.isHudVisible.value).toBe(true)

		wrapper.unmount()
	})

	it('does not auto-hide when blocked', () => {
		const { api, wrapper } = mountComposable(() => true)
		api.scheduleHudAutoHide()
		vi.advanceTimersByTime(5000)
		expect(api.isHudVisible.value).toBe(true)
		wrapper.unmount()
	})
})
