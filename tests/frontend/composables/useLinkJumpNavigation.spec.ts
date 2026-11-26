import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useLinkJumpNavigation } from '../../../src/composables/useLinkJumpNavigation'

function mountLinkJumpNav(goToPage: (page: number) => void) {
	let api!: ReturnType<typeof useLinkJumpNavigation>
	const Comp = defineComponent({
		setup() {
			api = useLinkJumpNavigation({
				goToPage,
				beaconDurationMs: 1000,
				jumpBackDurationMs: 4000,
			})
			return () => null
		},
	})
	const wrapper = mount(Comp)
	return { wrapper, api }
}

describe('useLinkJumpNavigation', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('navigates to target page, flashes arrival beacon, and supports jumping back', () => {
		const goToPage = vi.fn()
		const { wrapper, api } = mountLinkJumpNav(goToPage)

		api.jumpFromLink({
			sourcePage: 0, // page 1
			targetPage: 2, // page 3
			targetX: 0.5,
			targetY: 0.54,
			label: 'To Coda',
		})

		expect(goToPage).toHaveBeenCalledWith(3)
		expect(api.activeBeacon.value).toMatchObject({
			pageNumber: 3,
			x: 0.5,
			y: 0.54,
		})
		expect(api.jumpBackEntry.value).toEqual({
			returnPage: 1,
			fromLabel: 'To Coda',
		})

		// Beacon expires after duration
		vi.advanceTimersByTime(1000)
		expect(api.activeBeacon.value).toBeNull()
		expect(api.jumpBackEntry.value).not.toBeNull()

		// Jumping back returns to page 1 and clears chip (no arbitrary center beacon)
		api.jumpBack()
		expect(goToPage).toHaveBeenLastCalledWith(1)
		expect(api.jumpBackEntry.value).toBeNull()
		expect(api.activeBeacon.value).toBeNull()

		wrapper.unmount()
	})

	it('omits arrival beacon when link only specifies a page without exact targetX/targetY', () => {
		const goToPage = vi.fn()
		const { wrapper, api } = mountLinkJumpNav(goToPage)

		api.jumpFromLink({
			sourcePage: 0,
			targetPage: 2,
		})

		expect(goToPage).toHaveBeenCalledWith(3)
		expect(api.activeBeacon.value).toBeNull()
		expect(api.jumpBackEntry.value).toEqual({ returnPage: 1, fromLabel: undefined })

		wrapper.unmount()
	})

	it('does not show jump-back chip when jumping to the same page', () => {
		const goToPage = vi.fn()
		const { wrapper, api } = mountLinkJumpNav(goToPage)

		api.jumpFromLink({
			sourcePage: 1,
			targetPage: 1,
			targetX: 0.5,
			targetY: 0.14,
		})

		expect(goToPage).toHaveBeenCalledWith(2)
		expect(api.activeBeacon.value?.pageNumber).toBe(2)
		expect(api.jumpBackEntry.value).toBeNull()

		wrapper.unmount()
	})
})
