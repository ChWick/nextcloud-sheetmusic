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
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import TableMergeCells from 'vue-material-design-icons/TableMergeCells.vue'
import History from 'vue-material-design-icons/History.vue'
import FullscreenIcon from 'vue-material-design-icons/Fullscreen.vue'
import FullscreenExitIcon from 'vue-material-design-icons/FullscreenExit.vue'

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
	isFullscreen?: boolean
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
	'open-page-order-modal': []
	'open-join-modal': []
	'open-versions-modal': []
	'toggle-fullscreen': []
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

const hasStackedOtherEdits = computed(() => {
	const hasDelOrJoin = Boolean(props.hasAnyDeletion || props.hasAnyJoin)
	if (props.activeMode === 'rotate') {
		return hasDelOrJoin || Boolean(props.hasAnyCrop || props.hasAnyEnhance)
	}
	if (props.activeMode === 'crop') {
		return hasDelOrJoin || Boolean(props.hasAnyRotation || props.hasAnyEnhance)
	}
	return hasDelOrJoin || Boolean(props.hasAnyRotation || props.hasAnyCrop)
})

const saveButtonTitle = computed(() => {
	if (props.activeMode === 'bookmarks') return t('sheetmusic', 'Save bookmarks')
	if (props.activeMode === 'split') return t('sheetmusic', 'Split and create songs')
	if (hasStackedOtherEdits.value) return t('sheetmusic', 'Save changes')
	if (props.activeMode === 'rotate') return t('sheetmusic', 'Save rotated PDF')
	if (props.activeMode === 'crop') return t('sheetmusic', 'Save cropped PDF')
	return t('sheetmusic', 'Save enhanced PDF')
})

const saveButtonText = computed(() => {
	if (props.isSaving) {
		const baseText = props.activeMode === 'split' ? t('sheetmusic', 'Processing…') : t('sheetmusic', 'Saving…')
		if (typeof props.saveProgress === 'number' && props.saveProgress > 0) {
			return `${baseText} (${Math.round(props.saveProgress)}%)`
		}
		return baseText
	}
	if (props.activeMode === 'bookmarks') return t('sheetmusic', 'Save Bookmarks')
	if (props.activeMode === 'split') return t('sheetmusic', 'Split & Create Songs')
	if (hasStackedOtherEdits.value) return t('sheetmusic', 'Save Changes')
	if (props.activeMode === 'rotate') return t('sheetmusic', 'Save Rotations')
	if (props.activeMode === 'crop') return t('sheetmusic', 'Save Crops')
	return t('sheetmusic', 'Save Enhanced PDF')
})
const modeTabItems = computed(() => [
	{
		id: 'rotate' as Mode,
		label: t('sheetmusic', 'Rotate Pages'),
		icon: RotateRight,
		dirty: Boolean(props.hasAnyRotation || props.hasAnyDeletion),
		count: 0,
	},
	{
		id: 'crop' as Mode,
		label: t('sheetmusic', 'Crop Pages'),
		icon: CropIcon,
		dirty: Boolean(props.hasAnyCrop),
		count: 0,
	},
	{
		id: 'enhance' as Mode,
		label: t('sheetmusic', 'Enhance Scans'),
		icon: Tune,
		dirty: Boolean(props.hasAnyEnhance),
		count: 0,
	},
	{
		id: 'split' as Mode,
		label: t('sheetmusic', 'Split PDF'),
		icon: CallSplit,
		dirty: false,
		count: props.splitSegmentsCount,
	},
	{
		id: 'bookmarks' as Mode,
		label: t('sheetmusic', 'Bookmarks'),
		icon: BookmarkMusicOutline,
		dirty: false,
		count: props.bookmarksCount ?? 0,
	},
])
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
				<span v-if="isFullscreen" :class="$style.songTitle" :title="song.title">{{ song.title }}</span>
				<span v-if="targetFile" :class="$style.fileName">
					{{ getFileName(targetFile.path) }} — {{ hasAnyDeletion ? `${remainingPagesCount} / ${totalPages}` : totalPages }} {{ totalPages === 1 ? t('sheetmusic', 'page') : t('sheetmusic', 'pages') }}
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
						<span :class="$style.infoBadgeText">
							<span v-if="formattedFileSize !== '—'">{{ formattedFileSize }}</span>
							<span v-if="formattedFileSize !== '—' && primaryResolution"> · </span>
							<span v-if="primaryResolution">
								{{ primaryResolution.dpi }} DPI ({{ primaryResolution.pixelWidth }}×{{ primaryResolution.pixelHeight }} px)
							</span>
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
								<span>{{ t('sheetmusic', 'Page {n}', { n: pr.pageNum }, { escape: false }) }}</span>
								<span>{{ pr.pixelWidth }}×{{ pr.pixelHeight }} px · {{ pr.dpi }} DPI · {{ pr.widthMm }}×{{ pr.heightMm }} mm</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<!-- Center: Mode Switcher & PDF Tools -->
		<nav :class="$style.modeTabs" :aria-label="t('sheetmusic', 'PDF edit modes')">
			<button
				v-for="tab in modeTabItems"
				:key="tab.id"
				type="button"
				:class="[$style.tabButton, activeMode === tab.id && $style.tabButtonActive]"
				:title="tab.label"
				:aria-label="tab.label"
				@click="emit('update:activeMode', tab.id)">
				<component :is="tab.icon" :size="18" />
				<span :class="$style.tabLabel">{{ tab.label }}</span>
				<span v-if="tab.dirty" :class="$style.tabDirtyDot" :aria-label="t('sheetmusic', 'Has unsaved changes')" />
				<span v-if="tab.count > 0" :class="$style.tabCountBadge">{{ tab.count }}</span>
			</button>
			<span :class="$style.tabDivider" aria-hidden="true" />
			<button
				type="button"
				:class="$style.toolActionBtn"
				:disabled="isSaving || isLoading"
				:title="t('sheetmusic', 'Edit page order')"
				:aria-label="t('sheetmusic', 'Page Order…')"
				@click="emit('open-page-order-modal')">
				<FormatListNumbered :size="18" />
				<span :class="$style.tabLabel">{{ t('sheetmusic', 'Page Order…') }}</span>
			</button>
			<button
				type="button"
				:class="$style.toolActionBtn"
				:disabled="isSaving || isLoading || totalPages < 2"
				:title="t('sheetmusic', 'Join two pages into a single page')"
				:aria-label="t('sheetmusic', 'Join Pages…')"
				@click="emit('open-join-modal')">
				<TableMergeCells :size="18" />
				<span :class="$style.tabLabel">{{ t('sheetmusic', 'Join Pages…') }}</span>
			</button>
		</nav>

		<!-- Right: Save / Split & Fullscreen Buttons -->
		<div :class="$style.headerRight">
			<NcButton
				variant="primary"
				:disabled="saveButtonDisabled"
				:title="saveButtonTitle"
				:aria-label="saveButtonText"
				@click="emit('save')">
				<template #icon>
					<CallSplit v-if="activeMode === 'split'" :size="20" />
					<ContentSaveOutlineIcon v-else :size="20" />
				</template>
				<span :class="$style.saveBtnText">{{ saveButtonText }}</span>
			</NcButton>
			<NcButton
				variant="tertiary"
				:disabled="isSaving || isLoading"
				:title="t('sheetmusic', 'View file version history and revert changes')"
				:aria-label="t('sheetmusic', 'Version History…')"
				@click="emit('open-versions-modal')">
				<template #icon>
					<History :size="20" />
				</template>
			</NcButton>
			<NcButton
				variant="tertiary"
				:title="isFullscreen ? t('sheetmusic', 'Exit fullscreen') : t('sheetmusic', 'Fullscreen')"
				:aria-label="isFullscreen ? t('sheetmusic', 'Exit fullscreen') : t('sheetmusic', 'Fullscreen')"
				@click="emit('toggle-fullscreen')">
				<template #icon>
					<FullscreenExitIcon v-if="isFullscreen" :size="20" />
					<FullscreenIcon v-else :size="20" />
				</template>
			</NcButton>
		</div>
	</header>
</template>

<style module>
.header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 16px 0;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
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
	padding-bottom: 8px;
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
	align-items: flex-end;
	align-self: flex-end;
	justify-content: center;
	padding: 0;
	gap: 4px;
	flex-shrink: 0;
	margin: 0 auto;
}

button.tabButton {
	position: relative;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 8px 12px;
	box-sizing: border-box;
	border: 1px solid transparent;
	border-bottom: none;
	background-color: transparent;
	border-radius: var(--border-radius-large, 8px) var(--border-radius-large, 8px) 0 0;
	/* stylelint-disable-next-line declaration-no-important -- override Nextcloud global button margin */
	margin: 0 0 -1px !important;
	cursor: pointer;
	font-size: 13px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out;
	white-space: nowrap;
}

button.tabButton:hover:not(:disabled) {
	color: var(--color-main-text);
	background-color: rgba(128, 128, 128, 0.08);
}

button.tabButtonActive,
button.tabButtonActive:hover:not(:disabled) {
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-weight: 600;
	border-color: var(--color-border);
	border-bottom: 1px solid var(--color-main-background);
	box-shadow: none;
	z-index: 2;
}

.tabLabel {
	display: inline;
}

.tabDivider {
	align-self: center;
	width: 1px;
	height: 20px;
	background-color: var(--color-border);
	margin: 0 4px 6px;
	flex-shrink: 0;
}

button.toolActionBtn {
	align-self: center;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 5px 10px;
	box-sizing: border-box;
	border: 1px solid var(--color-border-dark, var(--color-border));
	background-color: var(--color-main-background);
	border-radius: var(--border-radius, 6px);
	/* stylelint-disable-next-line declaration-no-important -- override Nextcloud global button margin */
	margin: 0 0 6px !important;
	cursor: pointer;
	font-size: 13px;
	font-weight: 500;
	color: var(--color-main-text);
	transition: background-color 0.15s ease-in-out;
	white-space: nowrap;
}

button.toolActionBtn:hover:not(:disabled) {
	background-color: var(--color-background-dark, var(--color-background-hover));
}

button.toolActionBtn:disabled,
button.tabButton:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.saveBtnText {
	display: inline;
}

.tabDirtyDot {
	width: 7px;
	height: 7px;
	border-radius: 50%;
	background-color: var(--color-primary-element);
	flex-shrink: 0;
}

.tabCountBadge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 17px;
	height: 17px;
	padding: 0 5px;
	border-radius: 9px;
	background-color: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
	color: var(--color-primary-element);
	font-size: 11px;
	font-weight: 600;
	line-height: 1;
	flex-shrink: 0;
}

.headerRight {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 8px;
	flex: 1 1 0;
	flex-shrink: 0;
	margin-left: auto;
	padding-bottom: 8px;
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
		gap: 8px 12px;
		padding: 8px 12px 0;
	}

	.headerLeft {
		grid-area: left;
		min-width: 0;
		overflow: hidden;
		padding-bottom: 0;
	}

	.headerRight {
		grid-area: right;
		justify-self: end;
		margin-left: 0;
		flex: none;
		padding-bottom: 0;
	}

	.modeTabs {
		grid-area: tabs;
		width: 100%;
		max-width: 100%;
		display: flex;
		box-sizing: border-box;
		margin: 0;
		overflow: visible;
		justify-content: flex-start;
	}

	.tabButton {
		flex: 1 0 auto;
		min-height: 38px;
		padding: 8px 8px;
		font-size: 12px;
	}

	button.toolActionBtn {
		flex: 0 0 auto;
		min-height: 32px;
		padding: 5px 8px;
		font-size: 12px;
	}

	.infoBadgeText {
		display: none;
	}

	.infoBadgeBtn {
		padding: 4px 6px;
		border-radius: 50%;
	}

	.songTitle {
		max-width: 180px;
	}

	.fileName {
		max-width: 180px;
	}
}

@media (max-width: 900px) {
	.tabLabel {
		display: none;
	}

	.tabButton {
		flex: 1 1 0;
		min-width: 0;
		padding: 6px 8px;
		gap: 4px;
	}

	button.toolActionBtn {
		flex: 0 0 auto;
		padding: 5px 8px;
		gap: 4px;
	}

	.modeTabs {
		overflow-x: visible;
		justify-content: space-between;
	}
}

@media (max-width: 600px) {
	.header {
		padding: 6px 10px 0;
		gap: 6px 8px;
	}

	.headerLeft {
		gap: 8px;
	}

	.headerRight {
		gap: 4px;
	}

	.fileName {
		display: none;
	}

	.cancelBtnText,
	.headerLeft :global(.button-vue__text) {
		display: none;
	}

	.headerLeft :global(.button-vue) {
		--button-padding: var(--button-radius);
		padding-inline: var(--button-padding);
		line-height: 1;
		width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		justify-content: center;
	}

	.songTitle {
		max-width: 150px;
		font-size: 14px;
	}
}

@media (max-width: 480px) {
	.saveBtnText,
	.headerRight :global(.button-vue__text) {
		display: none;
	}

	.headerRight :global(.button-vue) {
		--button-padding: var(--button-radius);
		padding-inline: var(--button-padding);
		line-height: 1;
		width: var(--button-size, var(--default-clickable-area, 44px)) !important;
		justify-content: center;
	}

	.songTitle {
		max-width: 140px;
	}

	.tabButton {
		padding: 6px 4px;
		gap: 3px;
	}
}
</style>
