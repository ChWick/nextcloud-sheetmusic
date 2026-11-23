import type { ComputedRef, Ref } from 'vue'
import type {
	NormalizedPoint,
	ShapeType,
	SheetAnnotation,
} from '../types/annotation'
import type { EditorTool, EditorToolStyle } from './useAnnotationEditor'
import type { PendingLinkEdit, PendingTextEdit } from '../components/song-viewer/SongAnnotationInlinePopovers.vue'
import { ref } from 'vue'
import { isShapeTool } from './useAnnotationEditor'
import { useStylusSettings } from './useStylusSettings'
import {
	computeBoundingBoxFromPoints,
	translateAnnotation,
	VIEWBOX_WIDTH,
} from '../utils/annotation'
import {
	applyPenTipOffset,
	createStrokePressureTracker,
	getCoalescedPointerEvents,
	isLikelyPalmPointerEvent,
	isStylusEraserEvent,
	type StrokePressureTracker,
} from '../utils/stylus-settings'
import { getStampDefinition } from '../utils/musicSymbols'

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
	onCreate: (draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'>) => void
	onUpdate: (before: SheetAnnotation, after: SheetAnnotation) => void
	onDelete: (annotation: SheetAnnotation) => void
}

function roundNorm(val: number): number {
	return Math.round(val * 10000) / 10000
}

function applyResizeMove(b: SheetAnnotation, handle: string, pt: NormalizedPoint, vbHeight: number): SheetAnnotation {
	let x1 = b.x
	let y1 = b.y
	let x2 = b.x + b.width
	let y2 = b.y + b.height
	if (handle.includes('w')) x1 = Math.min(x2 - 0.01, pt.x)
	if (handle.includes('e')) x2 = Math.max(x1 + 0.01, pt.x)
	if (handle.includes('n')) y1 = Math.min(y2 - 0.01, pt.y)
	if (handle.includes('s')) y2 = Math.max(y1 + 0.01, pt.y)

	const next: SheetAnnotation = JSON.parse(JSON.stringify(b))
	next.x = roundNorm(x1)
	next.y = roundNorm(y1)
	next.width = roundNorm(x2 - x1)
	next.height = roundNorm(y2 - y1)
	if (next.type === 'stamp') {
		next.style.fontSize = Math.round(next.height * vbHeight * 0.85)
	}
	return next
}

function applyEndpointMove(b: SheetAnnotation, handle: string, pt: NormalizedPoint): SheetAnnotation | null {
	const next: SheetAnnotation = JSON.parse(JSON.stringify(b))
	const pts = next.data.points ?? []
	const idx = handle === 'p0' ? 0 : 1
	if (!pts[idx]) return null
	pts[idx] = pt
	const box = computeBoundingBoxFromPoints(pts, 0.01)
	next.x = box.x
	next.y = box.y
	next.width = box.width
	next.height = box.height
	return next
}

function applyHeightMove(b: SheetAnnotation, pt: NormalizedPoint, vbHeight: number): SheetAnnotation | null {
	const next: SheetAnnotation = JSON.parse(JSON.stringify(b))
	const pts = next.data.points ?? []
	if (pts.length < 2) return null

	const x1 = pts[0].x * VIEWBOX_WIDTH
	const y1 = pts[0].y * vbHeight
	const x2 = pts[1].x * VIEWBOX_WIDTH
	const y2 = pts[1].y * vbHeight
	const dx = x2 - x1
	const dy = y2 - y1
	const len = Math.hypot(dx, dy) || 1.0
	const nx = -dy / len
	const ny = dx / len

	const px = pt.x * VIEWBOX_WIDTH
	const py = pt.y * vbHeight
	const st = next.data.shapeType
	const baseX = st === 'crescendo' ? x2 : st === 'decrescendo' || st === 'bracket' ? x1 : (x1 + x2) / 2
	const baseY = st === 'crescendo' ? y2 : st === 'decrescendo' || st === 'bracket' ? y1 : (y1 + y2) / 2
	const proj = (px - baseX) * nx + (py - baseY) * ny

	if (st === 'slur') {
		next.data.curvature = Math.round(Math.max(-180, Math.min(180, proj * 2)) * 10) / 10
	} else if (st === 'bracket') {
		const clamped = Math.round(Math.max(-120, Math.min(120, proj)) * 10) / 10
		next.data.aperture = Math.abs(clamped) < 4 ? (clamped < 0 ? -4 : 4) : clamped
	} else {
		next.data.aperture = Math.round(Math.max(4, Math.min(120, Math.abs(proj))) * 10) / 10
	}
	return next
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
	const pendingText = ref<PendingTextEdit | null>(null)
	const pendingLink = ref<PendingLinkEdit | null>(null)
	const activeTouchPointers = new Set<number>()
	let capturedPointerId: number | null = null
	let isMultiTouchGesture = false

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
		releaseCapturedPointer(pointerId)
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
		const tool = opts.activeTool.value
		const s = opts.toolStyle.value
		const pageNumber = opts.pageIndex.value

		if (tool === 'drawing' || tool === 'highlight') {
			const pts = strokePoints.length >= 2 ? strokePoints : [p1, { x: Math.min(1, p1.x + 0.002), y: p1.y }]
			const box = computeBoundingBoxFromPoints(pts)
			return {
				pageNumber,
				layerIndex: 0,
				type: tool,
				...box,
				style: {
					stroke: tool === 'highlight' ? s.highlightColor : s.color,
					strokeWidth: tool === 'highlight' ? s.highlightWidth : s.strokeWidth,
					opacity: tool === 'highlight' ? s.highlightOpacity : s.opacity,
					blendMode: tool === 'highlight' ? 'multiply' : 'normal',
					lineStyle: tool === 'highlight' ? 'solid' : s.lineStyle,
				},
				data: { points: pts, smooth: true },
			}
		}

		if (isShapeTool(tool)) {
			if (tool === 'rect' || tool === 'ellipse') {
				return {
					pageNumber,
					layerIndex: 0,
					type: 'shape',
					x: roundNorm(Math.min(p1.x, p2.x)),
					y: roundNorm(Math.min(p1.y, p2.y)),
					width: roundNorm(Math.max(0.01, Math.abs(p2.x - p1.x))),
					height: roundNorm(Math.max(0.01, Math.abs(p2.y - p1.y))),
					style: {
						stroke: s.color,
						strokeWidth: s.strokeWidth,
						opacity: s.opacity,
						lineStyle: s.lineStyle,
						fill: s.fill,
					},
					data: { shapeType: tool as ShapeType },
				}
			}

			const box = computeBoundingBoxFromPoints([p1, p2], 0.01)
			const shapeData: SheetAnnotation['data'] = {
				shapeType: tool as ShapeType,
				points: [p1, p2],
			}
			if ((tool === 'crescendo' || tool === 'decrescendo' || tool === 'bracket') && s.shapeHeight) {
				shapeData.aperture = s.shapeHeight
			} else if (tool === 'slur' && s.shapeHeight) {
				shapeData.curvature = -Math.abs(s.shapeHeight)
			}
			return {
				pageNumber,
				layerIndex: 0,
				type: 'shape',
				...box,
				style: {
					stroke: s.color,
					strokeWidth: s.strokeWidth,
					opacity: s.opacity,
					lineStyle: s.lineStyle,
				},
				data: shapeData,
			}
		}

		if (tool === 'stamp') {
			const def = getStampDefinition(s.stampSymbol)
			return {
				pageNumber,
				layerIndex: 0,
				type: 'stamp',
				x: roundNorm(p2.x),
				y: roundNorm(p2.y),
				width: 0,
				height: 0,
				style: {
					color: s.color,
					fontSize: s.stampSize || 40,
					opacity: s.opacity,
				},
				data: {
					symbol: def?.symbol ?? s.stampSymbol,
					category: def?.category,
					fontKind: def?.fontKind ?? 'music',
				},
			}
		}

		return null
	}

	function onPointerDown(e: PointerEvent) {
		if (!opts.editable.value || e.ctrlKey || e.metaKey) return

		if (e.pointerType === 'pen') {
			markPenDetected()
			if (interaction.value?.pointerType === 'touch') {
				cancelInteraction()
			}
		}

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
			const adj = applyPenTipOffset(e.clientX, e.clientY, e.pointerType, stylusSettings.value)
			const hitEl = typeof document.elementFromPoint === 'function'
				? document.elementFromPoint(adj.x, adj.y)
				: null
			const hitUuid = findAnnotationUuidFromElement(hitEl) ?? findAnnotationUuidFromEvent(e)
			const erasedUuids = new Set<string>()
			eraseAnnotationByUuid(hitUuid, erasedUuids)
			opts.svgRef.value?.setPointerCapture?.(e.pointerId)
			capturedPointerId = e.pointerId
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
			opts.svgRef.value?.setPointerCapture?.(e.pointerId)
			capturedPointerId = e.pointerId
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
				targetBefore: JSON.parse(JSON.stringify(opts.selectedItem.value)),
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

		if (opts.activeTool.value === 'select') {
			if (hitUuid) {
				const ann = opts.activeAnnotations.value.find((a) => a.uuid === hitUuid)
				opts.onSelect(hitUuid)
				if (ann) {
					e.preventDefault()
					opts.svgRef.value?.setPointerCapture?.(e.pointerId)
					capturedPointerId = e.pointerId
					interaction.value = {
						kind: 'move',
						pointerId: e.pointerId,
						startNorm: pt,
						currentNorm: pt,
						points: [pt],
						targetBefore: JSON.parse(JSON.stringify(ann)),
						moved: false,
						pointerType: e.pointerType,
					}
				}
			} else {
				opts.onSelect(null)
			}
			return
		}

		e.preventDefault()
		opts.svgRef.value?.setPointerCapture?.(e.pointerId)
		capturedPointerId = e.pointerId
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

		const draft = buildDraftFromTool(startPoint, startPoint, state.points)
		if (draft) {
			previewAnnotation.value = { ...draft, uuid: '__draft__', songId: 0, fileId: 0 }
		}
	}

	function onPointerMove(e: PointerEvent) {
		if (e.pointerType === 'pen') {
			markPenDetected()
			if (interaction.value?.pointerType === 'touch') {
				cancelInteraction()
			}
		}
		if (isMultiTouchGesture) return
		const state = interaction.value
		if (!state) {
			if (opts.editable.value && (opts.activeTool.value === 'select' || opts.activeTool.value === 'eraser')) {
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
			const adj = applyPenTipOffset(e.clientX, e.clientY, e.pointerType, stylusSettings.value)
			const hitEl = typeof document.elementFromPoint === 'function'
				? document.elementFromPoint(adj.x, adj.y)
				: null
			const hitUuid = findAnnotationUuidFromElement(hitEl) ?? findAnnotationUuidFromEvent(e)
			eraseAnnotationByUuid(hitUuid, state.erasedUuids)
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
			if (tool === 'drawing' || tool === 'highlight') {
				for (const ev of getCoalescedPointerEvents(e)) {
					const evPt = clientToNorm(ev.clientX, ev.clientY, ev.pointerType || e.pointerType)
					const last = state.points[state.points.length - 1]
					if (!last || Math.hypot(evPt.x - last.x, evPt.y - last.y) > 0.002) {
						const p = sampleStrokePressure(ev, state)
						state.points.push(p !== undefined ? { ...evPt, p } : evPt)
					}
				}
			}
			const draft = buildDraftFromTool(state.startNorm, pt, state.points)
			if (draft) {
				previewAnnotation.value = { ...draft, uuid: '__draft__', songId: 0, fileId: 0 }
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
			if (opts.activeTool.value === 'text') {
				pendingText.value = {
					mode: 'create',
					x: Math.min(0.85, Math.min(state.startNorm.x, pt.x)),
					y: Math.min(0.92, Math.min(state.startNorm.y, pt.y)),
					width: state.moved ? Math.max(0.15, Math.abs(pt.x - state.startNorm.x)) : 0.25,
					height: state.moved ? Math.max(0.04, Math.abs(pt.y - state.startNorm.y)) : 0.06,
					text: '',
				}
				return
			}

			if (opts.activeTool.value === 'link') {
				pendingLink.value = {
					x: Math.min(0.9, Math.min(state.startNorm.x, pt.x)),
					y: Math.min(0.94, Math.min(state.startNorm.y, pt.y)),
					width: state.moved ? Math.max(0.05, Math.abs(pt.x - state.startNorm.x)) : 0.1,
					height: state.moved ? Math.max(0.04, Math.abs(pt.y - state.startNorm.y)) : 0.06,
					targetPageOneIndexed: Math.min(opts.totalPages.value, opts.pageNumber.value + 1),
					label: '',
				}
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

	function onAnnotationDblClick(ann: SheetAnnotation) {
		if (!opts.editable.value || opts.activeTool.value !== 'select') return
		if (ann.type === 'text') {
			pendingText.value = {
				mode: 'edit',
				x: ann.x,
				y: ann.y,
				width: ann.width || 0.25,
				height: ann.height || 0.06,
				text: ann.data?.text ?? '',
				existing: ann,
			}
		} else if (ann.type === 'link') {
			pendingLink.value = {
				mode: 'edit',
				x: ann.x,
				y: ann.y,
				width: ann.width || 0.1,
				height: ann.height || 0.06,
				targetPageOneIndexed: (ann.data?.targetPage ?? 0) + 1,
				label: ann.data?.label ?? '',
				existing: ann,
			}
		}
	}

	function commitPendingText() {
		const pending = pendingText.value
		if (!pending) return
		pendingText.value = null
		const trimmed = pending.text.trim()
		if (!trimmed) {
			if (pending.mode === 'edit' && pending.existing) {
				opts.onDelete(pending.existing)
			}
			return
		}

		if (pending.mode === 'edit' && pending.existing) {
			opts.onUpdate(pending.existing, {
				...pending.existing,
				data: { ...pending.existing.data, text: trimmed },
			})
			return
		}

		const s = opts.toolStyle.value
		opts.onCreate({
			pageNumber: opts.pageIndex.value,
			layerIndex: 0,
			type: 'text',
			x: roundNorm(pending.x),
			y: roundNorm(pending.y),
			width: roundNorm(pending.width),
			height: roundNorm(pending.height),
			style: {
				color: s.color,
				fontSize: s.fontSize,
				fontFamily: s.fontFamily,
				textAlign: s.textAlign,
				opacity: s.opacity,
			},
			data: { text: trimmed },
		})
	}

	function cancelPendingText() {
		pendingText.value = null
	}

	function commitPendingLink() {
		const pending = pendingLink.value
		if (!pending) return
		pendingLink.value = null
		const targetZeroIndexed = Math.max(0, Math.min(opts.totalPages.value - 1, pending.targetPageOneIndexed - 1))
		const label = pending.label.trim() || `→ p. ${targetZeroIndexed + 1}`

		if (pending.mode === 'edit' && pending.existing) {
			opts.onUpdate(pending.existing, {
				...pending.existing,
				data: { ...pending.existing.data, targetPage: targetZeroIndexed, label },
			})
			return
		}

		opts.onCreate({
			pageNumber: opts.pageIndex.value,
			layerIndex: 0,
			type: 'link',
			x: roundNorm(pending.x),
			y: roundNorm(pending.y),
			width: roundNorm(pending.width),
			height: roundNorm(pending.height),
			style: {
				opacity: 1,
				fontSize: opts.toolStyle.value.fontSize,
			},
			data: { targetPage: targetZeroIndexed, label },
		})
	}

	function cancelPendingLink() {
		pendingLink.value = null
	}

	return {
		previewAnnotation,
		hoveredUuid,
		pendingText,
		pendingLink,
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
