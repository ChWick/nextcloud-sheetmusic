import { showError, showSuccess } from '@nextcloud/dialogs'
import { onBeforeUnmount, ref, type Ref } from 'vue'

export interface CopyToClipboardOptions {
	resetDelayMs?: number
	successMessage?: string
	errorMessage?: string
}

export interface UseCopyToClipboardReturn {
	copiedKey: Ref<string | null>
	copy: (text: string, key: string, options?: CopyToClipboardOptions) => Promise<boolean>
}

/**
 * Copy text to the clipboard, track a temporary copied-state key for UI feedback,
 * and optionally display localized Nextcloud toast notifications.
 */
export function useCopyToClipboard(defaultDelayMs = 2500): UseCopyToClipboardReturn {
	const copiedKey = ref<string | null>(null)
	let resetTimer: ReturnType<typeof setTimeout> | null = null

	function clearResetTimer() {
		if (resetTimer !== null) {
			clearTimeout(resetTimer)
			resetTimer = null
		}
	}

	async function copy(text: string, key: string, options: CopyToClipboardOptions = {}): Promise<boolean> {
		const {
			resetDelayMs = defaultDelayMs,
			successMessage,
			errorMessage,
		} = options

		try {
			await navigator.clipboard.writeText(text)
			copiedKey.value = key
			if (successMessage) {
				showSuccess(successMessage)
			}
			clearResetTimer()
			resetTimer = setTimeout(() => {
				if (copiedKey.value === key) {
					copiedKey.value = null
				}
				resetTimer = null
			}, resetDelayMs)
			return true
		} catch (error) {
			console.error('Failed to copy to clipboard:', error)
			if (errorMessage) {
				showError(errorMessage)
			}
			return false
		}
	}

	onBeforeUnmount(clearResetTimer)

	return {
		copiedKey,
		copy,
	}
}
