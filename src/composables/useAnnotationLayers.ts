/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, watch, type ComputedRef, type Ref } from 'vue'
import type { AnnotationLayer, SheetAnnotation } from '../types/annotation'
import {
	addAnnotationLayer,
	DEFAULT_LAYER_ID,
	filterAndSortAnnotationsForLayers,
	getCachedFileAnnotationLayers,
	loadFileAnnotationLayers,
	persistFileAnnotationLayers,
	removeAnnotationLayer,
	removeLayerIdFromAnnotation,
	renameAnnotationLayer,
	reorderAnnotationLayer,
	toggleAnnotationLayerVisibility,
} from '../utils/annotationLayers'

export interface UseAnnotationLayersOptions {
	fileId: () => number
	annotations: Ref<SheetAnnotation[]>
	selectedAnnotation: ComputedRef<SheetAnnotation | null>
	onClearSelection: () => void
	onCreateAnnotation: (draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> & { uuid?: string }) => Promise<SheetAnnotation> | void
	onUpdateAnnotation: (before: SheetAnnotation, after: SheetAnnotation, recordHistory?: boolean) => Promise<SheetAnnotation> | void
}

/**
 * Manages per-file annotation layers (CRUD, reordering, visibility, multi-layer assignment,
 * and z-ordered visibility filtering).
 */
export function useAnnotationLayers(opts: UseAnnotationLayersOptions) {
	const layers = ref<AnnotationLayer[]>(getCachedFileAnnotationLayers(opts.fileId()))
	const activeLayerId = ref<number>(DEFAULT_LAYER_ID)

	watch(
		opts.fileId,
		(fileId) => {
			layers.value = getCachedFileAnnotationLayers(fileId)
			activeLayerId.value = DEFAULT_LAYER_ID
			void loadFileAnnotationLayers(fileId).then((loaded) => {
				if (opts.fileId() === fileId) {
					layers.value = loaded
					if (!loaded.some((l) => l.id === activeLayerId.value)) {
						activeLayerId.value = DEFAULT_LAYER_ID
					}
				}
			})
		},
		{ immediate: true },
	)

	function saveLayers(next: AnnotationLayer[]) {
		layers.value = next
		void persistFileAnnotationLayers(opts.fileId(), next)
	}

	function addLayer(name: string) {
		const { layers: next, created } = addAnnotationLayer(layers.value, name)
		saveLayers(next)
		activeLayerId.value = created.id
	}

	function renameLayer(id: number, name: string) {
		saveLayers(renameAnnotationLayer(layers.value, id, name))
	}

	function toggleLayerVisibility(id: number) {
		const next = toggleAnnotationLayerVisibility(layers.value, id)
		saveLayers(next)
		if (opts.selectedAnnotation.value && !filterAndSortAnnotationsForLayers([opts.selectedAnnotation.value], next).length) {
			opts.onClearSelection()
		}
	}

	function reorderLayer(id: number, direction: 'up' | 'down') {
		saveLayers(reorderAnnotationLayer(layers.value, id, direction))
	}

	function deleteLayer(id: number) {
		if (id === DEFAULT_LAYER_ID) return
		const next = removeAnnotationLayer(layers.value, id)
		saveLayers(next)
		if (activeLayerId.value === id) {
			activeLayerId.value = DEFAULT_LAYER_ID
		}
		for (const ann of [...opts.annotations.value]) {
			const updatedIds = removeLayerIdFromAnnotation(ann, id)
			if (updatedIds) {
				void opts.onUpdateAnnotation(
					ann,
					{
						...ann,
						layerIndex: updatedIds[0] ?? DEFAULT_LAYER_ID,
						data: { ...ann.data, layerIds: updatedIds },
					},
					false,
				)
			}
		}
	}

	function updateSelectedLayers(layerIds: number[]) {
		const sel = opts.selectedAnnotation.value
		if (!sel || layerIds.length === 0) return
		const cleanIds = Array.from(new Set(layerIds))
		void opts.onUpdateAnnotation(sel, {
			...sel,
			layerIndex: cleanIds[0] ?? DEFAULT_LAYER_ID,
			data: {
				...sel.data,
				layerIds: cleanIds,
			},
		})
	}

	function createAnnotationOnActiveLayer(
		draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> & { uuid?: string },
	) {
		const targetLayerId = layers.value.some((l) => l.id === activeLayerId.value)
			? activeLayerId.value
			: DEFAULT_LAYER_ID
		const targetLayer = layers.value.find((l) => l.id === targetLayerId)
		if (targetLayer && !targetLayer.visible) {
			saveLayers(toggleAnnotationLayerVisibility(layers.value, targetLayerId))
		}
		void opts.onCreateAnnotation({
			...draft,
			layerIndex: targetLayerId,
			data: {
				...draft.data,
				layerIds: [targetLayerId],
			},
		})
	}

	const visibleAnnotations = computed(() =>
		filterAndSortAnnotationsForLayers(opts.annotations.value, layers.value),
	)

	return {
		layers,
		activeLayerId,
		visibleAnnotations,
		addLayer,
		renameLayer,
		toggleLayerVisibility,
		reorderLayer,
		deleteLayer,
		updateSelectedLayers,
		createAnnotationOnActiveLayer,
	}
}
