/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { NormalizedPoint } from '../types/annotation'
import { VIEWBOX_WIDTH } from './annotation'
import {
	computeNormalizedCloudDistance,
	dist2D,
	getLearnedSamples,
	prepareCloud,
	strokePathLength,
	type PreparedCloud,
} from './handwritingModel'
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

interface PreparedTemplate {
	symbol: string
	clusterOnly: boolean
	cloud: PreparedCloud
}

const MIN_CONFIDENCE = 0.3
const MIN_ALT_CONFIDENCE = 0.1
const MAX_CANDIDATES = 7

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

function scoreSinglePair(cloud: PreparedCloud, ref: PreparedCloud, bonus = 0): number {
	const strokeDiff = Math.abs(cloud.strokeCount - ref.strokeCount)
	if (strokeDiff > 2) return 0

	const normDist = computeNormalizedCloudDistance(cloud, ref)

	// Aspect ratio penalty so thin vertical lines don't match wide shapes
	const arA = Math.max(0.15, Math.min(6, cloud.aspectRatio))
	const arB = Math.max(0.15, Math.min(6, ref.aspectRatio))
	const arPenalty = Math.abs(Math.log(arA / arB)) * 0.08

	const strokePenalty = strokeDiff * 0.055
	return Math.max(0, Math.min(0.99, 1 - normDist * 1.15 - arPenalty - strokePenalty + bonus))
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

		const score = scoreSinglePair(cloud, tpl.cloud)
		const prev = bestBySymbol.get(tpl.symbol) ?? 0
		if (score > prev) {
			bestBySymbol.set(tpl.symbol, score)
		}
	}

	// Score against user's active-learning prototypes
	if (!includeClusterOnly) {
		const learned = getLearnedSamples()
		for (const sample of learned) {
			const bonus = 0.06 * Math.min(1, (sample.weight || 1) / 1.5)
			const score = scoreSinglePair(cloud, sample, bonus)
			const prev = bestBySymbol.get(sample.symbol) ?? 0
			if (score > prev) {
				bestBySymbol.set(sample.symbol, score)
			}
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
		return { stroke: s, minX: box.minX, maxX: box.maxX }
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
		const chord = dist2D(s[0], s[s.length - 1])
		const straightness = len > 1e-3 ? chord / len : 1

		// Staccato dot: tiny tap or tight dot
		if (Math.max(box.w, box.h) < 8 || len < 10) {
			return [makeCandidate('\uE4A2', 0.92), makeCandidate('\uE4CE', 0.5)]
		}

		// Tenuto: nearly horizontal straight dash
		if (box.w > 12 && box.h / box.w < 0.24 && straightness > 0.88) {
			return [makeCandidate('\uE4A4', 0.9), makeCandidate('\uED11', 0.45)]
		}

		// Vertical or slightly slanted straight line (up to ~28°): Fingering 1 (runner-up: staccatissimo)
		const dy = Math.abs(s[s.length - 1].y - s[0].y)
		const dx = Math.abs(s[s.length - 1].x - s[0].x)
		if (box.h > 10 && straightness > 0.9 && (box.w / box.h < 0.28 || (dy > 10 && dy / Math.max(dx, 0.1) > 1.85))) {
			return [makeCandidate('\uED11', 0.92), makeCandidate('\uE4A6', 0.72), makeCandidate('\uE4CE', 0.48)]
		}
	}

	// Caesura (//): two roughly parallel diagonal slashes
	if (svgStrokes.length === 2) {
		const s0 = svgStrokes[0]
		const s1 = svgStrokes[1]
		const l0 = strokePathLength(s0)
		const l1 = strokePathLength(s1)
		const c0 = dist2D(s0[0], s0[s0.length - 1])
		const c1 = dist2D(s1[0], s1[s1.length - 1])
		if (l0 > 10 && l1 > 10 && c0 / l0 > 0.88 && c1 / l1 > 0.88) {
			const v0 = { x: s0[s0.length - 1].x - s0[0].x, y: s0[s0.length - 1].y - s0[0].y }
			const v1 = { x: s1[s1.length - 1].x - s1[0].x, y: s1[s1.length - 1].y - s1[0].y }
			const dot = Math.abs((v0.x * v1.x + v0.y * v1.y) / (c0 * c1))
			const slope0 = Math.abs(v0.x / Math.max(1e-3, Math.abs(v0.y)))
			const b0 = boundingBox([s0])
			const b1 = boundingBox([s1])
			const centerDist = Math.abs((b0.minX + b0.maxX) / 2 - (b1.minX + b1.maxX) / 2)
			if (dot > 0.92 && slope0 > 0.15 && slope0 < 1.3 && centerDist > 4) {
				return [makeCandidate('\uE4D1', 0.9), makeCandidate('\uED11', 0.45)]
			}
		}
	}

	return null
}

function rankCandidates(scores: Map<string, number>): HandwritingRecognitionResult {
	const ranked = Array.from(scores.entries())
		.filter(([, score]) => score >= MIN_ALT_CONFIDENCE)
		.sort((a, b) => b[1] - a[1])
		.slice(0, MAX_CANDIDATES)
		.map(([symbol, score]) => makeCandidate(symbol, score))

	const best = ranked[0] && ranked[0].score >= MIN_CONFIDENCE ? ranked[0] : null
	return {
		best,
		candidates: ranked,
	}
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
	if (diag > 8 && totalLen / diag > 12) {
		return { best: null, candidates: [] }
	}

	// 1. Score point-cloud against built-in templates + user's learned prototypes
	const fullCloud = prepareCloud(svgStrokes)
	const combinedScores = scoreCloudAgainstTemplates(fullCloud, false)

	// 2. Merge exact geometric primitives (dots, dashes, straight vertical 1, parallel //)
	const primitiveMatches = tryGeometricPrimitives(svgStrokes)
	if (primitiveMatches) {
		for (const prim of primitiveMatches) {
			const prev = combinedScores.get(prim.symbol) ?? 0
			if (prim.score > prev) {
				combinedScores.set(prim.symbol, prim.score)
			}
		}
		return rankCandidates(combinedScores)
	}

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
				const boosted = Math.min(0.98, avgScore + 0.04)
				const prev = combinedScores.get(mappedSymbol) ?? 0
				if (boosted > prev) {
					combinedScores.set(mappedSymbol, boosted)
				}
			}
		}
	}

	return rankCandidates(combinedScores)
}
