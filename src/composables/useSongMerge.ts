import { ref, type Ref } from 'vue'
import { t } from '@nextcloud/l10n'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiPost } from '../api/client'
import type { IntegrityIssue, LibraryData, Song } from '../types/song'
import type { MergedSongData, ResolveBothSongData } from '../components/song-editor/SongMergeDialog.vue'

export interface UseSongMergeOptions {
	selectedLibrary: Ref<number>
	selectedSongIds: Ref<number[]>
	songs: Ref<Song[]>
	onMerged?: (data?: LibraryData) => void
	onClearSelection?: () => void
	reloadLibrary?: (libraryId: number) => Promise<void>
}

export function useSongMerge(options: UseSongMergeOptions) {
	const isMergeDialogOpen = ref(false)
	const isMerging = ref(false)
	const mergeError = ref('')
	const mergeIssue = ref<IntegrityIssue | null>(null)

	function openCompareMergeDialog() {
		if (options.selectedSongIds.value.length !== 2) return
		const songA = options.songs.value.find(s => s.id === options.selectedSongIds.value[0])
		const songB = options.songs.value.find(s => s.id === options.selectedSongIds.value[1])
		if (!songA || !songB) return

		mergeIssue.value = {
			type: 'identical_song',
			severity: 'info',
			message: t('sheetmusic', 'Comparing 2 selected songs for merge or conflict resolution.'),
			songId: songA.id,
			songTitle: songA.title,
			fixable: true,
			details: {
				songIds: [songA.id, songB.id],
				exactDuplicate: false,
			},
		}
		mergeError.value = ''
		isMergeDialogOpen.value = true
	}

	async function executeMerge(payload: {
		keepId: number
		droppedId: number
		merged: MergedSongData | Record<string, unknown>
		targetSongId?: number
	}) {
		isMerging.value = true
		mergeError.value = ''
		try {
			const response = await axios.post(
				`/ocs/v2.php/apps/sheetmusic/api/songs/merge`,
				{
					libraryId: options.selectedLibrary.value,
					keepId: payload.keepId,
					droppedId: payload.droppedId,
					merged: payload.merged,
				},
				{ headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' } },
			)
			const targetSong = options.songs.value.find(s => s.id === (payload.targetSongId ?? payload.keepId))
			isMergeDialogOpen.value = false
			mergeIssue.value = null
			options.onClearSelection?.()
			if (response.data?.ocs?.data) {
				options.onMerged?.(response.data.ocs.data)
			} else {
				await options.reloadLibrary?.(options.selectedLibrary.value)
			}
			const targetTitle = targetSong?.title ?? ''
			if (targetTitle) {
				showSuccess(t('sheetmusic', 'Songs merged successfully into "{title}".', { title: targetTitle }, { escape: false }))
			} else {
				showSuccess(t('sheetmusic', 'Songs merged successfully.'))
			}
		} catch (error) {
			console.error('Failed to merge songs:', error)
			const serverMessage = axios.isAxiosError(error)
				? error.response?.data?.ocs?.data?.message ?? error.response?.data?.message
				: undefined
			mergeError.value = serverMessage
				?? t('sheetmusic', 'Failed to merge songs. Please try again.')
			showError(mergeError.value)
		} finally {
			isMerging.value = false
		}
	}

	async function executeResolveBoth(payload: {
		songA: ResolveBothSongData
		songB: ResolveBothSongData
	}) {
		if (options.selectedLibrary.value < 0) return

		isMerging.value = true
		mergeError.value = ''
		try {
			const response = await apiPost('/api/songs/resolve-both', {
				libraryId: options.selectedLibrary.value,
				songA: payload.songA,
				songB: payload.songB,
			})

			isMergeDialogOpen.value = false
			mergeIssue.value = null
			options.onClearSelection?.()
			if (response.data?.ocs?.data) {
				options.onMerged?.(response.data.ocs.data)
			} else {
				await options.reloadLibrary?.(options.selectedLibrary.value)
			}
			showSuccess(t('sheetmusic', 'Both songs saved successfully.'))
		} catch (error: any) {
			console.error('Failed to resolve both songs:', error)
			const serverMessage = error?.response?.data?.ocs?.data?.message
				?? error?.response?.data?.message
				?? error?.message
			mergeError.value = serverMessage
				?? t('sheetmusic', 'Failed to save updated songs. Please try again.')
			showError(mergeError.value)
		} finally {
			isMerging.value = false
		}
	}

	return {
		isMergeDialogOpen,
		isMerging,
		mergeError,
		mergeIssue,
		openCompareMergeDialog,
		executeMerge,
		executeResolveBoth,
	}
}
