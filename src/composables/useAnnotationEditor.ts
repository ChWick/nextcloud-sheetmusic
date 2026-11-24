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
import { handleAnnotationEditorKeydown } from '../utils/annotationShortcuts'
import {
	createDefaultToolStyle,
	mergeAnnotationStyleIntoToolStyle,
	stepStrokeWidth,
} from '../utils/annotationToolStyle'

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
	| 'handwriting'
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

	const toolStyle = ref<EditorToolStyle>(createDefaultToolStyle())

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
		void flushPendingUpdate()
		isEditing.value = false
		selectedUuid.value = null
	}

	function setTool(tool: EditorTool) {
		activeTool.value = tool
		if (tool !== 'select') {
			void flushPendingUpdate()
			selectedUuid.value = null
		}
	}

	function syncToolStyleFromAnnotation(ann: SheetAnnotation) {
		toolStyle.value = mergeAnnotationStyleIntoToolStyle(toolStyle.value, ann)
	}

	function selectAnnotation(uuid: string | null) {
		if (pendingUpdate && pendingUpdate.after.uuid !== uuid) {
			void flushPendingUpdate()
		}
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

	function toRestorableCopy(ann: SheetAnnotation): SheetAnnotation {
		return { ...cloneAnn(ann), id: undefined, deletedAt: null }
	}

	let activeSaveCount = 0
	const localRevisions = new Map<string, number>()

	function bumpLocalRevision(uuid: string): number {
		const next = (localRevisions.get(uuid) ?? 0) + 1
		localRevisions.set(uuid, next)
		return next
	}

	async function withSaving<T>(fn: () => Promise<T>, fallbackMessage?: string, rethrow = true): Promise<T | undefined> {
		activeSaveCount++
		isSaving.value = true
		if (fallbackMessage) saveError.value = null
		try {
			return await fn()
		} catch (err) {
			if (fallbackMessage) {
				saveError.value = err instanceof Error ? err.message : fallbackMessage
			}
			if (rethrow) throw err
			return undefined
		} finally {
			activeSaveCount = Math.max(0, activeSaveCount - 1)
			isSaving.value = activeSaveCount > 0
		}
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
		const revisionAtStart = localRevisions.get(ann.uuid) ?? 0
		const saved = await withSaving(async () => {
			const res = await saveContextAnnotation(ctx, {
				...ann,
				songId,
				fileId: ann.fileId || ctx.fileId,
			})
			// Avoid overwriting newer local slider/drag edits with an older in-flight response
			if ((localRevisions.get(ann.uuid) ?? 0) === revisionAtStart) {
				upsertLocal(res)
			} else if (res.id) {
				const latest = annotations.value.find((a) => a.uuid === ann.uuid)
				if (latest && !latest.id) {
					upsertLocal({ ...latest, id: res.id })
				}
			}
			return res
		}, 'Failed to save annotation')
		return saved!
	}

	const STYLE_SAVE_DEBOUNCE_MS = 200
	let pendingUpdate: { before: SheetAnnotation; after: SheetAnnotation; timer: ReturnType<typeof setTimeout> | null } | null = null

	async function flushPendingUpdate(): Promise<void> {
		if (!pendingUpdate) return
		const { before, after, timer } = pendingUpdate
		pendingUpdate = null
		if (timer !== null) {
			clearTimeout(timer)
		}
		await updateAnnotation(before, after, true)
	}

	function scheduleCoalescedUpdate(before: SheetAnnotation, after: SheetAnnotation): void {
		bumpLocalRevision(after.uuid)
		upsertLocal(after)
		if (selectedUuid.value === after.uuid) {
			syncToolStyleFromAnnotation(after)
		}

		if (pendingUpdate && pendingUpdate.after.uuid !== after.uuid) {
			void flushPendingUpdate()
		}

		const initialBefore = pendingUpdate ? pendingUpdate.before : cloneAnn(before)
		if (pendingUpdate?.timer !== null && pendingUpdate?.timer !== undefined) {
			clearTimeout(pendingUpdate.timer)
		}
		const timer = setTimeout(() => {
			void flushPendingUpdate()
		}, STYLE_SAVE_DEBOUNCE_MS)
		pendingUpdate = { before: initialBefore, after: cloneAnn(after), timer }
	}

	async function persistDelete(ann: SheetAnnotation): Promise<void> {
		removeLocal(ann.uuid)
		if (!ann.id) return
		await withSaving(() => deleteSongAnnotation(ann.id!), 'Failed to delete annotation')
	}

	async function batchDeleteLocalAndRemote(anns: SheetAnnotation[], swallowError = false): Promise<void> {
		const idsToDelete = anns.map((a) => a.id).filter((id): id is number => typeof id === 'number')
		const uuidsToDelete = new Set(anns.map((a) => a.uuid))
		annotations.value = annotations.value.filter((a) => !uuidsToDelete.has(a.uuid))
		if (selectedUuid.value && uuidsToDelete.has(selectedUuid.value)) {
			selectedUuid.value = null
		}
		if (idsToDelete.length > 0) {
			await withSaving(
				() => batchContextAnnotations(getContext(), { deleteIds: idsToDelete }),
				swallowError ? 'Failed to clear page annotations' : undefined,
				!swallowError,
			)
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
		selectedUuid.value = ann.uuid
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
		if (pendingUpdate?.after.uuid === ann.uuid) {
			if (pendingUpdate.timer !== null) clearTimeout(pendingUpdate.timer)
			pendingUpdate = null
		}
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
		await flushPendingUpdate()
		const pageAnns = annotations.value.filter(
			(a) => !a.deletedAt && a.pageNumber === pageNumberZeroIndexed,
		)
		if (pageAnns.length === 0) return

		const snapshots = pageAnns.map(cloneAnn)
		selectedUuid.value = null
		await batchDeleteLocalAndRemote(pageAnns, true)

		undoStack.value = [...undoStack.value, { kind: 'batch-delete', annotations: snapshots }]
		redoStack.value = []
	}

	async function undo(): Promise<void> {
		await flushPendingUpdate()
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
			const saved = await persistSave(toRestorableCopy(action.annotation))
			redoStack.value = [...redoStack.value, { kind: 'delete', annotation: saved }]
		} else if (action.kind === 'batch-delete') {
			const restoredList: SheetAnnotation[] = []
			const upsertPayload = action.annotations.map(toRestorableCopy)
			await withSaving(async () => {
				const res = await batchContextAnnotations(getContext(), { upsert: upsertPayload })
				for (const s of res.saved) {
					upsertLocal(s)
					restoredList.push(s)
				}
			})
			redoStack.value = [
				...redoStack.value,
				{ kind: 'batch-delete', annotations: restoredList },
			]
		}
	}

	async function redo(): Promise<void> {
		await flushPendingUpdate()
		const action = redoStack.value[redoStack.value.length - 1]
		if (!action) return
		redoStack.value = redoStack.value.slice(0, -1)

		if (action.kind === 'create') {
			const saved = await persistSave(toRestorableCopy(action.annotation))
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
			await batchDeleteLocalAndRemote(action.annotations)
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
				if (nextSize !== cur) {
					updateSelectedStyle({ fontSize: nextSize })
				}
				return
			}
			if (sel.type === 'drawing' || sel.type === 'highlight' || sel.type === 'shape') {
				const cur = Number(sel.style?.strokeWidth) || (sel.type === 'highlight' ? toolStyle.value.highlightWidth : toolStyle.value.strokeWidth)
				const nextWidth = stepStrokeWidth(cur, deltaSign)
				if (nextWidth !== cur) {
					updateSelectedStyle({ strokeWidth: nextWidth })
				}
				return
			}
			return
		}

		// No selection: adjust active tool style
		if (activeTool.value === 'stamp' || activeTool.value === 'handwriting') {
			toolStyle.value = { ...toolStyle.value, stampSize: Math.max(12, Math.min(120, toolStyle.value.stampSize + deltaSign * 4)) }
		} else if (activeTool.value === 'text' || activeTool.value === 'link') {
			toolStyle.value = { ...toolStyle.value, fontSize: Math.max(10, Math.min(96, toolStyle.value.fontSize + deltaSign * 2)) }
		} else if (activeTool.value === 'highlight') {
			toolStyle.value = { ...toolStyle.value, highlightWidth: Math.max(1, Math.min(64, toolStyle.value.highlightWidth + deltaSign * 2)) }
		} else if (activeTool.value === 'drawing' || isShapeTool(activeTool.value)) {
			toolStyle.value = { ...toolStyle.value, strokeWidth: stepStrokeWidth(toolStyle.value.strokeWidth, deltaSign) }
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
		handleAnnotationEditorKeydown(e, {
			hasSelection: () => Boolean(selectedAnnotation.value || selectedUuid.value),
			undo,
			redo,
			deleteSelected,
			clearSelection: () => { selectedUuid.value = null },
			setTool,
			nudgeSelected,
			adjustSizeOrThickness,
			selectStampSymbol,
		})
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
				void flushPendingUpdate()
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
		scheduleCoalescedUpdate(current, updated)
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
		scheduleCoalescedUpdate(current, updated)
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
