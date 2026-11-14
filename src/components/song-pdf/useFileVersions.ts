import { ref, toValue, type MaybeRefOrGetter } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiGet, apiPost } from '../../api/client'
import type { LibraryData } from '../../types/song'
import type { FileVersionInfo } from './SongPdfVersionsModal.vue'

export interface UseFileVersionsOptions {
	libraryId: MaybeRefOrGetter<number | undefined>
	songId: MaybeRefOrGetter<number | undefined>
	fileId: MaybeRefOrGetter<number | string | undefined>
	onReverted?: (data: LibraryData) => void
}

export function useFileVersions(options: UseFileVersionsOptions) {
	const isVersionsModalOpen = ref(false)
	const fileVersions = ref<FileVersionInfo[]>([])
	const isLoadingVersions = ref(false)
	const isRevertingVersion = ref(false)

	async function openVersionsModal() {
		const libraryId = toValue(options.libraryId)
		const songId = toValue(options.songId)
		const fileId = toValue(options.fileId)

		if (libraryId === undefined || songId === undefined || fileId === undefined) {
			return
		}

		isVersionsModalOpen.value = true
		isLoadingVersions.value = true
		fileVersions.value = []

		try {
			const res = await (apiGet as any)(
				'/api/libraries/{libraryId}/songs/{id}/file/{fileId}/versions',
				{
					pathParams: {
						libraryId,
						id: songId,
						fileId,
					},
				},
			)
			const rawData = (res.data as any)?.ocs?.data ?? (res.data as any)?.data ?? res.data
			const rawList = Array.isArray(rawData)
				? rawData
				: (Array.isArray(rawData?.versions) ? rawData.versions : (Array.isArray(rawData?.data) ? rawData.data : []))
			const versions: FileVersionInfo[] = rawList.map((v: any) => ({
				revision: Number(v.revision ?? v.version ?? v.timestamp ?? 0),
				timestamp: Number(v.timestamp ?? v.revision ?? v.version ?? 0),
				size: Number(v.size ?? 0),
			}))
			versions.sort((a, b) => b.timestamp - a.timestamp)
			fileVersions.value = versions
		} catch (err: any) {
			console.error('Failed to load file versions:', err)
			showError(t('sheetmusic', 'Failed to load file versions.'))
		} finally {
			isLoadingVersions.value = false
		}
	}

	async function revertToVersion(revision: number) {
		const libraryId = toValue(options.libraryId)
		const songId = toValue(options.songId)
		const fileId = toValue(options.fileId)

		if (libraryId === undefined || songId === undefined || fileId === undefined) {
			return
		}

		isRevertingVersion.value = true

		try {
			const res = await (apiPost as any)(
				'/api/libraries/{libraryId}/songs/{id}/file/{fileId}/versions/{revision}/revert',
				{},
				{
					pathParams: {
						libraryId,
						id: songId,
						fileId,
						revision,
					},
				},
			)
			showSuccess(t('sheetmusic', 'Reverted PDF to earlier version successfully.'))
			isVersionsModalOpen.value = false
			const data = res.data?.ocs?.data as LibraryData
			options.onReverted?.(data)
		} catch (err: any) {
			console.error('Failed to revert file version:', err)
			showError(t('sheetmusic', 'Failed to revert version: {error}', {
				error: err?.message ?? String(err),
			}, { escape: false }))
		} finally {
			isRevertingVersion.value = false
		}
	}

	return {
		isVersionsModalOpen,
		fileVersions,
		isLoadingVersions,
		isRevertingVersion,
		openVersionsModal,
		revertToVersion,
	}
}
