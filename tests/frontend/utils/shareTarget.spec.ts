import { describe, expect, it, vi } from 'vitest'
import {
	clearShareTargetParam,
	consumePendingShare,
	defaultTitleFromFilename,
	getShareTokenParam,
	hasShareTargetParam,
	isSupportedSharedFile,
} from '../../../src/utils/shareTarget'

const mockAxiosGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...args: unknown[]) => mockAxiosGet(...args),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path: string) => `/index.php${path}`,
}))

describe('shareTarget utils', () => {
	describe('isSupportedSharedFile', () => {
		it('accepts PDF and DOCX files by extension or MIME type', () => {
			expect(isSupportedSharedFile(new File(['a'], 'Score.pdf', { type: 'application/pdf' }))).toBe(true)
			expect(isSupportedSharedFile(new File(['a'], 'Notes.DOCX', { type: '' }))).toBe(true)
			expect(isSupportedSharedFile(new File(['a'], 'document', {
				type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			}))).toBe(true)
		})

		it('rejects unsupported file types', () => {
			expect(isSupportedSharedFile(new File(['a'], 'photo.png', { type: 'image/png' }))).toBe(false)
			expect(isSupportedSharedFile(new File(['a'], 'notes.txt', { type: 'text/plain' }))).toBe(false)
		})
	})

	describe('defaultTitleFromFilename', () => {
		it('strips pdf/docx extension and trims whitespace', () => {
			expect(defaultTitleFromFilename('  Moonlight Sonata.pdf ')).toBe('Moonlight Sonata')
			expect(defaultTitleFromFilename('Choir Notes.DOCX')).toBe('Choir Notes')
			expect(defaultTitleFromFilename('NoExtension')).toBe('NoExtension')
		})
	})

	describe('hasShareTargetParam and clearShareTargetParam', () => {
		it('detects share-target=1 in query string', () => {
			expect(hasShareTargetParam('?share-target=1')).toBe(true)
			expect(hasShareTargetParam('?foo=bar&share-target=1')).toBe(true)
			expect(hasShareTargetParam('?share-target=0')).toBe(false)
			expect(hasShareTargetParam('')).toBe(false)
		})

		it('removes share-target and share-token from window.location without losing hash', () => {
			const token = 'a'.repeat(32)
			window.history.replaceState({}, '', `/index.php/apps/sheetmusic/?share-target=1&share-token=${token}#/library/1/songs`)
			expect(hasShareTargetParam()).toBe(true)
			expect(getShareTokenParam()).toBe(token)

			clearShareTargetParam()
			expect(hasShareTargetParam()).toBe(false)
			expect(getShareTokenParam()).toBeNull()
			expect(window.location.hash).toBe('#/library/1/songs')
		})
	})

	describe('consumePendingShare with server token', () => {
		it('fetches staged shared file from server endpoint and reconstructs File', async () => {
			const token = 'b'.repeat(32)
			mockAxiosGet.mockResolvedValue({
				data: {
					title: 'Shared Score',
					text: '',
					url: '',
					files: [
						{
							name: 'Score.pdf',
							type: 'application/pdf',
							dataBase64: btoa('pdf-data'),
						},
					],
				},
			})

			const payload = await consumePendingShare(token)
			expect(mockAxiosGet).toHaveBeenCalledWith(`/index.php/apps/sheetmusic/share-target/${token}`)
			expect(payload?.title).toBe('Shared Score')
			expect(payload?.files).toHaveLength(1)
			expect(payload?.files[0].name).toBe('Score.pdf')
			expect(payload?.files[0].type).toBe('application/pdf')
		})
	})
})
