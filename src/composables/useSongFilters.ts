import type { Arranger, Artist, Book, Collection, Composer, CustomGroup, EntityType, FilterEntity, Genre, Instrument, Key, Signature, Song, SourceType, Year } from '../types/song'
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router'
import { ENTITY_COLUMNS } from '../components/songs/song-view-columns'
import { normalizeSearchString } from '../utils/string'

/** The entries the filters can select; they resolve the ids stored in the URL. */
export interface FilterOptions {
	composers: Composer[]
	books: Book[]
	collections: Collection[]
	artists: Artist[]
	genres: Genre[]
	keys: Key[]
	customGroups?: CustomGroup[]
	customgroups?: CustomGroup[]
	years?: Year[]
	signatures?: Signature[]
	sourceTypes?: SourceType[]
	sourcetypes?: SourceType[]
	instruments?: Instrument[]
	arrangers?: Arranger[]
}

const FILTER_TYPES: EntityType[] = ENTITY_COLUMNS.map((col) => col.entityType)

export const UNASSIGNED_ID = -1

/** The special filter entry for songs without an assigned entity in that category. */
export function noneEntryOf(entityType: EntityType): FilterEntity {
	switch (entityType) {
		case 'composers':
			return { id: UNASSIGNED_ID, name: t('sheetmusic', '(No composer)') }
		case 'books':
			return { id: UNASSIGNED_ID, title: t('sheetmusic', '(No book)') }
		case 'collections':
			return { id: UNASSIGNED_ID, name: t('sheetmusic', '(No collection)') }
		case 'artists':
			return { id: UNASSIGNED_ID, name: t('sheetmusic', '(No artist)') }
		case 'genres':
			return { id: UNASSIGNED_ID, name: t('sheetmusic', '(No genre)') }
		case 'keys':
			return { id: UNASSIGNED_ID, name: t('sheetmusic', '(No key)') }
		case 'customgroups':
			return { id: UNASSIGNED_ID, name: t('sheetmusic', '(No custom group)') }
		case 'years':
			return { id: UNASSIGNED_ID, name: t('sheetmusic', '(No year)'), year: 0 }
		case 'signatures':
			return { id: UNASSIGNED_ID, name: t('sheetmusic', '(No time signature)') }
		case 'sourcetypes':
			return { id: UNASSIGNED_ID, name: t('sheetmusic', '(No source type)'), type: t('sheetmusic', '(No source type)') }
		case 'instruments':
			return { id: UNASSIGNED_ID, name: t('sheetmusic', '(No instrument)') }
		case 'arrangers':
			return { id: UNASSIGNED_ID, name: t('sheetmusic', '(No arranger)') }
	}
}

/** Whether the current viewport width is considered mobile (<= 768px). */
export function isMobileViewport(): boolean {
	if (typeof window === 'undefined') return false
	return window.innerWidth <= 768
}

let userExplicitlyToggled = false

/** Whether the filter bar is shown; defaults to closed on mobile (<= 768px) and open on desktop. */
export const filterBarVisible = ref(!isMobileViewport())

if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
	const mediaQuery = window.matchMedia('(max-width: 768px)')
	const handleMediaChange = (e: MediaQueryListEvent | MediaQueryList) => {
		if (!userExplicitlyToggled) {
			filterBarVisible.value = !e.matches
		}
	}
	if (mediaQuery.addEventListener) {
		mediaQuery.addEventListener('change', handleMediaChange)
	} else if ('addListener' in mediaQuery) {
		(mediaQuery as any).addListener(handleMediaChange)
	}
}

export function toggleFilterBar(): void {
	userExplicitlyToggled = true
	filterBarVisible.value = !filterBarVisible.value
}

export function resetFilterBarState(options?: { isMobile?: boolean }): void {
	userExplicitlyToggled = false
	if (options?.isMobile !== undefined) {
		filterBarVisible.value = !options.isMobile
	} else {
		filterBarVisible.value = !isMobileViewport()
	}
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

	function withoutFilesFilterOf(): boolean {
		const raw = route.query.withoutFiles ?? route.query.without_files ?? route.query.noFiles ?? route.query.no_files
		const value = Array.isArray(raw) ? raw[0] : raw
		return value === 'true' || value === '1' || value === 'yes'
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

	const withoutFiles = computed({
		get: () => withoutFilesFilterOf(),
		set: (val: boolean) => {
			const query: LocationQueryRaw = { ...route.query }
			if (!val) {
				delete query.withoutFiles
				delete query.without_files
				delete query.noFiles
				delete query.no_files
			} else {
				query.withoutFiles = '1'
			}
			router.push({ query })
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
		const opts = options()
		const col = ENTITY_COLUMNS.find((c) => c.entityType === entityType)
		const entries = ((opts[entityType] ?? (col ? opts[col.songProp] : [])) ?? []) as FilterEntity[]
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
		Boolean(titleFilterOf().trim()) || withoutFilesFilterOf() || FILTER_TYPES.some(entityType => idsOf(entityType).length > 0)
	)

	function matchesCategory<T extends { id: number }>(songItems: T[] | undefined, selectedIds: Set<number>): boolean {
		if (selectedIds.size === 0) return true
		const hasUnassignedSelected = selectedIds.has(UNASSIGNED_ID)
		const isUnassigned = !songItems || songItems.length === 0
		if (hasUnassignedSelected && isUnassigned) return true
		if (songItems && songItems.some(item => selectedIds.has(item.id))) return true
		return false
	}

	/**
	 * Filter songs matching active criteria:
	 * - Multiple selections within the same entity type match with OR (e.g. Composer = Bach OR Mozart).
	 * - Different entity types, title search query, and "without files" match with AND.
	 */
	function filterSongs(songs: Song[]): Song[] {
		const titleQuery = normalizeSearchString(titleFilterOf())
		const requireNoFiles = withoutFilesFilterOf()
		const activeFilters = ENTITY_COLUMNS
			.map((col) => ({
				getItems: col.getItems,
				ids: new Set(idsOf(col.entityType)),
			}))
			.filter(f => f.ids.size > 0)

		return songs.filter(song => {
			if (titleQuery && !normalizeSearchString(song.title).includes(titleQuery)) {
				return false
			}
			if (requireNoFiles && song.files && song.files.length > 0) {
				return false
			}
			for (const { getItems, ids } of activeFilters) {
				if (!matchesCategory(getItems(song), ids)) {
					return false
				}
			}
			return true
		})
	}

	/** Drop every selection in one history entry, leaving the unfiltered view. */
	function clearFilters(): void {
		const query: LocationQueryRaw = { ...route.query }
		delete query.title
		delete query.search
		delete query.withoutFiles
		delete query.without_files
		delete query.noFiles
		delete query.no_files
		for (const entityType of FILTER_TYPES) {
			delete query[entityType]
		}
		router.push({ query })
	}

	function createSelectedComputed<T extends FilterEntity>(entityType: EntityType) {
		return computed({
			get: () => selectedOf(entityType) as T[],
			set: (value: T[]) => selectIds(entityType, value.map(entry => entry.id)),
		})
	}

	const selectedComposers = createSelectedComputed<Composer>('composers')
	const selectedBooks = createSelectedComputed<Book>('books')
	const selectedCollections = createSelectedComputed<Collection>('collections')
	const selectedArtists = createSelectedComputed<Artist>('artists')
	const selectedGenres = createSelectedComputed<Genre>('genres')
	const selectedKeys = createSelectedComputed<Key>('keys')
	const selectedCustomGroups = createSelectedComputed<CustomGroup>('customgroups')
	const selectedYears = createSelectedComputed<Year>('years')
	const selectedSignatures = createSelectedComputed<Signature>('signatures')
	const selectedSourceTypes = createSelectedComputed<SourceType>('sourcetypes')
	const selectedInstruments = createSelectedComputed<Instrument>('instruments')
	const selectedArrangers = createSelectedComputed<Arranger>('arrangers')

	return {
		searchTitle,
		withoutFiles,
		selectedComposers,
		selectedBooks,
		selectedCollections,
		selectedArtists,
		selectedGenres,
		selectedKeys,
		selectedCustomGroups,
		selectedYears,
		selectedSignatures,
		selectedSourceTypes,
		selectedInstruments,
		selectedArrangers,
		hasActiveFilters,
		isFiltered,
		toggleFilter,
		filterSongs,
		clearFilters,
		filterBarVisible,
		toggleFilterBar,
	}
}