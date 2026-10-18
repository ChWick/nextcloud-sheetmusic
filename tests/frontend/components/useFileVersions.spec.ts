import { describe, expect, it, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { useFileVersions } from '../../../src/components/song-pdf/useFileVersions'
import * as client from '../../../src/api/client'
import * as dialogs from '@nextcloud/dialogs'

vi.mock('../../../src/api/client', () => ({
	apiGet: vi.fn(),
	apiPost: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

describe('useFileVersions.ts', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('initializes with default closed state and empty versions', () => {
		const { isVersionsModalOpen, fileVersions, isLoadingVersions, isRevertingVersion } = useFileVersions({
			libraryId: 1,
			songId: 10,
			fileId: 101,
		})

		expect(isVersionsModalOpen.value).toBe(false)
		expect(fileVersions.value).toEqual([])
		expect(isLoadingVersions.value).toBe(false)
		expect(isRevertingVersion.value).toBe(false)
	})

	it('does not fetch versions if libraryId, songId or fileId is missing', async () => {
		const libraryId = ref<number | undefined>(undefined)
		const { openVersionsModal } = useFileVersions({
			libraryId,
			songId: 10,
			fileId: 101,
		})

		await openVersionsModal()
		expect(client.apiGet).not.toHaveBeenCalled()
	})

	it('fetches versions, sorts by timestamp descending, and updates state', async () => {
		const mockVersions = [
			{ revision: 1, timestamp: 1000, size: 2048 },
			{ revision: 2, timestamp: 3000, size: 4096 },
			{ revision: 3, timestamp: 2000, size: 3072 },
		]

		vi.mocked(client.apiGet).mockResolvedValueOnce({
			data: { ocs: { data: { versions: mockVersions } } },
		} as any)

		const { isVersionsModalOpen, fileVersions, isLoadingVersions, openVersionsModal } = useFileVersions({
			libraryId: 1,
			songId: 10,
			fileId: 101,
		})

		const promise = openVersionsModal()
		expect(isVersionsModalOpen.value).toBe(true)
		expect(isLoadingVersions.value).toBe(true)

		await promise

		expect(isLoadingVersions.value).toBe(false)
		expect(client.apiGet).toHaveBeenCalledWith(
			'/api/libraries/{libraryId}/songs/{id}/file/{fileId}/versions',
			{
				pathParams: {
					libraryId: 1,
					id: 10,
					fileId: 101,
				},
			},
		)
		expect(fileVersions.value.map((v) => v.revision)).toEqual([2, 3, 1])
	})

	it('handles fetch error gracefully and shows error message', async () => {
		vi.mocked(client.apiGet).mockRejectedValueOnce(new Error('Network error'))

		const { isVersionsModalOpen, isLoadingVersions, openVersionsModal } = useFileVersions({
			libraryId: 1,
			songId: 10,
			fileId: 101,
		})

		await openVersionsModal()

		expect(isVersionsModalOpen.value).toBe(true)
		expect(isLoadingVersions.value).toBe(false)
		expect(dialogs.showError).toHaveBeenCalledWith('Failed to load file versions.')
	})

	it('reverts version successfully, calls onReverted callback and shows success toast', async () => {
		const mockLibraryData = { songs: [{ id: 10, title: 'Updated Song' }] }
		vi.mocked(client.apiPost).mockResolvedValueOnce({
			data: { ocs: { data: mockLibraryData } },
		} as any)

		const onReverted = vi.fn()
		const { isVersionsModalOpen, isRevertingVersion, revertToVersion } = useFileVersions({
			libraryId: 1,
			songId: 10,
			fileId: 101,
			onReverted,
		})

		isVersionsModalOpen.value = true
		const promise = revertToVersion(2)
		expect(isRevertingVersion.value).toBe(true)

		await promise

		expect(isRevertingVersion.value).toBe(false)
		expect(isVersionsModalOpen.value).toBe(false)
		expect(client.apiPost).toHaveBeenCalledWith(
			'/api/libraries/{libraryId}/songs/{id}/file/{fileId}/versions/{revision}/revert',
			{},
			{
				pathParams: {
					libraryId: 1,
					id: 10,
					fileId: 101,
					revision: 2,
				},
			},
		)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Reverted PDF to earlier version successfully.')
		expect(onReverted).toHaveBeenCalledWith(mockLibraryData)
	})

	it('handles revert error gracefully and shows error toast', async () => {
		vi.mocked(client.apiPost).mockRejectedValueOnce(new Error('Permission denied'))

		const onReverted = vi.fn()
		const { isVersionsModalOpen, isRevertingVersion, revertToVersion } = useFileVersions({
			libraryId: 1,
			songId: 10,
			fileId: 101,
			onReverted,
		})

		isVersionsModalOpen.value = true
		await revertToVersion(3)

		expect(isRevertingVersion.value).toBe(false)
		expect(isVersionsModalOpen.value).toBe(true)
		expect(onReverted).not.toHaveBeenCalled()
		expect(dialogs.showError).toHaveBeenCalledWith(expect.stringContaining('Permission denied'))
	})
})
