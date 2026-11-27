<script setup lang="ts">
import type { SheetAnnotation } from '../../types/annotation'
import type { Bookmark } from '../../types/song'
import type { EditorTool, EditorToolStyle } from '../../composables/useAnnotationEditor'
import {
	clampNormalizedCoord,
	getLinkCenter,
	type LinkArrivalBeacon,
	type LinkJumpPayload,
	type LinkTargetPickRequest,
	type LinkTargetPickResult,
} from '../../utils/annotationLinks'
import type { MusicFontId } from '../../utils/musicFont'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { generateSmoothSvgPath, getViewBoxHeight, VIEWBOX_WIDTH } from '../../utils/annotation'
import { createDefaultToolStyle } from '../../utils/annotationToolStyle'
import { adaptAnnotationColorForNight } from '../../utils/performance-settings'
import { useAnnotationPointerInteraction } from '../../composables/useAnnotationPointerInteraction'
import { useStylusSettings } from '../../composables/useStylusSettings'
import SongAnnotationItem from './SongAnnotationItem.vue'
import SongAnnotationSelectionBox from './SongAnnotationSelectionBox.vue'
import SongAnnotationInlinePopovers from './SongAnnotationInlinePopovers.vue'
import SongAnnotationHandwritingPill from './SongAnnotationHandwritingPill.vue'

const props = withDefaults(
	defineProps<{
		annotations?: SheetAnnotation[]
		/** 1-indexed page number from PDF.js */
		pageNumber: number
		visible?: boolean
		pageWidth?: number
		pageHeight?: number
		totalPages?: number
		editable?: boolean
		/** Allow immediate stylus-only drawing (and hardware eraser) without enabling full edit mode. */
		stylusQuickDraw?: boolean
		activeTool?: EditorTool
		toolStyle?: EditorToolStyle
		selectedUuid?: string | null
		selectedUuids?: string[]
		musicFont?: MusicFontId
		bookmarks?: Bookmark[]
		linkBeacon?: LinkArrivalBeacon | null
		pickingLinkTarget?: boolean
		nightMode?: boolean
	}>(),
	{
		annotations: () => [],
		visible: false,
		pageWidth: 595,
		pageHeight: 842,
		totalPages: 1,
		editable: false,
		stylusQuickDraw: false,
		activeTool: 'select',
		toolStyle: createDefaultToolStyle,
		selectedUuid: null,
		selectedUuids: () => [],
		musicFont: 'bravura',
		bookmarks: () => [],
		linkBeacon: null,
		pickingLinkTarget: false,
		nightMode: false,
	},
)

const emit = defineEmits<{
	'jump-to-page': [payload: LinkJumpPayload]
	'start-pick-link-target': [req: LinkTargetPickRequest]
	'complete-pick-link-target': [res: LinkTargetPickResult]
	'open-link-picker': [req: LinkTargetPickRequest]
	select: [uuid: string | null]
	'select-many': [uuids: string[]]
	'create-annotation': [draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> & { uuid?: string }]
	'update-annotation': [before: SheetAnnotation, after: SheetAnnotation]
	'batch-update-annotations': [pairs: Array<{ before: SheetAnnotation; after: SheetAnnotation }>]
	'delete-annotation': [annotation: SheetAnnotation]
}>()

const svgRef = ref<SVGSVGElement | null>(null)
const { isTouchDrawingBlocked } = useStylusSettings()

const vbHeight = computed(() => getViewBoxHeight(props.pageWidth, props.pageHeight))
const pageIndex = computed(() => Math.max(0, props.pageNumber - 1))

const activeAnnotations = computed(() => {
	if (!props.annotations || props.annotations.length === 0) return []
	return props.annotations.filter((a) => !a.deletedAt && a.pageNumber === pageIndex.value)
})

const svgWidthPx = ref<number>(0)
let resizeObserver: ResizeObserver | null = null

function updateSvgWidth() {
	const rect = svgRef.value?.getBoundingClientRect()
	if (rect && rect.width > 0) {
		svgWidthPx.value = rect.width
	}
}

watch(svgRef, (el) => {
	resizeObserver?.disconnect()
	resizeObserver = null
	if (el && typeof ResizeObserver !== 'undefined') {
		updateSvgWidth()
		resizeObserver = new ResizeObserver(() => updateSvgWidth())
		resizeObserver.observe(el)
	}
})

onMounted(() => updateSvgWidth())
onUnmounted(() => {
	resizeObserver?.disconnect()
	resizeObserver = null
})

const uiScale = computed(() => {
	const renderedWidth = svgWidthPx.value > 0 ? svgWidthPx.value : (props.pageWidth || VIEWBOX_WIDTH)
	return VIEWBOX_WIDTH / Math.max(1, renderedWidth)
})

const effectiveSelectedUuids = computed<string[]>(() => {
	if (!props.editable) return []
	if (props.selectedUuids && props.selectedUuids.length > 0) return props.selectedUuids
	return props.selectedUuid ? [props.selectedUuid] : []
})

const selectedItems = computed<SheetAnnotation[]>(() => {
	if (effectiveSelectedUuids.value.length === 0) return []
	const idSet = new Set(effectiveSelectedUuids.value)
	return displayedAnnotations.value.filter((a) => idSet.has(a.uuid))
})

const selectedItem = computed(() => {
	if (selectedItems.value.length !== 1) return null
	return selectedItems.value[0]
})

const {
	previewAnnotation,
	previewAnnotations,
	marqueeBox,
	hoveredUuid,
	pendingText,
	pendingLink,
	handwritingPendingStrokes,
	activeHandwritingStroke,
	handwritingSwapState,
	selectHandwritingCandidate,
	keepHandwritingInk,
	deleteHandwritingPlaced,
	extendHandwritingPillTimeout,
	dismissHandwritingSwapPill,
	onPointerDown,
	onPointerMove,
	onPointerUp,
	onPointerCancel,
	onOverlayClick,
	onAnnotationDblClick,
	commitPendingText,
	cancelPendingText,
	commitPendingLink,
	cancelPendingLink,
} = useAnnotationPointerInteraction({
	svgRef,
	vbHeight,
	uiScale,
	pageIndex,
	pageNumber: computed(() => props.pageNumber),
	totalPages: computed(() => props.totalPages),
	editable: computed(() => props.editable),
	stylusQuickDraw: computed(() => props.stylusQuickDraw),
	activeTool: computed(() => props.activeTool),
	toolStyle: computed(() => props.toolStyle),
	activeAnnotations,
	selectedItem,
	selectedItems,
	onSelect: (uuid) => emit('select', uuid),
	onSelectMany: (uuids) => emit('select-many', uuids),
	onCreate: (draft) => emit('create-annotation', draft),
	onUpdate: (before, after) => emit('update-annotation', before, after),
	onBatchUpdate: (pairs) => emit('batch-update-annotations', pairs),
	onDelete: (ann) => emit('delete-annotation', ann),
})

const displayedAnnotations = computed(() => {
	if (!props.visible && !props.editable) {
		return previewAnnotation.value && previewAnnotation.value.uuid === '__draft__'
			? [previewAnnotation.value]
			: []
	}
	const multiMap = previewAnnotations.value
	const list = activeAnnotations.value.map((ann) => {
		const multiPreview = multiMap.get(ann.uuid)
		if (multiPreview) return multiPreview
		if (previewAnnotation.value && previewAnnotation.value.uuid === ann.uuid) {
			return previewAnnotation.value
		}
		return ann
	})
	if (previewAnnotation.value && previewAnnotation.value.uuid === '__draft__') {
		list.push(previewAnnotation.value)
	}
	return list
})

const selectedLinkTarget = computed(() => {
	if (!props.editable || !props.selectedUuid) return null
	const sel = props.annotations.find(
		(a) => !a.deletedAt && a.uuid === props.selectedUuid && a.type === 'link',
	)
	if (!sel || sel.data?.targetPage !== pageIndex.value) return null
	const tx = clampNormalizedCoord(sel.data?.targetX)
	const ty = clampNormalizedCoord(sel.data?.targetY)
	if (tx === undefined || ty === undefined) return null
	const samePage = sel.pageNumber === pageIndex.value
	const center = samePage ? getLinkCenter(sel) : { x: 0, y: 0 }
	return {
		x: tx,
		y: ty,
		samePage,
		sourceX: center.x,
		sourceY: center.y,
	}
})

const handwritingPreviewPath = computed(() => {
	const strokes = [
		...handwritingPendingStrokes.value,
		...(activeHandwritingStroke.value.length > 0 ? [activeHandwritingStroke.value] : []),
	]
	if (strokes.length === 0) return ''
	return strokes
		.map((s) => generateSmoothSvgPath(s, true, vbHeight.value))
		.filter(Boolean)
		.join(' ')
})

const cursorClass = computed(() => {
	if (props.pickingLinkTarget) return 'cursor-crosshair'
	if (!props.editable) return ''
	switch (props.activeTool) {
		case 'select':
			return 'cursor-select'
		case 'text':
			return 'cursor-text'
		case 'eraser':
			return 'cursor-eraser'
		default:
			return 'cursor-crosshair'
	}
})

function handleOverlayPointerDown(e: PointerEvent) {
	if (props.pickingLinkTarget && svgRef.value) {
		e.preventDefault()
		e.stopPropagation()
		const rect = svgRef.value.getBoundingClientRect()
		if (rect.width > 0 && rect.height > 0) {
			const x = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width))
			const y = Math.min(1, Math.max(0, (e.clientY - rect.top) / rect.height))
			emit('complete-pick-link-target', { pageIndex: pageIndex.value, x, y })
		}
		return
	}
	onPointerDown(e)
}

function buildLinkPickRequest(): LinkTargetPickRequest | null {
	const pending = pendingLink.value
	if (!pending) return null
	pendingLink.value = null
	return {
		mode: pending.mode ?? 'create',
		sourcePageIndex: pageIndex.value,
		x: pending.x,
		y: pending.y,
		width: pending.width,
		height: pending.height,
		label: pending.label,
		linkStyle: pending.linkStyle,
		createReverse: pending.createReverse,
		existing: pending.existing,
	}
}

function handleStartPickLinkTarget() {
	const req = buildLinkPickRequest()
	if (req) emit('start-pick-link-target', req)
}

function handleOpenLinkPicker() {
	const req = buildLinkPickRequest()
	if (req) emit('open-link-picker', req)
}
</script>

<template>
	<div
		v-if="(visible && (displayedAnnotations.length > 0 || editable)) || stylusQuickDraw || (linkBeacon && linkBeacon.pageNumber === pageNumber) || pickingLinkTarget || Boolean(selectedLinkTarget)"
		class="sheetmusic-annotation-layer">
		<svg
			ref="svgRef"
			class="sheetmusic-annotation-overlay"
			:class="[{ 'is-editable': editable || pickingLinkTarget, 'is-stylus-quick-draw': stylusQuickDraw && !editable && !pickingLinkTarget, 'is-stylus-only': editable && !pickingLinkTarget && isTouchDrawingBlocked }, cursorClass]"
			data-test="annotation-overlay"
			:viewBox="`0 0 ${VIEWBOX_WIDTH} ${vbHeight}`"
			preserveAspectRatio="xMidYMid meet"
			@pointerdown="handleOverlayPointerDown"
			@pointermove="onPointerMove"
			@pointerup="onPointerUp"
			@pointercancel="onPointerCancel"
			@click.capture="onOverlayClick"
			@pointerleave="hoveredUuid = null">
			<SongAnnotationItem
				v-for="item in displayedAnnotations"
				:key="item.uuid || item.id"
				:item="item"
				:vb-height="vbHeight"
				:ui-scale="uiScale"
				:music-font="musicFont"
				:editable="editable"
				:night-mode="nightMode"
				:hovered-select="editable && activeTool !== 'eraser' && hoveredUuid === item.uuid"
				:hovered-eraser="editable && activeTool === 'eraser' && hoveredUuid === item.uuid"
				@dblclick-text="onAnnotationDblClick"
				@jump-to-page="emit('jump-to-page', $event)" />

			<path
				v-if="handwritingPreviewPath"
				:d="handwritingPreviewPath"
				:stroke="adaptAnnotationColorForNight(toolStyle.color, nightMode, '#000000')"
				:stroke-width="Math.max(2, toolStyle.strokeWidth || 2.5)"
				opacity="0.72"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
				pointer-events="none"
				data-test="handwriting-ink-preview" />

			<rect
				v-if="marqueeBox"
				:x="marqueeBox.x * VIEWBOX_WIDTH"
				:y="marqueeBox.y * vbHeight"
				:width="marqueeBox.width * VIEWBOX_WIDTH"
				:height="marqueeBox.height * vbHeight"
				fill="rgba(0, 130, 201, 0.12)"
				stroke="#0082c9"
				:stroke-width="1.5 * uiScale"
				:stroke-dasharray="`${4 * uiScale}, ${3 * uiScale}`"
				pointer-events="none"
				data-test="annotation-marquee-box" />

			<SongAnnotationSelectionBox
				v-for="sel in (activeTool !== 'eraser' ? selectedItems : [])"
				:key="`sel-${sel.uuid}`"
				:item="sel"
				:vb-height="vbHeight"
				:ui-scale="uiScale"
				:show-handles="selectedItems.length === 1" />

			<g
				v-if="selectedLinkTarget"
				class="sheetmusic-selected-link-target"
				pointer-events="none"
				data-test="selected-link-target-marker">
				<line
					v-if="selectedLinkTarget.samePage"
					:x1="selectedLinkTarget.sourceX * VIEWBOX_WIDTH"
					:y1="selectedLinkTarget.sourceY * vbHeight"
					:x2="selectedLinkTarget.x * VIEWBOX_WIDTH"
					:y2="selectedLinkTarget.y * vbHeight"
					stroke="#e65100"
					:stroke-width="2 * uiScale"
					:stroke-dasharray="`${6 * uiScale}, ${4 * uiScale}`"
					opacity="0.75" />
				<circle
					:cx="selectedLinkTarget.x * VIEWBOX_WIDTH"
					:cy="selectedLinkTarget.y * vbHeight"
					:r="20 * uiScale"
					class="sheetmusic-selected-link-target-ring" />
				<circle
					:cx="selectedLinkTarget.x * VIEWBOX_WIDTH"
					:cy="selectedLinkTarget.y * vbHeight"
					:r="6 * uiScale"
					class="sheetmusic-selected-link-target-core" />
			</g>

			<g
				v-if="linkBeacon && linkBeacon.pageNumber === pageNumber"
				:key="`beacon-${linkBeacon.token}`"
				class="sheetmusic-link-beacon"
				pointer-events="none"
				data-test="annotation-link-beacon">
				<circle
					:cx="linkBeacon.x * VIEWBOX_WIDTH"
					:cy="linkBeacon.y * vbHeight"
					:r="34 * uiScale"
					class="sheetmusic-link-beacon-ring" />
				<circle
					:cx="linkBeacon.x * VIEWBOX_WIDTH"
					:cy="linkBeacon.y * vbHeight"
					:r="10 * uiScale"
					class="sheetmusic-link-beacon-core" />
			</g>
		</svg>

		<SongAnnotationHandwritingPill
			:swap-state="handwritingSwapState"
			:music-font="musicFont"
			@select="selectHandwritingCandidate"
			@keep-ink="keepHandwritingInk"
			@delete="deleteHandwritingPlaced"
			@extend-timeout="extendHandwritingPillTimeout"
			@dismiss="dismissHandwritingSwapPill" />

		<SongAnnotationInlinePopovers
			:pending-text="pendingText"
			:pending-link="pendingLink"
			:total-pages="totalPages"
			:bookmarks="bookmarks"
			@update:pending-text-text="pendingText && (pendingText.text = $event)"
			@update:pending-link-page="pendingLink && (pendingLink.targetPageOneIndexed = $event)"
			@update:pending-link-label="pendingLink && (pendingLink.label = $event)"
			@update:pending-link-style="pendingLink && (pendingLink.linkStyle = $event)"
			@update:pending-link-reverse="pendingLink && (pendingLink.createReverse = $event)"
			@pick-link-target="handleStartPickLinkTarget"
			@open-link-picker="handleOpenLinkPicker"
			@commit-text="commitPendingText"
			@cancel-text="cancelPendingText"
			@commit-link="commitPendingLink"
			@cancel-link="cancelPendingLink" />
	</div>
</template>

<style scoped>
.sheetmusic-annotation-layer {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	pointer-events: none;
	z-index: 2;
}

.sheetmusic-annotation-overlay {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	pointer-events: none;
	user-select: none;
}

.sheetmusic-annotation-overlay.is-editable {
	pointer-events: auto;
	touch-action: none;
}

.sheetmusic-annotation-overlay.is-stylus-quick-draw {
	pointer-events: auto;
	touch-action: pan-x pan-y pinch-zoom;
}

.cursor-crosshair {
	cursor: crosshair;
}

.cursor-select {
	cursor: default;
}

.cursor-text {
	cursor: text;
}

.cursor-eraser {
	cursor: not-allowed;
}

:deep(.is-hovered-select) {
	filter: drop-shadow(0 0 3px rgba(0, 130, 201, 0.85));
	cursor: grab;
}

:deep(.is-hovered-eraser) {
	filter: drop-shadow(0 0 4px rgba(229, 57, 53, 0.9));
	opacity: 0.72;
	cursor: pointer;
}

:deep(.sheetmusic-annotation-link) {
	pointer-events: auto;
	cursor: pointer;
	fill: rgba(0, 120, 255, 0.18);
	stroke: rgba(0, 120, 255, 0.65);
	stroke-width: 1.5;
	transition: fill 0.15s ease-in-out;
}

:deep(.sheetmusic-annotation-link:hover) {
	fill: rgba(0, 120, 255, 0.32);
}

:deep(.sheetmusic-annotation-link-circle) {
	stroke-width: 2.2;
}

:deep(.sheetmusic-annotation-link-shimmer) {
	animation: sheetmusic-link-shimmer 0.75s ease-in-out 2;
}

@keyframes sheetmusic-link-shimmer {
	0%,
	100% {
		opacity: 1;
	}

	50% {
		opacity: 0.4;
		filter: drop-shadow(0 0 6px rgba(0, 130, 201, 0.75));
	}
}

:deep(.sheetmusic-resize-handle) {
	fill: #ffffff;
	stroke: #0082c9;
	cursor: pointer;
	pointer-events: auto;
}

:deep(.sheetmusic-height-handle) {
	fill: #ffeb3b;
	stroke: #0082c9;
	cursor: ns-resize;
	pointer-events: auto;
}

.sheetmusic-selected-link-target-ring {
	fill: rgba(230, 81, 0, 0.18);
	stroke: #e65100;
	stroke-width: 2.5;
}

.sheetmusic-selected-link-target-core {
	fill: #e65100;
}

.sheetmusic-link-beacon-ring {
	fill: rgba(0, 130, 201, 0.22);
	stroke: #0082c9;
	stroke-width: 3;
	animation: sheetmusic-beacon-pulse 1.1s ease-out 2;
	transform-box: fill-box;
	transform-origin: center;
}

.sheetmusic-link-beacon-core {
	fill: #0082c9;
	opacity: 0.85;
}

@keyframes sheetmusic-beacon-pulse {
	0% {
		transform: scale(0.55);
		opacity: 0.95;
	}

	100% {
		transform: scale(1.65);
		opacity: 0;
	}
}
</style>
