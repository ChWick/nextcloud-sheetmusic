import { ref } from 'vue'
import { showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { apiDelete, apiGet, apiPost } from '../api/client'
import { createSetlist, deleteSetlist, fetchSetlists, uploadSetlistFile } from '../api/setlists'
import type { Setlist, Song } from '../types/song'

export type ShareTargetMode = 'new-song' | 'existing-song' | 'setlist-file' | 'new-setlist'

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

	async function createTargetSong(libraryId: number, title: string): Promise<number> {
		const createRes = await apiPost('/api/songs', { libraryId, title: title.trim() } as any)
		const createdData = createRes.data.ocs.data as any
		const newSongId = Number(createdData.createdSongId ?? createdData.id)
		if (!newSongId) {
			throw new Error(t('sheetmusic', 'Failed to create song.'))
		}
		return newSongId
	}

	async function createTargetSetlist(libraryId: number, name: string): Promise<number> {
		const createdSetlist = await createSetlist(libraryId, name.trim())
		if (!createdSetlist?.id) {
			throw new Error(t('sheetmusic', 'Failed to create setlist.'))
		}
		return createdSetlist.id
	}

	async function uploadFilesWithRollback(
		files: File[],
		uploadFile: (file: File) => Promise<unknown>,
		rollback?: () => Promise<unknown>,
	): Promise<void> {
		try {
			for (const file of files) {
				await uploadFile(file)
			}
		} catch (uploadError) {
			if (rollback) {
				await rollback().catch(() => {})
			}
			throw uploadError
		}
	}

	async function executeImport(params: {
		mode: ShareTargetMode
		libraryId: number
		files: File[]
		newSongTitle: string
		newSetlistName: string
		songId: number
		setlistId: number
	}): Promise<ShareImportResult | null> {
		isSubmitting.value = true
		errorMessage.value = ''
		try {
			const { mode, libraryId, files, newSongTitle, newSetlistName, songId, setlistId } = params

			if (mode === 'new-song' || mode === 'existing-song') {
				const targetSongId = mode === 'new-song'
					? await createTargetSong(libraryId, newSongTitle)
					: songId
				await uploadFilesWithRollback(
					files,
					(file) => {
						const formData = new FormData()
						formData.append('file', file)
						return apiPost('/api/libraries/{libraryId}/songs/{id}/file/upload', formData as any, {
							pathParams: { libraryId, id: targetSongId },
						})
					},
					mode === 'new-song'
						? () => apiDelete('/api/songs/{id}', { pathParams: { id: targetSongId }, queryParams: { libraryId } })
						: undefined,
				)
				showSuccess(mode === 'new-song'
					? t('sheetmusic', 'Imported shared file into new song.')
					: t('sheetmusic', 'Added shared file to song.'))
				return { targetType: mode, libraryId, songId: targetSongId }
			}

			const targetSetlistId = mode === 'new-setlist'
				? await createTargetSetlist(libraryId, newSetlistName)
				: setlistId
			await uploadFilesWithRollback(
				files,
				(file) => uploadSetlistFile(libraryId, targetSetlistId, file),
				mode === 'new-setlist' ? () => deleteSetlist(libraryId, targetSetlistId) : undefined,
			)
			showSuccess(mode === 'new-setlist'
				? t('sheetmusic', 'Created setlist and attached shared file.')
				: t('sheetmusic', 'Attached shared file to setlist.'))
			return { targetType: mode, libraryId, setlistId: targetSetlistId }
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
