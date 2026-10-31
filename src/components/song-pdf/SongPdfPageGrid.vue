<script setup lang="ts">
import SongPdfPageCard, { type SplitSegmentSummary } from './SongPdfPageCard.vue'

withDefaults(
	defineProps<{
		totalPages: number
		activeMode: 'rotate' | 'crop' | 'enhance' | 'split'
		selectedPages?: number[]
		isPageCropped: (pageNum: number) => boolean
		isPageEnhanced: (pageNum: number) => boolean
		isPageDeleted: (pageNum: number) => boolean
		pageRotations?: number[]
		getCropIndicatorText: (pageNum: number) => string
		getEnhanceIndicatorText: (pageNum: number) => string
		getPageSegmentsSummary: (pageNum: number) => SplitSegmentSummary[]
		remainingPagesCount: number
	}>(),
	{
		selectedPages: () => [],
		pageRotations: () => [],
	},
)

const emit = defineEmits<{
	'card-click': [pageNum: number]
	'open-crop-modal': [pageNum: number]
	'open-enhance-modal': [pageNum: number]
	'rotate-page': [pageIndex: number, deltaDeg: number]
	'open-rotate-modal': [pageNum: number]
	'open-join-modal': [pageNum: number, targetPage: number]
	'toggle-delete-page': [pageNum: number]
	'set-container-ref': [pageNum: number, el: HTMLElement | null]
}>()
</script>

<template>
	<div :class="$style.grid" data-test="pdf-page-grid">
		<SongPdfPageCard
			v-for="pageNum in totalPages"
			:key="pageNum"
			:page-num="pageNum"
			:active-mode="activeMode"
			:is-selected="selectedPages.includes(pageNum)"
			:is-cropped="isPageCropped(pageNum)"
			:is-enhanced="isPageEnhanced(pageNum)"
			:is-deleted="isPageDeleted(pageNum)"
			:rotation-deg="pageRotations[pageNum - 1] || 0"
			:crop-indicator-text="getCropIndicatorText(pageNum)"
			:enhance-indicator-text="getEnhanceIndicatorText(pageNum)"
			:page-segments="getPageSegmentsSummary(pageNum)"
			:total-pages="totalPages"
			:can-delete="remainingPagesCount > 1"
			@card-click="emit('card-click', pageNum)"
			@open-crop-modal="emit('open-crop-modal', pageNum)"
			@open-enhance-modal="emit('open-enhance-modal', pageNum)"
			@rotate-page="emit('rotate-page', pageNum - 1, $event)"
			@open-rotate-modal="emit('open-rotate-modal', pageNum)"
			@open-join-modal="emit('open-join-modal', pageNum, $event)"
			@toggle-delete-page="emit('toggle-delete-page', pageNum)"
			@set-container-ref="(p, el) => emit('set-container-ref', p, el)" />
	</div>
</template>

<style module>
.grid {
	flex: 1;
	min-height: 0;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(215px, 1fr));
	align-content: start;
	gap: 16px;
	padding: 16px;
	overflow-y: auto;
}
</style>
