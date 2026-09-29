import type { FileLogEntry } from '../../../src/types/song'

import { generateUrl } from '@nextcloud/router'
import { describe, expect, it, vi } from 'vitest'
import { badgeFor, computeSyncStats, CODE_MESSAGES, displayPath, fileLink, messageFor, relativeFromStored } from '../../../src/utils/file-sync'

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((path: string, params?: Record<string, any>) => {
		if (path === '/f/{fileId}' && params?.fileId) {
			return `/f/${params.fileId}`
		}
		if (path === '/apps/files') {
			return '/apps/files'
		}
		return path
	}),
}))

vi.mock('@nextcloud/l10n', () => ({
	translate: vi.fn((app: string, text: string) => text),
}))

describe('file-sync utility functions', () => {
	it('messageFor returns localized message when code matches', () => {
		const entry: FileLogEntry = {
			songId: 1,
			songTitle: 'Test Song',
			filePath: '/path',
			message: 'Fallback message',
			level: 'info',
			code: 'path-moved',
			fileId: 123,
		}

		expect(messageFor(entry)).toBe(CODE_MESSAGES['path-moved'])
	})

	it('messageFor returns fallback message when code is unknown', () => {
		const entry: FileLogEntry = {
			songId: 1,
			songTitle: 'Test Song',
			filePath: '/path',
			message: 'Fallback message',
			level: 'info',
			code: 'unknown-code',
			fileId: null,
		}

		expect(messageFor(entry)).toBe('Fallback message')
	})

	it('badgeFor returns correct glyphs per level', () => {
		expect(badgeFor('error')).toBe('✖')
		expect(badgeFor('warning')).toBe('⚠')
		expect(badgeFor('info')).toBe('ℹ')
	})

	it('relativeFromStored extracts path below user folder', () => {
		expect(relativeFromStored('/remote.php/dav/files/admin/Noten/Miles/So What.pdf')).toBe('Noten/Miles/So What.pdf')
		expect(relativeFromStored('/invalid/path')).toBeNull()
	})

	it('displayPath returns null for empty string or invalid path', () => {
		const entry1: FileLogEntry = { songId: 0, songTitle: '', filePath: '', message: '', level: 'info', code: 'test', fileId: null }
		expect(displayPath(entry1)).toBeNull()

		const entry2: FileLogEntry = { songId: 1, songTitle: 'Song', filePath: '/remote.php/dav/files/admin/Sheet.pdf', message: '', level: 'info', code: 'test', fileId: 5 }
		expect(displayPath(entry2)).toBe('Sheet.pdf')
	})

	it('fileLink returns direct node URL when fileId > 0', () => {
		const entry: FileLogEntry = {
			songId: 1,
			songTitle: 'Song',
			filePath: '/remote.php/dav/files/admin/Folder/Song.pdf',
			message: '',
			level: 'info',
			code: 'test',
			fileId: 456,
		}

		expect(fileLink(entry)).toBe('/f/456')
		expect(generateUrl).toHaveBeenCalledWith('/f/{fileId}', { fileId: '456' })
	})

	it('fileLink returns folder URL in Files app when fileId is null', () => {
		const entry: FileLogEntry = {
			songId: 1,
			songTitle: 'Song',
			filePath: '/remote.php/dav/files/admin/Noten/Miles/So What.pdf',
			message: '',
			level: 'warning',
			code: 'source-missing',
			fileId: null,
		}

		expect(fileLink(entry)).toBe('/apps/files?dir=%2FNoten%2FMiles')
	})

	it('computeSyncStats aggregates stats accurately', () => {
		const log: FileLogEntry[] = [
			{ songId: 1, songTitle: 'S1', filePath: '/f1.pdf', message: '', level: 'info', code: 'path-moved', fileId: 10 },
			{ songId: 1, songTitle: 'S1', filePath: '/f1.pdf', message: '', level: 'info', code: 'metadata-updated', fileId: 10 },
			{ songId: 2, songTitle: 'S2', filePath: '/f2.pdf', message: '', level: 'warning', code: 'source-missing', fileId: null },
			{ songId: 0, songTitle: '', filePath: '', message: '', level: 'error', code: 'hashcodes-unreadable', fileId: null },
		]

		const stats = computeSyncStats(log)
		expect(stats.files).toBe(2)
		expect(stats.moved).toBe(1)
		expect(stats.updated).toBe(1)
		expect(stats.warnings).toBe(1)
		expect(stats.errors).toBe(1)
	})
})
