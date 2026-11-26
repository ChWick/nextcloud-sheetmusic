<script setup lang="ts">
import type { Arranger, Artist, Book, Collection, Composer, CustomGroup, Genre, Instrument, Key, LibraryData, SetlistItem, Signature, Song, SongFile, SourceType, Year } from '../types/song'

import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { ensurePdfJsWorker } from '../utils/pdfjs'
import { getPdfFiles, isPdfFile, openFileExternally } from '../utils/file'
import { getFileVersionToken, getVersionedFileUrl } from '../utils/file-version'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import SongEditor from './SongEditor.vue'
import DeleteSongDialog from './DeleteSongDialog.vue'
import FileViewerContent from './file-preview/FileViewerContent.vue'
import SongPdfMergeModal from './song-pdf/SongPdfMergeModal.vue'
import SongPdfVersionsModal from './song-pdf/SongPdfVersionsModal.vue'
import { useFileVersions } from './song-pdf/useFileVersions'
import SongViewerMetadata from './song-viewer/SongViewerMetadata.vue'
import SetlistPerformanceViewer from './setlists/SetlistPerformanceViewer.vue'
import FullscreenShell from './FullscreenShell.vue'
import { extractEntityLists } from './songs/song-view-columns'
import { fetchSongAnnotations } from '../api/annotations'
import { useFullscreenBody } from '../composables/useFullscreenBody'
import type { SheetAnnotation } from '../types/annotation'
import {
	createLibraryPerformanceStore,
	DEFAULT_PERFORMANCE_SETTINGS,
	getDefaultSepiaForMode,
	isNightScoreMode,
	type ScoreColorMode,
} from '../utils/performance-settings'

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
		arrangers?: Arranger[]
		hideHeader?: boolean
		canWrite?: boolean
		initialFullscreen?: boolean
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
		arrangers: () => [],
		hideHeader: false,
		canWrite: true,
		initialFullscreen: false,
	},
)

const emit = defineEmits<{
	back: []
	'modify-pdf': [song: Song, fileId?: string | number, mode?: string, fullscreen?: boolean]
	saved: [data: LibraryData, songId?: number]
	updated: [data: LibraryData]
	/** The song was deleted; its files went to the trash bin or stayed, per the chosen option. */
	deleted: [data: LibraryData | undefined, songId: number]
}>()

ensurePdfJsWorker()

const isEditing = ref(false)
const isDeleteDialogOpen = ref(false)
const isMergePdfsModalOpen = ref(false)
const isPerformanceMode = ref(false)
const performanceStartPage = ref(1)
const selectedFileId = ref('')
const filePreviewerRef = ref<InstanceType<typeof FileViewerContent> | null>(null)
const scoreColorMode = ref<ScoreColorMode>(DEFAULT_PERFORMANCE_SETTINGS.scoreColorMode)
const scoreBrightness = ref<number>(DEFAULT_PERFORMANCE_SETTINGS.scoreBrightness)
const scoreSepia = ref<number>(DEFAULT_PERFORMANCE_SETTINGS.scoreSepia)

function loadScoreAppearance() {
	if (props.libraryId <= 0) return
	const s = createLibraryPerformanceStore(props.libraryId).get()
	scoreColorMode.value = s.scoreColorMode
	scoreBrightness.value = s.scoreBrightness
	scoreSepia.value = s.scoreSepia
}

function persistViewerScoreAppearance() {
	if (props.libraryId <= 0) return
	const store = createLibraryPerformanceStore(props.libraryId)
	store.save({
		...store.get(),
		scoreColorMode: scoreColorMode.value,
		scoreBrightness: scoreBrightness.value,
		scoreSepia: scoreSepia.value,
	})
}

function updateViewerScoreColorMode(mode: ScoreColorMode) {
	scoreColorMode.value = mode
	scoreSepia.value = getDefaultSepiaForMode(mode)
	persistViewerScoreAppearance()
}

function toggleViewerNightMode() {
	updateViewerScoreColorMode(isNightScoreMode(scoreColorMode.value) ? 'normal' : 'night')
}

function updateViewerScoreBrightness(brightness: number) {
	scoreBrightness.value = brightness
	persistViewerScoreAppearance()
}

function updateViewerScoreSepia(sepia: number) {
	scoreSepia.value = sepia
	if (isNightScoreMode(scoreColorMode.value)) {
		scoreColorMode.value = sepia > 0 ? 'night-sepia' : 'night'
	} else {
		scoreColorMode.value = sepia > 0 ? 'sepia' : 'normal'
	}
	persistViewerScoreAppearance()
}

watch(() => props.libraryId, loadScoreAppearance, { immediate: true })
watch(isPerformanceMode, (open) => {
	if (!open) loadScoreAppearance()
})

const performanceItems = computed<SetlistItem[]>(() => {
	if (!props.song) return []
	return [{
		id: props.song.id,
		setlistId: 0,
		songId: props.song.id,
		position: 0,
		songTitle: props.song.title,
		pageNum: performanceStartPage.value,
	}]
})

function startPerformanceMode() {
	if (!props.song || !selectedFile.value?.path) return
	performanceStartPage.value = filePreviewerRef.value?.currentPage ?? activeBookmarkPage.value ?? 1
	isPerformanceMode.value = true
}

const isSelectedFilePdf = computed(() => isPdfFile(selectedFile.value?.path))

const entityListsProps = computed(() => extractEntityLists(props))
const songBookmarks = computed(() => props.song?.bookmarks ?? [])
const songFiles = computed(() => props.song?.files ?? [])
const songPdfFiles = computed(() => getPdfFiles(songFiles.value))

/** The file of the active tab, falling back to the first file of the song. */
const selectedFile = computed<SongFile | null>(() => {
	const files = songFiles.value
	return files.find((file) => String(file.id) === selectedFileId.value) ?? files[0] ?? null
})

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

const localVersionBump = ref(0)

function handleSaved(data: LibraryData, songId?: number) {
	localVersionBump.value++
	isEditing.value = false
	emit('saved', data, songId)
}

const fileVersionToken = computed(() => {
	if (!selectedFile.value) return ''
	const baseToken = getFileVersionToken(selectedFile.value, props.song)
	return localVersionBump.value > 0 ? `${baseToken}-b${localVersionBump.value}` : baseToken
})

const previewFilePath = computed(() => {
	if (!selectedFile.value?.path) return ''
	return getVersionedFileUrl(selectedFile.value.path, fileVersionToken.value)
})

function openInExternalViewer() {
	const filePath = previewFilePath.value || selectedFile.value?.path
	if (filePath) {
		openFileExternally(filePath)
	}
}

function handleUpdated(data: LibraryData) {
	localVersionBump.value++
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
			isPerformanceMode.value = false
		}
	},
)

watch(
	() => props.song,
	(newSong) => {
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

const annotations = ref<SheetAnnotation[]>([])

const annotationContext = computed(() => ({
	songId: props.song?.id ?? 0,
	fileId: Number(selectedFile.value?.fileId ?? selectedFile.value?.id ?? 0),
}))

const {
	isFullscreen: isAnnotationFullscreen,
	toggleFullscreen: toggleAnnotationFullscreen,
	preserveFullscreenOnUnmount,
} = useFullscreenBody(() => Boolean(props.initialFullscreen))

function openPdfModifier(mode?: string) {
	if (!props.song) return
	if (isAnnotationFullscreen.value) {
		preserveFullscreenOnUnmount()
	}
	emit('modify-pdf', props.song, selectedFile.value?.id, mode, isAnnotationFullscreen.value || undefined)
}

async function loadAnnotations() {
	if (!props.song?.id) {
		annotations.value = []
		return
	}
	try {
		const targetFileId = selectedFile.value?.fileId ?? selectedFile.value?.id
		annotations.value = await fetchSongAnnotations(props.song.id, targetFileId)
	} catch (error) {
		console.error('Failed to load annotations:', error)
		annotations.value = []
	}
}

watch(
	[() => props.song?.id, () => selectedFile.value?.id, () => selectedFile.value?.fileId],
	() => {
		loadAnnotations()
	},
	{ immediate: true },
)

defineExpose({
	openInExternalViewer,
	openVersionsModal,
	promptDelete: () => { isDeleteDialogOpen.value = true },
	startEditing: () => { isEditing.value = true },
	selectedFile,
	annotations,
	loadAnnotations,
	selectFile,
})
</script>

<template>
	<FullscreenShell :active="isAnnotationFullscreen">
		<div :class="$style.container">
		<header v-if="song && !hideHeader && !isAnnotationFullscreen" :class="$style.header">
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
				<div v-if="!isEditing && canWrite" :class="$style.headerActions">
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
				:song="song" />
		</header>

		<!-- Metadata bar when header is integrated into top bar -->
		<SongViewerMetadata
			v-if="song && hideHeader && !isEditing && !isAnnotationFullscreen"
			:song="song" />

		<!-- Loading State while song is not yet resolved -->
		<div v-if="!song" :class="$style.loadingState" data-test="song-viewer-loading">
			<NcLoadingIcon :size="36" />
			<span>{{ t('sheetmusic', 'Loading song…') }}</span>
		</div>

		<!-- Song Editor View -->
		<SongEditor
			v-else-if="isEditing"
			:song="song"
			:library-id="libraryId"
			v-bind="entityListsProps"
			@saved="handleSaved"
			@updated="handleUpdated"
			@cancel="isEditing = false" />

		<!-- PDF / File Viewer View -->
		<template v-else>
			<FileViewerContent
				v-if="selectedFile"
				ref="filePreviewerRef"
				:file="selectedFile"
				:files="songFiles"
				:file-path="selectedFile.path ? previewFilePath : ''"
				:is-pdf="isSelectedFilePdf"
				:page-order="selectedFile.pageOrder"
				:target-page="activeBookmarkPage"
				:preview-key="`${selectedFile.id}-${fileVersionToken}`"
				:annotations="annotations"
				:annotation-context="annotationContext"
				:can-write="canWrite"
				:is-fullscreen="isAnnotationFullscreen"
				:bookmarks="songBookmarks"
				:active-bookmark-page="activeBookmarkPage"
				:score-color-mode="scoreColorMode"
				:score-brightness="scoreBrightness"
				:score-sepia="scoreSepia"
				@update:annotations="annotations = $event"
				@select-file="selectFile"
				@play="startPerformanceMode"
				@open-external="openInExternalViewer"
				@modify-pdf="openPdfModifier()"
				@merge-pdfs="isMergePdfsModalOpen = true"
				@open-versions="openVersionsModal"
				@toggle-night-mode="toggleViewerNightMode"
				@update:score-color-mode="updateViewerScoreColorMode"
				@update:score-brightness="updateViewerScoreBrightness"
				@update:score-sepia="updateViewerScoreSepia"
				@toggle-fullscreen="toggleAnnotationFullscreen"
				@jump-to-bookmark="jumpToBookmark"
				@edit-bookmarks="openPdfModifier('bookmarks')">
				<template #missingFile>
					<div :class="$style.errorNotice" role="alert">
						{{ t('sheetmusic', 'Missing file: The file does not exist on the file system.') }}
					</div>
				</template>
			</FileViewerContent>
			<div v-else :class="$style.emptyNotice">
				{{ t('sheetmusic', 'No files attached to this song.') }}
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

		<SongPdfMergeModal
			v-if="song && isMergePdfsModalOpen"
			v-model:open="isMergePdfsModalOpen"
			:song="song"
			:library-id="libraryId ?? -1"
			:pdf-files="songPdfFiles"
			@merged="handleUpdated" />

		<SetlistPerformanceViewer
			v-if="isPerformanceMode && song && selectedFile?.path"
			:items="performanceItems"
			:songs="[song]"
			:library-id="libraryId"
			:preferred-file-id="selectedFile.id"
			:initial-annotations="annotations"
			@close="isPerformanceMode = false" />
		</div>
	</FullscreenShell>
</template>

<style module>
.container {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	min-width: 0;
	min-height: 0;
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

.loadingState {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	flex: 1;
	min-height: 240px;
	color: var(--color-text-maxcontrast);
}

.emptyNotice {
	margin: 16px 0;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}

.errorNotice {
	margin: 16px 0;
	padding: 14px 16px;
	border: 1px solid var(--color-error);
	border-left-width: 4px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}
</style>
