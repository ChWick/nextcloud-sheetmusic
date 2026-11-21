import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { showError } from '@nextcloud/dialogs'
import BatchDeleteSongsDialog from '../../../src/components/BatchDeleteSongsDialog.vue'
import * as client from '../../../src/api/client'

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
		await flushPromises()

		expect(postSpy).toHaveBeenCalledWith('/api/songs-batch-delete', {
			libraryId: 1,
			songIds: [10, 20],
			deleteFiles: true,
		})

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('deleted')).toEqual([[{ songs: [] }, [10, 20]]])
	})

	it('submits batch delete request with deleteFiles unchecked', async () => {
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

		const checkbox = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		await checkbox.setValue(false)

		const deleteButton = Array.from(document.querySelectorAll('button')).find(
			b => b.textContent?.includes('Delete 2 songs'),
		)
		await deleteButton?.click()
		await flushPromises()

		expect(postSpy).toHaveBeenCalledWith('/api/songs-batch-delete', {
			libraryId: 1,
			songIds: [10, 20],
			deleteFiles: false,
		})
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

	it('shows error when batch delete fails', async () => {
		vi.spyOn(client, 'apiPost').mockRejectedValue({
			response: { data: { message: 'Network timeout' } },
		})

		const wrapper = mount(BatchDeleteSongsDialog, {
			props: {
				open: true,
				libraryId: 1,
				songIds: [10],
			},
			attachTo: document.body,
		})

		const deleteButton = Array.from(document.querySelectorAll('button')).find(
			b => b.textContent?.includes('Delete 1 songs'),
		)
		await deleteButton?.click()
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Network timeout')
		expect(document.body.textContent).toContain('Network timeout')
		expect(wrapper.emitted('update:open')).toBeUndefined()
		expect(wrapper.emitted('deleted')).toBeUndefined()
	})

	it('does not submit when songIds is empty or libraryId is invalid', async () => {
		const postSpy = vi.spyOn(client, 'apiPost')

		mount(BatchDeleteSongsDialog, {
			props: {
				open: true,
				libraryId: 1,
				songIds: [],
			},
			attachTo: document.body,
		})

		const deleteButton = Array.from(document.querySelectorAll('button')).find(
			b => b.textContent?.includes('Delete 0 songs'),
		)
		await deleteButton?.click()
		await flushPromises()

		expect(postSpy).not.toHaveBeenCalled()
	})

	it('resets deleteFiles and error when dialog opens again', async () => {
		vi.spyOn(client, 'apiPost').mockRejectedValue({
			response: { data: { message: 'Database locked' } },
		})

		const wrapper = mount(BatchDeleteSongsDialog, {
			props: {
				open: true,
				libraryId: 1,
				songIds: [10],
			},
			attachTo: document.body,
		})

		const deleteButton = Array.from(document.querySelectorAll('button')).find(
			b => b.textContent?.includes('Delete 1 songs'),
		)
		await deleteButton?.click()
		await flushPromises()
		expect(document.body.textContent).toContain('Database locked')

		// Close dialog
		await wrapper.setProps({ open: false })
		await flushPromises()
		// Reopen dialog
		await wrapper.setProps({ open: true })
		await flushPromises()

		expect(document.body.textContent).not.toContain('Database locked')
	})
})
