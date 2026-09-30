import type { Song } from '../types/song'

export function formatComposers(song: Song, fallback = '—'): string {
	if (!song.composers || song.composers.length === 0) return fallback
	return song.composers.map(c => c.name).join(', ')
}

export function formatBooks(song: Song, fallback = '—'): string {
	if (!song.books || song.books.length === 0) return fallback
	return song.books.map(b => b.title).join(', ')
}

export function formatCollections(song: Song, fallback = '—'): string {
	if (!song.collections || song.collections.length === 0) return fallback
	return song.collections.map(c => c.name).join(', ')
}

export function formatArtists(song: Song, fallback = '—'): string {
	if (!song.artists || song.artists.length === 0) return fallback
	return song.artists.map(a => a.name).join(', ')
}

export function formatDateTimestamp(timestamp?: number | null, fallback = '—'): string {
	if (!timestamp || timestamp <= 0) return fallback
	try {
		return new Date(timestamp).toLocaleString(undefined, {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		})
	} catch {
		return fallback
	}
}

export type SortKey = 'title' | 'composer' | 'book' | 'collection' | 'artist' | 'lastModified' | 'dateCreated'
export type SortOrder = 'asc' | 'desc'

export function sortSongs(songs: Song[], sortKey: SortKey, sortOrder: SortOrder): Song[] {
	return [...songs].sort((a, b) => {
		let cmp = 0

		if (sortKey === 'lastModified') {
			const valA = a.lastModified ?? 0
			const valB = b.lastModified ?? 0
			cmp = valA - valB
		} else if (sortKey === 'dateCreated') {
			const valA = a.dateCreated ?? 0
			const valB = b.dateCreated ?? 0
			cmp = valA - valB
		} else {
			let valA = ''
			let valB = ''

			switch (sortKey) {
				case 'title':
					valA = a.title ?? ''
					valB = b.title ?? ''
					break
				case 'composer':
					valA = formatComposers(a, '')
					valB = formatComposers(b, '')
					break
				case 'book':
					valA = formatBooks(a, '')
					valB = formatBooks(b, '')
					break
				case 'collection':
					valA = formatCollections(a, '')
					valB = formatCollections(b, '')
					break
				case 'artist':
					valA = formatArtists(a, '')
					valB = formatArtists(b, '')
					break
			}

			cmp = valA.localeCompare(valB, undefined, { sensitivity: 'base', numeric: true })
		}

		return sortOrder === 'asc' ? cmp : -cmp
	})
}
