import { onMounted, onUnmounted } from 'vue'

interface WakeLockSentinelLike {
	release: () => Promise<void>
}

interface NavigatorWithWakeLock extends Navigator {
	wakeLock?: {
		request: (type: 'screen') => Promise<WakeLockSentinelLike>
	}
}

/**
 * Acquires a Screen Wake Lock while mounted and re-acquires when the tab becomes visible.
 */
export function useWakeLock() {
	let sentinel: WakeLockSentinelLike | null = null

	async function requestWakeLock() {
		try {
			const nav = navigator as NavigatorWithWakeLock
			if (nav.wakeLock) {
				sentinel = await nav.wakeLock.request('screen')
			}
		} catch {
			// Wake lock not supported or denied
		}
	}

	function handleVisibilityChange() {
		if (document.visibilityState === 'visible') {
			requestWakeLock()
		}
	}

	onMounted(() => {
		document.addEventListener('visibilitychange', handleVisibilityChange)
		requestWakeLock()
	})

	onUnmounted(() => {
		document.removeEventListener('visibilitychange', handleVisibilityChange)
		if (sentinel) {
			sentinel.release().catch(() => {})
			sentinel = null
		}
	})

	return {
		requestWakeLock,
	}
}
