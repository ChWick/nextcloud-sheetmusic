import type { FileLogEntry, FileLogLevel } from '../types/song'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** Sort order of the levels, so a filter keeps everything at or above it. */
export const LEVEL_ORDER: Record<FileLogLevel, number> = {
	info: 0,
	warning: 1,
	error: 2,
}

/**
 * The localized wording of every outcome code the sync tool reports.
 */
export const CODE_MESSAGES: Record<string, string> = {
	'path-moved': t('mobilesheetsmanager', 'File moved to its naming-rule path'),
	'path-current': t('mobilesheetsmanager', 'File is already at its naming-rule path'),
	'no-naming-rule': t('mobilesheetsmanager', 'The song has no composer, artist or collection, so the file keeps its name'),
	'source-outside-library': t('mobilesheetsmanager', 'The stored path does not point into your files'),
	'source-missing': t('mobilesheetsmanager', 'The file recorded in the library database does not exist'),
	'path-persist-failed': t('mobilesheetsmanager', 'The library database rejected the move; the file was moved back'),
	'path-failed': t('mobilesheetsmanager', 'The file could not be moved'),
	'metadata-updated': t('mobilesheetsmanager', 'Document properties updated'),
	'metadata-current': t('mobilesheetsmanager', 'Document properties are already up to date'),
	'not-a-pdf': t('mobilesheetsmanager', 'Not a PDF - nothing to update'),
	'metadata-file-missing': t('mobilesheetsmanager', 'The file does not exist, so its document properties could not be checked'),
	'metadata-not-a-file': t('mobilesheetsmanager', 'The recorded path is not a file'),
	'metadata-unreadable': t('mobilesheetsmanager', 'The file could not be read, so its document properties could not be checked'),
	'metadata-write-failed': t('mobilesheetsmanager', 'The document properties could not be written'),
	'metadata-facts-failed': t('mobilesheetsmanager', 'The document properties were written, but the library database was not refreshed'),
	'metadata-failed': t('mobilesheetsmanager', 'The document properties could not be updated'),
	'hashcodes-unreadable': t('mobilesheetsmanager', 'The MobileSheets hash codes file could not be read'),
	'hashcodes-save-failed': t('mobilesheetsmanager', 'The MobileSheets hash codes file could not be saved'),
}

/** The wording of an entry: its code localized, its message as a fallback. */
export function messageFor(entry: FileLogEntry): string {
	return CODE_MESSAGES[entry.code] ?? entry.message
}

/** The glyph shown in front of an entry, matching its level's color. */
export function badgeFor(level: FileLogLevel): string {
	return level === 'error' ? '✖' : level === 'warning' ? '⚠' : 'ℹ'
}

/** The stored WebDAV path as it reads below the user folder, or null. */
export function relativeFromStored(filePath: string): string | null {
	const match = /^\/remote\.php\/dav\/files\/[^/]+\/(.+)$/.exec(filePath)
	return match ? match[1] : null
}

/** The entry's file path shown short, without the WebDAV prefix. */
export function displayPath(entry: FileLogEntry): string | null {
	return entry.filePath === '' ? null : relativeFromStored(entry.filePath)
}

/**
 * The Files app link for an entry: the file itself when its node id is known,
 * its folder otherwise - which covers files that do not exist (yet), where the
 * folder is exactly where the user has to look.
 */
export function fileLink(entry: FileLogEntry): string | null {
	if (entry.fileId !== null && entry.fileId > 0) {
		return generateUrl('/f/{fileId}', { fileId: String(entry.fileId) })
	}

	const relative = relativeFromStored(entry.filePath)
	if (relative === null) {
		return null
	}

	const separator = relative.lastIndexOf('/')
	const directory = separator === -1 ? '/' : `/${relative.slice(0, separator)}`

	return generateUrl('/apps/files') + '?dir=' + encodeURIComponent(directory)
}

export interface SyncStats {
	files: number
	moved: number
	updated: number
	current: number
	warnings: number
	errors: number
}

/** Computes report summary numbers from log entries. */
export function computeSyncStats(log: FileLogEntry[]): SyncStats {
	const files = new Set(
		log.filter((entry) => entry.songId > 0).map((entry) => `${entry.songId}|${entry.filePath}`),
	).size
	const byLevel = (level: FileLogLevel) => log.filter((entry) => entry.level === level).length
	const byCode = (codes: string[]) => log.filter((entry) => codes.includes(entry.code)).length

	return {
		files,
		moved: byCode(['path-moved']),
		updated: byCode(['metadata-updated']),
		current: byCode(['path-current', 'metadata-current']),
		warnings: byLevel('warning'),
		errors: byLevel('error'),
	}
}
