<script setup lang="ts">
import type { SheetAnnotation } from '../../types/annotation'
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
import { MUSIC_FONT_FAMILY } from '../../utils/musicSymbols'

withDefaults(defineProps<{
	item: SheetAnnotation
	vbHeight: number
	uiScale?: number
	editable: boolean
	hoveredSelect: boolean
	hoveredEraser: boolean
}>(), {
	uiScale: 1,
})

const emit = defineEmits<{
	'jump-to-page': [pageNumber: number]
	'dblclick-text': [item: SheetAnnotation]
}>()
</script>

<template>
	<g
		:data-ann-uuid="item.uuid"
		:class="{
			'is-hovered-select': hoveredSelect,
			'is-hovered-eraser': hoveredEraser,
		}">
		<!-- Freehand drawing, highlight (path), or path-based shape (line, arrow, hairpin, slur, bracket) -->
		<template v-if="item.type === 'drawing' || (item.type === 'highlight' && !item.data?.rect) || isPathShape(item)">
			<path
				v-if="hasPressurePoints(item)"
				:d="getAnnotationPressurePath(item, vbHeight)"
				:fill="item.style?.stroke || '#e53935'"
				stroke="none"
				:opacity="item.style?.opacity ?? 1.0"
				:style="{ mixBlendMode: item.style?.blendMode || 'normal' }"
				:data-ann-uuid="item.uuid"
				data-test="annotation-drawing"
				data-pressure-stroke="true" />
			<path
				v-else
				:d="getAnnotationPath(item, vbHeight)"
				:stroke="item.style?.stroke || '#e53935'"
				:stroke-width="item.style?.strokeWidth || 2.5"
				:stroke-dasharray="getAnnotationDashArray(item)"
				:opacity="item.style?.opacity ?? 1.0"
				:style="{ mixBlendMode: item.style?.blendMode || (item.type === 'highlight' ? 'multiply' : 'normal') }"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
				:data-ann-uuid="item.uuid"
				:data-test="item.type === 'shape' ? 'annotation-shape' : 'annotation-drawing'" />
			<path
				v-if="editable"
				:d="getAnnotationPath(item, vbHeight)"
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
				:stroke-dasharray="getAnnotationDashArray(item)"
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
				:stroke-dasharray="getAnnotationDashArray(item)"
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
		<template v-else-if="item.type === 'stamp'">
			<circle
				v-if="editable"
				:cx="Math.round((item.x + (item.width || 0) / 2) * VIEWBOX_WIDTH * 10) / 10"
				:cy="Math.round((item.y + (item.height || 0) / 2) * vbHeight * 10) / 10"
				:r="getStampSelectionRadius(item, uiScale)"
				fill="transparent"
				:data-ann-uuid="item.uuid"
				data-test="annotation-hit-target" />
			<text
				:x="getStampRenderPosition(item, vbHeight).x"
				:y="getStampRenderPosition(item, vbHeight).y"
				:fill="item.style?.color || '#000000'"
				:opacity="item.style?.opacity ?? 1.0"
				:font-size="getStampRenderFontSize(item)"
				text-anchor="middle"
				:dominant-baseline="getStampRenderPosition(item, vbHeight).dominantBaseline"
				:font-family="MUSIC_FONT_FAMILY"
				pointer-events="none"
				:data-ann-uuid="item.uuid"
				data-test="annotation-stamp">
				{{ getAnnotationDisplaySymbol(item) }}
			</text>
		</template>

		<!-- Text Annotation -->
		<g
			v-else-if="item.type === 'text' && item.data?.text"
			:data-ann-uuid="item.uuid"
			@dblclick.stop="emit('dblclick-text', item)">
			<rect
				v-if="editable || (item.style?.backgroundColor && item.style.backgroundColor !== 'transparent') || item.style?.borderColor"
				:x="getAnnotationTextLayout(item, vbHeight).boxX"
				:y="getAnnotationTextLayout(item, vbHeight).boxY"
				:width="getAnnotationTextLayout(item, vbHeight).boxWidth"
				:height="getAnnotationTextLayout(item, vbHeight).boxHeight"
				:fill="item.style?.backgroundColor || 'transparent'"
				:stroke="item.style?.borderColor || 'transparent'"
				:stroke-width="item.style?.borderColor ? 1.5 : 10"
				:vector-effect="item.style?.borderColor ? undefined : 'non-scaling-stroke'"
				rx="4"
				:data-ann-uuid="item.uuid" />
			<text
				:x="getAnnotationTextLayout(item, vbHeight).x"
				:y="getAnnotationTextLayout(item, vbHeight).y"
				:fill="item.style?.color || '#000000'"
				:opacity="item.style?.opacity ?? 1.0"
				:font-size="getAnnotationTextLayout(item, vbHeight).fontSize"
				:font-family="getAnnotationTextLayout(item, vbHeight).fontFamily"
				:font-weight="item.style?.fontWeight || 'normal'"
				:font-style="item.style?.fontStyle || 'normal'"
				:text-anchor="getAnnotationTextLayout(item, vbHeight).textAnchor"
				:data-ann-uuid="item.uuid"
				data-test="annotation-text">
				<tspan
					v-for="(line, lineIdx) in getAnnotationTextLayout(item, vbHeight).lines"
					:key="lineIdx"
					:x="getAnnotationTextLayout(item, vbHeight).x"
					:dy="lineIdx === 0 ? 0 : getAnnotationTextLayout(item, vbHeight).lineHeight">
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
