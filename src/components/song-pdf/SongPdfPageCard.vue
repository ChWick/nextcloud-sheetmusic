<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

// Icons
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import Rotate360 from 'vue-material-design-icons/Rotate360.vue'
import TableMergeCells from 'vue-material-design-icons/TableMergeCells.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import DeleteRestore from 'vue-material-design-icons/DeleteRestore.vue'
import CropIcon from 'vue-material-design-icons/Crop.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import CheckboxMarkedCircleOutline from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'

export interface SplitSegmentSummary {
	id: string
	title: string
	index: number
	color: string
}

const props = withDefaults(
	defineProps<{
		pageNum: number
		activeMode: 'rotate' | 'crop' | 'enhance' | 'split'
		isSelected?: boolean
		isCropped?: boolean
		isEnhanced?: boolean
		isDeleted?: boolean
		rotationDeg?: number
		cropIndicatorText?: string
		enhanceIndicatorText?: string
		pageSegments?: SplitSegmentSummary[]
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
		canDelete: true,
	},
)

const emit = defineEmits<{
	'card-click': [pageNum: number]
	'set-container-ref': [pageNum: number, el: HTMLElement | null]
	'rotate-page': [deltaDeg: number]
	'open-rotate-modal': []
	'open-join-modal': [targetPageNum: number]
	'toggle-delete-page': []
	'open-crop-modal': []
	'open-enhance-modal': []
}>()

function handleCardClick() {
	if (props.activeMode === 'split') {
		emit('card-click', props.pageNum)
	} else if (props.activeMode === 'crop' && !props.isDeleted) {
		emit('open-crop-modal')
	} else if (props.activeMode === 'enhance' && !props.isDeleted) {
		emit('open-enhance-modal')
	}
}

const hasRotation = computed(() => Math.abs(props.rotationDeg) > 0.001)
const formattedRotation = computed(() => `${Math.round(props.rotationDeg * 10) / 10}°`)
</script>

<template>
	<div
		:class="[
			$style.pageCard,
			isSelected && $style.pageCardSelected,
			activeMode === 'crop' && isCropped && $style.pageCardCropped,
			activeMode === 'enhance' && isEnhanced && $style.pageCardEnhanced,
			isDeleted && $style.pageCardDeleted,
		]"
		@click="handleCardClick">
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

		<div :class="$style.canvasWrapper">
			<div
				:ref="(el) => emit('set-container-ref', pageNum, el as HTMLElement | null)"
				:class="$style.thumbnailSlot"
				:data-page-num="pageNum"
				:style="{
					transform: `rotate(${rotationDeg || 0}deg)`,
					transition: 'transform 0.15s ease-out'
				}"></div>
			<div v-if="isDeleted" :class="$style.deletedOverlay">
				<DeleteOutline :size="28" />
				<span>{{ t('sheetmusic', 'Deleted') }}</span>
			</div>
		</div>

		<div :class="$style.cardFooter">
			<span :class="[$style.pageNumLabel, isDeleted && $style.pageNumLabelDeleted]">
				{{ t('sheetmusic', 'p. {page}', { page: pageNum }) }}
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

			<!-- Rotate Actions -->
			<div v-if="activeMode === 'rotate'" :class="$style.cardActions">
				<NcButton
					size="small"
					variant="tertiary"
					:disabled="isDeleted"
					:title="t('sheetmusic', 'Rotate page 90° CCW')"
					:aria-label="t('sheetmusic', 'Rotate page 90° CCW')"
					@click.stop="emit('rotate-page', -90)">
					<template #icon>
						<RotateLeft :size="16" />
					</template>
				</NcButton>
				<NcButton
					size="small"
					variant="tertiary"
					:disabled="isDeleted"
					:title="t('sheetmusic', 'Rotate page 90° CW')"
					:aria-label="t('sheetmusic', 'Rotate page 90° CW')"
					@click.stop="emit('rotate-page', 90)">
					<template #icon>
						<RotateRight :size="16" />
					</template>
				</NcButton>
				<NcButton
					size="small"
					variant="tertiary"
					:disabled="isDeleted"
					:title="t('sheetmusic', 'Rotate page {page} by custom angle', { page: pageNum })"
					:aria-label="t('sheetmusic', 'Rotate page {page} by custom angle', { page: pageNum })"
					@click.stop="emit('open-rotate-modal')">
					<template #icon>
						<Rotate360 :size="16" />
					</template>
				</NcButton>
				<NcButton
					v-if="pageNum < totalPages && !isDeleted"
					size="small"
					variant="tertiary"
					:title="t('sheetmusic', 'Join with page {p}', { p: pageNum + 1 })"
					:aria-label="t('sheetmusic', 'Join with page {p}', { p: pageNum + 1 })"
					@click.stop="emit('open-join-modal', pageNum + 1)">
					<template #icon>
						<TableMergeCells :size="16" />
					</template>
				</NcButton>
				<NcButton
					size="small"
					:variant="isDeleted ? 'primary' : 'tertiary'"
					:title="isDeleted ? t('sheetmusic', 'Restore page {page}', { page: pageNum }) : t('sheetmusic', 'Delete page {page}', { page: pageNum })"
					:aria-label="isDeleted ? t('sheetmusic', 'Restore page {page}', { page: pageNum }) : t('sheetmusic', 'Delete page {page}', { page: pageNum })"
					:disabled="!isDeleted && !canDelete"
					@click.stop="emit('toggle-delete-page')">
					<template #icon>
						<DeleteRestore v-if="isDeleted" :size="16" />
						<DeleteOutline v-else :size="16" />
					</template>
				</NcButton>
			</div>

			<!-- Crop Actions -->
			<div v-else-if="activeMode === 'crop'" :class="$style.cardActions">
				<NcButton
					v-if="!isDeleted"
					size="small"
					:variant="isCropped ? 'primary' : 'tertiary'"
					:title="t('sheetmusic', 'Crop page {page}', { page: pageNum })"
					@click.stop="emit('open-crop-modal')">
					<template #icon>
						<CropIcon :size="16" />
					</template>
					{{ t('sheetmusic', 'Crop') }}
				</NcButton>
				<NcButton
					v-if="pageNum < totalPages && !isDeleted"
					size="small"
					variant="tertiary"
					:title="t('sheetmusic', 'Join with page {p}', { p: pageNum + 1 })"
					:aria-label="t('sheetmusic', 'Join with page {p}', { p: pageNum + 1 })"
					@click.stop="emit('open-join-modal', pageNum + 1)">
					<template #icon>
						<TableMergeCells :size="16" />
					</template>
				</NcButton>
				<NcButton
					size="small"
					:variant="isDeleted ? 'primary' : 'tertiary'"
					:title="isDeleted ? t('sheetmusic', 'Restore page {page}', { page: pageNum }) : t('sheetmusic', 'Delete page {page}', { page: pageNum })"
					:aria-label="isDeleted ? t('sheetmusic', 'Restore page {page}', { page: pageNum }) : t('sheetmusic', 'Delete page {page}', { page: pageNum })"
					:disabled="!isDeleted && !canDelete"
					@click.stop="emit('toggle-delete-page')">
					<template #icon>
						<DeleteRestore v-if="isDeleted" :size="16" />
						<DeleteOutline v-else :size="16" />
					</template>
				</NcButton>
			</div>

			<!-- Enhance Actions -->
			<div v-else-if="activeMode === 'enhance'" :class="$style.cardActions">
				<NcButton
					v-if="!isDeleted"
					size="small"
					:variant="isEnhanced ? 'primary' : 'tertiary'"
					:title="t('sheetmusic', 'Enhance page {page}', { page: pageNum })"
					@click.stop="emit('open-enhance-modal')">
					<template #icon>
						<Tune :size="16" />
					</template>
					{{ t('sheetmusic', 'Enhance') }}
				</NcButton>
				<NcButton
					size="small"
					:variant="isDeleted ? 'primary' : 'tertiary'"
					:title="isDeleted ? t('sheetmusic', 'Restore page {page}', { page: pageNum }) : t('sheetmusic', 'Delete page {page}', { page: pageNum })"
					:aria-label="isDeleted ? t('sheetmusic', 'Restore page {page}', { page: pageNum }) : t('sheetmusic', 'Delete page {page}', { page: pageNum })"
					:disabled="!isDeleted && !canDelete"
					@click.stop="emit('toggle-delete-page')">
					<template #icon>
						<DeleteRestore v-if="isDeleted" :size="16" />
						<DeleteOutline v-else :size="16" />
					</template>
				</NcButton>
			</div>

			<!-- Split Mode Selection Indicator -->
			<div v-else-if="activeMode === 'split'" :class="$style.selectCheckbox">
				<CheckboxMarkedCircleOutline
					v-if="isSelected"
					:size="18"
					:class="$style.checkedIcon" />
				<span v-else :class="$style.uncheckedDot"></span>
			</div>
		</div>
	</div>
</template>

<style module>
.pageCard {
	display: flex;
	flex-direction: column;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-main-background);
	overflow: hidden;
	transition: all 0.15s ease-in-out;
	cursor: pointer;
	position: relative;
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

.canvasWrapper {
	position: relative;
	width: 100%;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 12px;
	background-color: var(--color-background-hover);
	min-height: 180px;
}

.thumbnailSlot {
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
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

.cardFooter {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 6px 10px;
	border-top: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	gap: 6px;
}

.pageNumLabel {
	font-size: 12px;
	font-weight: 500;
	color: var(--color-main-text);
	display: flex;
	align-items: center;
	gap: 4px;
	flex-wrap: wrap;
}

.pageNumLabelDeleted {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.rotationBadge {
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
	color: var(--color-primary-element);
	padding: 1px 5px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
}

.cropBadge {
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
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
	color: var(--color-primary-element);
	padding: 1px 6px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
}

.deletedBadge {
	background-color: rgba(220, 38, 38, 0.15);
	color: #dc2626;
	padding: 1px 5px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
}

.cardActions {
	display: flex;
	align-items: center;
	gap: 2px;
}

.selectCheckbox {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
}

.checkedIcon {
	color: var(--color-primary-element);
}

.uncheckedDot {
	width: 14px;
	height: 14px;
	border-radius: 50%;
	border: 1px solid var(--color-border);
}
</style>
