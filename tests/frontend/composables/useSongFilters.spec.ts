import { beforeEach, describe, expect, it, vi } from 'vitest'
import { isMobileViewport, noneEntryOf, resetFilterBarState, UNASSIGNED_ID, useSongFilters } from '../../../src/composables/useSongFilters'
import type { Song } from '../../../src/types/song'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
}))

const mockRoute = { query: {} as Record<string, any> }
const mockRouter = { push: vi.fn() }

vi.mock('vue-router', () => ({
	useRoute: () => mockRoute,
	useRouter: () => mockRouter,
}))

describe('useSongFilters composable with unassigned matching', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		mockRoute.query = {}
	})

	const testSongs: Song[] = [
		{
			id: 1,
			title: 'Song with Mozart in Jazz Collection',
			composers: [{ id: 10, name: 'Mozart' }],
			collections: [{ id: 100, name: 'Jazz' }],
		},
		{
			id: 2,
			title: 'Song without Composer in Jazz Collection',
			composers: [],
			collections: [{ id: 100, name: 'Jazz' }],
		},
		{
			id: 3,
			title: 'Song with Bach without Collection',
			composers: [{ id: 20, name: 'Bach' }],
			collections: [],
		},
	]

	const options = () => ({
		composers: [{ id: 10, name: 'Mozart' }, { id: 20, name: 'Bach' }],
		books: [],
		collections: [{ id: 100, name: 'Jazz' }],
		artists: [],
	})

	it('returns noneEntryOf correctly for all entity types', () => {
		expect(noneEntryOf('composers')).toEqual({ id: UNASSIGNED_ID, name: '(No composer)' })
		expect(noneEntryOf('books')).toEqual({ id: UNASSIGNED_ID, title: '(No book)' })
		expect(noneEntryOf('collections')).toEqual({ id: UNASSIGNED_ID, name: '(No collection)' })
		expect(noneEntryOf('artists')).toEqual({ id: UNASSIGNED_ID, name: '(No artist)' })
		expect(noneEntryOf('sourcetypes')).toEqual({ id: UNASSIGNED_ID, name: '(No source type)', type: '(No source type)' })
		expect(noneEntryOf('instruments')).toEqual({ id: UNASSIGNED_ID, name: '(No instrument)' })
	})

	it('filters songs without composer in specific collection', () => {
		mockRoute.query = { composers: '-1', collections: '100' }

		const { filterSongs } = useSongFilters(options)
		const result = filterSongs(testSongs)

		expect(result).toHaveLength(1)
		expect(result[0].id).toBe(2)
		expect(result[0].title).toBe('Song without Composer in Jazz Collection')
	})

	it('filters songs with Mozart or without composer', () => {
		mockRoute.query = { composers: '10,-1' }

		const { filterSongs } = useSongFilters(options)
		const result = filterSongs(testSongs)

		expect(result.map((s) => s.id)).toEqual([1, 2])
	})

	it('resolves -1 in selectedComposers getter', () => {
		mockRoute.query = { composers: '-1' }

		const { selectedComposers } = useSongFilters(options)
		expect(selectedComposers.value).toEqual([{ id: UNASSIGNED_ID, name: '(No composer)' }])
	})

	it('updates query with -1 when setting unassigned filter', () => {
		const { selectedComposers } = useSongFilters(options)
		selectedComposers.value = [{ id: UNASSIGNED_ID, name: '(No composer)' }]

		expect(mockRouter.push).toHaveBeenCalledWith({
			query: { composers: '-1' },
		})
	})

	it('checks if an entity is filtered using isFiltered', () => {
		mockRoute.query = { composers: '10,20', books: '5' }

		const { isFiltered } = useSongFilters(options)

		expect(isFiltered('composers', 10)).toBe(true)
		expect(isFiltered('composers', 20)).toBe(true)
		expect(isFiltered('composers', 30)).toBe(false)
		expect(isFiltered('books', 5)).toBe(true)
		expect(isFiltered('books', 10)).toBe(false)
		expect(isFiltered('artists', 1)).toBe(false)
	})

	it('toggles filter correctly adding and removing entity ids', () => {
		mockRoute.query = { composers: '10' }

		const { toggleFilter } = useSongFilters(options)

		// Toggle adding Bach (id: 20)
		toggleFilter('composers', { id: 20, name: 'Bach' })
		expect(mockRouter.push).toHaveBeenLastCalledWith({
			query: { composers: '10,20' },
		})

		// Toggle removing Mozart (id: 10)
		toggleFilter('composers', { id: 10, name: 'Mozart' })
		expect(mockRouter.push).toHaveBeenLastCalledWith({
			query: {},
		})
	})

	it('filters songs without files', () => {
		const songsWithAndWithoutFiles: Song[] = [
			{ id: 1, title: 'Song with file', files: [{ id: 1, path: 'song1.pdf' }] },
			{ id: 2, title: 'Song with empty files array', files: [] },
			{ id: 3, title: 'Song without files property' },
		]

		mockRoute.query = { withoutFiles: '1' }
		const { filterSongs, withoutFiles, hasActiveFilters } = useSongFilters(options)

		expect(withoutFiles.value).toBe(true)
		expect(hasActiveFilters.value).toBe(true)

		const result = filterSongs(songsWithAndWithoutFiles)
		expect(result.map(s => s.id)).toEqual([2, 3])
	})

	it('updates query when setting withoutFiles filter', () => {
		const { withoutFiles } = useSongFilters(options)
		withoutFiles.value = true

		expect(mockRouter.push).toHaveBeenCalledWith({
			query: { withoutFiles: '1' },
		})
	})

	it('clears withoutFiles when clearFilters is called', () => {
		mockRoute.query = { withoutFiles: '1', composers: '10' }
		const { clearFilters } = useSongFilters(options)

		clearFilters()
		expect(mockRouter.push).toHaveBeenCalledWith({
			query: {},
		})
	})

	it('filters songs by title with diacritic/accent insensitivity in both directions', () => {
		const songsWithAccents: Song[] = [
			{ id: 1, title: 'Sonata by Pierre' },
			{ id: 2, title: 'Prélude by Frédéric' },
			{ id: 3, title: 'Joshua by Händel' },
		]

		// Query has accent, target has no accent: "Piérre" -> matches "Pierre"
		mockRoute.query = { search: 'Piérre' }
		const { filterSongs: filter1 } = useSongFilters(options)
		expect(filter1(songsWithAccents).map(s => s.id)).toEqual([1])

		// Query has no accent, target has accent: "prelude" -> matches "Prélude"
		mockRoute.query = { search: 'prelude' }
		const { filterSongs: filter2 } = useSongFilters(options)
		expect(filter2(songsWithAccents).map(s => s.id)).toEqual([2])

		// Query has no umlaut, target has umlaut: "handel" -> matches "Händel"
		mockRoute.query = { search: 'handel' }
		const { filterSongs: filter3 } = useSongFilters(options)
		expect(filter3(songsWithAccents).map(s => s.id)).toEqual([3])
	})

	it('filters songs with OR for multiple selections within the same entity type and AND across different entity types and title', () => {
		const songs: Song[] = [
			{
				id: 1,
				title: 'Adagio in G minor',
				composers: [{ id: 10, name: 'J.S. Bach' }],
				genres: [{ id: 100, name: 'Classical' }],
			},
			{
				id: 2,
				title: 'Adagio in E major',
				composers: [{ id: 20, name: 'W.A. Mozart' }],
				genres: [{ id: 100, name: 'Classical' }],
			},
			{
				id: 3,
				title: 'Allegro',
				composers: [{ id: 10, name: 'J.S. Bach' }],
				genres: [{ id: 100, name: 'Classical' }],
			},
			{
				id: 4,
				title: 'Adagio in C minor',
				composers: [{ id: 30, name: 'L.v. Beethoven' }],
				genres: [{ id: 100, name: 'Classical' }],
			},
			{
				id: 5,
				title: 'Adagio in D major',
				composers: [{ id: 20, name: 'W.A. Mozart' }],
				genres: [{ id: 200, name: 'Jazz' }],
			},
		]

		// Filter: Title="Adagio" AND Composer=("J.S. Bach" OR "W.A. Mozart")
		mockRoute.query = { title: 'Adagio', composers: '10,20' }
		const { filterSongs: filterOR } = useSongFilters(options)
		const res1 = filterOR(songs)
		// Should include both Bach's Adagio and Mozart's Adagios (ids: 1, 2, 5), but exclude Allegro (id 3) and Beethoven's Adagio (id 4)
		expect(res1.map(s => s.id)).toEqual([1, 2, 5])

		// Add different entity type: Genre="Classical" (id 100)
		// Should AND with the previous filters: Adagios of Bach OR Mozart, AND Classical
		mockRoute.query = { title: 'Adagio', composers: '10,20', genres: '100' }
		const { filterSongs: filterAND } = useSongFilters(options)
		const res2 = filterAND(songs)
		expect(res2.map(s => s.id)).toEqual([1, 2])
	})

	describe('filterBarVisible responsive defaults', () => {
		it('detects mobile viewport correctly', () => {
			window.innerWidth = 500
			expect(isMobileViewport()).toBe(true)

			window.innerWidth = 768
			expect(isMobileViewport()).toBe(true)

			window.innerWidth = 769
			expect(isMobileViewport()).toBe(false)

			window.innerWidth = 1200
			expect(isMobileViewport()).toBe(false)
		})

		it('defaults to closed (false) in mobile view and can be toggled', () => {
			resetFilterBarState({ isMobile: true })
			const { filterBarVisible, toggleFilterBar } = useSongFilters(options)

			expect(filterBarVisible.value).toBe(false)

			toggleFilterBar()
			expect(filterBarVisible.value).toBe(true)

			toggleFilterBar()
			expect(filterBarVisible.value).toBe(false)
		})

		it('defaults to open (true) in desktop view and can be toggled', () => {
			resetFilterBarState({ isMobile: false })
			const { filterBarVisible, toggleFilterBar } = useSongFilters(options)

			expect(filterBarVisible.value).toBe(true)

			toggleFilterBar()
			expect(filterBarVisible.value).toBe(false)

			toggleFilterBar()
			expect(filterBarVisible.value).toBe(true)
		})
	})
})
