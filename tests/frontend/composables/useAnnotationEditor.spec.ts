/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAnnotationEditor } from '../../../src/composables/useAnnotationEditor'
import type { SheetAnnotation } from '../../../src/types/annotation'

const mocks = vi.hoisted(() => ({
	saveSongAnnotation: vi.fn(),
	deleteSongAnnotation: vi.fn(),
	batchSongAnnotations: vi.fn(),
}))

vi.mock('../../../src/api/annotations', () => ({
	saveSongAnnotation: mocks.saveSongAnnotation,
	deleteSongAnnotation: mocks.deleteSongAnnotation,
	batchSongAnnotations: mocks.batchSongAnnotations,
}))

describe('useAnnotationEditor', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		let nextId = 100
		mocks.saveSongAnnotation.mockImplementation(async (_songId: number, ann: Partial<SheetAnnotation>) => ({
			...ann,
			id: ann.id ?? nextId++,
		}))
		mocks.deleteSongAnnotation.mockResolvedValue(undefined)
		mocks.batchSongAnnotations.mockResolvedValue({ saved: [], deletedIds: [] })
	})

	it('creates, updates, deletes, undoes, and redoes annotations', async () => {
		const annotations = ref<SheetAnnotation[]>([])
		const editor = useAnnotationEditor(annotations, () => ({ songId: 7, fileId: 42 }))

		editor.enterEditMode()
		expect(editor.isEditing.value).toBe(true)

		// Create
		const created = await editor.createAnnotation({
			pageNumber: 0,
			type: 'stamp',
			x: 0.2,
			y: 0.3,
			width: 0.04,
			height: 0.04,
			style: { color: '#e53935', fontSize: 36 },
			data: { symbol: '𝆑𝆑', category: 'dynamic', fontKind: 'music' },
		})

		expect(created.id).toBe(100)
		expect(annotations.value).toHaveLength(1)
		expect(editor.canUndo.value).toBe(true)

		// Update
		const moved = { ...created, x: 0.5 }
		await editor.updateAnnotation(created, moved)
		expect(annotations.value[0].x).toBe(0.5)

		// Undo update
		await editor.undo()
		expect(annotations.value[0].x).toBe(0.2)
		expect(editor.canRedo.value).toBe(true)

		// Redo update
		await editor.redo()
		expect(annotations.value[0].x).toBe(0.5)

		// Delete
		await editor.deleteAnnotation(annotations.value[0])
		expect(annotations.value).toHaveLength(0)

		// Undo delete
		await editor.undo()
		expect(annotations.value).toHaveLength(1)
	})

	it('clears all annotations on the current page in a batch and supports undo', async () => {
		const annotations = ref<SheetAnnotation[]>([
			{
				id: 1,
				uuid: 'p0-a',
				songId: 7,
				fileId: 42,
				pageNumber: 0,
				type: 'drawing',
				x: 0.1,
				y: 0.1,
				width: 0.2,
				height: 0.2,
				style: {},
				data: { points: [{ x: 0.1, y: 0.1 }, { x: 0.2, y: 0.2 }] },
			},
			{
				id: 2,
				uuid: 'p1-a',
				songId: 7,
				fileId: 42,
				pageNumber: 1,
				type: 'drawing',
				x: 0.1,
				y: 0.1,
				width: 0.2,
				height: 0.2,
				style: {},
				data: { points: [{ x: 0.1, y: 0.1 }, { x: 0.2, y: 0.2 }] },
			},
		])

		const editor = useAnnotationEditor(annotations, () => ({ songId: 7, fileId: 42 }))
		await editor.clearPage(0)

		expect(annotations.value).toHaveLength(1)
		expect(annotations.value[0].uuid).toBe('p1-a')
		expect(mocks.batchSongAnnotations).toHaveBeenCalledWith(7, { deleteIds: [1] })

		mocks.batchSongAnnotations.mockResolvedValueOnce({
			saved: [{ ...annotations.value[0], id: 99, uuid: 'p0-a', pageNumber: 0 }],
			deletedIds: [],
		})
		await editor.undo()
		expect(annotations.value).toHaveLength(2)
	})

	it('handles keyboard shortcuts for Delete, fingerings, music symbols, and tools when editing', async () => {
		const annotations = ref<SheetAnnotation[]>([
			{
				id: 1,
				uuid: 'shape-1',
				songId: 7,
				fileId: 42,
				pageNumber: 0,
				type: 'shape',
				x: 0.1,
				y: 0.1,
				width: 0.2,
				height: 0.2,
				style: {},
				data: { shapeType: 'rect' },
			},
		])

		const editor = useAnnotationEditor(annotations, () => ({ songId: 7, fileId: 42 }))
		editor.enterEditMode()

		// Fingering shortcut '3'
		window.dispatchEvent(new KeyboardEvent('keydown', { key: '3' }))
		expect(editor.activeTool.value).toBe('stamp')
		expect(editor.toolStyle.value.stampSymbol).toBe('3')

		// Accidental shortcut 'b' -> flat
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'b' }))
		expect(editor.activeTool.value).toBe('stamp')
		expect(editor.toolStyle.value.stampSymbol).toBe('♭')

		// Tool shortcut 'v' -> select
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'v' }))
		expect(editor.activeTool.value).toBe('select')

		// Delete key removes selected annotation
		editor.selectAnnotation('shape-1')
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Delete' }))
		await Promise.resolve()
		expect(annotations.value).toHaveLength(0)

		editor.exitEditMode()
	})

	it('adjusts symbol size / shape thickness with + and - and moves with arrow keys', async () => {
		const annotations = ref<SheetAnnotation[]>([
			{
				id: 10,
				uuid: 'stamp-1',
				songId: 7,
				fileId: 42,
				pageNumber: 0,
				type: 'stamp',
				x: 0.5,
				y: 0.5,
				width: 0.04,
				height: 0.04,
				style: { fontSize: 40 },
				data: { symbol: '𝆑𝆑' },
			},
			{
				id: 11,
				uuid: 'shape-2',
				songId: 7,
				fileId: 42,
				pageNumber: 0,
				type: 'shape',
				x: 0.2,
				y: 0.2,
				width: 0.3,
				height: 0.1,
				style: { strokeWidth: 4 },
				data: { shapeType: 'rect' },
			},
		])

		const editor = useAnnotationEditor(annotations, () => ({ songId: 7, fileId: 42 }))
		editor.enterEditMode()

		// Increase stamp fontSize with '+' (no bounding box, just center & fontSize)
		editor.selectAnnotation('stamp-1')
		window.dispatchEvent(new KeyboardEvent('keydown', { key: '+' }))
		await Promise.resolve()
		expect(annotations.value[0].style.fontSize).toBe(44)

		// Move stamp with ArrowRight & ArrowUp
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
		await Promise.resolve()
		expect(annotations.value[0].x).toBe(0.503)

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', shiftKey: true }))
		await Promise.resolve()
		expect(annotations.value[0].y).toBe(0.485)

		// Decrease shape thickness with '-'
		editor.selectAnnotation('shape-2')
		window.dispatchEvent(new KeyboardEvent('keydown', { key: '-' }))
		await Promise.resolve()
		expect(annotations.value[1].style.strokeWidth).toBe(3.5)

		editor.exitEditMode()
	})

	it('adopts selected annotation style when switching to a drawing or stamp tool', async () => {
		const annotations = ref<SheetAnnotation[]>([
			{
				id: 20,
				uuid: 'green-stamp',
				songId: 7,
				fileId: 42,
				pageNumber: 0,
				type: 'stamp',
				x: 0.3,
				y: 0.3,
				width: 0,
				height: 0,
				style: { color: '#43a047', fontSize: 64, opacity: 0.8 },
				data: { symbol: '𝆑𝆑' },
			},
			{
				id: 21,
				uuid: 'blue-dashed-line',
				songId: 7,
				fileId: 42,
				pageNumber: 0,
				type: 'shape',
				x: 0.1,
				y: 0.1,
				width: 0.3,
				height: 0.1,
				style: { stroke: '#1e88e5', strokeWidth: 5, lineStyle: 'dashed', opacity: 0.9 },
				data: { shapeType: 'line', points: [{ x: 0.1, y: 0.1 }, { x: 0.4, y: 0.2 }] },
			},
		])

		const editor = useAnnotationEditor(annotations, () => ({ songId: 7, fileId: 42 }))
		editor.enterEditMode()

		// Select green size-64 symbol, then press '1' -> toolStyle keeps green & size 64
		editor.selectAnnotation('green-stamp')
		window.dispatchEvent(new KeyboardEvent('keydown', { key: '1' }))
		expect(editor.activeTool.value).toBe('stamp')
		expect(editor.toolStyle.value.stampSymbol).toBe('1')
		expect(editor.toolStyle.value.color).toBe('#43a047')
		expect(editor.toolStyle.value.stampSize).toBe(64)
		expect(editor.toolStyle.value.opacity).toBe(0.8)

		// Select blue dashed width-5 line, then switch to pen -> toolStyle keeps blue, dashed, width 5
		editor.selectAnnotation('blue-dashed-line')
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'p' }))
		expect(editor.activeTool.value).toBe('drawing')
		expect(editor.toolStyle.value.color).toBe('#1e88e5')
		expect(editor.toolStyle.value.strokeWidth).toBe(5)
		expect(editor.toolStyle.value.lineStyle).toBe('dashed')

		editor.exitEditMode()
	})
})
