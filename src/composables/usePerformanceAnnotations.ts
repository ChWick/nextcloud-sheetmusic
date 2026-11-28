/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'
import { deleteSongAnnotation, fetchSongAnnotations, saveSongAnnotation } from '../api/annotations'
import type { AnnotationLayer, SheetAnnotation } from '../types/annotation'
import type { Song, SongFile } from '../types/song'
import {
	createDefaultAnnotationLayers,
	filterAndSortAnnotationsForLayers,
	getCachedFileAnnotationLayers,
	loadFileAnnotationLayers,
	persistFileAnnotationLayers,
	toggleAnnotationLayerVisibility,
} from '../utils/annotationLayers'
import { createUuid } from './useAnnotationEditor'

export interface UsePerformanceAnnotationsOptions {
	libraryId: Ref<number>
	currentSong: ComputedRef<Song | null>
	currentFile: ComputedRef<SongFile | null>
	currentFileId: ComputedRef<number>
	isSingleSongMode: ComputedRef<boolean>
	initialAnnotations: Ref<SheetAnnotation[] | undefined>
	canStylusQuickDraw: ComputedRef<boolean>
	showAnnotations: Ref<boolean>
	onEnsureAnnotationsVisible: () => void
	onEmitAnnotations?: (annotations: SheetAnnotation[]) => void
}

/**
 * Resolves the primary numeric file identifier for a song and its selected file.
 *
 * @param song Active or adjacent song
 * @param file Selected primary file for the song
 */
export function getSongPrimaryFileId(song: Song | null, file: SongFile | null): number {
	return Number(file?.fileId ?? file?.id ?? song?.files?.[0]?.fileId ?? song?.files?.[0]?.id ?? 0)
}

function getAnnotationCacheKey(songId: number, fileId: number): string {
	return `${songId}:${fileId}`
}

/**
 * Manages cached annotation & layer loading, background preloading, and
 * optimistic stylus quick-draw mutations in Performance Mode without
 * flashing stale annotations across song transitions.
 *
 * @param opts Reactive song/file state and viewer callbacks
 */
export function usePerformanceAnnotations(opts: UsePerformanceAnnotationsOptions) {
	const annotations = ref<SheetAnnotation[]>([])
	const annotationLayers = ref<AnnotationLayer[]>(createDefaultAnnotationLayers())
	const annotationsCache = new Map<string, SheetAnnotation[]>()
	const pendingQuickDeleteUuids = new Set<string>()
	let annotationRequestId = 0

	const visibleAnnotations = computed(() => {
		const songId = opts.currentSong.value?.id
		const scoped = songId
			? annotations.value.filter((ann) => !ann.songId || ann.songId === songId)
			: annotations.value
		return filterAndSortAnnotationsForLayers(scoped, annotationLayers.value)
	})

	function preloadSongAnnotations(song: Song | null, file: SongFile | null, seenKeys?: Set<string>) {
		if (!song || opts.libraryId.value <= 0) return
		const fileId = getSongPrimaryFileId(song, file)
		if (fileId > 0) {
			void loadFileAnnotationLayers(fileId).catch(() => {})
		}
		const cacheKey = getAnnotationCacheKey(song.id, fileId)
		if (annotationsCache.has(cacheKey) || seenKeys?.has(cacheKey)) return
		seenKeys?.add(cacheKey)
		void fetchSongAnnotations(song.id, fileId || undefined)
			.then((list) => {
				annotationsCache.set(cacheKey, list)
			})
			.catch(() => {})
	}

	watch([opts.currentSong, opts.currentFile], async ([song]) => {
		const reqId = ++annotationRequestId
		const targetFileId = opts.currentFileId.value
		annotationLayers.value = getCachedFileAnnotationLayers(targetFileId)
		if (targetFileId > 0) {
			void loadFileAnnotationLayers(targetFileId).then((loaded) => {
				if (reqId === annotationRequestId) {
					annotationLayers.value = loaded
				}
			})
		}
		if (!song || opts.libraryId.value <= 0) {
			annotations.value = []
			return
		}
		if (opts.isSingleSongMode.value && opts.initialAnnotations.value !== undefined) {
			annotations.value = opts.initialAnnotations.value
			return
		}
		const cacheKey = getAnnotationCacheKey(song.id, targetFileId)
		// Swap synchronously to cached annotations (or empty) so the previous song's
		// annotations never flash on the newly rendered song while fetching.
		annotations.value = annotationsCache.get(cacheKey) ?? []
		try {
			const list = await fetchSongAnnotations(song.id, targetFileId || undefined)
			annotationsCache.set(cacheKey, list)
			if (reqId === annotationRequestId) {
				annotations.value = list
			}
		} catch {
			if (reqId === annotationRequestId && !annotationsCache.has(cacheKey)) {
				annotations.value = []
			}
		}
	}, { immediate: true })

	function onToggleLayerVisibility(layerId: number) {
		const next = toggleAnnotationLayerVisibility(annotationLayers.value, layerId)
		annotationLayers.value = next
		const targetFileId = opts.currentFileId.value
		if (targetFileId > 0) {
			void persistFileAnnotationLayers(targetFileId, next)
		}
		const toggled = next.find((l) => l.id === layerId)
		if (toggled?.visible && !opts.showAnnotations.value) {
			opts.onEnsureAnnotationsVisible()
		}
	}

	async function onQuickCreateAnnotation(draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> & { uuid?: string }) {
		if (!opts.canStylusQuickDraw.value || !opts.currentSong.value) return
		const songId = opts.currentSong.value.id
		const fileId = opts.currentFileId.value
		const reqIdAtStart = annotationRequestId
		const visibleLayer = annotationLayers.value.find((l) => l.visible)
		const targetLayerId = visibleLayer?.id ?? 0
		const optimistic: SheetAnnotation = {
			...draft,
			uuid: draft.uuid || createUuid(),
			songId,
			fileId,
			layerId: draft.layerId ?? targetLayerId,
		}
		const cacheKey = getAnnotationCacheKey(songId, fileId)
		annotations.value = [...annotations.value, optimistic]
		annotationsCache.set(cacheKey, annotations.value)
		if (opts.isSingleSongMode.value) {
			opts.onEmitAnnotations?.(annotations.value)
		}
		if (!opts.showAnnotations.value) {
			opts.onEnsureAnnotationsVisible()
		}
		try {
			const saved = await saveSongAnnotation(songId, optimistic)
			if (pendingQuickDeleteUuids.delete(optimistic.uuid)) {
				if (saved.id) {
					void deleteSongAnnotation(saved.id).catch(() => {})
				}
				return
			}
			const cachedList = annotationsCache.get(cacheKey)
			if (cachedList) {
				annotationsCache.set(cacheKey, cachedList.map((a) => (a.uuid === optimistic.uuid ? saved : a)))
			}
			if (reqIdAtStart !== annotationRequestId) return
			annotations.value = annotations.value.map((a) => (a.uuid === optimistic.uuid ? saved : a))
			annotationsCache.set(cacheKey, annotations.value)
			if (opts.isSingleSongMode.value) {
				opts.onEmitAnnotations?.(annotations.value)
			}
		} catch {
			// Keep optimistic stroke locally if offline or transient save error
		}
	}

	async function onQuickDeleteAnnotation(ann: SheetAnnotation) {
		if (!opts.canStylusQuickDraw.value) return
		annotations.value = annotations.value.filter((a) => a.uuid !== ann.uuid)
		if (opts.currentSong.value) {
			annotationsCache.set(getAnnotationCacheKey(opts.currentSong.value.id, opts.currentFileId.value), annotations.value)
		}
		if (opts.isSingleSongMode.value) {
			opts.onEmitAnnotations?.(annotations.value)
		}
		if (ann.id) {
			try {
				await deleteSongAnnotation(ann.id)
			} catch {
				// Ignore transient delete errors
			}
		} else if (ann.uuid) {
			pendingQuickDeleteUuids.add(ann.uuid)
		}
	}

	return {
		annotations,
		annotationLayers,
		visibleAnnotations,
		preloadSongAnnotations,
		onToggleLayerVisibility,
		onQuickCreateAnnotation,
		onQuickDeleteAnnotation,
	}
}
