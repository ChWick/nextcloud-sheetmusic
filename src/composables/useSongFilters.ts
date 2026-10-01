import type { Artist, Book, Collection, Composer, EntityType, FilterEntity, Genre, Song } from '../types/song'
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router'

/** The entries the filters can select; they resolve the ids stored in the URL. */
export interface FilterOptions {
	composers: Composer[]
	books: Book[]
	collections: Collection[]
	artists: Artist[]
	genres: Genre[]
}

const FILTER_TYPES: EntityType[] = ['composers', 'artists', 'books', 'collections', 'genres']

export const UNASSIGNED_ID = -1

/** The special filter entry for songs without an assigned entity in that category. */
export function noneEntryOf(entityType: EntityType): FilterEntity {
	switch (entityType) {
		case 'composers':
			return { id: UNASSIGNED_ID, name: t('mobilesheetsmanager', '(No composer)') }
		case 'books':
			return { id: UNASSIGNED_ID, title: t('mobilesheetsmanager', '(No book)') }
		case 'collections':
			return { id: UNASSIGNED_ID, name: t('mobilesheetsmanager', '(No collection)') }
		case 'artists':
			return { id: UNASSIGNED_ID, name: t('mobilesheetsmanager', '(No artist)') }
		case 'genres':
			return { id: UNASSIGNED_ID, name: t('mobilesheetsmanager', '(No genre)') }
	}
}

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

	function titleFilterOf(): string {
		const raw = route.query.title ?? route.query.search ?? ''
		const value = Array.isArray(raw) ? raw[0] : raw
		return typeof value === 'string' ? value : ''
	}

	const searchTitle = computed({
		get: () => titleFilterOf(),
		set: (val: string) => {
			const query: LocationQueryRaw = { ...route.query }
			const trimmed = val ? val.trim() : ''
			if (!trimmed) {
				delete query.title
				delete query.search
			} else {
				query.title = val
			}
			router.replace({ query })
		},
	})

	/** The ids one filter parameter selects, e.g. "composers=3,7" or "composers=-1". */
	function idsOf(entityType: EntityType): number[] {
		const raw = route.query[entityType]
		const values = (Array.isArray(raw) ? raw : [raw]).filter(value => value !== null && value !== undefined)
		const ids = values
			.flatMap(value => String(value).split(','))
			.map(Number)
			.filter(id => Number.isInteger(id) && id !== 0)
		return [...new Set(ids)]
	}

	function isFiltered(entityType: EntityType, id: number): boolean {
		return idsOf(entityType).includes(id)
	}

	function toggleFilter(entityType: EntityType, entity: FilterEntity): void {
		const currentIds = idsOf(entityType)
		if (currentIds.includes(entity.id)) {
			selectIds(entityType, currentIds.filter(i => i !== entity.id))
		} else {
			selectIds(entityType, [...currentIds, entity.id])
		}
	}

	/** The entries behind the ids of one filter parameter, in the order of the URL. */
	function selectedOf(entityType: EntityType): FilterEntity[] {
		const entries = (options()[entityType] ?? []) as FilterEntity[]
		const noneEntry = noneEntryOf(entityType)
		return idsOf(entityType).map(id => {
			if (id === UNASSIGNED_ID) {
				return noneEntry
			}
			return entries.find(entry => entry.id === id) ?? ({ id } as FilterEntity)
		})
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

	const hasActiveFilters = computed(() =>
		Boolean(titleFilterOf().trim()) || FILTER_TYPES.some(entityType => idsOf(entityType).length > 0)
	)

	function matchesCategory<T extends { id: number }>(songItems: T[] | undefined, selectedIds: Set<number>): boolean {
		if (selectedIds.size === 0) return true
		const hasUnassignedSelected = selectedIds.has(UNASSIGNED_ID)
		const isUnassigned = !songItems || songItems.length === 0
		if (hasUnassignedSelected && isUnassigned) return true
		if (songItems && songItems.some(item => selectedIds.has(item.id))) return true
		return false
	}

	/** Whether the song carries every active filter selection. */
	function songMatchesFilters(song: Song): boolean {
		const titleQuery = titleFilterOf().trim().toLowerCase()
		if (titleQuery && !song.title.toLowerCase().includes(titleQuery)) {
			return false
		}

		const composerIds = new Set(idsOf('composers'))
		const bookIds = new Set(idsOf('books'))
		const collectionIds = new Set(idsOf('collections'))
		const artistIds = new Set(idsOf('artists'))
		const genreIds = new Set(idsOf('genres'))

		if (!matchesCategory(song.composers, composerIds)) return false
		if (!matchesCategory(song.books, bookIds)) return false
		if (!matchesCategory(song.collections, collectionIds)) return false
		if (!matchesCategory(song.artists, artistIds)) return false
		if (!matchesCategory(song.genres, genreIds)) return false
		return true
	}

	/** The songs that carry every active filter selection. */
	function filterSongs(songs: Song[]): Song[] {
		return songs.filter(songMatchesFilters)
	}

	/** Drop every selection in one history entry, leaving the unfiltered view. */
	function clearFilters(): void {
		const query: LocationQueryRaw = { ...route.query }
		delete query.title
		delete query.search
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
	const selectedGenres = computed({
		get: () => selectedOf('genres') as Genre[],
		set: (value: Genre[]) => selectIds('genres', value.map(entry => entry.id)),
	})

	return {
		searchTitle,
		selectedComposers,
		selectedBooks,
		selectedCollections,
		selectedArtists,
		selectedGenres,
		hasActiveFilters,
		isFiltered,
		toggleFilter,
		filterSongs,
		clearFilters,
		filterBarVisible,
		toggleFilterBar,
	}
}