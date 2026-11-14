import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import { mount } from '@vue/test-utils'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useCopyToClipboard } from '../../../src/composables/useCopyToClipboard'

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

function mountClipboard(delayMs = 2000) {
	let api!: ReturnType<typeof useCopyToClipboard>
	const Comp = defineComponent({
		setup() {
			api = useCopyToClipboard(delayMs)
			return api
		},
		template: '<div />',
	})
	const wrapper = mount(Comp)
	return { wrapper, ...api }
}

describe('useCopyToClipboard', () => {
	const writeTextMock = vi.fn()

	beforeEach(() => {
		vi.useFakeTimers()
		vi.clearAllMocks()
		writeTextMock.mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText: writeTextMock },
			configurable: true,
		})
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('copies text, updates copiedKey temporarily, and shows success toast', async () => {
		const { wrapper, copiedKey, copy } = mountClipboard(2000)

		const ok = await copy('https://example.com', 'share-1', { successMessage: 'Copied!' })
		expect(ok).toBe(true)
		expect(writeTextMock).toHaveBeenCalledWith('https://example.com')
		expect(copiedKey.value).toBe('share-1')
		expect(showSuccess).toHaveBeenCalledWith('Copied!')

		await vi.advanceTimersByTimeAsync(2000)
		expect(copiedKey.value).toBeNull()
		wrapper.unmount()
	})

	it('handles clipboard failure and shows optional error toast', async () => {
		writeTextMock.mockRejectedValueOnce(new Error('denied'))
		const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
		const { wrapper, copiedKey, copy } = mountClipboard()

		const ok = await copy('secret', 'share-2', { errorMessage: 'Failed!' })
		expect(ok).toBe(false)
		expect(copiedKey.value).toBeNull()
		expect(showError).toHaveBeenCalledWith('Failed!')

		consoleSpy.mockRestore()
		wrapper.unmount()
	})
})
