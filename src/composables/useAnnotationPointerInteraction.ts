import type { ComputedRef, Ref } from 'vue'
import type {
	NormalizedPoint,
	SheetAnnotation,
} from '../types/annotation'
import type { EditorTool, EditorToolStyle } from './useAnnotationEditor'
import { ref } from 'vue'
import { useAnnotationInlineEditors } from './useAnnotationInlineEditors'
import { useHandwritingInput } from './useHandwritingInput'
import { useStylusSettings } from './useStylusSettings'
import { translateAnnotation } from '../utils/annotation'
import {
	applyEndpointMove,
	applyHeightMove,
	applyResizeMove,
	buildAnnotationDraft,
	roundNorm,
} from '../utils/annotationTransform'
import {
	applyPenTipOffset,
	createStrokePressureTracker,
	getCoalescedPointerEvents,
	isLikelyPalmPointerEvent,
	isStylusEraserEvent,
	type StrokePressureTracker,
} from '../utils/stylus-settings'

type InteractionKind = 'draw' | 'move' | 'resize' | 'endpoint' | 'height' | 'stylus-erase'

interface InteractionState {
	kind: InteractionKind
	pointerId?: number
	startNorm: NormalizedPoint
	currentNorm: NormalizedPoint
	points: NormalizedPoint[]
	targetBefore?: SheetAnnotation
	handle?: string
	moved?: boolean
	pointerType?: string
	pressureTracker?: StrokePressureTracker
	erasedUuids?: Set<string>
}

export interface UseAnnotationPointerInteractionOptions {
	svgRef: Ref<SVGSVGElement | null>
	vbHeight: ComputedRef<number>
	pageIndex: ComputedRef<number>
	pageNumber: ComputedRef<number>
	totalPages: ComputedRef<number>
	editable: ComputedRef<boolean>
	activeTool: ComputedRef<EditorTool>
	toolStyle: ComputedRef<EditorToolStyle>
	activeAnnotations: ComputedRef<SheetAnnotation[]>
	selectedItem: ComputedRef<SheetAnnotation | null>
	onSelect: (uuid: string | null) => void
	onCreate: (draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> & { uuid?: string }) => void
	onUpdate: (before: SheetAnnotation, after: SheetAnnotation) => void
	onDelete: (annotation: SheetAnnotation) => void
}

/**
 * Manages pointer interactions (draw, move, resize, endpoint/height drag, erase, hover)
 * and inline text/link creation popovers for SongAnnotationOverlay.
 */
export function useAnnotationPointerInteraction(opts: UseAnnotationPointerInteractionOptions) {
	const {
		settings: stylusSettings,
		isTouchDrawingBlocked,
		markPenDetected,
		isPenRecentlyActive,
	} = useStylusSettings()

	const interaction = ref<InteractionState | null>(null)
	const previewAnnotation = ref<SheetAnnotation | null>(null)
	const hoveredUuid = ref<string | null>(null)
	const {
		pendingText,
		pendingLink,
		openCreateText,
		openCreateLink,
		onAnnotationDblClick,
		commitPendingText,
		cancelPendingText,
		commitPendingLink,
		cancelPendingLink,
	} = useAnnotationInlineEditors({
		pageIndex: opts.pageIndex,
		pageNumber: opts.pageNumber,
		totalPages: opts.totalPages,
		editable: opts.editable,
		activeTool: opts.activeTool,
		toolStyle: opts.toolStyle,
		onCreate: opts.onCreate,
		onUpdate: opts.onUpdate,
		onDelete: opts.onDelete,
	})
	const handwriting = useHandwritingInput({
		vbHeight: opts.vbHeight,
		pageIndex: opts.pageIndex,
		editable: opts.editable,
		activeTool: opts.activeTool,
		toolStyle: opts.toolStyle,
		activeAnnotations: opts.activeAnnotations,
		onCreate: opts.onCreate,
		onUpdate: opts.onUpdate,
		onDelete: opts.onDelete,
	})
	const activeHandwritingStroke = ref<NormalizedPoint[]>([])
	const activeTouchPointers = new Set<number>()
	let capturedPointerId: number | null = null
	let isMultiTouchGesture = false

	function capturePointer(pointerId: number) {
		opts.svgRef.value?.setPointerCapture?.(pointerId)
		capturedPointerId = pointerId
	}

	function releaseCapturedPointer(pointerId?: number) {
		const idToRelease = pointerId ?? capturedPointerId
		if (idToRelease !== null && opts.svgRef.value?.hasPointerCapture?.(idToRelease)) {
			opts.svgRef.value.releasePointerCapture(idToRelease)
		}
		if (pointerId === undefined || capturedPointerId === pointerId) {
			capturedPointerId = null
		}
	}

	function cancelInteraction(pointerId?: number) {
		interaction.value = null
		previewAnnotation.value = null
		activeHandwritingStroke.value = []
		handwriting.resumeRecognizeTimerIfPending()
		releaseCapturedPointer(pointerId)
	}

	function handlePenTakeover(e: PointerEvent) {
		if (e.pointerType !== 'pen') return
		markPenDetected()
		if (interaction.value?.pointerType === 'touch') {
			cancelInteraction()
		}
	}

	function cloneAnnotation(ann: SheetAnnotation): SheetAnnotation {
		return JSON.parse(JSON.stringify(ann))
	}

	function setDraftPreview(draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> | null) {
		if (draft) {
			previewAnnotation.value = { ...draft, uuid: '__draft__', songId: 0, fileId: 0 }
		}
	}

	function clientToNorm(clientX: number, clientY: number, pointerType?: string): NormalizedPoint {
		const el = opts.svgRef.value
		if (!el) return { x: 0, y: 0 }
		const rect = el.getBoundingClientRect()
		if (rect.width <= 0 || rect.height <= 0) return { x: 0, y: 0 }
		const adj = applyPenTipOffset(clientX, clientY, pointerType, stylusSettings.value)
		const x = Math.min(1, Math.max(0, (adj.x - rect.left) / rect.width))
		const y = Math.min(1, Math.max(0, (adj.y - rect.top) / rect.height))
		return { x: roundNorm(x), y: roundNorm(y) }
	}

	function shouldCapturePressure(): boolean {
		return opts.activeTool.value === 'drawing'
			&& opts.toolStyle.value.lineStyle === 'solid'
			&& stylusSettings.value.pressureMode !== 'off'
			&& stylusSettings.value.pressureSensitivity > 0
	}

	function sampleStrokePressure(ev: PointerEvent, state: InteractionState): number | undefined {
		if (!shouldCapturePressure() || !state.pressureTracker) return undefined
		return state.pressureTracker.sample(ev, stylusSettings.value).effectivePressure
	}

	function findAnnotationUuidFromElement(el: Element | null): string | null {
		let target = el as HTMLElement | SVGElement | null
		while (target && target !== opts.svgRef.value) {
			const uuid = target.getAttribute?.('data-ann-uuid')
			if (uuid) return uuid
			target = target.parentElement
		}
		return null
	}

	function findAnnotationUuidFromEvent(e: PointerEvent | MouseEvent): string | null {
		return findAnnotationUuidFromElement(e.target as Element | null)
	}

	function findAnnotationUuidAtStylusPoint(e: PointerEvent): string | null {
		const adj = applyPenTipOffset(e.clientX, e.clientY, e.pointerType, stylusSettings.value)
		const hitEl = typeof document.elementFromPoint === 'function'
			? document.elementFromPoint(adj.x, adj.y)
			: null
		return findAnnotationUuidFromElement(hitEl) ?? findAnnotationUuidFromEvent(e)
	}

	function eraseAnnotationByUuid(uuid: string | null, erasedSet?: Set<string>) {
		if (!uuid) return
		if (erasedSet?.has(uuid)) return
		const ann = opts.activeAnnotations.value.find((a) => a.uuid === uuid)
		if (ann) {
			erasedSet?.add(uuid)
			opts.onDelete(ann)
		}
	}

	function buildDraftFromTool(
		p1: NormalizedPoint,
		p2: NormalizedPoint,
		strokePoints: NormalizedPoint[],
	): Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> | null {
		return buildAnnotationDraft(
			opts.activeTool.value,
			opts.toolStyle.value,
			opts.pageIndex.value,
			p1,
			p2,
			strokePoints,
		)
	}

	function onPointerDown(e: PointerEvent) {
		if (!opts.editable.value || e.ctrlKey || e.metaKey) return

		handlePenTakeover(e)

		const isHardwareErase = stylusSettings.value.hardwareEraser && isStylusEraserEvent(e)
		if (e.button !== 0 && !isHardwareErase) return

		if (e.pointerType === 'touch') {
			if (
				isTouchDrawingBlocked.value
				|| interaction.value?.pointerType === 'pen'
				|| isLikelyPalmPointerEvent(e)
				|| isPenRecentlyActive()
			) {
				return
			}
			activeTouchPointers.add(e.pointerId)
			if (activeTouchPointers.size >= 2) {
				isMultiTouchGesture = true
				cancelInteraction()
				return
			}
		}
		if (isMultiTouchGesture) return
		if (pendingText.value || pendingLink.value) return

		const pt = clientToNorm(e.clientX, e.clientY, e.pointerType)

		if (isHardwareErase) {
			e.preventDefault()
			const erasedUuids = new Set<string>()
			eraseAnnotationByUuid(findAnnotationUuidAtStylusPoint(e), erasedUuids)
			capturePointer(e.pointerId)
			interaction.value = {
				kind: 'stylus-erase',
				pointerId: e.pointerId,
				startNorm: pt,
				currentNorm: pt,
				points: [pt],
				pointerType: e.pointerType,
				erasedUuids,
			}
			return
		}

		const targetEl = e.target as HTMLElement | SVGElement | null
		const handle = targetEl?.getAttribute?.('data-resize-handle')

		if (opts.activeTool.value !== 'eraser' && handle && opts.selectedItem.value) {
			e.preventDefault()
			e.stopPropagation()
			capturePointer(e.pointerId)
			const kind: InteractionKind = handle === 'height'
				? 'height'
				: handle === 'p0' || handle === 'p1'
					? 'endpoint'
					: 'resize'
			interaction.value = {
				kind,
				pointerId: e.pointerId,
				handle,
				startNorm: pt,
				currentNorm: pt,
				points: [pt],
				targetBefore: cloneAnnotation(opts.selectedItem.value),
				moved: false,
				pointerType: e.pointerType,
			}
			return
		}

		const hitUuid = findAnnotationUuidFromEvent(e)

		if (opts.activeTool.value === 'eraser') {
			eraseAnnotationByUuid(hitUuid)
			return
		}

		if (opts.activeTool.value === 'select' || (hitUuid && !(opts.activeTool.value === 'handwriting' && handwriting.pendingStrokes.value.length > 0))) {
			if (hitUuid) {
				handwriting.dismissSwapPill()
				const ann = opts.activeAnnotations.value.find((a) => a.uuid === hitUuid)
				opts.onSelect(hitUuid)
				if (ann) {
					e.preventDefault()
					capturePointer(e.pointerId)
					interaction.value = {
						kind: 'move',
						pointerId: e.pointerId,
						startNorm: pt,
						currentNorm: pt,
						points: [pt],
						targetBefore: cloneAnnotation(ann),
						moved: false,
						pointerType: e.pointerType,
					}
				}
			} else {
				opts.onSelect(null)
			}
			return
		}

		if (opts.selectedItem.value) {
			opts.onSelect(null)
		}

		e.preventDefault()
		capturePointer(e.pointerId)
		const state: InteractionState = {
			kind: 'draw',
			pointerId: e.pointerId,
			startNorm: pt,
			currentNorm: pt,
			points: [],
			moved: false,
			pointerType: e.pointerType,
			pressureTracker: createStrokePressureTracker(e.pointerType),
		}
		const initialPressure = sampleStrokePressure(e, state)
		const startPoint: NormalizedPoint = initialPressure !== undefined ? { ...pt, p: initialPressure } : pt
		state.startNorm = startPoint
		state.currentNorm = startPoint
		state.points.push(startPoint)
		interaction.value = state

		if (opts.activeTool.value === 'handwriting') {
			handwriting.beginStroke(startPoint)
			activeHandwritingStroke.value = [startPoint]
		} else {
			setDraftPreview(buildDraftFromTool(startPoint, startPoint, state.points))
		}
	}

	function onPointerMove(e: PointerEvent) {
		handlePenTakeover(e)
		if (isMultiTouchGesture) return
		const state = interaction.value
		if (!state) {
			if (opts.editable.value) {
				hoveredUuid.value = findAnnotationUuidFromEvent(e)
			} else if (hoveredUuid.value !== null) {
				hoveredUuid.value = null
			}
			return
		}
		if (state.pointerId !== undefined && e.pointerId !== state.pointerId) {
			return
		}

		if (state.kind === 'stylus-erase') {
			eraseAnnotationByUuid(findAnnotationUuidAtStylusPoint(e), state.erasedUuids)
			return
		}

		const pt = clientToNorm(e.clientX, e.clientY, e.pointerType)
		state.currentNorm = pt
		if (Math.hypot(pt.x - state.startNorm.x, pt.y - state.startNorm.y) > 0.002) {
			state.moved = true
		}

		if (state.kind === 'move' && state.targetBefore) {
			previewAnnotation.value = translateAnnotation(
				state.targetBefore,
				pt.x - state.startNorm.x,
				pt.y - state.startNorm.y,
			)
			return
		}

		if (state.kind === 'resize' && state.targetBefore && state.handle) {
			previewAnnotation.value = applyResizeMove(state.targetBefore, state.handle, pt, opts.vbHeight.value)
			return
		}

		if (state.kind === 'endpoint' && state.targetBefore && state.handle) {
			const next = applyEndpointMove(state.targetBefore, state.handle, pt)
			if (next) previewAnnotation.value = next
			return
		}

		if (state.kind === 'height' && state.targetBefore) {
			const next = applyHeightMove(state.targetBefore, pt, opts.vbHeight.value)
			if (next) previewAnnotation.value = next
			return
		}

		if (state.kind === 'draw') {
			const tool = opts.activeTool.value
			if (tool === 'drawing' || tool === 'highlight' || tool === 'handwriting') {
				for (const ev of getCoalescedPointerEvents(e)) {
					const evPt = clientToNorm(ev.clientX, ev.clientY, ev.pointerType || e.pointerType)
					const last = state.points[state.points.length - 1]
					if (!last || Math.hypot(evPt.x - last.x, evPt.y - last.y) > 0.002) {
						const p = sampleStrokePressure(ev, state)
						state.points.push(p !== undefined ? { ...evPt, p } : evPt)
					}
				}
			}
			if (tool === 'handwriting') {
				activeHandwritingStroke.value = [...state.points]
			} else {
				setDraftPreview(buildDraftFromTool(state.startNorm, pt, state.points))
			}
		}
	}

	function onPointerUp(e: PointerEvent) {
		if (e.pointerType === 'touch') {
			activeTouchPointers.delete(e.pointerId)
			if (isMultiTouchGesture) {
				cancelInteraction(e.pointerId)
				if (activeTouchPointers.size === 0) {
					isMultiTouchGesture = false
				}
				return
			}
		}

		const state = interaction.value
		if (!state) return
		if (state.pointerId !== undefined && e.pointerId !== state.pointerId) {
			return
		}
		interaction.value = null
		releaseCapturedPointer(e.pointerId)

		if (state.kind === 'stylus-erase') {
			return
		}

		const pt = clientToNorm(e.clientX, e.clientY, e.pointerType)
		const preview = previewAnnotation.value
		previewAnnotation.value = null

		if (state.kind !== 'draw' && state.targetBefore) {
			if (state.moved && preview) {
				opts.onUpdate(state.targetBefore, preview)
			}
			return
		}

		if (state.kind === 'draw') {
			if (opts.activeTool.value === 'handwriting') {
				activeHandwritingStroke.value = []
				handwriting.endStroke(state.points)
				return
			}

			if (opts.activeTool.value === 'text') {
				openCreateText(state.startNorm, pt, Boolean(state.moved))
				return
			}

			if (opts.activeTool.value === 'link') {
				openCreateLink(state.startNorm, pt, Boolean(state.moved))
				return
			}

			const draft = buildDraftFromTool(state.startNorm, pt, state.points)
			if (draft) {
				opts.onCreate(draft)
			}
		}
	}

	function onPointerCancel(e: PointerEvent) {
		if (e.pointerType === 'touch') {
			activeTouchPointers.delete(e.pointerId)
			if (activeTouchPointers.size === 0) {
				isMultiTouchGesture = false
			}
		}
		const state = interaction.value
		if (state && state.pointerId !== undefined && e.pointerId !== state.pointerId) {
			return
		}
		cancelInteraction(e.pointerId)
	}

	return {
		previewAnnotation,
		hoveredUuid,
		pendingText,
		pendingLink,
		handwritingPendingStrokes: handwriting.pendingStrokes,
		activeHandwritingStroke,
		handwritingSwapState: handwriting.swapState,
		selectHandwritingCandidate: handwriting.selectCandidate,
		keepHandwritingInk: handwriting.keepInk,
		deleteHandwritingPlaced: handwriting.deletePlaced,
		extendHandwritingPillTimeout: handwriting.extendSwapPillTimeout,
		dismissHandwritingSwapPill: handwriting.dismissSwapPill,
		onPointerDown,
		onPointerMove,
		onPointerUp,
		onPointerCancel,
		onAnnotationDblClick,
		commitPendingText,
		cancelPendingText,
		commitPendingLink,
		cancelPendingLink,
	}
}
