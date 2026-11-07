<script setup lang="ts">
import type { SheetAnnotation } from '../../types/annotation'
import { computed } from 'vue'
import { generateSmoothSvgPath, getAnnotationDashArray } from '../../utils/annotation'

const props = withDefaults(
	defineProps<{
		annotations?: SheetAnnotation[]
		pageNumber: number // 1-indexed page from PDF.js or 0-indexed
		visible?: boolean
	}>(),
	{
		annotations: () => [],
		visible: true,
	},
)

const emit = defineEmits<{
	'jump-to-page': [pageNumber: number]
}>()

const activeAnnotations = computed(() => {
	if (!props.annotations || props.annotations.length === 0) {
		return []
	}
	return props.annotations.filter((a) => {
		if (a.deletedAt) return false
		// Match either 1-indexed or 0-indexed page number
		return a.pageNumber === props.pageNumber || a.pageNumber === props.pageNumber - 1
	})
})

function getAnnotationPath(item: SheetAnnotation): string {
	const points = item.data?.points
	if (points && points.length > 0) {
		const isSmooth = item.data?.smoothMode !== false && item.data?.drawMode !== 1
		return generateSmoothSvgPath(points, isSmooth)
	}
	return item.data?.path || ''
}
</script>

<template>
	<svg
		v-if="visible && activeAnnotations.length > 0"
		class="sheetmusic-annotation-overlay"
		data-test="annotation-overlay"
		viewBox="0 0 1000 1000"
		preserveAspectRatio="none">
		<template v-for="item in activeAnnotations" :key="item.uuid || item.id">
			<!-- Freehand drawing, highlight, or path-based shape/line -->
			<path
				v-if="(item.type === 'drawing' || item.type === 'highlight' || (item.type === 'shape' && (item.data?.path || item.data?.points)))"
				:d="getAnnotationPath(item)"
				:stroke="item.style?.stroke || '#ff0000'"
				:stroke-width="item.style?.strokeWidth || 2.0"
				:stroke-dasharray="getAnnotationDashArray(item)"
				:opacity="item.style?.opacity ?? 1.0"
				:style="{ mixBlendMode: item.style?.blendMode || (item.type === 'highlight' ? 'multiply' : 'normal') }"
				fill="none"
				stroke-linecap="round"
				stroke-linejoin="round"
				:data-test="item.type === 'shape' ? 'annotation-shape' : 'annotation-drawing'" />

			<!-- Shape (Rectangle) -->
			<rect
				v-else-if="item.type === 'shape'"
				:x="item.x * 1000"
				:y="item.y * 1000"
				:width="item.width * 1000"
				:height="item.height * 1000"
				:stroke="item.style?.stroke || '#ff0000'"
				:stroke-width="item.style?.strokeWidth || 2.0"
				:stroke-dasharray="getAnnotationDashArray(item)"
				:fill="item.style?.fill || 'transparent'"
				data-test="annotation-shape" />

			<!-- Stamp Annotation -->
			<text
				v-else-if="item.type === 'stamp'"
				:x="item.x * 1000"
				:y="(item.y * 1000) + (item.style?.fontSize ? item.style.fontSize * 0.8 : 32)"
				:fill="item.style?.color || '#000000'"
				:font-size="item.style?.fontSize || 40"
				font-family="sans-serif, music, Bravura, 'Noto Music'"
				data-test="annotation-stamp">
				{{ item.data?.displaySymbol || item.data?.symbol || '' }}
			</text>

			<!-- Text Annotation -->
			<text
				v-else-if="item.type === 'text' && item.data?.text"
				:x="item.x * 1000"
				:y="(item.y * 1000) + (item.data.fontSize || 16)"
				:fill="item.style?.color || '#000000'"
				:font-size="item.data.fontSize || 16"
				:font-family="item.data.fontFamily || 'sans-serif'"
				data-test="annotation-text">
				{{ item.data.text }}
			</text>

			<!-- Navigation jump link -->
			<rect
				v-else-if="item.type === 'link'"
				:x="item.x * 1000"
				:y="item.y * 1000"
				:width="item.width * 1000"
				:height="item.height * 1000"
				class="sheetmusic-annotation-link"
				data-test="annotation-link"
				@click="emit('jump-to-page', item.data?.targetPage ?? 0)" />
		</template>
	</svg>
</template>

<style scoped>
.sheetmusic-annotation-overlay {
	position: absolute;
	top: 0;
	left: 0;
	width: 100%;
	height: 100%;
	pointer-events: none;
	z-index: 2;
}

.sheetmusic-annotation-link {
	pointer-events: auto;
	cursor: pointer;
	fill: rgba(0, 120, 255, 0.2);
	stroke: rgba(0, 120, 255, 0.6);
	stroke-width: 1.5;
	transition: fill 0.15s ease-in-out;
}

.sheetmusic-annotation-link:hover {
	fill: rgba(0, 120, 255, 0.35);
}
</style>
