<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

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
		<!-- Rotate Toolbar -->
		<template v-if="activeMode === 'rotate'">
			<div :class="$style.toolbarGroup">
				<span :class="$style.toolbarTitle">{{ t('sheetmusic', 'Quick Actions:') }}</span>
				<NcButton
					variant="secondary"
					:title="t('sheetmusic', 'Rotate All 90° CW')"
					:aria-label="t('sheetmusic', 'Rotate All 90° CW')"
					@click="emit('rotate-all', 90)">
					<template #icon>
						<RotateRight :size="20" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Rotate All 90° CW') }}</span>
				</NcButton>
				<NcButton
					variant="secondary"
					:title="t('sheetmusic', 'Rotate All 90° CCW')"
					:aria-label="t('sheetmusic', 'Rotate All 90° CCW')"
					@click="emit('rotate-all', -90)">
					<template #icon>
						<RotateLeft :size="20" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Rotate All 90° CCW') }}</span>
				</NcButton>
				<NcButton
					variant="secondary"
					:title="t('sheetmusic', 'Flip All 180°')"
					:aria-label="t('sheetmusic', 'Flip All 180°')"
					@click="emit('rotate-all', 180)">
					<template #icon>
						<Autorenew :size="20" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Flip All 180°') }}</span>
				</NcButton>
				<NcButton
					variant="secondary"
					:title="t('sheetmusic', 'Rotate by custom angle / fine deskew')"
					:aria-label="t('sheetmusic', 'Rotate by Angle…')"
					@click="emit('open-rotate-modal')">
					<template #icon>
						<Rotate360 :size="20" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Rotate by Angle…') }}</span>
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="!hasAnyRotation"
					:title="t('sheetmusic', 'Reset all page rotations')"
					:aria-label="t('sheetmusic', 'Reset all page rotations')"
					@click="emit('reset-rotations')">
					<template #icon>
						<RestoreIcon :size="20" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Reset All') }}</span>
				</NcButton>
			</div>
		</template>

		<!-- Crop Toolbar -->
		<template v-else-if="activeMode === 'crop'">
			<div :class="$style.toolbarGroup">
				<span :class="$style.toolbarTitle">
					{{ t('sheetmusic', 'Click on any page or "Crop" button to adjust crop margins:') }}
				</span>
				<NcButton
					variant="secondary"
					:title="t('sheetmusic', 'Crop first page and apply to all')"
					:aria-label="t('sheetmusic', 'Crop All Pages…')"
					@click="emit('open-crop-modal')">
					<template #icon>
						<CropIcon :size="20" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Crop All Pages…') }}</span>
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="!hasAnyCrop"
					:title="t('sheetmusic', 'Reset all page crops to full page')"
					:aria-label="t('sheetmusic', 'Reset All Crops')"
					@click="emit('reset-all-crops')">
					<template #icon>
						<RestoreIcon :size="20" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Reset All Crops') }}</span>
				</NcButton>
			</div>
		</template>

		<!-- Enhance Toolbar -->
		<template v-else-if="activeMode === 'enhance'">
			<div :class="$style.toolbarGroup">
				<span :class="$style.toolbarTitle">
					{{ t('sheetmusic', 'Enhance scan quality (Gaussian filter, thresholding, gray level mapping):') }}
				</span>
				<NcButton
					variant="secondary"
					:title="t('sheetmusic', 'Configure scan enhancement and apply to all pages')"
					:aria-label="t('sheetmusic', 'Enhance All Pages…')"
					@click="emit('open-enhance-modal')">
					<template #icon>
						<Tune :size="20" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Enhance All Pages…') }}</span>
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="!hasAnyEnhance"
					:title="t('sheetmusic', 'Reset all page enhancements')"
					:aria-label="t('sheetmusic', 'Reset All Enhancements')"
					@click="emit('reset-all-enhancements')">
					<template #icon>
						<RestoreIcon :size="20" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Reset All Enhancements') }}</span>
				</NcButton>
			</div>
		</template>

		<!-- Split & Bookmarks Selection Toolbar -->
		<template v-else-if="activeMode === 'split' || activeMode === 'bookmarks' || activeMode === 'movements'">
			<div :class="$style.toolbarGroup">
				<span :class="$style.toolbarTitle">
					{{ t('sheetmusic', 'Click pages to select (Shift+Click for range):') }}
				</span>
				<span v-if="selectedPagesCount > 0" :class="$style.selectionBadge">
					{{ t('sheetmusic', '{count} pages selected', { count: selectedPagesCount }, { escape: false }) }}
				</span>
				<NcButton
					v-if="totalPages > 0 && selectedPagesCount < totalPages"
					variant="secondary"
					:title="t('sheetmusic', 'Select all pages')"
					:aria-label="t('sheetmusic', 'Select all pages')"
					@click="emit('select-all-pages')">
					<template #icon>
						<CheckboxMultipleMarkedOutline :size="20" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Select All') }}</span>
				</NcButton>
				<NcButton
					v-if="selectedPagesCount > 0"
					variant="tertiary"
					:title="activeMode === 'split' ? t('sheetmusic', 'Clear selection') : t('sheetmusic', 'Clear Selection ({count})', { count: selectedPagesCount }, { escape: false })"
					:aria-label="activeMode === 'split' ? t('sheetmusic', 'Clear selection') : t('sheetmusic', 'Clear Selection ({count})', { count: selectedPagesCount }, { escape: false })"
					@click="emit('clear-selection')">
					<template #icon>
						<CloseIcon :size="20" />
					</template>
					<span :class="$style.btnText">
						{{ activeMode === 'split' ? t('sheetmusic', 'Clear Selection') : t('sheetmusic', 'Clear Selection ({count})', { count: selectedPagesCount }, { escape: false }) }}
					</span>
				</NcButton>
			</div>
		</template>

		<!-- Right-aligned Global Controls: Restore Deleted, Discard All & Thumbnail Zoom -->
		<div :class="$style.toolbarRightControls">
			<NcButton
				v-if="hasAnyDeletion && (activeMode === 'rotate' || activeMode === 'crop' || activeMode === 'enhance')"
				variant="tertiary"
				:title="t('sheetmusic', 'Restore all deleted pages')"
				:aria-label="t('sheetmusic', 'Restore Deleted ({count})', { count: deletedPagesCount }, { escape: false })"
				@click="emit('restore-all-pages')">
				<template #icon>
					<DeleteRestore :size="20" />
				</template>
				<span :class="$style.btnText">{{ t('sheetmusic', 'Restore Deleted ({count})', { count: deletedPagesCount }, { escape: false }) }}</span>
			</NcButton>
			<NcButton
				v-if="hasAnyChange && (activeMode === 'rotate' || activeMode === 'crop' || activeMode === 'enhance')"
				variant="tertiary"
				:title="t('sheetmusic', 'Discard all unsaved edits')"
				:aria-label="t('sheetmusic', 'Discard All')"
				@click="emit('discard-all-edits')">
				<template #icon>
					<DeleteSweepOutline :size="20" />
				</template>
				<span :class="$style.btnText">{{ t('sheetmusic', 'Discard All') }}</span>
			</NcButton>

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

.btnText {
	display: inline;
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

	/* On mobile screens, hide button text so buttons flow horizontally as compact icon buttons until space runs out */
	.btnText {
		display: none;
	}

	.toolbar :global(.button-vue__text) {
		display: none !important;
	}

	.toolbar :global(.button-vue) {
		--button-padding: 0;
		line-height: 1;
		width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		min-width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		min-height: var(--button-size, var(--default-clickable-area, 44px)) !important;
		padding: 0 !important;
		padding-inline: 0 !important;
		justify-content: center !important;
	}

	.toolbar :global(.button-vue__wrapper) {
		justify-content: center !important;
		width: 100% !important;
	}

	.toolbar :global(.button-vue__icon) {
		margin: 0 !important;
	}
}
</style>
