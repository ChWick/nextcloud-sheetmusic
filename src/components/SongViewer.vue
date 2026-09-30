<script setup lang="ts">
import type { Artist, Book, Collection, Composer, LibraryData, Song, SongFile } from '../types/song'

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
import { getFileName, openFileExternally } from '../utils/file'
import { describeRequestFailure } from '../utils/api'
import { apiDelete } from '../api/client'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { computed, nextTick, ref, watch } from 'vue'
import SongEditor from './SongEditor.vue'

const props = withDefaults(
	defineProps<{
		song?: Song | null
		libraryId?: number
		composers?: Composer[]
		books?: Book[]
		collections?: Collection[]
		artists?: Artist[]
	}>(),
	{
		song: null,
		libraryId: -1,
		composers: () => [],
		books: () => [],
		collections: () => [],
		artists: () => [],
	},
)

const emit = defineEmits<{
	back: []
	saved: [data: LibraryData, songId?: number]
	updated: [data: LibraryData]
	/** The song was deleted; its files went to the trash bin or stayed, per the chosen option. */
	deleted: [data: LibraryData | undefined, songId: number]
}>()

GlobalWorkerOptions.workerSrc = pdfWorker

const pdfContainer = ref<HTMLElement>()
const isLoading = ref(false)
const errorMessage = ref('')
const isEditing = ref(false)
const isDeleteDialogOpen = ref(false)
const isDeletingSong = ref(false)
const deleteError = ref('')
/** Whether the attached files are deleted along with the song. */
const deleteFiles = ref(true)
const selectedFileId = ref('')
let currentLoadId = 0
/** Set when the song changed while the editor covered the PDF view, which defers the render. */
let renderDeferred = false

const songComposers = computed(() => props.song?.composers ?? [])

const songBooks = computed(() => props.song?.books ?? [])

const songCollections = computed(() => props.song?.collections ?? [])

const songArtists = computed(() => props.song?.artists ?? [])

const songFiles = computed(() => props.song?.files ?? [])

/** The file of the active tab, falling back to the first file of the song. */
const selectedFile = computed<SongFile | null>(() => {
	const files = songFiles.value
	return files.find((file) => String(file.id) === selectedFileId.value) ?? files[0] ?? null
})

/** Tab labels are kept short so that many files stay readable. */
function getFileLabel(file: SongFile, index: number): string {
	const name = getFileName(file.path).replace(/\.pdf$/i, '')
	const truncated = name.length > 30 ? `${name.slice(0, 29)}…` : name
	return `${index + 1}. ${truncated}`
}

function selectFile(fileId: string) {
	if (fileId === selectedFileId.value) {
		return
	}
	selectedFileId.value = fileId
	renderSelectedFile()
}

/** Saving closes the editor; the parent data is refreshed on the way out. */
function handleSaved(data: LibraryData, songId?: number) {
	isEditing.value = false
	emit('saved', data, songId)
}

/**
 * Open the selected file in an external viewer: the file's URL goes to a new browser
 * tab, where the PDF is shown by the browser's own viewer or handed to the system's
 * PDF application - instead of the embedded view of this app.
 */
function openInExternalViewer() {
	const filePath = selectedFile.value?.path
	if (!filePath) {
		return
	}
	openFileExternally(filePath)
}

/**
 * The editor changed the song's files without saving (upload/attach). The editor has to
 * stay open, so the data is only passed on and 'saved' - which closes it - is not emitted.
 */
function handleUpdated(data: LibraryData) {
	emit('updated', data)
}

/**
 * Delete the song: the song with every row pointing at it leaves the database, and its
 * files go with it to the Nextcloud trash bin when "Delete all attached files" is set.
 */
async function confirmDeleteSong() {
	if (!props.song || props.libraryId < 0) {
		return
	}

	const songId = props.song.id
	isDeletingSong.value = true
	deleteError.value = ''

	try {
		const response = await apiDelete('/api/songs/{id}', {
			pathParams: { id: songId },
			queryParams: { libraryId: props.libraryId, deleteFiles: deleteFiles.value },
		})
		isDeleteDialogOpen.value = false
		// The payload is only a convenience. It must never turn a successful deletion into
		// an error, so a missing body is passed on as undefined and the parent asks the
		// server for the library instead.
		const data = response.data?.ocs?.data
		emit('deleted', data, songId)
	} catch (error) {
		deleteError.value = describeRequestFailure(error, t('mobilesheetsmanager', 'Could not delete the song.'))
	} finally {
		isDeletingSong.value = false
	}
}

async function renderSelectedFile() {
	const loadId = ++currentLoadId
	errorMessage.value = ''

	await nextTick()
	if (pdfContainer.value) {
		pdfContainer.value.replaceChildren()
	}

	if (!props.song) {
		isLoading.value = false
		return
	}

	const filePath = selectedFile.value?.path
	if (!filePath) {
		isLoading.value = false
		errorMessage.value = t('mobilesheetsmanager', 'No file available for this song.')
		return
	}

	isLoading.value = true

	try {
		const response = await axios.get(filePath, {
			responseType: 'arraybuffer',
		})

		if (loadId !== currentLoadId) {
			return
		}

		const pdfData = response.data instanceof ArrayBuffer ? new Uint8Array(response.data) : response.data
		const wasmUrl = typeof generateFilePath === 'function' ? generateFilePath('mobilesheetsmanager', 'js', 'pdfjs/wasm/') : new URL('pdfjs-dist/wasm/', import.meta.url).href
		const cMapUrl = typeof generateFilePath === 'function' ? generateFilePath('mobilesheetsmanager', 'js', 'pdfjs/cmaps/') : new URL('pdfjs-dist/cmaps/', import.meta.url).href
		const standardFontDataUrl = typeof generateFilePath === 'function' ? generateFilePath('mobilesheetsmanager', 'js', 'pdfjs/standard_fonts/') : new URL('pdfjs-dist/standard_fonts/', import.meta.url).href

		const pdf = await getDocument({
			data: pdfData,
			cMapUrl,
			cMapPacked: true,
			standardFontDataUrl,
			wasmUrl,
		}).promise

		if (loadId !== currentLoadId) {
			return
		}

		await nextTick()
		if (!pdfContainer.value || loadId !== currentLoadId) {
			return
		}

		pdfContainer.value.replaceChildren()

		for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
			if (loadId !== currentLoadId) {
				return
			}
			const page = await pdf.getPage(pageNumber)
			const viewport = page.getViewport({ scale: 1.25 })
			const canvas = document.createElement('canvas')
			canvas.width = Math.floor(viewport.width)
			canvas.height = Math.floor(viewport.height)
			canvas.className = 'mobilesheetsmanager-pdf-page'
			pdfContainer.value?.append(canvas)

			const canvasContext = canvas.getContext('2d')
			if (canvasContext) {
				canvasContext.fillStyle = '#ffffff'
				canvasContext.fillRect(0, 0, canvas.width, canvas.height)
				await page.render({
					canvasContext,
					viewport,
					background: 'rgb(255, 255, 255)',
				}).promise
			}
		}
	} catch (error) {
		console.error('Failed to load or render PDF:', error)
		if (loadId === currentLoadId) {
			errorMessage.value = t('mobilesheetsmanager', 'Could not load the selected song.')
		}
	} finally {
		if (loadId === currentLoadId) {
			isLoading.value = false
		}
	}
}

// Picking another song leaves any open editing session behind.
watch(
	() => props.song?.id,
	(newSongId, previousSongId) => {
		if (newSongId !== previousSongId) {
			isEditing.value = false
		}
	},
)

// The song the editor works on can be refreshed in place - a file was added, renamed or
// removed - which must not close the editor. The listed files are still followed, so the
// tabs and the PDF view catch up once the editor is closed.
watch(
	() => props.song,
	(newSong) => {
		const files = newSong?.files ?? []
		// Keep the active tab when the very same file is still part of the song,
		// e.g. after the editor refreshed the song data.
		const keepsSelection = files.some((file) => String(file.id) === selectedFileId.value)
		selectedFileId.value = keepsSelection ? selectedFileId.value : (files[0] ? String(files[0].id) : '')

		// The editor's form replaces the PDF view, so there is no container to render
		// into while it is open; the render waits until it is visible again.
		if (isEditing.value) {
			renderDeferred = true
			return
		}

		renderSelectedFile()
	},
	{ immediate: true },
)

// The v-if that hides the PDF while editing destroys every rendered canvas, so the
// viewer must always re-render when the editor closes - not only for deferred song
// changes.
watch(isEditing, (editing) => {
	if (!editing) {
		renderDeferred = false
		renderSelectedFile()
	}
})
</script>

<template>
	<div :class="$style.container">
		<header v-if="song" :class="$style.header">
			<div :class="$style.headerTop">
				<NcButton
					:title="t('mobilesheetsmanager', 'Back to songs')"
					:aria-label="t('mobilesheetsmanager', 'Back to songs')"
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
						:title="t('mobilesheetsmanager', 'Open in external viewer')"
						:aria-label="t('mobilesheetsmanager', 'Open in external viewer')"
						@click="openInExternalViewer">
						<template #icon>
							<OpenInNewIcon :size="20" />
						</template>
					</NcButton>
					<NcButton
						variant="secondary"
						:title="t('mobilesheetsmanager', 'Edit song')"
						:aria-label="t('mobilesheetsmanager', 'Edit song')"
						@click="isEditing = true">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
					</NcButton>
					<NcButton
						variant="error"
						:title="t('mobilesheetsmanager', 'Delete song')"
						:aria-label="t('mobilesheetsmanager', 'Delete song')"
						@click="isDeleteDialogOpen = true">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
					</NcButton>
				</div>
			</div>

			<div v-if="!isEditing && (songComposers.length > 0 || songBooks.length > 0 || songCollections.length > 0)" :class="$style.metadata">
				<div v-if="songComposers.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Composer:') }}</span>
					<span>{{ songComposers.map((c) => c.name).join(', ') }}</span>
				</div>
				<div v-if="songBooks.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Book:') }}</span>
					<span>{{ songBooks.map((b) => b.title).join(', ') }}</span>
				</div>
				<div v-if="songCollections.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Collection:') }}</span>
					<span>{{ songCollections.map((c) => c.name).join(', ') }}</span>
				</div>
				<div v-if="songArtists.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Artist:') }}</span>
					<span>{{ songArtists.map((a) => a.name).join(', ') }}</span>
				</div>
			</div>
		</header>

		<!-- Song Editor View -->
		<SongEditor
			v-if="song && isEditing"
			:song="song"
			:library-id="libraryId"
			:composers="composers"
			:books="books"
			:collections="collections"
            :artists="artists"
			@saved="handleSaved"
			@updated="handleUpdated"
			@cancel="isEditing = false" />

		<!-- PDF Viewer View -->
		<template v-else>
			<div v-if="songFiles.length > 1" :class="$style.fileTabs">
				<NcRadioGroup
					:model-value="selectedFileId"
					:label="t('mobilesheetsmanager', 'PDF files of this song')"
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

			<div v-if="isLoading" :class="$style.loading">
				<NcLoadingIcon :size="32" :name="t('mobilesheetsmanager', 'Loading song...')" />
			</div>

			<p v-if="errorMessage" :class="$style.error" role="alert">
				{{ errorMessage }}
			</p>

			<div
				v-show="song && !isLoading && !errorMessage"
				ref="pdfContainer"
				:class="$style.pdfViewer"
				:aria-label="song?.title" />

		</template>

		<NcDialog
			v-if="isDeleteDialogOpen"
			:name="t('mobilesheetsmanager', 'Delete song')"
			@close="isDeleteDialogOpen = false">
			<p>{{ t('mobilesheetsmanager', 'Delete "{title}" from this library?', { title: song?.title ?? '' }) }}</p>
			<p>{{ t('mobilesheetsmanager', 'The song and everything pointing at it is removed from the MobileSheets database.') }}</p>
			<NcCheckboxRadioSwitch v-model="deleteFiles" type="checkbox">
				{{ t('mobilesheetsmanager', 'Delete all attached files') }}
			</NcCheckboxRadioSwitch>
			<p v-if="deleteFiles">
				{{ t('mobilesheetsmanager', 'The {count} attached file(s) are moved to the Nextcloud trash bin, so they can be restored from there.', { count: songFiles.length }) }}
			</p>
			<p v-else>
				{{ t('mobilesheetsmanager', 'The {count} attached file(s) are kept in your Nextcloud files.', { count: songFiles.length }) }}
			</p>
			<p v-if="deleteError" :class="$style.error" role="alert">{{ deleteError }}</p>
			<div :class="$style.dialogActions">
				<NcButton @click="isDeleteDialogOpen = false">
					{{ t('mobilesheetsmanager', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="isDeletingSong"
					@click="confirmDeleteSong">
					<template v-if="isDeletingSong">
						<NcLoadingIcon :size="16" />
					</template>
					{{ t('mobilesheetsmanager', 'Delete song') }}
				</NcButton>
			</div>
		</NcDialog>
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
	margin-bottom: 12px;
	padding: 2px;
	overflow-x: auto;
}

.pdfViewer {
	width: 100%;
	height: 75vh;
	overflow: auto;
	scrollbar-gutter: stable;
	border: 1px solid var(--color-border);
	background: var(--color-background-dark);
	padding: 16px;
}

.pdfViewer :global(.mobilesheetsmanager-pdf-page) {
	display: block;
	max-width: 100%;
	height: auto;
	margin: 0 auto 16px;
	background-color: #ffffff !important;
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
