import axios from '@nextcloud/axios'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { useSongImport } from '../../../src/composables/useSongImport'

vi.mock('@nextcloud/axios', () => ({
	default: {
		post: vi.fn(),
	},
}))

describe('useSongImport composable', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('initializes with default empty state', () => {
		const { importPrefill, importedFile, importedNextcloudNode } = useSongImport()

		expect(importPrefill.value).toBeNull()
		expect(importedFile.value).toBeNull()
		expect(importedNextcloudNode.value).toBeNull()
	})

	it('clears import state on clearImportState', () => {
		const { importPrefill, importedFile, importedNextcloudNode, clearImportState } = useSongImport()

		importPrefill.value = { title: 'Test' } as any
		importedFile.value = new File([''], 'test.pdf')
		importedNextcloudNode.value = { path: '/path', name: 'test.pdf' }

		clearImportState()

		expect(importPrefill.value).toBeNull()
		expect(importedFile.value).toBeNull()
		expect(importedNextcloudNode.value).toBeNull()
	})

	it('parses file metadata and notifies onSongReady when handling imported file', async () => {
		vi.mocked(axios.post).mockResolvedValueOnce({
			data: { ocs: { data: { title: 'Imported Title', composers: ['Bach'] } } },
		} as any)

		const onSongReady = vi.fn()
		const { importPrefill, importedFile, handleImportFile } = useSongImport({
			onSongReady,
		})

		const file = new File(['dummy-pdf'], 'score.pdf', { type: 'application/pdf' })
		const event = {
			target: {
				files: [file],
				value: 'score.pdf',
			},
		} as unknown as Event

		await handleImportFile(event)

		expect(importedFile.value?.name).toBe('score.pdf')
		expect(importPrefill.value).toEqual({ title: 'Imported Title', composers: ['Bach'] })
		expect(onSongReady).toHaveBeenCalled()
	})
})
