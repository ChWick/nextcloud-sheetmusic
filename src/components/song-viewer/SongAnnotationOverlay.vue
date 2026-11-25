<script setup lang="ts">
import type { SheetAnnotation } from '../../types/annotation'
import type { EditorTool, EditorToolStyle } from '../../composables/useAnnotationEditor'
import type { MusicFontId } from '../../utils/musicFont'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { generateSmoothSvgPath, getViewBoxHeight, VIEWBOX_WIDTH } from '../../utils/annotation'
import { createDefaultToolStyle } from '../../utils/annotationToolStyle'
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
		activeTool?: EditorTool
		toolStyle?: EditorToolStyle
		selectedUuid?: string | null
		musicFont?: MusicFontId
	}>(),
	{
		annotations: () => [],
		visible: false,
		pageWidth: 595,
		pageHeight: 842,
		totalPages: 1,
		editable: false,
		activeTool: 'select',
		toolStyle: createDefaultToolStyle,
		selectedUuid: null,
		musicFont: 'bravura',
	},
)

const emit = defineEmits<{
	'jump-to-page': [pageNumber: number]
	select: [uuid: string | null]
	'create-annotation': [draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> & { uuid?: string }]
	'update-annotation': [before: SheetAnnotation, after: SheetAnnotation]
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

const selectedItem = computed(() => {
	if (!props.editable || !props.selectedUuid) return null
	return displayedAnnotations.value.find((a) => a.uuid === props.selectedUuid) ?? null
})

const {
	previewAnnotation,
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
	activeTool: computed(() => props.activeTool),
	toolStyle: computed(() => props.toolStyle),
	activeAnnotations,
	selectedItem,
	onSelect: (uuid) => emit('select', uuid),
	onCreate: (draft) => emit('create-annotation', draft),
	onUpdate: (before, after) => emit('update-annotation', before, after),
	onDelete: (ann) => emit('delete-annotation', ann),
})

const displayedAnnotations = computed(() => {
	const list = activeAnnotations.value.map((ann) =>
		previewAnnotation.value && previewAnnotation.value.uuid === ann.uuid
			? previewAnnotation.value
			: ann,
	)
	if (previewAnnotation.value && previewAnnotation.value.uuid === '__draft__') {
		list.push(previewAnnotation.value)
	}
	return list
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
</script>

<template>
	<div
		v-if="visible && (displayedAnnotations.length > 0 || editable)"
		class="sheetmusic-annotation-layer">
		<svg
			ref="svgRef"
			class="sheetmusic-annotation-overlay"
			:class="[{ 'is-editable': editable, 'is-stylus-only': editable && isTouchDrawingBlocked }, cursorClass]"
			data-test="annotation-overlay"
			:viewBox="`0 0 ${VIEWBOX_WIDTH} ${vbHeight}`"
			preserveAspectRatio="xMidYMid meet"
			@pointerdown="onPointerDown"
			@pointermove="onPointerMove"
			@pointerup="onPointerUp"
			@pointercancel="onPointerCancel"
			@pointerleave="hoveredUuid = null">
			<SongAnnotationItem
				v-for="item in displayedAnnotations"
				:key="item.uuid || item.id"
				:item="item"
				:vb-height="vbHeight"
				:ui-scale="uiScale"
				:music-font="musicFont"
				:editable="editable"
				:hovered-select="editable && activeTool !== 'eraser' && hoveredUuid === item.uuid"
				:hovered-eraser="editable && activeTool === 'eraser' && hoveredUuid === item.uuid"
				@dblclick-text="onAnnotationDblClick"
				@jump-to-page="emit('jump-to-page', $event)" />

			<path
				v-if="handwritingPreviewPath"
				:d="handwritingPreviewPath"
				:stroke="toolStyle.color || '#000000'"
				:stroke-width="Math.max(2, toolStyle.strokeWidth || 2.5)"
				opacity="0.72"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
				pointer-events="none"
				data-test="handwriting-ink-preview" />

			<SongAnnotationSelectionBox
				v-if="selectedItem && activeTool !== 'eraser'"
				:item="selectedItem"
				:vb-height="vbHeight"
				:ui-scale="uiScale" />
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
			@update:pending-text-text="pendingText && (pendingText.text = $event)"
			@update:pending-link-page="pendingLink && (pendingLink.targetPageOneIndexed = $event)"
			@update:pending-link-label="pendingLink && (pendingLink.label = $event)"
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
</style>
