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

export type SortKey = 'title' | 'composer' | 'book' | 'collection' | 'artist'
export type SortOrder = 'asc' | 'desc'

export function sortSongs(songs: Song[], sortKey: SortKey, sortOrder: SortOrder): Song[] {
	return [...songs].sort((a, b) => {
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

		const cmp = valA.localeCompare(valB, undefined, { sensitivity: 'base', numeric: true })
		return sortOrder === 'asc' ? cmp : -cmp
	})
}
