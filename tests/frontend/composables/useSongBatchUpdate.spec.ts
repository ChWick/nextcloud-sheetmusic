import { computed, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiPost } from '../../../src/api/client'
import { useSongBatchUpdate } from '../../../src/composables/useSongBatchUpdate'

vi.mock('../../../src/api/client', () => ({
	apiGet: vi.fn().mockResolvedValue({ data: { ocs: { data: { percent: 50, message: 'Working' } } } }),
	apiPost: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

describe('useSongBatchUpdate', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('does nothing when no songs are selected', async () => {
		const onUpdated = vi.fn()
		const onClearSelection = vi.fn()
		const batch = useSongBatchUpdate({
			selectedLibrary: ref(1),
			selectedSongIds: ref([]),
			entityListsProps: computed(() => ({})),
			onUpdated,
			onClearSelection,
		})

		await batch.handleApplyBatchUpdate({})

		expect(apiPost).not.toHaveBeenCalled()
		expect(onUpdated).not.toHaveBeenCalled()
	})

	it('creates new entities, submits batch update, clears selection, and notifies parent', async () => {
		const onUpdated = vi.fn()
		const onClearSelection = vi.fn()
		vi.mocked(apiPost)
			.mockResolvedValueOnce({ data: { ocs: { data: { id: 99 } } } } as any)
			.mockResolvedValueOnce({ data: { ocs: { data: { songs: [] } } } } as any)

		const batch = useSongBatchUpdate({
			selectedLibrary: ref(2),
			selectedSongIds: ref([10, 11]),
			entityListsProps: computed(() => ({ composers: [] })),
			onUpdated,
			onClearSelection,
		})
		batch.isBatchEditorOpen.value = true

		await batch.handleApplyBatchUpdate({
			composers: { action: 'add', ids: [1], newLabels: ['Bach'] },
		})

		expect(apiPost).toHaveBeenNthCalledWith(1, '/api/composers', {
			libraryId: 2,
			name: 'Bach',
		})
		expect(apiPost).toHaveBeenNthCalledWith(
			2,
			'/api/songs-batch-update',
			expect.objectContaining({
				libraryId: 2,
				songIds: [10, 11],
				composers: { action: 'add', ids: [1, 99] },
			}),
		)
		expect(batch.isBatchEditorOpen.value).toBe(false)
		expect(onClearSelection).toHaveBeenCalledTimes(1)
		expect(onUpdated).toHaveBeenCalledWith({ songs: [] })
		expect(showSuccess).toHaveBeenCalled()
		expect(batch.isApplyingBatchUpdate.value).toBe(false)
	})

	it('shows error toast and resets loading state on failure', async () => {
		vi.mocked(apiPost).mockRejectedValueOnce(new Error('Network fail'))
		const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

		const batch = useSongBatchUpdate({
			selectedLibrary: ref(1),
			selectedSongIds: ref([10]),
			entityListsProps: computed(() => ({})),
			onUpdated: vi.fn(),
			onClearSelection: vi.fn(),
		})

		await batch.handleApplyBatchUpdate({})

		expect(showError).toHaveBeenCalled()
		expect(batch.isApplyingBatchUpdate.value).toBe(false)
		consoleSpy.mockRestore()
	})
})
