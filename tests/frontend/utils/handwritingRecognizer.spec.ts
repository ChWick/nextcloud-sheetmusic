/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest'
import type { NormalizedPoint } from '../../../src/types/annotation'
import {
	getLearnedSampleCount,
	learnHandwritingSample,
	resetLearnedHandwritingModel,
} from '../../../src/utils/handwritingModel'
import { recognizeHandwriting } from '../../../src/utils/handwritingRecognizer'

const VB_HEIGHT = 1414

/** Convert local [0..1] glyph coordinates placed at (ox, oy) with size (w, h) in SVG units into NormalizedPoints */
function placeGlyph(
	strokes: Array<Array<[number, number]>>,
	ox = 400,
	oy = 400,
	w = 32,
	h = 44,
): NormalizedPoint[][] {
	return strokes.map((s) =>
		s.map(([lx, ly]) => ({
			x: (ox + lx * w) / 1000,
			y: (oy + ly * h) / VB_HEIGHT,
		})),
	)
}

function arc(
	cx: number,
	cy: number,
	rx: number,
	ry: number,
	startDeg: number,
	endDeg: number,
	steps = 12,
): Array<[number, number]> {
	const pts: Array<[number, number]> = []
	for (let i = 0; i <= steps; i++) {
		const a = ((startDeg + ((endDeg - startDeg) * i) / steps) * Math.PI) / 180
		pts.push([cx + Math.cos(a) * rx, cy + Math.sin(a) * ry])
	}
	return pts
}

describe('handwritingRecognizer', () => {
	describe('fingerings 0-5', () => {
		it('recognizes 0 (closed oval)', () => {
			const strokes = placeGlyph([arc(0.5, 0.5, 0.34, 0.48, -90, 270, 16)])
			const res = recognizeHandwriting(strokes, VB_HEIGHT)
			expect(res.best?.symbol).toBe('\uED10')
		})

		it('recognizes 1 (vertical, slanted, and European hooked variants)', () => {
			const straight = placeGlyph([[[0.5, 0.05], [0.5, 0.95]]])
			const resStraight = recognizeHandwriting(straight, VB_HEIGHT)
			expect(resStraight.best?.symbol).toBe('\uED11')
			expect(resStraight.candidates.map((c) => c.symbol)).toContain('\uE4A6')

			const slanted = placeGlyph([[[0.65, 0.05], [0.35, 0.95]]], 400, 400, 32, 44)
			expect(recognizeHandwriting(slanted, VB_HEIGHT).best?.symbol).toBe('\uED11')

			const europeanHook = placeGlyph([[[0.12, 0.45], [0.62, 0.05], [0.62, 0.95]]], 400, 400, 30, 46)
			expect(recognizeHandwriting(europeanHook, VB_HEIGHT).best?.symbol).toBe('\uED11')
		})

		it('recognizes 2 (standard, soft/rounded, narrow, and angular variants)', () => {
			const standard = placeGlyph([[
				...arc(0.48, 0.26, 0.32, 0.24, -155, 25, 10),
				[0.14, 0.95],
				[0.88, 0.95],
			]])
			expect(recognizeHandwriting(standard, VB_HEIGHT).best?.symbol).toBe('\uED12')

			const softQuick = placeGlyph([[
				[0.22, 0.25], [0.46, 0.08], [0.75, 0.2], [0.7, 0.44], [0.4, 0.7], [0.18, 0.9], [0.52, 0.93], [0.85, 0.87],
			]], 400, 400, 32, 42)
			expect(recognizeHandwriting(softQuick, VB_HEIGHT).best?.symbol).toBe('\uED12')

			const angular = placeGlyph([[
				[0.16, 0.16], [0.8, 0.14], [0.14, 0.92], [0.88, 0.92],
			]], 400, 400, 32, 42)
			expect(recognizeHandwriting(angular, VB_HEIGHT).best?.symbol).toBe('\uED12')
		})

		it('recognizes 3', () => {
			const strokes = placeGlyph([[
				...arc(0.46, 0.26, 0.32, 0.23, -150, 85, 9),
				...arc(0.48, 0.74, 0.35, 0.24, -85, 155, 10),
			]])
			const res = recognizeHandwriting(strokes, VB_HEIGHT)
			expect(res.best?.symbol).toBe('\uED13')
		})

		it('recognizes 4 (open, European short-stem, vertical-L, and 1-stroke variants)', () => {
			const open = placeGlyph([
				[[0.32, 0.05], [0.12, 0.62], [0.88, 0.62]],
				[[0.68, 0.08], [0.68, 0.95]],
			])
			expect(recognizeHandwriting(open, VB_HEIGHT).best?.symbol).toBe('\uED14')

			const europeanShortStem = placeGlyph([
				[[0.4, 0.06], [0.12, 0.6], [0.88, 0.6]],
				[[0.64, 0.36], [0.64, 0.95]],
			])
			expect(recognizeHandwriting(europeanShortStem, VB_HEIGHT).best?.symbol).toBe('\uED14')

			const verticalL = placeGlyph([
				[[0.2, 0.06], [0.2, 0.6], [0.86, 0.6]],
				[[0.66, 0.34], [0.66, 0.95]],
			])
			expect(recognizeHandwriting(verticalL, VB_HEIGHT).best?.symbol).toBe('\uED14')

			const oneStrokeTopDown = placeGlyph([[
				[0.34, 0.06], [0.12, 0.62], [0.86, 0.62], [0.66, 0.3], [0.66, 0.95],
			]])
			expect(recognizeHandwriting(oneStrokeTopDown, VB_HEIGHT).best?.symbol).toBe('\uED14')
		})

		it('recognizes 1-stroke 5', () => {
			const strokes = placeGlyph([[
				[0.82, 0.06],
				[0.22, 0.06],
				[0.18, 0.45],
				...arc(0.48, 0.68, 0.34, 0.26, -125, 145, 10),
			]])
			const res = recognizeHandwriting(strokes, VB_HEIGHT)
			expect(res.best?.symbol).toBe('\uED15')
		})
	})

	describe('accidentals', () => {
		it('recognizes sharp (#)', () => {
			const strokes = placeGlyph([
				[[0.32, 0.05], [0.26, 0.95]],
				[[0.74, 0.05], [0.68, 0.95]],
				[[0.06, 0.36], [0.94, 0.28]],
				[[0.06, 0.72], [0.94, 0.64]],
			])
			const res = recognizeHandwriting(strokes, VB_HEIGHT)
			expect(res.best?.symbol).toBe('\uE262')
		})

		it('recognizes flat (b)', () => {
			const strokes = placeGlyph([[
				[0.22, 0.05],
				[0.22, 0.95],
				...arc(0.44, 0.72, 0.34, 0.22, 110, -135, 10),
			]])
			const res = recognizeHandwriting(strokes, VB_HEIGHT)
			expect(res.best?.symbol).toBe('\uE260')
		})

		it('recognizes natural (♮)', () => {
			const strokes = placeGlyph([
				[[0.26, 0.05], [0.26, 0.68], [0.76, 0.6]],
				[[0.26, 0.38], [0.76, 0.3], [0.76, 0.95]],
			])
			const res = recognizeHandwriting(strokes, VB_HEIGHT)
			expect(res.best?.symbol).toBe('\uE261')
		})

		it('recognizes double sharp (x)', () => {
			const strokes = placeGlyph([
				[[0.12, 0.12], [0.88, 0.88]],
				[[0.88, 0.12], [0.12, 0.88]],
			], 400, 400, 36, 36)
			const res = recognizeHandwriting(strokes, VB_HEIGHT)
			expect(res.best?.symbol).toBe('\uE263')
		})
	})

	describe('dynamics (single and compound)', () => {
		it('recognizes p and f', () => {
			const pStrokes = placeGlyph([[
				[0.24, 0.95],
				[0.24, 0.08],
				...arc(0.48, 0.3, 0.32, 0.22, -135, 145, 10),
			]])
			expect(recognizeHandwriting(pStrokes, VB_HEIGHT).best?.symbol).toBe('\uE520')

			const fStrokes = placeGlyph([
				[[0.8, 0.1], [0.6, 0.04], [0.44, 0.2], [0.36, 0.92], [0.18, 0.96]],
				[[0.16, 0.46], [0.74, 0.46]],
			])
			expect(recognizeHandwriting(fStrokes, VB_HEIGHT).best?.symbol).toBe('\uE522')
		})

		it('recognizes compound dynamic mf from horizontally separated strokes', () => {
			const mGlyph = placeGlyph([[
				[0.06, 0.88], [0.12, 0.2], [0.28, 0.14], [0.44, 0.85], [0.56, 0.2], [0.72, 0.14], [0.92, 0.88],
			]], 350, 400, 34, 34)
			const fGlyph = placeGlyph([
				[[0.8, 0.1], [0.6, 0.04], [0.44, 0.2], [0.36, 0.92], [0.18, 0.96]],
				[[0.16, 0.46], [0.74, 0.46]],
			], 396, 395, 28, 44)
			const res = recognizeHandwriting([...mGlyph, ...fGlyph], VB_HEIGHT)
			expect(res.best?.symbol).toBe('\uE52D')
		})

		it('recognizes compound dynamic pp', () => {
			const p1 = placeGlyph([[
				[0.24, 0.95], [0.24, 0.08], ...arc(0.48, 0.3, 0.32, 0.22, -135, 145, 10),
			]], 350, 400, 28, 42)
			const p2 = placeGlyph([[
				[0.24, 0.95], [0.24, 0.08], ...arc(0.48, 0.3, 0.32, 0.22, -135, 145, 10),
			]], 390, 400, 28, 42)
			const res = recognizeHandwriting([...p1, ...p2], VB_HEIGHT)
			expect(res.best?.symbol).toBe('\uE52B')
		})
	})

	describe('organ pedaling symbols', () => {
		it('recognizes heel (∪) and toe (∧)', () => {
			const unionU = placeGlyph([[
				[0.16, 0.1], [0.16, 0.56], ...arc(0.5, 0.58, 0.34, 0.34, 180, 0, 10), [0.84, 0.1],
			]], 400, 400, 34, 34)
			expect(recognizeHandwriting(unionU, VB_HEIGHT).best?.symbol).toBe('\uE661')

			const toeInvV = placeGlyph([[[0.08, 0.86], [0.5, 0.14], [0.92, 0.86]]], 400, 400, 36, 30)
			const invRes = recognizeHandwriting(toeInvV, VB_HEIGHT)
			expect(invRes.best?.symbol).toBe('\uE664')
			expect(invRes.candidates.map((c) => c.symbol)).toContain('\uE4AC')
		})

		it('recognizes string bowing marks (down bow 𝆪 and up bow 𝆫)', () => {
			const downBow = placeGlyph([[
				[0.16, 0.9], [0.16, 0.16], [0.84, 0.16], [0.84, 0.9],
			]], 400, 400, 34, 34)
			expect(recognizeHandwriting(downBow, VB_HEIGHT).best?.symbol).toBe('\uE610')

			const upBow = placeGlyph([[[0.14, 0.12], [0.5, 0.9], [0.86, 0.12]]], 400, 400, 30, 34)
			expect(recognizeHandwriting(upBow, VB_HEIGHT).best?.symbol).toBe('\uE612')
		})
	})

	describe('articulations and ornaments', () => {
		it('recognizes staccato dot, tenuto dash, accent, and marcato', () => {
			const dot = placeGlyph([[[0.5, 0.5], [0.52, 0.52]]], 400, 400, 6, 6)
			expect(recognizeHandwriting(dot, VB_HEIGHT).best?.symbol).toBe('\uE4A2')

			const dash = placeGlyph([[[0.05, 0.5], [0.95, 0.5]]], 400, 400, 32, 20)
			expect(recognizeHandwriting(dash, VB_HEIGHT).best?.symbol).toBe('\uE4A4')

			const accent = placeGlyph([[[0.1, 0.18], [0.9, 0.5], [0.1, 0.82]]], 400, 400, 36, 26)
			expect(recognizeHandwriting(accent, VB_HEIGHT).best?.symbol).toBe('\uE4A0')

			const marcato = placeGlyph([[[0.2, 0.9], [0.5, 0.08], [0.8, 0.9]]], 400, 400, 26, 36)
			expect(recognizeHandwriting(marcato, VB_HEIGHT).best?.symbol).toBe('\uE4AC')
		})

		it('recognizes fermata above, caesura, turn, and coda', () => {
			const fermata = placeGlyph([
				arc(0.5, 0.75, 0.44, 0.55, 180, 360, 12),
				[[0.48, 0.76], [0.52, 0.8]],
			], 400, 400, 42, 30)
			expect(recognizeHandwriting(fermata, VB_HEIGHT).best?.symbol).toBe('\uE4C0')

			const caesura = placeGlyph([
				[[0.14, 0.9], [0.46, 0.1]],
				[[0.54, 0.9], [0.86, 0.1]],
			], 400, 400, 36, 40)
			expect(recognizeHandwriting(caesura, VB_HEIGHT).best?.symbol).toBe('\uE4D1')

			const turn = placeGlyph([[
				[0.06, 0.66], [0.24, 0.22], [0.5, 0.5], [0.76, 0.78], [0.94, 0.34],
			]], 400, 400, 44, 24)
			expect(recognizeHandwriting(turn, VB_HEIGHT).best?.symbol).toBe('\uE567')

			const coda = placeGlyph([
				arc(0.5, 0.5, 0.34, 0.38, -90, 270, 14),
				[[0.5, 0.04], [0.5, 0.96]],
				[[0.06, 0.5], [0.94, 0.5]],
			], 400, 400, 40, 40)
			expect(recognizeHandwriting(coda, VB_HEIGHT).best?.symbol).toBe('\uE048')
		})
	})

	describe('unrecognized input', () => {
		it('returns null for empty strokes or chaotic scribbles', () => {
			expect(recognizeHandwriting([], VB_HEIGHT).best).toBeNull()

			const scribble: Array<[number, number]> = []
			for (let i = 0; i < 40; i++) {
				scribble.push([
					(i % 2 === 0 ? 0.05 : 0.95) + ((i * 7) % 10) * 0.01,
					(i / 40),
				])
			}
			const res = recognizeHandwriting(placeGlyph([scribble, scribble, scribble, scribble, scribble], 200, 200, 120, 120), VB_HEIGHT)
			expect(res.best).toBeNull()
		})
	})

	describe('active learning', () => {
		afterEach(() => {
			resetLearnedHandwritingModel()
		})

		it('adapts to a user-corrected personal glyph style and persists prototypes', () => {
			// A distinctive custom zigzag-hook stroke that isn't a built-in trill template
			const customTrill = placeGlyph([[
				[0.1, 0.7], [0.3, 0.2], [0.45, 0.75], [0.65, 0.2], [0.85, 0.7],
			]], 400, 400, 46, 30)

			expect(getLearnedSampleCount()).toBe(0)
			learnHandwritingSample(customTrill, VB_HEIGHT, '\uE566', { isCorrection: true })
			expect(getLearnedSampleCount()).toBe(1)

			// Re-drawing a slightly noisy version of the same personal gesture now recognizes as trill
			const noisyTrill = placeGlyph([[
				[0.12, 0.68], [0.29, 0.22], [0.47, 0.73], [0.64, 0.23], [0.84, 0.68],
			]], 520, 600, 44, 32)
			const res = recognizeHandwriting(noisyTrill, VB_HEIGHT)
			expect(res.best?.symbol).toBe('\uE566')

			// Teaching a near-duplicate merges/reinforces instead of duplicating
			learnHandwritingSample(noisyTrill, VB_HEIGHT, '\uE566', { isCorrection: false })
			expect(getLearnedSampleCount()).toBe(1)
		})

		it('still recognizes messy/jittery handwriting cleanly immediately after reset', () => {
			resetLearnedHandwritingModel()
			expect(getLearnedSampleCount()).toBe(0)

			// Messy, slightly tilted, jittery '0' started from the left side (not top)
			const messyZero = placeGlyph([arc(0.5, 0.5, 0.38, 0.45, 180, 530, 16).map(([x, y], i) => [
				x + ((i % 3) - 1) * 0.05,
				y + (((i + 1) % 3) - 1) * 0.04,
			])], 400, 400, 34, 40)
			expect(recognizeHandwriting(messyZero, VB_HEIGHT).best?.symbol).toBe('\uED10')

			// Messy, jittery '2' with slanted proportions
			const messyTwo = placeGlyph([[
				[0.18, 0.3], [0.38, 0.08], [0.78, 0.15], [0.82, 0.38],
				[0.48, 0.62], [0.12, 0.92], [0.45, 0.88], [0.92, 0.95],
			]], 400, 400, 36, 44)
			expect(recognizeHandwriting(messyTwo, VB_HEIGHT).best?.symbol).toBe('\uED12')

			// Messy, jittery '3'
			const messyThree = placeGlyph([[
				[0.15, 0.2], [0.55, 0.06], [0.82, 0.25], [0.42, 0.48],
				[0.86, 0.72], [0.52, 0.96], [0.12, 0.82],
			]], 400, 400, 30, 44)
			expect(recognizeHandwriting(messyThree, VB_HEIGHT).best?.symbol).toBe('\uED13')
		})
	})
})
