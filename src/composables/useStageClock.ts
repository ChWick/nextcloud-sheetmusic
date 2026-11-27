/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, onUnmounted, ref } from 'vue'

export type StageClockMode = 'clock' | 'remaining' | 'countdown'
export type CountdownStatus = 'normal' | 'warning' | 'expired'

export const COUNTDOWN_PRESET_MINUTES = [15, 30, 45, 60] as const
const MODE_STORAGE_KEY = 'sheetmusic:stage-clock-mode'
const WARNING_THRESHOLD_SECONDS = 300 // 5 minutes

function loadSavedMode(): StageClockMode {
	if (typeof window === 'undefined') return 'clock'
	try {
		const raw = window.localStorage.getItem(MODE_STORAGE_KEY)
		if (raw === 'clock' || raw === 'remaining' || raw === 'countdown') {
			return raw
		}
	} catch {
		// Ignore storage errors
	}
	return 'clock'
}

/**
 * Reactive wall clock and gig countdown timer for Performance Mode.
 *
 * @param initialCountdownSeconds Default countdown target in seconds (default 30 min)
 */
export function useStageClock(initialCountdownSeconds = 1800) {
	const mode = ref<StageClockMode>(loadSavedMode())
	const now = ref<Date>(new Date())
	const countdownTargetSeconds = ref<number>(Math.max(60, Math.round(initialCountdownSeconds)))
	const countdownRemainingSeconds = ref<number>(countdownTargetSeconds.value)
	const isCountdownRunning = ref<boolean>(false)

	let intervalId: ReturnType<typeof setInterval> | null = null

	function setMode(next: StageClockMode): void {
		mode.value = next
		if (typeof window !== 'undefined') {
			try {
				window.localStorage.setItem(MODE_STORAGE_KEY, next)
			} catch {
				// Ignore storage errors
			}
		}
	}

	function tick(): void {
		now.value = new Date()
		if (isCountdownRunning.value) {
			if (countdownRemainingSeconds.value > 0) {
				countdownRemainingSeconds.value -= 1
			}
			if (countdownRemainingSeconds.value <= 0) {
				countdownRemainingSeconds.value = 0
				isCountdownRunning.value = false
			}
		}
	}

	if (typeof setInterval === 'function') {
		intervalId = setInterval(tick, 1000)
	}

	function startCountdown(): void {
		if (countdownRemainingSeconds.value <= 0) {
			countdownRemainingSeconds.value = countdownTargetSeconds.value
		}
		isCountdownRunning.value = true
	}

	function pauseCountdown(): void {
		isCountdownRunning.value = false
	}

	function toggleCountdown(): void {
		if (isCountdownRunning.value) {
			pauseCountdown()
		} else {
			startCountdown()
		}
	}

	function resetCountdown(nextTargetSeconds?: number): void {
		if (typeof nextTargetSeconds === 'number' && Number.isFinite(nextTargetSeconds) && nextTargetSeconds > 0) {
			countdownTargetSeconds.value = Math.max(60, Math.round(nextTargetSeconds))
		}
		isCountdownRunning.value = false
		countdownRemainingSeconds.value = countdownTargetSeconds.value
	}

	const countdownStatus = computed<CountdownStatus>(() => {
		if (countdownRemainingSeconds.value <= 0) return 'expired'
		if (isCountdownRunning.value && countdownRemainingSeconds.value <= WARNING_THRESHOLD_SECONDS) {
			return 'warning'
		}
		return 'normal'
	})

	onUnmounted(() => {
		if (intervalId !== null) {
			clearInterval(intervalId)
			intervalId = null
		}
	})

	return {
		mode,
		now,
		countdownTargetSeconds,
		countdownRemainingSeconds,
		isCountdownRunning,
		countdownStatus,
		setMode,
		startCountdown,
		pauseCountdown,
		toggleCountdown,
		resetCountdown,
	}
}
