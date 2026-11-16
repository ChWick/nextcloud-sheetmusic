import { onMounted, onUnmounted } from 'vue'

export interface PerformanceStageInputOptions {
	isScrollMode: () => boolean
	onNextPage: () => void
	onPrevPage: () => void
	onToggleHud: () => void
	onEscape: () => void
}

/**
 * Handles touch swipe, left/center/right tap zones, and keyboard/pedal navigation
 * for the fullscreen performance viewer stage.
 */
export function usePerformanceStageInput(options: PerformanceStageInputOptions) {
	let touchStartX = 0
	let touchStartY = 0
	let touchStartTime = 0

	function onStageTouchStart(event: TouchEvent) {
		if (event.touches.length !== 1) return
		touchStartX = event.touches[0].clientX
		touchStartY = event.touches[0].clientY
		touchStartTime = Date.now()
	}

	function onStageTouchEnd(event: TouchEvent) {
		if (options.isScrollMode()) return
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

	function onStageClick(event: MouseEvent) {
		if ((event.target as HTMLElement | null)?.closest?.('.sheetmusic-annotation-link-group')) {
			return
		}
		if (options.isScrollMode()) {
			options.onToggleHud()
			return
		}
		const target = event.currentTarget as HTMLElement | null
		if (!target) return
		const rect = target.getBoundingClientRect()
		const relX = (event.clientX - rect.left) / Math.max(1, rect.width)
		if (relX < 0.25) {
			options.onPrevPage()
		} else if (relX > 0.75) {
			options.onNextPage()
		} else {
			options.onToggleHud()
		}
	}

	function handleKeyDown(event: KeyboardEvent) {
		const tag = (event.target as HTMLElement | null)?.tagName
		if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return

		switch (event.key) {
		case 'ArrowRight':
		case 'PageDown':
		case ' ':
		case 'Enter':
			event.preventDefault()
			options.onNextPage()
			break
		case 'ArrowLeft':
		case 'PageUp':
		case 'Backspace':
			event.preventDefault()
			options.onPrevPage()
			break
		case 'Escape':
			event.preventDefault()
			options.onEscape()
			break
		}
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
