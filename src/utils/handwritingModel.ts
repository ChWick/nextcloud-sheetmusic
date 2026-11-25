/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { NormalizedPoint } from '../types/annotation'
import {
	deleteHandwritingModel,
	fetchHandwritingModel,
	saveHandwritingModel,
} from '../api/annotations'
import { isEffectivelyOffline } from '../composables/useOnlineStatus'
import { VIEWBOX_WIDTH } from './annotation'
import {
	computeNormalizedCloudDistance,
	computeTangents,
	dist2D,
	prepareCloud,
	SAMPLE_COUNT,
	strokePathLength,
	type CloudPoint,
	type PreparedCloud,
} from './handwritingCloud'
import type { Point2D } from './handwritingTemplates'

export {
	computeNormalizedCloudDistance,
	dist2D,
	prepareCloud,
	SAMPLE_COUNT,
	strokePathLength,
	type CloudPoint,
	type PreparedCloud,
}

export interface LearnedHandwritingSample {
	symbol: string
	points: CloudPoint[]
	strokeCount: number
	aspectRatio: number
	/** Higher weight for explicit corrections (1.5) vs implicit accepts (1.0), capped at 2.0 */
	weight: number
	updatedAt: number
}

const STORAGE_KEY = 'sheetmusic:handwriting-model:v1'
const MAX_SAMPLES_PER_SYMBOL = 12
const MAX_TOTAL_SAMPLES = 300
const MERGE_DISTANCE_THRESHOLD = 0.14
const REMOTE_SYNC_DEBOUNCE_MS = 1500

let cachedSamples: LearnedHandwritingSample[] | null = null
let hydratedFromServer = false
let hydrationPromise: Promise<number> | null = null
let remoteSyncTimer: ReturnType<typeof setTimeout> | null = null

function findClosestSample(
	samples: LearnedHandwritingSample[],
	target: PreparedCloud,
	symbol: string,
): { idx: number; dist: number } {
	let idx = -1
	let dist = Infinity
	for (let i = 0; i < samples.length; i++) {
		const existing = samples[i]
		if (existing.symbol !== symbol || existing.strokeCount !== target.strokeCount) continue
		const d = computeNormalizedCloudDistance(target, existing)
		if (d < dist) {
			dist = d
			idx = i
		}
	}
	return { idx, dist }
}

function readStorage(): LearnedHandwritingSample[] {
	if (typeof localStorage === 'undefined') return []
	try {
		const raw = localStorage.getItem(STORAGE_KEY)
		if (!raw) return []
		const parsed = JSON.parse(raw)
		if (!Array.isArray(parsed)) return []
		return parsed.filter(
			(item): item is LearnedHandwritingSample =>
				Boolean(item)
				&& typeof item.symbol === 'string'
				&& Array.isArray(item.points)
				&& item.points.length > 0
				&& typeof item.strokeCount === 'number'
				&& typeof item.aspectRatio === 'number',
		)
	} catch {
		return []
	}
}

function writeStorage(samples: LearnedHandwritingSample[]): void {
	if (typeof localStorage === 'undefined') return
	try {
		localStorage.setItem(STORAGE_KEY, JSON.stringify(samples))
	} catch {
		// Ignore quota or private-mode errors gracefully
	}
}

export function getLearnedSamples(): LearnedHandwritingSample[] {
	if (!cachedSamples) {
		cachedSamples = readStorage()
	}
	return cachedSamples
}

export function getLearnedSampleCount(): number {
	return getLearnedSamples().length
}

/**
 * Active-learning update: record a user-accepted or user-corrected handwriting sample
 * into the persistent personal prototype bank.
 */
export function learnHandwritingSample(
	strokes: NormalizedPoint[][],
	vbHeight: number,
	symbol: string,
	options: { isCorrection?: boolean } = {},
): void {
	const nonEmpty = strokes.filter((s) => s.length > 0)
	if (nonEmpty.length === 0 || !symbol) return
	const canonicalSymbol = symbol.trim()

	const svgStrokes: Point2D[][] = nonEmpty.map((s) =>
		s.map((pt) => ({ x: pt.x * VIEWBOX_WIDTH, y: pt.y * vbHeight })),
	)
	const cloud = prepareCloud(svgStrokes)
	const now = Date.now()
	const samples = [...getLearnedSamples()]

	const { idx: closestIdx, dist: closestDist } = findClosestSample(samples, cloud, canonicalSymbol)

	if (closestIdx >= 0 && closestDist < MERGE_DISTANCE_THRESHOLD && samples[closestIdx].points.length === cloud.points.length) {
		const old = samples[closestIdx]
		const alpha = options.isCorrection ? 0.35 : 0.2
		const blendedRaw = old.points.map((p, idx) => ({
			x: p.x * (1 - alpha) + cloud.points[idx].x * alpha,
			y: p.y * (1 - alpha) + cloud.points[idx].y * alpha,
			strokeId: p.strokeId,
		}))
		samples[closestIdx] = {
			...old,
			points: computeTangents(blendedRaw),
			aspectRatio: Math.round((old.aspectRatio * (1 - alpha) + cloud.aspectRatio * alpha) * 1000) / 1000,
			weight: Math.min(2.0, Math.round((old.weight + (options.isCorrection ? 0.25 : 0.1)) * 100) / 100),
			updatedAt: now,
		}
	} else {
		samples.push({
			symbol: canonicalSymbol,
			points: cloud.points,
			strokeCount: cloud.strokeCount,
			aspectRatio: cloud.aspectRatio,
			weight: options.isCorrection ? 1.5 : 1.0,
			updatedAt: now,
		})

		// Enforce per-symbol cap (evict lowest-weight / oldest sample for this symbol)
		const sameSymbolIndices = samples
			.map((s, idx) => ({ s, idx }))
			.filter(({ s }) => s.symbol === canonicalSymbol)
		if (sameSymbolIndices.length > MAX_SAMPLES_PER_SYMBOL) {
			sameSymbolIndices.sort((a, b) => (a.s.weight - b.s.weight) || (a.s.updatedAt - b.s.updatedAt))
			samples.splice(sameSymbolIndices[0].idx, 1)
		}

		// Enforce global cap
		if (samples.length > MAX_TOTAL_SAMPLES) {
			let evictIdx = 0
			for (let i = 1; i < samples.length; i++) {
				if (
					samples[i].weight < samples[evictIdx].weight
					|| (samples[i].weight === samples[evictIdx].weight && samples[i].updatedAt < samples[evictIdx].updatedAt)
				) {
					evictIdx = i
				}
			}
			samples.splice(evictIdx, 1)
		}
	}

	cachedSamples = samples
	writeStorage(samples)
	scheduleRemoteSync()
}

function scheduleRemoteSync(): void {
	if (remoteSyncTimer !== null) {
		clearTimeout(remoteSyncTimer)
	}
	remoteSyncTimer = setTimeout(() => {
		remoteSyncTimer = null
		if (isEffectivelyOffline()) return
		void saveHandwritingModel(getLearnedSamples()).catch(() => {
			// Offline or unauthenticated (e.g. public share): keep local cache
		})
	}, REMOTE_SYNC_DEBOUNCE_MS)
}

export function mergeSampleLists(
	local: LearnedHandwritingSample[],
	remote: LearnedHandwritingSample[],
): { merged: LearnedHandwritingSample[]; localHadExtras: boolean } {
	const merged = [...remote]
	let localHadExtras = false

	for (const sample of local) {
		const { idx: matchIdx, dist: matchDist } = findClosestSample(merged, sample, sample.symbol)

		if (matchIdx >= 0 && matchDist < MERGE_DISTANCE_THRESHOLD) {
			if ((sample.updatedAt || 0) > (merged[matchIdx].updatedAt || 0)) {
				merged[matchIdx] = sample
				localHadExtras = true
			}
		} else {
			merged.push(sample)
			localHadExtras = true
		}
	}

	// Enforce per-symbol and global caps keeping highest-weight / newest entries
	const bySymbol = new Map<string, LearnedHandwritingSample[]>()
	for (const s of merged) {
		const list = bySymbol.get(s.symbol) ?? []
		list.push(s)
		bySymbol.set(s.symbol, list)
	}
	const pruned: LearnedHandwritingSample[] = []
	for (const list of bySymbol.values()) {
		list.sort((a, b) => (b.weight - a.weight) || (b.updatedAt - a.updatedAt))
		pruned.push(...list.slice(0, MAX_SAMPLES_PER_SYMBOL))
	}
	if (pruned.length > MAX_TOTAL_SAMPLES) {
		pruned.sort((a, b) => (b.weight - a.weight) || (b.updatedAt - a.updatedAt))
		pruned.length = MAX_TOTAL_SAMPLES
	}

	return { merged: pruned, localHadExtras }
}

/**
 * Hydrate the per-user handwriting model from Nextcloud once per session,
 * merging any offline-learned local prototypes and syncing them back if needed.
 */
export async function hydrateHandwritingModelFromServer(force = false): Promise<number> {
	if (hydratedFromServer && !force) {
		return getLearnedSampleCount()
	}
	if (hydrationPromise && !force) {
		return hydrationPromise
	}
	if (isEffectivelyOffline()) {
		return getLearnedSampleCount()
	}

	hydrationPromise = (async () => {
		try {
			const remote = await fetchHandwritingModel()
			const local = getLearnedSamples()
			const { merged, localHadExtras } = mergeSampleLists(local, remote)
			cachedSamples = merged
			writeStorage(merged)
			hydratedFromServer = true
			if (localHadExtras && merged.length > 0) {
				scheduleRemoteSync()
			}
		} catch {
			// Offline or public share: continue with local cache
		} finally {
			hydrationPromise = null
		}
		return getLearnedSampleCount()
	})()

	return hydrationPromise
}

export function resetLearnedHandwritingModel(options: { syncRemote?: boolean } = {}): void {
	if (remoteSyncTimer !== null) {
		clearTimeout(remoteSyncTimer)
		remoteSyncTimer = null
	}
	cachedSamples = []
	hydratedFromServer = Boolean(options.syncRemote)
	hydrationPromise = null
	if (typeof localStorage !== 'undefined') {
		try {
			localStorage.removeItem(STORAGE_KEY)
		} catch {
			// Ignore storage errors
		}
	}
	if (options.syncRemote && !isEffectivelyOffline()) {
		void deleteHandwritingModel().catch(() => {
			// Ignore network errors on reset
		})
	}
}
