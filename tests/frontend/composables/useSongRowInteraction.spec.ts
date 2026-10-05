import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSongRowInteraction } from '../../../src/composables/useSongRowInteraction'
import type { Song } from '../../../src/types/song'

describe('useSongRowInteraction composable', () => {
	beforeEach(() => {
		vi.useFakeTimers()
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.useRealTimers()
	})

	const mockSong: Song = { id: 42, title: 'Test Song' }

	function createTouchEvent(
		touches: Array<{ clientX: number; clientY: number }>,
		target?: EventTarget,
		cancelable = false
	): TouchEvent {
		return {
			touches,
			target: target ?? {
				nodeType: 1,
				closest: () => null,
			},
			cancelable,
			preventDefault: vi.fn(),
		} as unknown as TouchEvent
	}

	it('triggers long-press selection and vibration after 500ms threshold', () => {
		const onToggleSelect = vi.fn()
		const onViewSong = vi.fn()
		const vibrateMock = vi.fn()
		vi.stubGlobal('navigator', { vibrate: vibrateMock })

		const { isLongPressing, handleTouchStart } = useSongRowInteraction({
			onToggleSelect,
			onViewSong,
		})

		const touchEvent = createTouchEvent([{ clientX: 100, clientY: 100 }])
		handleTouchStart(42, touchEvent)

		expect(isLongPressing.value).toBe(false)
		expect(onToggleSelect).not.toHaveBeenCalled()
		expect(vibrateMock).not.toHaveBeenCalled()

		// Advance time just before 500 ms threshold
		vi.advanceTimersByTime(499)
		expect(isLongPressing.value).toBe(false)
		expect(onToggleSelect).not.toHaveBeenCalled()
		expect(vibrateMock).not.toHaveBeenCalled()

		// Reach 500 ms threshold
		vi.advanceTimersByTime(1)
		expect(isLongPressing.value).toBe(true)
		expect(onToggleSelect).toHaveBeenCalledWith(42)
		expect(vibrateMock).toHaveBeenCalledWith(40)
	})

	it('does not start hold gesture when touching links or interactive elements', () => {
		const onToggleSelect = vi.fn()
		const { handleTouchStart } = useSongRowInteraction({ onToggleSelect })

		const linkElement = {
			nodeType: 1,
			closest: (selector: string) => (selector.includes('a') ? {} : null),
		} as unknown as Element

		const buttonElement = {
			nodeType: 1,
			closest: (selector: string) => (selector.includes('button') ? {} : null),
		} as unknown as Element

		// Touch on link
		handleTouchStart(42, createTouchEvent([{ clientX: 10, clientY: 10 }], linkElement))
		vi.advanceTimersByTime(600)
		expect(onToggleSelect).not.toHaveBeenCalled()

		// Touch on button (e.g. filter chip)
		handleTouchStart(42, createTouchEvent([{ clientX: 10, clientY: 10 }], buttonElement))
		vi.advanceTimersByTime(600)
		expect(onToggleSelect).not.toHaveBeenCalled()
	})

	it('does not open song for viewing when clicking on links or interactive elements', () => {
		const onViewSong = vi.fn()
		const { handleSongClick } = useSongRowInteraction({ onViewSong })

		const linkElement = {
			nodeType: 1,
			closest: (selector: string) => (selector.includes('a') ? {} : null),
		} as unknown as Element

		const clickOnLink = {
			target: linkElement,
			preventDefault: vi.fn(),
			stopPropagation: vi.fn(),
		} as unknown as Event

		handleSongClick(mockSong, clickOnLink)
		expect(onViewSong).not.toHaveBeenCalled()
	})

	it('cancels timer on movement exceeding threshold', () => {
		const onToggleSelect = vi.fn()
		const { isLongPressing, handleTouchStart, handleTouchMove } = useSongRowInteraction({
			onToggleSelect,
		})

		handleTouchStart(42, createTouchEvent([{ clientX: 100, clientY: 100 }]))

		// Movement within threshold (<= 10px) does not cancel
		handleTouchMove(createTouchEvent([{ clientX: 108, clientY: 108 }]))
		vi.advanceTimersByTime(200)
		expect(onToggleSelect).not.toHaveBeenCalled()

		// Movement exceeding threshold (> 10px) cancels
		handleTouchMove(createTouchEvent([{ clientX: 112, clientY: 100 }]))

		// Even after remaining time passes, selection is not triggered
		vi.advanceTimersByTime(400)
		expect(isLongPressing.value).toBe(false)
		expect(onToggleSelect).not.toHaveBeenCalled()
	})

	it('cancels timer on handleTouchEnd and handleTouchCancel', () => {
		const onToggleSelect = vi.fn()
		const { handleTouchStart, handleTouchEnd, handleTouchCancel } = useSongRowInteraction({
			onToggleSelect,
		})

		// Test touchend
		handleTouchStart(42, createTouchEvent([{ clientX: 50, clientY: 50 }]))
		vi.advanceTimersByTime(200)
		handleTouchEnd()
		vi.advanceTimersByTime(400)
		expect(onToggleSelect).not.toHaveBeenCalled()

		// Test touchcancel
		handleTouchStart(42, createTouchEvent([{ clientX: 50, clientY: 50 }]))
		vi.advanceTimersByTime(200)
		handleTouchCancel()
		vi.advanceTimersByTime(400)
		expect(onToggleSelect).not.toHaveBeenCalled()
	})

	it('prevents default on touchend when long press was triggered', () => {
		const onToggleSelect = vi.fn()
		const { handleTouchStart, handleTouchEnd } = useSongRowInteraction({ onToggleSelect })

		handleTouchStart(42, createTouchEvent([{ clientX: 50, clientY: 50 }]))
		vi.advanceTimersByTime(500)

		const touchEndEvent = {
			cancelable: true,
			preventDefault: vi.fn(),
		} as unknown as TouchEvent

		handleTouchEnd(touchEndEvent)
		expect(touchEndEvent.preventDefault).toHaveBeenCalled()
	})

	it('suppresses click after a long-press and allows click otherwise', () => {
		const onViewSong = vi.fn()
		const onToggleSelect = vi.fn()
		const { isLongPressing, handleTouchStart, handleSongClick } = useSongRowInteraction({
			onViewSong,
			onToggleSelect,
		})

		// Normal click without long press
		const normalClickEvent = {
			target: { nodeType: 1, closest: () => null },
			preventDefault: vi.fn(),
			stopPropagation: vi.fn(),
		} as unknown as Event

		handleSongClick(mockSong, normalClickEvent)
		expect(onViewSong).toHaveBeenCalledWith(mockSong)
		expect(normalClickEvent.preventDefault).not.toHaveBeenCalled()
		expect(normalClickEvent.stopPropagation).not.toHaveBeenCalled()

		onViewSong.mockClear()

		// Trigger long press
		handleTouchStart(42, createTouchEvent([{ clientX: 10, clientY: 10 }]))
		vi.advanceTimersByTime(500)
		expect(isLongPressing.value).toBe(true)

		// Click event immediately following long press should be suppressed
		const longPressClickEvent = {
			target: { nodeType: 1, closest: () => null },
			preventDefault: vi.fn(),
			stopPropagation: vi.fn(),
		} as unknown as Event

		handleSongClick(mockSong, longPressClickEvent)
		expect(longPressClickEvent.preventDefault).toHaveBeenCalled()
		expect(longPressClickEvent.stopPropagation).toHaveBeenCalled()
		expect(onViewSong).not.toHaveBeenCalled()
		expect(isLongPressing.value).toBe(false)

		// Wait past the long press window (1000ms)
		vi.advanceTimersByTime(1100)

		// Subsequent click after suppression should work again normally
		handleSongClick(mockSong, normalClickEvent)
		expect(onViewSong).toHaveBeenCalledWith(mockSong)
	})

	it('supports passing an emit function', () => {
		const emit = vi.fn()
		const { handleTouchStart, handleSongClick } = useSongRowInteraction(emit)

		handleTouchStart(99, createTouchEvent([{ clientX: 0, clientY: 0 }]))
		vi.advanceTimersByTime(500)
		expect(emit).toHaveBeenCalledWith('toggle-select-song', 99)

		const clickEvent = {
			target: { nodeType: 1, closest: () => null },
			preventDefault: vi.fn(),
			stopPropagation: vi.fn(),
		} as unknown as Event
		handleSongClick(mockSong, clickEvent)
		expect(emit).not.toHaveBeenCalledWith('view-song', mockSong) // Suppressed after long press

		// Wait past suppression window
		vi.advanceTimersByTime(1100)
		handleSongClick(mockSong, clickEvent)
		expect(emit).toHaveBeenCalledWith('view-song', mockSong)
	})

	it('ignores multi-touch events', () => {
		const onToggleSelect = vi.fn()
		const { handleTouchStart } = useSongRowInteraction({ onToggleSelect })

		// Multi-touch (> 1 touch)
		handleTouchStart(42, createTouchEvent([
			{ clientX: 0, clientY: 0 },
			{ clientX: 10, clientY: 10 },
		]))
		vi.advanceTimersByTime(600)
		expect(onToggleSelect).not.toHaveBeenCalled()
	})

	it('handles vibration errors gracefully', () => {
		const vibrateMock = vi.fn(() => {
			throw new Error('Vibration disabled')
		})
		vi.stubGlobal('navigator', { vibrate: vibrateMock })

		const onToggleSelect = vi.fn()
		const { handleTouchStart } = useSongRowInteraction({ onToggleSelect })

		handleTouchStart(42, createTouchEvent([{ clientX: 0, clientY: 0 }]))
		expect(() => vi.advanceTimersByTime(500)).not.toThrow()
		expect(onToggleSelect).toHaveBeenCalledWith(42)
	})
})
