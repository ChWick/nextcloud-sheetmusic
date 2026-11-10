<script setup lang="ts">
import type { SongFile } from '../../types/song'
import type { PdfPageResolutionSummary } from '../song-pdf/pdf-enhance-engine'

import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { getFileName } from '../../utils/file'
import { formatByteSize } from '../song-pdf/pdf-enhance-engine'

const props = withDefaults(
	defineProps<{
		file: SongFile
		isPdf: boolean
		currentPage?: number
		totalPages?: number
		fileSizeBytes?: number | null
		pageResolutions?: PdfPageResolutionSummary[]
		showAnnotations?: boolean
		canWrite?: boolean
	}>(),
	{
		currentPage: 1,
		totalPages: 1,
		fileSizeBytes: null,
		pageResolutions: () => [],
		showAnnotations: false,
		canWrite: true,
	},
)

const emit = defineEmits<{
	'open-external': []
	'modify-pdf': []
	'open-versions': []
	'toggle-annotations': []
}>()

const showInfoDropdown = ref(false)

const effectiveSizeBytes = computed(() => props.fileSizeBytes ?? props.file?.fileSize ?? null)
const formattedFileSize = computed(() => formatByteSize(effectiveSizeBytes.value))
const fileName = computed(() => getFileName(props.file.path))

const activePageResolution = computed<PdfPageResolutionSummary | null>(() => {
	if (!props.pageResolutions || props.pageResolutions.length === 0) return null
	return (
		props.pageResolutions.find((p) => p.pageNum === props.currentPage) ??
		props.pageResolutions[0] ??
		null
	)
})
</script>

<template>
	<div :class="$style.fileToolbar" data-test="song-file-toolbar">
		<div :class="$style.fileInfoSection">
			<span :class="$style.fileName" :title="file.path">
				<FileDocumentOutline :size="18" :class="$style.fileIcon" />
				<span :class="$style.fileNameText">{{ fileName }}</span>
			</span>

			<div
				v-if="isPdf && (formattedFileSize !== '—' || activePageResolution)"
				:class="$style.infoPopoverWrap">
				<button
					type="button"
					:class="$style.infoBadgeBtn"
					data-test="pdf-file-info-trigger"
					:title="t('sheetmusic', 'Show PDF file size and resolution details')"
					:aria-label="t('sheetmusic', 'Show PDF file size and resolution details')"
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
							<template v-if="effectiveSizeBytes"> ({{ effectiveSizeBytes.toLocaleString() }} B)</template>
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
		</div>

		<div :class="$style.fileActions">
			<NcButton
				size="small"
				variant="secondary"
				:title="t('sheetmusic', 'Open in external viewer')"
				:aria-label="t('sheetmusic', 'Open in external viewer')"
				@click="emit('open-external')">
				<template #icon>
					<OpenInNewIcon :size="18" />
				</template>
			</NcButton>
			<NcButton
				v-if="isPdf"
				size="small"
				variant="secondary"
				:title="showAnnotations ? t('sheetmusic', 'Hide annotations (experimental)') : t('sheetmusic', 'Show annotations (experimental)')"
				:aria-label="showAnnotations ? t('sheetmusic', 'Hide annotations (experimental)') : t('sheetmusic', 'Show annotations (experimental)')"
				data-test="toggle-annotations-button"
				@click="emit('toggle-annotations')">
				<template #icon>
					<EyeOutline v-if="showAnnotations" :size="18" />
					<EyeOffOutline v-else :size="18" />
				</template>
			</NcButton>
			<NcButton
				v-if="isPdf && canWrite !== false"
				size="small"
				variant="secondary"
				:title="t('sheetmusic', 'Modify PDF (rotate, crop, enhance, split)')"
				:aria-label="t('sheetmusic', 'Modify PDF (rotate, crop, enhance, split)')"
				@click="emit('modify-pdf')">
				<template #icon>
					<FileDocumentEditOutline :size="18" />
				</template>
			</NcButton>
			<NcButton
				v-if="isPdf && canWrite !== false"
				size="small"
				variant="secondary"
				:title="t('sheetmusic', 'PDF version history')"
				:aria-label="t('sheetmusic', 'PDF version history')"
				@click="emit('open-versions')">
				<template #icon>
					<History :size="18" />
				</template>
			</NcButton>
		</div>
	</div>
</template>

<style module>
.fileToolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 6px 12px;
	margin-bottom: 8px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	flex-wrap: wrap;
	box-sizing: border-box;
}

.fileInfoSection {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
	flex-wrap: wrap;
}

.fileName {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 0.9rem;
	font-weight: 500;
	color: var(--color-main-text);
	min-width: 0;
}

.fileIcon {
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.fileNameText {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.fileActions {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.infoPopoverWrap {
	position: relative;
	display: inline-flex;
	align-items: center;
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
