import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import type { Setlist, SetlistItem } from '../types/song'

export async function fetchSetlists(libraryId: number): Promise<Setlist[]> {
	const url = generateOcsUrl('/apps/sheetmusic/api/libraries/' + libraryId + '/setlists')
	const response = await axios.get(url)
	return response.data?.ocs?.data ?? []
}

export async function fetchSetlist(libraryId: number, setlistId: number): Promise<Setlist> {
	const url = generateOcsUrl('/apps/sheetmusic/api/libraries/' + libraryId + '/setlists/' + setlistId)
	const response = await axios.get(url)
	return response.data?.ocs?.data
}

export async function createSetlist(libraryId: number, name: string): Promise<Setlist> {
	const url = generateOcsUrl('/apps/sheetmusic/api/setlists')
	const response = await axios.post(url, { libraryId, name })
	return response.data?.ocs?.data
}

export async function updateSetlist(
	libraryId: number,
	setlistId: number,
	data: { name?: string; sortBy?: number; ascending?: number },
): Promise<Setlist> {
	const url = generateOcsUrl('/apps/sheetmusic/api/setlists/' + setlistId)
	const response = await axios.post(url, { libraryId, ...data })
	return response.data?.ocs?.data
}

export async function deleteSetlist(libraryId: number, setlistId: number): Promise<void> {
	const url = generateOcsUrl('/apps/sheetmusic/api/setlists/' + setlistId)
	await axios.delete(url, { params: { libraryId } })
}

export async function addSetlistItem(
	libraryId: number,
	setlistId: number,
	songId: number,
	bookmarkId?: number | null,
	position?: number | null,
): Promise<SetlistItem> {
	const url = generateOcsUrl('/apps/sheetmusic/api/setlists/' + setlistId + '/items')
	const response = await axios.post(url, {
		libraryId,
		songId,
		bookmarkId: bookmarkId ?? null,
		position: position ?? null,
	})
	return response.data?.ocs?.data
}

export async function updateSetlistItem(
	libraryId: number,
	setlistId: number,
	itemId: number,
	data: { bookmarkId?: number | null },
): Promise<SetlistItem> {
	const url = generateOcsUrl('/apps/sheetmusic/api/setlists/' + setlistId + '/items/' + itemId)
	const response = await axios.post(url, {
		libraryId,
		...data,
	})
	return response.data?.ocs?.data
}

export async function removeSetlistItem(
	libraryId: number,
	setlistId: number,
	itemId: number,
): Promise<void> {
	const url = generateOcsUrl('/apps/sheetmusic/api/setlists/' + setlistId + '/items/' + itemId)
	await axios.delete(url, { params: { libraryId } })
}

export async function reorderSetlistItems(
	libraryId: number,
	setlistId: number,
	itemIds: number[],
): Promise<void> {
	const url = generateOcsUrl('/apps/sheetmusic/api/setlists/' + setlistId + '/reorder')
	await axios.post(url, {
		libraryId,
		itemIds,
	})
}
