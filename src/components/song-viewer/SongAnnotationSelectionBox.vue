<script setup lang="ts">
import type { SheetAnnotation } from '../../types/annotation'
import { computed } from 'vue'
import { getShapeHeightHandle, VIEWBOX_WIDTH } from '../../utils/annotation'

const props = defineProps<{
	item: SheetAnnotation
	vbHeight: number
}>()

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
			:r="Math.max(14, (Number(item.style?.fontSize) || 40) * 0.6)"
			fill="none"
			stroke="#0082c9"
			stroke-width="1.5"
			stroke-dasharray="4, 3"
			pointer-events="none" />
		<rect
			v-else
			:x="item.x * VIEWBOX_WIDTH - 4"
			:y="item.y * vbHeight - 4"
			:width="Math.max(12, item.width * VIEWBOX_WIDTH + 8)"
			:height="Math.max(12, item.height * vbHeight + 8)"
			fill="none"
			stroke="#0082c9"
			stroke-width="1.5"
			stroke-dasharray="4, 3"
			pointer-events="none" />

		<!-- Endpoint & height handles for 2-point shapes -->
		<template v-if="isTwoPointShape && item.data?.points">
			<circle
				:cx="item.data.points[0].x * VIEWBOX_WIDTH"
				:cy="item.data.points[0].y * vbHeight"
				r="6"
				class="sheetmusic-resize-handle"
				data-resize-handle="p0" />
			<circle
				:cx="item.data.points[1].x * VIEWBOX_WIDTH"
				:cy="item.data.points[1].y * vbHeight"
				r="6"
				class="sheetmusic-resize-handle"
				data-resize-handle="p1" />
			<circle
				v-if="shapeHeightHandle"
				:cx="shapeHeightHandle.x"
				:cy="shapeHeightHandle.y"
				r="6"
				class="sheetmusic-height-handle"
				data-resize-handle="height" />
		</template>

		<!-- Corner resize handles for box annotations -->
		<template v-else-if="item.type !== 'drawing' && item.type !== 'highlight' && item.type !== 'stamp'">
			<rect
				:x="item.x * VIEWBOX_WIDTH - 8"
				:y="item.y * vbHeight - 8"
				width="9"
				height="9"
				class="sheetmusic-resize-handle"
				data-resize-handle="nw" />
			<rect
				:x="(item.x + item.width) * VIEWBOX_WIDTH - 1"
				:y="item.y * vbHeight - 8"
				width="9"
				height="9"
				class="sheetmusic-resize-handle"
				data-resize-handle="ne" />
			<rect
				:x="item.x * VIEWBOX_WIDTH - 8"
				:y="(item.y + item.height) * vbHeight - 1"
				width="9"
				height="9"
				class="sheetmusic-resize-handle"
				data-resize-handle="sw" />
			<rect
				:x="(item.x + item.width) * VIEWBOX_WIDTH - 1"
				:y="(item.y + item.height) * vbHeight - 1"
				width="9"
				height="9"
				class="sheetmusic-resize-handle"
				data-resize-handle="se" />
		</template>
	</g>
</template>
