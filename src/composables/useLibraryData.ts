import type { Artist, Book, Collection, Composer, CustomGroup, Genre, Key, LibraryData, Signature, Song, Year } from '../types/song'
import { apiGet } from '../api/client'
import { translate as t } from '@nextcloud/l10n'
import { ref } from 'vue'

export function useLibraryData() {
	const songs = ref<Song[]>([])
	const composers = ref<Composer[]>([])
	const books = ref<Book[]>([])
	const collections = ref<Collection[]>([])
	const artists = ref<Artist[]>([])
	const genres = ref<Genre[]>([])
	const keys = ref<Key[]>([])
	const customGroups = ref<CustomGroup[]>([])
	const years = ref<Year[]>([])
	const signatures = ref<Signature[]>([])
	const fileError = ref('')
	const isLoading = ref(false)

	function handleLibraryDataUpdate(data: LibraryData) {
		songs.value = data.songs ?? []
		composers.value = data.composers ?? []
		books.value = data.books ?? []
		collections.value = data.collections ?? []
		artists.value = data.artists ?? []
		genres.value = data.genres ?? []
		keys.value = data.keys ?? []
		customGroups.value = data.customGroups ?? []
		years.value = data.years ?? []
		signatures.value = data.signatures ?? []
	}

	async function loadLibraryData(id: number) {
		isLoading.value = true
		fileError.value = ''
		try {
			const response = await apiGet('/api/songs', { queryParams: { id } })
			handleLibraryDataUpdate(response.data.ocs.data)
		} catch {
			fileError.value = t('mobilesheetsmanager', 'Could not load this library.')
		} finally {
			isLoading.value = false
		}
	}

	return {
		songs,
		composers,
		books,
		collections,
		artists,
		genres,
		keys,
		customGroups,
		years,
		signatures,
		fileError,
		isLoading,
		handleLibraryDataUpdate,
		loadLibraryData,
	}
}
