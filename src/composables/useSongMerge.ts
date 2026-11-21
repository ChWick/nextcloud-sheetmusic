import { ref, type Ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiPost } from '../api/client'
import { OCS_JSON_HEADERS, ocsPost } from '../api/ocs'
import { extractApiErrorMessage } from '../utils/api'
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

	async function finishMergeSuccess(data?: LibraryData) {
		isMergeDialogOpen.value = false
		mergeIssue.value = null
		options.onClearSelection?.()
		if (data) {
			options.onMerged?.(data)
		} else {
			await options.reloadLibrary?.(options.selectedLibrary.value)
		}
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
			const data = await ocsPost<LibraryData>(
				'/api/songs/merge',
				{
					libraryId: options.selectedLibrary.value,
					keepId: payload.keepId,
					droppedId: payload.droppedId,
					merged: payload.merged,
				},
				{ headers: OCS_JSON_HEADERS },
			)
			const targetSong = options.songs.value.find(s => s.id === (payload.targetSongId ?? payload.keepId))
			await finishMergeSuccess(data)
			const targetTitle = targetSong?.title ?? ''
			if (targetTitle) {
				showSuccess(t('sheetmusic', 'Songs merged successfully into "{title}".', { title: targetTitle }, { escape: false }))
			} else {
				showSuccess(t('sheetmusic', 'Songs merged successfully.'))
			}
		} catch (error) {
			console.error('Failed to merge songs:', error)
			mergeError.value = extractApiErrorMessage(error, t('sheetmusic', 'Failed to merge songs. Please try again.'))
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

			await finishMergeSuccess(response.data?.ocs?.data)
			showSuccess(t('sheetmusic', 'Both songs saved successfully.'))
		} catch (error: unknown) {
			console.error('Failed to resolve both songs:', error)
			mergeError.value = extractApiErrorMessage(error, t('sheetmusic', 'Failed to save updated songs. Please try again.'))
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
