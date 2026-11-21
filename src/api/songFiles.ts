import { apiPost } from './client'

export interface UploadSongFileOptions {
	pageCount?: number
	pageOrder?: string
}

/**
 * Uploads a new file (PDF, image, etc.) to a song via the Sheet Music OCS API.
 */
export function uploadSongFile(
	libraryId: number,
	songId: number,
	file: File,
	options?: UploadSongFileOptions,
) {
	const formData = new FormData()
	formData.append('file', file)
	if (options?.pageCount !== undefined) {
		formData.append('pageCount', String(options.pageCount))
	}
	if (options?.pageOrder !== undefined) {
		formData.append('pageOrder', options.pageOrder)
	}
	return apiPost('/api/libraries/{libraryId}/songs/{id}/file/upload', formData as any, {
		pathParams: { libraryId, id: songId },
	})
}

/**
 * Replaces an existing song file in-place (triggering Nextcloud file versioning).
 */
export function updateSongFile(
	libraryId: number,
	songId: number,
	fileId: number | string,
	file: File,
) {
	const formData = new FormData()
	formData.append('file', file)
	return (apiPost as any)('/api/libraries/{libraryId}/songs/{id}/file/{fileId}/update', formData, {
		pathParams: { libraryId, id: songId, fileId },
	})
}
