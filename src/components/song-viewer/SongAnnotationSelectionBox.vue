<script setup lang="ts">
import type { SheetAnnotation } from '../../types/annotation'
import { computed } from 'vue'
import { getShapeHeightHandle, getStampInkBounds, VIEWBOX_WIDTH } from '../../utils/annotation'
import { getLinkCircleGeometry } from '../../utils/annotationLinks'

const props = withDefaults(defineProps<{
	item: SheetAnnotation
	vbHeight: number
	uiScale?: number
	showHandles?: boolean
}>(), {
	uiScale: 1,
	showHandles: true,
})

const scale = computed(() => Math.max(0.1, props.uiScale))
const boxStrokeWidth = computed(() => 1.5 * scale.value)
const boxDashArray = computed(() => `${4 * scale.value}, ${3 * scale.value}`)
const boxPad = computed(() => 2 * scale.value)
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
const stampBounds = computed(() =>
	props.item.type === 'stamp' ? getStampInkBounds(props.item, props.vbHeight, scale.value) : null,
)
const isLinkCircle = computed(() =>
	props.item.type === 'link' && props.item.data?.linkStyle === 'circle',
)
const isLinkBox = computed(() =>
	props.item.type === 'link' && props.item.data?.linkStyle !== 'circle',
)
const linkCircle = computed(() =>
	isLinkCircle.value ? getLinkCircleGeometry(props.item, props.vbHeight) : null,
)
const linkCircleDiag = computed(() => (linkCircle.value ? linkCircle.value.r * Math.SQRT1_2 : 0))
</script>

<template>
	<g class="sheetmusic-selection-box">
		<!-- Circle link: hug the actual circle directly (no dashed box) -->
		<circle
			v-if="linkCircle"
			:cx="linkCircle.cx"
			:cy="linkCircle.cy"
			:r="linkCircle.r"
			fill="none"
			stroke="#0082c9"
			:stroke-width="2.5 * scale"
			pointer-events="none"
			data-test="selection-link-circle" />
		<!-- Box link: highlight the actual rounded box directly (no dashed box) -->
		<rect
			v-else-if="isLinkBox"
			:x="item.x * VIEWBOX_WIDTH"
			:y="item.y * vbHeight"
			:width="item.width * VIEWBOX_WIDTH"
			:height="item.height * vbHeight"
			rx="4"
			fill="none"
			stroke="#0082c9"
			:stroke-width="2.5 * scale"
			pointer-events="none"
			data-test="selection-link-box" />
		<rect
			v-else-if="item.type === 'stamp' && stampBounds"
			:x="stampBounds.x"
			:y="stampBounds.y"
			:width="stampBounds.width"
			:height="stampBounds.height"
			rx="2"
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
		<template v-if="showHandles && isTwoPointShape && item.data?.points">
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

		<!-- Perimeter resize handles for circle links -->
		<template v-else-if="showHandles && linkCircle">
			<circle
				:cx="linkCircle.cx - linkCircleDiag"
				:cy="linkCircle.cy - linkCircleDiag"
				:r="handleSize / 2"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="nw" />
			<circle
				:cx="linkCircle.cx + linkCircleDiag"
				:cy="linkCircle.cy - linkCircleDiag"
				:r="handleSize / 2"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="ne" />
			<circle
				:cx="linkCircle.cx - linkCircleDiag"
				:cy="linkCircle.cy + linkCircleDiag"
				:r="handleSize / 2"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="sw" />
			<circle
				:cx="linkCircle.cx + linkCircleDiag"
				:cy="linkCircle.cy + linkCircleDiag"
				:r="handleSize / 2"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="se" />
		</template>

		<!-- Corner resize handles for box links (directly on actual box corners) -->
		<template v-else-if="showHandles && isLinkBox">
			<rect
				:x="item.x * VIEWBOX_WIDTH - handleSize / 2"
				:y="item.y * vbHeight - handleSize / 2"
				:width="handleSize"
				:height="handleSize"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="nw" />
			<rect
				:x="(item.x + item.width) * VIEWBOX_WIDTH - handleSize / 2"
				:y="item.y * vbHeight - handleSize / 2"
				:width="handleSize"
				:height="handleSize"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="ne" />
			<rect
				:x="item.x * VIEWBOX_WIDTH - handleSize / 2"
				:y="(item.y + item.height) * vbHeight - handleSize / 2"
				:width="handleSize"
				:height="handleSize"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="sw" />
			<rect
				:x="(item.x + item.width) * VIEWBOX_WIDTH - handleSize / 2"
				:y="(item.y + item.height) * vbHeight - handleSize / 2"
				:width="handleSize"
				:height="handleSize"
				:stroke-width="handleStrokeWidth"
				class="sheetmusic-resize-handle"
				data-resize-handle="se" />
		</template>

		<!-- Corner resize handles for other box annotations -->
		<template v-else-if="showHandles && item.type !== 'drawing' && item.type !== 'highlight' && item.type !== 'stamp'">
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
