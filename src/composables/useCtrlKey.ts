import { getCurrentInstance, onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * Tracks whether the Control (or Command/Meta on macOS) key is held down.
 * Used to keep multi-select dropdowns open during multi-selection.
 */
export function useCtrlKey() {
	const isCtrlPressed = ref(false)

	function handleKeyDown(event: KeyboardEvent) {
		if (event.ctrlKey || event.metaKey || event.key === 'Control' || event.key === 'Meta') {
			isCtrlPressed.value = true
		}
	}

	function handleKeyUp(event: KeyboardEvent) {
		if (!event.ctrlKey && !event.metaKey) {
			isCtrlPressed.value = false
		}
	}

	function handlePointerDown(event: MouseEvent | PointerEvent) {
		isCtrlPressed.value = Boolean(event.ctrlKey || event.metaKey)
	}

	function handleWindowBlur() {
		isCtrlPressed.value = false
	}

	if (getCurrentInstance()) {
		onMounted(() => {
			if (typeof window !== 'undefined') {
				window.addEventListener('keydown', handleKeyDown)
				window.addEventListener('keyup', handleKeyUp)
				window.addEventListener('pointerdown', handlePointerDown, true)
				window.addEventListener('blur', handleWindowBlur)
			}
		})

		onBeforeUnmount(() => {
			if (typeof window !== 'undefined') {
				window.removeEventListener('keydown', handleKeyDown)
				window.removeEventListener('keyup', handleKeyUp)
				window.removeEventListener('pointerdown', handlePointerDown, true)
				window.removeEventListener('blur', handleWindowBlur)
			}
		})
	}

	return {
		isCtrlPressed,
		handleKeyDown,
		handleKeyUp,
		handlePointerDown,
		handleWindowBlur,
	}
}
