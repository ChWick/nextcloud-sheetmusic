/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises } from '@vue/test-utils'
import { computed, nextTick, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { deleteSongAnnotation, fetchSongAnnotations, saveSongAnnotation } from '../../../src/api/annotations'
import { getSongPrimaryFileId, usePerformanceAnnotations } from '../../../src/composables/usePerformanceAnnotations'
import type { SheetAnnotation } from '../../../src/types/annotation'
import type { Song, SongFile } from '../../../src/types/song'

vi.mock('../../../src/api/annotations', () => ({
	fetchSongAnnotations: vi.fn(),
	saveSongAnnotation: vi.fn(),
	deleteSongAnnotation: vi.fn(),
}))

vi.mock('../../../src/utils/annotationLayers', async () => {
	const actual = await vi.importActual<typeof import('../../../src/utils/annotationLayers')>('../../../src/utils/annotationLayers')
	return {
		...actual,
		loadFileAnnotationLayers: vi.fn().mockResolvedValue(actual.createDefaultAnnotationLayers()),
		persistFileAnnotationLayers: vi.fn().mockResolvedValue(undefined),
	}
})

function makeAnnotation(overrides: Partial<SheetAnnotation> = {}): SheetAnnotation {
	return {
		id: 1,
		uuid: 'ann-1',
		songId: 10,
		fileId: 100,
		pageNumber: 0,
		layerIndex: 0,
		type: 'text',
		x: 0.1,
		y: 0.1,
		width: 0.2,
		height: 0.1,
		style: { color: '#ff0000' },
		data: { text: 'Note' },
		...overrides,
	}
}

const songA: Song = { id: 10, title: 'Song A', files: [{ id: 100, fileId: 100, path: '/a.pdf' }] }
const songB: Song = { id: 20, title: 'Song B', files: [{ id: 200, fileId: 200, path: '/b.pdf' }] }

describe('usePerformanceAnnotations', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('resolves primary file ID cleanly across fileId and id fallbacks', () => {
		expect(getSongPrimaryFileId(songA, songA.files![0])).toBe(100)
		expect(getSongPrimaryFileId({ id: 1, title: 'X', files: [{ id: 77, path: '/x.pdf' }] }, null)).toBe(77)
		expect(getSongPrimaryFileId(null, null)).toBe(0)
	})

	it('swaps synchronously to preloaded annotations and never flashes previous song annotations while fetching', async () => {
		const annA = makeAnnotation({ id: 1, uuid: 'a-1', songId: 10, fileId: 100 })
		const annB = makeAnnotation({ id: 2, uuid: 'b-1', songId: 20, fileId: 200 })

		let resolveSongBFetch!: (list: SheetAnnotation[]) => void
		vi.mocked(fetchSongAnnotations).mockImplementation((songId: number) => {
			if (songId === 10) return Promise.resolve([annA])
			return new Promise<SheetAnnotation[]>((resolve) => {
				resolveSongBFetch = resolve
			})
		})

		const currentSong = ref<Song | null>(songA)
		const currentFile = computed<SongFile | null>(() => currentSong.value?.files?.[0] ?? null)
		const currentFileId = computed(() => getSongPrimaryFileId(currentSong.value, currentFile.value))

		const state = usePerformanceAnnotations({
			libraryId: ref(1),
			currentSong: computed(() => currentSong.value),
			currentFile,
			currentFileId,
			isSingleSongMode: computed(() => false),
			initialAnnotations: ref(undefined),
			canStylusQuickDraw: computed(() => true),
			showAnnotations: ref(true),
			onEnsureAnnotationsVisible: vi.fn(),
		})

		await flushPromises()
		expect(state.visibleAnnotations.value.map((a) => a.uuid)).toEqual(['a-1'])

		// Switch to Song B (uncached, fetch still pending): must clear Song A's annotation immediately
		currentSong.value = songB
		await flushPromises()
		expect(state.visibleAnnotations.value).toEqual([])

		// Resolve Song B fetch
		resolveSongBFetch([annB])
		await flushPromises()
		expect(state.visibleAnnotations.value.map((a) => a.uuid)).toEqual(['b-1'])

		// Switch back to Song A (already cached): Song A's annotation appears immediately on nextTick without waiting for network
		vi.mocked(fetchSongAnnotations).mockReturnValueOnce(new Promise(() => {}))
		currentSong.value = songA
		await nextTick()
		expect(state.visibleAnnotations.value.map((a) => a.uuid)).toEqual(['a-1'])
	})

	it('handles optimistic quick-draw create/delete and deletes on server if erased before save completes', async () => {
		vi.mocked(fetchSongAnnotations).mockResolvedValue([])
		let resolveSave!: (saved: SheetAnnotation) => void
		vi.mocked(saveSongAnnotation).mockReturnValue(new Promise((resolve) => {
			resolveSave = resolve
		}))
		vi.mocked(deleteSongAnnotation).mockResolvedValue(undefined as any)

		const onEnsureAnnotationsVisible = vi.fn()
		const currentSong = ref<Song | null>(songA)
		const currentFile = computed<SongFile | null>(() => currentSong.value?.files?.[0] ?? null)
		const currentFileId = computed(() => getSongPrimaryFileId(currentSong.value, currentFile.value))

		const state = usePerformanceAnnotations({
			libraryId: ref(1),
			currentSong: computed(() => currentSong.value),
			currentFile,
			currentFileId,
			isSingleSongMode: computed(() => false),
			initialAnnotations: ref(undefined),
			canStylusQuickDraw: computed(() => true),
			showAnnotations: ref(false),
			onEnsureAnnotationsVisible,
		})
		await flushPromises()

		const createPromise = state.onQuickCreateAnnotation({
			uuid: 'draft-1',
			pageNumber: 0,
			type: 'drawing',
			x: 0.1,
			y: 0.1,
			width: 0.2,
			height: 0.2,
			style: { stroke: '#000' },
			data: { points: [{ x: 0.1, y: 0.1 }] },
		})

		expect(onEnsureAnnotationsVisible).toHaveBeenCalledTimes(1)
		expect(state.annotations.value).toHaveLength(1)

		// Delete before server save resolves
		await state.onQuickDeleteAnnotation(state.annotations.value[0])
		expect(state.annotations.value).toHaveLength(0)

		// Server save finishes later -> should immediately delete the server record
		resolveSave(makeAnnotation({ id: 999, uuid: 'draft-1', songId: 10, fileId: 100 }))
		await createPromise
		await flushPromises()

		expect(deleteSongAnnotation).toHaveBeenCalledWith(999)
		expect(state.annotations.value).toHaveLength(0)
	})
})
