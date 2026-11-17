import { ref } from 'vue'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { apiGet, apiPost } from '../api/client'
import { fetchSetlists, uploadSetlistFile } from '../api/setlists'
import type { Setlist, Song } from '../types/song'

export type ShareTargetMode = 'new-song' | 'existing-song' | 'setlist-file'

export interface ShareImportResult {
	targetType: ShareTargetMode
	libraryId: number
	songId?: number
	setlistId?: number
}

/**
 * Manages loading library targets (songs/setlists) and executing the
 * selected share-target import action.
 */
export function useShareTargetImport() {
	const songs = ref<Song[]>([])
	const setlists = ref<Setlist[]>([])
	const loadedLibraryId = ref(-1)
	const isLoadingTargets = ref(false)
	const isSubmitting = ref(false)
	const errorMessage = ref('')

	function resetTargets() {
		loadedLibraryId.value = -1
		songs.value = []
		setlists.value = []
		errorMessage.value = ''
	}

	async function loadTargets(libraryId: number): Promise<{ firstSongId: number; firstSetlistId: number } | null> {
		if (libraryId < 0 || loadedLibraryId.value === libraryId) {
			return null
		}
		isLoadingTargets.value = true
		errorMessage.value = ''
		try {
			const [songsRes, setlistList] = await Promise.all([
				apiGet('/api/songs', { queryParams: { id: libraryId } }),
				fetchSetlists(libraryId),
			])
			songs.value = (songsRes.data.ocs.data.songs ?? []) as Song[]
			setlists.value = setlistList
			loadedLibraryId.value = libraryId
			return {
				firstSongId: songs.value[0]?.id ?? -1,
				firstSetlistId: setlists.value[0]?.id ?? -1,
			}
		} catch {
			errorMessage.value = t('sheetmusic', 'Could not load songs and setlists for the selected library.')
			return null
		} finally {
			isLoadingTargets.value = false
		}
	}

	async function uploadFilesToSong(libraryId: number, songId: number, files: File[]) {
		for (const file of files) {
			const formData = new FormData()
			formData.append('file', file)
			await apiPost('/api/libraries/{libraryId}/songs/{id}/file/upload', formData as any, {
				pathParams: { libraryId, id: songId },
			})
		}
	}

	async function executeImport(params: {
		mode: ShareTargetMode
		libraryId: number
		files: File[]
		newSongTitle: string
		songId: number
		setlistId: number
	}): Promise<ShareImportResult | null> {
		isSubmitting.value = true
		errorMessage.value = ''
		try {
			const { mode, libraryId, files, newSongTitle, songId, setlistId } = params
			if (mode === 'new-song') {
				const createRes = await apiPost('/api/songs', {
					libraryId,
					title: newSongTitle.trim(),
				} as any)
				const createdData = createRes.data.ocs.data as any
				const newSongId = Number(createdData.createdSongId ?? createdData.id)
				if (!newSongId) {
					throw new Error(t('sheetmusic', 'Failed to create song.'))
				}
				await uploadFilesToSong(libraryId, newSongId, files)
				showSuccess(t('sheetmusic', 'Imported shared file into new song.'))
				return { targetType: 'new-song', libraryId, songId: newSongId }
			}

			if (mode === 'existing-song') {
				await uploadFilesToSong(libraryId, songId, files)
				showSuccess(t('sheetmusic', 'Added shared file to song.'))
				return { targetType: 'existing-song', libraryId, songId }
			}

			for (const file of files) {
				await uploadSetlistFile(libraryId, setlistId, file)
			}
			showSuccess(t('sheetmusic', 'Attached shared file to setlist.'))
			return { targetType: 'setlist-file', libraryId, setlistId }
		} catch (error: any) {
			errorMessage.value = error?.response?.data?.ocs?.meta?.message
				|| error?.message
				|| t('sheetmusic', 'Failed to import shared file.')
			return null
		} finally {
			isSubmitting.value = false
		}
	}

	return {
		songs,
		setlists,
		isLoadingTargets,
		isSubmitting,
		errorMessage,
		resetTargets,
		loadTargets,
		executeImport,
	}
}
