import type { Arranger, Artist, Book, Collection, Composer, CustomGroup, Genre, Instrument, Key, LibraryData, Signature, Song, SourceType, Year } from '../types/song'
import type { Sublibrary } from '../types/library'
import { apiGet } from '../api/client'
import { OCS_JSON_HEADERS, ocsGet } from '../api/ocs'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { describeRequestFailure } from '../utils/api'
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
	const sourceTypes = ref<SourceType[]>([])
	const instruments = ref<Instrument[]>([])
	const arrangers = ref<Arranger[]>([])
	const sublibraries = ref<Sublibrary[]>([])
	const fileError = ref('')
	const isLoading = ref(false)
	const hasLoaded = ref(false)

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
		sourceTypes.value = data.sourceTypes ?? []
		instruments.value = data.instruments ?? []
		arrangers.value = data.arrangers ?? []
	}

	async function loadSublibraries(id: number) {
		try {
			const data = await ocsGet<{ sublibraries?: Sublibrary[] }>(
				`/api/libraries/${id}/sublibraries`,
				{ headers: OCS_JSON_HEADERS },
			)
			sublibraries.value = data?.sublibraries ?? []
		} catch {
			sublibraries.value = []
		}
	}

	async function loadLibraryData(id: number) {
		isLoading.value = true
		hasLoaded.value = false
		fileError.value = ''
		try {
			const [response] = await Promise.all([
				apiGet('/api/songs', { queryParams: { id } }),
				loadSublibraries(id),
			])
			handleLibraryDataUpdate(response.data.ocs.data)
		} catch (error) {
			fileError.value = describeRequestFailure(error, t('sheetmusic', 'Could not load this library.'))
			showError(fileError.value)
		} finally {
			isLoading.value = false
			hasLoaded.value = true
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
		sourceTypes,
		instruments,
		arrangers,
		sublibraries,
		fileError,
		isLoading,
		hasLoaded,
		handleLibraryDataUpdate,
		loadLibraryData,
		loadSublibraries,
	}
}
