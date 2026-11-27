import { computed, getCurrentScope, onScopeDispose, ref } from 'vue'
import {
	AUTO_SCROLL_SPEED_DEFAULT,
	sanitizeAutoScrollDelay,
	sanitizeAutoScrollSpeed,
} from '../utils/performance-settings'

export type AutoScrollMode = 'speed' | 'duration'

export interface UseAutoScrollOptions {
	/** Returns the scrollable container element (e.g. FilePreviewer's pdfContainer). */
	getContainer: () => HTMLElement | null
	/** Returns the active song's duration in seconds (if known), used for duration calibration. */
	getSongDurationSeconds?: () => number | null
	/** Optional callback invoked before starting so caller can switch to continuous scroll layout. */
	onBeforeStart?: () => void
	/** Initial speed in px/s (5..200). */
	initialSpeed?: number
	/** Initial start delay in seconds (0..30). */
	initialDelay?: number
	/** Optional persistence callback when speed or delay changes. */
	onSettingsChange?: (settings: { autoScrollSpeed: number; autoScrollDelay: number }) => void
}

/**
 * Computes effective vertical scroll speed (px/s) for either direct speed mode
 * or song-duration calibration mode.
 */
export function computeAutoScrollPxPerSec(
	mode: AutoScrollMode,
	speedPxPerSec: number,
	scrollablePx: number,
	durationSeconds: number | null,
): number {
	if (mode === 'duration' && durationSeconds && durationSeconds > 0 && scrollablePx > 0) {
		return Math.max(1, scrollablePx / durationSeconds)
	}
	return sanitizeAutoScrollSpeed(speedPxPerSec)
}

/**
 * Hands-free vertical auto-scrolling with fractional pixel accumulation,
 * optional song-duration calibration, and optional start countdown delay.
 */
export function useAutoScroll(options: UseAutoScrollOptions) {
	const mode = ref<AutoScrollMode>('speed')
	const speedPxPerSec = ref<number>(sanitizeAutoScrollSpeed(options.initialSpeed ?? AUTO_SCROLL_SPEED_DEFAULT))
	const startDelaySeconds = ref<number>(sanitizeAutoScrollDelay(options.initialDelay ?? 0))
	const isRunning = ref(false)
	const isPaused = ref(false)
	const countdownRemaining = ref(0)

	const isActive = computed(() => isRunning.value || countdownRemaining.value > 0)

	let rafId: number | null = null
	let lastFrameTime: number | null = null
	let subPixelRemainder = 0
	let lastExpectedScrollTop: number | null = null
	let delayIntervalId: ReturnType<typeof setInterval> | null = null

	function clearDelayTimer() {
		if (delayIntervalId !== null) {
			clearInterval(delayIntervalId)
			delayIntervalId = null
		}
		countdownRemaining.value = 0
	}

	function cancelFrame() {
		if (rafId !== null) {
			cancelAnimationFrame(rafId)
			rafId = null
		}
		lastFrameTime = null
	}

	function getEffectiveSpeedPxPerSec(): number {
		const el = options.getContainer()
		const scrollablePx = el ? Math.max(0, el.scrollHeight - el.clientHeight) : 0
		const duration = options.getSongDurationSeconds?.() ?? null
		return computeAutoScrollPxPerSec(mode.value, speedPxPerSec.value, scrollablePx, duration)
	}

	function step(now: number) {
		if (!isRunning.value) return
		const el = options.getContainer()
		if (!el) {
			stop()
			return
		}
		const maxScroll = Math.max(0, el.scrollHeight - el.clientHeight)
		if (lastFrameTime !== null) {
			const dt = Math.min(0.1, Math.max(0, (now - lastFrameTime) / 1000))
			// If user manually nudged scrollTop, resync without fighting them
			if (lastExpectedScrollTop !== null && Math.abs(el.scrollTop - lastExpectedScrollTop) > 2) {
				subPixelRemainder = 0
			}
			const pxPerSec = getEffectiveSpeedPxPerSec()
			const delta = pxPerSec * dt + subPixelRemainder
			const wholePx = Math.floor(delta)
			subPixelRemainder = delta - wholePx
			if (wholePx > 0 && maxScroll > 0) {
				const nextTop = Math.min(maxScroll, el.scrollTop + wholePx)
				el.scrollTop = nextTop
				lastExpectedScrollTop = el.scrollTop
				if (nextTop >= maxScroll - 0.5) {
					stop()
					return
				}
			}
		} else {
			lastExpectedScrollTop = el.scrollTop
		}
		lastFrameTime = now
		rafId = requestAnimationFrame(step)
	}

	function beginScrollingLoop() {
		clearDelayTimer()
		cancelFrame()
		isRunning.value = true
		isPaused.value = false
		subPixelRemainder = 0
		lastExpectedScrollTop = null
		rafId = requestAnimationFrame(step)
	}

	function start() {
		if (isRunning.value || countdownRemaining.value > 0) return
		options.onBeforeStart?.()
		const el = options.getContainer()
		if (el) {
			const maxScroll = Math.max(0, el.scrollHeight - el.clientHeight)
			if (maxScroll > 0 && el.scrollTop >= maxScroll - 1) {
				el.scrollTop = 0
			}
		}
		isPaused.value = false
		const delay = startDelaySeconds.value
		if (delay > 0) {
			countdownRemaining.value = delay
			delayIntervalId = setInterval(() => {
				if (countdownRemaining.value <= 1) {
					beginScrollingLoop()
				} else {
					countdownRemaining.value -= 1
				}
			}, 1000)
		} else {
			beginScrollingLoop()
		}
	}

	function pause() {
		if (!isRunning.value && countdownRemaining.value === 0) return
		clearDelayTimer()
		cancelFrame()
		isRunning.value = false
		isPaused.value = true
	}

	function resume() {
		if (isRunning.value) return
		options.onBeforeStart?.()
		beginScrollingLoop()
	}

	function toggle() {
		if (isRunning.value || countdownRemaining.value > 0) {
			pause()
		} else if (isPaused.value) {
			resume()
		} else {
			start()
		}
	}

	function stop() {
		clearDelayTimer()
		cancelFrame()
		isRunning.value = false
		isPaused.value = false
		subPixelRemainder = 0
		lastExpectedScrollTop = null
	}

	function resetToTop() {
		const el = options.getContainer()
		if (el) {
			el.scrollTop = 0
		}
		subPixelRemainder = 0
		lastExpectedScrollTop = 0
	}

	function setSpeed(pxPerSec: number) {
		speedPxPerSec.value = sanitizeAutoScrollSpeed(pxPerSec)
		options.onSettingsChange?.({
			autoScrollSpeed: speedPxPerSec.value,
			autoScrollDelay: startDelaySeconds.value,
		})
	}

	function adjustSpeed(delta: number) {
		setSpeed(speedPxPerSec.value + delta)
	}

	function setStartDelay(seconds: number) {
		startDelaySeconds.value = sanitizeAutoScrollDelay(seconds)
		options.onSettingsChange?.({
			autoScrollSpeed: speedPxPerSec.value,
			autoScrollDelay: startDelaySeconds.value,
		})
	}

	function setMode(nextMode: AutoScrollMode) {
		mode.value = nextMode
	}

	function applySettings(settings: { autoScrollSpeed?: number; autoScrollDelay?: number }) {
		if (settings.autoScrollSpeed !== undefined) {
			speedPxPerSec.value = sanitizeAutoScrollSpeed(settings.autoScrollSpeed)
		}
		if (settings.autoScrollDelay !== undefined) {
			startDelaySeconds.value = sanitizeAutoScrollDelay(settings.autoScrollDelay)
		}
	}

	if (getCurrentScope()) {
		onScopeDispose(() => stop())
	}

	return {
		mode,
		speedPxPerSec,
		startDelaySeconds,
		isRunning,
		isPaused,
		countdownRemaining,
		isActive,
		getEffectiveSpeedPxPerSec,
		start,
		pause,
		resume,
		toggle,
		stop,
		resetToTop,
		setSpeed,
		adjustSpeed,
		setStartDelay,
		setMode,
		applySettings,
	}
}
