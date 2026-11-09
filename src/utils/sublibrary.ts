import type { Song } from '../types/song'

const ENTITY_PROP_MAP: Record<string, keyof Song> = {
	instrument: 'instruments',
	instruments: 'instruments',
	composer: 'composers',
	composers: 'composers',
	genre: 'genres',
	genres: 'genres',
	collection: 'collections',
	collections: 'collections',
	artist: 'artists',
	artists: 'artists',
	book: 'books',
	books: 'books',
	customgroup: 'customGroups',
	customgroups: 'customGroups',
	sourcetype: 'sourceTypes',
	sourcetypes: 'sourceTypes',
	key: 'keys',
	keys: 'keys',
	arranger: 'arrangers',
	arrangers: 'arrangers',
	year: 'years',
	years: 'years',
	signature: 'signatures',
	signatures: 'signatures',
}

/**
 * Checks whether a given song satisfies all criteria defined in sublibrary filters.
 */
export function matchesSublibraryFilters(
	filters: Record<string, string[] | string> | undefined | null,
	song: Song,
): boolean {
	if (!filters || Object.keys(filters).length === 0) {
		return true
	}

	for (const [entityType, vals] of Object.entries(filters)) {
		const expectedValues = (Array.isArray(vals) ? vals : [vals])
			.map(v => String(v).toLowerCase().trim())
			.filter(v => v !== '')

		if (expectedValues.length === 0) {
			continue
		}

		const propKey = ENTITY_PROP_MAP[entityType.toLowerCase()] ?? (entityType.endsWith('s') ? entityType : `${entityType}s`)
		const songEntities = (song as any)[propKey] as { name?: string; title?: string }[] | undefined
		if (!songEntities || songEntities.length === 0) {
			return false
		}

		const hasMatch = songEntities.some(ent => {
			const entName = (ent.title || ent.name || '').toLowerCase().trim()
			return expectedValues.includes(entName)
		})

		if (!hasMatch) {
			return false
		}
	}

	return true
}

/**
 * Returns the count of matching songs for a given sublibrary filter,
 * or null if songs list is not loaded yet.
 */
export function countMatchingSongs(
	filters: Record<string, string[] | string> | undefined | null,
	songs: Song[] | { value?: Song[] } | undefined | null,
): number | null {
	const list = Array.isArray(songs)
		? songs
		: (songs && typeof songs === 'object' && 'value' in songs && Array.isArray((songs as any).value) ? (songs as any).value : null)

	if (!list || list.length === 0) {
		return null
	}
	if (!filters || Object.keys(filters).length === 0) {
		return list.length
	}
	return list.filter(song => matchesSublibraryFilters(filters, song)).length
}
