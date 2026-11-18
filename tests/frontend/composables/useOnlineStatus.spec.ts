import { describe, expect, it, beforeEach } from 'vitest'
import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import {
	consumeOfflineReturnState,
	isEffectivelyOffline,
	setOfflineReturnState,
	setSimulatedOffline,
	useOnlineStatus,
} from '../../../src/composables/useOnlineStatus'

describe('useOnlineStatus', () => {
	beforeEach(() => {
		setSimulatedOffline(false)
		setOfflineReturnState(null)
	})

	it('tracks browser online state and simulated offline toggle', () => {
		const TestComp = defineComponent({
			setup() {
				return useOnlineStatus()
			},
			template: '<div />',
		})

		const wrapper = mount(TestComp)
		expect(wrapper.vm.isOnline).toBe(true)
		expect(wrapper.vm.isSimulatedOffline).toBe(false)
		expect(isEffectivelyOffline()).toBe(false)

		setSimulatedOffline(true)
		expect(wrapper.vm.isOnline).toBe(false)
		expect(wrapper.vm.isSimulatedOffline).toBe(true)
		expect(isEffectivelyOffline()).toBe(true)

		window.dispatchEvent(new Event('offline'))
		expect(wrapper.vm.isBrowserOnline).toBe(false)
		expect(wrapper.vm.isOnline).toBe(false)

		window.dispatchEvent(new Event('online'))
		expect(wrapper.vm.isBrowserOnline).toBe(true)
		// Still simulated offline until explicitly cleared
		expect(wrapper.vm.isOnline).toBe(false)

		setSimulatedOffline(false)
		expect(wrapper.vm.isOnline).toBe(true)
		expect(isEffectivelyOffline()).toBe(false)

		wrapper.unmount()
	})

	it('stores and consumes offline return state once', () => {
		expect(consumeOfflineReturnState()).toBeNull()

		setOfflineReturnState({
			libraryId: 1,
			setlistId: 7,
			isPlaying: true,
			itemIndex: 2,
		})

		expect(consumeOfflineReturnState()).toEqual({
			libraryId: 1,
			setlistId: 7,
			isPlaying: true,
			itemIndex: 2,
		})
		expect(consumeOfflineReturnState()).toBeNull()
	})
})
