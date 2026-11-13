import type { Song, SongFile } from '../types/song'

/**
 * Builds a deterministic unique version identifier for a song file.
 * Returns e.g. "fileId@version" or "songId:fileId@updatedAt".
 * This identifier is dynamic and only changes when the file or song is actually modified,
 * preventing redundant re-downloads during regular navigation.
 */
export function getFileVersionToken(file?: SongFile | null, song?: Song | null): string {
	if (!file) {
		return ''
	}
	const fileId = file.fileId ?? file.id
	const version = file.updatedAt ?? song?.lastModified ?? song?.updatedAt ?? 1
	return `${fileId}@${version}`
}

/**
 * Builds a versioned file URL for fetching without mutating browser history.
 * Appends a ?v= parameter based on the unique file version token.
 */
export function getVersionedFileUrl(filePath: string, versionToken: string): string {
	if (!filePath) {
		return ''
	}
	if (!versionToken) {
		return filePath
	}
	const separator = filePath.includes('?') ? '&' : '?'
	return `${filePath}${separator}v=${encodeURIComponent(versionToken)}`
}

/**
 * Extracts the version token from a versioned file URL, if present.
 */
export function extractVersionTokenFromUrl(url: string): string | null {
	if (!url || !url.includes('v=')) {
		return null
	}
	try {
		const parsedUrl = new URL(url, 'http://localhost')
		return parsedUrl.searchParams.get('v')
	} catch {
		const match = /[?&]v=([^&#]+)/.exec(url)
		return match ? decodeURIComponent(match[1]) : null
	}
}
