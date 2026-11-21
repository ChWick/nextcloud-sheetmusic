import { describe, expect, it, vi } from 'vitest'
import { formatFileSize, getCacheBustedFileUrl, getFileName, isImageFile, isPdfFile, openFileExternally, pickPrimarySongFile } from '../../../src/utils/file'

describe('file utils', () => {
	describe('getFileName', () => {
		it('extracts filename from absolute path', () => {
			expect(getFileName('/remote.php/dav/files/admin/Noten/Beethoven - Symphony 5.pdf')).toBe('Beethoven - Symphony 5.pdf')
		})

		it('returns original path if no slashes exist', () => {
			expect(getFileName('SongFile.pdf')).toBe('SongFile.pdf')
		})

		it('handles empty string gracefully', () => {
			expect(getFileName('')).toBe('')
		})

		it('strips query parameters from path', () => {
			expect(getFileName('/path/to/Song.pdf?t=12345678')).toBe('Song.pdf')
		})

		it('decodes URI encoded characters in filename', () => {
			expect(getFileName('/path/to/My%20Song%20%281%29.pdf')).toBe('My Song (1).pdf')
		})
	})

	describe('openFileExternally', () => {
		it('calls window.open with the file path and target blank', () => {
			const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
			openFileExternally('/path/to/file.pdf')
			expect(openSpy).toHaveBeenCalledWith('/path/to/file.pdf', '_blank', 'noopener,noreferrer')
			openSpy.mockRestore()
		})
	})

	describe('getCacheBustedFileUrl', () => {
		it('appends timestamp query parameter to path', () => {
			const url = getCacheBustedFileUrl('/path/to/file.pdf', 12345)
			expect(url).toBe('/path/to/file.pdf?t=12345')
		})

		it('appends with ampersand if url already has query parameters', () => {
			const url = getCacheBustedFileUrl('/path/to/file.pdf?download=1', 'v2')
			expect(url).toBe('/path/to/file.pdf?download=1&t=v2')
		})

		it('handles empty string gracefully', () => {
			expect(getCacheBustedFileUrl('')).toBe('')
		})
	})

	describe('isPdfFile', () => {
		it('matches .pdf extensions with or without query parameters and mime types', () => {
			expect(isPdfFile('/dav/files/admin/Score.PDF')).toBe(true)
			expect(isPdfFile('/dav/files/admin/Score.pdf?v=123')).toBe(true)
			expect(isPdfFile('unnamed', 'application/pdf')).toBe(true)
			expect(isPdfFile('/dav/files/admin/Notes.docx')).toBe(false)
			expect(isPdfFile(null)).toBe(false)
		})
	})

	describe('isImageFile', () => {
		it('matches supported image extensions with or without query parameters', () => {
			expect(isImageFile('/dav/files/admin/cover.PNG')).toBe(true)
			expect(isImageFile('/dav/files/admin/page.jpg?v=2')).toBe(true)
			expect(isImageFile('/dav/files/admin/diagram.svg')).toBe(true)
			expect(isImageFile('/dav/files/admin/score.pdf')).toBe(false)
			expect(isImageFile(undefined)).toBe(false)
		})
	})

	describe('formatFileSize', () => {
		it('formats bytes, kilobytes, and megabytes', () => {
			expect(formatFileSize(500)).toBe('500 B')
			expect(formatFileSize(1536)).toBe('1.5 KB')
			expect(formatFileSize(2621440)).toBe('2.5 MB')
		})
	})

	describe('pickPrimarySongFile', () => {
		it('prefers matching fileId, then PDF, then primary, then first', () => {
			const files = [
				{ id: 1, path: '/img.png', isPrimary: false },
				{ id: 2, path: '/score.pdf', isPrimary: false },
				{ id: 3, path: '/notes.txt', isPrimary: true },
			] as any[]
			expect(pickPrimarySongFile(files, 3)?.id).toBe(3)
			expect(pickPrimarySongFile(files)?.id).toBe(2)
			expect(pickPrimarySongFile([files[0], files[2]])?.id).toBe(3)
			expect(pickPrimarySongFile([files[0]])?.id).toBe(1)
			expect(pickPrimarySongFile([])).toBeNull()
		})
	})
})

