<script setup lang="ts">
import type { Component } from 'vue'
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ResponsiveNcButton from '../ResponsiveNcButton.vue'

// Icons
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import Rotate360 from 'vue-material-design-icons/Rotate360.vue'
import Autorenew from 'vue-material-design-icons/Autorenew.vue'
import DeleteRestore from 'vue-material-design-icons/DeleteRestore.vue'
import CropIcon from 'vue-material-design-icons/Crop.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import CheckboxMultipleMarkedOutline from 'vue-material-design-icons/CheckboxMultipleMarkedOutline.vue'
import DeleteSweepOutline from 'vue-material-design-icons/DeleteSweepOutline.vue'
import MagnifyMinusOutline from 'vue-material-design-icons/MagnifyMinusOutline.vue'
import MagnifyPlusOutline from 'vue-material-design-icons/MagnifyPlusOutline.vue'

interface ToolbarActionButton {
	id: string
	label: string
	title?: string
	ariaLabel?: string
	variant: 'secondary' | 'tertiary'
	disabled?: boolean
	icon: Component
	onClick: () => void
}

const props = withDefaults(
	defineProps<{
		activeMode: 'rotate' | 'crop' | 'enhance' | 'split' | 'bookmarks' | 'movements'
		totalPages: number
		hasAnyRotation: boolean
		hasAnyCrop: boolean
		hasAnyEnhance: boolean
		hasAnyDeletion: boolean
		hasAnyChange?: boolean
		deletedPagesCount: number
		selectedPagesCount: number
		thumbnailSize?: 'small' | 'medium' | 'large'
	}>(),
	{
		hasAnyChange: false,
		thumbnailSize: 'medium',
	},
)

const emit = defineEmits<{
	'rotate-all': [deltaDeg: number]
	'open-rotate-modal': []
	'reset-rotations': []
	'restore-all-pages': []
	'open-crop-modal': []
	'reset-all-crops': []
	'open-enhance-modal': []
	'reset-all-enhancements': []
	'clear-selection': []
	'select-all-pages': []
	'discard-all-edits': []
	'update:thumbnailSize': [size: 'small' | 'medium' | 'large']
}>()

const isEditMode = computed(() =>
	props.activeMode === 'rotate' || props.activeMode === 'crop' || props.activeMode === 'enhance',
)

const isSelectionMode = computed(() =>
	props.activeMode === 'split' || props.activeMode === 'bookmarks' || props.activeMode === 'movements',
)

const modeToolbar = computed<{ title: string; buttons: ToolbarActionButton[] } | null>(() => {
	if (props.activeMode === 'rotate') {
		return {
			title: t('sheetmusic', 'Quick Actions:'),
			buttons: [
				{
					id: 'rotate-cw',
					label: t('sheetmusic', 'Rotate All 90° CW'),
					variant: 'secondary',
					icon: RotateRight,
					onClick: () => emit('rotate-all', 90),
				},
				{
					id: 'rotate-ccw',
					label: t('sheetmusic', 'Rotate All 90° CCW'),
					variant: 'secondary',
					icon: RotateLeft,
					onClick: () => emit('rotate-all', -90),
				},
				{
					id: 'rotate-180',
					label: t('sheetmusic', 'Flip All 180°'),
					variant: 'secondary',
					icon: Autorenew,
					onClick: () => emit('rotate-all', 180),
				},
				{
					id: 'rotate-angle',
					label: t('sheetmusic', 'Rotate by Angle…'),
					title: t('sheetmusic', 'Rotate by custom angle / fine deskew'),
					variant: 'secondary',
					icon: Rotate360,
					onClick: () => emit('open-rotate-modal'),
				},
				{
					id: 'reset-rotations',
					label: t('sheetmusic', 'Reset All'),
					title: t('sheetmusic', 'Reset all page rotations'),
					ariaLabel: t('sheetmusic', 'Reset all page rotations'),
					variant: 'tertiary',
					disabled: !props.hasAnyRotation,
					icon: RestoreIcon,
					onClick: () => emit('reset-rotations'),
				},
			],
		}
	}

	if (props.activeMode === 'crop') {
		return {
			title: t('sheetmusic', 'Click on any page or "Crop" button to adjust crop margins:'),
			buttons: [
				{
					id: 'crop-all',
					label: t('sheetmusic', 'Crop All Pages…'),
					title: t('sheetmusic', 'Crop first page and apply to all'),
					variant: 'secondary',
					icon: CropIcon,
					onClick: () => emit('open-crop-modal'),
				},
				{
					id: 'reset-crops',
					label: t('sheetmusic', 'Reset All Crops'),
					title: t('sheetmusic', 'Reset all page crops to full page'),
					variant: 'tertiary',
					disabled: !props.hasAnyCrop,
					icon: RestoreIcon,
					onClick: () => emit('reset-all-crops'),
				},
			],
		}
	}

	if (props.activeMode === 'enhance') {
		return {
			title: t('sheetmusic', 'Enhance scan quality (Gaussian filter, thresholding, gray level mapping):'),
			buttons: [
				{
					id: 'enhance-all',
					label: t('sheetmusic', 'Enhance All Pages…'),
					title: t('sheetmusic', 'Configure scan enhancement and apply to all pages'),
					variant: 'secondary',
					icon: Tune,
					onClick: () => emit('open-enhance-modal'),
				},
				{
					id: 'reset-enhancements',
					label: t('sheetmusic', 'Reset All Enhancements'),
					title: t('sheetmusic', 'Reset all page enhancements'),
					variant: 'tertiary',
					disabled: !props.hasAnyEnhance,
					icon: RestoreIcon,
					onClick: () => emit('reset-all-enhancements'),
				},
			],
		}
	}

	if (isSelectionMode.value) {
		const buttons: ToolbarActionButton[] = []
		if (props.totalPages > 0 && props.selectedPagesCount < props.totalPages) {
			buttons.push({
				id: 'select-all',
				label: t('sheetmusic', 'Select All'),
				title: t('sheetmusic', 'Select all pages'),
				ariaLabel: t('sheetmusic', 'Select all pages'),
				variant: 'secondary',
				icon: CheckboxMultipleMarkedOutline,
				onClick: () => emit('select-all-pages'),
			})
		}
		if (props.selectedPagesCount > 0) {
			const clearCountLabel = t('sheetmusic', 'Clear Selection ({count})', { count: props.selectedPagesCount }, { escape: false })
			buttons.push({
				id: 'clear-selection',
				label: props.activeMode === 'split' ? t('sheetmusic', 'Clear Selection') : clearCountLabel,
				title: props.activeMode === 'split' ? t('sheetmusic', 'Clear selection') : clearCountLabel,
				ariaLabel: props.activeMode === 'split' ? t('sheetmusic', 'Clear selection') : clearCountLabel,
				variant: 'tertiary',
				icon: CloseIcon,
				onClick: () => emit('clear-selection'),
			})
		}
		return {
			title: t('sheetmusic', 'Click pages to select (Shift+Click for range):'),
			buttons,
		}
	}

	return null
})

function stepThumbnailSize(direction: -1 | 1) {
	const order: Array<'small' | 'medium' | 'large'> = ['small', 'medium', 'large']
	const idx = order.indexOf(props.thumbnailSize ?? 'medium')
	const next = order[Math.max(0, Math.min(order.length - 1, idx + direction))]
	emit('update:thumbnailSize', next)
}
</script>

<template>
	<div
		:class="$style.toolbar"
		role="toolbar"
		:aria-label="t('sheetmusic', 'PDF modifier toolbar')">
		<div v-if="modeToolbar" :class="$style.toolbarGroup">
			<span :class="$style.toolbarTitle">{{ modeToolbar.title }}</span>
			<span v-if="isSelectionMode && selectedPagesCount > 0" :class="$style.selectionBadge">
				{{ t('sheetmusic', '{count} pages selected', { count: selectedPagesCount }, { escape: false }) }}
			</span>
			<ResponsiveNcButton
				v-for="btn in modeToolbar.buttons"
				:key="btn.id"
				:variant="btn.variant"
				:disabled="btn.disabled"
				:label="btn.label"
				:title="btn.title"
				:aria-label="btn.ariaLabel"
				@click="btn.onClick">
				<template #icon>
					<component :is="btn.icon" :size="20" />
				</template>
			</ResponsiveNcButton>
		</div>

		<!-- Right-aligned Global Controls: Restore Deleted, Discard All & Thumbnail Zoom -->
		<div :class="$style.toolbarRightControls">
			<ResponsiveNcButton
				v-if="hasAnyDeletion && isEditMode"
				variant="tertiary"
				:label="t('sheetmusic', 'Restore Deleted ({count})', { count: deletedPagesCount }, { escape: false })"
				:title="t('sheetmusic', 'Restore all deleted pages')"
				@click="emit('restore-all-pages')">
				<template #icon>
					<DeleteRestore :size="20" />
				</template>
			</ResponsiveNcButton>
			<ResponsiveNcButton
				v-if="hasAnyChange && isEditMode"
				variant="tertiary"
				:label="t('sheetmusic', 'Discard All')"
				:title="t('sheetmusic', 'Discard all unsaved edits')"
				@click="emit('discard-all-edits')">
				<template #icon>
					<DeleteSweepOutline :size="20" />
				</template>
			</ResponsiveNcButton>

			<div
				:class="$style.zoomSwitcher"
				role="group"
				:aria-label="t('sheetmusic', 'Thumbnail size')">
				<NcButton
					variant="tertiary"
					:disabled="thumbnailSize === 'small'"
					:title="t('sheetmusic', 'Smaller thumbnails')"
					:aria-label="t('sheetmusic', 'Smaller thumbnails')"
					@click="stepThumbnailSize(-1)">
					<template #icon>
						<MagnifyMinusOutline :size="20" />
					</template>
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="thumbnailSize === 'large'"
					:title="t('sheetmusic', 'Larger thumbnails')"
					:aria-label="t('sheetmusic', 'Larger thumbnails')"
					@click="stepThumbnailSize(1)">
					<template #icon>
						<MagnifyPlusOutline :size="20" />
					</template>
				</NcButton>
			</div>
		</div>
	</div>
</template>

<style module>
.toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 16px;
	background-color: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
	flex-wrap: wrap;
	flex-shrink: 0;
	min-height: var(--default-clickable-area, 44px);
	box-sizing: border-box;
}

.toolbarGroup {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}

.toolbarTitle {
	font-size: 13px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	margin-right: 4px;
}

.selectionBadge {
	display: inline-flex;
	align-items: center;
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
	color: var(--color-primary-element);
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
}

.toolbarRightControls {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-left: auto;
	flex-shrink: 0;
}

.zoomSwitcher {
	display: inline-flex;
	align-items: center;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 6px);
	padding: 2px;
	gap: 2px;
}

/* Tablet & Mobile responsive styles */
@media (max-width: 900px) {
	.toolbar {
		padding: 6px 12px;
		gap: 8px;
	}
}

@media (max-width: 768px) {
	.toolbar {
		padding: 6px 10px;
		gap: 6px;
		flex-direction: row;
		flex-wrap: wrap;
	}

	.toolbarTitle {
		display: none;
	}

	/* Flattens the group wrappers so buttons flow continuously horizontally */
	.toolbarGroup {
		display: contents;
	}
}
</style>
