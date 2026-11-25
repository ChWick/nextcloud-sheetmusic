<script setup lang="ts">
import type { SheetAnnotation } from '../../types/annotation'
import { computed } from 'vue'
import {
	getAnnotationDashArray,
	getAnnotationDisplaySymbol,
	getAnnotationPath,
	getAnnotationPressurePath,
	getAnnotationTextLayout,
	getStampRenderFontSize,
	getStampRenderPosition,
	getStampSelectionRadius,
	hasPressurePoints,
	isPathShape,
	VIEWBOX_WIDTH,
} from '../../utils/annotation'
import { getMusicFontFamily, type MusicFontId } from '../../utils/musicFont'

const props = withDefaults(defineProps<{
	item: SheetAnnotation
	vbHeight: number
	uiScale?: number
	musicFont?: MusicFontId
	editable: boolean
	hoveredSelect: boolean
	hoveredEraser: boolean
}>(), {
	uiScale: 1,
	musicFont: 'bravura',
})

const emit = defineEmits<{
	'jump-to-page': [pageNumber: number]
	'dblclick-text': [item: SheetAnnotation]
}>()

const isPathItem = computed(() =>
	props.item.type === 'drawing'
	|| (props.item.type === 'highlight' && !props.item.data?.rect)
	|| isPathShape(props.item),
)
const usePressurePath = computed(() => isPathItem.value && hasPressurePoints(props.item))
const pathD = computed(() => (isPathItem.value ? getAnnotationPath(props.item, props.vbHeight) : ''))
const pressurePathD = computed(() => (usePressurePath.value ? getAnnotationPressurePath(props.item, props.vbHeight) : ''))
const dashArray = computed(() => getAnnotationDashArray(props.item))

const stampPos = computed(() =>
	props.item.type === 'stamp' ? getStampRenderPosition(props.item, props.vbHeight) : null,
)
const stampCenterX = computed(() =>
	Math.round((props.item.x + (props.item.width || 0) / 2) * VIEWBOX_WIDTH * 10) / 10,
)
const stampCenterY = computed(() =>
	Math.round((props.item.y + (props.item.height || 0) / 2) * props.vbHeight * 10) / 10,
)
const stampRadius = computed(() =>
	props.item.type === 'stamp' ? getStampSelectionRadius(props.item, props.uiScale) : 0,
)
const stampFontSize = computed(() => getStampRenderFontSize(props.item))
const stampSymbol = computed(() => getAnnotationDisplaySymbol(props.item))
const musicFontFamily = computed(() => getMusicFontFamily(props.musicFont))

const textLayout = computed(() =>
	props.item.type === 'text' && props.item.data?.text
		? getAnnotationTextLayout(props.item, props.vbHeight)
		: null,
)
</script>

<template>
	<g
		:data-ann-uuid="item.uuid"
		:class="{
			'is-hovered-select': hoveredSelect,
			'is-hovered-eraser': hoveredEraser,
		}">
		<!-- Freehand drawing, highlight (path), or path-based shape (line, arrow, hairpin, slur, bracket) -->
		<template v-if="isPathItem">
			<path
				v-if="usePressurePath"
				:d="pressurePathD"
				:fill="item.style?.stroke || '#e53935'"
				stroke="none"
				:opacity="item.style?.opacity ?? 1.0"
				:style="{ mixBlendMode: item.style?.blendMode || 'normal' }"
				:data-ann-uuid="item.uuid"
				data-test="annotation-drawing"
				data-pressure-stroke="true" />
			<path
				v-else
				:d="pathD"
				:stroke="item.style?.stroke || '#e53935'"
				:stroke-width="item.style?.strokeWidth || 2.5"
				:stroke-dasharray="dashArray"
				:opacity="item.style?.opacity ?? 1.0"
				:style="{ mixBlendMode: item.style?.blendMode || (item.type === 'highlight' ? 'multiply' : 'normal') }"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
				:data-ann-uuid="item.uuid"
				:data-test="item.type === 'shape' ? 'annotation-shape' : 'annotation-drawing'" />
			<path
				v-if="editable"
				:d="pathD"
				stroke="transparent"
				stroke-width="14"
				vector-effect="non-scaling-stroke"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
				pointer-events="stroke"
				:data-ann-uuid="item.uuid"
				data-test="annotation-hit-target" />
		</template>

		<!-- Rectangular highlight -->
		<rect
			v-else-if="item.type === 'highlight' && item.data?.rect"
			:x="item.x * VIEWBOX_WIDTH"
			:y="item.y * vbHeight"
			:width="item.width * VIEWBOX_WIDTH"
			:height="item.height * vbHeight"
			:fill="item.style?.stroke || '#ffeb3b'"
			:opacity="item.style?.opacity ?? 0.4"
			:style="{ mixBlendMode: item.style?.blendMode || 'multiply' }"
			:data-ann-uuid="item.uuid"
			data-test="annotation-drawing" />

		<!-- Shape: Ellipse -->
		<template v-else-if="item.type === 'shape' && item.data?.shapeType === 'ellipse'">
			<ellipse
				:cx="(item.x + item.width / 2) * VIEWBOX_WIDTH"
				:cy="(item.y + item.height / 2) * vbHeight"
				:rx="Math.max(1, (item.width / 2) * VIEWBOX_WIDTH)"
				:ry="Math.max(1, (item.height / 2) * vbHeight)"
				:stroke="item.style?.stroke || '#e53935'"
				:stroke-width="item.style?.strokeWidth || 2.5"
				:stroke-dasharray="dashArray"
				:opacity="item.style?.opacity ?? 1.0"
				:fill="item.style?.fill || 'transparent'"
				:data-ann-uuid="item.uuid"
				data-test="annotation-shape" />
			<ellipse
				v-if="editable"
				:cx="(item.x + item.width / 2) * VIEWBOX_WIDTH"
				:cy="(item.y + item.height / 2) * vbHeight"
				:rx="Math.max(1, (item.width / 2) * VIEWBOX_WIDTH)"
				:ry="Math.max(1, (item.height / 2) * vbHeight)"
				stroke="transparent"
				stroke-width="14"
				vector-effect="non-scaling-stroke"
				fill="none"
				pointer-events="stroke"
				:data-ann-uuid="item.uuid"
				data-test="annotation-hit-target" />
		</template>

		<!-- Shape: Rectangle -->
		<template v-else-if="item.type === 'shape'">
			<rect
				:x="item.x * VIEWBOX_WIDTH"
				:y="item.y * vbHeight"
				:width="item.width * VIEWBOX_WIDTH"
				:height="item.height * vbHeight"
				:stroke="item.style?.stroke || '#e53935'"
				:stroke-width="item.style?.strokeWidth || 2.5"
				:stroke-dasharray="dashArray"
				:opacity="item.style?.opacity ?? 1.0"
				:fill="item.style?.fill || 'transparent'"
				:data-ann-uuid="item.uuid"
				data-test="annotation-shape" />
			<rect
				v-if="editable"
				:x="item.x * VIEWBOX_WIDTH"
				:y="item.y * vbHeight"
				:width="item.width * VIEWBOX_WIDTH"
				:height="item.height * vbHeight"
				stroke="transparent"
				stroke-width="14"
				vector-effect="non-scaling-stroke"
				fill="none"
				pointer-events="stroke"
				:data-ann-uuid="item.uuid"
				data-test="annotation-hit-target" />
		</template>

		<!-- Stamp Annotation: SMuFL music glyph centered at (x, y) with fontSize -->
		<template v-else-if="item.type === 'stamp' && stampPos">
			<circle
				v-if="editable"
				:cx="stampCenterX"
				:cy="stampCenterY"
				:r="stampRadius"
				fill="transparent"
				:data-ann-uuid="item.uuid"
				data-test="annotation-hit-target" />
			<text
				:x="stampPos.x"
				:y="stampPos.y"
				:fill="item.style?.color || '#000000'"
				:opacity="item.style?.opacity ?? 1.0"
				:font-size="stampFontSize"
				text-anchor="middle"
				:dominant-baseline="stampPos.dominantBaseline"
				:font-family="musicFontFamily"
				pointer-events="none"
				:data-ann-uuid="item.uuid"
				data-test="annotation-stamp">
				{{ stampSymbol }}
			</text>
		</template>

		<!-- Text Annotation -->
		<g
			v-else-if="textLayout"
			:data-ann-uuid="item.uuid"
			@dblclick.stop="emit('dblclick-text', item)">
			<rect
				v-if="editable || (item.style?.backgroundColor && item.style.backgroundColor !== 'transparent') || item.style?.borderColor"
				:x="textLayout.boxX"
				:y="textLayout.boxY"
				:width="textLayout.boxWidth"
				:height="textLayout.boxHeight"
				:fill="item.style?.backgroundColor || 'transparent'"
				:stroke="item.style?.borderColor || 'transparent'"
				:stroke-width="item.style?.borderColor ? 1.5 : 10"
				:vector-effect="item.style?.borderColor ? undefined : 'non-scaling-stroke'"
				rx="4"
				:data-ann-uuid="item.uuid" />
			<text
				:x="textLayout.x"
				:y="textLayout.y"
				:fill="item.style?.color || '#000000'"
				:opacity="item.style?.opacity ?? 1.0"
				:font-size="textLayout.fontSize"
				:font-family="textLayout.fontFamily"
				:font-weight="item.style?.fontWeight || 'normal'"
				:font-style="item.style?.fontStyle || 'normal'"
				:text-anchor="textLayout.textAnchor"
				:data-ann-uuid="item.uuid"
				data-test="annotation-text">
				<tspan
					v-for="(line, lineIdx) in textLayout.lines"
					:key="lineIdx"
					:x="textLayout.x"
					:dy="lineIdx === 0 ? 0 : textLayout.lineHeight">
					{{ line }}
				</tspan>
			</text>
		</g>

		<!-- Navigation jump link -->
		<g
			v-else-if="item.type === 'link'"
			:data-ann-uuid="item.uuid"
			class="sheetmusic-annotation-link-group"
			@click="!editable && ($event.stopPropagation(), emit('jump-to-page', item.data?.targetPage ?? 0))"
			@dblclick="editable && emit('dblclick-text', item)">
			<rect
				:x="item.x * VIEWBOX_WIDTH"
				:y="item.y * vbHeight"
				:width="item.width * VIEWBOX_WIDTH"
				:height="item.height * vbHeight"
				rx="4"
				class="sheetmusic-annotation-link"
				:data-ann-uuid="item.uuid"
				data-test="annotation-link" />
			<text
				v-if="item.data?.label"
				:x="(item.x + item.width / 2) * VIEWBOX_WIDTH"
				:y="(item.y + item.height / 2) * vbHeight"
				text-anchor="middle"
				dominant-baseline="central"
				:font-size="item.style?.fontSize || 14"
				font-weight="600"
				fill="#0052cc"
				:data-ann-uuid="item.uuid">
				{{ item.data.label }}
			</text>
		</g>
	</g>
</template>
