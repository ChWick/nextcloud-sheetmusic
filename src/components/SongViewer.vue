<script setup lang="ts">
import type { Artist, Book, Collection, Composer, CustomGroup, Genre, Instrument, Key, LibraryData, Signature, Song, SongFile, SourceType, Year } from '../types/song'

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
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import ViewDay from 'vue-material-design-icons/ViewDay.vue'
import ViewStream from 'vue-material-design-icons/ViewStream.vue'
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import SongEditor from './SongEditor.vue'
import DeleteSongDialog from './DeleteSongDialog.vue'
import FilePreviewer from './FilePreviewer.vue'

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

defineExpose({
	openInExternalViewer,
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
						:title="t('sheetmusic', 'Modify PDF (rotate, crop, split)')"
						:aria-label="t('sheetmusic', 'Modify PDF (rotate, crop, split)')"
						@click="emit('modify-pdf', song, selectedFile?.id)">
						<template #icon>
							<FileDocumentEditOutline :size="20" />
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

			<div v-if="!isEditing && (songComposers.length > 0 || songBooks.length > 0 || songCollections.length > 0 || songArtists.length > 0 || songGenres.length > 0 || songKeys.length > 0 || songCustomGroups.length > 0 || songYears.length > 0 || songSignatures.length > 0 || songSourceTypes.length > 0 || song.stars || song.difficulty || song.duration || song.keywords || song.custom || song.custom2)" :class="$style.metadata">
				<div v-if="songComposers.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Composer:') }}</span>
					<span>{{ songComposers.map((c) => c.name).join(', ') }}</span>
				</div>
				<div v-if="songBooks.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Book:') }}</span>
					<span>{{ songBooks.map((b) => b.title).join(', ') }}</span>
				</div>
				<div v-if="songCollections.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Collection:') }}</span>
					<span>{{ songCollections.map((c) => c.name).join(', ') }}</span>
				</div>
				<div v-if="songArtists.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Artist:') }}</span>
					<span>{{ songArtists.map((a) => a.name).join(', ') }}</span>
				</div>
				<div v-if="songGenres.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Genre:') }}</span>
					<span>{{ songGenres.map((g) => g.name).join(', ') }}</span>
				</div>
				<div v-if="songKeys.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Key:') }}</span>
					<span>{{ songKeys.map((k) => k.name).join(', ') }}</span>
				</div>
				<div v-if="songCustomGroups.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom Group:') }}</span>
					<span>{{ songCustomGroups.map((cg) => cg.name).join(', ') }}</span>
				</div>
				<div v-if="songYears.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Year:') }}</span>
					<span>{{ songYears.map((y) => y.name).join(', ') }}</span>
				</div>
				<div v-if="songSignatures.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Time Signature:') }}</span>
					<span>{{ songSignatures.map((s) => s.name).join(', ') }}</span>
				</div>
				<div v-if="songSourceTypes.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Source Type:') }}</span>
					<span>{{ songSourceTypes.map((st) => st.name).join(', ') }}</span>
				</div>
				<div v-if="songBookmarks.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Bookmarks:') }}</span>
					<span>{{ songBookmarks.map((b) => `${b.name} (p. ${b.pageNum})`).join(', ') }}</span>
				</div>
				<div v-if="songAudioFiles.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Audio Files:') }}</span>
					<span>{{ songAudioFiles.map((a) => a.title || a.file).join(', ') }}</span>
				</div>
				<div v-if="song.stars" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Rating:') }}</span>
					<span>{{ formatStars(song.stars) }}</span>
				</div>
				<div v-if="song.difficulty" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Difficulty:') }}</span>
					<span>{{ formatDifficulty(song.difficulty) }}</span>
				</div>
				<div v-if="song.duration" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Duration:') }}</span>
					<span>{{ formatDuration(song.duration) }}</span>
				</div>
				<div v-if="song.keywords" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Keywords:') }}</span>
					<span>{{ song.keywords }}</span>
				</div>
				<div v-if="song.custom" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom:') }}</span>
					<span>{{ song.custom }}</span>
				</div>
				<div v-if="song.custom2" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom 2:') }}</span>
					<span>{{ song.custom2 }}</span>
				</div>
			</div>
		</header>

		<!-- Metadata bar when header is integrated into top bar -->
		<div v-if="song && hideHeader && !isEditing && (songComposers.length > 0 || songBooks.length > 0 || songCollections.length > 0 || songArtists.length > 0 || songGenres.length > 0 || songKeys.length > 0 || songCustomGroups.length > 0 || songYears.length > 0 || songSignatures.length > 0 || songSourceTypes.length > 0 || song.stars || song.difficulty || song.duration || song.keywords || song.custom || song.custom2)" :class="$style.metadata">
			<div v-if="songComposers.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Composer:') }}</span>
				<span>{{ songComposers.map((c) => c.name).join(', ') }}</span>
			</div>
			<div v-if="songBooks.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Book:') }}</span>
				<span>{{ songBooks.map((b) => b.title).join(', ') }}</span>
			</div>
			<div v-if="songCollections.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Collection:') }}</span>
				<span>{{ songCollections.map((c) => c.name).join(', ') }}</span>
			</div>
			<div v-if="songArtists.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Artist:') }}</span>
				<span>{{ songArtists.map((a) => a.name).join(', ') }}</span>
			</div>
			<div v-if="songGenres.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Genre:') }}</span>
				<span>{{ songGenres.map((g) => g.name).join(', ') }}</span>
			</div>
			<div v-if="songKeys.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Key:') }}</span>
				<span>{{ songKeys.map((k) => k.name).join(', ') }}</span>
			</div>
			<div v-if="songCustomGroups.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom Group:') }}</span>
				<span>{{ songCustomGroups.map((cg) => cg.name).join(', ') }}</span>
			</div>
			<div v-if="songYears.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Year:') }}</span>
				<span>{{ songYears.map((y) => y.name).join(', ') }}</span>
			</div>
			<div v-if="songSignatures.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Time Signature:') }}</span>
				<span>{{ songSignatures.map((s) => s.name).join(', ') }}</span>
			</div>
			<div v-if="songSourceTypes.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Source Type:') }}</span>
				<span>{{ songSourceTypes.map((st) => st.name).join(', ') }}</span>
			</div>
			<div v-if="songInstruments.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Instrument:') }}</span>
				<span>{{ songInstruments.map((i) => i.name).join(', ') }}</span>
			</div>
			<div v-if="song.opus" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Opus:') }}</span>
				<span>{{ song.opus }}</span>
			</div>
			<div v-if="song.catalogNumber" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Catalog No.:') }}</span>
				<span>{{ song.catalogNumber }}</span>
			</div>
			<div v-if="song.songNumber" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Song No.:') }}</span>
				<span>{{ song.songNumber }}</span>
			</div>
			<div v-if="song.stars" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Rating:') }}</span>
				<span>{{ formatStars(song.stars) }}</span>
			</div>
			<div v-if="song.difficulty" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Difficulty:') }}</span>
				<span>{{ formatDifficulty(song.difficulty) }}</span>
			</div>
			<div v-if="song.duration" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Duration:') }}</span>
				<span>{{ formatDuration(song.duration) }}</span>
			</div>
			<div v-if="song.keywords" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Keywords:') }}</span>
				<span>{{ song.keywords }}</span>
			</div>
			<div v-if="song.custom" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom:') }}</span>
				<span>{{ song.custom }}</span>
			</div>
			<div v-if="song.custom2" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('sheetmusic', 'Custom 2:') }}</span>
				<span>{{ song.custom2 }}</span>
			</div>
		</div>

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

			<FilePreviewer
				v-if="selectedFile?.path"
				:key="`${selectedFile.id}-${selectedFile.path}-${song?.lastModified ?? ''}-${previewVersion}`"
				:file-path="previewFilePath"
				:show-external-button="false" />
			<div v-else :class="$style.error">
				{{ t('sheetmusic', 'No file available for this song.') }}
			</div>
		</template>

		<DeleteSongDialog
			v-model:open="isDeleteDialogOpen"
			:song="song"
			:library-id="libraryId ?? -1"
			:file-count="songFiles.length"
			@deleted="handleSongDeleted" />
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

.fileTabs {
	display: flex;
	align-items: center;
	margin-bottom: 8px;
	padding: 2px;
	overflow-x: auto;
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
