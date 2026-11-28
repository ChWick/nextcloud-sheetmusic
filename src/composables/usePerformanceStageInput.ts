import { onMounted, onUnmounted } from 'vue'
import {
	DEFAULT_STAGE_INPUT_MAPPING,
	resolveKeyAction,
	resolveTapZoneAction,
	type StageAction,
	type StageInputMapping,
} from '../utils/stage-input-mapping'

export interface PerformanceStageInputOptions {
	isScrollMode: () => boolean
	/** True while (or just after) an active pinch-zoom or drag-pan gesture; suppresses both taps and swipes. */
	isPanningOrPinching?: () => boolean
	/** True when zoomed content can scroll horizontally; suppresses horizontal touch swipes but NOT container click zones. */
	canPanHorizontally?: () => boolean
	/** Optional customizable tap-zone & pedal/keyboard action mapping. */
	getInputMapping?: () => StageInputMapping
	onNextPage: () => void
	onPrevPage: () => void
	onToggleHud: () => void
	/** Optional dispatcher for extended actions (next-song, toggle-metronome, etc.). */
	onAction?: (action: StageAction) => void
	/** Optional handler for Spacebar; returns true if handled (e.g. toggling auto-scroll). */
	onSpace?: () => boolean
	/** Optional handler for stage tap in scroll mode; returns true if handled (e.g. pausing active auto-scroll). */
	onStageTap?: () => boolean
	onEscape: () => void
}

/**
 * Handles touch swipe, tap zones, and keyboard/pedal navigation
 * for the fullscreen performance viewer stage.
 *
 * @param options Stage input state accessors and action handlers
 */
export function usePerformanceStageInput(options: PerformanceStageInputOptions) {
	let touchStartX = 0
	let touchStartY = 0
	let touchStartTime = 0
	let multiTouchActive = false

	function onStageTouchStart(event: TouchEvent) {
		if (event.touches.length !== 1) {
			multiTouchActive = true
			return
		}
		multiTouchActive = false
		touchStartX = event.touches[0].clientX
		touchStartY = event.touches[0].clientY
		touchStartTime = Date.now()
	}

	function onStageTouchEnd(event: TouchEvent) {
		if (multiTouchActive) {
			if (event.touches.length === 0) {
				multiTouchActive = false
			}
			return
		}
		if (options.isScrollMode() || options.isPanningOrPinching?.() || options.canPanHorizontally?.()) return
		if (event.changedTouches.length !== 1) return
		const dx = event.changedTouches[0].clientX - touchStartX
		const dy = event.changedTouches[0].clientY - touchStartY
		const elapsed = Date.now() - touchStartTime

		if (elapsed < 500 && Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.2) {
			if (dx < 0) {
				options.onNextPage()
			} else {
				options.onPrevPage()
			}
		}
	}

	function dispatchAction(action: StageAction) {
		if (action === 'none') return
		if (action === 'autoscroll-or-next-page') {
			if (!options.onSpace?.()) {
				options.onNextPage()
			}
			return
		}
		if (action === 'next-page') {
			options.onNextPage()
		} else if (action === 'prev-page') {
			options.onPrevPage()
		} else if (action === 'toggle-hud') {
			options.onToggleHud()
		} else {
			options.onAction?.(action)
		}
	}

	function onStageClick(event: MouseEvent) {
		if (options.isPanningOrPinching?.()) {
			return
		}
		if ((event.target as HTMLElement | null)?.closest?.('.sheetmusic-annotation-link-group')) {
			return
		}
		if (options.isScrollMode() && options.onStageTap?.()) {
			return
		}
		const target = event.currentTarget as HTMLElement | null
		if (!target) return
		const rect = target.getBoundingClientRect()
		if (rect.width <= 0 || rect.height <= 0) {
			options.onToggleHud()
			return
		}
		const relX = (event.clientX - rect.left) / rect.width
		const relY = (event.clientY - rect.top) / rect.height
		const mapping = options.getInputMapping?.() ?? DEFAULT_STAGE_INPUT_MAPPING
		dispatchAction(resolveTapZoneAction(mapping.tapZoneLayout, relX, relY))
	}

	function handleKeyDown(event: KeyboardEvent) {
		const tag = (event.target as HTMLElement | null)?.tagName
		if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return

		if (event.key === 'Escape') {
			event.preventDefault()
			options.onEscape()
			return
		}

		const mapping = options.getInputMapping?.() ?? DEFAULT_STAGE_INPUT_MAPPING
		const action = resolveKeyAction(event.key, mapping)
		if (action === null) return

		event.preventDefault()
		dispatchAction(action)
	}

	onMounted(() => {
		window.addEventListener('keydown', handleKeyDown)
	})

	onUnmounted(() => {
		window.removeEventListener('keydown', handleKeyDown)
	})

	return {
		onStageTouchStart,
		onStageTouchEnd,
		onStageClick,
	}
}
