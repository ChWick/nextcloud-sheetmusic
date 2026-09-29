import { describe, expect, it } from 'vitest'
import { getFileName } from '../../../src/utils/file'

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
