/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

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

export const SAMPLE_COUNT = 32

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

export function computeTangents(pts: Array<{ x: number; y: number; strokeId: number }>): CloudPoint[] {
	return pts.map((p, i) => {
		// Use a 2-step window when available to smooth out stylus hand-tremor jitter
		const prevIdx = i >= 2 && pts[i - 2].strokeId === p.strokeId
			? i - 2
			: i >= 1 && pts[i - 1].strokeId === p.strokeId
				? i - 1
				: i
		const nextIdx = i + 2 < pts.length && pts[i + 2].strokeId === p.strokeId
			? i + 2
			: i + 1 < pts.length && pts[i + 1].strokeId === p.strokeId
				? i + 1
				: i
		const prev = pts[prevIdx]
		const next = pts[nextIdx]
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
	// Undirected tangent alignment (gentle weight so stylus jitter doesn't over-penalize)
	const dot = Math.abs(a.tx * b.tx + a.ty * b.ty)
	const dirDist = 1 - Math.min(1, dot)
	return posDist + 0.12 * dirDist
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

function isClosedLoop(pts: CloudPoint[]): boolean {
	if (pts.length < 4) return false
	return Math.hypot(pts[0].x - pts[pts.length - 1].x, pts[0].y - pts[pts.length - 1].y) < 0.28
}

/**
 * Normalized similarity distance (lower is better; ~0 = identical, ~0.5+ = unrelated).
 * Blends $P+ tangent-aware cloud distance with ordered path distance for open single-stroke glyphs.
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

	if (
		a.strokeCount === 1
		&& b.strokeCount === 1
		&& a.points.length === b.points.length
		&& !isClosedLoop(a.points)
		&& !isClosedLoop(b.points)
	) {
		const ordered = orderedSingleStrokeDistance(a.points, b.points)
		return cloudNorm * 0.82 + ordered * 0.18
	}
	return cloudNorm
}
