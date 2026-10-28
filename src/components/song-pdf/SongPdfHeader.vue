<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import type { Song, SongFile } from '../../types/song'
import { getFileName } from '../../utils/file'
import { formatByteSize, type PdfPageResolutionSummary } from './pdf-enhance-engine'

// Icons
import CloseIcon from 'vue-material-design-icons/Close.vue'
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import CropIcon from 'vue-material-design-icons/Crop.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import CallSplit from 'vue-material-design-icons/CallSplit.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

export type Mode = 'rotate' | 'crop' | 'enhance' | 'split'

const props = defineProps<{
	song: Song
	targetFile?: SongFile
	totalPages: number
	remainingPagesCount: number
	hasAnyDeletion: boolean
	activeMode: Mode
	isSaving: boolean
	isLoading: boolean
	hasAnyChange: boolean
	hasAnyRotation?: boolean
	hasAnyCrop?: boolean
	hasAnyEnhance?: boolean
	hasAnyJoin?: boolean
	splitSegmentsCount: number
	saveProgress?: number
	fileSizeBytes?: number | null
	pageResolutions?: PdfPageResolutionSummary[]
}>()

const showInfoDropdown = ref(false)

const formattedFileSize = computed(() => {
	const bytes = props.fileSizeBytes ?? props.targetFile?.fileSize ?? null
	return formatByteSize(bytes)
})

const primaryResolution = computed<PdfPageResolutionSummary | null>(() => {
	return props.pageResolutions?.[0] ?? null
})

const emit = defineEmits<{
	'cancel': []
	'update:activeMode': [mode: Mode]
	'save': []
}>()

const saveButtonDisabled = computed(() => {
	if (props.isSaving || props.isLoading) return true
	if (props.activeMode === 'split') {
		return props.splitSegmentsCount === 0
	}
	return !props.hasAnyChange || props.remainingPagesCount === 0
})

const saveButtonTitle = computed(() => {
	if (props.activeMode === 'rotate') {
		return (props.hasAnyDeletion || props.hasAnyCrop || props.hasAnyEnhance || props.hasAnyJoin)
			? t('sheetmusic', 'Save changes')
			: t('sheetmusic', 'Save rotated PDF')
	}
	if (props.activeMode === 'crop') {
		return (props.hasAnyDeletion || props.hasAnyRotation || props.hasAnyEnhance || props.hasAnyJoin)
			? t('sheetmusic', 'Save changes')
			: t('sheetmusic', 'Save cropped PDF')
	}
	if (props.activeMode === 'enhance') {
		return t('sheetmusic', 'Save enhanced PDF')
	}
	return t('sheetmusic', 'Split and create songs')
})

const saveButtonText = computed(() => {
	if (props.isSaving) {
		const baseText = props.activeMode === 'split' ? t('sheetmusic', 'Processing…') : t('sheetmusic', 'Saving…')
		if (typeof props.saveProgress === 'number' && props.saveProgress > 0) {
			return `${baseText} (${Math.round(props.saveProgress)}%)`
		}
		return baseText
	}
	if (props.activeMode === 'rotate') {
		return (props.hasAnyDeletion || props.hasAnyCrop || props.hasAnyEnhance || props.hasAnyJoin)
			? t('sheetmusic', 'Save Changes')
			: t('sheetmusic', 'Save Rotations')
	}
	if (props.activeMode === 'crop') {
		return (props.hasAnyDeletion || props.hasAnyRotation || props.hasAnyEnhance || props.hasAnyJoin)
			? t('sheetmusic', 'Save Changes')
			: t('sheetmusic', 'Save Crops')
	}
	if (props.activeMode === 'enhance') {
		return t('sheetmusic', 'Save Enhanced PDF')
	}
	return t('sheetmusic', 'Split & Create Songs')
})
</script>

<template>
	<header :class="$style.header">
		<div :class="$style.headerLeft">
			<NcButton
				variant="secondary"
				:title="t('sheetmusic', 'Back to song')"
				:aria-label="t('sheetmusic', 'Back to song')"
				@click="emit('cancel')">
				<template #icon>
					<CloseIcon :size="20" />
				</template>
				{{ t('sheetmusic', 'Cancel') }}
			</NcButton>
			<div :class="$style.fileMeta">
				<span :class="$style.songTitle">{{ song.title }}</span>
				<span v-if="targetFile" :class="$style.fileName">
					({{ getFileName(targetFile.path) }} — {{ hasAnyDeletion ? `${remainingPagesCount} / ${totalPages}` : totalPages }} {{ totalPages === 1 ? t('sheetmusic', 'page') : t('sheetmusic', 'pages') }})
				</span>
				<div
					v-if="formattedFileSize !== '—' || primaryResolution"
					:class="$style.infoPopoverWrap">
					<button
						type="button"
						:class="$style.infoBadgeBtn"
						data-test="pdf-modifier-info-trigger"
						:title="t('sheetmusic', 'Show PDF file size and resolution details')"
						@click="showInfoDropdown = !showInfoDropdown">
						<InformationOutline :size="15" />
						<span v-if="formattedFileSize !== '—'">{{ formattedFileSize }}</span>
						<span v-if="formattedFileSize !== '—' && primaryResolution">·</span>
						<span v-if="primaryResolution">
							{{ primaryResolution.dpi }} DPI ({{ primaryResolution.pixelWidth }}×{{ primaryResolution.pixelHeight }} px)
						</span>
					</button>
					<div
						v-if="showInfoDropdown"
						:class="$style.infoDropdown"
						data-test="pdf-modifier-info-dropdown">
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
							<span :class="$style.infoVal" data-test="pdf-info-filesize">
								{{ formattedFileSize }}
								<template v-if="fileSizeBytes"> ({{ fileSizeBytes.toLocaleString() }} B)</template>
							</span>
							<span :class="$style.infoLabel">{{ t('sheetmusic', 'Pages:') }}</span>
							<span :class="$style.infoVal">{{ hasAnyDeletion ? `${remainingPagesCount} / ${totalPages}` : totalPages }}</span>
							<template v-if="primaryResolution">
								<span :class="$style.infoLabel">{{ t('sheetmusic', 'Resolution:') }}</span>
								<span :class="$style.infoVal" data-test="pdf-info-resolution">
									{{ primaryResolution.pixelWidth }} × {{ primaryResolution.pixelHeight }} px ({{ primaryResolution.dpi }} DPI)
								</span>
								<span :class="$style.infoLabel">{{ t('sheetmusic', 'Page size:') }}</span>
								<span :class="$style.infoVal">
									{{ primaryResolution.widthMm }} × {{ primaryResolution.heightMm }} mm ({{ Math.round(primaryResolution.widthPt) }} × {{ Math.round(primaryResolution.heightPt) }} pt)
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
		</div>

		<!-- Mode Switcher Tabs -->
		<div :class="$style.modeTabs">
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'rotate' && $style.tabButtonActive]"
				@click="emit('update:activeMode', 'rotate')">
				<RotateRight :size="18" />
				<span>{{ t('sheetmusic', 'Rotate Pages') }}</span>
			</button>
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'crop' && $style.tabButtonActive]"
				@click="emit('update:activeMode', 'crop')">
				<CropIcon :size="18" />
				<span>{{ t('sheetmusic', 'Crop Pages') }}</span>
			</button>
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'enhance' && $style.tabButtonActive]"
				@click="emit('update:activeMode', 'enhance')">
				<Tune :size="18" />
				<span>{{ t('sheetmusic', 'Enhance Scans') }}</span>
			</button>
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'split' && $style.tabButtonActive]"
				@click="emit('update:activeMode', 'split')">
				<CallSplit :size="18" />
				<span>{{ t('sheetmusic', 'Split PDF') }}</span>
			</button>
		</div>

		<div :class="$style.headerRight">
			<NcButton
				variant="primary"
				:disabled="saveButtonDisabled"
				:title="saveButtonTitle"
				@click="emit('save')">
				<template #icon>
					<CallSplit v-if="activeMode === 'split'" :size="20" />
					<ContentSaveOutlineIcon v-else :size="20" />
				</template>
				{{ saveButtonText }}
			</NcButton>
		</div>
	</header>
</template>

<style module>
.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 16px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-main-background);
	gap: 16px;
	flex-shrink: 0;
}

.headerLeft {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
}

.fileMeta {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.infoPopoverWrap {
	position: relative;
	display: inline-flex;
	align-items: center;
	flex-shrink: 0;
}

.infoBadgeBtn {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 12px;
	border: 1px solid var(--color-border);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 12px;
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

.songTitle {
	font-weight: bold;
	font-size: 16px;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
}

.fileName {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
}

.modeTabs {
	display: flex;
	align-items: center;
	background-color: var(--color-background-hover);
	padding: 3px;
	border-radius: 8px;
	gap: 2px;
}

.tabButton {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border: none;
	background: transparent;
	border-radius: 6px;
	cursor: pointer;
	font-size: 13px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	transition: all 0.15s ease-in-out;
}

.tabButton:hover {
	color: var(--color-main-text);
}

.tabButtonActive {
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: 600;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.headerRight {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>

