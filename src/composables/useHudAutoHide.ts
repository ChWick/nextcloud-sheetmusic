import { onUnmounted, ref } from 'vue'

/**
 * Manages auto-hiding HUD visibility with inactivity timeout and pause/resume guards.
 */
export function useHudAutoHide(
	timeoutMs = 3000,
	isBlocked: () => boolean = () => false,
) {
	const isHudVisible = ref(true)
	let hudHideTimer: ReturnType<typeof setTimeout> | null = null
	let isHudPinned = false

	function clearHudTimer() {
		if (hudHideTimer !== null) {
			clearTimeout(hudHideTimer)
			hudHideTimer = null
		}
	}

	function scheduleHudAutoHide() {
		clearHudTimer()
		if (isHudPinned || isBlocked()) return
		hudHideTimer = setTimeout(() => {
			if (!isHudPinned && !isBlocked()) {
				isHudVisible.value = false
			}
		}, timeoutMs)
	}

	function showHudTemporarily() {
		isHudVisible.value = true
		scheduleHudAutoHide()
	}

	function hideHud() {
		clearHudTimer()
		isHudVisible.value = false
	}

	function toggleHud() {
		if (isHudVisible.value) {
			hideHud()
		} else {
			showHudTemporarily()
		}
	}

	function pauseHudAutoHide() {
		isHudPinned = true
		clearHudTimer()
	}

	function resumeHudAutoHide() {
		isHudPinned = false
		if (isHudVisible.value) {
			scheduleHudAutoHide()
		}
	}

	onUnmounted(() => {
		clearHudTimer()
	})

	return {
		isHudVisible,
		clearHudTimer,
		scheduleHudAutoHide,
		showHudTemporarily,
		hideHud,
		toggleHud,
		pauseHudAutoHide,
		resumeHudAutoHide,
	}
}
