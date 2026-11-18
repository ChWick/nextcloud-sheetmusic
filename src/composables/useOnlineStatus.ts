import { computed, onMounted, onUnmounted, ref } from 'vue'

const isBrowserOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
const isSimulatedOffline = ref(false)
let listenerCount = 0

function handleOnline() {
	isBrowserOnline.value = true
}

function handleOffline() {
	isBrowserOnline.value = false
}

/**
 * Returns true when the browser is offline or the user has enabled simulated
 * offline mode to test/verify offline data availability.
 */
export function isEffectivelyOffline(): boolean {
	return (typeof navigator !== 'undefined' && navigator.onLine === false) || isSimulatedOffline.value
}

/**
 * Enables or disables simulated offline mode.
 */
export function setSimulatedOffline(value: boolean): void {
	isSimulatedOffline.value = value
}

/**
 * Reactive wrapper around navigator.onLine, browser online/offline events,
 * and simulated offline mode.
 */
export function useOnlineStatus() {
	const isOnline = computed(() => isBrowserOnline.value && !isSimulatedOffline.value)

	onMounted(() => {
		if (typeof window === 'undefined') return
		if (listenerCount === 0) {
			window.addEventListener('online', handleOnline)
			window.addEventListener('offline', handleOffline)
		}
		listenerCount += 1
	})

	onUnmounted(() => {
		if (typeof window === 'undefined') return
		listenerCount = Math.max(0, listenerCount - 1)
		if (listenerCount === 0) {
			window.removeEventListener('online', handleOnline)
			window.removeEventListener('offline', handleOffline)
		}
	})

	return {
		isOnline,
		isBrowserOnline,
		isSimulatedOffline,
		setSimulatedOffline,
	}
}
