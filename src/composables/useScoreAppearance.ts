import { computed, ref } from 'vue'
import {
	DEFAULT_PERFORMANCE_SETTINGS,
	getDefaultSepiaForMode,
	isNightScoreMode,
	sanitizeScoreBrightness,
	sanitizeScoreSepia,
	type ScoreColorMode,
} from '../utils/performance-settings'

export interface ScoreAppearanceState {
	scoreColorMode: ScoreColorMode
	scoreBrightness: number
	scoreSepia: number
}

export interface UseScoreAppearanceOptions {
	initial?: Partial<ScoreAppearanceState>
	onChange?: (state: ScoreAppearanceState) => void
}

/**
 * Shared state machine for score inversion/tinting mode, brightness dimming,
 * and continuous sepia strength used by both SongViewer and Performance Mode.
 */
export function useScoreAppearance(options: UseScoreAppearanceOptions = {}) {
	const scoreColorMode = ref<ScoreColorMode>(
		options.initial?.scoreColorMode ?? DEFAULT_PERFORMANCE_SETTINGS.scoreColorMode,
	)
	const scoreBrightness = ref<number>(
		sanitizeScoreBrightness(options.initial?.scoreBrightness ?? DEFAULT_PERFORMANCE_SETTINGS.scoreBrightness),
	)
	const scoreSepia = ref<number>(
		sanitizeScoreSepia(options.initial?.scoreSepia ?? DEFAULT_PERFORMANCE_SETTINGS.scoreSepia),
	)
	const isNightMode = computed(() => isNightScoreMode(scoreColorMode.value))

	function snapshot(): ScoreAppearanceState {
		return {
			scoreColorMode: scoreColorMode.value,
			scoreBrightness: scoreBrightness.value,
			scoreSepia: scoreSepia.value,
		}
	}

	function notify() {
		options.onChange?.(snapshot())
	}

	function apply(state: Partial<ScoreAppearanceState>) {
		if (state.scoreColorMode !== undefined) scoreColorMode.value = state.scoreColorMode
		if (state.scoreBrightness !== undefined) scoreBrightness.value = sanitizeScoreBrightness(state.scoreBrightness)
		if (state.scoreSepia !== undefined) scoreSepia.value = sanitizeScoreSepia(state.scoreSepia)
	}

	function setMode(mode: ScoreColorMode) {
		scoreColorMode.value = mode
		scoreSepia.value = getDefaultSepiaForMode(mode)
		notify()
	}

	function toggleNight() {
		setMode(isNightScoreMode(scoreColorMode.value) ? 'normal' : 'night')
	}

	function setBrightness(brightness: number) {
		scoreBrightness.value = sanitizeScoreBrightness(brightness)
		notify()
	}

	function setSepia(sepia: number) {
		const clamped = sanitizeScoreSepia(sepia)
		scoreSepia.value = clamped
		if (isNightScoreMode(scoreColorMode.value)) {
			scoreColorMode.value = clamped > 0 ? 'night-sepia' : 'night'
		} else {
			scoreColorMode.value = clamped > 0 ? 'sepia' : 'normal'
		}
		notify()
	}

	return {
		scoreColorMode,
		scoreBrightness,
		scoreSepia,
		isNightMode,
		apply,
		setMode,
		toggleNight,
		setBrightness,
		setSepia,
	}
}
