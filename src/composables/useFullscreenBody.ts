import { onMounted, onUnmounted, ref } from 'vue'

/**
 * Manages fullscreen state, `document.body` CSS class, and `fullscreenchange` syncing
 * for full-screen editor/viewer views, with optional handoff across view transitions.
 */
export function useFullscreenBody(bodyClass: string, initialFullscreen?: () => boolean) {
	const isFullscreen = ref(false)
	let keepFullscreenOnUnmount = false

	function syncBodyClass(active: boolean) {
		if (typeof document !== 'undefined') {
			document.body.classList.toggle(bodyClass, active)
		}
	}

	async function toggleFullscreen() {
		const next = !isFullscreen.value
		isFullscreen.value = next
		syncBodyClass(next)
		try {
			if (next && !document.fullscreenElement && document.documentElement.requestFullscreen) {
				await document.documentElement.requestFullscreen()
			} else if (!next && document.fullscreenElement && document.exitFullscreen) {
				await document.exitFullscreen()
			}
		} catch {
			// Fullscreen API may be blocked by browser policy; CSS fullscreen still applies
		}
	}

	function handleFullscreenChange() {
		const active = Boolean(document.fullscreenElement)
		isFullscreen.value = active
		syncBodyClass(active)
	}

	function preserveFullscreenOnUnmount() {
		keepFullscreenOnUnmount = true
	}

	onMounted(() => {
		document.addEventListener('fullscreenchange', handleFullscreenChange)
		if (initialFullscreen?.() || (typeof document !== 'undefined' && Boolean(document.fullscreenElement))) {
			isFullscreen.value = true
			syncBodyClass(true)
		}
	})

	onUnmounted(() => {
		document.removeEventListener('fullscreenchange', handleFullscreenChange)
		syncBodyClass(false)
		if (!keepFullscreenOnUnmount && typeof document !== 'undefined' && document.fullscreenElement && document.exitFullscreen) {
			document.exitFullscreen().catch(() => {})
		}
	})

	return {
		isFullscreen,
		toggleFullscreen,
		preserveFullscreenOnUnmount,
	}
}
