<script setup lang="ts">
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

withDefaults(
	defineProps<{
		pageNum: number
		activeMode: 'rotate' | 'crop' | 'enhance' | 'split'
		totalPages: number
		isSelected?: boolean
		isCropped?: boolean
		isEnhanced?: boolean
		isDeleted?: boolean
		canDelete?: boolean
	}>(),
	{
		isSelected: false,
		isCropped: false,
		isEnhanced: false,
		isDeleted: false,
		canDelete: true,
	},
)

const emit = defineEmits<{
	'rotate-page': [deltaDeg: number]
	'open-rotate-modal': []
	'open-join-modal': [targetPageNum: number]
	'toggle-delete-page': []
	'open-crop-modal': []
	'open-enhance-modal': []
}>()
</script>

<template>
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
</template>

<style module>
.cardActions {
	flex: 0 0 auto;
	margin-left: auto;
	display: flex;
	align-items: center;
	gap: 2px;
}

.cardActions :global(.button-vue) {
	--button-size: 28px;
	min-width: 28px;
	min-height: 28px;
	padding-inline: 4px;
}

@media (max-width: 480px) {
	.cardActions :global(.button-vue) {
		--button-size: 26px;
		min-width: 26px;
		min-height: 26px;
		padding-inline: 2px;
	}
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
