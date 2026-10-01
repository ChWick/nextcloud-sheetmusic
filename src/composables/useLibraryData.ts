import type { Artist, Book, Collection, Composer, Genre, LibraryData, Song } from '../types/song'
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
	const fileError = ref('')
	const isLoading = ref(false)

	function handleLibraryDataUpdate(data: LibraryData) {
		songs.value = data.songs ?? []
		composers.value = data.composers ?? []
		books.value = data.books ?? []
		collections.value = data.collections ?? []
		artists.value = data.artists ?? []
		genres.value = data.genres ?? []
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
		fileError,
		isLoading,
		handleLibraryDataUpdate,
		loadLibraryData,
	}
}
