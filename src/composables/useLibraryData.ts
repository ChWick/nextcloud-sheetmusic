import type { Artist, Book, Collection, Composer, LibraryData, Song } from '../types/song'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
import { ref } from 'vue'

export function useLibraryData() {
	const songs = ref<Song[]>([])
	const composers = ref<Composer[]>([])
	const books = ref<Book[]>([])
	const collections = ref<Collection[]>([])
	const artists = ref<Artist[]>([])
	const fileError = ref('')
	const isLoading = ref(false)

	function handleLibraryDataUpdate(data: LibraryData) {
		songs.value = data.songs ?? []
		composers.value = data.composers ?? []
		books.value = data.books ?? []
		collections.value = data.collections ?? []
		artists.value = data.artists ?? []
	}

	async function loadLibraryData(id: number) {
		isLoading.value = true
		fileError.value = ''
		try {
			const response = await axios.get(
				generateOcsUrl('/apps/mobilesheetsmanager/api/songs'),
				{ params: { id } },
			)
			handleLibraryDataUpdate(response.data.ocs.data as LibraryData)
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
		fileError,
		isLoading,
		handleLibraryDataUpdate,
		loadLibraryData,
	}
}
