<script setup lang="ts">
import type { Bookmark, SongFile } from '../../types/song'
import type { PdfPageResolutionSummary } from '../song-pdf/pdf-enhance-engine'

import { computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DrawIcon from 'vue-material-design-icons/Draw.vue'
import EyeOffOutline from 'vue-material-design-icons/EyeOffOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FullscreenIcon from 'vue-material-design-icons/Fullscreen.vue'
import FullscreenExitIcon from 'vue-material-design-icons/FullscreenExit.vue'
import History from 'vue-material-design-icons/History.vue'
import MergeIcon from 'vue-material-design-icons/Merge.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import PlayIcon from 'vue-material-design-icons/Play.vue'
import PdfInfoPopover from '../song-pdf/PdfInfoPopover.vue'
import SongViewerBookmarksBar from './SongViewerBookmarksBar.vue'
import { getFileName, getPdfFiles } from '../../utils/file'
import { formatByteSize } from '../song-pdf/pdf-enhance-engine'

const props = withDefaults(
	defineProps<{
		file: Pick<SongFile, 'id' | 'path'> & { fileSize?: number | null }
		files?: SongFile[]
		isPdf: boolean
		currentPage?: number
		totalPages?: number
		fileSizeBytes?: number | null
		pageResolutions?: PdfPageResolutionSummary[]
		showAnnotations?: boolean
		isEditingAnnotations?: boolean
		isFullscreen?: boolean
		canWrite?: boolean
		bookmarks?: Bookmark[]
		activeBookmarkPage?: number
		showPlay?: boolean
		showBookmarks?: boolean
		showModifyPdf?: boolean
		showVersions?: boolean
		showExternal?: boolean
		showBack?: boolean
		backLabel?: string
	}>(),
	{
		files: () => [],
		currentPage: 1,
		totalPages: 1,
		fileSizeBytes: null,
		pageResolutions: () => [],
		showAnnotations: true,
		isEditingAnnotations: false,
		isFullscreen: false,
		canWrite: true,
		bookmarks: () => [],
		activeBookmarkPage: undefined,
		showPlay: true,
		showBookmarks: true,
		showModifyPdf: true,
		showVersions: true,
		showExternal: true,
		showBack: false,
		backLabel: undefined,
	},
)

const emit = defineEmits<{
	back: []
	'select-file': [fileId: string]
	play: []
	'open-external': []
	'modify-pdf': []
	'merge-pdfs': []
	'open-versions': []
	'toggle-annotations': []
	'toggle-edit-annotations': []
	'toggle-fullscreen': []
	'jump-to-bookmark': [pageNum: number]
	'edit-bookmarks': []
}>()

const hasMultiplePdfs = computed(() => getPdfFiles(props.files).length >= 2)
const hasMenuActions = computed(() =>
	props.isPdf
	|| (hasMultiplePdfs.value && props.canWrite !== false)
	|| props.showExternal,
)

const effectiveSizeBytes = computed(() => props.fileSizeBytes ?? props.file?.fileSize ?? null)
const formattedFileSize = computed(() => formatByteSize(effectiveSizeBytes.value))
const fileName = computed(() => getFileName(props.file.path))
const editAnnotationsLabel = computed(() =>
	props.isEditingAnnotations ? t('sheetmusic', 'Exit annotation editor') : t('sheetmusic', 'Edit annotations'),
)
const fullscreenLabel = computed(() =>
	props.isFullscreen ? t('sheetmusic', 'Exit fullscreen') : t('sheetmusic', 'Fullscreen'),
)
const showAnnotationsLabel = computed(() =>
	props.showAnnotations ? t('sheetmusic', 'Hide annotations') : t('sheetmusic', 'Show annotations'),
)

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
			<NcButton
				v-if="showBack"
				variant="tertiary"
				:title="backLabel || t('sheetmusic', 'Back')"
				:aria-label="backLabel || t('sheetmusic', 'Back')"
				data-test="viewer-back-button"
				@click="emit('back')">
				<template #icon>
					<ArrowLeftIcon :size="20" />
				</template>
			</NcButton>
			<div v-if="files.length > 1" :class="$style.fileSelectWrap">
				<FileDocumentOutline :size="18" :class="$style.fileIcon" />
				<select
					:value="String(file.id)"
					:class="$style.fileSelect"
					:aria-label="t('sheetmusic', 'Files of this song')"
					data-test="song-file-selector"
					@change="emit('select-file', ($event.target as HTMLSelectElement).value)">
					<option
						v-for="(item, index) in files"
						:key="item.id"
						:value="String(item.id)">
						{{ index + 1 }}. {{ getFileName(item.path) }}
					</option>
				</select>
			</div>
			<span v-else :class="$style.fileName" :title="file.path">
				<FileDocumentOutline :size="18" :class="$style.fileIcon" />
				<span :class="$style.fileNameText">{{ fileName }}</span>
			</span>

			<PdfInfoPopover
				v-if="isPdf && (formattedFileSize !== '—' || activePageResolution)"
				:formatted-file-size="formattedFileSize"
				:file-size-bytes="effectiveSizeBytes"
				:total-pages="totalPages"
				:primary-resolution="activePageResolution"
				:page-resolutions="pageResolutions"
				trigger-test-id="pdf-file-info-trigger"
				dropdown-test-id="pdf-file-info-dropdown"
				file-size-test-id="pdf-file-info-size"
				resolution-test-id="pdf-file-info-resolution" />
		</div>

		<div :class="$style.fileActions">
			<SongViewerBookmarksBar
				v-if="showBookmarks && isPdf"
				:bookmarks="bookmarks"
				:active-bookmark-page="activeBookmarkPage"
				:can-write="canWrite"
				@jump="emit('jump-to-bookmark', $event)"
				@edit="emit('edit-bookmarks')" />
			<NcButton
				v-if="showPlay && file?.path"
				variant="primary"
				:title="t('sheetmusic', 'Play song')"
				:aria-label="t('sheetmusic', 'Play song')"
				data-test="play-song-button"
				@click="emit('play')">
				<template #icon>
					<PlayIcon :size="20" />
				</template>
			</NcButton>
			<NcButton
				v-if="isPdf && canWrite !== false"
				:variant="isEditingAnnotations ? 'primary' : 'secondary'"
				:title="editAnnotationsLabel"
				:aria-label="editAnnotationsLabel"
				data-test="edit-annotations-button"
				@click="emit('toggle-edit-annotations')">
				<template #icon>
					<DrawIcon :size="20" />
				</template>
			</NcButton>
			<NcButton
				:variant="isFullscreen ? 'primary' : 'secondary'"
				:title="fullscreenLabel"
				:aria-label="fullscreenLabel"
				data-test="viewer-fullscreen-button"
				@click="emit('toggle-fullscreen')">
				<template #icon>
					<FullscreenExitIcon v-if="isFullscreen" :size="20" />
					<FullscreenIcon v-else :size="20" />
				</template>
			</NcButton>
			<NcActions
				v-if="hasMenuActions"
				:aria-label="t('sheetmusic', 'More file actions')"
				data-test="pdf-actions-menu">
				<NcActionButton
					v-if="isPdf"
					:close-after-click="true"
					:title="showAnnotationsLabel"
					data-test="toggle-annotations-button"
					@click="emit('toggle-annotations')">
					<template #icon>
						<EyeOutline v-if="showAnnotations" :size="18" />
						<EyeOffOutline v-else :size="18" />
					</template>
					{{ showAnnotationsLabel }}
				</NcActionButton>
				<NcActionButton
					v-if="showModifyPdf && isPdf && canWrite !== false"
					:close-after-click="true"
					:title="t('sheetmusic', 'Modify PDF')"
					@click="emit('modify-pdf')">
					<template #icon>
						<FileDocumentEditOutline :size="18" />
					</template>
					{{ t('sheetmusic', 'Modify PDF') }}
				</NcActionButton>
				<NcActionButton
					v-if="hasMultiplePdfs && canWrite !== false"
					:close-after-click="true"
					:title="t('sheetmusic', 'Merge PDFs')"
					data-test="merge-pdfs-action"
					@click="emit('merge-pdfs')">
					<template #icon>
						<MergeIcon :size="18" />
					</template>
					{{ t('sheetmusic', 'Merge PDFs') }}
				</NcActionButton>
				<NcActionButton
					v-if="showVersions && isPdf && canWrite !== false"
					:close-after-click="true"
					:title="t('sheetmusic', 'PDF version history')"
					@click="emit('open-versions')">
					<template #icon>
						<History :size="18" />
					</template>
					{{ t('sheetmusic', 'PDF version history') }}
				</NcActionButton>
				<NcActionButton
					v-if="showExternal"
					:close-after-click="true"
					:title="t('sheetmusic', 'Open in external viewer')"
					@click="emit('open-external')">
					<template #icon>
						<OpenInNewIcon :size="18" />
					</template>
					{{ t('sheetmusic', 'Open in external viewer') }}
				</NcActionButton>
			</NcActions>
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
	flex-shrink: 0;
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

.fileSelectWrap {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	min-width: 0;
}

.fileSelect {
	height: 32px;
	max-width: 260px;
	padding: 0 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 0.875rem;
	font-weight: 500;
	cursor: pointer;
}

.fileActions {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}
</style>
