import { describe, expect, it } from 'vitest'
import type { Song, SongFile } from '../../../src/types/song'
import {
	extractVersionTokenFromUrl,
	getFileVersionToken,
	getVersionedFileUrl,
} from '../../../src/utils/file-version'

describe('file-version utils', () => {
	describe('getFileVersionToken', () => {
		it('returns empty string when file is null or undefined', () => {
			expect(getFileVersionToken(null)).toBe('')
			expect(getFileVersionToken(undefined)).toBe('')
		})

		it('uses file.updatedAt when available', () => {
			const file: SongFile = {
				id: 42,
				songId: 10,
				fileId: 105,
				path: '/test.pdf',
				orderIndex: 0,
				updatedAt: 1710000000000,
			}
			expect(getFileVersionToken(file)).toBe('105@1710000000000')
		})

		it('falls back to song.lastModified when file.updatedAt is missing', () => {
			const file: SongFile = {
				id: 42,
				songId: 10,
				fileId: 105,
				path: '/test.pdf',
				orderIndex: 0,
			}
			const song = {
				id: 10,
				title: 'Test Song',
				lastModified: 1690000000000,
			} as unknown as Song
			expect(getFileVersionToken(file, song)).toBe('105@1690000000000')
		})

		it('falls back to song.updatedAt when lastModified is missing', () => {
			const file: SongFile = {
				id: 42,
				songId: 10,
				path: '/test.pdf',
				orderIndex: 0,
			}
			const song = {
				id: 10,
				title: 'Test Song',
				updatedAt: 1680000000000,
			} as unknown as Song
			expect(getFileVersionToken(file, song)).toBe('42@1680000000000')
		})

		it('falls back to 1 when no timestamps are available', () => {
			const file: SongFile = {
				id: 42,
				songId: 10,
				path: '/test.pdf',
				orderIndex: 0,
			}
			expect(getFileVersionToken(file)).toBe('42@1')
		})
	})

	describe('getVersionedFileUrl', () => {
		it('returns empty string if filePath is empty', () => {
			expect(getVersionedFileUrl('', '10@123')).toBe('')
		})

		it('returns filePath unchanged if versionToken is empty', () => {
			expect(getVersionedFileUrl('/path/to/doc.pdf', '')).toBe('/path/to/doc.pdf')
		})

		it('appends ?v= to simple file path', () => {
			expect(getVersionedFileUrl('/path/to/doc.pdf', '10@123')).toBe('/path/to/doc.pdf?v=10%40123')
		})

		it('appends &v= if file path already contains query parameters', () => {
			expect(getVersionedFileUrl('/path/to/doc.pdf?dl=1', '10@123')).toBe('/path/to/doc.pdf?dl=1&v=10%40123')
		})
	})

	describe('extractVersionTokenFromUrl', () => {
		it('returns null for empty or non-versioned URL', () => {
			expect(extractVersionTokenFromUrl('')).toBeNull()
			expect(extractVersionTokenFromUrl('/path/to/doc.pdf')).toBeNull()
			expect(extractVersionTokenFromUrl('/path/to/doc.pdf?download=1')).toBeNull()
		})

		it('extracts token from URL with ?v= parameter', () => {
			expect(extractVersionTokenFromUrl('/path/to/doc.pdf?v=10%40123')).toBe('10@123')
		})

		it('extracts token from URL with multiple parameters', () => {
			expect(extractVersionTokenFromUrl('/path/to/doc.pdf?download=1&v=abc%20123&other=true')).toBe('abc 123')
		})
	})
})
