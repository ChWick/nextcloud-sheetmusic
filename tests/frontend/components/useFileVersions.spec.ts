import axios from '@nextcloud/axios'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { useFileVersions } from '../../../src/components/song-pdf/useFileVersions'
import * as dialogs from '@nextcloud/dialogs'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
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
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('fetches versions, sorts by timestamp descending, and updates state', async () => {
		const mockVersions = [
			{ revision: 1, timestamp: 1000, size: 2048 },
			{ revision: 2, timestamp: 3000, size: 4096 },
			{ revision: 3, timestamp: 2000, size: 3072 },
		]

		vi.mocked(axios.get).mockResolvedValueOnce({
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
		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining('/api/libraries/1/songs/10/file/101/versions'),
		)
		expect(fileVersions.value.map((v) => v.revision)).toEqual([2, 3, 1])
	})

	it('fetches versions from a flat array response (controller data response) and normalizes fields', async () => {
		const rawBackendVersions = [
			{ version: '1000', timestamp: 1000, size: 2048, name: 'score.pdf' },
			{ version: '3000', revision: 3000, timestamp: 3000, size: 4096, name: 'score.pdf' },
		]

		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: rawBackendVersions } },
		} as any)

		const { fileVersions, openVersionsModal } = useFileVersions({
			libraryId: 1,
			songId: 10,
			fileId: 101,
		})

		await openVersionsModal()

		expect(fileVersions.value).toEqual([
			{ revision: 3000, timestamp: 3000, size: 4096 },
			{ revision: 1000, timestamp: 1000, size: 2048 },
		])
	})

	it('fetches versions when response is unwrapped directly as an array or nested under data', async () => {
		const rawVersions = [{ version: '500', timestamp: 500, size: 1024 }]

		// Direct data array
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: rawVersions,
		} as any)

		const { fileVersions, openVersionsModal } = useFileVersions({
			libraryId: 1,
			songId: 10,
			fileId: 101,
		})

		await openVersionsModal()
		expect(fileVersions.value).toEqual([{ revision: 500, timestamp: 500, size: 1024 }])

		// Nested data.data
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { data: rawVersions },
		} as any)

		await openVersionsModal()
		expect(fileVersions.value).toEqual([{ revision: 500, timestamp: 500, size: 1024 }])
	})

	it('handles fetch error gracefully and shows error message', async () => {
		vi.mocked(axios.get).mockRejectedValueOnce(new Error('Network error'))

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
		vi.mocked(axios.post).mockResolvedValueOnce({
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
		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/libraries/1/songs/10/file/101/versions/2/revert'),
			{},
		)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Reverted PDF to earlier version successfully.')
		expect(onReverted).toHaveBeenCalledWith(mockLibraryData)
	})

	it('handles revert error gracefully and shows error toast', async () => {
		vi.mocked(axios.post).mockRejectedValueOnce(new Error('Permission denied'))

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
