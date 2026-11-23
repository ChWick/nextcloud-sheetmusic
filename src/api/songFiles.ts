import { apiPost } from './client'

export interface UploadSongFileOptions {
	pageCount?: number
	pageOrder?: string
}

function buildSongFileFormData(file: File, options?: UploadSongFileOptions): FormData {
	const formData = new FormData()
	formData.append('file', file)
	if (options?.pageCount !== undefined) {
		formData.append('pageCount', String(options.pageCount))
	}
	if (options?.pageOrder !== undefined) {
		formData.append('pageOrder', options.pageOrder)
	}
	return formData
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
	const formData = buildSongFileFormData(file, options)
	return apiPost('/api/libraries/{libraryId}/songs/{id}/file/upload', formData as any, {
		pathParams: { libraryId, id: songId },
	})
}

export interface MergeSongFilesOptions extends UploadSongFileOptions {
	deleteFileIds?: Array<number | string>
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
	const formData = buildSongFileFormData(file)
	return (apiPost as any)('/api/libraries/{libraryId}/songs/{id}/file/{fileId}/update', formData, {
		pathParams: { libraryId, id: songId, fileId },
	})
}

/**
 * Saves a merged PDF for a song, optionally deletes the merged source PDFs,
 * and recomputes all song file paths according to library naming rules.
 */
export function mergeSongFiles(
	libraryId: number,
	songId: number,
	file: File,
	options?: MergeSongFilesOptions,
) {
	const formData = buildSongFileFormData(file, options)
	if (options?.deleteFileIds && options.deleteFileIds.length > 0) {
		formData.append('deleteFileIds', JSON.stringify(options.deleteFileIds.map((id) => Number(id))))
	}
	return (apiPost as any)('/api/libraries/{libraryId}/songs/{id}/file/merge', formData, {
		pathParams: { libraryId, id: songId },
	})
}
