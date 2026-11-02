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
import BookmarkMusicOutline from 'vue-material-design-icons/BookmarkMusicOutline.vue'
import ContentSaveOutlineIcon from 'vue-material-design-icons/ContentSaveOutline.vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'

export type Mode = 'rotate' | 'crop' | 'enhance' | 'split' | 'bookmarks'

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
	bookmarksCount?: number
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
	if (props.activeMode === 'bookmarks') {
		return (props.bookmarksCount ?? 0) === 0
	}
	return !props.hasAnyChange || props.remainingPagesCount === 0
})

const saveButtonTitle = computed(() => {
	if (props.activeMode === 'bookmarks') {
		return t('sheetmusic', 'Save bookmarks')
	}
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
	if (props.activeMode === 'bookmarks') {
		return t('sheetmusic', 'Save Bookmarks')
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
		<!-- Left: Cancel Button, Song Title, File Details & Resolution Info -->
		<div :class="$style.headerLeft">
			<NcButton
				variant="secondary"
				:title="t('sheetmusic', 'Back to song')"
				:aria-label="t('sheetmusic', 'Back to song')"
				@click="emit('cancel')">
				<template #icon>
					<CloseIcon :size="20" />
				</template>
				<span :class="$style.cancelBtnText">{{ t('sheetmusic', 'Cancel') }}</span>
			</NcButton>
			<div :class="$style.fileMeta">
				<span :class="$style.songTitle" :title="song.title">{{ song.title }}</span>
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

		<!-- Center: Mode Switcher Tabs -->
		<nav :class="$style.modeTabs" :aria-label="t('sheetmusic', 'PDF edit modes')">
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'rotate' && $style.tabButtonActive]"
				:title="t('sheetmusic', 'Rotate Pages')"
				@click="emit('update:activeMode', 'rotate')">
				<RotateRight :size="18" />
				<span :class="$style.tabLabel">{{ t('sheetmusic', 'Rotate Pages') }}</span>
			</button>
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'crop' && $style.tabButtonActive]"
				:title="t('sheetmusic', 'Crop Pages')"
				@click="emit('update:activeMode', 'crop')">
				<CropIcon :size="18" />
				<span :class="$style.tabLabel">{{ t('sheetmusic', 'Crop Pages') }}</span>
			</button>
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'enhance' && $style.tabButtonActive]"
				:title="t('sheetmusic', 'Enhance Scans')"
				@click="emit('update:activeMode', 'enhance')">
				<Tune :size="18" />
				<span :class="$style.tabLabel">{{ t('sheetmusic', 'Enhance Scans') }}</span>
			</button>
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'split' && $style.tabButtonActive]"
				:title="t('sheetmusic', 'Split PDF')"
				@click="emit('update:activeMode', 'split')">
				<CallSplit :size="18" />
				<span :class="$style.tabLabel">{{ t('sheetmusic', 'Split PDF') }}</span>
			</button>
			<button
				type="button"
				:class="[$style.tabButton, activeMode === 'bookmarks' && $style.tabButtonActive]"
				:title="t('sheetmusic', 'Bookmarks')"
				@click="emit('update:activeMode', 'bookmarks')">
				<BookmarkMusicOutline :size="18" />
				<span :class="$style.tabLabel">{{ t('sheetmusic', 'Bookmarks') }}</span>
			</button>
		</nav>

		<!-- Right: Save / Split Button -->
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
	min-width: 0;
}

.headerLeft {
	display: flex;
	align-items: center;
	gap: 12px;
	min-width: 0;
	flex: 1 1 0;
	overflow: hidden;
}

.cancelBtnText {
	display: inline;
}

.fileMeta {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
	flex-shrink: 1;
	overflow: hidden;
}

.songTitle {
	font-weight: bold;
	font-size: 16px;
	color: var(--color-main-text);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 240px;
	flex-shrink: 1;
}

.fileName {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 280px;
	flex-shrink: 2;
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
	min-width: 290px;
	max-width: calc(100vw - 32px);
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

.modeTabs {
	display: flex;
	align-items: center;
	justify-content: center;
	background-color: var(--color-background-hover);
	padding: 3px;
	border-radius: var(--border-radius-large, 8px);
	gap: 2px;
	flex-shrink: 0;
	margin: 0 auto;
}

.tabButton {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 6px 12px;
	border: none;
	background: transparent;
	border-radius: var(--border-radius, 6px);
	cursor: pointer;
	font-size: 13px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	transition: all 0.15s ease-in-out;
	white-space: nowrap;
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

.tabLabel {
	display: inline;
}

.headerRight {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 8px;
	flex: 1 1 0;
	flex-shrink: 0;
	margin-left: auto;
}

/* Tablet & Mobile responsive layout: 2-tier CSS Grid */
@media (max-width: 1400px) {
	.header {
		display: grid;
		grid-template-columns: minmax(0, 1fr) auto;
		grid-template-areas:
			"left right"
			"tabs tabs";
		align-items: center;
		gap: 10px 12px;
		padding: 8px 12px;
	}

	.headerLeft {
		grid-area: left;
		min-width: 0;
		overflow: hidden;
	}

	.headerRight {
		grid-area: right;
		justify-self: end;
		margin-left: 0;
		flex: none;
	}

	.modeTabs {
		grid-area: tabs;
		width: 100%;
		max-width: 100%;
		display: flex;
		box-sizing: border-box;
		margin: 0;
		overflow-x: auto;
		-webkit-overflow-scrolling: touch;
		scrollbar-width: none;
		justify-content: flex-start;
	}

	.modeTabs::-webkit-scrollbar {
		display: none;
	}

	.tabButton {
		flex: 1 0 auto;
		min-height: 38px;
		padding: 6px 8px;
		font-size: 12px;
	}

	.infoPopoverWrap {
		display: none;
	}

	.songTitle {
		max-width: 180px;
	}

	.fileName {
		max-width: 180px;
	}
}

@media (max-width: 600px) {
	.fileName {
		display: none;
	}

	.songTitle {
		max-width: 150px;
		font-size: 14px;
	}

	.modeTabs {
		flex-wrap: wrap;
		gap: 4px;
		overflow-x: visible;
	}

	.tabButton {
		flex: 1 1 calc(50% - 4px);
		min-width: 120px;
		padding: 6px 6px;
	}
}

@media (max-width: 480px) {
	.cancelBtnText {
		display: none;
	}

	.songTitle {
		max-width: 130px;
	}

	.tabButton {
		padding: 6px 4px;
		gap: 4px;
		font-size: 11.5px;
	}
}
</style>
