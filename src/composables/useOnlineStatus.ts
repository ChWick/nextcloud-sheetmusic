import { onMounted, onUnmounted, ref } from 'vue'

/**
 * Reactive wrapper around navigator.onLine and browser online/offline events.
 */
export function useOnlineStatus() {
	const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true)

	function handleOnline() {
		isOnline.value = true
	}

	function handleOffline() {
		isOnline.value = false
	}

	onMounted(() => {
		if (typeof window === 'undefined') return
		window.addEventListener('online', handleOnline)
		window.addEventListener('offline', handleOffline)
	})

	onUnmounted(() => {
		if (typeof window === 'undefined') return
		window.removeEventListener('online', handleOnline)
		window.removeEventListener('offline', handleOffline)
	})

	return { isOnline }
}
