<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import FitToScreenOutline from 'vue-material-design-icons/FitToScreenOutline.vue'
import MagnifyMinus from 'vue-material-design-icons/MagnifyMinus.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import ResponsiveNcButton from './ResponsiveNcButton.vue'

withDefaults(
	defineProps<{
		zoomLevel: number
		minZoom?: number
		maxZoom?: number
		fitDisabled?: boolean
		fitTitle?: string
		zoomOutTestId?: string
		zoomLevelTestId?: string
		zoomInTestId?: string
		fitTestId?: string
	}>(),
	{
		minZoom: 0.5,
		maxZoom: 3.0,
		fitDisabled: undefined,
		fitTitle: undefined,
		zoomOutTestId: undefined,
		zoomLevelTestId: undefined,
		zoomInTestId: undefined,
		fitTestId: undefined,
	},
)

const emit = defineEmits<{
	'zoom-out': []
	'zoom-in': []
	'zoom-reset': []
}>()
</script>

<template>
	<div :class="$style.zoomControls">
		<ResponsiveNcButton
			variant="secondary"
			icon-only
			:disabled="zoomLevel <= minZoom"
			:label="t('sheetmusic', 'Zoom out')"
			:data-test="zoomOutTestId"
			@click="emit('zoom-out')">
			<template #icon>
				<MagnifyMinus :size="20" />
			</template>
		</ResponsiveNcButton>
		<span :class="$style.zoomIndicator" :data-test="zoomLevelTestId">
			{{ Math.round(zoomLevel * 100) }}%
		</span>
		<ResponsiveNcButton
			variant="secondary"
			icon-only
			:disabled="zoomLevel >= maxZoom"
			:label="t('sheetmusic', 'Zoom in')"
			:data-test="zoomInTestId"
			@click="emit('zoom-in')">
			<template #icon>
				<MagnifyPlus :size="20" />
			</template>
		</ResponsiveNcButton>
		<NcButton
			variant="tertiary"
			:disabled="fitDisabled ?? zoomLevel === 1"
			:title="fitTitle ?? t('sheetmusic', 'Fit to screen')"
			:aria-label="fitTitle ?? t('sheetmusic', 'Fit to screen')"
			:data-test="fitTestId"
			@click="emit('zoom-reset')">
			<template #icon>
				<FitToScreenOutline :size="20" />
			</template>
			{{ t('sheetmusic', 'Fit') }}
		</NcButton>
	</div>
</template>

<style module>
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
</style>
