<script setup lang="ts">
import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ArrowExpandHorizontalIcon from 'vue-material-design-icons/ArrowExpandHorizontal.vue'
import ChevronLeftIcon from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import CompareHorizontalIcon from 'vue-material-design-icons/CompareHorizontal.vue'
import CropIcon from 'vue-material-design-icons/Crop.vue'
import FitToPageOutlineIcon from 'vue-material-design-icons/FitToPageOutline.vue'
import FormatListBulletedIcon from 'vue-material-design-icons/FormatListBulleted.vue'
import SkipNextIcon from 'vue-material-design-icons/SkipNext.vue'
import SkipPreviousIcon from 'vue-material-design-icons/SkipPrevious.vue'
import ViewGridOutlineIcon from 'vue-material-design-icons/ViewGridOutline.vue'
import DoublePageTurnModeSelector from '../file-preview/DoublePageTurnModeSelector.vue'
import NightModeSplitButton from '../song-viewer/NightModeSplitButton.vue'
import PerformanceAutoScrollControl from './PerformanceAutoScrollControl.vue'
import type { AutoScrollMode } from '../../composables/useAutoScroll'
import type { DoublePageTurnMode } from '../../utils/page-turn'
import type { PerformanceLayoutChoice, PerformanceZoomChoice, ScoreColorMode } from '../../utils/performance-settings'

const props = withDefaults(defineProps<{
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
	tightCrop?: boolean
	scoreColorMode?: ScoreColorMode
	scoreBrightness?: number
	scoreSepia?: number
	isAppearancePopoverOpen?: boolean
	autoScrollMode?: AutoScrollMode
	autoScrollSpeed?: number
	autoScrollDelay?: number
	autoScrollRunning?: boolean
	autoScrollPaused?: boolean
	autoScrollCountdown?: number
	songDurationSeconds?: number | null
	isAutoScrollPopoverOpen?: boolean
	isPagePickerOpen?: boolean
	isDrawerOpen?: boolean
	showSetlistControls?: boolean
}>(), {
	interleaveSongs: false,
	tightCrop: false,
	scoreColorMode: 'normal',
	scoreBrightness: 100,
	scoreSepia: 0,
	isAppearancePopoverOpen: undefined,
	autoScrollMode: 'speed',
	autoScrollSpeed: 35,
	autoScrollDelay: 0,
	autoScrollRunning: false,
	autoScrollPaused: false,
	autoScrollCountdown: 0,
	songDurationSeconds: null,
	isAutoScrollPopoverOpen: undefined,
	isPagePickerOpen: false,
	isDrawerOpen: false,
	showSetlistControls: true,
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
	toggleTightCrop: []
	toggleNightMode: []
	'update:scoreColorMode': [mode: ScoreColorMode]
	'update:scoreBrightness': [brightness: number]
	'update:scoreSepia': [sepia: number]
	'update:appearancePopoverOpen': [open: boolean]
	toggleAutoScroll: []
	resetAutoScrollTop: []
	'update:autoScrollMode': [mode: AutoScrollMode]
	'update:autoScrollSpeed': [speed: number]
	adjustAutoScrollSpeed: [delta: number]
	'update:autoScrollDelay': [delay: number]
	'update:autoScrollPopoverOpen': [open: boolean]
	togglePagePicker: []
	toggleDrawer: []
	pauseAutoHide: []
	resumeAutoHide: []
}>()

interface LayoutOption {
	value: PerformanceLayoutChoice
	label: string
}

const layoutOptions = computed<LayoutOption[]>(() => [
	{ value: 'auto', label: t('sheetmusic', 'Auto (Tablet)') },
	{ value: 'single', label: t('sheetmusic', 'Single page') },
	{ value: 'double', label: t('sheetmusic', 'Two pages') },
	{ value: 'continuous', label: t('sheetmusic', 'Scroll') },
])

const selectedLayoutOption = computed<LayoutOption>({
	get: () => layoutOptions.value.find((o) => o.value === props.layoutChoice) ?? layoutOptions.value[0],
	set: (opt) => {
		if (opt?.value) {
			emit('changeLayout', opt.value)
		}
	},
})
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
				v-if="showSetlistControls"
				variant="secondary"
				:disabled="!hasPrevSong"
				:title="t('sheetmusic', 'Previous song')"
				:aria-label="t('sheetmusic', 'Previous song')"
				@click="emit('prevSong')">
				<template #icon><SkipPreviousIcon :size="20" /></template>
			</NcButton>
			<NcButton
				variant="secondary"
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
				variant="secondary"
				:disabled="!hasNextSong && !canGoNextPage"
				:title="t('sheetmusic', 'Next page')"
				:aria-label="t('sheetmusic', 'Next page')"
				@click="emit('nextPage')">
				<template #icon><ChevronRightIcon :size="20" /></template>
			</NcButton>
			<NcButton
				v-if="showSetlistControls"
				variant="secondary"
				:disabled="!hasNextSong"
				:title="t('sheetmusic', 'Next song')"
				:aria-label="t('sheetmusic', 'Next song')"
				@click="emit('nextSong')">
				<template #icon><SkipNextIcon :size="20" /></template>
			</NcButton>
			<NcButton
				v-if="totalPages > 1 || hasPrevSong || hasNextSong"
				:variant="isPagePickerOpen ? 'primary' : 'secondary'"
				:aria-pressed="isPagePickerOpen"
				:aria-label="t('sheetmusic', 'Jump to page')"
				:title="t('sheetmusic', 'Jump to page')"
				@click="emit('togglePagePicker')">
				<template #icon><ViewGridOutlineIcon :size="20" /></template>
			</NcButton>
			<NcButton
				v-if="showSetlistControls"
				:variant="isDrawerOpen ? 'primary' : 'secondary'"
				:aria-pressed="isDrawerOpen"
				:aria-label="t('sheetmusic', 'Setlist items')"
				:title="t('sheetmusic', 'Setlist items')"
				@click="emit('toggleDrawer')">
				<template #icon><FormatListBulletedIcon :size="20" /></template>
			</NcButton>
		</div>

		<div :class="$style.modeGroup">
			<NcSelect
				v-model="selectedLayoutOption"
				:options="layoutOptions"
				label="label"
				placement="top"
				:clearable="false"
				:input-label="t('sheetmusic', 'Page layout')"
				:class="$style.layoutSelect"
				data-test="performance-layout-select"
				@open="emit('pauseAutoHide')"
				@close="emit('resumeAutoHide')" />

			<DoublePageTurnModeSelector
				v-if="layoutChoice === 'auto' || effectiveLayout === 'double'"
				:model-value="doublePageTurnMode"
				@update:model-value="emit('changeDoublePageTurnMode', $event)" />

			<NcButton
				v-if="showSetlistControls && (layoutChoice === 'auto' || effectiveLayout === 'double')"
				:variant="interleaveSongs ? 'primary' : 'secondary'"
				:aria-pressed="interleaveSongs"
				:aria-label="t('sheetmusic', 'Interleave last page of current song with first page of next song')"
				:title="t('sheetmusic', 'Interleave last page of current song with first page of next song')"
				@click="emit('toggleInterleave')">
				<template #icon><CompareHorizontalIcon :size="20" /></template>
			</NcButton>

			<NcButton
				:variant="tightCrop ? 'primary' : 'secondary'"
				:aria-pressed="tightCrop"
				:aria-label="t('sheetmusic', 'Tight on-the-fly crop')"
				:title="t('sheetmusic', 'Tight on-the-fly crop')"
				data-test="performance-bottom-crop-toggle"
				@click="emit('toggleTightCrop')">
				<template #icon><CropIcon :size="20" /></template>
			</NcButton>

			<PerformanceAutoScrollControl
				:open="isAutoScrollPopoverOpen"
				:mode="autoScrollMode"
				:speed-px-per-sec="autoScrollSpeed"
				:start-delay-seconds="autoScrollDelay"
				:is-running="autoScrollRunning"
				:is-paused="autoScrollPaused"
				:countdown-remaining="autoScrollCountdown"
				:song-duration-seconds="songDurationSeconds"
				@toggle="emit('toggleAutoScroll')"
				@reset-to-top="emit('resetAutoScrollTop')"
				@update:mode="emit('update:autoScrollMode', $event)"
				@update:speed-px-per-sec="emit('update:autoScrollSpeed', $event)"
				@adjust-speed="emit('adjustAutoScrollSpeed', $event)"
				@update:start-delay-seconds="emit('update:autoScrollDelay', $event)"
				@update:open="emit('update:autoScrollPopoverOpen', $event)" />

			<NightModeSplitButton
				:open="isAppearancePopoverOpen"
				:score-color-mode="scoreColorMode"
				:score-brightness="scoreBrightness"
				:score-sepia="scoreSepia"
				toggle-test-id="performance-bottom-night-toggle"
				@toggle-night-mode="emit('toggleNightMode')"
				@update:score-color-mode="emit('update:scoreColorMode', $event)"
				@update:score-brightness="emit('update:scoreBrightness', $event)"
				@update:score-sepia="emit('update:scoreSepia', $event)"
				@update:open="emit('update:appearancePopoverOpen', $event)" />

			<NcButton
				variant="secondary"
				:aria-label="zoomChoice === 'fit-page' ? t('sheetmusic', 'Fit Page') : t('sheetmusic', 'Fit Width')"
				:title="zoomChoice === 'fit-page' ? t('sheetmusic', 'Fit Page (click for Fit Width)') : t('sheetmusic', 'Fit Width (click for Fit Page)')"
				@click="emit('toggleZoom')">
				<template #icon>
					<FitToPageOutlineIcon v-if="zoomChoice === 'fit-page'" :size="20" />
					<ArrowExpandHorizontalIcon v-else :size="20" />
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
	z-index: 30;
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

.layoutSelect {
	width: 156px;
	min-width: 146px;
}

.layoutSelect :deep(.vs__selected),
.layoutSelect :deep(.vs__search) {
	color: var(--color-main-text);
}

.layoutSelect :deep(.vs__open-indicator) {
	fill: var(--color-main-text);
}

:global(.vs__dropdown-menu) {
	z-index: 100030 !important;
}
</style>
