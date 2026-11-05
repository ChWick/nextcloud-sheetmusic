<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FitToScreenOutline from 'vue-material-design-icons/FitToScreenOutline.vue'
import MagnifyMinus from 'vue-material-design-icons/MagnifyMinus.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import ViewDay from 'vue-material-design-icons/ViewDay.vue'
import ViewStream from 'vue-material-design-icons/ViewStream.vue'

type PageLayoutMode = 'continuous' | 'single' | 'double'

withDefaults(defineProps<{
	isPdf: boolean
	currentPage: number
	totalPages: number
	currentPageDisplay: string
	pageLayout: PageLayoutMode
	zoomSelectValue: string
	isCustomZoom: boolean
	displayScale: number
	showExternalButton?: boolean
}>(), {
	showExternalButton: true,
})

const emit = defineEmits<{
	previousPage: []
	nextPage: []
	pageLayout: [mode: PageLayoutMode]
	zoomSelect: [event: Event]
	zoomIn: []
	zoomOut: []
	fitWidth: []
	rotate: []
	openExternal: []
}>()
</script>

<template>
	<div :class="$style.toolbar">
		<div :class="$style.toolbarGroup">
			<template v-if="isPdf">
				<NcButton
					variant="secondary"
					size="small"
					:disabled="currentPage <= 1 || pageLayout === 'continuous'"
					:title="t('sheetmusic', 'Previous page')"
					:aria-label="t('sheetmusic', 'Previous page')"
					@click="emit('previousPage')">
					<template #icon><ChevronLeft :size="20" /></template>
				</NcButton>
				<span :class="$style.pageIndicator">
					{{ t('sheetmusic', 'Page {current} of {total}', { current: currentPageDisplay, total: totalPages || 1 }) }}
				</span>
				<NcButton
					variant="secondary"
					size="small"
					:disabled="currentPage >= totalPages || pageLayout === 'continuous'"
					:title="t('sheetmusic', 'Next page')"
					:aria-label="t('sheetmusic', 'Next page')"
					@click="emit('nextPage')">
					<template #icon><ChevronRight :size="20" /></template>
				</NcButton>
			</template>
		</div>

		<div v-if="isPdf" :class="$style.toolbarGroup">
			<div :class="$style.segmentedGroup">
				<NcButton
					size="small"
					:variant="pageLayout === 'continuous' ? 'primary' : 'secondary'"
					:title="t('sheetmusic', 'Continuous scroll (All pages)')"
					:aria-label="t('sheetmusic', 'Continuous scroll (All pages)')"
					@click="emit('pageLayout', 'continuous')">
					<template #icon><ViewStream :size="18" /></template>
				</NcButton>
				<NcButton
					size="small"
					:variant="pageLayout === 'single' ? 'primary' : 'secondary'"
					:title="t('sheetmusic', 'Single page')"
					:aria-label="t('sheetmusic', 'Single page')"
					@click="emit('pageLayout', 'single')">
					<template #icon><ViewDay :size="18" /></template>
				</NcButton>
				<NcButton
					size="small"
					:variant="pageLayout === 'double' ? 'primary' : 'secondary'"
					:title="t('sheetmusic', 'Two pages side-by-side')"
					:aria-label="t('sheetmusic', 'Two pages side-by-side')"
					@click="emit('pageLayout', 'double')">
					<template #icon><ViewColumn :size="18" /></template>
				</NcButton>
			</div>
		</div>

		<div :class="$style.toolbarGroup">
			<NcButton
				variant="secondary"
				size="small"
				:title="t('sheetmusic', 'Zoom out')"
				:aria-label="t('sheetmusic', 'Zoom out')"
				@click="emit('zoomOut')">
				<template #icon><MagnifyMinus :size="18" /></template>
			</NcButton>

			<div :class="$style.zoomSelector">
				<select
					:value="zoomSelectValue"
					:aria-label="t('sheetmusic', 'Zoom level')"
					:class="$style.zoomDropdown"
					@change="emit('zoomSelect', $event)">
					<option value="fit-width">{{ t('sheetmusic', 'Fit width') }}</option>
					<option value="fit-page">{{ t('sheetmusic', 'Fit page') }}</option>
					<option value="0.5">50%</option>
					<option value="0.75">75%</option>
					<option value="1.0">100%</option>
					<option value="1.25">125%</option>
					<option value="1.5">150%</option>
					<option value="2.0">200%</option>
					<option v-if="isCustomZoom" value="custom">
						{{ Math.round(displayScale * 100) }}%
					</option>
				</select>
			</div>

			<NcButton
				variant="secondary"
				size="small"
				:title="t('sheetmusic', 'Zoom in')"
				:aria-label="t('sheetmusic', 'Zoom in')"
				@click="emit('zoomIn')">
				<template #icon><MagnifyPlus :size="18" /></template>
			</NcButton>

			<NcButton
				variant="secondary"
				size="small"
				:title="t('sheetmusic', 'Fit to width')"
				:aria-label="t('sheetmusic', 'Fit to width')"
				@click="emit('fitWidth')">
				<template #icon><FitToScreenOutline :size="18" /></template>
			</NcButton>

			<NcButton
				variant="secondary"
				size="small"
				:title="t('sheetmusic', 'Rotate clockwise')"
				:aria-label="t('sheetmusic', 'Rotate clockwise')"
				@click="emit('rotate')">
				<template #icon><RotateRight :size="18" /></template>
			</NcButton>

			<NcButton
				v-if="showExternalButton"
				variant="secondary"
				size="small"
				:title="t('sheetmusic', 'Open in external viewer')"
				:aria-label="t('sheetmusic', 'Open in external viewer')"
				@click="emit('openExternal')">
				<template #icon><OpenInNewIcon :size="18" /></template>
			</NcButton>
		</div>
	</div>
</template>

<style module>
.toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 16px;
	background-color: var(--color-main-background);
	border-bottom: 1px solid var(--color-border);
	gap: 12px;
	flex-wrap: wrap;
}

.toolbarGroup {
	display: flex;
	align-items: center;
	gap: 6px;
}

.pageIndicator {
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	padding: 0 6px;
}

.segmentedGroup {
	display: flex;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	overflow: hidden;
}

.zoomSelector {
	display: flex;
	align-items: center;
}

.zoomDropdown {
	height: 34px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
	cursor: pointer;
}
</style>
