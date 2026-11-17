/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, getCurrentScope, onUnmounted, ref, watch, type Ref } from 'vue'
import {
	batchSetlistFileAnnotations,
	batchSongAnnotations,
	deleteSongAnnotation,
	saveSetlistFileAnnotation,
	saveSongAnnotation,
} from '../api/annotations'
import type {
	LineStyle,
	ShapeType,
	SheetAnnotation,
	TextAlign,
} from '../types/annotation'
import { translateAnnotation } from '../utils/annotation'
import { MUSIC_STAMPS } from '../utils/musicSymbols'

export type EditorTool =
	| 'select'
	| 'drawing'
	| 'highlight'
	| 'line'
	| 'arrow'
	| 'crescendo'
	| 'decrescendo'
	| 'slur'
	| 'rect'
	| 'ellipse'
	| 'bracket'
	| 'text'
	| 'stamp'
	| 'link'
	| 'eraser'

export interface EditorToolStyle {
	color: string
	highlightColor: string
	strokeWidth: number
	highlightWidth: number
	opacity: number
	highlightOpacity: number
	lineStyle: LineStyle
	fontSize: number
	fontFamily: string
	textAlign: TextAlign
	fill: string
	shapeHeight: number
	stampSymbol: string
	stampSize: number
}

type HistoryAction =
	| { kind: 'create'; annotation: SheetAnnotation }
	| { kind: 'update'; before: SheetAnnotation; after: SheetAnnotation }
	| { kind: 'delete'; annotation: SheetAnnotation }
	| { kind: 'batch-delete'; annotations: SheetAnnotation[] }

export function createUuid(): string {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}
	return 'ann-' + Math.random().toString(36).slice(2, 10) + '-' + Date.now().toString(36)
}

export function isShapeTool(tool: EditorTool): tool is ShapeType {
	return (
		tool === 'line' ||
		tool === 'arrow' ||
		tool === 'crescendo' ||
		tool === 'decrescendo' ||
		tool === 'slur' ||
		tool === 'rect' ||
		tool === 'ellipse' ||
		tool === 'bracket'
	)
}

export type AnnotationEditorContext =
	| { songId: number; fileId: number }
	| { libraryId: number; setlistId: number; setlistFileId: number; fileId: number }

function isSetlistContext(
	ctx: AnnotationEditorContext,
): ctx is { libraryId: number; setlistId: number; setlistFileId: number; fileId: number } {
	return 'setlistId' in ctx
}

function saveContextAnnotation(
	ctx: AnnotationEditorContext,
	annotation: Partial<SheetAnnotation>,
): Promise<SheetAnnotation> {
	if (isSetlistContext(ctx)) {
		return saveSetlistFileAnnotation(ctx.libraryId, ctx.setlistId, ctx.setlistFileId, annotation)
	}
	return saveSongAnnotation(ctx.songId, annotation)
}

function batchContextAnnotations(
	ctx: AnnotationEditorContext,
	payload: { upsert?: Array<Partial<SheetAnnotation>>; deleteIds?: number[] },
): Promise<{ saved: SheetAnnotation[]; deletedIds: number[] }> {
	if (isSetlistContext(ctx)) {
		return batchSetlistFileAnnotations(ctx.libraryId, ctx.setlistId, ctx.setlistFileId, payload)
	}
	return batchSongAnnotations(ctx.songId, payload)
}

export function useAnnotationEditor(
	annotations: Ref<SheetAnnotation[]>,
	getContext: () => AnnotationEditorContext,
) {
	const isEditing = ref(false)
	const activeTool = ref<EditorTool>('drawing')
	const selectedUuid = ref<string | null>(null)
	const isSaving = ref(false)
	const saveError = ref<string | null>(null)

	const toolStyle = ref<EditorToolStyle>({
		color: '#e53935',
		highlightColor: '#ffeb3b',
		strokeWidth: 2.5,
		highlightWidth: 18,
		opacity: 1.0,
		highlightOpacity: 0.4,
		lineStyle: 'solid',
		fontSize: 22,
		fontFamily: 'sans-serif',
		textAlign: 'left',
		fill: 'transparent',
		shapeHeight: 18,
		stampSymbol: MUSIC_STAMPS.find((s) => s.symbol === '𝆑𝆑')?.symbol ?? '𝆑𝆑',
		stampSize: 40,
	})

	const undoStack = ref<HistoryAction[]>([])
	const redoStack = ref<HistoryAction[]>([])

	const canUndo = computed(() => undoStack.value.length > 0)
	const canRedo = computed(() => redoStack.value.length > 0)

	const selectedAnnotation = computed<SheetAnnotation | null>(() => {
		if (!selectedUuid.value) return null
		return annotations.value.find((a) => a.uuid === selectedUuid.value && !a.deletedAt) ?? null
	})

	function enterEditMode() {
		isEditing.value = true
		saveError.value = null
	}

	function exitEditMode() {
		isEditing.value = false
		selectedUuid.value = null
	}

	function setTool(tool: EditorTool) {
		activeTool.value = tool
		if (tool !== 'select') {
			selectedUuid.value = null
		}
	}

	function syncToolStyleFromAnnotation(ann: SheetAnnotation) {
		const next = { ...toolStyle.value }
		if (ann.type === 'highlight') {
			if (ann.style?.stroke) next.highlightColor = ann.style.stroke
			if (typeof ann.style?.strokeWidth === 'number') next.highlightWidth = ann.style.strokeWidth
			if (typeof ann.style?.opacity === 'number') next.highlightOpacity = ann.style.opacity
			toolStyle.value = next
			return
		}

		const color = ann.type === 'text' || ann.type === 'stamp' ? ann.style?.color : ann.style?.stroke
		if (color) next.color = color
		if (typeof ann.style?.opacity === 'number') next.opacity = ann.style.opacity

		if (ann.type === 'drawing' || ann.type === 'shape') {
			if (typeof ann.style?.strokeWidth === 'number') next.strokeWidth = ann.style.strokeWidth
			if (ann.style?.lineStyle) next.lineStyle = ann.style.lineStyle
			if (ann.type === 'shape') {
				if (typeof ann.data?.aperture === 'number') next.shapeHeight = Math.abs(ann.data.aperture)
				else if (typeof ann.data?.curvature === 'number') next.shapeHeight = Math.abs(ann.data.curvature)
			}
		} else if (ann.type === 'stamp') {
			if (typeof ann.style?.fontSize === 'number') next.stampSize = ann.style.fontSize
			if (ann.data?.symbol) next.stampSymbol = ann.data.symbol
		} else if (ann.type === 'text') {
			if (typeof ann.style?.fontSize === 'number') next.fontSize = ann.style.fontSize
			if (ann.style?.fontFamily) next.fontFamily = ann.style.fontFamily
			if (ann.style?.textAlign) next.textAlign = ann.style.textAlign
		} else if (ann.type === 'link') {
			if (typeof ann.style?.fontSize === 'number') next.fontSize = ann.style.fontSize
		}

		toolStyle.value = next
	}

	function selectAnnotation(uuid: string | null) {
		selectedUuid.value = uuid
		if (uuid) {
			const ann = annotations.value.find((a) => a.uuid === uuid && !a.deletedAt)
			if (ann) {
				syncToolStyleFromAnnotation(ann)
			}
		}
	}

	function cloneAnn(ann: SheetAnnotation): SheetAnnotation {
		return JSON.parse(JSON.stringify(ann)) as SheetAnnotation
	}

	function upsertLocal(saved: SheetAnnotation) {
		const idx = annotations.value.findIndex((a) => a.uuid === saved.uuid)
		if (idx >= 0) {
			annotations.value = [
				...annotations.value.slice(0, idx),
				saved,
				...annotations.value.slice(idx + 1),
			]
		} else {
			annotations.value = [...annotations.value, saved]
		}
	}

	function removeLocal(uuid: string) {
		annotations.value = annotations.value.filter((a) => a.uuid !== uuid)
		if (selectedUuid.value === uuid) {
			selectedUuid.value = null
		}
	}

	async function persistSave(ann: SheetAnnotation): Promise<SheetAnnotation> {
		const ctx = getContext()
		const songId = isSetlistContext(ctx) ? 0 : ctx.songId
		isSaving.value = true
		saveError.value = null
		try {
			const saved = await saveContextAnnotation(ctx, {
				...ann,
				songId,
				fileId: ann.fileId || ctx.fileId,
			})
			upsertLocal(saved)
			return saved
		} catch (err) {
			saveError.value = err instanceof Error ? err.message : 'Failed to save annotation'
			throw err
		} finally {
			isSaving.value = false
		}
	}

	async function persistDelete(ann: SheetAnnotation): Promise<void> {
		removeLocal(ann.uuid)
		if (!ann.id) return
		isSaving.value = true
		saveError.value = null
		try {
			await deleteSongAnnotation(ann.id)
		} catch (err) {
			saveError.value = err instanceof Error ? err.message : 'Failed to delete annotation'
			throw err
		} finally {
			isSaving.value = false
		}
	}

	async function createAnnotation(draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> & { uuid?: string }): Promise<SheetAnnotation> {
		const ctx = getContext()
		const ann: SheetAnnotation = {
			...draft,
			uuid: draft.uuid || createUuid(),
			songId: isSetlistContext(ctx) ? 0 : ctx.songId,
			fileId: ctx.fileId,
		}
		upsertLocal(ann)
		const saved = await persistSave(ann)
		undoStack.value = [...undoStack.value, { kind: 'create', annotation: cloneAnn(saved) }]
		redoStack.value = []
		return saved
	}

	async function updateAnnotation(
		before: SheetAnnotation,
		after: SheetAnnotation,
		recordHistory = true,
	): Promise<SheetAnnotation> {
		upsertLocal(after)
		if (selectedUuid.value === after.uuid) {
			syncToolStyleFromAnnotation(after)
		}
		const saved = await persistSave(after)
		if (recordHistory) {
			undoStack.value = [
				...undoStack.value,
				{ kind: 'update', before: cloneAnn(before), after: cloneAnn(saved) },
			]
			redoStack.value = []
		}
		return saved
	}

	async function deleteAnnotation(ann: SheetAnnotation, recordHistory = true): Promise<void> {
		const snapshot = cloneAnn(ann)
		await persistDelete(ann)
		if (recordHistory) {
			undoStack.value = [...undoStack.value, { kind: 'delete', annotation: snapshot }]
			redoStack.value = []
		}
	}

	async function deleteSelected(): Promise<void> {
		if (!selectedAnnotation.value) return
		await deleteAnnotation(selectedAnnotation.value)
	}

	async function clearPage(pageNumberZeroIndexed: number): Promise<void> {
		const ctx = getContext()
		const pageAnns = annotations.value.filter(
			(a) => !a.deletedAt && a.pageNumber === pageNumberZeroIndexed,
		)
		if (pageAnns.length === 0) return

		const snapshots = pageAnns.map(cloneAnn)
		const idsToDelete = pageAnns.map((a) => a.id).filter((id): id is number => typeof id === 'number')
		const uuidsToDelete = new Set(pageAnns.map((a) => a.uuid))
		annotations.value = annotations.value.filter((a) => !uuidsToDelete.has(a.uuid))
		selectedUuid.value = null

		if (idsToDelete.length > 0) {
			isSaving.value = true
			saveError.value = null
			try {
				await batchContextAnnotations(ctx, { deleteIds: idsToDelete })
			} catch (err) {
				saveError.value = err instanceof Error ? err.message : 'Failed to clear page annotations'
			} finally {
				isSaving.value = false
			}
		}

		undoStack.value = [...undoStack.value, { kind: 'batch-delete', annotations: snapshots }]
		redoStack.value = []
	}

	async function undo(): Promise<void> {
		const action = undoStack.value[undoStack.value.length - 1]
		if (!action) return
		undoStack.value = undoStack.value.slice(0, -1)

		if (action.kind === 'create') {
			await persistDelete(action.annotation)
			redoStack.value = [...redoStack.value, action]
		} else if (action.kind === 'update') {
			upsertLocal(action.before)
			const restored = await persistSave(action.before)
			redoStack.value = [
				...redoStack.value,
				{ kind: 'update', before: restored, after: action.after },
			]
		} else if (action.kind === 'delete') {
			const toRestore = { ...cloneAnn(action.annotation), id: undefined, deletedAt: null }
			const saved = await persistSave(toRestore)
			redoStack.value = [...redoStack.value, { kind: 'delete', annotation: saved }]
		} else if (action.kind === 'batch-delete') {
			const ctx = getContext()
			const restoredList: SheetAnnotation[] = []
			const upsertPayload = action.annotations.map((a) => ({
				...cloneAnn(a),
				id: undefined,
				deletedAt: null,
			}))
			isSaving.value = true
			try {
				const res = await batchContextAnnotations(ctx, { upsert: upsertPayload })
				for (const s of res.saved) {
					upsertLocal(s)
					restoredList.push(s)
				}
			} finally {
				isSaving.value = false
			}
			redoStack.value = [
				...redoStack.value,
				{ kind: 'batch-delete', annotations: restoredList },
			]
		}
	}

	async function redo(): Promise<void> {
		const action = redoStack.value[redoStack.value.length - 1]
		if (!action) return
		redoStack.value = redoStack.value.slice(0, -1)

		if (action.kind === 'create') {
			const toCreate = { ...cloneAnn(action.annotation), id: undefined, deletedAt: null }
			const saved = await persistSave(toCreate)
			undoStack.value = [...undoStack.value, { kind: 'create', annotation: saved }]
		} else if (action.kind === 'update') {
			upsertLocal(action.after)
			const restored = await persistSave(action.after)
			undoStack.value = [
				...undoStack.value,
				{ kind: 'update', before: action.before, after: restored },
			]
		} else if (action.kind === 'delete') {
			await persistDelete(action.annotation)
			undoStack.value = [...undoStack.value, action]
		} else if (action.kind === 'batch-delete') {
			const ctx = getContext()
			const idsToDelete = action.annotations
				.map((a) => a.id)
				.filter((id): id is number => typeof id === 'number')
			const uuids = new Set(action.annotations.map((a) => a.uuid))
			annotations.value = annotations.value.filter((a) => !uuids.has(a.uuid))
			if (idsToDelete.length > 0) {
				isSaving.value = true
				try {
					await batchContextAnnotations(ctx, { deleteIds: idsToDelete })
				} finally {
					isSaving.value = false
				}
			}
			undoStack.value = [...undoStack.value, action]
		}
	}

	function selectStampSymbol(symbol: string) {
		toolStyle.value = { ...toolStyle.value, stampSymbol: symbol }
		setTool('stamp')
	}

	function adjustSizeOrThickness(deltaSign: 1 | -1) {
		const sel = selectedAnnotation.value
		if (sel) {
			if (sel.type === 'stamp' || sel.type === 'text' || sel.type === 'link') {
				const fallback = sel.type === 'stamp' ? toolStyle.value.stampSize : sel.type === 'link' ? 14 : toolStyle.value.fontSize
				const cur = Number(sel.style?.fontSize) || fallback
				const step = sel.type === 'stamp' ? 4 : 2
				const nextSize = Math.max(8, Math.min(120, cur + deltaSign * step))
				if (nextSize === cur) return
				const updated: SheetAnnotation = {
					...sel,
					style: { ...sel.style, fontSize: nextSize },
				}
				updateAnnotation(sel, updated)
				return
			}
			if (sel.type === 'drawing' || sel.type === 'highlight' || sel.type === 'shape') {
				const cur = Number(sel.style?.strokeWidth) || (sel.type === 'highlight' ? toolStyle.value.highlightWidth : toolStyle.value.strokeWidth)
				const step = cur < 4 || (deltaSign < 0 && cur <= 4) ? 0.5 : 1
				const nextWidth = Math.max(1, Math.min(64, Math.round((cur + deltaSign * step) * 2) / 2))
				if (nextWidth === cur) return
				const updated: SheetAnnotation = {
					...sel,
					style: { ...sel.style, strokeWidth: nextWidth },
				}
				updateAnnotation(sel, updated)
				return
			}
			return
		}

		// No selection: adjust active tool style
		if (activeTool.value === 'stamp') {
			const next = Math.max(12, Math.min(120, toolStyle.value.stampSize + deltaSign * 4))
			toolStyle.value = { ...toolStyle.value, stampSize: next }
		} else if (activeTool.value === 'text' || activeTool.value === 'link') {
			const next = Math.max(10, Math.min(96, toolStyle.value.fontSize + deltaSign * 2))
			toolStyle.value = { ...toolStyle.value, fontSize: next }
		} else if (activeTool.value === 'highlight') {
			const cur = toolStyle.value.highlightWidth
			const next = Math.max(1, Math.min(64, cur + deltaSign * 2))
			toolStyle.value = { ...toolStyle.value, highlightWidth: next }
		} else if (activeTool.value === 'drawing' || isShapeTool(activeTool.value)) {
			const cur = toolStyle.value.strokeWidth
			const step = cur < 4 || (deltaSign < 0 && cur <= 4) ? 0.5 : 1
			const next = Math.max(1, Math.min(64, Math.round((cur + deltaSign * step) * 2) / 2))
			toolStyle.value = { ...toolStyle.value, strokeWidth: next }
		}
	}

	function nudgeSelected(dx: number, dy: number) {
		const sel = selectedAnnotation.value
		if (!sel) return
		const moved = translateAnnotation(sel, dx, dy)
		updateAnnotation(sel, moved)
	}

	function handleKeydown(e: KeyboardEvent): void {
		if (!isEditing.value) return

		const target = e.target as HTMLElement | null
		if (
			target &&
			(target.tagName === 'INPUT' ||
				target.tagName === 'TEXTAREA' ||
				target.tagName === 'SELECT' ||
				target.isContentEditable)
		) {
			return
		}

		// Undo / Redo
		if ((e.ctrlKey || e.metaKey) && !e.altKey) {
			const k = e.key.toLowerCase()
			if (k === 'z' && !e.shiftKey) {
				e.preventDefault()
				undo()
				return
			}
			if ((k === 'z' && e.shiftKey) || k === 'y') {
				e.preventDefault()
				redo()
				return
			}
			return
		}

		if (e.altKey || e.ctrlKey || e.metaKey) return

		// Delete / Backspace removes selected annotation
		if (e.key === 'Delete' || e.key === 'Backspace') {
			if (selectedAnnotation.value) {
				e.preventDefault()
				deleteSelected()
			}
			return
		}

		if (e.key === 'Escape') {
			if (selectedUuid.value) {
				selectedUuid.value = null
			} else {
				setTool('select')
			}
			return
		}

		// Arrow keys move selected annotation
		if (selectedAnnotation.value && (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
			e.preventDefault()
			const step = e.shiftKey ? 0.015 : 0.003
			const dx = e.key === 'ArrowLeft' ? -step : e.key === 'ArrowRight' ? step : 0
			const dy = e.key === 'ArrowUp' ? -step : e.key === 'ArrowDown' ? step : 0
			nudgeSelected(dx, dy)
			return
		}

		// '+' / '=' increases and '-' decreases symbol size or shape thickness
		if (e.key === '+' || e.key === '=') {
			e.preventDefault()
			adjustSizeOrThickness(1)
			return
		}
		if (e.key === '-' || e.key === '_') {
			e.preventDefault()
			adjustSizeOrThickness(-1)
			return
		}

		// Fingerings 0..5
		if (/^[0-5]$/.test(e.key) && !e.shiftKey) {
			e.preventDefault()
			selectStampSymbol(e.key)
			return
		}

		// Musical symbols & tool shortcuts
		if (e.key === '#' || (e.shiftKey && e.key === '3')) {
			e.preventDefault()
			selectStampSymbol('♯')
			return
		}

		const lower = e.key.toLowerCase()
		if (e.shiftKey && lower === 'p') {
			e.preventDefault()
			selectStampSymbol('𝆏')
			return
		}

		if (e.shiftKey) return

		switch (lower) {
			case 'v':
				setTool('select')
				break
			case 'p':
				setTool('drawing')
				break
			case 'h':
				setTool('highlight')
				break
			case 's':
				setTool('line')
				break
			case 't':
				setTool('text')
				break
			case 'm':
				setTool('stamp')
				break
			case 'l':
				setTool('link')
				break
			case 'e':
				setTool('eraser')
				break
			case 'f':
				selectStampSymbol('𝆑')
				break
			case 'b':
				selectStampSymbol('♭')
				break
			case 'n':
				selectStampSymbol('♮')
				break
		}
	}

	if (typeof window !== 'undefined') {
		watch(isEditing, (editing) => {
			if (editing) {
				window.addEventListener('keydown', handleKeydown)
			} else {
				window.removeEventListener('keydown', handleKeydown)
			}
		}, { immediate: true, flush: 'sync' })

		if (getCurrentScope()) {
			onUnmounted(() => {
				window.removeEventListener('keydown', handleKeydown)
			})
		}
	}

	function updateSelectedStyle(patch: Partial<SheetAnnotation['style']>) {
		const current = selectedAnnotation.value
		if (!current) return
		const updated: SheetAnnotation = {
			...current,
			style: {
				...current.style,
				...patch,
			},
		}
		updateAnnotation(current, updated)
	}

	function updateSelectedData(patch: Partial<SheetAnnotation['data']>) {
		const current = selectedAnnotation.value
		if (!current) return
		const updated: SheetAnnotation = {
			...current,
			data: {
				...current.data,
				...patch,
			},
		}
		updateAnnotation(current, updated)
	}

	return {
		isEditing,
		activeTool,
		selectedUuid,
		selectedAnnotation,
		toolStyle,
		isSaving,
		saveError,
		canUndo,
		canRedo,
		enterEditMode,
		exitEditMode,
		setTool,
		selectAnnotation,
		selectStampSymbol,
		handleKeydown,
		createAnnotation,
		updateAnnotation,
		updateSelectedStyle,
		updateSelectedData,
		deleteAnnotation,
		deleteSelected,
		clearPage,
		undo,
		redo,
	}
}
