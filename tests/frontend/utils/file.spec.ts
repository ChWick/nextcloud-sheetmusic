import { describe, expect, it, vi } from 'vitest'
import { getCacheBustedFileUrl, getFileName, openFileExternally } from '../../../src/utils/file'

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
})

