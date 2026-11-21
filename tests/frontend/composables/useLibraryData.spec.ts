import { describe, expect, it, vi, beforeEach } from 'vitest'
import { useLibraryData } from '../../../src/composables/useLibraryData'
import axios from '@nextcloud/axios'

vi.mock('@nextcloud/axios')

describe('useLibraryData composable', () => {
	beforeEach(() => {
		vi.resetAllMocks()
	})

	it('initializes with empty arrays and non-loading state', () => {
		const { songs, composers, books, collections, artists, instruments, arrangers, fileError, isLoading } = useLibraryData()

		expect(songs.value).toEqual([])
		expect(composers.value).toEqual([])
		expect(books.value).toEqual([])
		expect(collections.value).toEqual([])
		expect(artists.value).toEqual([])
		expect(instruments.value).toEqual([])
		expect(arrangers.value).toEqual([])
		expect(fileError.value).toBe('')
		expect(isLoading.value).toBe(false)
	})

	it('updates state when handleLibraryDataUpdate is called', () => {
		const { songs, composers, books, collections, artists, arrangers, handleLibraryDataUpdate } = useLibraryData()

		handleLibraryDataUpdate({
			songs: [{ id: 1, title: 'Test Song' }],
			composers: [{ id: 2, name: 'Mozart' }],
			books: [{ id: 3, title: 'Book 1' }],
			collections: [{ id: 4, name: 'Col 1' }],
			artists: [{ id: 5, name: 'Artist 1' }],
			arrangers: [{ id: 6, name: 'Arranger 1' }],
		})

		expect(songs.value).toEqual([{ id: 1, title: 'Test Song' }])
		expect(composers.value).toEqual([{ id: 2, name: 'Mozart' }])
		expect(books.value).toEqual([{ id: 3, title: 'Book 1' }])
		expect(collections.value).toEqual([{ id: 4, name: 'Col 1' }])
		expect(artists.value).toEqual([{ id: 5, name: 'Artist 1' }])
		expect(arrangers.value).toEqual([{ id: 6, name: 'Arranger 1' }])
	})

	it('loads library data successfully via loadLibraryData', async () => {
		const mockedAxios = vi.mocked(axios)
		mockedAxios.get.mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						songs: [{ id: 10, title: 'Loaded Song' }],
						composers: [],
						books: [],
						collections: [],
						artists: [],
					},
				},
			},
		})

		const { songs, isLoading, fileError, loadLibraryData } = useLibraryData()

		const promise = loadLibraryData(1)
		expect(isLoading.value).toBe(true)
		await promise

		expect(isLoading.value).toBe(false)
		expect(fileError.value).toBe('')
		expect(songs.value).toEqual([{ id: 10, title: 'Loaded Song' }])
	})

	it('sets fileError when loadLibraryData fails', async () => {
		const mockedAxios = vi.mocked(axios)
		mockedAxios.get.mockRejectedValueOnce(new Error('Network failure'))

		const { isLoading, fileError, loadLibraryData } = useLibraryData()

		await loadLibraryData(1)

		expect(isLoading.value).toBe(false)
		expect(fileError.value).toBe('Could not load this library.')
	})
})
