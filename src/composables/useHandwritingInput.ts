/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ComputedRef } from 'vue'
import type { NormalizedPoint, SheetAnnotation } from '../types/annotation'
import type { EditorTool, EditorToolStyle } from './useAnnotationEditor'
import { getCurrentScope, onScopeDispose, ref, watch } from 'vue'
import { createUuid } from './useAnnotationEditor'
import { computeBoundingBoxFromPoints, VIEWBOX_WIDTH } from '../utils/annotation'
import { roundNorm } from '../utils/annotationTransform'
import { learnHandwritingSample } from '../utils/handwritingModel'
import {
	recognizeHandwriting,
	type HandwritingCandidate,
} from '../utils/handwritingRecognizer'
import { getStampDefinition } from '../utils/musicSymbols'

export interface HandwritingSwapState {
	uuid: string
	x: number
	y: number
	initialSymbol: string
	activeSymbol: string
	candidates: HandwritingCandidate[]
	rawStrokes: NormalizedPoint[][]
	vbHeight: number
}

export interface UseHandwritingInputOptions {
	vbHeight: ComputedRef<number>
	pageIndex: ComputedRef<number>
	editable: ComputedRef<boolean>
	activeTool: ComputedRef<EditorTool>
	toolStyle: ComputedRef<EditorToolStyle>
	activeAnnotations: ComputedRef<SheetAnnotation[]>
	onCreate: (draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> & { uuid?: string }) => void
	onUpdate: (before: SheetAnnotation, after: SheetAnnotation) => void
	onDelete: (annotation: SheetAnnotation) => void
}

const RECOGNITION_DELAY_MS = 450
const SWAP_PILL_TIMEOUT_MS = 6000

/**
 * Manages multi-stroke handwriting capture, timed auto-conversion to music stamps,
 * and the post-conversion candidate swap / "Keep ink" pill.
 */
export function useHandwritingInput(opts: UseHandwritingInputOptions) {
	const pendingStrokes = ref<NormalizedPoint[][]>([])
	const swapState = ref<HandwritingSwapState | null>(null)

	let recognizeTimer: ReturnType<typeof setTimeout> | null = null
	let dismissTimer: ReturnType<typeof setTimeout> | null = null

	function clearRecognizeTimer() {
		if (recognizeTimer !== null) {
			clearTimeout(recognizeTimer)
			recognizeTimer = null
		}
	}

	function clearDismissTimer() {
		if (dismissTimer !== null) {
			clearTimeout(dismissTimer)
			dismissTimer = null
		}
	}

	function finalizeSwapPill(learnIfAccepted: boolean) {
		clearDismissTimer()
		const current = swapState.value
		swapState.value = null
		if (!current || !learnIfAccepted) return

		// Only learn if the placed annotation still exists (wasn't deleted or undone)
		const stillPresent = opts.activeAnnotations.value.some((a) => a.uuid === current.uuid)
		if (!stillPresent) return

		learnHandwritingSample(current.rawStrokes, current.vbHeight, current.activeSymbol, {
			isCorrection: current.activeSymbol !== current.initialSymbol,
		})
	}

	function scheduleDismissPill() {
		clearDismissTimer()
		dismissTimer = setTimeout(() => {
			finalizeSwapPill(true)
		}, SWAP_PILL_TIMEOUT_MS)
	}

	function dismissSwapPill() {
		finalizeSwapPill(true)
	}

	function isNearPendingStrokes(pt: NormalizedPoint): boolean {
		if (pendingStrokes.value.length === 0) return true
		const allPts = pendingStrokes.value.flat()
		if (allPts.length === 0) return true

		const vh = opts.vbHeight.value
		let minX = Infinity
		let minY = Infinity
		let maxX = -Infinity
		let maxY = -Infinity
		for (const p of allPts) {
			const sx = p.x * VIEWBOX_WIDTH
			const sy = p.y * vh
			if (sx < minX) minX = sx
			if (sy < minY) minY = sy
			if (sx > maxX) maxX = sx
			if (sy > maxY) maxY = sy
		}

		const px = pt.x * VIEWBOX_WIDTH
		const py = pt.y * vh
		const dx = px < minX ? minX - px : px > maxX ? px - maxX : 0
		const dy = py < minY ? minY - py : py > maxY ? py - maxY : 0
		const gap = Math.hypot(dx, dy)
		const boxSize = Math.max(maxX - minX, maxY - minY)
		const threshold = Math.max(65, boxSize * 1.5)
		return gap <= threshold
	}

	function buildStampDraft(symbol: string, cx: number, cy: number, uuid: string) {
		const def = getStampDefinition(symbol)
		const s = opts.toolStyle.value
		return {
			uuid,
			pageNumber: opts.pageIndex.value,
			layerIndex: 0,
			type: 'stamp' as const,
			x: roundNorm(cx),
			y: roundNorm(cy),
			width: 0,
			height: 0,
			style: {
				color: s.color,
				fontSize: s.stampSize || 40,
				opacity: s.opacity,
			},
			data: {
				symbol: def?.symbol ?? symbol,
				category: def?.category,
			},
		}
	}

	function flushPendingStrokes() {
		clearRecognizeTimer()
		const strokes = pendingStrokes.value
		pendingStrokes.value = []
		if (strokes.length === 0) return

		const result = recognizeHandwriting(strokes, opts.vbHeight.value)
		if (!result.best) {
			// Unrecognized stroke: discard cleanly
			return
		}

		const allPts = strokes.flat()
		const box = computeBoundingBoxFromPoints(allPts, 0)
		const cx = roundNorm(box.x + box.width / 2)
		const cy = roundNorm(box.y + box.height / 2)
		const uuid = createUuid()

		opts.onCreate(buildStampDraft(result.best.symbol, cx, cy, uuid))

		swapState.value = {
			uuid,
			x: cx,
			y: cy,
			initialSymbol: result.best.symbol,
			activeSymbol: result.best.symbol,
			candidates: result.candidates,
			rawStrokes: strokes.map((s) => s.map((p) => ({ ...p }))),
			vbHeight: opts.vbHeight.value,
		}
		scheduleDismissPill()
	}

	function beginStroke(startPt: NormalizedPoint) {
		dismissSwapPill()
		if (pendingStrokes.value.length > 0) {
			if (isNearPendingStrokes(startPt)) {
				clearRecognizeTimer()
			} else {
				flushPendingStrokes()
			}
		}
	}

	function endStroke(strokePoints: NormalizedPoint[]) {
		if (strokePoints.length === 0) return
		pendingStrokes.value = [...pendingStrokes.value, strokePoints.map((p) => ({ ...p }))]
		clearRecognizeTimer()
		recognizeTimer = setTimeout(() => {
			flushPendingStrokes()
		}, RECOGNITION_DELAY_MS)
	}

	function selectCandidate(symbol: string) {
		const current = swapState.value
		if (!current) return
		const ann = opts.activeAnnotations.value.find((a) => a.uuid === current.uuid)
		if (!ann) {
			dismissSwapPill()
			return
		}
		const def = getStampDefinition(symbol)
		const resolvedSymbol = def?.symbol ?? symbol
		if (resolvedSymbol !== current.activeSymbol) {
			const next: SheetAnnotation = {
				...JSON.parse(JSON.stringify(ann)),
				data: {
					...ann.data,
					symbol: resolvedSymbol,
					category: def?.category,
				},
			}
			opts.onUpdate(ann, next)
			swapState.value = {
				...current,
				activeSymbol: resolvedSymbol,
			}
		}
		finalizeSwapPill(true)
	}

	function keepInk() {
		const current = swapState.value
		if (!current) return
		const ann = opts.activeAnnotations.value.find((a) => a.uuid === current.uuid)
		if (ann) {
			opts.onDelete(ann)
		}

		const strokes = current.rawStrokes.map((s) =>
			s.length >= 2 ? s : [s[0], { x: Math.min(1, s[0].x + 0.002), y: s[0].y }],
		)
		const box = computeBoundingBoxFromPoints(strokes.flat())
		const s = opts.toolStyle.value
		opts.onCreate({
			pageNumber: opts.pageIndex.value,
			layerIndex: 0,
			type: 'drawing',
			...box,
			style: {
				stroke: s.color,
				strokeWidth: s.strokeWidth,
				opacity: s.opacity,
				blendMode: 'normal',
				lineStyle: 'solid',
			},
			data: {
				strokes,
				smooth: true,
			},
		})
		finalizeSwapPill(false)
	}

	function deletePlaced() {
		const current = swapState.value
		if (!current) return
		const ann = opts.activeAnnotations.value.find((a) => a.uuid === current.uuid)
		if (ann) {
			opts.onDelete(ann)
		}
		finalizeSwapPill(false)
	}

	function extendSwapPillTimeout() {
		if (swapState.value) {
			scheduleDismissPill()
		}
	}

	function cancelPending() {
		clearRecognizeTimer()
		pendingStrokes.value = []
	}

	watch(
		() => [opts.activeTool.value, opts.editable.value] as const,
		([tool, editable]) => {
			if (!editable || tool !== 'handwriting') {
				if (pendingStrokes.value.length > 0) {
					flushPendingStrokes()
				}
				dismissSwapPill()
			}
		},
	)

	if (getCurrentScope()) {
		onScopeDispose(() => {
			clearRecognizeTimer()
			clearDismissTimer()
		})
	}

	return {
		pendingStrokes,
		swapState,
		beginStroke,
		endStroke,
		flushPendingStrokes,
		selectCandidate,
		keepInk,
		deletePlaced,
		extendSwapPillTimeout,
		dismissSwapPill,
		cancelPending,
	}
}
