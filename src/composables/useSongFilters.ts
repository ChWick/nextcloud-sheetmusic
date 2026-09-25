import type { Artist, Book, Collection, Composer, Song } from '../types/song'

import { computed, ref, watch } from 'vue'

/**
 * The state of the song filter bar, shared by every view that embeds it.
 *
 * The selections are module state on purpose: the filter bar is one bar that each
 * view shows, so filters set in one view are still active in the next. They name
 * entries of one specific library - an id means something else in another library -
 * and are therefore dropped when the library changes.
 */
const selectedComposers = ref<Composer[]>([])
const selectedBooks = ref<Book[]>([])
const selectedCollections = ref<Collection[]>([])
const selectedArtists = ref<Artist[]>([])

/** Library the current selections belong to; null before the first view binds one. */
const filtersLibraryId = ref<number | null>(null)

function clearFilters(): void {
	selectedComposers.value = []
	selectedBooks.value = []
	selectedCollections.value = []
	selectedArtists.value = []
}

/**
 * Filter state and logic for the song filter bar.
 *
 * @param libraryId the library the caller shows; the selections are cleared when it
 *                  changes and kept when the same library is bound again
 */
export function useSongFilters(libraryId: () => number) {
	watch(libraryId, (id) => {
		if (filtersLibraryId.value !== id) {
			filtersLibraryId.value = id
			clearFilters()
		}
	}, { immediate: true })

	const hasActiveFilters = computed(() => (
		selectedComposers.value.length > 0
		|| selectedBooks.value.length > 0
		|| selectedCollections.value.length > 0
		|| selectedArtists.value.length > 0
	))

	/** Whether the song carries every active filter selection. */
	function songMatchesFilters(song: Song): boolean {
		const composerIds = new Set(selectedComposers.value.map(composer => composer.id))
		const bookIds = new Set(selectedBooks.value.map(book => book.id))
		const collectionIds = new Set(selectedCollections.value.map(collection => collection.id))
		const artistIds = new Set(selectedArtists.value.map(artist => artist.id))

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

	return {
		selectedComposers,
		selectedBooks,
		selectedCollections,
		selectedArtists,
		hasActiveFilters,
		filterSongs,
		clearFilters,
	}
}