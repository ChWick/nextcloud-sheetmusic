import { describe, expect, it } from 'vitest'
import { computeAutoScrollPxPerSec, useAutoScroll } from '../../../src/composables/useAutoScroll'

function createScrollableDiv(scrollHeight: number, clientHeight: number): HTMLDivElement {
	const el = document.createElement('div')
	Object.defineProperty(el, 'scrollHeight', { value: scrollHeight, configurable: true })
	Object.defineProperty(el, 'clientHeight', { value: clientHeight, configurable: true })
	el.scrollTop = 0
	return el
}

function waitMs(ms: number): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, ms))
}

describe('useAutoScroll (real timers & real DOM scrollTop)', () => {
	it('computes px/s accurately for custom speed and song-duration calibration', () => {
		expect(computeAutoScrollPxPerSec('speed', 45, 2000, 120)).toBe(45)
		expect(computeAutoScrollPxPerSec('speed', 1, 2000, 120)).toBe(5) // clamped to min
		expect(computeAutoScrollPxPerSec('speed', 500, 2000, 120)).toBe(200) // clamped to max

		// 1200px scrollable over 60s => 20 px/s
		expect(computeAutoScrollPxPerSec('duration', 35, 1200, 60)).toBeCloseTo(20, 5)
		// Falls back to custom speed when duration is missing
		expect(computeAutoScrollPxPerSec('duration', 35, 1200, null)).toBe(35)
	})

	it('scrolls a real container smoothly via requestAnimationFrame, pauses, resumes, and stops at bottom', async () => {
		const el = createScrollableDiv(1000, 800) // maxScroll = 200
		let beforeStartCalls = 0
		const scroller = useAutoScroll({
			getContainer: () => el,
			onBeforeStart: () => {
				beforeStartCalls += 1
			},
			initialSpeed: 150,
		})

		scroller.start()
		expect(beforeStartCalls).toBe(1)
		expect(scroller.isRunning.value).toBe(true)

		await waitMs(120)
		const midScroll = el.scrollTop
		expect(midScroll).toBeGreaterThan(0)

		// Pause freezes scrollTop
		scroller.pause()
		expect(scroller.isRunning.value).toBe(false)
		expect(scroller.isPaused.value).toBe(true)
		const pausedTop = el.scrollTop
		await waitMs(80)
		expect(el.scrollTop).toBe(pausedTop)

		// Resume continues scrolling until reaching bottom and auto-stops
		scroller.resume()
		expect(scroller.isRunning.value).toBe(true)
		const elShort = createScrollableDiv(820, 800) // maxScroll = 20
		const fastScroller = useAutoScroll({
			getContainer: () => elShort,
			initialSpeed: 200,
		})
		fastScroller.start()
		await waitMs(250)
		expect(elShort.scrollTop).toBe(20)
		expect(fastScroller.isRunning.value).toBe(false)

		scroller.resetToTop()
		expect(el.scrollTop).toBe(0)
		scroller.stop()
	})

	it('accumulates sub-pixel deltas so slow speeds (5 px/s) still advance scrollTop', async () => {
		const el = createScrollableDiv(2000, 500)
		const scroller = useAutoScroll({
			getContainer: () => el,
			initialSpeed: 5, // < 0.1px per 16ms frame
		})

		scroller.start()
		// Over ~350ms at 5 px/s, should accumulate at least 1px
		await waitMs(350)
		expect(el.scrollTop).toBeGreaterThanOrEqual(1)
		scroller.stop()
	})

	it('supports start delay countdown and clean cancellation before scrolling starts', async () => {
		const el = createScrollableDiv(2000, 500)
		const saved: Array<{ autoScrollSpeed: number; autoScrollDelay: number }> = []
		const scroller = useAutoScroll({
			getContainer: () => el,
			initialSpeed: 60,
			initialDelay: 2,
			onSettingsChange: (s) => saved.push(s),
		})

		scroller.start()
		expect(scroller.countdownRemaining.value).toBe(2)
		expect(scroller.isRunning.value).toBe(false)
		expect(scroller.isActive.value).toBe(true)
		expect(el.scrollTop).toBe(0)

		// Pause cancels the countdown without starting scroll
		scroller.toggle()
		expect(scroller.countdownRemaining.value).toBe(0)
		expect(scroller.isRunning.value).toBe(false)
		expect(scroller.isPaused.value).toBe(true)

		scroller.adjustSpeed(10)
		scroller.setStartDelay(5)
		expect(saved).toEqual([
			{ autoScrollSpeed: 70, autoScrollDelay: 2 },
			{ autoScrollSpeed: 70, autoScrollDelay: 5 },
		])
		scroller.stop()
	})
})
