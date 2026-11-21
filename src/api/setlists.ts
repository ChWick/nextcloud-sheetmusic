import type { Setlist, SetlistFile, SetlistItem } from '../types/song'
import { ocsDelete, ocsGet, ocsPost, ocsUrl } from './ocs'

export async function fetchSetlists(libraryId: number): Promise<Setlist[]> {
	return (await ocsGet<Setlist[]>(`/api/libraries/${libraryId}/setlists`)) ?? []
}

export async function fetchSetlist(libraryId: number, setlistId: number): Promise<Setlist> {
	return (await ocsGet<Setlist>(`/api/libraries/${libraryId}/setlists/${setlistId}`)) as Setlist
}

export async function createSetlist(libraryId: number, name: string): Promise<Setlist> {
	return (await ocsPost<Setlist>('/api/setlists', { libraryId, name })) as Setlist
}

export async function updateSetlist(
	libraryId: number,
	setlistId: number,
	data: { name?: string; sortBy?: number; ascending?: number },
): Promise<Setlist> {
	return (await ocsPost<Setlist>(`/api/setlists/${setlistId}`, { libraryId, ...data })) as Setlist
}

export async function deleteSetlist(libraryId: number, setlistId: number): Promise<void> {
	await ocsDelete(`/api/setlists/${setlistId}`, { params: { libraryId } })
}

export async function addSetlistItem(
	libraryId: number,
	setlistId: number,
	songId: number,
	bookmarkId?: number | null,
	position?: number | null,
): Promise<SetlistItem> {
	return (await ocsPost<SetlistItem>(`/api/setlists/${setlistId}/items`, {
		libraryId,
		songId,
		bookmarkId: bookmarkId ?? null,
		position: position ?? null,
	})) as SetlistItem
}

export async function updateSetlistItem(
	libraryId: number,
	setlistId: number,
	itemId: number,
	data: { bookmarkId?: number | null },
): Promise<SetlistItem> {
	return (await ocsPost<SetlistItem>(`/api/setlists/${setlistId}/items/${itemId}`, {
		libraryId,
		...data,
	})) as SetlistItem
}

export async function removeSetlistItem(
	libraryId: number,
	setlistId: number,
	itemId: number,
): Promise<void> {
	await ocsDelete(`/api/setlists/${setlistId}/items/${itemId}`, { params: { libraryId } })
}

export async function reorderSetlistItems(
	libraryId: number,
	setlistId: number,
	itemIds: number[],
): Promise<void> {
	await ocsPost(`/api/setlists/${setlistId}/reorder`, { libraryId, itemIds })
}

export async function fetchSetlistFiles(libraryId: number, setlistId: number): Promise<SetlistFile[]> {
	return (await ocsGet<SetlistFile[]>(`/api/libraries/${libraryId}/setlists/${setlistId}/files`)) ?? []
}

export async function uploadSetlistFile(
	libraryId: number,
	setlistId: number,
	file: File,
): Promise<SetlistFile> {
	const formData = new FormData()
	formData.append('file', file)
	return (await ocsPost<SetlistFile>(
		`/api/libraries/${libraryId}/setlists/${setlistId}/files`,
		formData,
		{ headers: { 'Content-Type': 'multipart/form-data' } },
	)) as SetlistFile
}

export async function attachNextcloudSetlistFile(
	libraryId: number,
	setlistId: number,
	sourcePath: string,
	action: 'copy' | 'move' = 'copy',
): Promise<SetlistFile> {
	return (await ocsPost<SetlistFile>(
		`/api/libraries/${libraryId}/setlists/${setlistId}/files/attach`,
		{ sourcePath, action },
	)) as SetlistFile
}

export async function deleteSetlistFile(
	libraryId: number,
	setlistId: number,
	fileId: number,
): Promise<void> {
	await ocsDelete(`/api/libraries/${libraryId}/setlists/${setlistId}/files/${fileId}`)
}

export function getSetlistFileContentUrl(
	libraryId: number,
	setlistId: number,
	fileId: number,
	version?: number,
): string {
	const base = ocsUrl(`/api/libraries/${libraryId}/setlists/${setlistId}/files/${fileId}/content`)
	return version !== undefined ? `${base}?v=${version}` : base
}
