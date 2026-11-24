import { beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { useSongListPreferences } from '../../../src/composables/useSongListPreferences'
import { DEFAULT_VISIBLE_COLUMNS } from '../../../src/types/song'

describe('useSongListPreferences', () => {
	beforeEach(() => {
		localStorage.clear()
	})

	it('returns default values when localStorage is empty', () => {
		const prefs = useSongListPreferences()

		expect(prefs.viewMode.value).toBe('table')
		expect(prefs.sortKey.value).toBe('title')
		expect(prefs.sortOrder.value).toBe('asc')
		expect(prefs.visibleColumns.value).toEqual(DEFAULT_VISIBLE_COLUMNS)
		expect(prefs.pageSize.value).toBe(50)
	})

	it('loads persisted preferences including legacy mobilesheetsmanager keys', () => {
		localStorage.setItem('mobilesheetsmanager_song_view_mode', 'cards')
		localStorage.setItem('mobilesheetsmanager_song_sort_key', 'composer')
		localStorage.setItem('mobilesheetsmanager_song_sort_order', 'desc')
		localStorage.setItem('mobilesheetsmanager_song_visible_columns', JSON.stringify(['title', 'composer']))
		localStorage.setItem('sheetmusic_songs_page_size', 'all')

		const prefs = useSongListPreferences()

		expect(prefs.viewMode.value).toBe('cards')
		expect(prefs.sortKey.value).toBe('composer')
		expect(prefs.sortOrder.value).toBe('desc')
		expect(prefs.visibleColumns.value).toEqual(['title', 'composer'])
		expect(prefs.pageSize.value).toBe('all')
	})

	it('falls back safely on invalid JSON or invalid page size', () => {
		localStorage.setItem('sheetmusic_song_visible_columns', '{invalid-json')
		localStorage.setItem('sheetmusic_songs_page_size', '-10')

		const prefs = useSongListPreferences()

		expect(prefs.visibleColumns.value).toEqual(DEFAULT_VISIBLE_COLUMNS)
		expect(prefs.pageSize.value).toBe(50)
	})

	it('persists updates back to localStorage', async () => {
		const prefs = useSongListPreferences()

		prefs.viewMode.value = 'list'
		prefs.sortKey.value = 'duration'
		prefs.sortOrder.value = 'desc'
		prefs.visibleColumns.value = ['title', 'duration']
		prefs.pageSize.value = 100
		await nextTick()

		expect(localStorage.getItem('sheetmusic_song_view_mode')).toBe('list')
		expect(localStorage.getItem('sheetmusic_song_sort_key')).toBe('duration')
		expect(localStorage.getItem('sheetmusic_song_sort_order')).toBe('desc')
		expect(localStorage.getItem('sheetmusic_song_visible_columns')).toBe(JSON.stringify(['title', 'duration']))
		expect(localStorage.getItem('sheetmusic_songs_page_size')).toBe('100')
	})
})
