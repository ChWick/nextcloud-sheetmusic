<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowExpandHorizontalIcon from 'vue-material-design-icons/ArrowExpandHorizontal.vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import CompareHorizontalIcon from 'vue-material-design-icons/CompareHorizontal.vue'
import FitToPageOutlineIcon from 'vue-material-design-icons/FitToPageOutline.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import SkipNextIcon from 'vue-material-design-icons/SkipNext.vue'
import SkipPreviousIcon from 'vue-material-design-icons/SkipPrevious.vue'
import ViewGridOutlineIcon from 'vue-material-design-icons/ViewGridOutline.vue'
import DoublePageTurnModeSelector from '../file-preview/DoublePageTurnModeSelector.vue'
import type { DoublePageTurnMode } from '../../utils/page-turn'
import type { PerformanceLayoutChoice, PerformanceZoomChoice } from '../../utils/performance-settings'

withDefaults(defineProps<{
	visible: boolean
	hasPrevSong: boolean
	hasNextSong: boolean
	canGoPrevPage: boolean
	canGoNextPage: boolean
	currentPageDisplay: string
	totalPages: number
	layoutChoice: PerformanceLayoutChoice
	effectiveLayout: 'continuous' | 'single' | 'double'
	doublePageTurnMode: DoublePageTurnMode
	zoomChoice: PerformanceZoomChoice
	interleaveSongs?: boolean
	isPagePickerOpen?: boolean
	isDrawerOpen?: boolean
}>(), {
	interleaveSongs: false,
	isPagePickerOpen: false,
	isDrawerOpen: false,
})

const emit = defineEmits<{
	prevSong: []
	prevPage: []
	nextPage: []
	nextSong: []
	changeLayout: [choice: PerformanceLayoutChoice]
	changeDoublePageTurnMode: [mode: DoublePageTurnMode]
	toggleZoom: []
	toggleInterleave: []
	togglePagePicker: []
	toggleDrawer: []
	pauseAutoHide: []
	resumeAutoHide: []
}>()
</script>

<template>
	<footer
		v-show="visible"
		:class="$style.bottomBar"
		@mouseenter="emit('pauseAutoHide')"
		@mouseleave="emit('resumeAutoHide')"
		@focusin="emit('pauseAutoHide')"
		@focusout="emit('resumeAutoHide')">
		<div :class="$style.navGroup">
			<NcButton
				variant="secondary"
				size="small"
				:disabled="!hasPrevSong"
				:title="t('sheetmusic', 'Previous song')"
				:aria-label="t('sheetmusic', 'Previous song')"
				@click="emit('prevSong')">
				<template #icon><SkipPreviousIcon :size="20" /></template>
			</NcButton>
			<NcButton
				variant="secondary"
				size="small"
				:disabled="!hasPrevSong && !canGoPrevPage"
				:title="t('sheetmusic', 'Previous page')"
				:aria-label="t('sheetmusic', 'Previous page')"
				@click="emit('prevPage')">
				<template #icon><ChevronLeftIcon :size="20" /></template>
			</NcButton>
			<span :class="$style.pageStatus">
				{{ t('sheetmusic', 'Page {current} of {total}', { current: currentPageDisplay, total: totalPages || 1 }, { escape: false }) }}
			</span>
			<NcButton
				v-if="totalPages > 1 || hasPrevSong || hasNextSong"
				:variant="isPagePickerOpen ? 'primary' : 'secondary'"
				size="small"
				:aria-pressed="isPagePickerOpen"
				:aria-label="t('sheetmusic', 'Jump to page')"
				:title="t('sheetmusic', 'Jump to page')"
				@click="emit('togglePagePicker')">
				<template #icon><ViewGridOutlineIcon :size="18" /></template>
			</NcButton>
			<NcButton
				:variant="isDrawerOpen ? 'primary' : 'secondary'"
				size="small"
				:aria-pressed="isDrawerOpen"
				:aria-label="t('sheetmusic', 'Setlist items')"
				:title="t('sheetmusic', 'Setlist items')"
				@click="emit('toggleDrawer')">
				<template #icon><FormatListBulletedIcon :size="18" /></template>
			</NcButton>
			<NcButton
				variant="secondary"
				size="small"
				:disabled="!hasNextSong && !canGoNextPage"
				:title="t('sheetmusic', 'Next page')"
				:aria-label="t('sheetmusic', 'Next page')"
				@click="emit('nextPage')">
				<template #icon><ChevronRightIcon :size="20" /></template>
			</NcButton>
			<NcButton
				variant="secondary"
				size="small"
				:disabled="!hasNextSong"
				:title="t('sheetmusic', 'Next song')"
				:aria-label="t('sheetmusic', 'Next song')"
				@click="emit('nextSong')">
				<template #icon><SkipNextIcon :size="20" /></template>
			</NcButton>
		</div>

		<div :class="$style.modeGroup">
			<select
				:value="layoutChoice"
				:aria-label="t('sheetmusic', 'Page layout')"
				:class="$style.selectControl"
				@change="emit('changeLayout', ($event.target as HTMLSelectElement).value as PerformanceLayoutChoice)">
				<option value="auto">
					{{ t('sheetmusic', 'Auto (Tablet)') }}
				</option>
				<option value="single">
					{{ t('sheetmusic', 'Single page') }}
				</option>
				<option value="double">
					{{ t('sheetmusic', 'Two pages') }}
				</option>
				<option value="continuous">
					{{ t('sheetmusic', 'Scroll') }}
				</option>
			</select>

			<DoublePageTurnModeSelector
				v-if="layoutChoice === 'auto' || effectiveLayout === 'double'"
				:model-value="doublePageTurnMode"
				@update:model-value="emit('changeDoublePageTurnMode', $event)" />

			<NcButton
				v-if="layoutChoice === 'auto' || effectiveLayout === 'double'"
				:variant="interleaveSongs ? 'primary' : 'secondary'"
				size="small"
				:aria-pressed="interleaveSongs"
				:aria-label="t('sheetmusic', 'Interleave last page of current song with first page of next song')"
				:title="t('sheetmusic', 'Interleave last page of current song with first page of next song')"
				@click="emit('toggleInterleave')">
				<template #icon><CompareHorizontalIcon :size="18" /></template>
			</NcButton>

			<NcButton
				variant="secondary"
				size="small"
				:aria-label="zoomChoice === 'fit-page' ? t('sheetmusic', 'Fit Page') : t('sheetmusic', 'Fit Width')"
				:title="zoomChoice === 'fit-page' ? t('sheetmusic', 'Fit Page (click for Fit Width)') : t('sheetmusic', 'Fit Width (click for Fit Page)')"
				@click="emit('toggleZoom')">
				<template #icon>
					<FitToPageOutlineIcon v-if="zoomChoice === 'fit-page'" :size="18" />
					<ArrowExpandHorizontalIcon v-else :size="18" />
				</template>
			</NcButton>
		</div>
	</footer>
</template>

<style module>
.bottomBar {
	position: absolute;
	bottom: 0;
	left: 0;
	right: 0;
	z-index: 2;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 16px;
	border-top: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	box-shadow: 0 -2px 12px rgba(0, 0, 0, 0.12);
}

.navGroup {
	display: flex;
	align-items: center;
	gap: 6px;
}

.pageStatus {
	font-size: 0.9rem;
	font-weight: 500;
	padding: 0 8px;
	white-space: nowrap;
}

.modeGroup {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.selectControl {
	height: 32px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
}
</style>
