/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { NormalizedPoint } from '../types/annotation'
import { VIEWBOX_WIDTH } from './annotation'
import {
	CLUSTER_TOKEN_MAP,
	HANDWRITING_TEMPLATES,
	MULTI_GLYPH_SEQUENCES,
	type HandwritingTemplate,
	type Point2D,
} from './handwritingTemplates'
import { getStampDefinition } from './musicSymbols'

export interface HandwritingCandidate {
	symbol: string
	label: string
	score: number
}

export interface HandwritingRecognitionResult {
	best: HandwritingCandidate | null
	candidates: HandwritingCandidate[]
}

interface CloudPoint {
	x: number
	y: number
	strokeId: number
}

interface PreparedCloud {
	points: CloudPoint[]
	strokeCount: number
	aspectRatio: number
}

interface PreparedTemplate {
	symbol: string
	clusterOnly: boolean
	cloud: PreparedCloud
}

const SAMPLE_COUNT = 32
const MIN_CONFIDENCE = 0.5
const MAX_CANDIDATES = 3

function dist(a: Point2D, b: Point2D): number {
	return Math.hypot(a.x - b.x, a.y - b.y)
}

function strokePathLength(stroke: Point2D[]): number {
	let len = 0
	for (let i = 1; i < stroke.length; i++) {
		len += dist(stroke[i - 1], stroke[i])
	}
	return len
}

function boundingBox(strokes: Point2D[][]): { minX: number; minY: number; maxX: number; maxY: number; w: number; h: number } {
	let minX = Infinity
	let minY = Infinity
	let maxX = -Infinity
	let maxY = -Infinity
	for (const stroke of strokes) {
		for (const p of stroke) {
			if (p.x < minX) minX = p.x
			if (p.y < minY) minY = p.y
			if (p.x > maxX) maxX = p.x
			if (p.y > maxY) maxY = p.y
		}
	}
	return {
		minX,
		minY,
		maxX,
		maxY,
		w: Math.max(0, maxX - minX),
		h: Math.max(0, maxY - minY),
	}
}

function resampleSingleStroke(stroke: Point2D[], count: number, strokeId: number): CloudPoint[] {
	if (stroke.length === 0) return []
	if (count <= 1 || stroke.length === 1) {
		return [{ x: stroke[0].x, y: stroke[0].y, strokeId }]
	}
	const totalLen = strokePathLength(stroke)
	if (totalLen < 1e-6) {
		const out: CloudPoint[] = []
		for (let i = 0; i < count; i++) {
			out.push({ x: stroke[0].x, y: stroke[0].y, strokeId })
		}
		return out
	}

	const interval = totalLen / (count - 1)
	const pts: Point2D[] = stroke.map((p) => ({ x: p.x, y: p.y }))
	const out: CloudPoint[] = [{ x: pts[0].x, y: pts[0].y, strokeId }]
	let acc = 0

	for (let i = 1; i < pts.length; i++) {
		const d = dist(pts[i - 1], pts[i])
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

function prepareCloud(strokes: Point2D[][]): PreparedCloud {
	const lengths = strokes.map((s) => Math.max(1e-3, strokePathLength(s)))
	const totalLen = lengths.reduce((sum, l) => sum + l, 0)

	// Allocate sample points proportionally to each stroke's length (at least 2 per stroke)
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

	const raw: CloudPoint[] = []
	for (let i = 0; i < strokes.length; i++) {
		raw.push(...resampleSingleStroke(strokes[i], counts[i], i))
	}

	// Isotropic scale to [0..1] preserving true aspect ratio
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
	const aspectRatio = w / Math.max(h, 1e-3)

	const scaled = raw.map((p) => ({
		x: (p.x - minX) / scale,
		y: (p.y - minY) / scale,
		strokeId: p.strokeId,
	}))

	// Translate centroid to origin
	let cx = 0
	let cy = 0
	for (const p of scaled) {
		cx += p.x
		cy += p.y
	}
	cx /= Math.max(1, scaled.length)
	cy /= Math.max(1, scaled.length)

	return {
		points: scaled.map((p) => ({ x: p.x - cx, y: p.y - cy, strokeId: p.strokeId })),
		strokeCount: strokes.length,
		aspectRatio,
	}
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
			const d = Math.hypot(a[i].x - b[j].x, a[i].y - b[j].y)
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

function greedyCloudMatch(a: PreparedCloud, b: PreparedCloud): number {
	const n = a.points.length
	const step = Math.max(1, Math.floor(Math.pow(n, 0.5)))
	let minDist = Infinity
	for (let i = 0; i < n; i += step) {
		const d1 = cloudDistance(a.points, b.points, i)
		const d2 = cloudDistance(b.points, a.points, i)
		const m = Math.min(d1, d2)
		if (m < minDist) minDist = m
	}
	return minDist
}

let preparedTemplatesCache: PreparedTemplate[] | null = null

function getPreparedTemplates(): PreparedTemplate[] {
	if (!preparedTemplatesCache) {
		preparedTemplatesCache = HANDWRITING_TEMPLATES.map((tpl: HandwritingTemplate) => ({
			symbol: tpl.symbol,
			clusterOnly: Boolean(tpl.clusterOnly),
			cloud: prepareCloud(tpl.strokes),
		}))
	}
	return preparedTemplatesCache
}

function makeCandidate(symbol: string, score: number): HandwritingCandidate {
	const def = getStampDefinition(symbol)
	return {
		symbol,
		label: def?.label ?? symbol,
		score: Math.round(Math.max(0, Math.min(1, score)) * 1000) / 1000,
	}
}

function scoreCloudAgainstTemplates(
	cloud: PreparedCloud,
	includeClusterOnly: boolean,
): Map<string, number> {
	const bestBySymbol = new Map<string, number>()
	const templates = getPreparedTemplates()

	for (const tpl of templates) {
		if (!includeClusterOnly && tpl.clusterOnly) continue
		if (includeClusterOnly && !(tpl.symbol in CLUSTER_TOKEN_MAP)) continue

		const strokeDiff = Math.abs(cloud.strokeCount - tpl.cloud.strokeCount)
		if (strokeDiff > 2) continue

		const rawDist = greedyCloudMatch(cloud, tpl.cloud)
		// Normalize distance by average weight sum (~n/2)
		const normDist = rawDist / (cloud.points.length * 0.5)

		// Aspect ratio penalty so thin vertical lines don't match wide shapes
		const arA = Math.max(0.15, Math.min(6, cloud.aspectRatio))
		const arB = Math.max(0.15, Math.min(6, tpl.cloud.aspectRatio))
		const arPenalty = Math.abs(Math.log(arA / arB)) * 0.08

		const strokePenalty = strokeDiff * 0.055
		const score = Math.max(0, 1 - normDist * 1.45 - arPenalty - strokePenalty)

		const prev = bestBySymbol.get(tpl.symbol) ?? 0
		if (score > prev) {
			bestBySymbol.set(tpl.symbol, score)
		}
	}

	return bestBySymbol
}

/**
 * Group strokes into horizontally separated left-to-right clusters (for multi-letter dynamics like mf, pp, sfz, tr).
 */
function clusterStrokesHorizontally(strokes: Point2D[][]): Point2D[][][] {
	if (strokes.length < 2) return [strokes]

	const items = strokes.map((s) => {
		const box = boundingBox([s])
		return { stroke: s, minX: box.minX, maxX: box.maxX, cx: (box.minX + box.maxX) / 2 }
	})
	items.sort((a, b) => a.minX - b.minX)

	const clusters: Array<{ strokes: Point2D[][]; minX: number; maxX: number }> = []
	for (const item of items) {
		const last = clusters[clusters.length - 1]
		if (!last) {
			clusters.push({ strokes: [item.stroke], minX: item.minX, maxX: item.maxX })
			continue
		}
		const overlap = Math.min(last.maxX, item.maxX) - Math.max(last.minX, item.minX)
		const minWidth = Math.max(1, Math.min(last.maxX - last.minX, item.maxX - item.minX))
		// Merge if strokes overlap horizontally by > 15% of the narrower stroke
		if (overlap / minWidth > 0.15) {
			last.strokes.push(item.stroke)
			last.minX = Math.min(last.minX, item.minX)
			last.maxX = Math.max(last.maxX, item.maxX)
		} else {
			clusters.push({ strokes: [item.stroke], minX: item.minX, maxX: item.maxX })
		}
	}

	return clusters.map((c) => c.strokes)
}

function tryGeometricPrimitives(svgStrokes: Point2D[][]): HandwritingCandidate[] | null {
	const box = boundingBox(svgStrokes)

	if (svgStrokes.length === 1) {
		const s = svgStrokes[0]
		const len = strokePathLength(s)
		const chord = dist(s[0], s[s.length - 1])
		const straightness = len > 1e-3 ? chord / len : 1

		// Staccato dot: tiny tap or tight dot
		if (Math.max(box.w, box.h) < 8 || len < 10) {
			return [makeCandidate('𝅼', 0.92), makeCandidate('𝄒', 0.5)]
		}

		// Tenuto: nearly horizontal straight dash
		if (box.w > 12 && box.h / box.w < 0.24 && straightness > 0.88) {
			return [makeCandidate('𝅽', 0.9), makeCandidate('1', 0.45)]
		}

		// Vertical straight line: Fingering 1 (runner-up: staccatissimo)
		if (box.h > 10 && box.w / box.h < 0.28 && straightness > 0.9) {
			return [makeCandidate('1', 0.92), makeCandidate('𝅾', 0.72), makeCandidate('𝄒', 0.48)]
		}
	}

	// Caesura (//): two roughly parallel diagonal slashes
	if (svgStrokes.length === 2) {
		const s0 = svgStrokes[0]
		const s1 = svgStrokes[1]
		const l0 = strokePathLength(s0)
		const l1 = strokePathLength(s1)
		const c0 = dist(s0[0], s0[s0.length - 1])
		const c1 = dist(s1[0], s1[s1.length - 1])
		if (l0 > 10 && l1 > 10 && c0 / l0 > 0.88 && c1 / l1 > 0.88) {
			const v0 = { x: s0[s0.length - 1].x - s0[0].x, y: s0[s0.length - 1].y - s0[0].y }
			const v1 = { x: s1[s1.length - 1].x - s1[0].x, y: s1[s1.length - 1].y - s1[0].y }
			const dot = Math.abs((v0.x * v1.x + v0.y * v1.y) / (c0 * c1))
			const slope0 = Math.abs(v0.x / Math.max(1e-3, Math.abs(v0.y)))
			const b0 = boundingBox([s0])
			const b1 = boundingBox([s1])
			const centerDist = Math.abs((b0.minX + b0.maxX) / 2 - (b1.minX + b1.maxX) / 2)
			if (dot > 0.92 && slope0 > 0.15 && slope0 < 1.3 && centerDist > 4) {
				return [makeCandidate('𝄓', 0.9), makeCandidate('1', 0.45)]
			}
		}
	}

	return null
}

/**
 * Recognize hand-drawn strokes (in normalized [0..1] page coordinates) into ranked music stamp candidates.
 */
export function recognizeHandwriting(
	strokes: NormalizedPoint[][],
	vbHeight: number,
): HandwritingRecognitionResult {
	const nonEmpty = strokes.filter((s) => s.length > 0)
	if (nonEmpty.length === 0) {
		return { best: null, candidates: [] }
	}

	const svgStrokes: Point2D[][] = nonEmpty.map((s) =>
		s.map((pt) => ({ x: pt.x * VIEWBOX_WIDTH, y: pt.y * vbHeight })),
	)

	// Reject obvious scribbles: too many strokes or extreme ink density relative to bounding diagonal
	if (svgStrokes.length > 5) {
		return { best: null, candidates: [] }
	}
	const fullBox = boundingBox(svgStrokes)
	const diag = Math.hypot(fullBox.w, fullBox.h)
	const totalLen = svgStrokes.reduce((sum, s) => sum + strokePathLength(s), 0)
	if (diag > 8 && totalLen / diag > 7.5) {
		return { best: null, candidates: [] }
	}

	// 1. Exact geometric primitives (dots, dashes, straight vertical 1, parallel //)
	const primitiveMatches = tryGeometricPrimitives(svgStrokes)
	if (primitiveMatches) {
		const filtered = primitiveMatches.filter((c) => c.score >= MIN_CONFIDENCE).slice(0, MAX_CANDIDATES)
		return {
			best: filtered[0] ?? null,
			candidates: filtered,
		}
	}

	// 2. Single-glyph point-cloud match against all standalone templates
	const fullCloud = prepareCloud(svgStrokes)
	const combinedScores = scoreCloudAgainstTemplates(fullCloud, false)

	// 3. Multi-glyph horizontal clustering for compound dynamics (pp, mf, mp, sfz, etc.) and tr
	const clusters = clusterStrokesHorizontally(svgStrokes)
	if (clusters.length >= 2 && clusters.length <= 3) {
		const clusterTopTokens = clusters.map((cluster) => {
			const cCloud = prepareCloud(cluster)
			const scores = scoreCloudAgainstTemplates(cCloud, true)
			return Array.from(scores.entries())
				.map(([sym, score]) => ({ token: CLUSTER_TOKEN_MAP[sym], score }))
				.filter((e) => Boolean(e.token) && e.score >= MIN_CONFIDENCE)
				.sort((a, b) => b.score - a.score)
				.slice(0, 2)
		})

		if (clusterTopTokens.every((list) => list.length > 0)) {
			// Evaluate combinations of top-2 tokens per cluster
			const combos: Array<{ seq: string; score: number }> = [{ seq: '', score: 0 }]
			for (const list of clusterTopTokens) {
				const next: Array<{ seq: string; score: number }> = []
				for (const prev of combos) {
					for (const item of list) {
						next.push({ seq: prev.seq + item.token, score: prev.score + item.score })
					}
				}
				combos.length = 0
				combos.push(...next)
			}

			for (const combo of combos) {
				const mappedSymbol = MULTI_GLYPH_SEQUENCES[combo.seq]
				if (!mappedSymbol) continue
				const avgScore = combo.score / clusters.length
				// Slight boost for valid multi-letter dynamic sequence
				const boosted = Math.min(0.98, avgScore + 0.04)
				const prev = combinedScores.get(mappedSymbol) ?? 0
				if (boosted > prev) {
					combinedScores.set(mappedSymbol, boosted)
				}
			}
		}
	}

	const ranked = Array.from(combinedScores.entries())
		.filter(([, score]) => score >= MIN_CONFIDENCE)
		.sort((a, b) => b[1] - a[1])
		.slice(0, MAX_CANDIDATES)
		.map(([symbol, score]) => makeCandidate(symbol, score))

	return {
		best: ranked[0] ?? null,
		candidates: ranked,
	}
}
