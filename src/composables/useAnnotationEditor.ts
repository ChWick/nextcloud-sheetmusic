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
import { hydrateHandwritingModelFromServer } from '../utils/handwritingModel'
import {
	createDefaultToolStyle,
	mergeAnnotationStyleIntoToolStyle,
	stepStrokeWidth,
} from '../utils/annotationToolStyle'

export type EditorTool =
	| 'select'
	| 'box-select'
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
	| 'bracket-up'
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
	| { kind: 'batch-update'; before: SheetAnnotation[]; after: SheetAnnotation[] }
	| { kind: 'delete'; annotation: SheetAnnotation }
	| { kind: 'batch-delete'; annotations: SheetAnnotation[] }

export function createUuid(): string {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}
	return 'ann-' + Math.random().toString(36).slice(2, 10) + '-' + Date.now().toString(36)
}

export function isShapeTool(tool: EditorTool): boolean {
	return (
		tool === 'line' ||
		tool === 'arrow' ||
		tool === 'crescendo' ||
		tool === 'decrescendo' ||
		tool === 'slur' ||
		tool === 'rect' ||
		tool === 'ellipse' ||
		tool === 'bracket' ||
		tool === 'bracket-up'
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
	const selectedUuids = ref<string[]>([])
	const selectedUuid = computed<string | null>({
		get: () => selectedUuids.value[0] ?? null,
		set: (uuid) => {
			selectedUuids.value = uuid ? [uuid] : []
		},
	})
	const isSaving = ref(false)
	const saveError = ref<string | null>(null)

	const toolStyle = ref<EditorToolStyle>(createDefaultToolStyle())

	const undoStack = ref<HistoryAction[]>([])
	const redoStack = ref<HistoryAction[]>([])

	const canUndo = computed(() => undoStack.value.length > 0)
	const canRedo = computed(() => redoStack.value.length > 0)

	const selectedAnnotations = computed<SheetAnnotation[]>(() => {
		if (selectedUuids.value.length === 0) return []
		const idSet = new Set(selectedUuids.value)
		return annotations.value.filter((a) => !a.deletedAt && idSet.has(a.uuid))
	})

	const selectedAnnotation = computed<SheetAnnotation | null>(() => {
		if (selectedAnnotations.value.length !== 1) return null
		return selectedAnnotations.value[0]
	})

	function enterEditMode() {
		isEditing.value = true
		saveError.value = null
		void hydrateHandwritingModelFromServer()
	}

	function exitEditMode() {
		void flushPendingUpdate()
		isEditing.value = false
		selectedUuids.value = []
	}

	function setTool(tool: EditorTool) {
		activeTool.value = tool
		if (tool !== 'select' && tool !== 'box-select') {
			void flushPendingUpdate()
			selectedUuids.value = []
		}
	}

	function syncToolStyleFromAnnotation(ann: SheetAnnotation) {
		toolStyle.value = mergeAnnotationStyleIntoToolStyle(toolStyle.value, ann)
	}

	function selectAnnotation(uuid: string | null) {
		if (pendingUpdate && pendingUpdate.after.uuid !== uuid) {
			void flushPendingUpdate()
		}
		if (pendingBatchUpdate) {
			void flushPendingUpdate()
		}
		selectedUuids.value = uuid ? [uuid] : []
		if (uuid) {
			const ann = annotations.value.find((a) => a.uuid === uuid && !a.deletedAt)
			if (ann) {
				syncToolStyleFromAnnotation(ann)
			}
		}
	}

	function selectAnnotations(uuids: string[]) {
		const unique = Array.from(new Set(uuids.filter(Boolean)))
		void flushPendingUpdate()
		selectedUuids.value = unique
		if (unique.length > 0) {
			const first = annotations.value.find((a) => a.uuid === unique[0] && !a.deletedAt)
			if (first) {
				syncToolStyleFromAnnotation(first)
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
		if (selectedUuids.value.includes(uuid)) {
			selectedUuids.value = selectedUuids.value.filter((id) => id !== uuid)
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
	let pendingBatchUpdate: {
		key: string
		beforeByUuid: Map<string, SheetAnnotation>
		afterByUuid: Map<string, SheetAnnotation>
		timer: ReturnType<typeof setTimeout> | null
	} | null = null

	async function flushPendingUpdate(): Promise<void> {
		if (pendingUpdate) {
			const { before, after, timer } = pendingUpdate
			pendingUpdate = null
			if (timer !== null) {
				clearTimeout(timer)
			}
			await updateAnnotation(before, after, true)
		}
		if (pendingBatchUpdate) {
			const { beforeByUuid, afterByUuid, timer } = pendingBatchUpdate
			pendingBatchUpdate = null
			if (timer !== null) {
				clearTimeout(timer)
			}
			const pairs: Array<{ before: SheetAnnotation; after: SheetAnnotation }> = []
			for (const [uuid, after] of afterByUuid.entries()) {
				const before = beforeByUuid.get(uuid)
				if (before) {
					pairs.push({ before, after })
				}
			}
			if (pairs.length > 0) {
				await batchUpdateAnnotations(pairs, true)
			}
		}
	}

	function scheduleCoalescedUpdate(before: SheetAnnotation, after: SheetAnnotation): void {
		bumpLocalRevision(after.uuid)
		upsertLocal(after)
		if (selectedUuids.value.length === 1 && selectedUuids.value[0] === after.uuid) {
			syncToolStyleFromAnnotation(after)
		}

		if (pendingUpdate && pendingUpdate.after.uuid !== after.uuid) {
			void flushPendingUpdate()
		}
		if (pendingBatchUpdate) {
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

	function scheduleCoalescedBatchUpdate(
		pairs: Array<{ before: SheetAnnotation; after: SheetAnnotation }>,
	): void {
		if (pairs.length === 0) return
		if (pairs.length === 1) {
			scheduleCoalescedUpdate(pairs[0].before, pairs[0].after)
			return
		}
		for (const { after } of pairs) {
			bumpLocalRevision(after.uuid)
			upsertLocal(after)
		}

		const key = pairs.map((p) => p.after.uuid).sort().join(',')
		if (pendingUpdate || (pendingBatchUpdate && pendingBatchUpdate.key !== key)) {
			void flushPendingUpdate()
		}

		const beforeByUuid = pendingBatchUpdate?.beforeByUuid ?? new Map<string, SheetAnnotation>()
		const afterByUuid = pendingBatchUpdate?.afterByUuid ?? new Map<string, SheetAnnotation>()
		for (const { before, after } of pairs) {
			if (!beforeByUuid.has(after.uuid)) {
				beforeByUuid.set(after.uuid, cloneAnn(before))
			}
			afterByUuid.set(after.uuid, cloneAnn(after))
		}
		if (pendingBatchUpdate?.timer !== null && pendingBatchUpdate?.timer !== undefined) {
			clearTimeout(pendingBatchUpdate.timer)
		}
		const timer = setTimeout(() => {
			void flushPendingUpdate()
		}, STYLE_SAVE_DEBOUNCE_MS)
		pendingBatchUpdate = { key, beforeByUuid, afterByUuid, timer }
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
		if (selectedUuids.value.some((id) => uuidsToDelete.has(id))) {
			selectedUuids.value = selectedUuids.value.filter((id) => !uuidsToDelete.has(id))
		}
		if (idsToDelete.length > 0) {
			await withSaving(
				() => batchContextAnnotations(getContext(), { deleteIds: idsToDelete }),
				swallowError ? 'Failed to clear page annotations' : undefined,
				!swallowError,
			)
		}
	}

	async function persistBatchUpsert(anns: SheetAnnotation[]): Promise<SheetAnnotation[]> {
		if (anns.length === 0) return []
		const ctx = getContext()
		const songId = isSetlistContext(ctx) ? 0 : ctx.songId
		const revisions = new Map<string, number>()
		for (const ann of anns) {
			revisions.set(ann.uuid, localRevisions.get(ann.uuid) ?? 0)
		}
		const payload = anns.map((ann) => ({
			...ann,
			songId,
			fileId: ann.fileId || ctx.fileId,
		}))
		const saved = await withSaving(async () => {
			const res = await batchContextAnnotations(ctx, { upsert: payload })
			for (const s of res.saved) {
				const startRev = revisions.get(s.uuid) ?? 0
				if ((localRevisions.get(s.uuid) ?? 0) === startRev) {
					upsertLocal(s)
				} else if (s.id) {
					const latest = annotations.value.find((a) => a.uuid === s.uuid)
					if (latest && !latest.id) {
						upsertLocal({ ...latest, id: s.id })
					}
				}
			}
			return res.saved
		}, 'Failed to save annotations')
		return saved ?? anns
	}

	async function batchUpdateAnnotations(
		pairs: Array<{ before: SheetAnnotation; after: SheetAnnotation }>,
		recordHistory = true,
	): Promise<SheetAnnotation[]> {
		if (pairs.length === 0) return []
		if (pairs.length === 1) {
			const saved = await updateAnnotation(pairs[0].before, pairs[0].after, recordHistory)
			return [saved]
		}
		for (const { after } of pairs) {
			upsertLocal(after)
		}
		const saved = await persistBatchUpsert(pairs.map((p) => p.after))
		if (recordHistory) {
			const savedByUuid = new Map(saved.map((s) => [s.uuid, s]))
			undoStack.value = [
				...undoStack.value,
				{
					kind: 'batch-update',
					before: pairs.map((p) => cloneAnn(p.before)),
					after: pairs.map((p) => cloneAnn(savedByUuid.get(p.after.uuid) ?? p.after)),
				},
			]
			redoStack.value = []
		}
		return saved
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
		const selected = selectedAnnotations.value
		if (selected.length === 0) return
		if (selected.length === 1) {
			await deleteAnnotation(selected[0])
			return
		}
		await flushPendingUpdate()
		const snapshots = selected.map(cloneAnn)
		selectedUuids.value = []
		await batchDeleteLocalAndRemote(selected)
		undoStack.value = [...undoStack.value, { kind: 'batch-delete', annotations: snapshots }]
		redoStack.value = []
	}

	async function clearPage(pageNumberZeroIndexed: number): Promise<void> {
		await flushPendingUpdate()
		const pageAnns = annotations.value.filter(
			(a) => !a.deletedAt && a.pageNumber === pageNumberZeroIndexed,
		)
		if (pageAnns.length === 0) return

		const snapshots = pageAnns.map(cloneAnn)
		selectedUuids.value = []
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
		} else if (action.kind === 'batch-update') {
			for (const b of action.before) upsertLocal(b)
			const restored = await persistBatchUpsert(action.before)
			redoStack.value = [
				...redoStack.value,
				{ kind: 'batch-update', before: restored, after: action.after },
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
		} else if (action.kind === 'batch-update') {
			for (const a of action.after) upsertLocal(a)
			const restored = await persistBatchUpsert(action.after)
			undoStack.value = [
				...undoStack.value,
				{ kind: 'batch-update', before: action.before, after: restored },
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
		const selected = selectedAnnotations.value
		if (selected.length > 0) {
			const pairs: Array<{ before: SheetAnnotation; after: SheetAnnotation }> = []
			for (const sel of selected) {
				if (sel.type === 'stamp' || sel.type === 'text' || sel.type === 'link') {
					const fallback = sel.type === 'stamp' ? toolStyle.value.stampSize : sel.type === 'link' ? 14 : toolStyle.value.fontSize
					const cur = Number(sel.style?.fontSize) || fallback
					const step = sel.type === 'stamp' ? 4 : 2
					const nextSize = Math.max(8, Math.min(120, cur + deltaSign * step))
					if (nextSize !== cur) {
						pairs.push({ before: sel, after: { ...sel, style: { ...sel.style, fontSize: nextSize } } })
					}
				} else if (sel.type === 'drawing' || sel.type === 'highlight' || sel.type === 'shape') {
					const cur = Number(sel.style?.strokeWidth) || (sel.type === 'highlight' ? toolStyle.value.highlightWidth : toolStyle.value.strokeWidth)
					const nextWidth = stepStrokeWidth(cur, deltaSign)
					if (nextWidth !== cur) {
						pairs.push({ before: sel, after: { ...sel, style: { ...sel.style, strokeWidth: nextWidth } } })
					}
				}
			}
			if (pairs.length > 0) {
				scheduleCoalescedBatchUpdate(pairs)
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
		const selected = selectedAnnotations.value
		if (selected.length === 0) return
		if (selected.length === 1) {
			const moved = translateAnnotation(selected[0], dx, dy)
			void updateAnnotation(selected[0], moved)
			return
		}
		void batchUpdateAnnotations(
			selected.map((ann) => ({ before: ann, after: translateAnnotation(ann, dx, dy) })),
		)
	}

	function handleKeydown(e: KeyboardEvent): void {
		if (!isEditing.value) return
		handleAnnotationEditorKeydown(e, {
			hasSelection: () => selectedUuids.value.length > 0,
			undo,
			redo,
			deleteSelected,
			clearSelection: () => { selectedUuids.value = [] },
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

	function buildStylePatchForAnnotation(
		ann: SheetAnnotation,
		patch: Partial<SheetAnnotation['style']>,
	): Partial<SheetAnnotation['style']> | null {
		const next: Partial<SheetAnnotation['style']> = {}
		let hasChange = false

		const chosenColor = patch.color ?? patch.stroke
		if (chosenColor !== undefined) {
			if (ann.type === 'text' || ann.type === 'stamp') {
				next.color = chosenColor
				hasChange = true
			} else if (ann.type === 'drawing' || ann.type === 'highlight' || ann.type === 'shape') {
				next.stroke = chosenColor
				hasChange = true
			} else if (ann.type === 'link') {
				next.color = chosenColor
				next.stroke = chosenColor
				hasChange = true
			}
		}

		if (patch.opacity !== undefined) {
			next.opacity = patch.opacity
			hasChange = true
		}

		if (patch.fontSize !== undefined && (ann.type === 'text' || ann.type === 'stamp' || ann.type === 'link')) {
			next.fontSize = patch.fontSize
			hasChange = true
		}

		if (patch.strokeWidth !== undefined && (ann.type === 'drawing' || ann.type === 'highlight' || ann.type === 'shape')) {
			next.strokeWidth = patch.strokeWidth
			hasChange = true
		}

		if (patch.lineStyle !== undefined && (ann.type === 'drawing' || ann.type === 'shape')) {
			next.lineStyle = patch.lineStyle
			hasChange = true
		}

		if (patch.textAlign !== undefined && ann.type === 'text') {
			next.textAlign = patch.textAlign
			hasChange = true
		}

		for (const [k, v] of Object.entries(patch)) {
			if (
				k !== 'color'
				&& k !== 'stroke'
				&& k !== 'opacity'
				&& k !== 'fontSize'
				&& k !== 'strokeWidth'
				&& k !== 'lineStyle'
				&& k !== 'textAlign'
				&& v !== undefined
			) {
				;(next as Record<string, unknown>)[k] = v
				hasChange = true
			}
		}

		return hasChange ? next : null
	}

	function updateSelectedStyle(patch: Partial<SheetAnnotation['style']>) {
		const selected = selectedAnnotations.value
		if (selected.length === 0) return
		if (selected.length === 1) {
			const current = selected[0]
			const updated: SheetAnnotation = {
				...current,
				style: {
					...current.style,
					...patch,
				},
			}
			scheduleCoalescedUpdate(current, updated)
			return
		}

		const pairs: Array<{ before: SheetAnnotation; after: SheetAnnotation }> = []
		for (const ann of selected) {
			const applicable = buildStylePatchForAnnotation(ann, patch)
			if (!applicable) continue
			pairs.push({
				before: ann,
				after: {
					...ann,
					style: {
						...ann.style,
						...applicable,
					},
				},
			})
		}
		if (pairs.length > 0) {
			scheduleCoalescedBatchUpdate(pairs)
			syncToolStyleFromAnnotation(pairs[0].after)
		}
	}

	function updateSelectedData(patch: Partial<SheetAnnotation['data']>) {
		const selected = selectedAnnotations.value
		if (selected.length === 0) return
		if (selected.length === 1) {
			const current = selected[0]
			const updated: SheetAnnotation = {
				...current,
				data: {
					...current.data,
					...patch,
				},
			}
			scheduleCoalescedUpdate(current, updated)
			return
		}

		const pairs: Array<{ before: SheetAnnotation; after: SheetAnnotation }> = []
		for (const ann of selected) {
			if (ann.type === 'shape' && (patch.aperture !== undefined || patch.curvature !== undefined)) {
				const st = ann.data?.shapeType
				if (st === 'slur') {
					const raw = Math.abs(patch.curvature ?? patch.aperture ?? 18)
					const sign = (ann.data?.curvature ?? -1) < 0 ? -1 : 1
					pairs.push({ before: ann, after: { ...ann, data: { ...ann.data, curvature: sign * raw } } })
				} else if (st === 'bracket') {
					const raw = Math.abs(patch.aperture ?? patch.curvature ?? 18)
					const sign = (ann.data?.aperture ?? 1) < 0 ? -1 : 1
					pairs.push({ before: ann, after: { ...ann, data: { ...ann.data, aperture: sign * raw } } })
				} else if (st === 'crescendo' || st === 'decrescendo') {
					const raw = Math.abs(patch.aperture ?? patch.curvature ?? 18)
					pairs.push({ before: ann, after: { ...ann, data: { ...ann.data, aperture: raw } } })
				}
			} else {
				pairs.push({
					before: ann,
					after: {
						...ann,
						data: {
							...ann.data,
							...patch,
						},
					},
				})
			}
		}
		if (pairs.length > 0) {
			scheduleCoalescedBatchUpdate(pairs)
		}
	}

	return {
		isEditing,
		activeTool,
		selectedUuid,
		selectedUuids,
		selectedAnnotation,
		selectedAnnotations,
		toolStyle,
		isSaving,
		saveError,
		canUndo,
		canRedo,
		enterEditMode,
		exitEditMode,
		setTool,
		selectAnnotation,
		selectAnnotations,
		selectStampSymbol,
		handleKeydown,
		createAnnotation,
		updateAnnotation,
		batchUpdateAnnotations,
		updateSelectedStyle,
		updateSelectedData,
		deleteAnnotation,
		deleteSelected,
		clearPage,
		undo,
		redo,
	}
}
