import { onMounted, onUnmounted, type Ref } from 'vue'

/**
 * Calls `onClose` when a document click lands outside `targetRef` while `isOpen` is true.
 */
export function useClickOutside(
	targetRef: Ref<HTMLElement | null>,
	isOpen: Ref<boolean>,
	onClose: () => void = () => {
		isOpen.value = false
	},
) {
	function handleDocumentClick(event: MouseEvent) {
		if (!isOpen.value || !targetRef.value) return
		if (!targetRef.value.contains(event.target as Node)) {
			onClose()
		}
	}

	onMounted(() => {
		document.addEventListener('click', handleDocumentClick)
	})

	onUnmounted(() => {
		document.removeEventListener('click', handleDocumentClick)
	})
}
