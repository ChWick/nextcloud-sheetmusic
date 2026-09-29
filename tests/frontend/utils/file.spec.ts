import { describe, expect, it, vi } from 'vitest'
import { getFileName, openFileExternally } from '../../../src/utils/file'

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
	})

	describe('openFileExternally', () => {
		it('calls window.open with the file path and target blank', () => {
			const openSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
			openFileExternally('/path/to/file.pdf')
			expect(openSpy).toHaveBeenCalledWith('/path/to/file.pdf', '_blank', 'noopener,noreferrer')
			openSpy.mockRestore()
		})
	})
})
