/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, ref } from 'vue'
import type { EditorTool } from '../../../src/composables/useAnnotationEditor'
import { useHandwritingInput } from '../../../src/composables/useHandwritingInput'
import type { SheetAnnotation } from '../../../src/types/annotation'
import { createDefaultToolStyle } from '../../../src/utils/annotationToolStyle'
import { getLearnedSampleCount, resetLearnedHandwritingModel } from '../../../src/utils/handwritingModel'

describe('useHandwritingInput', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		resetLearnedHandwritingModel()
	})

	afterEach(() => {
		vi.useRealTimers()
		resetLearnedHandwritingModel()
	})

	function setup() {
		const activeTool = ref<EditorTool>('handwriting')
		const editable = ref(true)
		const toolStyle = ref(createDefaultToolStyle())
		const activeAnnotations = ref<SheetAnnotation[]>([])

		const created: Array<Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> & { uuid?: string }> = []
		const updated: Array<{ before: SheetAnnotation; after: SheetAnnotation }> = []
		const deleted: SheetAnnotation[] = []

		const hw = useHandwritingInput({
			vbHeight: computed(() => 1414),
			pageIndex: computed(() => 0),
			editable: computed(() => editable.value),
			activeTool: computed(() => activeTool.value),
			toolStyle: computed(() => toolStyle.value),
			activeAnnotations: computed(() => activeAnnotations.value),
			onCreate: (draft) => {
				created.push(draft)
				activeAnnotations.value = [
					...activeAnnotations.value,
					{
						...draft,
						uuid: draft.uuid || `gen-${created.length}`,
						songId: 1,
						fileId: 1,
					},
				]
			},
			onUpdate: (before, after) => {
				updated.push({ before, after })
				activeAnnotations.value = activeAnnotations.value.map((a) => (a.uuid === after.uuid ? after : a))
			},
			onDelete: (ann) => {
				deleted.push(ann)
				activeAnnotations.value = activeAnnotations.value.filter((a) => a.uuid !== ann.uuid)
			},
		})

		return { hw, activeTool, editable, activeAnnotations, created, updated, deleted }
	}

	it('auto-converts after 450ms pause and allows swapping candidates', () => {
		const { hw, created, updated } = setup()

		// Draw a vertical line ('1', runner-up '𝅾')
		const stroke = [
			{ x: 0.5, y: 0.2 },
			{ x: 0.5, y: 0.24 },
		]
		hw.beginStroke(stroke[0])
		hw.endStroke(stroke)

		expect(created).toHaveLength(0)
		expect(hw.pendingStrokes.value).toHaveLength(1)

		vi.advanceTimersByTime(450)

		expect(created).toHaveLength(1)
		expect(created[0].type).toBe('stamp')
		expect(created[0].data.symbol).toBe('\uED11')
		expect(hw.swapState.value?.activeSymbol).toBe('\uED11')
		expect(hw.swapState.value?.candidates.map((c) => c.symbol)).toContain('\uE4A6')

		// Tap runner-up candidate in swap pill -> updates stamp and closes pill automatically
		hw.selectCandidate('\uE4A6')
		expect(updated).toHaveLength(1)
		expect(updated[0].after.data.symbol).toBe('\uE4A6')
		expect(hw.swapState.value).toBeNull()
	})

	it('replaces placed stamp with freehand drawing when Keep ink is clicked', () => {
		const { hw, created, deleted } = setup()

		const stroke = [
			{ x: 0.4, y: 0.3 },
			{ x: 0.4, y: 0.34 },
		]
		hw.beginStroke(stroke[0])
		hw.endStroke(stroke)
		vi.advanceTimersByTime(450)

		expect(created).toHaveLength(1)
		expect(created[0].type).toBe('stamp')

		hw.keepInk()

		expect(deleted).toHaveLength(1)
		expect(deleted[0].type).toBe('stamp')
		expect(created).toHaveLength(2)
		expect(created[1].type).toBe('drawing')
		expect(created[1].data.strokes).toHaveLength(1)
		expect(hw.swapState.value).toBeNull()
	})

	it('flushes previous symbol immediately when starting a new stroke far away', () => {
		const { hw, created } = setup()

		// First symbol at x=0.2
		hw.beginStroke({ x: 0.2, y: 0.2 })
		hw.endStroke([{ x: 0.2, y: 0.2 }, { x: 0.2, y: 0.24 }])
		expect(created).toHaveLength(0)

		// Second symbol far away at x=0.8 -> immediately flushes first without waiting 450ms
		hw.beginStroke({ x: 0.8, y: 0.2 })
		expect(created).toHaveLength(1)
		expect(created[0].data.symbol).toBe('\uED11')
	})

	it('combines nearby strokes into a multi-stroke symbol (e.g. 2-stroke 4)', () => {
		const { hw, created } = setup()

		const s1 = [
			{ x: (400 + 0.32 * 32) / 1000, y: (400 + 0.05 * 44) / 1414 },
			{ x: (400 + 0.12 * 32) / 1000, y: (400 + 0.62 * 44) / 1414 },
			{ x: (400 + 0.88 * 32) / 1000, y: (400 + 0.62 * 44) / 1414 },
		]
		const s2 = [
			{ x: (400 + 0.68 * 32) / 1000, y: (400 + 0.08 * 44) / 1414 },
			{ x: (400 + 0.68 * 32) / 1000, y: (400 + 0.95 * 44) / 1414 },
		]

		hw.beginStroke(s1[0])
		hw.endStroke(s1)
		vi.advanceTimersByTime(200)
		hw.beginStroke(s2[0])
		hw.endStroke(s2)

		expect(created).toHaveLength(0)
		vi.advanceTimersByTime(450)

		expect(created).toHaveLength(1)
		expect(created[0].data.symbol).toBe('\uED14')
	})

	it('deletes the placed stamp when deletePlaced is called', () => {
		const { hw, created, deleted, activeAnnotations } = setup()

		const stroke = [
			{ x: 0.4, y: 0.3 },
			{ x: 0.4, y: 0.34 },
		]
		hw.beginStroke(stroke[0])
		hw.endStroke(stroke)
		vi.advanceTimersByTime(450)

		expect(created).toHaveLength(1)
		expect(activeAnnotations.value).toHaveLength(1)

		hw.deletePlaced()

		expect(deleted).toHaveLength(1)
		expect(activeAnnotations.value).toHaveLength(0)
		expect(hw.swapState.value).toBeNull()
		// Deleted strokes must NOT be learned
		expect(getLearnedSampleCount()).toBe(0)
	})

	it('learns on implicit accept (timeout) and explicit correction, but not on keepInk', () => {
		const { hw } = setup()

		// 1. Implicit accept after timeout learns 1 sample
		const stroke1 = [{ x: 0.4, y: 0.3 }, { x: 0.4, y: 0.34 }]
		hw.beginStroke(stroke1[0])
		hw.endStroke(stroke1)
		vi.advanceTimersByTime(450)
		expect(getLearnedSampleCount()).toBe(0)
		vi.advanceTimersByTime(6000)
		expect(getLearnedSampleCount()).toBe(1)

		// 2. Explicit correction to runner-up closes pill and learns immediately
		hw.beginStroke(stroke1[0])
		hw.endStroke(stroke1)
		vi.advanceTimersByTime(450)
		hw.selectCandidate('\uE4A6')
		expect(hw.swapState.value).toBeNull()
		expect(getLearnedSampleCount()).toBe(2)

		// 3. Clicking the already-active symbol confirms it, closes pill, and does not emit redundant update
		hw.beginStroke(stroke1[0])
		hw.endStroke(stroke1)
		vi.advanceTimersByTime(450)
		hw.selectCandidate('\uED11')
		expect(hw.swapState.value).toBeNull()

		// 4. Keep ink does NOT add a learned sample
		hw.beginStroke(stroke1[0])
		hw.endStroke(stroke1)
		vi.advanceTimersByTime(450)
		hw.keepInk()
		expect(getLearnedSampleCount()).toBe(2)
	})

	it('falls back to freehand ink instead of discarding unrecognized strokes and resumes timer after cancel', () => {
		const { hw, created } = setup()

		// 6-stroke scribble (unrecognized) must fall back to a freehand drawing instead of disappearing
		const seg = [{ x: 0.2, y: 0.2 }, { x: 0.25, y: 0.25 }]
		for (let i = 0; i < 6; i++) {
			hw.beginStroke(seg[0])
			hw.endStroke(seg)
		}
		vi.advanceTimersByTime(450)

		expect(created).toHaveLength(1)
		expect(created[0].type).toBe('drawing')
		expect(created[0].data.strokes).toHaveLength(6)

		// If a second stroke starts (clearing the timer) and is cancelled mid-flight,
		// resumeRecognizeTimerIfPending() ensures the first stroke still flushes instead of getting stuck
		const vLine = [{ x: 0.5, y: 0.3 }, { x: 0.5, y: 0.34 }]
		hw.beginStroke(vLine[0])
		hw.endStroke(vLine)
		hw.beginStroke({ x: 0.51, y: 0.31 }) // clears timer
		hw.resumeRecognizeTimerIfPending() // simulates pointercancel recovery
		vi.advanceTimersByTime(450)

		expect(created).toHaveLength(2)
		expect(created[1].type).toBe('stamp')
	})
})
