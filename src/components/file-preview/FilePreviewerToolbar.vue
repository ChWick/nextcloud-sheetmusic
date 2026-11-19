<script setup lang="ts">
import type { Component } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ResponsiveNcButton from '../ResponsiveNcButton.vue'
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FitToScreenOutline from 'vue-material-design-icons/FitToScreenOutline.vue'
import MagnifyMinus from 'vue-material-design-icons/MagnifyMinus.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import ViewDay from 'vue-material-design-icons/ViewDay.vue'
import ViewStream from 'vue-material-design-icons/ViewStream.vue'
import PageNavControls from '../PageNavControls.vue'
import DoublePageTurnModeSelector from './DoublePageTurnModeSelector.vue'
import type { DoublePageTurnMode } from '../../utils/page-turn'

type PageLayoutMode = 'continuous' | 'single' | 'double'

withDefaults(defineProps<{
	isPdf: boolean
	currentPage: number
	totalPages: number
	currentPageDisplay: string
	pageLayout: PageLayoutMode
	doublePageTurnMode?: DoublePageTurnMode
	canGoPrev?: boolean
	canGoNext?: boolean
	zoomSelectValue: string
	isCustomZoom: boolean
	displayScale: number
	showExternalButton?: boolean
	showAnnotations?: boolean
}>(), {
	doublePageTurnMode: 'half',
	canGoPrev: undefined,
	canGoNext: undefined,
	showExternalButton: true,
	showAnnotations: true,
})

const emit = defineEmits<{
	previousPage: []
	nextPage: []
	pageLayout: [mode: PageLayoutMode]
	doublePageTurnMode: [mode: DoublePageTurnMode]
	zoomSelect: [event: Event]
	zoomIn: []
	zoomOut: []
	fitWidth: []
	rotate: []
	openExternal: []
	toggleAnnotations: []
}>()

const PAGE_LAYOUT_OPTIONS: Array<{ mode: PageLayoutMode; label: string; icon: Component }> = [
	{ mode: 'continuous', label: t('sheetmusic', 'Continuous scroll (All pages)'), icon: ViewStream },
	{ mode: 'single', label: t('sheetmusic', 'Single page'), icon: ViewDay },
	{ mode: 'double', label: t('sheetmusic', 'Two pages side-by-side'), icon: ViewColumn },
]
</script>

<template>
	<div :class="$style.toolbar">
		<div :class="$style.toolbarGroup">
			<PageNavControls
				v-if="isPdf"
				:current-page="currentPage"
				:total-pages="totalPages"
				:current-page-display="currentPageDisplay"
				:prev-disabled="pageLayout === 'continuous' || (canGoPrev !== undefined ? !canGoPrev : currentPage <= 1)"
				:next-disabled="pageLayout === 'continuous' || (canGoNext !== undefined ? !canGoNext : currentPage >= totalPages)"
				@prev="emit('previousPage')"
				@next="emit('nextPage')" />
		</div>

		<div v-if="isPdf" :class="$style.toolbarGroup">
			<div :class="$style.segmentedGroup">
				<ResponsiveNcButton
					v-for="opt in PAGE_LAYOUT_OPTIONS"
					:key="opt.mode"
					icon-only
					:variant="pageLayout === opt.mode ? 'primary' : 'secondary'"
					:label="opt.label"
					@click="emit('pageLayout', opt.mode)">
					<template #icon><component :is="opt.icon" :size="20" /></template>
				</ResponsiveNcButton>
			</div>

			<DoublePageTurnModeSelector
				v-if="pageLayout === 'double'"
				:model-value="doublePageTurnMode"
				@update:model-value="emit('doublePageTurnMode', $event)" />
		</div>

		<div :class="$style.toolbarGroup">
			<ResponsiveNcButton
				variant="secondary"
				icon-only
				:label="t('sheetmusic', 'Zoom out')"
				@click="emit('zoomOut')">
				<template #icon><MagnifyMinus :size="20" /></template>
			</ResponsiveNcButton>

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

			<ResponsiveNcButton
				variant="secondary"
				icon-only
				:label="t('sheetmusic', 'Zoom in')"
				@click="emit('zoomIn')">
				<template #icon><MagnifyPlus :size="20" /></template>
			</ResponsiveNcButton>

			<ResponsiveNcButton
				variant="secondary"
				icon-only
				:label="t('sheetmusic', 'Fit to width')"
				@click="emit('fitWidth')">
				<template #icon><FitToScreenOutline :size="20" /></template>
			</ResponsiveNcButton>

			<ResponsiveNcButton
				variant="secondary"
				icon-only
				:label="t('sheetmusic', 'Rotate clockwise')"
				@click="emit('rotate')">
				<template #icon><RotateRight :size="20" /></template>
			</ResponsiveNcButton>

			<ResponsiveNcButton
				v-if="isPdf"
				variant="secondary"
				icon-only
				:label="showAnnotations ? t('sheetmusic', 'Hide annotations') : t('sheetmusic', 'Show annotations')"
				data-test="toggle-annotations-button"
				@click="emit('toggleAnnotations')">
				<template #icon>
					<EyeOutline v-if="showAnnotations" :size="20" />
					<EyeOffOutline v-else :size="20" />
				</template>
			</ResponsiveNcButton>

			<ResponsiveNcButton
				v-if="showExternalButton"
				variant="secondary"
				icon-only
				:label="t('sheetmusic', 'Open in external viewer')"
				@click="emit('openExternal')">
				<template #icon><OpenInNewIcon :size="20" /></template>
			</ResponsiveNcButton>
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
	border-top: 1px solid var(--color-border);
	gap: 12px;
	flex-wrap: wrap;
}

.toolbarGroup {
	display: flex;
	align-items: center;
	gap: 6px;
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
	height: var(--default-clickable-area, 44px);
	padding: 0 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.85rem;
	cursor: pointer;
}
</style>
