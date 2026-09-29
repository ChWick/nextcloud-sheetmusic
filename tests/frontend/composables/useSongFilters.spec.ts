import { beforeEach, describe, expect, it, vi } from 'vitest'
import { noneEntryOf, UNASSIGNED_ID, useSongFilters } from '../../../src/composables/useSongFilters'
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
})
