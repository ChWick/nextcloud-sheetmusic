<script setup lang="ts">
import type { Arranger, Artist, Book, Collection, Composer, CustomGroup, Genre, Instrument, Key, LibraryData, Signature, Song, SongFile, SourceType, Year } from '../types/song'

import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { ensurePdfJsWorker } from '../utils/pdfjs'
import { openFileExternally } from '../utils/file'
import { getFileVersionToken, getVersionedFileUrl } from '../utils/file-version'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import SongEditor from './SongEditor.vue'
import DeleteSongDialog from './DeleteSongDialog.vue'
import AnnotatableFilePreview from './file-preview/AnnotatableFilePreview.vue'
import SongPdfVersionsModal from './song-pdf/SongPdfVersionsModal.vue'
import { useFileVersions } from './song-pdf/useFileVersions'
import SongViewerMetadata from './song-viewer/SongViewerMetadata.vue'
import SongViewerFileToolbar from './song-viewer/SongViewerFileToolbar.vue'
import FullscreenShell from './FullscreenShell.vue'
import { extractEntityLists } from './songs/song-view-columns'
import { fetchSongAnnotations } from '../api/annotations'
import { useFullscreenBody } from '../composables/useFullscreenBody'
import type { SheetAnnotation } from '../types/annotation'

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
const selectedFileId = ref('')
const filePreviewerRef = ref<InstanceType<typeof AnnotatableFilePreview> | null>(null)

const isSelectedFilePdf = computed(() => {
	const path = selectedFile.value?.path?.toLowerCase() ?? ''
	return path.endsWith('.pdf')
})

const entityListsProps = computed(() => extractEntityLists(props))
const songBookmarks = computed(() => props.song?.bookmarks ?? [])
const songFiles = computed(() => props.song?.files ?? [])

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

const isEditingAnnotations = ref(false)

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

function toggleAnnotationEditMode() {
	filePreviewerRef.value?.toggleEditMode()
}

function onEditingAnnotationsChange(editing: boolean) {
	isEditingAnnotations.value = editing
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

		<!-- Song Editor View -->
		<SongEditor
			v-if="song && isEditing"
			:song="song"
			:library-id="libraryId"
			v-bind="entityListsProps"
			@saved="handleSaved"
			@updated="handleUpdated"
			@cancel="isEditing = false" />

		<!-- PDF / File Viewer View -->
		<template v-else>
			<SongViewerFileToolbar
				v-if="selectedFile"
				:file="selectedFile"
				:files="songFiles"
				:is-pdf="isSelectedFilePdf"
				:current-page="filePreviewerRef?.currentPage ?? 1"
				:total-pages="filePreviewerRef?.totalPages ?? 1"
				:file-size-bytes="filePreviewerRef?.fileSizeBytes ?? null"
				:page-resolutions="filePreviewerRef?.pageResolutions ?? []"
				:show-annotations="filePreviewerRef?.showAnnotationsState ?? true"
				:is-editing-annotations="isEditingAnnotations"
				:is-fullscreen="isAnnotationFullscreen"
				:can-write="canWrite"
				:bookmarks="songBookmarks"
				:active-bookmark-page="activeBookmarkPage"
				@select-file="selectFile"
				@open-external="openInExternalViewer"
				@modify-pdf="openPdfModifier()"
				@open-versions="openVersionsModal"
				@toggle-annotations="filePreviewerRef?.toggleAnnotations()"
				@toggle-edit-annotations="toggleAnnotationEditMode"
				@toggle-fullscreen="toggleAnnotationFullscreen"
				@jump-to-bookmark="jumpToBookmark"
				@edit-bookmarks="openPdfModifier('bookmarks')" />

			<AnnotatableFilePreview
				v-if="selectedFile?.path"
				ref="filePreviewerRef"
				:preview-key="`${selectedFile.id}-${fileVersionToken}`"
				:file-path="previewFilePath"
				:page-order="selectedFile?.pageOrder"
				:target-page="activeBookmarkPage"
				:show-external-button="false"
				:annotations="annotations"
				:annotation-context="annotationContext"
				:can-annotate="isSelectedFilePdf && canWrite !== false"
				@update:annotations="annotations = $event"
				@update:is-editing="onEditingAnnotationsChange" />
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

.error {
	color: var(--color-error);
	margin: 16px 0;
}
</style>
