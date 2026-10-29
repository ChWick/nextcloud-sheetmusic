import { describe, expect, it, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { useSongMerge } from '../../../src/composables/useSongMerge'
import axios from '@nextcloud/axios'
import * as client from '../../../src/api/client'
import * as dialogs from '@nextcloud/dialogs'
import type { Song } from '../../../src/types/song'

vi.mock('@nextcloud/axios')
vi.mock('../../../src/api/client', () => ({
	apiPost: vi.fn(),
}))
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

const mockSongs: Song[] = [
	{ id: 10, title: 'Song Alpha', files: [] },
	{ id: 20, title: 'Song Beta', files: [] },
]

describe('useSongMerge composable', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('opens compare/merge dialog when 2 songs are selected', () => {
		const selectedLibrary = ref(1)
		const selectedSongIds = ref([10, 20])
		const songs = ref<Song[]>(mockSongs)

		const { isMergeDialogOpen, mergeIssue, openCompareMergeDialog } = useSongMerge({
			selectedLibrary,
			selectedSongIds,
			songs,
		})

		openCompareMergeDialog()

		expect(isMergeDialogOpen.value).toBe(true)
		expect(mergeIssue.value?.type).toBe('identical_song')
		expect(mergeIssue.value?.details?.songIds).toEqual([10, 20])
	})

	it('does not open dialog when selection is not exactly 2 songs', () => {
		const selectedLibrary = ref(1)
		const selectedSongIds = ref([10])
		const songs = ref<Song[]>(mockSongs)

		const { isMergeDialogOpen, openCompareMergeDialog } = useSongMerge({
			selectedLibrary,
			selectedSongIds,
			songs,
		})

		openCompareMergeDialog()

		expect(isMergeDialogOpen.value).toBe(false)
	})

	it('executes merge successfully and updates library data', async () => {
		const mockUpdatedData = { songs: [{ id: 10, title: 'Merged Song' }] }
		vi.mocked(axios.post).mockResolvedValueOnce({
			data: { ocs: { data: mockUpdatedData } },
		} as any)

		const selectedLibrary = ref(1)
		const selectedSongIds = ref([10, 20])
		const songs = ref<Song[]>(mockSongs)
		const onMerged = vi.fn()
		const onClearSelection = vi.fn()

		const { isMergeDialogOpen, executeMerge } = useSongMerge({
			selectedLibrary,
			selectedSongIds,
			songs,
			onMerged,
			onClearSelection,
		})

		isMergeDialogOpen.value = true

		await executeMerge({
			keepId: 10,
			droppedId: 20,
			merged: { title: 'Merged Song' },
		})

		expect(isMergeDialogOpen.value).toBe(false)
		expect(onClearSelection).toHaveBeenCalled()
		expect(onMerged).toHaveBeenCalledWith(mockUpdatedData)
		expect(dialogs.showSuccess).toHaveBeenCalled()
	})

	it('executes resolveBoth successfully', async () => {
		const mockUpdatedData = { songs: [{ id: 10, title: 'Song 1' }, { id: 20, title: 'Song 2' }] }
		vi.mocked(client.apiPost).mockResolvedValueOnce({
			data: { ocs: { data: mockUpdatedData } },
		} as any)

		const selectedLibrary = ref(1)
		const selectedSongIds = ref([10, 20])
		const songs = ref<Song[]>(mockSongs)
		const onMerged = vi.fn()
		const onClearSelection = vi.fn()

		const { isMergeDialogOpen, executeResolveBoth } = useSongMerge({
			selectedLibrary,
			selectedSongIds,
			songs,
			onMerged,
			onClearSelection,
		})

		isMergeDialogOpen.value = true

		await executeResolveBoth({
			songA: { id: 10, title: 'Song 1', detachFileIds: [] } as any,
			songB: { id: 20, title: 'Song 2', detachFileIds: [] } as any,
		})

		expect(isMergeDialogOpen.value).toBe(false)
		expect(onClearSelection).toHaveBeenCalled()
		expect(onMerged).toHaveBeenCalledWith(mockUpdatedData)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Both songs saved successfully.')
	})
})
