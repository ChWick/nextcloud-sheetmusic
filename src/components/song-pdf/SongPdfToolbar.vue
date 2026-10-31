<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

// Icons
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import RotateLeft from 'vue-material-design-icons/RotateLeft.vue'
import Rotate360 from 'vue-material-design-icons/Rotate360.vue'
import TableMergeCells from 'vue-material-design-icons/TableMergeCells.vue'
import DeleteRestore from 'vue-material-design-icons/DeleteRestore.vue'
import CropIcon from 'vue-material-design-icons/Crop.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import History from 'vue-material-design-icons/History.vue'

defineProps<{
	activeMode: 'rotate' | 'crop' | 'enhance' | 'split'
	totalPages: number
	hasAnyRotation: boolean
	hasAnyCrop: boolean
	hasAnyEnhance: boolean
	hasAnyDeletion: boolean
	deletedPagesCount: number
	selectedPagesCount: number
}>()

const emit = defineEmits<{
	'rotate-all': [deltaDeg: number]
	'open-rotate-modal': []
	'reset-rotations': []
	'open-join-modal': []
	'restore-all-pages': []
	'open-crop-modal': []
	'reset-all-crops': []
	'open-enhance-modal': []
	'reset-all-enhancements': []
	'open-versions-modal': []
	'clear-selection': []
}>()
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
					size="small"
					variant="secondary"
					:title="t('sheetmusic', 'Rotate All 90° CW')"
					:aria-label="t('sheetmusic', 'Rotate All 90° CW')"
					@click="emit('rotate-all', 90)">
					<template #icon>
						<RotateRight :size="16" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Rotate All 90° CW') }}</span>
				</NcButton>
				<NcButton
					size="small"
					variant="secondary"
					:title="t('sheetmusic', 'Rotate All 90° CCW')"
					:aria-label="t('sheetmusic', 'Rotate All 90° CCW')"
					@click="emit('rotate-all', -90)">
					<template #icon>
						<RotateLeft :size="16" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Rotate All 90° CCW') }}</span>
				</NcButton>
				<NcButton
					size="small"
					variant="secondary"
					:title="t('sheetmusic', 'Rotate by custom angle / fine deskew')"
					:aria-label="t('sheetmusic', 'Rotate by custom angle / fine deskew')"
					@click="emit('open-rotate-modal')">
					<template #icon>
						<Rotate360 :size="16" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Rotate by Angle…') }}</span>
				</NcButton>
			</div>

			<div :class="$style.toolbarDivider" />

			<div :class="$style.toolbarGroup">
				<NcButton
					v-if="totalPages >= 2"
					size="small"
					variant="secondary"
					:title="t('sheetmusic', 'Join two pages into a single page')"
					:aria-label="t('sheetmusic', 'Join two pages into a single page')"
					@click="emit('open-join-modal')">
					<template #icon>
						<TableMergeCells :size="16" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Join Pages…') }}</span>
				</NcButton>
				<NcButton
					size="small"
					variant="secondary"
					:title="t('sheetmusic', 'View file version history and revert changes')"
					:aria-label="t('sheetmusic', 'View file version history and revert changes')"
					@click="emit('open-versions-modal')">
					<template #icon>
						<History :size="16" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Version History…') }}</span>
				</NcButton>
			</div>

			<div :class="[$style.toolbarGroup, $style.toolbarGroupEnd]">
				<NcButton
					size="small"
					variant="tertiary"
					:disabled="!hasAnyRotation"
					:title="t('sheetmusic', 'Reset all page rotations')"
					:aria-label="t('sheetmusic', 'Reset all page rotations')"
					@click="emit('reset-rotations')">
					{{ t('sheetmusic', 'Reset All') }}
				</NcButton>
				<NcButton
					v-if="hasAnyDeletion"
					size="small"
					variant="tertiary"
					:title="t('sheetmusic', 'Restore all deleted pages')"
					:aria-label="t('sheetmusic', 'Restore all deleted pages')"
					@click="emit('restore-all-pages')">
					<template #icon>
						<DeleteRestore :size="16" />
					</template>
					{{ t('sheetmusic', 'Restore Deleted ({count})', { count: deletedPagesCount }) }}
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
					size="small"
					variant="secondary"
					:title="t('sheetmusic', 'Crop first page and apply to all')"
					:aria-label="t('sheetmusic', 'Crop first page and apply to all')"
					@click="emit('open-crop-modal')">
					<template #icon>
						<CropIcon :size="16" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Crop All Pages…') }}</span>
				</NcButton>
			</div>

			<div :class="$style.toolbarDivider" />

			<div :class="$style.toolbarGroup">
				<NcButton
					v-if="totalPages >= 2"
					size="small"
					variant="secondary"
					:title="t('sheetmusic', 'Join two pages into a single page')"
					:aria-label="t('sheetmusic', 'Join two pages into a single page')"
					@click="emit('open-join-modal')">
					<template #icon>
						<TableMergeCells :size="16" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Join Pages…') }}</span>
				</NcButton>
				<NcButton
					size="small"
					variant="secondary"
					:title="t('sheetmusic', 'View file version history and revert changes')"
					:aria-label="t('sheetmusic', 'View file version history and revert changes')"
					@click="emit('open-versions-modal')">
					<template #icon>
						<History :size="16" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Version History…') }}</span>
				</NcButton>
			</div>

			<div :class="[$style.toolbarGroup, $style.toolbarGroupEnd]">
				<NcButton
					size="small"
					variant="tertiary"
					:disabled="!hasAnyCrop"
					:title="t('sheetmusic', 'Reset all page crops to full page')"
					:aria-label="t('sheetmusic', 'Reset all page crops to full page')"
					@click="emit('reset-all-crops')">
					{{ t('sheetmusic', 'Reset All Crops') }}
				</NcButton>
				<NcButton
					v-if="hasAnyDeletion"
					size="small"
					variant="tertiary"
					:title="t('sheetmusic', 'Restore all deleted pages')"
					:aria-label="t('sheetmusic', 'Restore all deleted pages')"
					@click="emit('restore-all-pages')">
					<template #icon>
						<DeleteRestore :size="16" />
					</template>
					{{ t('sheetmusic', 'Restore Deleted ({count})', { count: deletedPagesCount }) }}
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
					size="small"
					variant="secondary"
					:title="t('sheetmusic', 'Configure scan enhancement and apply to all pages')"
					:aria-label="t('sheetmusic', 'Configure scan enhancement and apply to all pages')"
					@click="emit('open-enhance-modal')">
					<template #icon>
						<Tune :size="16" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Enhance All Pages…') }}</span>
				</NcButton>
			</div>

			<div :class="$style.toolbarDivider" />

			<div :class="$style.toolbarGroup">
				<NcButton
					size="small"
					variant="secondary"
					:title="t('sheetmusic', 'View file version history and revert changes')"
					:aria-label="t('sheetmusic', 'View file version history and revert changes')"
					@click="emit('open-versions-modal')">
					<template #icon>
						<History :size="16" />
					</template>
					<span :class="$style.btnText">{{ t('sheetmusic', 'Version History…') }}</span>
				</NcButton>
			</div>

			<div :class="[$style.toolbarGroup, $style.toolbarGroupEnd]">
				<NcButton
					size="small"
					variant="tertiary"
					:disabled="!hasAnyEnhance"
					:title="t('sheetmusic', 'Reset all page enhancements')"
					:aria-label="t('sheetmusic', 'Reset all page enhancements')"
					@click="emit('reset-all-enhancements')">
					{{ t('sheetmusic', 'Reset All Enhancements') }}
				</NcButton>
				<NcButton
					v-if="hasAnyDeletion"
					size="small"
					variant="tertiary"
					:title="t('sheetmusic', 'Restore all deleted pages')"
					:aria-label="t('sheetmusic', 'Restore all deleted pages')"
					@click="emit('restore-all-pages')">
					<template #icon>
						<DeleteRestore :size="16" />
					</template>
					{{ t('sheetmusic', 'Restore Deleted ({count})', { count: deletedPagesCount }) }}
				</NcButton>
			</div>
		</template>

		<!-- Split Toolbar -->
		<template v-else-if="activeMode === 'split'">
			<div :class="$style.toolbarGroup">
				<span :class="$style.toolbarTitle">
					{{ t('sheetmusic', 'Select pages in grid to assign to segments:') }}
				</span>
				<span v-if="selectedPagesCount > 0" :class="$style.selectionBadge">
					{{ t('sheetmusic', '{count} pages selected', { count: selectedPagesCount }) }}
				</span>
				<NcButton
					v-if="selectedPagesCount > 0"
					size="small"
					variant="tertiary"
					:title="t('sheetmusic', 'Clear selection')"
					:aria-label="t('sheetmusic', 'Clear selection')"
					@click="emit('clear-selection')">
					{{ t('sheetmusic', 'Clear Selection') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<style module>
.toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 16px;
	background-color: var(--color-background-hover);
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

.toolbarGroupEnd {
	margin-left: auto;
}

.toolbarDivider {
	width: 1px;
	height: 20px;
	background-color: var(--color-border);
	margin: 0 4px;
	flex-shrink: 0;
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

/* Tablet & Mobile responsive styles */
@media (max-width: 900px) {
	.toolbar {
		padding: 6px 12px;
		gap: 8px;
	}

	.toolbarDivider {
		display: none;
	}

	.toolbarGroupEnd {
		margin-left: 0;
	}
}

@media (max-width: 600px) {
	.toolbarTitle {
		display: none;
	}

	.toolbarGroup {
		gap: 4px;
	}
}
</style>
