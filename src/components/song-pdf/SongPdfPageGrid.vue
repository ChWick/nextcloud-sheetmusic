<script setup lang="ts">
import { computed } from 'vue'
import SongPdfPageCard, { type BookmarkPageSummary, type SplitSegmentSummary } from './SongPdfPageCard.vue'
import type { PageCrop } from './crop-types'

const props = withDefaults(
	defineProps<{
		totalPages: number
		activeMode: 'rotate' | 'crop' | 'enhance' | 'split' | 'bookmarks'
		selectedPages?: number[]
		isPageCropped: (pageNum: number) => boolean
		isPageEnhanced: (pageNum: number) => boolean
		isPageDeleted: (pageNum: number) => boolean
		pageRotations?: number[]
		pageCrops?: Record<number, PageCrop>
		thumbnailSize?: 'small' | 'medium' | 'large'
		getCropIndicatorText: (pageNum: number) => string
		getEnhanceIndicatorText: (pageNum: number) => string
		getPageSegmentsSummary: (pageNum: number) => SplitSegmentSummary[]
		getPageBookmarksSummary?: (pageNum: number) => BookmarkPageSummary[]
		remainingPagesCount: number
	}>(),
	{
		selectedPages: () => [],
		pageRotations: () => [],
		pageCrops: () => ({}),
		thumbnailSize: 'medium',
	},
)

const selectedPageSet = computed(() => new Set(props.selectedPages))
const EMPTY_SEGMENTS: SplitSegmentSummary[] = []
const EMPTY_BOOKMARKS: BookmarkPageSummary[] = []

const emit = defineEmits<{
	'card-click': [pageNum: number]
	'card-range-select': [pageNum: number]
	'zoom-page': [pageNum: number]
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
	<div
		:class="[
			$style.grid,
			thumbnailSize === 'small' && $style.gridSmall,
			thumbnailSize === 'large' && $style.gridLarge,
		]"
		data-test="pdf-page-grid">
		<SongPdfPageCard
			v-for="pageNum in totalPages"
			:key="pageNum"
			:page-num="pageNum"
			:active-mode="activeMode"
			:is-selected="selectedPageSet.has(pageNum)"
			:is-cropped="isPageCropped(pageNum)"
			:is-enhanced="isPageEnhanced(pageNum)"
			:is-deleted="isPageDeleted(pageNum)"
			:rotation-deg="pageRotations[pageNum - 1] || 0"
			:page-crop="pageCrops[pageNum]"
			:thumbnail-size="thumbnailSize"
			:crop-indicator-text="getCropIndicatorText(pageNum)"
			:enhance-indicator-text="getEnhanceIndicatorText(pageNum)"
			:page-segments="activeMode === 'split' ? getPageSegmentsSummary(pageNum) : EMPTY_SEGMENTS"
			:page-bookmarks="activeMode === 'bookmarks' && getPageBookmarksSummary ? getPageBookmarksSummary(pageNum) : EMPTY_BOOKMARKS"
			:total-pages="totalPages"
			:can-delete="remainingPagesCount > 1"
			@card-click="emit('card-click', pageNum)"
			@card-range-select="emit('card-range-select', pageNum)"
			@zoom-page="emit('zoom-page', pageNum)"
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
	grid-auto-rows: minmax(256px, auto);
	align-content: start;
	gap: 16px;
	padding: 16px;
	overflow-y: auto;
}

.gridSmall {
	grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
	grid-auto-rows: minmax(195px, auto);
	gap: 10px;
}

.gridLarge {
	grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
	grid-auto-rows: minmax(330px, auto);
	gap: 20px;
}

@media (max-width: 768px) {
	.grid {
		grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
		gap: 12px;
		padding: 12px;
	}

	.gridSmall {
		grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
		gap: 8px;
	}

	.gridLarge {
		grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	}
}

@media (max-width: 480px) {
	.grid {
		grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
		gap: 8px;
		padding: 8px;
	}

	.gridSmall {
		grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));
		gap: 6px;
	}

	.gridLarge {
		grid-template-columns: 1fr;
	}
}
</style>
