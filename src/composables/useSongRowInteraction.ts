import { getCurrentInstance, onBeforeUnmount, ref, type Ref } from 'vue'
import type { Song } from '../types/song'

export interface SongRowInteractionOptions {
	onToggleSelect?: (songId: number) => void
	onSelectSong?: (songId: number) => void
	onViewSong?: (song: Song) => void
	onView?: (song: Song) => void
	pressDelay?: number
	moveThreshold?: number
	vibrateDuration?: number
}

export type SongRowInteractionEmit = (event: any, ...args: any[]) => void

export interface UseSongRowInteractionReturn {
	isLongPressing: Ref<boolean>
	handleTouchStart: (songId: number, e: TouchEvent) => void
	handleTouchMove: (e: TouchEvent) => void
	handleTouchEnd: (e?: TouchEvent) => void
	handleTouchCancel: (e?: TouchEvent) => void
	handleSongClick: (song: Song, e: Event) => void
	cancelTouch: () => void
}

function isInteractiveElement(target: EventTarget | null): boolean {
	if (!target) return false
	let el = target as Element | null
	if (el && 'nodeType' in el && el.nodeType === 3) {
		el = (el as unknown as Node).parentElement
	}
	if (!el || typeof el.closest !== 'function') return false
	return Boolean(
		el.closest('a, button, input, select, textarea, [role="button"], [role="link"], [data-prevent-hold]')
	)
}

export function useSongRowInteraction(
	optionsOrEmit?: SongRowInteractionOptions | SongRowInteractionEmit
): UseSongRowInteractionReturn {
	let onToggleSelect: ((songId: number) => void) | undefined
	let onViewSong: ((song: Song) => void) | undefined
	let pressDelay = 500
	let moveThreshold = 10
	let vibrateDuration = 40

	if (typeof optionsOrEmit === 'function') {
		const emit = optionsOrEmit
		onToggleSelect = (songId: number) => emit('toggle-select-song', songId)
		onViewSong = (song) => emit('view-song', song)
	} else if (optionsOrEmit) {
		onToggleSelect = optionsOrEmit.onToggleSelect ?? optionsOrEmit.onSelectSong
		onViewSong = optionsOrEmit.onViewSong ?? optionsOrEmit.onView
		if (typeof optionsOrEmit.pressDelay === 'number') {
			pressDelay = optionsOrEmit.pressDelay
		}
		if (typeof optionsOrEmit.moveThreshold === 'number') {
			moveThreshold = optionsOrEmit.moveThreshold
		}
		if (typeof optionsOrEmit.vibrateDuration === 'number') {
			vibrateDuration = optionsOrEmit.vibrateDuration
		}
	}

	let touchTimer: ReturnType<typeof setTimeout> | null = null
	let longPressResetTimer: ReturnType<typeof setTimeout> | null = null
	let lastLongPressTime = 0
	let touchStartPos = { x: 0, y: 0 }
	const isLongPressing = ref(false)

	function cancelTouch(): void {
		if (touchTimer) {
			clearTimeout(touchTimer)
			touchTimer = null
		}
	}

	function handleTouchStart(songId: number, e: TouchEvent): void {
		if (e.touches.length !== 1) return
		if (isInteractiveElement(e.target)) return

		cancelTouch()
		isLongPressing.value = false
		touchStartPos = { x: e.touches[0].clientX, y: e.touches[0].clientY }
		touchTimer = setTimeout(() => {
			isLongPressing.value = true
			lastLongPressTime = Date.now()
			if (longPressResetTimer) {
				clearTimeout(longPressResetTimer)
			}
			longPressResetTimer = setTimeout(() => {
				isLongPressing.value = false
				longPressResetTimer = null
			}, 1000)

			if (typeof navigator !== 'undefined' && 'vibrate' in navigator) {
				try {
					navigator.vibrate(vibrateDuration)
				} catch {
					// ignore
				}
			}
			onToggleSelect?.(songId)
		}, pressDelay)
	}

	function handleTouchMove(e: TouchEvent): void {
		if (!touchTimer || e.touches.length !== 1) return
		const touch = e.touches[0]
		const dx = Math.abs(touch.clientX - touchStartPos.x)
		const dy = Math.abs(touch.clientY - touchStartPos.y)
		if (dx > moveThreshold || dy > moveThreshold) {
			cancelTouch()
		}
	}

	function handleTouchEnd(e?: TouchEvent): void {
		if (isLongPressing.value && e && e.cancelable) {
			e.preventDefault()
		}
		cancelTouch()
	}

	function handleTouchCancel(): void {
		cancelTouch()
	}

	function handleSongClick(song: Song, e: Event): void {
		if (isInteractiveElement(e.target)) {
			return
		}

		const isRecentlyLongPressed = isLongPressing.value || Date.now() - lastLongPressTime < 1000
		if (isRecentlyLongPressed) {
			e.preventDefault()
			e.stopPropagation()
			isLongPressing.value = false
			lastLongPressTime = 0
			if (longPressResetTimer) {
				clearTimeout(longPressResetTimer)
				longPressResetTimer = null
			}
			return
		}
		onViewSong?.(song)
	}

	if (getCurrentInstance()) {
		onBeforeUnmount(() => {
			cancelTouch()
			if (longPressResetTimer) {
				clearTimeout(longPressResetTimer)
				longPressResetTimer = null
			}
		})
	}

	return {
		isLongPressing,
		handleTouchStart,
		handleTouchMove,
		handleTouchEnd,
		handleTouchCancel,
		handleSongClick,
		cancelTouch,
	}
}
