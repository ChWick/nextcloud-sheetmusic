import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { usePerformanceStageInput } from '../../../src/composables/usePerformanceStageInput'

describe('usePerformanceStageInput', () => {
	function setupHarness(
		isScrollMode = () => false,
		extra: Partial<Parameters<typeof usePerformanceStageInput>[0]> = {},
	) {
		const onNextPage = vi.fn()
		const onPrevPage = vi.fn()
		const onToggleHud = vi.fn()
		const onEscape = vi.fn()
		let api!: ReturnType<typeof usePerformanceStageInput>

		const Comp = defineComponent({
			setup() {
				api = usePerformanceStageInput({
					isScrollMode,
					onNextPage,
					onPrevPage,
					onToggleHud,
					onEscape,
					...extra,
				})
				return { ...api }
			},
			template: '<div />',
		})

		const wrapper = mount(Comp)
		return { wrapper, api, onNextPage, onPrevPage, onToggleHud, onEscape }
	}

	it('handles left, center, and right tap zones in paged mode', () => {
		const { wrapper, api, onNextPage, onPrevPage, onToggleHud } = setupHarness(() => false)
		const mockEl = {
			getBoundingClientRect: () => ({ left: 0, width: 400 }),
		} as unknown as HTMLElement

		api.onStageClick({ currentTarget: mockEl, clientX: 50 } as unknown as MouseEvent)
		expect(onPrevPage).toHaveBeenCalledTimes(1)

		api.onStageClick({ currentTarget: mockEl, clientX: 200 } as unknown as MouseEvent)
		expect(onToggleHud).toHaveBeenCalledTimes(1)

		api.onStageClick({ currentTarget: mockEl, clientX: 350 } as unknown as MouseEvent)
		expect(onNextPage).toHaveBeenCalledTimes(1)

		wrapper.unmount()
	})

	it('toggles HUD anywhere on click in scroll mode', () => {
		const { wrapper, api, onNextPage, onPrevPage, onToggleHud } = setupHarness(() => true)
		const mockEl = {
			getBoundingClientRect: () => ({ left: 0, width: 400 }),
		} as unknown as HTMLElement

		api.onStageClick({ currentTarget: mockEl, clientX: 50 } as unknown as MouseEvent)
		expect(onPrevPage).not.toHaveBeenCalled()
		expect(onNextPage).not.toHaveBeenCalled()
		expect(onToggleHud).toHaveBeenCalledTimes(1)

		wrapper.unmount()
	})

	it('handles keyboard and pedal navigation', () => {
		const { wrapper, onNextPage, onPrevPage, onEscape } = setupHarness(() => false)

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'PageDown' }))
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'PageUp' }))
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))

		expect(onNextPage).toHaveBeenCalledTimes(1)
		expect(onPrevPage).toHaveBeenCalledTimes(1)
		expect(onEscape).toHaveBeenCalledTimes(1)

		wrapper.unmount()
	})

	it('ignores stage clicks on annotation jump links', () => {
		const { wrapper, api, onNextPage, onPrevPage, onToggleHud } = setupHarness(() => false)
		const mockEl = {
			getBoundingClientRect: () => ({ left: 0, width: 400 }),
		} as unknown as HTMLElement
		const linkGroup = document.createElement('div')
		linkGroup.className = 'sheetmusic-annotation-link-group'
		const child = document.createElement('span')
		linkGroup.appendChild(child)

		api.onStageClick({ currentTarget: mockEl, target: child, clientX: 350 } as unknown as MouseEvent)
		expect(onNextPage).not.toHaveBeenCalled()
		expect(onPrevPage).not.toHaveBeenCalled()
		expect(onToggleHud).not.toHaveBeenCalled()

		wrapper.unmount()
	})

	it('keeps container left/center/right click zones active when zoomed in (canPanHorizontally=true) while suppressing horizontal swipes', () => {
		const { wrapper, api, onNextPage, onPrevPage, onToggleHud } = setupHarness(() => false, {
			isPanningOrPinching: () => false,
			canPanHorizontally: () => true,
		})
		const mockEl = {
			getBoundingClientRect: () => ({ left: 100, width: 400 }),
		} as unknown as HTMLElement

		// Clicks relative to the container still work when zoomed in
		api.onStageClick({ currentTarget: mockEl, clientX: 150 } as unknown as MouseEvent)
		expect(onPrevPage).toHaveBeenCalledTimes(1)

		api.onStageClick({ currentTarget: mockEl, clientX: 300 } as unknown as MouseEvent)
		expect(onToggleHud).toHaveBeenCalledTimes(1)

		api.onStageClick({ currentTarget: mockEl, clientX: 450 } as unknown as MouseEvent)
		expect(onNextPage).toHaveBeenCalledTimes(1)

		// Horizontal touch swipe is suppressed when content pans horizontally
		api.onStageTouchStart({ touches: [{ clientX: 300, clientY: 200 }] } as unknown as TouchEvent)
		api.onStageTouchEnd({ changedTouches: [{ clientX: 150, clientY: 200 }] } as unknown as TouchEvent)
		expect(onNextPage).toHaveBeenCalledTimes(1)

		wrapper.unmount()
	})

	it('suppresses clicks while actively panning or pinching', () => {
		const { wrapper, api, onNextPage, onPrevPage, onToggleHud } = setupHarness(() => false, {
			isPanningOrPinching: () => true,
		})
		const mockEl = {
			getBoundingClientRect: () => ({ left: 0, width: 400 }),
		} as unknown as HTMLElement

		api.onStageClick({ currentTarget: mockEl, clientX: 50 } as unknown as MouseEvent)
		api.onStageClick({ currentTarget: mockEl, clientX: 200 } as unknown as MouseEvent)
		api.onStageClick({ currentTarget: mockEl, clientX: 350 } as unknown as MouseEvent)

		expect(onPrevPage).not.toHaveBeenCalled()
		expect(onToggleHud).not.toHaveBeenCalled()
		expect(onNextPage).not.toHaveBeenCalled()

		wrapper.unmount()
	})
})
