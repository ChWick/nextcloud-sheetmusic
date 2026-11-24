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
import type { Point2D } from './handwritingTemplates'

export interface CloudPoint {
	x: number
	y: number
	/** Unit tangent vector along the stroke for $P+ direction-aware matching */
	tx: number
	ty: number
	strokeId: number
}

export interface PreparedCloud {
	points: CloudPoint[]
	strokeCount: number
	aspectRatio: number
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

export const SAMPLE_COUNT = 32
const STORAGE_KEY = 'sheetmusic:handwriting-model:v1'
const MAX_SAMPLES_PER_SYMBOL = 12
const MAX_TOTAL_SAMPLES = 300
const MERGE_DISTANCE_THRESHOLD = 0.14
const REMOTE_SYNC_DEBOUNCE_MS = 1500

let cachedSamples: LearnedHandwritingSample[] | null = null
let hydratedFromServer = false
let hydrationPromise: Promise<number> | null = null
let remoteSyncTimer: ReturnType<typeof setTimeout> | null = null

export function dist2D(a: Point2D, b: Point2D): number {
	return Math.hypot(a.x - b.x, a.y - b.y)
}

export function strokePathLength(stroke: Point2D[]): number {
	let len = 0
	for (let i = 1; i < stroke.length; i++) {
		len += dist2D(stroke[i - 1], stroke[i])
	}
	return len
}

function resampleSingleStroke(stroke: Point2D[], count: number, strokeId: number): Array<{ x: number; y: number; strokeId: number }> {
	if (stroke.length === 0) return []
	if (count <= 1 || stroke.length === 1) {
		return [{ x: stroke[0].x, y: stroke[0].y, strokeId }]
	}
	const totalLen = strokePathLength(stroke)
	if (totalLen < 1e-6) {
		const out: Array<{ x: number; y: number; strokeId: number }> = []
		for (let i = 0; i < count; i++) {
			out.push({ x: stroke[0].x, y: stroke[0].y, strokeId })
		}
		return out
	}

	const interval = totalLen / (count - 1)
	const pts: Point2D[] = stroke.map((p) => ({ x: p.x, y: p.y }))
	const out: Array<{ x: number; y: number; strokeId: number }> = [{ x: pts[0].x, y: pts[0].y, strokeId }]
	let acc = 0

	for (let i = 1; i < pts.length; i++) {
		const d = dist2D(pts[i - 1], pts[i])
		if (acc + d >= interval) {
			const t = (interval - acc) / Math.max(d, 1e-6)
			const nx = pts[i - 1].x + t * (pts[i].x - pts[i - 1].x)
			const ny = pts[i - 1].y + t * (pts[i].y - pts[i - 1].y)
			out.push({ x: nx, y: ny, strokeId })
			pts.splice(i, 0, { x: nx, y: ny })
			acc = 0
		} else {
			acc += d
		}
	}

	while (out.length < count) {
		const last = stroke[stroke.length - 1]
		out.push({ x: last.x, y: last.y, strokeId })
	}
	return out.slice(0, count)
}

function computeTangents(pts: Array<{ x: number; y: number; strokeId: number }>): CloudPoint[] {
	return pts.map((p, i) => {
		const prev = i > 0 && pts[i - 1].strokeId === p.strokeId ? pts[i - 1] : p
		const next = i + 1 < pts.length && pts[i + 1].strokeId === p.strokeId ? pts[i + 1] : p
		const dx = next.x - prev.x
		const dy = next.y - prev.y
		const len = Math.hypot(dx, dy)
		const tx = len > 1e-6 ? dx / len : 0
		const ty = len > 1e-6 ? dy / len : 0
		return {
			x: Math.round(p.x * 10000) / 10000,
			y: Math.round(p.y * 10000) / 10000,
			tx: Math.round(tx * 1000) / 1000,
			ty: Math.round(ty * 1000) / 1000,
			strokeId: p.strokeId,
		}
	})
}

/**
 * Resample, isotropically scale, center, and compute unit tangents for $P+ matching.
 */
export function prepareCloud(strokes: Point2D[][]): PreparedCloud {
	const lengths = strokes.map((s) => Math.max(1e-3, strokePathLength(s)))
	const totalLen = lengths.reduce((sum, l) => sum + l, 0)

	const counts = lengths.map((l) => Math.max(2, Math.round((l / totalLen) * SAMPLE_COUNT)))
	let diff = counts.reduce((a, b) => a + b, 0) - SAMPLE_COUNT
	while (diff !== 0) {
		let bestIdx = 0
		for (let i = 1; i < counts.length; i++) {
			if (diff > 0 && counts[i] > counts[bestIdx]) bestIdx = i
			if (diff < 0 && lengths[i] > lengths[bestIdx]) bestIdx = i
		}
		if (diff > 0 && counts[bestIdx] > 2) {
			counts[bestIdx]--
			diff--
		} else if (diff < 0) {
			counts[bestIdx]++
			diff++
		} else {
			break
		}
	}

	const raw: Array<{ x: number; y: number; strokeId: number }> = []
	for (let i = 0; i < strokes.length; i++) {
		raw.push(...resampleSingleStroke(strokes[i], counts[i], i))
	}

	let minX = Infinity
	let minY = Infinity
	let maxX = -Infinity
	let maxY = -Infinity
	for (const p of raw) {
		if (p.x < minX) minX = p.x
		if (p.y < minY) minY = p.y
		if (p.x > maxX) maxX = p.x
		if (p.y > maxY) maxY = p.y
	}
	const w = maxX - minX
	const h = maxY - minY
	const scale = Math.max(w, h, 1e-6)
	const aspectRatio = Math.round((w / Math.max(h, 1e-3)) * 1000) / 1000

	const scaled = raw.map((p) => ({
		x: (p.x - minX) / scale,
		y: (p.y - minY) / scale,
		strokeId: p.strokeId,
	}))

	let cx = 0
	let cy = 0
	for (const p of scaled) {
		cx += p.x
		cy += p.y
	}
	cx /= Math.max(1, scaled.length)
	cy /= Math.max(1, scaled.length)

	const centered = scaled.map((p) => ({
		x: p.x - cx,
		y: p.y - cy,
		strokeId: p.strokeId,
	}))

	return {
		points: computeTangents(centered),
		strokeCount: strokes.length,
		aspectRatio,
	}
}

function pointPairDistance(a: CloudPoint, b: CloudPoint): number {
	const posDist = Math.hypot(a.x - b.x, a.y - b.y)
	// Undirected tangent alignment (handles strokes drawn in opposite direction cleanly)
	const dot = Math.abs(a.tx * b.tx + a.ty * b.ty)
	const dirDist = 1 - Math.min(1, dot)
	return posDist + 0.22 * dirDist
}

function cloudDistance(a: CloudPoint[], b: CloudPoint[], startIndex: number): number {
	const n = a.length
	const matched = new Uint8Array(b.length)
	let sum = 0
	let i = startIndex
	do {
		let bestIdx = -1
		let bestDist = Infinity
		for (let j = 0; j < b.length; j++) {
			if (matched[j]) continue
			const d = pointPairDistance(a[i], b[j])
			if (d < bestDist) {
				bestDist = d
				bestIdx = j
			}
		}
		if (bestIdx >= 0) {
			matched[bestIdx] = 1
			const weight = 1 - ((i - startIndex + n) % n) / n
			sum += weight * bestDist
		}
		i = (i + 1) % n
	} while (i !== startIndex)
	return sum
}

function orderedSingleStrokeDistance(a: CloudPoint[], b: CloudPoint[]): number {
	const n = Math.min(a.length, b.length)
	if (n === 0) return Infinity
	let fwd = 0
	let rev = 0
	for (let i = 0; i < n; i++) {
		fwd += pointPairDistance(a[i], b[i])
		rev += pointPairDistance(a[i], b[n - 1 - i])
	}
	return Math.min(fwd, rev) / n
}

/**
 * Normalized similarity distance (lower is better; ~0 = identical, ~0.5+ = unrelated).
 * Blends $P+ tangent-aware cloud distance with ordered path distance for single-stroke glyphs.
 */
export function computeNormalizedCloudDistance(a: PreparedCloud, b: PreparedCloud): number {
	const n = a.points.length
	const step = Math.max(1, Math.floor(Math.pow(n, 0.5)))
	let minRaw = Infinity
	for (let i = 0; i < n; i += step) {
		const d1 = cloudDistance(a.points, b.points, i)
		const d2 = cloudDistance(b.points, a.points, i)
		const m = Math.min(d1, d2)
		if (m < minRaw) minRaw = m
	}
	const cloudNorm = minRaw / (n * 0.5)

	if (a.strokeCount === 1 && b.strokeCount === 1 && a.points.length === b.points.length) {
		const ordered = orderedSingleStrokeDistance(a.points, b.points)
		return cloudNorm * 0.65 + ordered * 0.35
	}
	return cloudNorm
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

	const svgStrokes: Point2D[][] = nonEmpty.map((s) =>
		s.map((pt) => ({ x: pt.x * VIEWBOX_WIDTH, y: pt.y * vbHeight })),
	)
	const cloud = prepareCloud(svgStrokes)
	const now = Date.now()
	const samples = [...getLearnedSamples()]

	// Look for a very similar existing prototype for the same symbol & strokeCount to reinforce/blend
	let closestIdx = -1
	let closestDist = Infinity
	for (let i = 0; i < samples.length; i++) {
		const existing = samples[i]
		if (existing.symbol !== symbol || existing.strokeCount !== cloud.strokeCount) continue
		const d = computeNormalizedCloudDistance(cloud, existing)
		if (d < closestDist) {
			closestDist = d
			closestIdx = i
		}
	}

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
			symbol,
			points: cloud.points,
			strokeCount: cloud.strokeCount,
			aspectRatio: cloud.aspectRatio,
			weight: options.isCorrection ? 1.5 : 1.0,
			updatedAt: now,
		})

		// Enforce per-symbol cap (evict lowest-weight / oldest sample for this symbol)
		const sameSymbolIndices = samples
			.map((s, idx) => ({ s, idx }))
			.filter(({ s }) => s.symbol === symbol)
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

function mergeSampleLists(
	local: LearnedHandwritingSample[],
	remote: LearnedHandwritingSample[],
): { merged: LearnedHandwritingSample[]; localHadExtras: boolean } {
	const merged = [...remote]
	let localHadExtras = false

	for (const sample of local) {
		let matchIdx = -1
		let matchDist = Infinity
		for (let i = 0; i < merged.length; i++) {
			const existing = merged[i]
			if (existing.symbol !== sample.symbol || existing.strokeCount !== sample.strokeCount) continue
			const d = computeNormalizedCloudDistance(sample, existing)
			if (d < matchDist) {
				matchDist = d
				matchIdx = i
			}
		}

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
	hydratedFromServer = false
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
