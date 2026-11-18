import { onMounted, onUnmounted, type Ref } from 'vue'

export interface PdfTouchZoomOptions {
	containerRef: Ref<HTMLElement | undefined>
	stageRef: Ref<HTMLElement | undefined>
	getCurrentScale: () => number
	isEnabled: () => boolean
	isAnnotating?: () => boolean
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
	let startMidClientX = 0
	let startMidClientY = 0
	let startScrollLeft = 0
	let startScrollTop = 0
	let committedPanX = 0
	let committedPanY = 0
	let livePanX = 0
	let livePanY = 0
	let lastPinchEndTime = 0

	// Mouse pan state
	let isMousePanning = false
	let mouseStartX = 0
	let mouseStartY = 0
	let mouseStartScrollLeft = 0
	let mouseStartScrollTop = 0

	// Wheel zoom state
	let wheelCommitTimer: ReturnType<typeof setTimeout> | null = null
	let wheelStartScale = 0
	let wheelTargetScale = 0
	let wheelFocalViewportX = 0
	let wheelFocalViewportY = 0
	let wheelFocalContentX = 0
	let wheelFocalContentY = 0

	function applyStageTransform(scaleFactor: number, panX: number, panY: number) {
		const stage = options.stageRef.value
		if (!stage) return
		const hasPan = Math.abs(panX) > 0.5 || Math.abs(panY) > 0.5
		const hasScale = Math.abs(scaleFactor - 1) > 0.001
		if (!hasPan && !hasScale) {
			stage.style.transform = ''
			stage.style.transformOrigin = ''
		} else if (!hasPan) {
			stage.style.transform = `scale(${scaleFactor})`
		} else if (!hasScale) {
			stage.style.transformOrigin = ''
			stage.style.transform = `translate(${Math.round(panX)}px, ${Math.round(panY)}px)`
		} else {
			stage.style.transform = `translate(${Math.round(panX)}px, ${Math.round(panY)}px) scale(${scaleFactor})`
		}
	}

	function resetStageTransform() {
		applyStageTransform(1, committedPanX, committedPanY)
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
		startMidClientX = mid.x
		startMidClientY = mid.y
		startScrollLeft = container.scrollLeft
		startScrollTop = container.scrollTop
		livePanX = committedPanX
		livePanY = committedPanY
		focalViewportX = mid.x - rect.left
		focalViewportY = mid.y - rect.top
		focalContentX = focalViewportX + container.scrollLeft
		focalContentY = focalViewportY + container.scrollTop

		isPinching = true
		startDist = dist
		startScale = Math.max(minScale, options.getCurrentScale())
		liveScale = startScale
	}

	function computeAxisPan(startScroll: number, maxScroll: number, panDelta: number, committedPan: number) {
		const desiredScroll = startScroll - panDelta
		const clampedScroll = clamp(desiredScroll, 0, maxScroll)
		return {
			scroll: Math.round(clampedScroll),
			livePan: committedPan + (clampedScroll - desiredScroll),
		}
	}

	function handleTouchMove(event: TouchEvent) {
		if (!isPinching || event.touches.length !== 2) {
			return
		}
		event.preventDefault()

		const t0 = event.touches[0]
		const t1 = event.touches[1]
		const dist = getTouchDistance(t0, t1)
		const ratio = dist / Math.max(1, startDist)
		liveScale = clamp(+(startScale * ratio).toFixed(3), minScale, maxScale)

		const mid = getTouchMidpoint(t0, t1)
		const panDeltaX = mid.x - startMidClientX
		const panDeltaY = mid.y - startMidClientY

		const container = options.containerRef.value
		if (container) {
			const xPan = computeAxisPan(
				startScrollLeft,
				Math.max(0, container.scrollWidth - container.clientWidth),
				panDeltaX,
				committedPanX,
			)
			container.scrollLeft = xPan.scroll
			livePanX = xPan.livePan

			const yPan = computeAxisPan(
				startScrollTop,
				Math.max(0, container.scrollHeight - container.clientHeight),
				panDeltaY,
				committedPanY,
			)
			container.scrollTop = yPan.scroll
			livePanY = yPan.livePan

			const rect = container.getBoundingClientRect()
			focalViewportX = mid.x - rect.left
			focalViewportY = mid.y - rect.top
		}

		const stage = options.stageRef.value
		if (stage) {
			const factor = liveScale / startScale
			stage.style.transformOrigin = `${focalContentX}px ${focalContentY}px`
			applyStageTransform(factor, livePanX, livePanY)
		}
	}

	async function handleTouchEnd(event: TouchEvent) {
		if (!isPinching || event.touches.length >= 2) {
			return
		}

		isPinching = false
		lastPinchEndTime = Date.now()
		const targetScale = liveScale
		committedPanX = livePanX
		committedPanY = livePanY
		resetStageTransform()

		const container = options.containerRef.value
		const scaleChanged = Math.abs(targetScale - startScale) > 0.01

		if (!scaleChanged) {
			return
		}

		const scaleRatio = targetScale / startScale
		await options.onCommitZoom(targetScale)
		resetStageTransform()

		if (container) {
			const maxScrollLeft = Math.max(0, container.scrollWidth - container.clientWidth)
			const maxScrollTop = Math.max(0, container.scrollHeight - container.clientHeight)
			container.scrollLeft = clamp(focalContentX * scaleRatio - focalViewportX, 0, maxScrollLeft)
			container.scrollTop = clamp(focalContentY * scaleRatio - focalViewportY, 0, maxScrollTop)
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

	function handleWheel(event: WheelEvent) {
		if (!options.isEnabled() || !(event.ctrlKey || event.metaKey)) {
			return
		}
		const container = options.containerRef.value
		if (!container) return

		event.preventDefault()

		if (wheelCommitTimer === null) {
			wheelStartScale = Math.max(minScale, options.getCurrentScale())
			wheelTargetScale = wheelStartScale
			const rect = container.getBoundingClientRect()
			wheelFocalViewportX = event.clientX - rect.left
			wheelFocalViewportY = event.clientY - rect.top
			wheelFocalContentX = wheelFocalViewportX + container.scrollLeft
			wheelFocalContentY = wheelFocalViewportY + container.scrollTop
		} else {
			clearTimeout(wheelCommitTimer)
		}

		const zoomFactor = clamp(Math.exp(-event.deltaY * 0.002), 0.75, 1.35)
		wheelTargetScale = clamp(+(wheelTargetScale * zoomFactor).toFixed(3), minScale, maxScale)

		const stage = options.stageRef.value
		if (stage) {
			const factor = wheelTargetScale / wheelStartScale
			stage.style.transformOrigin = `${wheelFocalContentX}px ${wheelFocalContentY}px`
			applyStageTransform(factor, committedPanX, committedPanY)
		}

		wheelCommitTimer = setTimeout(async () => {
			wheelCommitTimer = null
			const targetScale = wheelTargetScale
			const scaleRatio = targetScale / wheelStartScale
			resetStageTransform()

			if (Math.abs(targetScale - wheelStartScale) <= 0.005) {
				return
			}

			await options.onCommitZoom(targetScale)
			resetStageTransform()
			if (container) {
				const maxScrollLeft = Math.max(0, container.scrollWidth - container.clientWidth)
				const maxScrollTop = Math.max(0, container.scrollHeight - container.clientHeight)
				container.scrollLeft = clamp(wheelFocalContentX * scaleRatio - wheelFocalViewportX, 0, maxScrollLeft)
				container.scrollTop = clamp(wheelFocalContentY * scaleRatio - wheelFocalViewportY, 0, maxScrollTop)
			}
		}, 120)
	}

	function handleMouseDown(event: MouseEvent) {
		if (!options.isEnabled()) return
		const isMiddle = event.button === 1
		const isModifierDrag = event.button === 0 && (event.ctrlKey || event.metaKey)
		const targetEl = event.target as HTMLElement | null
		const isInteractiveTarget = Boolean(targetEl?.closest?.('a, button, input, select, textarea, .sheetmusic-annotation-link'))
		const isPlainViewDrag = event.button === 0 && !options.isAnnotating?.() && !isInteractiveTarget

		if (!isMiddle && !isModifierDrag && !isPlainViewDrag) {
			return
		}
		const container = options.containerRef.value
		if (!container) return

		event.preventDefault()
		isMousePanning = true
		mouseStartX = event.clientX
		mouseStartY = event.clientY
		mouseStartScrollLeft = container.scrollLeft
		mouseStartScrollTop = container.scrollTop
		livePanX = committedPanX
		livePanY = committedPanY
		container.style.cursor = 'grabbing'
		document.documentElement.classList.add('sheetmusic-panning')
	}

	function handleMouseMove(event: MouseEvent) {
		if (!isMousePanning) return
		const container = options.containerRef.value
		if (!container) return

		const panDeltaX = event.clientX - mouseStartX
		const panDeltaY = event.clientY - mouseStartY

		const xPan = computeAxisPan(
			mouseStartScrollLeft,
			Math.max(0, container.scrollWidth - container.clientWidth),
			panDeltaX,
			committedPanX,
		)
		container.scrollLeft = xPan.scroll
		livePanX = xPan.livePan

		const yPan = computeAxisPan(
			mouseStartScrollTop,
			Math.max(0, container.scrollHeight - container.clientHeight),
			panDeltaY,
			committedPanY,
		)
		container.scrollTop = yPan.scroll
		livePanY = yPan.livePan

		applyStageTransform(1, livePanX, livePanY)
	}

	function stopMousePan() {
		if (!isMousePanning) return
		isMousePanning = false
		committedPanX = livePanX
		committedPanY = livePanY
		resetStageTransform()
		document.documentElement.classList.remove('sheetmusic-panning')
		const container = options.containerRef.value
		if (container) {
			container.style.cursor = ''
		}
	}

	function handleModifierKey(event: KeyboardEvent) {
		const el = options.containerRef.value
		if (!el) return
		const active = options.isEnabled() && (event.ctrlKey || event.metaKey)
		el.classList.toggle('sheetmusic-pan-ready', active)
	}

	function clearModifierCursor() {
		options.containerRef.value?.classList.remove('sheetmusic-pan-ready')
	}

	onMounted(() => {
		const el = options.containerRef.value
		if (!el) return
		el.addEventListener('touchstart', handleTouchStart, { passive: true })
		el.addEventListener('touchmove', handleTouchMove, { passive: false })
		el.addEventListener('touchend', handleTouchEnd, { passive: true })
		el.addEventListener('touchcancel', handleTouchCancel, { passive: true })
		el.addEventListener('wheel', handleWheel, { passive: false })
		el.addEventListener('mousedown', handleMouseDown)
		window.addEventListener('mousemove', handleMouseMove)
		window.addEventListener('mouseup', stopMousePan)
		window.addEventListener('keydown', handleModifierKey)
		window.addEventListener('keyup', handleModifierKey)
		window.addEventListener('blur', clearModifierCursor)
	})

	onUnmounted(() => {
		if (wheelCommitTimer !== null) {
			clearTimeout(wheelCommitTimer)
			wheelCommitTimer = null
		}
		const el = options.containerRef.value
		if (el) {
			el.removeEventListener('touchstart', handleTouchStart)
			el.removeEventListener('touchmove', handleTouchMove)
			el.removeEventListener('touchend', handleTouchEnd)
			el.removeEventListener('touchcancel', handleTouchCancel)
			el.removeEventListener('wheel', handleWheel)
			el.removeEventListener('mousedown', handleMouseDown)
			el.classList.remove('sheetmusic-pan-ready')
		}
		window.removeEventListener('mousemove', handleMouseMove)
		window.removeEventListener('mouseup', stopMousePan)
		window.removeEventListener('keydown', handleModifierKey)
		window.removeEventListener('keyup', handleModifierKey)
		window.removeEventListener('blur', clearModifierCursor)
		document.documentElement.classList.remove('sheetmusic-panning')
	})

	function resetPan() {
		committedPanX = 0
		committedPanY = 0
		livePanX = 0
		livePanY = 0
		applyStageTransform(1, 0, 0)
	}

	return {
		wasRecentlyPinching: () => isPinching || Date.now() - lastPinchEndTime < 350,
		resetPan,
	}
}
