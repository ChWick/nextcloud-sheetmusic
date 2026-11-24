import { ref, watch } from 'vue'
import { DEFAULT_VISIBLE_COLUMNS, type SongColumn } from '../types/song'
import type { SortKey, SortOrder } from '../utils/song-formatter'
import type { ViewMode } from '../components/songs/song-view-columns'
import type { PageSize } from '../components/songs/SongPagination.vue'

const DEFAULT_PAGE_SIZE: PageSize = 50

function readStoredColumns(): SongColumn[] {
	const stored = localStorage.getItem('sheetmusic_song_visible_columns')
		?? localStorage.getItem('mobilesheetsmanager_song_visible_columns')
	if (!stored) return DEFAULT_VISIBLE_COLUMNS
	try {
		const parsed = JSON.parse(stored)
		if (Array.isArray(parsed) && parsed.length > 0) return parsed
	} catch {
		// fallback to defaults
	}
	return DEFAULT_VISIBLE_COLUMNS
}

function readStoredPageSize(): PageSize {
	try {
		const saved = localStorage.getItem('sheetmusic_songs_page_size')
		if (saved === 'all') return 'all'
		if (saved) {
			const num = parseInt(saved, 10)
			if (!isNaN(num) && num > 0) return num
		}
	} catch {
		// fallback to default
	}
	return DEFAULT_PAGE_SIZE
}

export function useSongListPreferences() {
	const viewMode = ref<ViewMode>(
		(localStorage.getItem('sheetmusic_song_view_mode')
			?? localStorage.getItem('mobilesheetsmanager_song_view_mode')) as ViewMode || 'table',
	)

	const sortKey = ref<SortKey>(
		(localStorage.getItem('sheetmusic_song_sort_key')
			?? localStorage.getItem('mobilesheetsmanager_song_sort_key')) as SortKey || 'title',
	)

	const sortOrder = ref<SortOrder>(
		(localStorage.getItem('sheetmusic_song_sort_order')
			?? localStorage.getItem('mobilesheetsmanager_song_sort_order')) as SortOrder || 'asc',
	)

	const visibleColumns = ref<SongColumn[]>(readStoredColumns())
	const pageSize = ref<PageSize>(readStoredPageSize())

	watch(viewMode, (newMode) => {
		localStorage.setItem('sheetmusic_song_view_mode', newMode)
	})

	watch(sortKey, (newKey) => {
		localStorage.setItem('sheetmusic_song_sort_key', newKey)
	})

	watch(sortOrder, (newOrder) => {
		localStorage.setItem('sheetmusic_song_sort_order', newOrder)
	})

	watch(visibleColumns, (newCols) => {
		localStorage.setItem('sheetmusic_song_visible_columns', JSON.stringify(newCols))
	}, { deep: true })

	watch(pageSize, (newSize) => {
		try {
			localStorage.setItem('sheetmusic_songs_page_size', String(newSize))
		} catch {
			// ignore storage errors
		}
	})

	return {
		viewMode,
		sortKey,
		sortOrder,
		visibleColumns,
		pageSize,
	}
}
