import type { Artist, Book, Collection, Composer, EntityType, FilterEntity, Song } from '../types/song'

import { computed, ref } from 'vue'
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router'

/** The entries the filters can select; they resolve the ids stored in the URL. */
export interface FilterOptions {
	composers: Composer[]
	books: Book[]
	collections: Collection[]
	artists: Artist[]
}

const FILTER_TYPES: EntityType[] = ['composers', 'artists', 'books', 'collections']

/** Whether the filter bar is shown; the choice is remembered across views. */
const filterBarVisible = ref(true)

function toggleFilterBar(): void {
	filterBarVisible.value = !filterBarVisible.value
}

/**
 * The state of the song filter bar, read from and written to the URL query
 * ("…?composers=3&books=7"), so the browser history restores it: going back undoes
 * a filter change or a view change, going forward redoes it, and a URL without a
 * query shows the unfiltered view. The query stores only ids; the given options
 * resolve them to the entries for display and matching. A URL always names the
 * library it belongs to, so its ids can never be read against another library.
 */
export function useSongFilters(options: () => FilterOptions) {
	const route = useRoute()
	const router = useRouter()

	/** The ids one filter parameter selects, e.g. "composers=3,7" or repeated params. */
	function idsOf(entityType: EntityType): number[] {
		const raw = route.query[entityType]
		const values = (Array.isArray(raw) ? raw : [raw]).filter(value => value !== null && value !== undefined)
		const ids = values
			.flatMap(value => String(value).split(','))
			.map(Number)
			.filter(id => Number.isInteger(id) && id > 0)
		return [...new Set(ids)]
	}

	/** The entries behind the ids of one filter parameter, in the order of the URL. */
	function selectedOf(entityType: EntityType): FilterEntity[] {
		const entries = options()[entityType] as FilterEntity[]
		return idsOf(entityType).map(id => entries.find(entry => entry.id === id) ?? { id } as FilterEntity)
	}

	/** Replace one filter parameter; dropping every id removes it from the URL. */
	function selectIds(entityType: EntityType, ids: number[]): void {
		const query: LocationQueryRaw = { ...route.query }
		if (ids.length === 0) {
			delete query[entityType]
		} else {
			query[entityType] = ids.join(',')
		}
		router.push({ query })
	}

	const hasActiveFilters = computed(() => FILTER_TYPES.some(entityType => idsOf(entityType).length > 0))

	/** Whether the song carries every active filter selection. */
	function songMatchesFilters(song: Song): boolean {
		const composerIds = new Set(idsOf('composers'))
		const bookIds = new Set(idsOf('books'))
		const collectionIds = new Set(idsOf('collections'))
		const artistIds = new Set(idsOf('artists'))

		if (composerIds.size && !(song.composers?.some(composer => composerIds.has(composer.id)))) return false
		if (bookIds.size && !(song.books?.some(book => bookIds.has(book.id)))) return false
		if (collectionIds.size && !(song.collections?.some(collection => collectionIds.has(collection.id)))) return false
		if (artistIds.size && !(song.artists?.some(artist => artistIds.has(artist.id)))) return false
		return true
	}

	/** The songs that carry every active filter selection. */
	function filterSongs(songs: Song[]): Song[] {
		return songs.filter(songMatchesFilters)
	}

	/** Drop every selection in one history entry, leaving the unfiltered view. */
	function clearFilters(): void {
		const query: LocationQueryRaw = { ...route.query }
		for (const entityType of FILTER_TYPES) {
			delete query[entityType]
		}
		router.push({ query })
	}

	const selectedComposers = computed({
		get: () => selectedOf('composers') as Composer[],
		set: (value: Composer[]) => selectIds('composers', value.map(entry => entry.id)),
	})
	const selectedBooks = computed({
		get: () => selectedOf('books') as Book[],
		set: (value: Book[]) => selectIds('books', value.map(entry => entry.id)),
	})
	const selectedCollections = computed({
		get: () => selectedOf('collections') as Collection[],
		set: (value: Collection[]) => selectIds('collections', value.map(entry => entry.id)),
	})
	const selectedArtists = computed({
		get: () => selectedOf('artists') as Artist[],
		set: (value: Artist[]) => selectIds('artists', value.map(entry => entry.id)),
	})

	return {
		selectedComposers,
		selectedBooks,
		selectedCollections,
		selectedArtists,
		hasActiveFilters,
		filterSongs,
		clearFilters,
		filterBarVisible,
		toggleFilterBar,
	}
}