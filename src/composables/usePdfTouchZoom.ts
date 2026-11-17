import { onMounted, onUnmounted, type Ref } from 'vue'

export interface PdfTouchZoomOptions {
	containerRef: Ref<HTMLElement | undefined>
	stageRef: Ref<HTMLElement | undefined>
	getCurrentScale: () => number
	isEnabled: () => boolean
	onCommitZoom: (scale: number) => Promise<void> | void
	minScale?: number
	maxScale?: number
}

function getTouchDistance(t0: Touch, t1: Touch): number {
	return Math.hypot(t1.clientX - t0.clientX, t1.clientY - t0.clientY)
}

function getTouchMidpoint(t0: Touch, t1: Touch): { x: number; y: number } {
	return {
		x: (t0.clientX + t1.clientX) / 2,
		y: (t0.clientY + t1.clientY) / 2,
	}
}

function clamp(value: number, min: number, max: number): number {
	return Math.min(max, Math.max(min, value))
}

/**
 * Enables two-finger pinch-to-zoom (with live CSS transform preview and crisp
 * PDF re-render on release) while preserving native one-finger panning.
 */
export function usePdfTouchZoom(options: PdfTouchZoomOptions) {
	const minScale = options.minScale ?? 0.25
	const maxScale = options.maxScale ?? 4.0

	let isPinching = false
	let startDist = 0
	let startScale = 1
	let liveScale = 1
	let focalViewportX = 0
	let focalViewportY = 0
	let focalContentX = 0
	let focalContentY = 0
	let lastPinchEndTime = 0

	function resetStageTransform() {
		const stage = options.stageRef.value
		if (stage) {
			stage.style.transform = ''
			stage.style.transformOrigin = ''
		}
	}

	function handleTouchStart(event: TouchEvent) {
		if (!options.isEnabled() || event.touches.length !== 2) {
			return
		}
		const container = options.containerRef.value
		if (!container) {
			return
		}

		const t0 = event.touches[0]
		const t1 = event.touches[1]
		const dist = getTouchDistance(t0, t1)
		if (dist <= 0) {
			return
		}

		const rect = container.getBoundingClientRect()
		const mid = getTouchMidpoint(t0, t1)
		focalViewportX = mid.x - rect.left
		focalViewportY = mid.y - rect.top
		focalContentX = focalViewportX + container.scrollLeft
		focalContentY = focalViewportY + container.scrollTop

		isPinching = true
		startDist = dist
		startScale = Math.max(minScale, options.getCurrentScale())
		liveScale = startScale
	}

	function handleTouchMove(event: TouchEvent) {
		if (!isPinching || event.touches.length !== 2) {
			return
		}
		event.preventDefault()

		const dist = getTouchDistance(event.touches[0], event.touches[1])
		const ratio = dist / Math.max(1, startDist)
		liveScale = clamp(+(startScale * ratio).toFixed(3), minScale, maxScale)

		const stage = options.stageRef.value
		if (stage) {
			const factor = liveScale / startScale
			stage.style.transformOrigin = `${focalContentX}px ${focalContentY}px`
			stage.style.transform = `scale(${factor})`
		}
	}

	async function handleTouchEnd(event: TouchEvent) {
		if (!isPinching || event.touches.length >= 2) {
			return
		}

		isPinching = false
		lastPinchEndTime = Date.now()
		const targetScale = liveScale
		resetStageTransform()

		if (Math.abs(targetScale - startScale) <= 0.01) {
			return
		}

		const container = options.containerRef.value
		const scaleRatio = targetScale / startScale
		await options.onCommitZoom(targetScale)

		if (container) {
			container.scrollLeft = Math.max(0, focalContentX * scaleRatio - focalViewportX)
			container.scrollTop = Math.max(0, focalContentY * scaleRatio - focalViewportY)
		}
	}

	function handleTouchCancel() {
		if (!isPinching) {
			return
		}
		isPinching = false
		lastPinchEndTime = Date.now()
		resetStageTransform()
	}

	onMounted(() => {
		const el = options.containerRef.value
		if (!el) return
		el.addEventListener('touchstart', handleTouchStart, { passive: true })
		el.addEventListener('touchmove', handleTouchMove, { passive: false })
		el.addEventListener('touchend', handleTouchEnd, { passive: true })
		el.addEventListener('touchcancel', handleTouchCancel, { passive: true })
	})

	onUnmounted(() => {
		const el = options.containerRef.value
		if (!el) return
		el.removeEventListener('touchstart', handleTouchStart)
		el.removeEventListener('touchmove', handleTouchMove)
		el.removeEventListener('touchend', handleTouchEnd)
		el.removeEventListener('touchcancel', handleTouchCancel)
	})

	return {
		wasRecentlyPinching: () => isPinching || Date.now() - lastPinchEndTime < 350,
	}
}
