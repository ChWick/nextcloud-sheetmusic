import { computed, ref } from 'vue'

export interface OfflineReturnState {
	libraryId: number
	setlistId: number
	isPlaying: boolean
	itemIndex: number
}

const isBrowserOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)
const isSimulatedOffline = ref(false)
const offlineReturnState = ref<OfflineReturnState | null>(null)

if (typeof window !== 'undefined') {
	window.addEventListener('online', () => {
		isBrowserOnline.value = true
	})
	window.addEventListener('offline', () => {
		isBrowserOnline.value = false
	})
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
 * Stores the current offline setlist/performance state so returning online
 * can restore the same setlist (and active performance song).
 */
export function setOfflineReturnState(state: OfflineReturnState | null): void {
	offlineReturnState.value = state
}

/**
 * Returns and clears the stored offline return state.
 */
export function consumeOfflineReturnState(): OfflineReturnState | null {
	const current = offlineReturnState.value
	offlineReturnState.value = null
	return current
}

/**
 * Reactive wrapper around navigator.onLine, browser online/offline events,
 * and simulated offline mode.
 */
export function useOnlineStatus() {
	const isOnline = computed(() => isBrowserOnline.value && !isSimulatedOffline.value)

	return {
		isOnline,
		isBrowserOnline,
		isSimulatedOffline,
		setSimulatedOffline,
	}
}
