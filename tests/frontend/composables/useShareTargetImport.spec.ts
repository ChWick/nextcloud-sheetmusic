import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useShareTargetImport } from '../../../src/composables/useShareTargetImport'

const mockApiGet = vi.fn()
const mockApiPost = vi.fn()
const mockApiDelete = vi.fn()
const mockCreateSetlist = vi.fn()
const mockDeleteSetlist = vi.fn()
const mockFetchSetlists = vi.fn()
const mockUploadSetlistFile = vi.fn()
const mockShowSuccess = vi.fn()

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...args: unknown[]) => mockShowSuccess(...args),
}))

vi.mock('../../../src/api/client', () => ({
	apiGet: (...args: unknown[]) => mockApiGet(...args),
	apiPost: (...args: unknown[]) => mockApiPost(...args),
	apiDelete: (...args: unknown[]) => mockApiDelete(...args),
}))

vi.mock('../../../src/api/setlists', () => ({
	createSetlist: (...args: unknown[]) => mockCreateSetlist(...args),
	deleteSetlist: (...args: unknown[]) => mockDeleteSetlist(...args),
	fetchSetlists: (...args: unknown[]) => mockFetchSetlists(...args),
	uploadSetlistFile: (...args: unknown[]) => mockUploadSetlistFile(...args),
}))

const sampleFile = new File(['%PDF-1.4'], 'Score.pdf', { type: 'application/pdf' })

describe('useShareTargetImport', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockApiDelete.mockResolvedValue({})
		mockDeleteSetlist.mockResolvedValue(undefined)
	})

	it('rolls back newly created song if file upload fails', async () => {
		mockApiPost.mockImplementation((path: string) => {
			if (path === '/api/songs') {
				return Promise.resolve({ data: { ocs: { data: { createdSongId: 88 } } } })
			}
			return Promise.reject({ response: { data: { ocs: { meta: { message: 'File already exists' } } } } })
		})

		const { executeImport, errorMessage } = useShareTargetImport()
		const result = await executeImport({
			mode: 'new-song',
			libraryId: 1,
			files: [sampleFile],
			newSongTitle: 'Score',
			newSetlistName: '',
			songId: -1,
			setlistId: -1,
		})

		expect(result).toBeNull()
		expect(errorMessage.value).toBe('File already exists')
		expect(mockApiDelete).toHaveBeenCalledWith('/api/songs/{id}', {
			pathParams: { id: 88 },
			queryParams: { libraryId: 1 },
		})
	})

	it('does not delete an existing song if file upload fails', async () => {
		mockApiPost.mockRejectedValue(new Error('Upload failed'))

		const { executeImport, errorMessage } = useShareTargetImport()
		const result = await executeImport({
			mode: 'existing-song',
			libraryId: 1,
			files: [sampleFile],
			newSongTitle: '',
			newSetlistName: '',
			songId: 42,
			setlistId: -1,
		})

		expect(result).toBeNull()
		expect(errorMessage.value).toBe('Upload failed')
		expect(mockApiDelete).not.toHaveBeenCalled()
	})

	it('rolls back newly created setlist if file upload fails', async () => {
		mockCreateSetlist.mockResolvedValue({ id: 77, name: 'Gala' })
		mockUploadSetlistFile.mockRejectedValue(new Error('Unsupported file'))

		const { executeImport, errorMessage } = useShareTargetImport()
		const result = await executeImport({
			mode: 'new-setlist',
			libraryId: 1,
			files: [sampleFile],
			newSongTitle: '',
			newSetlistName: 'Gala',
			songId: -1,
			setlistId: -1,
		})

		expect(result).toBeNull()
		expect(errorMessage.value).toBe('Unsupported file')
		expect(mockDeleteSetlist).toHaveBeenCalledWith(1, 77)
	})
})
