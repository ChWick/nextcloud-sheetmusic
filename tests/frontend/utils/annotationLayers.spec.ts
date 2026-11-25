/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/api/annotations', () => ({
	fetchFileAnnotationLayers: vi.fn(),
	saveFileAnnotationLayers: vi.fn(),
}))

vi.mock('../../../src/composables/useOnlineStatus', () => ({
	isEffectivelyOffline: vi.fn(() => false),
}))

import { fetchFileAnnotationLayers, saveFileAnnotationLayers } from '../../../src/api/annotations'
import { isEffectivelyOffline } from '../../../src/composables/useOnlineStatus'
import type { SheetAnnotation } from '../../../src/types/annotation'
import {
	addAnnotationLayer,
	createDefaultAnnotationLayers,
	DEFAULT_LAYER_ID,
	filterAndSortAnnotationsForLayers,
	getAnnotationLayerIds,
	getCachedFileAnnotationLayers,
	isAnnotationVisibleInLayers,
	loadFileAnnotationLayers,
	persistFileAnnotationLayers,
	removeAnnotationLayer,
	removeLayerIdFromAnnotation,
	renameAnnotationLayer,
	reorderAnnotationLayer,
	sanitizeAnnotationLayers,
	toggleAnnotationLayerVisibility,
} from '../../../src/utils/annotationLayers'

function makeAnn(uuid: string, layerIds?: number[], layerIndex?: number): SheetAnnotation {
	return {
		uuid,
		songId: 1,
		fileId: 10,
		pageNumber: 0,
		layerIndex,
		type: 'stamp',
		x: 0.5,
		y: 0.5,
		width: 0,
		height: 0,
		style: { color: '#000', fontSize: 40 },
		data: { symbol: '\uE522', ...(layerIds ? { layerIds } : {}) },
	}
}

describe('annotationLayers utils', () => {
	beforeEach(() => {
		localStorage.clear()
		vi.clearAllMocks()
		vi.mocked(isEffectivelyOffline).mockReturnValue(false)
	})

	it('always guarantees a default layer (id 0) and contiguous order indices', () => {
		const fromEmpty = sanitizeAnnotationLayers([])
		expect(fromEmpty).toEqual([{ id: DEFAULT_LAYER_ID, name: 'Default', visible: true, order: 0 }])

		const fromCustomOnly = sanitizeAnnotationLayers([{ id: 3, name: 'Organ', visible: false, order: 5 }])
		expect(fromCustomOnly.map((l) => l.id)).toEqual([0, 3])
		expect(fromCustomOnly.map((l) => l.order)).toEqual([0, 1])
	})

	it('supports adding, renaming, toggling visibility, reordering, and removing non-default layers', () => {
		let layers = createDefaultAnnotationLayers()
		const added1 = addAnnotationLayer(layers, 'Fingerings')
		layers = added1.layers
		expect(added1.created).toEqual({ id: 1, name: 'Fingerings', visible: true, order: 1 })

		const added2 = addAnnotationLayer(layers, 'Conductor')
		layers = added2.layers
		expect(layers.map((l) => l.name)).toEqual(['Default', 'Fingerings', 'Conductor'])

		layers = renameAnnotationLayer(layers, 1, 'Piano Fingerings')
		expect(layers[1].name).toBe('Piano Fingerings')

		layers = toggleAnnotationLayerVisibility(layers, 1)
		expect(layers[1].visible).toBe(false)

		layers = reorderAnnotationLayer(layers, 2, 'up')
		expect(layers.map((l) => l.id)).toEqual([0, 2, 1])
		expect(layers.map((l) => l.order)).toEqual([0, 1, 2])

		// Default layer cannot be deleted
		expect(removeAnnotationLayer(layers, DEFAULT_LAYER_ID)).toHaveLength(3)

		// Non-default layer can be deleted
		layers = removeAnnotationLayer(layers, 2)
		expect(layers.map((l) => l.id)).toEqual([0, 1])
		expect(layers.map((l) => l.order)).toEqual([0, 1])
	})

	it('evaluates multi-layer visibility and sorts annotations by highest visible layer order', () => {
		const layers = [
			{ id: 0, name: 'Default', visible: true, order: 0 },
			{ id: 1, name: 'Fingerings', visible: false, order: 1 },
			{ id: 2, name: 'Pedal', visible: true, order: 2 },
		]

		const onDefault = makeAnn('a-default') // falls back to [0]
		const onHiddenOnly = makeAnn('a-hidden', [1])
		const onBothHiddenAndVisible = makeAnn('a-multi', [1, 2])
		const onPedal = makeAnn('a-pedal', [2])

		expect(getAnnotationLayerIds(onDefault)).toEqual([0])
		expect(isAnnotationVisibleInLayers(onDefault, layers)).toBe(true)
		expect(isAnnotationVisibleInLayers(onHiddenOnly, layers)).toBe(false)
		expect(isAnnotationVisibleInLayers(onBothHiddenAndVisible, layers)).toBe(true)

		// Order: a-pedal & a-multi (order 2) render after a-default (order 0)
		const filtered = filterAndSortAnnotationsForLayers(
			[onPedal, onHiddenOnly, onDefault, onBothHiddenAndVisible],
			layers,
		)
		expect(filtered.map((a) => a.uuid)).toEqual(['a-default', 'a-pedal', 'a-multi'])
	})

	it('updates annotation layerIds when a layer is removed, falling back to default layer', () => {
		expect(removeLayerIdFromAnnotation(makeAnn('a', [0]), 2)).toBeNull()
		expect(removeLayerIdFromAnnotation(makeAnn('b', [2]), 2)).toEqual([DEFAULT_LAYER_ID])
		expect(removeLayerIdFromAnnotation(makeAnn('c', [1, 2]), 2)).toEqual([1])
	})

	it('persists and loads per-file layers with offline cache fallback', async () => {
		const customLayers = [
			{ id: 0, name: 'Default', visible: true, order: 0 },
			{ id: 1, name: 'Jazz', visible: true, order: 1 },
		]
		vi.mocked(saveFileAnnotationLayers).mockResolvedValueOnce(customLayers)

		await persistFileAnnotationLayers(42, customLayers)
		expect(getCachedFileAnnotationLayers(42)).toEqual(customLayers)
		expect(saveFileAnnotationLayers).toHaveBeenCalledWith(42, customLayers)

		vi.mocked(isEffectivelyOffline).mockReturnValue(true)
		expect(await loadFileAnnotationLayers(42)).toEqual(customLayers)
		expect(fetchFileAnnotationLayers).not.toHaveBeenCalled()

		vi.mocked(isEffectivelyOffline).mockReturnValue(false)
		vi.mocked(fetchFileAnnotationLayers).mockResolvedValueOnce(customLayers)
		expect(await loadFileAnnotationLayers(42)).toEqual(customLayers)
	})
})
