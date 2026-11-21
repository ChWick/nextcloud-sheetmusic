import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useShareTargetImport } from '../../../src/composables/useShareTargetImport'

const mockShowSuccess = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (...args: unknown[]) => mockShowSuccess(...args),
}))

const sampleFile = new File(['%PDF-1.4'], 'Score.pdf', { type: 'application/pdf' })

describe('useShareTargetImport', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		vi.mocked(axios.delete).mockResolvedValue({ data: { ocs: { data: {} } } } as any)
	})

	it('rolls back newly created song if file upload fails', async () => {
		vi.mocked(axios.post).mockImplementation((url: string) => {
			if (url.endsWith('/api/songs')) {
				return Promise.resolve({ data: { ocs: { data: { createdSongId: 88 } } } } as any)
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
		expect(axios.delete).toHaveBeenCalledWith(
			expect.stringContaining('/api/songs/88'),
			expect.objectContaining({ params: { libraryId: 1 } }),
		)
	})

	it('does not delete an existing song if file upload fails', async () => {
		vi.mocked(axios.post).mockRejectedValue(new Error('Upload failed'))

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
		expect(axios.delete).not.toHaveBeenCalled()
	})

	it('rolls back newly created setlist if file upload fails', async () => {
		vi.mocked(axios.post).mockImplementation((url: string) => {
			if (url.endsWith('/api/setlists')) {
				return Promise.resolve({ data: { ocs: { data: { id: 77, name: 'Gala' } } } } as any)
			}
			return Promise.reject(new Error('Unsupported file'))
		})

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
		expect(axios.delete).toHaveBeenCalledWith(
			expect.stringContaining('/api/setlists/77'),
			expect.objectContaining({ params: { libraryId: 1 } }),
		)
	})
})
