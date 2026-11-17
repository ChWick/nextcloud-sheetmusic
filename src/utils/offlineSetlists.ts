import { generateOcsUrl } from '@nextcloud/router'
import { apiGet } from '../api/client'
import { fetchSetlist, fetchSetlistFiles, getSetlistFileContentUrl } from '../api/setlists'
import type { Song } from '../types/song'
import { getFileVersionToken, getVersionedFileUrl } from './file-version'
import { isStandaloneDisplayMode } from './pwa'

export const OFFLINE_SETLIST_CACHE = 'sheetmusic-offline-setlists-v1'
export const OFFLINE_STORAGE_KEY = 'sheetmusic_offline_setlists_v1'

export interface OfflineSetlistRecord {
	libraryId: number
	setlistId: number
	syncedAt: number
	urls: string[]
	fileCount: number
}

function makeKey(libraryId: number, setlistId: number): string {
	return `${libraryId}:${setlistId}`
}

/**
 * Offline setlist syncing is only offered when running as an installed PWA
 * with Cache Storage and Service Worker support.
 */
export function isOfflineSetlistsSupported(): boolean {
	return isStandaloneDisplayMode()
		&& typeof caches !== 'undefined'
		&& typeof navigator !== 'undefined'
		&& 'serviceWorker' in navigator
}

export function getOfflineSetlistMap(): Record<string, OfflineSetlistRecord> {
	if (typeof localStorage === 'undefined') {
		return {}
	}
	try {
		const raw = localStorage.getItem(OFFLINE_STORAGE_KEY)
		if (!raw) return {}
		const parsed = JSON.parse(raw)
		return parsed && typeof parsed === 'object' ? parsed : {}
	} catch {
		return {}
	}
}

function saveOfflineSetlistMap(map: Record<string, OfflineSetlistRecord>): void {
	if (typeof localStorage === 'undefined') {
		return
	}
	localStorage.setItem(OFFLINE_STORAGE_KEY, JSON.stringify(map))
}

export function isSetlistAvailableOffline(libraryId: number, setlistId: number): boolean {
	const map = getOfflineSetlistMap()
	return Boolean(map[makeKey(libraryId, setlistId)])
}

function isVersionedBinaryUrl(url: string): boolean {
	return (url.includes('/remote.php/dav/') || (url.includes('/files/') && url.endsWith('/content') || url.includes('/content?v=')))
		&& url.includes('v=')
}

async function cacheUrl(cache: Cache, url: string): Promise<void> {
	// Skip re-downloading immutable versioned PDFs/files that are already cached
	if (isVersionedBinaryUrl(url)) {
		const existing = await cache.match(url)
		if (existing) {
			return
		}
	}

	const isOcs = url.includes('/ocs/v2.php/')
	const response = await fetch(url, {
		credentials: 'include',
		headers: isOcs
			? { 'OCS-APIREQUEST': 'true', Accept: 'application/json' }
			: undefined,
	})
	if (!response.ok) {
		throw new Error(`Failed to cache ${url}: HTTP ${response.status}`)
	}
	await cache.put(url, response)
}

export async function syncSetlistOffline(params: {
	libraryId: number
	setlistId: number
	songs: Song[]
	onProgress?: (completed: number, total: number) => void
}): Promise<OfflineSetlistRecord> {
	const { libraryId, setlistId, songs, onProgress } = params

	const [setlistDetail, setlistFiles] = await Promise.all([
		fetchSetlist(libraryId, setlistId),
		fetchSetlistFiles(libraryId, setlistId),
	])

	const urls = new Set<string>()

	if (typeof window !== 'undefined' && window.location?.pathname) {
		urls.add(window.location.pathname)
	}
	urls.add(generateOcsUrl('/apps/sheetmusic/api/libraries'))
	urls.add(`${generateOcsUrl('/apps/sheetmusic/api/songs')}?id=${libraryId}`)
	urls.add(generateOcsUrl(`/apps/sheetmusic/api/libraries/${libraryId}/setlists`))
	urls.add(generateOcsUrl(`/apps/sheetmusic/api/libraries/${libraryId}/setlists/${setlistId}`))
	urls.add(generateOcsUrl(`/apps/sheetmusic/api/libraries/${libraryId}/setlists/${setlistId}/files`))

	const songById = new Map<number, Song>()
	for (const song of songs) {
		songById.set(song.id, song)
	}

	let fileCount = 0
	const seenSongIds = new Set<number>()
	for (const item of setlistDetail.items ?? []) {
		if (seenSongIds.has(item.songId)) continue
		seenSongIds.add(item.songId)
		const song = songById.get(item.songId)
		if (!song) continue

		const files = song.files ?? []
		if (files.length === 0) {
			urls.add(generateOcsUrl(`/apps/sheetmusic/api/songs/${song.id}/annotations`))
			continue
		}

		for (const file of files) {
			if (file.path) {
				urls.add(getVersionedFileUrl(file.path, getFileVersionToken(file, song)))
				fileCount++
			}
			const annBase = generateOcsUrl(`/apps/sheetmusic/api/songs/${song.id}/annotations`)
			urls.add(file.id ? `${annBase}?fileId=${file.id}` : annBase)
		}
	}

	for (const sf of setlistFiles) {
		urls.add(getSetlistFileContentUrl(libraryId, setlistId, sf.id, sf.updatedAt))
		urls.add(generateOcsUrl(`/apps/sheetmusic/api/libraries/${libraryId}/setlists/${setlistId}/files/${sf.id}/annotations`))
		fileCount++
	}

	const urlList = Array.from(urls)
	const cache = await caches.open(OFFLINE_SETLIST_CACHE)

	let completed = 0
	onProgress?.(0, urlList.length)
	for (const url of urlList) {
		await cacheUrl(cache, url)
		completed++
		onProgress?.(completed, urlList.length)
	}

	const record: OfflineSetlistRecord = {
		libraryId,
		setlistId,
		syncedAt: Date.now(),
		urls: urlList,
		fileCount,
	}

	const map = getOfflineSetlistMap()
	const previous = map[makeKey(libraryId, setlistId)]
	map[makeKey(libraryId, setlistId)] = record
	saveOfflineSetlistMap(map)

	if (previous) {
		const retainedUrls = collectRetainedUrls(map)
		for (const oldUrl of previous.urls) {
			if (!retainedUrls.has(oldUrl)) {
				await cache.delete(oldUrl)
			}
		}
	}

	return record
}

function collectRetainedUrls(map: Record<string, OfflineSetlistRecord>): Set<string> {
	const retainedUrls = new Set<string>()
	for (const record of Object.values(map)) {
		for (const url of record.urls) {
			retainedUrls.add(url)
		}
	}
	return retainedUrls
}

export async function removeSetlistOffline(libraryId: number, setlistId: number): Promise<void> {
	const map = getOfflineSetlistMap()
	const key = makeKey(libraryId, setlistId)
	const target = map[key]
	if (!target) {
		return
	}

	delete map[key]
	saveOfflineSetlistMap(map)

	if (typeof caches === 'undefined') {
		return
	}

	const retainedUrls = collectRetainedUrls(map)

	const cache = await caches.open(OFFLINE_SETLIST_CACHE)
	for (const url of target.urls) {
		if (!retainedUrls.has(url)) {
			await cache.delete(url)
		}
	}
}

let activeBackgroundSync: Promise<void> | null = null

/**
 * Refreshes all offline-pinned setlists in the background (skipping unchanged
 * versioned PDFs) when online. Safe to call repeatedly; concurrent calls share
 * the same in-flight run.
 */
export async function syncAllOfflineSetlists(): Promise<void> {
	if (!isOfflineSetlistsSupported()) {
		return
	}
	if (typeof navigator !== 'undefined' && navigator.onLine === false) {
		return
	}
	if (activeBackgroundSync) {
		return activeBackgroundSync
	}

	activeBackgroundSync = (async () => {
		const entries = Object.values(getOfflineSetlistMap())
		if (entries.length === 0) {
			return
		}

		const byLibrary = new Map<number, OfflineSetlistRecord[]>()
		for (const entry of entries) {
			const list = byLibrary.get(entry.libraryId) ?? []
			list.push(entry)
			byLibrary.set(entry.libraryId, list)
		}

		for (const [libraryId, records] of byLibrary.entries()) {
			try {
				const res = await apiGet('/api/songs', { queryParams: { id: libraryId } })
				const songs = (res.data.ocs.data.songs ?? []) as Song[]
				for (const record of records) {
					try {
						await syncSetlistOffline({
							libraryId,
							setlistId: record.setlistId,
							songs,
						})
					} catch {
						// Ignore individual setlist failures during background sync
					}
				}
			} catch {
				// Ignore library fetch failures when offline/unreachable
			}
		}
	})().finally(() => {
		activeBackgroundSync = null
	})

	return activeBackgroundSync
}

const AUTO_SYNC_INTERVAL_MS = 5 * 60 * 1000

/**
 * Runs an initial background sync of offline setlists and keeps them fresh when
 * the app regains connectivity or returns to the foreground.
 */
export function initOfflineSetlistsAutoSync(): () => void {
	if (!isOfflineSetlistsSupported() || typeof window === 'undefined') {
		return () => {}
	}

	let lastSyncAt = Date.now()
	void syncAllOfflineSetlists()

	const onOnline = () => {
		lastSyncAt = Date.now()
		void syncAllOfflineSetlists()
	}

	const onVisibilityChange = () => {
		if (document.visibilityState === 'visible' && Date.now() - lastSyncAt >= AUTO_SYNC_INTERVAL_MS) {
			lastSyncAt = Date.now()
			void syncAllOfflineSetlists()
		}
	}

	window.addEventListener('online', onOnline)
	document.addEventListener('visibilitychange', onVisibilityChange)

	return () => {
		window.removeEventListener('online', onOnline)
		document.removeEventListener('visibilitychange', onVisibilityChange)
	}
}
