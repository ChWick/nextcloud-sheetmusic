<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ResponsiveNcButton from '../ResponsiveNcButton.vue'

// Icons
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import Rotate360 from 'vue-material-design-icons/Rotate360.vue'
import Autorenew from 'vue-material-design-icons/Autorenew.vue'
import TableMergeCells from 'vue-material-design-icons/TableMergeCells.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import DeleteRestore from 'vue-material-design-icons/DeleteRestore.vue'
import CropIcon from 'vue-material-design-icons/Crop.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import CheckboxMarkedCircleOutline from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'

const props = withDefaults(
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

const rotateActions = computed(() => [
	{ id: 'ccw', label: t('sheetmusic', 'Rotate page 90° CCW'), icon: RotateLeft, onClick: () => emit('rotate-page', -90) },
	{ id: 'cw', label: t('sheetmusic', 'Rotate page 90° CW'), icon: RotateRight, onClick: () => emit('rotate-page', 90) },
	{ id: '180', label: t('sheetmusic', 'Flip page 180°'), icon: Autorenew, onClick: () => emit('rotate-page', 180) },
	{
		id: 'custom',
		label: t('sheetmusic', 'Rotate page {page} by custom angle', { page: props.pageNum }, { escape: false }),
		icon: Rotate360,
		onClick: () => emit('open-rotate-modal'),
	},
])
</script>

<template>
	<!-- Rotate / Crop / Enhance Actions -->
	<div
		v-if="activeMode === 'rotate' || activeMode === 'crop' || activeMode === 'enhance'"
		:class="$style.cardActions">
		<template v-if="activeMode === 'rotate'">
			<ResponsiveNcButton
				v-for="act in rotateActions"
				:key="act.id"
				size="small"
				variant="tertiary"
				icon-only
				:disabled="isDeleted"
				:label="act.label"
				@click.stop="act.onClick">
				<template #icon>
					<component :is="act.icon" :size="16" />
				</template>
			</ResponsiveNcButton>
			<ResponsiveNcButton
				v-if="pageNum < totalPages && !isDeleted"
				size="small"
				variant="tertiary"
				icon-only
				:label="t('sheetmusic', 'Join with page {p}', { p: pageNum + 1 }, { escape: false })"
				@click.stop="emit('open-join-modal', pageNum + 1)">
				<template #icon>
					<TableMergeCells :size="16" />
				</template>
			</ResponsiveNcButton>
		</template>

		<template v-else-if="activeMode === 'crop'">
			<ResponsiveNcButton
				v-if="!isDeleted"
				size="small"
				collapse-at="480"
				:variant="isCropped ? 'primary' : 'tertiary'"
				:label="t('sheetmusic', 'Crop')"
				:title="t('sheetmusic', 'Crop page {page}', { page: pageNum }, { escape: false })"
				@click.stop="emit('open-crop-modal')">
				<template #icon>
					<CropIcon :size="16" />
				</template>
			</ResponsiveNcButton>
			<ResponsiveNcButton
				v-if="pageNum < totalPages && !isDeleted"
				size="small"
				variant="tertiary"
				icon-only
				:label="t('sheetmusic', 'Join with page {p}', { p: pageNum + 1 }, { escape: false })"
				@click.stop="emit('open-join-modal', pageNum + 1)">
				<template #icon>
					<TableMergeCells :size="16" />
				</template>
			</ResponsiveNcButton>
		</template>

		<ResponsiveNcButton
			v-else-if="!isDeleted"
			size="small"
			collapse-at="480"
			:variant="isEnhanced ? 'primary' : 'tertiary'"
			:label="t('sheetmusic', 'Enhance')"
			:title="t('sheetmusic', 'Enhance page {page}', { page: pageNum }, { escape: false })"
			@click.stop="emit('open-enhance-modal')">
			<template #icon>
				<Tune :size="16" />
			</template>
		</ResponsiveNcButton>

		<ResponsiveNcButton
			size="small"
			icon-only
			:variant="isDeleted ? 'primary' : 'tertiary'"
			:label="isDeleted ? t('sheetmusic', 'Restore page {page}', { page: pageNum }, { escape: false }) : t('sheetmusic', 'Delete page {page}', { page: pageNum }, { escape: false })"
			:disabled="!isDeleted && !canDelete"
			@click.stop="emit('toggle-delete-page')">
			<template #icon>
				<DeleteRestore v-if="isDeleted" :size="16" />
				<DeleteOutline v-else :size="16" />
			</template>
		</ResponsiveNcButton>
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
	.cardActions {
		width: 100%;
		margin-left: 0;
		justify-content: flex-end;
		flex-wrap: wrap;
	}

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
