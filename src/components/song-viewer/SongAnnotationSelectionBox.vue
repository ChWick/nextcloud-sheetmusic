<script setup lang="ts">
import type { SheetAnnotation } from '../../types/annotation'
import { computed } from 'vue'
import { getShapeHeightHandle, getStampRenderFontSize, VIEWBOX_WIDTH } from '../../utils/annotation'

const props = withDefaults(defineProps<{
	item: SheetAnnotation
	vbHeight: number
	uiScale?: number
}>(), {
	uiScale: 1,
})

const scale = computed(() => Math.max(0.1, props.uiScale))
const boxStrokeWidth = computed(() => 1.5 * scale.value)
const boxDashArray = computed(() => `${4 * scale.value}, ${3 * scale.value}`)
const boxPad = computed(() => 4 * scale.value)
const handleRadius = computed(() => 6 * scale.value)
const handleSize = computed(() => 9 * scale.value)
const handleStrokeWidth = computed(() => 2 * scale.value)

const isTwoPointShape = computed(() => {
	if (props.item.type !== 'shape') return false
	const st = props.item.data?.shapeType
	return (
		(st === 'line' || st === 'arrow' || st === 'crescendo' || st === 'decrescendo' || st === 'slur' || st === 'bracket') &&
		Array.isArray(props.item.data?.points) &&
		props.item.data.points.length >= 2
	)
})

const shapeHeightHandle = computed(() => getShapeHeightHandle(props.item, props.vbHeight))
</script>

<template>
	<g class="sheetmusic-selection-box">
		<circle
			v-if="item.type === 'stamp'"
			:cx="Math.round((item.x + (item.width || 0) / 2) * VIEWBOX_WIDTH * 10) / 10"
			:cy="Math.round((item.y + (item.height || 0) / 2) * vbHeight * 10) / 10"
			:r="Math.max(14 * scale, getStampRenderFontSize(item) * 0.6 + boxPad)"
			fill="none"
			stroke="#0082c9"
			:stroke-width="boxStrokeWidth"
			:stroke-dasharray="boxDashArray"
			pointer-events="none" />
		<rect
			v-else
			:x="item.x * VIEWBOX_WIDTH - boxPad"
			:y="item.y * vbHeight - boxPad"
			:width="Math.max(12 * scale, item.width * VIEWBOX_WIDTH + boxPad * 2)"
			:height="Math.max(12 * scale, item.height * vbHeight + boxPad * 2)"
			fill="none"
			stroke="#0082c9"
			:stroke-width="boxStrokeWidth"
			:stroke-dasharray="boxDashArray"
			pointer-events="none" />

		<!-- Endpoint & height handles for 2-point shapes -->
		<template v-if="isTwoPointShape && item.data?.points">
			<circle
				:cx="item.data.points[0].x * VIEWBOX_WIDTH"
				:cy="item.data.points[0].y * vbHeight"
				:r="handleRadius"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="p0" />
			<circle
				:cx="item.data.points[1].x * VIEWBOX_WIDTH"
				:cy="item.data.points[1].y * vbHeight"
				:r="handleRadius"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="p1" />
			<circle
				v-if="shapeHeightHandle"
				:cx="shapeHeightHandle.x"
				:cy="shapeHeightHandle.y"
				:r="handleRadius"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-height-handle"
				data-resize-handle="height" />
		</template>

		<!-- Corner resize handles for box annotations -->
		<template v-else-if="item.type !== 'drawing' && item.type !== 'highlight' && item.type !== 'stamp'">
			<rect
				:x="item.x * VIEWBOX_WIDTH - boxPad - handleSize / 2"
				:y="item.y * vbHeight - boxPad - handleSize / 2"
				:width="handleSize"
				:height="handleSize"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="nw" />
			<rect
				:x="(item.x + item.width) * VIEWBOX_WIDTH + boxPad - handleSize / 2"
				:y="item.y * vbHeight - boxPad - handleSize / 2"
				:width="handleSize"
				:height="handleSize"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="ne" />
			<rect
				:x="item.x * VIEWBOX_WIDTH - boxPad - handleSize / 2"
				:y="(item.y + item.height) * vbHeight + boxPad - handleSize / 2"
				:width="handleSize"
				:height="handleSize"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="sw" />
			<rect
				:x="(item.x + item.width) * VIEWBOX_WIDTH + boxPad - handleSize / 2"
				:y="(item.y + item.height) * vbHeight + boxPad - handleSize / 2"
				:width="handleSize"
				:height="handleSize"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="se" />
		</template>
	</g>
</template>
