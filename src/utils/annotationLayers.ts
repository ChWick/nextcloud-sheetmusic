/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { fetchFileAnnotationLayers, saveFileAnnotationLayers } from '../api/annotations'
import { isEffectivelyOffline } from '../composables/useOnlineStatus'
import type { AnnotationLayer, SheetAnnotation } from '../types/annotation'

export const DEFAULT_LAYER_ID = 0
const STORAGE_KEY = 'sheetmusic:file-annotation-layers:v1'

export function createDefaultAnnotationLayers(): AnnotationLayer[] {
	return [{ id: DEFAULT_LAYER_ID, name: 'Default', visible: true, order: 0 }]
}

/**
 * Normalize a layer list: ensure the default layer (id 0) always exists, deduplicate IDs,
 * and assign contiguous 0..n-1 order indices.
 */
export function sanitizeAnnotationLayers(raw: unknown): AnnotationLayer[] {
	const byId = new Map<number, AnnotationLayer>()
	if (Array.isArray(raw)) {
		for (const item of raw) {
			if (!item || typeof item !== 'object') continue
			const rec = item as Partial<AnnotationLayer>
			if (typeof rec.id !== 'number' || !Number.isFinite(rec.id) || rec.id < 0) continue
			const id = Math.floor(rec.id)
			if (byId.has(id)) continue
			const trimmed = typeof rec.name === 'string' ? rec.name.trim() : ''
			byId.set(id, {
				id,
				name: trimmed || (id === DEFAULT_LAYER_ID ? 'Default' : `Layer ${id}`),
				visible: rec.visible !== false,
				order: typeof rec.order === 'number' && Number.isFinite(rec.order) ? rec.order : byId.size,
			})
		}
	}

	if (!byId.has(DEFAULT_LAYER_ID)) {
		byId.set(DEFAULT_LAYER_ID, {
			id: DEFAULT_LAYER_ID,
			name: 'Default',
			visible: true,
			order: -1,
		})
	}

	const sorted = Array.from(byId.values()).sort((a, b) => (a.order - b.order) || (a.id - b.id))
	return sorted.map((layer, idx) => ({ ...layer, order: idx }))
}

/**
 * Return the deduplicated list of layer IDs an annotation belongs to (always at least 1).
 */
export function getAnnotationLayerIds(ann: SheetAnnotation): number[] {
	const raw = ann.data?.layerIds
	if (Array.isArray(raw) && raw.length > 0) {
		const valid = Array.from(
			new Set(raw.filter((id): id is number => typeof id === 'number' && Number.isFinite(id) && id >= 0).map(Math.floor)),
		)
		if (valid.length > 0) return valid
	}
	const fallback = typeof ann.layerIndex === 'number' && Number.isFinite(ann.layerIndex) && ann.layerIndex >= 0
		? Math.floor(ann.layerIndex)
		: DEFAULT_LAYER_ID
	return [fallback]
}

/**
 * An annotation is visible if ANY of its assigned layers is currently visible.
 */
export function isAnnotationVisibleInLayers(
	ann: SheetAnnotation,
	layers: AnnotationLayer[],
): boolean {
	if (!layers || layers.length === 0) return true
	const byId = new Map(layers.map((l) => [l.id, l]))
	const defaultVisible = byId.get(DEFAULT_LAYER_ID)?.visible ?? true
	const ids = getAnnotationLayerIds(ann)
	return ids.some((id) => {
		const layer = byId.get(id)
		return layer ? layer.visible : defaultVisible
	})
}

/**
 * Filter annotations to those visible in the current layer configuration and sort them
 * by their highest visible layer's `order` (preserving relative order within a layer).
 */
export function filterAndSortAnnotationsForLayers(
	annotations: SheetAnnotation[],
	layers: AnnotationLayer[],
): SheetAnnotation[] {
	if (!layers || layers.length <= 1) {
		return annotations.filter((ann) => isAnnotationVisibleInLayers(ann, layers))
	}
	const byId = new Map(layers.map((l) => [l.id, l]))
	const defaultOrder = byId.get(DEFAULT_LAYER_ID)?.order ?? 0

	const visibleWithOrder = annotations
		.map((ann, idx) => {
			if (!isAnnotationVisibleInLayers(ann, layers)) return null
			const ids = getAnnotationLayerIds(ann)
			let maxOrder = -Infinity
			for (const id of ids) {
				const layer = byId.get(id)
				if (layer && layer.visible && layer.order > maxOrder) {
					maxOrder = layer.order
				}
			}
			if (maxOrder === -Infinity) maxOrder = defaultOrder
			return { ann, idx, order: maxOrder }
		})
		.filter((item): item is { ann: SheetAnnotation; idx: number; order: number } => item !== null)

	visibleWithOrder.sort((a, b) => (a.order - b.order) || (a.idx - b.idx))
	return visibleWithOrder.map((item) => item.ann)
}

export function addAnnotationLayer(
	layers: AnnotationLayer[],
	name: string,
): { layers: AnnotationLayer[]; created: AnnotationLayer } {
	const clean = sanitizeAnnotationLayers(layers)
	const maxId = clean.reduce((max, l) => Math.max(max, l.id), DEFAULT_LAYER_ID)
	const nextId = maxId + 1
	const created: AnnotationLayer = {
		id: nextId,
		name: name.trim() || `Layer ${nextId}`,
		visible: true,
		order: clean.length,
	}
	return {
		layers: [...clean, created],
		created,
	}
}

export function renameAnnotationLayer(
	layers: AnnotationLayer[],
	layerId: number,
	newName: string,
): AnnotationLayer[] {
	const trimmed = newName.trim()
	if (!trimmed) return layers
	return layers.map((l) => (l.id === layerId ? { ...l, name: trimmed } : l))
}

export function toggleAnnotationLayerVisibility(
	layers: AnnotationLayer[],
	layerId: number,
): AnnotationLayer[] {
	return layers.map((l) => (l.id === layerId ? { ...l, visible: !l.visible } : l))
}

export function removeAnnotationLayer(
	layers: AnnotationLayer[],
	layerId: number,
): AnnotationLayer[] {
	if (layerId === DEFAULT_LAYER_ID) return layers
	const filtered = layers.filter((l) => l.id !== layerId)
	return sanitizeAnnotationLayers(filtered)
}

export function reorderAnnotationLayer(
	layers: AnnotationLayer[],
	layerId: number,
	direction: 'up' | 'down',
): AnnotationLayer[] {
	const clean = sanitizeAnnotationLayers(layers)
	const idx = clean.findIndex((l) => l.id === layerId)
	if (idx < 0) return clean
	const targetIdx = direction === 'up' ? idx - 1 : idx + 1
	if (targetIdx < 0 || targetIdx >= clean.length) return clean
	const swapped = [...clean]
	const [moved] = swapped.splice(idx, 1)
	swapped.splice(targetIdx, 0, moved)
	return swapped.map((l, i) => ({ ...l, order: i }))
}

/**
 * Compute updated `layerIds` after removing a deleted layer ID from an annotation.
 * Returns null if the annotation did not reference the removed layer.
 */
export function removeLayerIdFromAnnotation(
	ann: SheetAnnotation,
	removedLayerId: number,
): number[] | null {
	const current = getAnnotationLayerIds(ann)
	if (!current.includes(removedLayerId)) return null
	const remaining = current.filter((id) => id !== removedLayerId)
	return remaining.length > 0 ? remaining : [DEFAULT_LAYER_ID]
}

function readCache(): Record<string, AnnotationLayer[]> {
	if (typeof localStorage === 'undefined') return {}
	try {
		const raw = localStorage.getItem(STORAGE_KEY)
		if (!raw) return {}
		const parsed = JSON.parse(raw)
		return parsed && typeof parsed === 'object' ? (parsed as Record<string, AnnotationLayer[]>) : {}
	} catch {
		return {}
	}
}

function writeCache(map: Record<string, AnnotationLayer[]>): void {
	if (typeof localStorage === 'undefined') return
	try {
		localStorage.setItem(STORAGE_KEY, JSON.stringify(map))
	} catch {
		// Ignore quota or private-mode errors
	}
}

export function getCachedFileAnnotationLayers(fileId?: number | null): AnnotationLayer[] {
	if (!fileId || fileId <= 0) return createDefaultAnnotationLayers()
	const stored = readCache()[String(fileId)]
	return stored ? sanitizeAnnotationLayers(stored) : createDefaultAnnotationLayers()
}

export function setCachedFileAnnotationLayers(fileId: number, layers: AnnotationLayer[]): void {
	if (fileId <= 0) return
	const cache = readCache()
	cache[String(fileId)] = sanitizeAnnotationLayers(layers)
	writeCache(cache)
}

export async function loadFileAnnotationLayers(fileId?: number | null): Promise<AnnotationLayer[]> {
	if (!fileId || fileId <= 0) return createDefaultAnnotationLayers()
	const cached = getCachedFileAnnotationLayers(fileId)
	if (isEffectivelyOffline()) return cached
	try {
		const remote = await fetchFileAnnotationLayers(fileId)
		const resolved = sanitizeAnnotationLayers(remote)
		setCachedFileAnnotationLayers(fileId, resolved)
		return resolved
	} catch {
		return cached
	}
}

export async function persistFileAnnotationLayers(fileId: number, layers: AnnotationLayer[]): Promise<void> {
	if (fileId <= 0) return
	const clean = sanitizeAnnotationLayers(layers)
	setCachedFileAnnotationLayers(fileId, clean)
	if (isEffectivelyOffline()) return
	try {
		await saveFileAnnotationLayers(fileId, clean)
	} catch {
		// Offline or unauthenticated: keep local cache
	}
}
