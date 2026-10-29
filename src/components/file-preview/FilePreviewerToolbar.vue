<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FitToScreenOutline from 'vue-material-design-icons/FitToScreenOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import MagnifyMinus from 'vue-material-design-icons/MagnifyMinus.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import ViewDay from 'vue-material-design-icons/ViewDay.vue'
import ViewStream from 'vue-material-design-icons/ViewStream.vue'
import { formatByteSize, type PdfPageResolutionSummary } from '../song-pdf/pdf-enhance-engine'

type PageLayoutMode = 'continuous' | 'single' | 'double'

const props = withDefaults(defineProps<{
	isPdf: boolean
	currentPage: number
	totalPages: number
	currentPageDisplay: string
	pageLayout: PageLayoutMode
	zoomSelectValue: string
	isCustomZoom: boolean
	displayScale: number
	showExternalButton?: boolean
	fileSizeBytes?: number | null
	pageResolutions?: PdfPageResolutionSummary[]
}>(), {
	showExternalButton: true,
	fileSizeBytes: null,
	pageResolutions: () => [],
})

const showInfoDropdown = ref(false)

const formattedFileSize = computed(() => formatByteSize(props.fileSizeBytes))

const activePageResolution = computed<PdfPageResolutionSummary | null>(() => {
	if (!props.pageResolutions || props.pageResolutions.length === 0) return null
	return (
		props.pageResolutions.find((p) => p.pageNum === props.currentPage) ??
		props.pageResolutions[0] ??
		null
	)
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

				<div
					v-if="formattedFileSize !== '—' || activePageResolution"
					:class="$style.infoPopoverWrap">
					<button
						type="button"
						:class="$style.infoBadgeBtn"
						data-test="pdf-file-info-trigger"
						:title="t('sheetmusic', 'Show PDF file size and resolution details')"
						@click="showInfoDropdown = !showInfoDropdown">
						<InformationOutline :size="15" />
						<span v-if="formattedFileSize !== '—'">{{ formattedFileSize }}</span>
						<span v-if="formattedFileSize !== '—' && activePageResolution">·</span>
						<span v-if="activePageResolution">
							{{ activePageResolution.dpi }} DPI ({{ activePageResolution.pixelWidth }}×{{ activePageResolution.pixelHeight }} px)
						</span>
					</button>
					<div
						v-if="showInfoDropdown"
						:class="$style.infoDropdown"
						data-test="pdf-file-info-dropdown">
						<div :class="$style.infoDropdownHeader">
							<strong>{{ t('sheetmusic', 'PDF File & Resolution Info') }}</strong>
							<button
								type="button"
								:class="$style.infoCloseBtn"
								@click="showInfoDropdown = false">
								×
							</button>
						</div>
						<div :class="$style.infoGrid">
							<span :class="$style.infoLabel">{{ t('sheetmusic', 'File size:') }}</span>
							<span :class="$style.infoVal" data-test="pdf-file-info-size">
								{{ formattedFileSize }}
								<template v-if="fileSizeBytes"> ({{ fileSizeBytes.toLocaleString() }} B)</template>
							</span>
							<span :class="$style.infoLabel">{{ t('sheetmusic', 'Pages:') }}</span>
							<span :class="$style.infoVal">{{ totalPages }}</span>
							<template v-if="activePageResolution">
								<span :class="$style.infoLabel">{{ t('sheetmusic', 'Resolution:') }}</span>
								<span :class="$style.infoVal" data-test="pdf-file-info-resolution">
									{{ activePageResolution.pixelWidth }} × {{ activePageResolution.pixelHeight }} px ({{ activePageResolution.dpi }} DPI)
								</span>
								<span :class="$style.infoLabel">{{ t('sheetmusic', 'Page size:') }}</span>
								<span :class="$style.infoVal">
									{{ activePageResolution.widthMm }} × {{ activePageResolution.heightMm }} mm ({{ Math.round(activePageResolution.widthPt) }} × {{ Math.round(activePageResolution.heightPt) }} pt)
								</span>
							</template>
						</div>
						<div
							v-if="pageResolutions && pageResolutions.length > 1"
							:class="$style.perPageList">
							<div :class="$style.perPageTitle">{{ t('sheetmusic', 'Per-Page Resolution') }}</div>
							<div
								v-for="pr in pageResolutions"
								:key="pr.pageNum"
								:class="$style.perPageRow">
								<span>{{ t('sheetmusic', 'Page {n}', { n: pr.pageNum }) }}</span>
								<span>{{ pr.pixelWidth }}×{{ pr.pixelHeight }} px · {{ pr.dpi }} DPI · {{ pr.widthMm }}×{{ pr.heightMm }} mm</span>
							</div>
						</div>
					</div>
				</div>
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

.infoPopoverWrap {
	position: relative;
	display: inline-flex;
	align-items: center;
	margin-left: 4px;
}

.infoBadgeBtn {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 3px 9px;
	border-radius: 12px;
	border: 1px solid var(--color-border);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 0.8rem;
	cursor: pointer;
	white-space: nowrap;
}

.infoBadgeBtn:hover {
	background: var(--color-background-dark);
}

.infoDropdown {
	position: absolute;
	top: calc(100% + 6px);
	left: 0;
	z-index: 200;
	min-width: 310px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	box-shadow: 0 6px 20px rgba(0, 0, 0, 0.18);
	padding: 10px 12px;
	font-size: 12px;
	color: var(--color-main-text);
	white-space: normal;
}

.infoDropdownHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 6px;
	margin-bottom: 8px;
}

.infoCloseBtn {
	border: none;
	background: transparent;
	cursor: pointer;
	font-size: 16px;
	line-height: 1;
	color: var(--color-text-maxcontrast);
	padding: 0 4px;
}

.infoGrid {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 4px 10px;
}

.infoLabel {
	color: var(--color-text-maxcontrast);
}

.infoVal {
	font-weight: 600;
}

.perPageList {
	margin-top: 8px;
	padding-top: 6px;
	border-top: 1px solid var(--color-border);
	max-height: 150px;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 3px;
}

.perPageTitle {
	font-weight: 600;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.perPageRow {
	display: flex;
	justify-content: space-between;
	gap: 8px;
	font-size: 11px;
}
</style>
