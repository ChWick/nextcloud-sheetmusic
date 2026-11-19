<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import PageNavControls from '../PageNavControls.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import MagnifyMinus from 'vue-material-design-icons/MagnifyMinus.vue'
import FitToScreenOutline from 'vue-material-design-icons/FitToScreenOutline.vue'

withDefaults(
	defineProps<{
		pageNum: number
		totalPages: number
		zoomLevel?: number
		minZoom?: number
		maxZoom?: number
		isCustomCropped?: boolean
	}>(),
	{
		zoomLevel: 1.0,
		minZoom: 0.5,
		maxZoom: 3.0,
		isCustomCropped: false,
	},
)

const emit = defineEmits<{
	'change-page': [page: number]
	'zoom-in': []
	'zoom-out': []
	'zoom-reset': []
	'apply-preset': [percent: number]
	'reset-crop': []
}>()
</script>

<template>
	<div :class="$style.toolBar">
		<!-- Page Navigation -->
		<PageNavControls
			:current-page="pageNum"
			:total-pages="totalPages"
			icon-style="arrow"
			@prev="emit('change-page', pageNum - 1)"
			@next="emit('change-page', pageNum + 1)" />

		<!-- Zoom & Pan Controls -->
		<div :class="$style.zoomControls">
			<NcButton
				variant="secondary"
				:disabled="zoomLevel <= minZoom"
				:title="t('sheetmusic', 'Zoom out')"
				:aria-label="t('sheetmusic', 'Zoom out')"
				data-test="crop-zoom-out"
				@click="emit('zoom-out')">
				<template #icon>
					<MagnifyMinus :size="20" />
				</template>
			</NcButton>
			<span :class="$style.zoomIndicator" data-test="crop-zoom-level">
				{{ Math.round(zoomLevel * 100) }}%
			</span>
			<NcButton
				variant="secondary"
				:disabled="zoomLevel >= maxZoom"
				:title="t('sheetmusic', 'Zoom in')"
				:aria-label="t('sheetmusic', 'Zoom in')"
				data-test="crop-zoom-in"
				@click="emit('zoom-in')">
				<template #icon>
					<MagnifyPlus :size="20" />
				</template>
			</NcButton>
			<NcButton
				variant="tertiary"
				:disabled="zoomLevel === 1"
				:title="t('sheetmusic', 'Fit to screen')"
				:aria-label="t('sheetmusic', 'Fit to screen')"
				data-test="crop-zoom-fit"
				@click="emit('zoom-reset')">
				<template #icon>
					<FitToScreenOutline :size="20" />
				</template>
				{{ t('sheetmusic', 'Fit') }}
			</NcButton>
		</div>

		<!-- Crop Margin Presets & Reset -->
		<div :class="$style.toolActions">
			<NcButton
				variant="secondary"
				:title="t('sheetmusic', 'Trim 5% margin from all edges')"
				@click="emit('apply-preset', 5)">
				{{ t('sheetmusic', 'Trim 5%') }}
			</NcButton>
			<NcButton
				variant="secondary"
				:title="t('sheetmusic', 'Trim 10% margin from all edges')"
				@click="emit('apply-preset', 10)">
				{{ t('sheetmusic', 'Trim 10%') }}
			</NcButton>
			<NcButton
				variant="tertiary"
				:disabled="!isCustomCropped"
				:title="t('sheetmusic', 'Reset to full page')"
				:aria-label="t('sheetmusic', 'Reset to full page')"
				@click="emit('reset-crop')">
				<template #icon>
					<RestoreIcon :size="20" />
				</template>
				{{ t('sheetmusic', 'Reset to Full Page') }}
			</NcButton>
		</div>
	</div>
</template>

<style module>
.toolBar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 6px 10px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large, 8px);
	gap: 10px;
	flex-wrap: wrap;
	flex-shrink: 0;
	z-index: 2;
}

.pageNav {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.pageIndicator {
	font-size: 13px;
	font-weight: 600;
	color: var(--color-main-text);
	white-space: nowrap;
}

.zoomControls {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.zoomIndicator {
	font-size: 12px;
	font-weight: 600;
	min-width: 44px;
	text-align: center;
	color: var(--color-main-text);
}

.toolActions {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}

@media (max-width: 768px) {
	.toolBar {
		padding: 4px 6px;
		gap: 6px;
	}

	.pageIndicator {
		font-size: 12px;
	}

	.toolActions {
		gap: 4px;
	}
}
</style>
