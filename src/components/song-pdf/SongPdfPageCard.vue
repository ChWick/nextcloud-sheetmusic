<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import SongPdfCardActions from './SongPdfCardActions.vue'
import type { PageCrop } from './crop-types'

export interface SplitSegmentSummary {
	id: string
	title: string
	index: number
	color: string
}

export interface BookmarkPageSummary {
	id: string
	name: string
	index: number
}
export type MovementPageSummary = BookmarkPageSummary

const props = withDefaults(
	defineProps<{
		pageNum: number
		activeMode: 'rotate' | 'crop' | 'enhance' | 'split' | 'bookmarks' | 'movements'
		isSelected?: boolean
		isCropped?: boolean
		isEnhanced?: boolean
		isDeleted?: boolean
		rotationDeg?: number
		pageCrop?: PageCrop
		thumbnailSize?: 'small' | 'medium' | 'large'
		cropIndicatorText?: string
		enhanceIndicatorText?: string
		pageSegments?: SplitSegmentSummary[]
		pageBookmarks?: BookmarkPageSummary[]
		pageMovements?: MovementPageSummary[]
		totalPages: number
		canDelete?: boolean
	}>(),
	{
		isSelected: false,
		isCropped: false,
		isEnhanced: false,
		isDeleted: false,
		rotationDeg: 0,
		cropIndicatorText: '',
		enhanceIndicatorText: '',
		pageSegments: () => [],
		pageBookmarks: () => [],
		pageMovements: () => [],
		canDelete: true,
	},
)

const activeBookmarkSummaries = computed<BookmarkPageSummary[]>(() => {
	if (props.pageBookmarks && props.pageBookmarks.length > 0) return props.pageBookmarks
	return props.pageMovements || []
})

const emit = defineEmits<{
	'card-click': [pageNum: number]
	'card-range-select': [pageNum: number]
	'zoom-page': [pageNum: number]
	'set-container-ref': [pageNum: number, el: HTMLElement | null]
	'rotate-page': [deltaDeg: number]
	'open-rotate-modal': []
	'open-join-modal': [targetPageNum: number]
	'toggle-delete-page': []
	'open-crop-modal': []
	'open-enhance-modal': []
}>()

function handleCardClick(event: MouseEvent) {
	if (props.activeMode === 'split' || props.activeMode === 'bookmarks' || props.activeMode === 'movements') {
		if (event.shiftKey) {
			emit('card-range-select', props.pageNum)
		} else {
			emit('card-click', props.pageNum)
		}
	} else if (props.activeMode === 'crop' && !props.isDeleted) {
		emit('open-crop-modal')
	} else if (props.activeMode === 'enhance' && !props.isDeleted) {
		emit('open-enhance-modal')
	}
}

const cropOverlayStyle = computed(() => {
	if (!props.isCropped || !props.pageCrop || props.isDeleted) return null
	const { x, y, width, height } = props.pageCrop
	return {
		left: `${Math.max(0, Math.min(1, x)) * 100}%`,
		top: `${Math.max(0, Math.min(1, y)) * 100}%`,
		width: `${Math.max(0, Math.min(1, width)) * 100}%`,
		height: `${Math.max(0, Math.min(1, height)) * 100}%`,
	}
})

const hasRotation = computed(() => Math.abs(props.rotationDeg) > 0.001)
const formattedRotation = computed(() => `${Math.round(props.rotationDeg * 10) / 10}°`)
</script>

<template>
	<div
		:class="[
			$style.pageCard,
			thumbnailSize === 'small' && $style.pageCardSmall,
			thumbnailSize === 'large' && $style.pageCardLarge,
			isSelected && $style.pageCardSelected,
			activeMode === 'crop' && isCropped && $style.pageCardCropped,
			activeMode === 'enhance' && isEnhanced && $style.pageCardEnhanced,
			isDeleted && $style.pageCardDeleted,
		]"
		@click="handleCardClick($event)">
		<!-- Segment color indicators bar in split mode -->
		<div v-if="activeMode === 'split'" :class="$style.segmentPills">
			<span
				v-for="seg in pageSegments"
				:key="seg.id"
				:class="$style.segmentPill"
				:style="{ backgroundColor: seg.color }"
				:title="seg.title">
				{{ seg.index }}
			</span>
		</div>

		<!-- Bookmark indicators bar in bookmarks mode -->
		<div v-if="(activeMode === 'bookmarks' || activeMode === 'movements') && activeBookmarkSummaries.length > 0" :class="$style.segmentPills">
			<span
				v-for="bm in activeBookmarkSummaries"
				:key="bm.id"
				:class="[$style.segmentPill, $style.bookmarkPill]"
				:title="bm.name">
				#{{ bm.index }}
			</span>
		</div>

		<div :class="$style.canvasWrapper">
			<div :class="$style.thumbnailStage"
				:style="{
					transform: `rotate(${rotationDeg || 0}deg)`,
					transition: 'transform 0.15s ease-out'
				}">
				<div
					:ref="(el) => emit('set-container-ref', pageNum, el as HTMLElement | null)"
					:class="$style.thumbnailSlot"
					:data-page-num="pageNum"></div>
				<div
					v-if="cropOverlayStyle"
					:class="$style.cropPreviewFrame"
					:style="cropOverlayStyle"
					aria-hidden="true" />
			</div>
			<div v-if="isDeleted" :class="$style.deletedOverlay">
				<DeleteOutline :size="28" />
				<span>{{ t('sheetmusic', 'Deleted') }}</span>
			</div>
			<button
				v-else
				type="button"
				:class="$style.zoomBtn"
				:title="t('sheetmusic', 'Zoom in on page {p}', { p: pageNum }, { escape: false })"
				:aria-label="t('sheetmusic', 'Zoom in on page {p}', { p: pageNum }, { escape: false })"
				data-test="card-zoom-btn"
				@click.stop="emit('zoom-page', pageNum)">
				<MagnifyPlus :size="15" />
			</button>
		</div>

		<div :class="$style.cardFooter">
			<span :class="[$style.pageNumLabel, isDeleted && $style.pageNumLabelDeleted]">
				{{ t('sheetmusic', 'p. {page}', { page: pageNum }, { escape: false }) }}
				<span v-if="hasRotation" :class="$style.rotationBadge">
					{{ formattedRotation }}
				</span>
				<span v-if="activeMode === 'crop' && isCropped" :class="$style.cropBadge" :title="cropIndicatorText">
					{{ t('sheetmusic', 'Cropped') }}
				</span>
				<span v-if="activeMode === 'enhance' && isEnhanced" :class="$style.enhanceBadge" :title="enhanceIndicatorText">
					{{ t('sheetmusic', 'Enhanced') }}
				</span>
				<span v-if="isDeleted" :class="$style.deletedBadge">
					{{ t('sheetmusic', 'Deleted') }}
				</span>
			</span>

			<SongPdfCardActions
				:page-num="pageNum"
				:active-mode="activeMode"
				:total-pages="totalPages"
				:is-selected="isSelected"
				:is-cropped="isCropped"
				:is-enhanced="isEnhanced"
				:is-deleted="isDeleted"
				:can-delete="canDelete"
				@rotate-page="emit('rotate-page', $event)"
				@open-rotate-modal="emit('open-rotate-modal')"
				@open-join-modal="emit('open-join-modal', $event)"
				@toggle-delete-page="emit('toggle-delete-page')"
				@open-crop-modal="emit('open-crop-modal')"
				@open-enhance-modal="emit('open-enhance-modal')" />
		</div>
	</div>
</template>

<style module>
.pageCard {
	display: flex;
	flex-direction: column;
	min-height: 256px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-main-background);
	overflow: hidden;
	transition: all 0.15s ease-in-out;
	cursor: pointer;
	position: relative;
	content-visibility: auto;
	contain-intrinsic-size: auto 256px;
}

.pageCard:hover {
	border-color: var(--color-primary-element);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}

.pageCardSelected {
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element);
}

.pageCardCropped {
	border-color: var(--color-primary-element);
}

.pageCardEnhanced {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

.pageCardDeleted {
	opacity: 0.55;
	border-style: dashed;
}

.segmentPills {
	position: absolute;
	top: 6px;
	left: 6px;
	display: flex;
	gap: 4px;
	z-index: 2;
}

.segmentPill {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	border-radius: 50%;
	color: #fff;
	font-size: 10px;
	font-weight: bold;
	box-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.bookmarkPill {
	background-color: var(--color-primary-element);
	width: auto;
	min-width: 20px;
	padding: 0 4px;
	border-radius: 10px;
}

.canvasWrapper {
	position: relative;
	width: 100%;
	flex: 1 0 214px;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 12px;
	background-color: var(--color-background-hover);
	min-height: 214px;
	box-sizing: border-box;
	overflow: hidden;
}

.thumbnailStage {
	position: relative;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
}

.thumbnailSlot {
	display: flex;
	align-items: center;
	justify-content: center;
}

.cropPreviewFrame {
	position: absolute;
	box-sizing: border-box;
	border: 1.5px dashed var(--color-primary-element);
	box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.35);
	pointer-events: none;
}

.pageCardSmall {
	min-height: 195px;
}

.pageCardSmall .canvasWrapper {
	flex: 1 0 150px;
	min-height: 150px;
	padding: 8px;
}

.pageCardSmall .thumbnailSlot :global(canvas) {
	max-width: 100%;
	max-height: 136px;
}

.pageCardLarge {
	min-height: 330px;
}

.pageCardLarge .canvasWrapper {
	flex: 1 0 280px;
	min-height: 280px;
	padding: 16px;
}

.pageCardLarge .thumbnailSlot :global(canvas) {
	max-width: 220px;
	max-height: 260px;
}

.thumbnailSlot :global(canvas) {
	max-width: 140px;
	max-height: 190px;
	object-fit: contain;
	display: block;
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
}

.deletedOverlay {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background-color: rgba(255, 255, 255, 0.7);
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	font-weight: bold;
	font-size: 14px;
}

.zoomBtn {
	position: absolute;
	top: 6px;
	right: 6px;
	z-index: 3;
	display: flex;
	align-items: center;
	justify-content: center;
	width: 26px;
	height: 26px;
	border-radius: 50%;
	border: 1px solid var(--color-border);
	background-color: rgba(255, 255, 255, 0.9);
	color: var(--color-main-text);
	cursor: pointer;
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);
	transition: background-color 0.15s ease, transform 0.15s ease, opacity 0.15s ease;
	opacity: 0.85;
}

.pageCard:hover .zoomBtn {
	opacity: 1;
}

.zoomBtn:hover {
	background-color: #fff;
	transform: scale(1.1);
	color: var(--color-primary-element);
}

.cardFooter {
	position: relative;
	z-index: 2;
	flex-shrink: 0;
	margin-top: auto;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 6px 8px;
	min-height: 40px;
	box-sizing: border-box;
	border-top: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	gap: 4px;
}

.pageNumLabel {
	font-size: 12px;
	font-weight: 500;
	color: var(--color-main-text);
	flex: 1 1 0;
	min-width: 0;
	display: flex;
	align-items: center;
	gap: 4px;
	flex-wrap: nowrap;
	white-space: nowrap;
	overflow: hidden;
}

.pageNumLabelDeleted {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.rotationBadge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
	min-width: 34px;
	box-sizing: border-box;
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
	color: var(--color-primary-element);
	padding: 1px 4px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	text-align: center;
}

.cropBadge {
	flex-shrink: 0;
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
	color: var(--color-primary-element);
	padding: 1px 5px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
}

.enhanceBadge {
	display: inline-flex;
	align-items: center;
	flex-shrink: 0;
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
	color: var(--color-primary-element);
	padding: 1px 6px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
}

.deletedBadge {
	flex-shrink: 0;
	background-color: rgba(220, 38, 38, 0.15);
	color: #dc2626;
	padding: 1px 5px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
}

@media (max-width: 480px) {
	.pageCard {
		min-height: 185px;
	}

	.canvasWrapper {
		flex: 1 0 140px;
		min-height: 140px;
		padding: 8px;
	}

	.thumbnailSlot :global(canvas) {
		max-width: 100%;
		max-height: 136px;
	}

	.cardFooter {
		flex-direction: column;
		align-items: stretch;
		padding: 6px;
		gap: 4px;
		min-height: 0;
	}

	.pageNumLabel {
		font-size: 11px;
	}
}
</style>
