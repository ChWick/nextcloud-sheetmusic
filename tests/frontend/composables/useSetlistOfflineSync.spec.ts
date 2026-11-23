/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Setlist, Song } from '../../../src/types/song'

const {
	mockShowSuccess,
	mockShowError,
	mockGetOfflineSetlistMap,
	mockSyncSetlistOffline,
	mockRemoveSetlistOffline,
} = vi.hoisted(() => ({
	mockShowSuccess: vi.fn(),
	mockShowError: vi.fn(),
	mockGetOfflineSetlistMap: vi.fn(),
	mockSyncSetlistOffline: vi.fn(),
	mockRemoveSetlistOffline: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: mockShowSuccess,
	showError: mockShowError,
}))

vi.mock('../../../src/utils/offlineSetlists', () => ({
	isOfflineSetlistsSupported: () => true,
	getOfflineSetlistMap: mockGetOfflineSetlistMap,
	syncSetlistOffline: mockSyncSetlistOffline,
	removeSetlistOffline: mockRemoveSetlistOffline,
}))

import { useSetlistOfflineSync } from '../../../src/composables/useSetlistOfflineSync'

describe('useSetlistOfflineSync', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockGetOfflineSetlistMap.mockReturnValue({})
	})

	it('filters offline setlist IDs by active libraryId', () => {
		mockGetOfflineSetlistMap.mockReturnValue({
			'1:10': { libraryId: 1, setlistId: 10 },
			'2:20': { libraryId: 2, setlistId: 20 },
		})

		const selectedSetlist = ref<Setlist | null>({ id: 10, name: 'Concert' } as Setlist)
		const songs = ref<Song[]>([])
		const state = useSetlistOfflineSync({
			getLibraryId: () => 1,
			selectedSetlist,
			songs,
		})

		expect(state.offlineSetlistIds.value).toEqual([10])
		expect(state.isSelectedSetlistOffline.value).toBe(true)
	})

	it('syncs selected setlist offline, reports progress, and clears syncing state', async () => {
		let progressSnapshot = ''
		mockSyncSetlistOffline.mockImplementation(async ({ onProgress }) => {
			onProgress(1, 2)
			progressSnapshot = state.selectedSetlistOfflineProgressText.value
			mockGetOfflineSetlistMap.mockReturnValue({ '1:10': { libraryId: 1, setlistId: 10 } })
		})

		const selectedSetlist = ref<Setlist | null>({ id: 10, name: 'Concert' } as Setlist)
		const songs = ref<Song[]>([])
		const state = useSetlistOfflineSync({
			getLibraryId: () => 1,
			selectedSetlist,
			songs,
		})

		await state.toggleSelectedSetlistOffline()

		expect(progressSnapshot).toBe('1/2')
		expect(state.isSelectedSetlistSyncingOffline.value).toBe(false)
		expect(state.isSelectedSetlistOffline.value).toBe(true)
		expect(mockShowSuccess).toHaveBeenCalledTimes(1)
	})

	it('removes setlist from offline storage when already cached and handles sync errors', async () => {
		mockGetOfflineSetlistMap.mockReturnValue({ '1:10': { libraryId: 1, setlistId: 10 } })
		mockRemoveSetlistOffline.mockImplementation(async () => {
			mockGetOfflineSetlistMap.mockReturnValue({})
		})

		const selectedSetlist = ref<Setlist | null>({ id: 10, name: 'Concert' } as Setlist)
		const songs = ref<Song[]>([])
		const state = useSetlistOfflineSync({
			getLibraryId: () => 1,
			selectedSetlist,
			songs,
		})

		await state.toggleSelectedSetlistOffline()
		expect(mockRemoveSetlistOffline).toHaveBeenCalledWith(1, 10)
		expect(state.isSelectedSetlistOffline.value).toBe(false)

		mockSyncSetlistOffline.mockRejectedValueOnce(new Error('quota exceeded'))
		await state.toggleSelectedSetlistOffline()
		expect(mockShowError).toHaveBeenCalledTimes(1)
		expect(state.isSelectedSetlistSyncingOffline.value).toBe(false)
	})
})
