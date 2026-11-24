import { computed, ref, type ComputedRef, type Ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiGet, apiPost } from '../api/client'
import { describeRequestFailure } from '../utils/api'
import { useProgressPolling } from './useProgressPolling'
import { ENTITY_COLUMNS } from '../components/songs/song-view-columns'
import { resolveSongEntityIds } from '../components/song-editor/entity-utils'
import type { BatchUpdatePayload } from '../components/song-editor/BatchSongEditorDialog.vue'
import type { LibraryData } from '../types/song'

export function useSongBatchUpdate(options: {
	selectedLibrary: ComputedRef<number> | Ref<number>
	selectedSongIds: Ref<number[]> | ComputedRef<number[]>
	entityListsProps: ComputedRef<Record<string, unknown>>
	onUpdated: (data: LibraryData) => void
	onClearSelection: () => void
}) {
	const isBatchEditorOpen = ref(false)
	const isApplyingBatchUpdate = ref(false)

	const {
		progress: batchProgress,
		start: startBatchPolling,
		stop: stopBatchProgressPolling,
	} = useProgressPolling({
		preserveEmptyMessage: true,
		fetchProgress: async (progressId) => {
			const res = await apiGet('/api/libraries/progress/{progressId}', {
				pathParams: { progressId },
			})
			return res?.data?.ocs?.data as Record<string, unknown> | undefined
		},
	})

	const batchUpdateProgressPercent = computed(() => batchProgress.value?.percent ?? 0)
	const batchUpdateProgressMessage = computed(() => batchProgress.value?.message ?? '')

	async function handleApplyBatchUpdate(payload: BatchUpdatePayload) {
		if (options.selectedSongIds.value.length === 0 || isApplyingBatchUpdate.value) return
		const count = options.selectedSongIds.value.length
		const progressId = `batch-edit-${Date.now()}-${Math.random().toString(36).substring(2, 9)}`
		isApplyingBatchUpdate.value = true
		startBatchPolling(progressId, t('sheetmusic', 'Updating {count} songs…', { count }, { escape: false }))

		try {
			const resolvedPayload: BatchUpdatePayload = { ...payload }
			const selectionsToCreate = ENTITY_COLUMNS
				.filter((col) => (payload[col.songProp]?.newLabels?.length ?? 0) > 0)
				.map((col) => ({
					idField: col.idField,
					endpoint: col.endpoint,
					labelField: col.nameKey,
					selected: (payload[col.songProp]?.newLabels ?? []).map((label) => ({ [col.nameKey]: label })),
					options: ((options.entityListsProps.value as any)[col.songProp] ?? []) as Array<{ id: number; name?: string; title?: string }>,
				}))

			if (selectionsToCreate.length > 0) {
				const createdByField = await resolveSongEntityIds(
					selectionsToCreate,
					async (endpoint, labelField, label) => {
						const res = await apiPost(endpoint as any, {
							libraryId: options.selectedLibrary.value,
							[labelField]: label,
						} as any)
						return res.data.ocs.data.id
					},
				)
				for (const col of ENTITY_COLUMNS) {
					const update = payload[col.songProp]
					if (update?.newLabels?.length) {
						resolvedPayload[col.songProp] = {
							action: update.action,
							ids: Array.from(new Set([...update.ids, ...(createdByField[col.idField] ?? [])])),
						}
					}
				}
			}

			const response = await apiPost('/api/songs-batch-update', {
				libraryId: options.selectedLibrary.value,
				songIds: options.selectedSongIds.value,
				progressId,
				...resolvedPayload,
			} as any)
			isBatchEditorOpen.value = false
			options.onClearSelection()
			if (response.data?.ocs?.data) {
				options.onUpdated(response.data.ocs.data)
			}
			showSuccess(t('sheetmusic', 'Successfully updated {count} songs.', { count }, { escape: false }))
		} catch (error) {
			console.error('Failed to batch update songs:', error)
			showError(describeRequestFailure(error, t('sheetmusic', 'Failed to update selected songs.')))
		} finally {
			stopBatchProgressPolling()
			isApplyingBatchUpdate.value = false
		}
	}

	return {
		isBatchEditorOpen,
		isApplyingBatchUpdate,
		batchUpdateProgressPercent,
		batchUpdateProgressMessage,
		handleApplyBatchUpdate,
	}
}
