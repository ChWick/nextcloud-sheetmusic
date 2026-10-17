import { mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import BatchDeleteSongsDialog from '../../../src/components/BatchDeleteSongsDialog.vue'
import * as client from '../../../src/api/client'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string, vars?: Record<string, any>) => {
		if (!vars) return text
		let res = text
		for (const [k, v] of Object.entries(vars)) {
			res = res.replace(`{${k}}`, String(v))
		}
		return res
	},
	getLanguage: () => 'en',
	isRTL: () => false,
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

describe('BatchDeleteSongsDialog.vue', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
		document.body.innerHTML = ''
	})

	it('renders dialog with count of selected songs', () => {
		mount(BatchDeleteSongsDialog, {
			props: {
				open: true,
				libraryId: 1,
				songIds: [10, 20, 30],
			},
			attachTo: document.body,
		})

		expect(document.body.textContent).toContain('Delete selected songs')
		expect(document.body.textContent).toContain('Delete 3 selected songs from this library?')
		expect(document.body.textContent).toContain('Delete all attached files')
	})

	it('submits batch delete request with deleteFiles true by default', async () => {
		const postSpy = vi.spyOn(client, 'apiPost').mockResolvedValue({
			data: { ocs: { data: { songs: [] } } },
		} as any)

		const wrapper = mount(BatchDeleteSongsDialog, {
			props: {
				open: true,
				libraryId: 1,
				songIds: [10, 20],
			},
			attachTo: document.body,
		})

		const deleteButton = Array.from(document.querySelectorAll('button')).find(
			b => b.textContent?.includes('Delete 2 songs'),
		)
		expect(deleteButton).toBeDefined()
		await deleteButton?.click()

		expect(postSpy).toHaveBeenCalledWith('/api/songs-batch-delete', {
			libraryId: 1,
			songIds: [10, 20],
			deleteFiles: true,
		})

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('deleted')).toEqual([[{ songs: [] }, 2]])
	})

	it('emits close when cancel is clicked', async () => {
		const wrapper = mount(BatchDeleteSongsDialog, {
			props: {
				open: true,
				libraryId: 1,
				songIds: [10],
			},
			attachTo: document.body,
		})

		const cancelButton = Array.from(document.querySelectorAll('button')).find(
			b => b.textContent?.includes('Cancel'),
		)
		expect(cancelButton).toBeDefined()
		await cancelButton?.click()

		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})

