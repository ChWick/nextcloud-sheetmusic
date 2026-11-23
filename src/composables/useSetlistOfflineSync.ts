/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, type Ref } from 'vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import type { Setlist, Song } from '../types/song'
import {
	getOfflineSetlistMap,
	isOfflineSetlistsSupported,
	removeSetlistOffline,
	syncSetlistOffline,
} from '../utils/offlineSetlists'

export function useSetlistOfflineSync(options: {
	getLibraryId: () => number
	selectedSetlist: Ref<Setlist | null>
	songs: Ref<Song[]>
}) {
	const offlineSupported = isOfflineSetlistsSupported()
	const offlineSetlistMap = ref(getOfflineSetlistMap())
	const syncingProgressBySetlistId = ref<Record<number, string>>({})

	function refreshOfflineMap() {
		offlineSetlistMap.value = getOfflineSetlistMap()
	}

	const offlineSetlistIds = computed(() =>
		Object.values(offlineSetlistMap.value)
			.filter((entry) => entry.libraryId === options.getLibraryId())
			.map((entry) => entry.setlistId),
	)

	const syncingOfflineSetlistIds = computed(() =>
		Object.keys(syncingProgressBySetlistId.value).map(Number),
	)

	const isSelectedSetlistOffline = computed(() =>
		Boolean(options.selectedSetlist.value && offlineSetlistIds.value.includes(options.selectedSetlist.value.id)),
	)

	const isSelectedSetlistSyncingOffline = computed(() =>
		Boolean(options.selectedSetlist.value && options.selectedSetlist.value.id in syncingProgressBySetlistId.value),
	)

	const selectedSetlistOfflineProgressText = computed(() =>
		options.selectedSetlist.value ? (syncingProgressBySetlistId.value[options.selectedSetlist.value.id] ?? '') : '',
	)

	async function removeOffline(setlistId: number) {
		await removeSetlistOffline(options.getLibraryId(), setlistId)
		refreshOfflineMap()
	}

	async function toggleSelectedSetlistOffline() {
		if (!options.selectedSetlist.value) return
		const setlistId = options.selectedSetlist.value.id
		if (setlistId in syncingProgressBySetlistId.value) return

		if (offlineSetlistIds.value.includes(setlistId)) {
			await removeOffline(setlistId)
			showSuccess(t('sheetmusic', 'Removed setlist from offline storage.'))
			return
		}

		syncingProgressBySetlistId.value = {
			...syncingProgressBySetlistId.value,
			[setlistId]: t('sheetmusic', 'Saving offline…'),
		}
		try {
			await syncSetlistOffline({
				libraryId: options.getLibraryId(),
				setlistId,
				songs: options.songs.value,
				onProgress: (done, total) => {
					syncingProgressBySetlistId.value = {
						...syncingProgressBySetlistId.value,
						[setlistId]: `${done}/${total}`,
					}
				},
			})
			refreshOfflineMap()
			showSuccess(t('sheetmusic', 'Setlist is now available offline.'))
		} catch {
			showError(t('sheetmusic', 'Could not cache all setlist files for offline use.'))
		} finally {
			const next = { ...syncingProgressBySetlistId.value }
			delete next[setlistId]
			syncingProgressBySetlistId.value = next
		}
	}

	return {
		offlineSupported,
		offlineSetlistIds,
		syncingOfflineSetlistIds,
		isSelectedSetlistOffline,
		isSelectedSetlistSyncingOffline,
		selectedSetlistOfflineProgressText,
		refreshOfflineMap,
		removeOffline,
		toggleSelectedSetlistOffline,
	}
}
