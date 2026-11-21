import { describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { usePdfTouchZoom } from '../../../src/composables/usePdfTouchZoom'

function makeTouch(clientX: number, clientY: number): Touch {
	return { clientX, clientY } as Touch
}

function makeTouchEvent(type: string, touches: Touch[]): TouchEvent {
	const evt = new Event(type, { cancelable: true }) as TouchEvent
	Object.defineProperty(evt, 'touches', { value: touches })
	return evt
}

describe('usePdfTouchZoom', () => {
	it('applies live CSS scale during two-finger pinch and commits zoom on release', async () => {
		const container = document.createElement('div')
		const stage = document.createElement('div')
		container.appendChild(stage)
		container.getBoundingClientRect = () => ({ left: 0, top: 0, width: 400, height: 600 } as DOMRect)

		const onCommitZoom = vi.fn().mockResolvedValue(undefined)
		let api!: ReturnType<typeof usePdfTouchZoom>

		const Comp = defineComponent({
			setup() {
				const containerRef = ref<HTMLElement | undefined>(container)
				const stageRef = ref<HTMLElement | undefined>(stage)
				api = usePdfTouchZoom({
					containerRef,
					stageRef,
					getCurrentScale: () => 1.0,
					isEnabled: () => true,
					onCommitZoom,
				})
				return {}
			},
			template: '<div />',
		})

		mount(Comp)

		container.dispatchEvent(makeTouchEvent('touchstart', [makeTouch(100, 100), makeTouch(200, 100)]))
		const moveEvt = makeTouchEvent('touchmove', [makeTouch(50, 100), makeTouch(250, 100)])
		container.dispatchEvent(moveEvt)

		expect(moveEvt.defaultPrevented).toBe(true)
		expect(stage.style.transform).toBe('scale(2)')
		expect(api.wasRecentlyPinching()).toBe(true)

		container.dispatchEvent(makeTouchEvent('touchend', [makeTouch(50, 100)]))
		await Promise.resolve()

		expect(stage.style.transform).toBe('')
		expect(onCommitZoom).toHaveBeenCalledWith(2, expect.any(Function))
	})

	it('clamps zoom scale within [minScale, maxScale] and ignores when disabled', async () => {
		const container = document.createElement('div')
		const stage = document.createElement('div')
		container.getBoundingClientRect = () => ({ left: 0, top: 0, width: 400, height: 600 } as DOMRect)

		const onCommitZoom = vi.fn()
		let enabled = false

		const Comp = defineComponent({
			setup() {
				usePdfTouchZoom({
					containerRef: ref<HTMLElement | undefined>(container),
					stageRef: ref<HTMLElement | undefined>(stage),
					getCurrentScale: () => 1.0,
					isEnabled: () => enabled,
					onCommitZoom,
					minScale: 0.5,
					maxScale: 3.0,
				})
				return {}
			},
			template: '<div />',
		})

		mount(Comp)

		container.dispatchEvent(makeTouchEvent('touchstart', [makeTouch(100, 100), makeTouch(200, 100)]))
		container.dispatchEvent(makeTouchEvent('touchmove', [makeTouch(0, 100), makeTouch(500, 100)]))
		container.dispatchEvent(makeTouchEvent('touchend', []))
		expect(onCommitZoom).not.toHaveBeenCalled()

		enabled = true
		container.dispatchEvent(makeTouchEvent('touchstart', [makeTouch(100, 100), makeTouch(200, 100)]))
		container.dispatchEvent(makeTouchEvent('touchmove', [makeTouch(0, 100), makeTouch(500, 100)]))
		container.dispatchEvent(makeTouchEvent('touchend', []))
		await Promise.resolve()
		expect(onCommitZoom).toHaveBeenCalledWith(3.0, expect.any(Function))
	})

	it('persists two-finger pan offset on release and clears it via resetPan', async () => {
		const container = document.createElement('div')
		const stage = document.createElement('div')
		container.getBoundingClientRect = () => ({ left: 0, top: 0, width: 400, height: 600 } as DOMRect)

		let api!: ReturnType<typeof usePdfTouchZoom>
		const Comp = defineComponent({
			setup() {
				api = usePdfTouchZoom({
					containerRef: ref<HTMLElement | undefined>(container),
					stageRef: ref<HTMLElement | undefined>(stage),
					getCurrentScale: () => 1.0,
					isEnabled: () => true,
					onCommitZoom: vi.fn(),
				})
				return {}
			},
			template: '<div />',
		})

		mount(Comp)

		container.dispatchEvent(makeTouchEvent('touchstart', [makeTouch(100, 100), makeTouch(200, 100)]))
		container.dispatchEvent(makeTouchEvent('touchmove', [makeTouch(140, 120), makeTouch(240, 120)]))
		expect(stage.style.transform).toBe('translate(40px, 20px)')

		container.dispatchEvent(makeTouchEvent('touchend', [makeTouch(140, 120)]))
		await Promise.resolve()
		expect(stage.style.transform).toBe('translate(40px, 20px)')

		api.resetPan()
		expect(stage.style.transform).toBe('')
	})

	it('zooms on Ctrl+wheel and pans on Ctrl+drag', async () => {
		vi.useFakeTimers()
		const container = document.createElement('div')
		const stage = document.createElement('div')
		container.getBoundingClientRect = () => ({ left: 0, top: 0, width: 400, height: 600 } as DOMRect)

		const onCommitZoom = vi.fn()
		let api!: ReturnType<typeof usePdfTouchZoom>
		const Comp = defineComponent({
			setup() {
				api = usePdfTouchZoom({
					containerRef: ref<HTMLElement | undefined>(container),
					stageRef: ref<HTMLElement | undefined>(stage),
					getCurrentScale: () => 1.0,
					isEnabled: () => true,
					isAnnotating: () => true,
					onCommitZoom,
				})
				return {}
			},
			template: '<div />',
		})

		mount(Comp)

		const wheelEvt = Object.assign(new Event('wheel', { bubbles: true, cancelable: true }), {
			deltaY: -100,
			ctrlKey: true,
			clientX: 200,
			clientY: 300,
		})
		container.dispatchEvent(wheelEvt)
		expect(wheelEvt.defaultPrevented).toBe(true)

		vi.advanceTimersByTime(150)
		await Promise.resolve()
		expect(onCommitZoom).toHaveBeenCalledTimes(1)
		expect(onCommitZoom.mock.calls[0][0]).toBeGreaterThan(1.0)

		// Ctrl+drag should pan even when isAnnotating() is true
		container.dispatchEvent(new MouseEvent('mousedown', { button: 0, ctrlKey: true, clientX: 100, clientY: 100, cancelable: true }))
		window.dispatchEvent(new MouseEvent('mousemove', { clientX: 150, clientY: 130 }))
		expect(stage.style.transform).toBe('translate(50px, 30px)')
		window.dispatchEvent(new MouseEvent('mouseup'))
		expect(stage.style.transform).toBe('translate(50px, 30px)')

		api.resetPan()
		expect(stage.style.transform).toBe('')
		vi.useRealTimers()
	})

	it('toggles sheetmusic-pan-ready cursor class on Ctrl keydown/keyup and blur', () => {
		const container = document.createElement('div')
		const stage = document.createElement('div')

		const Comp = defineComponent({
			setup() {
				usePdfTouchZoom({
					containerRef: ref<HTMLElement | undefined>(container),
					stageRef: ref<HTMLElement | undefined>(stage),
					getCurrentScale: () => 1.0,
					isEnabled: () => true,
					onCommitZoom: vi.fn(),
				})
				return {}
			},
			template: '<div />',
		})

		mount(Comp)

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Control', ctrlKey: true }))
		expect(container.classList.contains('sheetmusic-pan-ready')).toBe(true)

		window.dispatchEvent(new KeyboardEvent('keyup', { key: 'Control', ctrlKey: false }))
		expect(container.classList.contains('sheetmusic-pan-ready')).toBe(false)

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Control', ctrlKey: true }))
		expect(container.classList.contains('sheetmusic-pan-ready')).toBe(true)
		window.dispatchEvent(new Event('blur'))
		expect(container.classList.contains('sheetmusic-pan-ready')).toBe(false)
	})

	it('marks wasRecentlyDragging only when mouse moves past drag threshold, not on plain click', () => {
		const container = document.createElement('div')
		const stage = document.createElement('div')
		let api!: ReturnType<typeof usePdfTouchZoom>

		const Comp = defineComponent({
			setup() {
				api = usePdfTouchZoom({
					containerRef: ref<HTMLElement | undefined>(container),
					stageRef: ref<HTMLElement | undefined>(stage),
					getCurrentScale: () => 2.0,
					isEnabled: () => true,
					onCommitZoom: vi.fn(),
				})
				return {}
			},
			template: '<div />',
		})

		mount(Comp)

		// Plain click (no movement) should NOT mark wasRecentlyDragging
		container.dispatchEvent(new MouseEvent('mousedown', { button: 0, clientX: 100, clientY: 100 }))
		window.dispatchEvent(new MouseEvent('mouseup'))
		expect(api.wasRecentlyDragging()).toBe(false)

		// Dragging > 5px SHOULD mark wasRecentlyDragging
		container.dispatchEvent(new MouseEvent('mousedown', { button: 0, clientX: 100, clientY: 100 }))
		window.dispatchEvent(new MouseEvent('mousemove', { clientX: 130, clientY: 100 }))
		expect(api.wasRecentlyDragging()).toBe(true)
		window.dispatchEvent(new MouseEvent('mouseup'))
		expect(api.wasRecentlyDragging()).toBe(true)
	})
})
