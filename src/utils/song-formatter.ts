import type { Song } from '../types/song'

function formatNamedEntities<T>(
	items?: T[] | null,
	getName: (item: T) => string = (item: any) => item?.name ?? '',
	fallback = '—',
): string {
	if (!items || items.length === 0) return fallback
	const names = items.map(getName).filter(Boolean)
	return names.length > 0 ? names.join(', ') : fallback
}

export function formatComposers(song: Song, fallback = '—'): string {
	return formatNamedEntities(song.composers, c => c.name, fallback)
}

export function formatBooks(song: Song, fallback = '—'): string {
	return formatNamedEntities(song.books, b => b.title, fallback)
}

export function formatCollections(song: Song, fallback = '—'): string {
	return formatNamedEntities(song.collections, c => c.name, fallback)
}

export function formatArtists(song: Song, fallback = '—'): string {
	return formatNamedEntities(song.artists, a => a.name, fallback)
}

export function formatGenres(song: Song, fallback = '—'): string {
	return formatNamedEntities(song.genres, g => g.name, fallback)
}

export function formatKeys(song: Song, fallback = '—'): string {
	return formatNamedEntities(song.keys, k => k.name, fallback)
}

export function formatCustomGroups(song: Song, fallback = '—'): string {
	return formatNamedEntities(song.customGroups, cg => cg.name, fallback)
}

export function formatYears(song: Song, fallback = '—'): string {
	return formatNamedEntities(song.years, y => y.name, fallback)
}

export function formatSignatures(song: Song, fallback = '—'): string {
	return formatNamedEntities(song.signatures, s => s.name, fallback)
}

export function formatSourceTypes(song: Song, fallback = '—'): string {
	return formatNamedEntities(song.sourceTypes, st => st.name, fallback)
}

export function formatInstruments(song: Song, fallback = '—'): string {
	return formatNamedEntities(song.instruments, i => i.name, fallback)
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
		const ms = timestamp < 100000000000 ? timestamp * 1000 : timestamp
		return new Date(ms).toLocaleString(undefined, {
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

export type SortKey = 'title' | 'composer' | 'book' | 'collection' | 'artist' | 'genre' | 'key' | 'customGroup' | 'year' | 'signature' | 'sourceType' | 'instrument' | 'opus' | 'catalogNumber' | 'songNumber' | 'stars' | 'difficulty' | 'duration' | 'keywords' | 'custom' | 'custom2' | 'lastModified' | 'dateCreated'
export type SortOrder = 'asc' | 'desc'

const NUMERIC_SORT_KEYS = new Set<SortKey>(['lastModified', 'dateCreated', 'stars', 'difficulty', 'duration'])

const ENTITY_FORMATTERS: Partial<Record<SortKey, (song: Song) => string>> = {
	composer: a => formatComposers(a, ''),
	book: a => formatBooks(a, ''),
	collection: a => formatCollections(a, ''),
	artist: a => formatArtists(a, ''),
	genre: a => formatGenres(a, ''),
	key: a => formatKeys(a, ''),
	customGroup: a => formatCustomGroups(a, ''),
	year: a => formatYears(a, ''),
	signature: a => formatSignatures(a, ''),
	sourceType: a => formatSourceTypes(a, ''),
	instrument: a => formatInstruments(a, ''),
}

export function sortSongs(songs: Song[], sortKey: SortKey, sortOrder: SortOrder): Song[] {
	return [...songs].sort((a, b) => {
		let cmp = 0

		if (NUMERIC_SORT_KEYS.has(sortKey)) {
			const valA = ((a as any)[sortKey] as number | undefined) ?? 0
			const valB = ((b as any)[sortKey] as number | undefined) ?? 0
			cmp = valA - valB
		} else {
			const formatter = ENTITY_FORMATTERS[sortKey]
			const valA = formatter ? formatter(a) : String((a as any)[sortKey] ?? '')
			const valB = formatter ? formatter(b) : String((b as any)[sortKey] ?? '')

			cmp = valA.localeCompare(valB, undefined, { sensitivity: 'base', numeric: true })
		}

		return sortOrder === 'asc' ? cmp : -cmp
	})
}
