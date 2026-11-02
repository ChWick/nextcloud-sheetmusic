<script setup lang="ts">
import type { Artist, Book, Bookmark, Collection, Composer, CustomGroup, Genre, Instrument, Key, LibraryData, Signature, Song, SongFile, SourceType, Year } from '../types/song'

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateFilePath, generateOcsUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { getCacheBustedFileUrl, getFileName, openFileExternally } from '../utils/file'
import { describeRequestFailure } from '../utils/api'
import { formatDifficulty, formatDuration, formatStars } from '../utils/song-formatter'
import { apiDelete } from '../api/client'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ChevronLeft from 'vue-material-design-icons/ChevronLeft.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FitToScreenOutline from 'vue-material-design-icons/FitToScreenOutline.vue'
import MagnifyMinus from 'vue-material-design-icons/MagnifyMinus.vue'
import MagnifyPlus from 'vue-material-design-icons/MagnifyPlus.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import ViewDay from 'vue-material-design-icons/ViewDay.vue'
import ViewStream from 'vue-material-design-icons/ViewStream.vue'
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import { useRoute } from 'vue-router'
import SongEditor from './SongEditor.vue'
import DeleteSongDialog from './DeleteSongDialog.vue'
import FilePreviewer from './FilePreviewer.vue'
import SongPdfVersionsModal from './song-pdf/SongPdfVersionsModal.vue'
import { useFileVersions } from './song-pdf/useFileVersions'
import SongViewerMetadata from './song-viewer/SongViewerMetadata.vue'
import SongViewerBookmarksBar from './song-viewer/SongViewerBookmarksBar.vue'

const props = withDefaults(
	defineProps<{
		song?: Song | null
		libraryId?: number
		composers?: Composer[]
		books?: Book[]
		collections?: Collection[]
		artists?: Artist[]
		genres?: Genre[]
		keys?: Key[]
		customGroups?: CustomGroup[]
		years?: Year[]
		signatures?: Signature[]
		sourceTypes?: SourceType[]
		instruments?: Instrument[]
		hideHeader?: boolean
	}>(),
	{
		song: null,
		libraryId: -1,
		composers: () => [],
		books: () => [],
		collections: () => [],
		artists: () => [],
		genres: () => [],
		keys: () => [],
		customGroups: () => [],
		years: () => [],
		signatures: () => [],
		sourceTypes: () => [],
		instruments: () => [],
		hideHeader: false,
	},
)

const emit = defineEmits<{
	back: []
	'modify-pdf': [song: Song, fileId?: string | number]
	saved: [data: LibraryData, songId?: number]
	updated: [data: LibraryData]
	/** The song was deleted; its files went to the trash bin or stayed, per the chosen option. */
	deleted: [data: LibraryData | undefined, songId: number]
}>()

GlobalWorkerOptions.workerSrc = pdfWorker

const isEditing = ref(false)
const isDeleteDialogOpen = ref(false)
const isDeletingSong = ref(false)
const deleteError = ref('')
const deleteFiles = ref(true)
const selectedFileId = ref('')

const isSelectedFilePdf = computed(() => {
	const path = selectedFile.value?.path?.toLowerCase() ?? ''
	return path.endsWith('.pdf')
})

const songComposers = computed(() => props.song?.composers ?? [])
const songBooks = computed(() => props.song?.books ?? [])
const songCollections = computed(() => props.song?.collections ?? [])
const songArtists = computed(() => props.song?.artists ?? [])
const songGenres = computed(() => props.song?.genres ?? [])
const songKeys = computed(() => props.song?.keys ?? [])
const songCustomGroups = computed(() => props.song?.customGroups ?? [])
const songYears = computed(() => props.song?.years ?? [])
const songSignatures = computed(() => props.song?.signatures ?? [])
const songSourceTypes = computed(() => props.song?.sourceTypes ?? [])
const songInstruments = computed(() => props.song?.instruments ?? [])
const songBookmarks = computed(() => props.song?.bookmarks ?? [])
const songAudioFiles = computed(() => props.song?.audioFiles ?? [])
const songFiles = computed(() => props.song?.files ?? [])

/** The file of the active tab, falling back to the first file of the song. */
const selectedFile = computed<SongFile | null>(() => {
	const files = songFiles.value
	return files.find((file) => String(file.id) === selectedFileId.value) ?? files[0] ?? null
})

/** Tab labels are kept short so that many files stay readable. */
function getFileLabel(file: SongFile, index: number): string {
	const name = getFileName(file.path)
	const truncated = name.length > 30 ? `${name.slice(0, 29)}…` : name
	return `${index + 1}. ${truncated}`
}

const route = useRoute()
const pageParam = route?.query?.page
const initialPage = typeof pageParam === 'string' ? parseInt(pageParam, 10) : undefined
const activeBookmarkPage = ref<number | undefined>(initialPage)

function jumpToBookmark(pageNum: number) {
	activeBookmarkPage.value = pageNum
}

watch(() => props.song?.id, () => {
	const p = route?.query?.page
	activeBookmarkPage.value = typeof p === 'string' ? parseInt(p, 10) : undefined
})

if (route) {
	watch(() => route.query?.page, (newPage) => {
		if (newPage) {
			activeBookmarkPage.value = parseInt(newPage as string, 10)
		}
	})
}

function selectFile(fileId: string) {
	selectedFileId.value = fileId
}

const previewVersion = ref(Date.now())

function handleSaved(data: LibraryData, songId?: number) {
	previewVersion.value = Date.now()
	isEditing.value = false
	emit('saved', data, songId)
}

const previewFilePath = computed(() => {
	if (!selectedFile.value?.path) return ''
	return getCacheBustedFileUrl(selectedFile.value.path, `${selectedFile.value.id}-${props.song?.lastModified ?? ''}-${previewVersion.value}`)
})

function openInExternalViewer() {
	const filePath = previewFilePath.value || selectedFile.value?.path
	if (filePath) {
		openFileExternally(filePath)
	}
}

function handleUpdated(data: LibraryData) {
	previewVersion.value = Date.now()
	emit('updated', data)
}

function handleSongDeleted(data: LibraryData | undefined, songId: number) {
	emit('deleted', data, songId)
}

watch(
	() => props.song?.id,
	(newSongId, previousSongId) => {
		if (newSongId !== previousSongId) {
			isEditing.value = false
		}
	},
)

watch(
	() => props.song,
	(newSong) => {
		previewVersion.value = Date.now()
		const files = newSong?.files ?? []
		const keepsSelection = files.some((file) => String(file.id) === selectedFileId.value)
		selectedFileId.value = keepsSelection ? selectedFileId.value : (files[0] ? String(files[0].id) : '')
	},
	{ immediate: true },
)

const {
	isVersionsModalOpen,
	fileVersions,
	isLoadingVersions,
	isRevertingVersion,
	openVersionsModal,
	revertToVersion,
} = useFileVersions({
	libraryId: () => props.libraryId,
	songId: () => props.song?.id,
	fileId: () => selectedFile.value?.id,
	onReverted: (data) => {
		handleUpdated(data)
	},
})

defineExpose({
	openInExternalViewer,
	openVersionsModal,
	promptDelete: () => { isDeleteDialogOpen.value = true },
	startEditing: () => { isEditing.value = true },
	selectedFile,
})
</script>

<template>
	<div :class="$style.container">
		<header v-if="song && !hideHeader" :class="$style.header">
			<div :class="$style.headerTop">
				<NcButton
					:title="t('sheetmusic', 'Back to songs')"
					:aria-label="t('sheetmusic', 'Back to songs')"
					@click="emit('back')">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
				</NcButton>
				<h3 :class="$style.title">{{ song.title }}</h3>
				<div v-if="!isEditing" :class="$style.headerActions">
					<NcButton
						v-if="selectedFile"
						variant="secondary"
						:title="t('sheetmusic', 'Open in external viewer')"
						:aria-label="t('sheetmusic', 'Open in external viewer')"
						@click="openInExternalViewer">
						<template #icon>
							<OpenInNewIcon :size="20" />
						</template>
					</NcButton>
					<NcButton
						v-if="song && isSelectedFilePdf"
						variant="secondary"
						:title="t('sheetmusic', 'Modify PDF (rotate, crop, enhance, split)')"
						:aria-label="t('sheetmusic', 'Modify PDF (rotate, crop, enhance, split)')"
						@click="emit('modify-pdf', song, selectedFile?.id)">
						<template #icon>
							<FileDocumentEditOutline :size="20" />
						</template>
					</NcButton>
					<NcButton
						v-if="song && isSelectedFilePdf"
						variant="secondary"
						:title="t('sheetmusic', 'PDF version history')"
						:aria-label="t('sheetmusic', 'PDF version history')"
						@click="openVersionsModal">
						<template #icon>
							<History :size="20" />
						</template>
					</NcButton>
					<NcButton
						variant="secondary"
						:title="t('sheetmusic', 'Edit song')"
						:aria-label="t('sheetmusic', 'Edit song')"
						@click="isEditing = true">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
					</NcButton>
					<NcButton
						variant="error"
						:title="t('sheetmusic', 'Delete song')"
						:aria-label="t('sheetmusic', 'Delete song')"
						@click="isDeleteDialogOpen = true">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<SongViewerMetadata
				v-if="!isEditing"
				:song="song"
				:composers="songComposers"
				:books="songBooks"
				:collections="songCollections"
				:artists="songArtists"
				:genres="songGenres"
				:keys="songKeys"
				:custom-groups="songCustomGroups"
				:years="songYears"
				:signatures="songSignatures"
				:source-types="songSourceTypes"
				:instruments="songInstruments"
				:bookmarks="songBookmarks"
				:audio-files="songAudioFiles"
				@jump-to-bookmark="jumpToBookmark" />
		</header>

		<!-- Metadata bar when header is integrated into top bar -->
		<SongViewerMetadata
			v-if="song && hideHeader && !isEditing"
			:song="song"
			:composers="songComposers"
			:books="songBooks"
			:collections="songCollections"
			:artists="songArtists"
			:genres="songGenres"
			:keys="songKeys"
			:custom-groups="songCustomGroups"
			:years="songYears"
			:signatures="songSignatures"
			:source-types="songSourceTypes"
			:instruments="songInstruments"
			:bookmarks="songBookmarks"
			:audio-files="songAudioFiles"
			@jump-to-bookmark="jumpToBookmark" />

		<!-- Song Editor View -->
		<SongEditor
			v-if="song && isEditing"
			:song="song"
			:library-id="libraryId"
			:composers="composers"
			:books="books"
			:collections="collections"
			:artists="artists"
			:genres="genres"
			:keys="keys"
			:custom-groups="customGroups"
			:years="years"
			:signatures="signatures"
			:source-types="sourceTypes"
			:instruments="instruments"
			@saved="handleSaved"
			@updated="handleUpdated"
			@cancel="isEditing = false" />

		<!-- PDF / File Viewer View -->
		<template v-else>
			<div v-if="songFiles.length > 1" :class="$style.fileTabs">
				<NcRadioGroup
					:model-value="selectedFileId"
					:label="t('sheetmusic', 'Files of this song')"
					hide-label
					@update:model-value="selectFile">
					<NcRadioGroupButton
						v-for="(file, index) in songFiles"
						:key="file.id"
						:value="String(file.id)"
						:label="getFileLabel(file, index)"
						:title="file.path" />
				</NcRadioGroup>
			</div>

			<SongViewerBookmarksBar
				:bookmarks="songBookmarks"
				:active-bookmark-page="activeBookmarkPage"
				@jump="jumpToBookmark" />

			<FilePreviewer
				v-if="selectedFile?.path"
				:key="`${selectedFile.id}-${selectedFile.path}-${song?.lastModified ?? ''}-${previewVersion}`"
				:file-path="previewFilePath"
				:target-page="activeBookmarkPage"
				:show-external-button="false" />
			<div v-else :class="$style.error">
				{{ songFiles.length === 0 ? t('sheetmusic', 'No files attached to this song.') : t('sheetmusic', 'Missing file: The file does not exist on the file system.') }}
			</div>
		</template>

		<DeleteSongDialog
			v-model:open="isDeleteDialogOpen"
			:song="song"
			:library-id="libraryId ?? -1"
			:file-count="songFiles.length"
			@deleted="handleSongDeleted" />

		<SongPdfVersionsModal
			v-model:open="isVersionsModalOpen"
			:versions="fileVersions"
			:is-loading="isLoadingVersions"
			:is-reverting="isRevertingVersion"
			@revert="revertToVersion" />
	</div>
</template>

<style module>
.container {
	display: flex;
	flex-direction: column;
	width: 100%;
	min-width: 0;
}

.header {
	margin-bottom: 16px;
}

.headerTop {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	margin-bottom: 8px;
	min-width: 0;
}

.headerActions {
	display: flex;
	flex-shrink: 0;
	gap: 8px;
}

.title {
	margin: 0;
	font-size: 1.2rem;
	font-weight: 600;
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.metadata {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.metaItem {
	display: inline-flex;
	gap: 4px;
}

.metaLabel {
	font-weight: 600;
}

.loading {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 200px;
}

.metaLinkList {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 6px;
}

.metaLinkBtn {
	background: none;
	border: none;
	padding: 0;
	color: var(--color-primary-element);
	text-decoration: underline;
	cursor: pointer;
	font-size: inherit;

	&:hover {
		color: var(--color-primary-element-hover, var(--color-primary-element));
	}
}

.fileTabs {
	display: flex;
	align-items: center;
	margin-bottom: 8px;
	padding: 2px;
	overflow-x: auto;
}

.bookmarksBar {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	margin-bottom: 8px;
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	overflow-x: auto;
	box-sizing: border-box;
}

.bookmarksLabel {
	font-weight: 600;
	font-size: 0.85rem;
	white-space: nowrap;
	color: var(--color-text-maxcontrast);
}

.bookmarksList {
	display: flex;
	flex-wrap: nowrap;
	gap: 6px;
}

.bookmarkChip {
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 16px);
	padding: 3px 10px;
	font-size: 0.85rem;
	cursor: pointer;
	white-space: nowrap;
	transition: background-color 0.15s ease, border-color 0.15s ease;

	&:hover {
		background: var(--color-background-hover);
		border-color: var(--color-primary-element);
	}
}

.bookmarkChipActive {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-bottom: 0;
	border-radius: var(--border-radius-large, 8px) var(--border-radius-large, 8px) 0 0;
	box-sizing: border-box;
}

.toolbarGroup {
	display: flex;
	align-items: center;
	gap: 6px;
}

.segmentedGroup {
	display: inline-flex;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	overflow: hidden;
}

.segmentedGroup button {
	border-radius: 0 !important;
	border: 0 !important;
}

.segmentedGroup button:not(:last-child) {
	border-right: 1px solid var(--color-border) !important;
}

.pageIndicator {
	font-size: 0.85rem;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	padding: 0 4px;
	white-space: nowrap;
}

.zoomSelector {
	display: inline-flex;
	align-items: center;
}

.zoomDropdown {
	background: var(--color-main-background);
	color: var(--color-main-text);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius, 4px);
	padding: 4px 8px;
	font-size: 0.85rem;
	height: 34px;
	cursor: pointer;
}

.pdfViewer,
.imageViewer {
	width: 100%;
	height: 80vh;
	overflow: auto;
	scrollbar-gutter: stable;
	border: 1px solid var(--color-border);
	border-radius: 0 0 var(--border-radius-large, 8px) var(--border-radius-large, 8px);
	background: var(--color-background-dark);
	padding: 16px;
	box-sizing: border-box;
	display: flex;
}

.pdfViewer.layoutContinuous {
	flex-direction: column;
	align-items: center;
	gap: 16px;
}

.pdfViewer.layoutSingle {
	justify-content: center;
	align-items: center;
}

.pdfViewer.layoutDouble {
	flex-direction: row;
	justify-content: center;
	align-items: flex-start;
	flex-wrap: wrap;
	gap: 16px;
}

.imageViewer {
	justify-content: center;
	align-items: center;
}

.viewerImage {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.nonPdfContainer {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 16px;
	height: 40vh;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
	color: var(--color-text-maxcontrast);
	text-align: center;
	padding: 24px;
}

.pdfViewer :global(.sheetmusic-pdf-page) {
	display: block;
	max-width: 100%;
	height: auto;
	background-color: #ffffff !important;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}

.error {
	color: var(--color-error);
	margin: 16px 0;
}

.dialogActions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 24px;
}
</style>
