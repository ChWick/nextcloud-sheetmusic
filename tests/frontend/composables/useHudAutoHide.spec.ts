import { defineComponent, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { useHudAutoHide } from '../../../src/composables/useHudAutoHide'

function waitMs(ms: number): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, ms))
}

describe('useHudAutoHide (real timers, no mocks)', () => {
	function mountComposable(isBlocked = () => false, timeoutMs = 45) {
		let api!: ReturnType<typeof useHudAutoHide>
		const Comp = defineComponent({
			setup() {
				api = useHudAutoHide(timeoutMs, isBlocked)
				return {}
			},
			template: '<div />',
		})
		const wrapper = mount(Comp)
		return { api, wrapper }
	}

	it('auto-hides after timeout and respects pause/resume', async () => {
		const { api, wrapper } = mountComposable()
		api.scheduleHudAutoHide()
		expect(api.isHudVisible.value).toBe(true)

		api.pauseHudAutoHide()
		await waitMs(70)
		expect(api.isHudVisible.value).toBe(true)

		api.resumeHudAutoHide()
		await waitMs(70)
		expect(api.isHudVisible.value).toBe(false)

		api.toggleHud()
		expect(api.isHudVisible.value).toBe(true)

		wrapper.unmount()
	})

	it('does not auto-hide while blocked and reschedules once unblocked', async () => {
		const isPopoverOpen = ref(true)
		const { api, wrapper } = mountComposable(() => isPopoverOpen.value)
		api.scheduleHudAutoHide()
		await waitMs(70)
		expect(api.isHudVisible.value).toBe(true)

		// Closing the popover automatically reschedules HUD auto-hide
		isPopoverOpen.value = false
		await wrapper.vm.$nextTick()
		await waitMs(70)
		expect(api.isHudVisible.value).toBe(false)

		wrapper.unmount()
	})
})
