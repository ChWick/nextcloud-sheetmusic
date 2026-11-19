<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import PageNavControls from '../PageNavControls.vue'
import ZoomControls from '../ZoomControls.vue'
import RestoreIcon from 'vue-material-design-icons/Restore.vue'

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
		<ZoomControls
			:zoom-level="zoomLevel"
			:min-zoom="minZoom"
			:max-zoom="maxZoom"
			zoom-out-test-id="crop-zoom-out"
			zoom-level-test-id="crop-zoom-level"
			zoom-in-test-id="crop-zoom-in"
			fit-test-id="crop-zoom-fit"
			@zoom-out="emit('zoom-out')"
			@zoom-in="emit('zoom-in')"
			@zoom-reset="emit('zoom-reset')" />

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

	.toolActions {
		gap: 4px;
	}
}
</style>
