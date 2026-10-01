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

export function formatDuration(seconds?: number | null, fallback = '—'): string {
	if (!seconds || seconds <= 0) return fallback
	const mins = Math.floor(seconds / 60)
	const secs = seconds % 60
	return `${mins}:${secs.toString().padStart(2, '0')}`
}

export function formatStars(stars?: number | null, fallback = '—'): string {
	if (!stars || stars <= 0) return fallback
	return '★'.repeat(Math.min(5, stars)) + '☆'.repeat(Math.max(0, 5 - stars))
}

export function formatDifficulty(difficulty?: number | null, fallback = '—'): string {
	if (!difficulty || difficulty <= 0) return fallback
	return `${difficulty}/5`
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

export type SortKey = 'title' | 'composer' | 'book' | 'collection' | 'artist' | 'stars' | 'difficulty' | 'duration' | 'keywords' | 'custom' | 'custom2' | 'lastModified' | 'dateCreated'
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
		} else if (sortKey === 'stars') {
			const valA = a.stars ?? 0
			const valB = b.stars ?? 0
			cmp = valA - valB
		} else if (sortKey === 'difficulty') {
			const valA = a.difficulty ?? 0
			const valB = b.difficulty ?? 0
			cmp = valA - valB
		} else if (sortKey === 'duration') {
			const valA = a.duration ?? 0
			const valB = b.duration ?? 0
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
				case 'keywords':
					valA = a.keywords ?? ''
					valB = b.keywords ?? ''
					break
				case 'custom':
					valA = a.custom ?? ''
					valB = b.custom ?? ''
					break
				case 'custom2':
					valA = a.custom2 ?? ''
					valB = b.custom2 ?? ''
					break
			}

			cmp = valA.localeCompare(valB, undefined, { sensitivity: 'base', numeric: true })
		}

		return sortOrder === 'asc' ? cmp : -cmp
	})
}
