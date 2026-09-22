<script setup lang="ts">
import type { Artist, Book, Collection, Composer, LibraryData, Song, SongFile } from '../types/song'

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
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
	saved: [data: LibraryData]
	updated: [data: LibraryData]
}>()

GlobalWorkerOptions.workerSrc = pdfWorker

const pdfContainer = ref<HTMLElement>()
const isLoading = ref(false)
const errorMessage = ref('')
const isEditing = ref(false)
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

function getFileName(filePath: string): string {
	return filePath.split('/').pop() ?? filePath
}

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
function handleSaved(data: LibraryData) {
	isEditing.value = false
	emit('saved', data)
}

/**
 * The editor changed the song's files without saving (upload/attach). The editor has to
 * stay open, so the data is only passed on and 'saved' - which closes it - is not emitted.
 */
function handleUpdated(data: LibraryData) {
	emit('updated', data)
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

		const pdf = await getDocument({ data: response.data }).promise

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
			canvas.width = viewport.width
			canvas.height = viewport.height
			canvas.className = 'mobilesheetsmanager-pdf-page'
			pdfContainer.value?.append(canvas)
			await page.render({ canvas, viewport }).promise
		}
	} catch {
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

// Catch up on the render that the open editor deferred, e.g. after a file was uploaded.
watch(isEditing, (editing) => {
	if (!editing && renderDeferred) {
		renderDeferred = false
		renderSelectedFile()
	}
})
</script>

<template>
	<div :class="$style.container">
		<header v-if="song" :class="$style.header">
			<div :class="$style.headerTop">
				<h3 :class="$style.title">{{ song.title }}</h3>
				<NcButton
					v-if="!isEditing"
					variant="secondary"
					@click="isEditing = true">
					{{ t('mobilesheetsmanager', 'Edit song') }}
				</NcButton>
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

			<div v-if="!song" :class="$style.emptyState">
				<p>{{ t('mobilesheetsmanager', 'Select a song to view.') }}</p>
			</div>
		</template>
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
}

.title {
	margin: 0;
	font-size: 1.2rem;
	font-weight: 600;
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
	border: 1px solid var(--color-border);
	background: var(--color-background-dark);
	padding: 16px;
}

.pdfViewer :global(.mobilesheetsmanager-pdf-page) {
	display: block;
	max-width: 100%;
	height: auto;
	margin: 0 auto 16px;
	background: white;
}

.emptyState {
	padding: 32px 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.error {
	color: var(--color-error);
	margin: 16px 0;
}
</style>
