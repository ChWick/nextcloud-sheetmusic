<script setup lang="ts">
import type { Artist, Book, Collection, Composer, Genre, LibraryData, Song, SongFile } from '../types/song'

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
import RotateRight from 'vue-material-design-icons/RotateRight.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import ViewDay from 'vue-material-design-icons/ViewDay.vue'
import ViewStream from 'vue-material-design-icons/ViewStream.vue'
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import SongEditor from './SongEditor.vue'

const props = withDefaults(
	defineProps<{
		song?: Song | null
		libraryId?: number
		composers?: Composer[]
		books?: Book[]
		collections?: Collection[]
		artists?: Artist[]
		genres?: Genre[]
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
		hideHeader: false,
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
let currentRenderId = 0
/** Set when the song changed while the editor covered the PDF view, which defers the render. */
let renderDeferred = false

// Cached loaded PDF document to allow rapid local re-rendering on zoom/page/layout changes
const loadedPdf = shallowRef<any>(null)
const totalPages = ref(1)
const currentPage = ref(1)

// Viewer controls: layout, zoom, rotation
type PageLayoutMode = 'continuous' | 'single' | 'double'
type ZoomMode = 'fit-width' | 'fit-page' | 'custom'

const pageLayout = ref<PageLayoutMode>('continuous')
const zoomMode = ref<ZoomMode>('fit-width')
const customScale = ref(1.0)
const effectiveScale = ref(1.0)
const rotation = ref(0)
const imageScale = ref(1.0)

const zoomSteps = [0.25, 0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0, 2.5, 3.0, 4.0]

const songComposers = computed(() => props.song?.composers ?? [])

const songBooks = computed(() => props.song?.books ?? [])

const songCollections = computed(() => props.song?.collections ?? [])

const songArtists = computed(() => props.song?.artists ?? [])

const songGenres = computed(() => props.song?.genres ?? [])

const songFiles = computed(() => props.song?.files ?? [])

/** The file of the active tab, falling back to the first file of the song. */
const selectedFile = computed<SongFile | null>(() => {
	const files = songFiles.value
	return files.find((file) => String(file.id) === selectedFileId.value) ?? files[0] ?? null
})

const isPdfFile = computed(() => {
	const path = selectedFile.value?.path ?? ''
	return path.toLowerCase().endsWith('.pdf')
})

const isImageFile = computed(() => {
	const path = (selectedFile.value?.path ?? '').toLowerCase()
	return path.endsWith('.png') || path.endsWith('.jpg') || path.endsWith('.jpeg') || path.endsWith('.webp') || path.endsWith('.gif') || path.endsWith('.svg') || path.endsWith('.bmp')
})

const isUnsupportedFile = computed(() => {
	return !isPdfFile.value && !isImageFile.value
})

const currentPageDisplay = computed(() => {
	if (pageLayout.value === 'double') {
		const next = Math.min(currentPage.value + 1, totalPages.value)
		return currentPage.value === next ? `${currentPage.value}` : `${currentPage.value}–${next}`
	}
	if (pageLayout.value === 'continuous') {
		return `1–${totalPages.value || 1}`
	}
	return `${currentPage.value}`
})

const zoomSelectValue = computed(() => {
	if (zoomMode.value === 'fit-width') return 'fit-width'
	if (zoomMode.value === 'fit-page') return 'fit-page'
	const matches = [0.5, 0.75, 1.0, 1.25, 1.5, 2.0].some((val) => Math.abs(val - customScale.value) < 0.01)
	return matches ? String(customScale.value) : 'custom'
})

const isCustomZoom = computed(() => {
	if (zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page') return false
	return ![0.5, 0.75, 1.0, 1.25, 1.5, 2.0].some((val) => Math.abs(val - customScale.value) < 0.01)
})

const imageStyle = computed(() => ({
	transform: `rotate(${rotation.value}deg) scale(${imageScale.value})`,
	transformOrigin: 'center center',
	transition: 'transform 0.15s ease-out',
}))

/** Tab labels are kept short so that many files stay readable. */
function getFileLabel(file: SongFile, index: number): string {
	const name = getFileName(file.path)
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
 * Open the selected file in an external viewer.
 */
function openInExternalViewer() {
	const filePath = selectedFile.value?.path
	if (!filePath) {
		return
	}
	openFileExternally(filePath)
}

/**
 * The editor changed the song's files without saving (upload/attach).
 */
function handleUpdated(data: LibraryData) {
	emit('updated', data)
}

/**
 * Delete the song.
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
		const data = response.data?.ocs?.data
		emit('deleted', data, songId)
	} catch (error) {
		deleteError.value = describeRequestFailure(error, t('mobilesheetsmanager', 'Could not delete the song.'))
	} finally {
		isDeletingSong.value = false
	}
}

function prevPage() {
	if (pageLayout.value === 'double') {
		currentPage.value = Math.max(1, currentPage.value - 2)
	} else {
		currentPage.value = Math.max(1, currentPage.value - 1)
	}
	renderCurrentView()
}

function nextPage() {
	if (pageLayout.value === 'double') {
		if (currentPage.value + 2 <= totalPages.value) {
			currentPage.value += 2
		} else if (currentPage.value + 1 <= totalPages.value) {
			currentPage.value += 1
		}
	} else {
		currentPage.value = Math.min(totalPages.value, currentPage.value + 1)
	}
	renderCurrentView()
}

function setPageLayout(mode: PageLayoutMode) {
	if (pageLayout.value === mode) return
	pageLayout.value = mode
	renderCurrentView()
}

function setZoomMode(mode: ZoomMode) {
	zoomMode.value = mode
	if (isImageFile.value) {
		imageScale.value = mode === 'fit-width' ? 1.0 : 0.75
		return
	}
	renderCurrentView()
}

function onZoomSelect(event: Event) {
	const val = (event.target as HTMLSelectElement).value
	if (val === 'fit-width' || val === 'fit-page') {
		setZoomMode(val)
	} else if (val !== 'custom') {
		const scale = parseFloat(val)
		if (!Number.isNaN(scale) && scale > 0) {
			zoomMode.value = 'custom'
			customScale.value = scale
			if (isImageFile.value) {
				imageScale.value = scale
			} else {
				renderCurrentView()
			}
		}
	}
}

function zoomIn() {
	if (isImageFile.value) {
		imageScale.value = Math.min(4.0, +(imageScale.value + 0.25).toFixed(2))
		return
	}
	const current = effectiveScale.value
	const next = zoomSteps.find((s) => s > current + 0.05) ?? Math.min(4.0, +(current + 0.25).toFixed(2))
	zoomMode.value = 'custom'
	customScale.value = next
	renderCurrentView()
}

function zoomOut() {
	if (isImageFile.value) {
		imageScale.value = Math.max(0.25, +(imageScale.value - 0.25).toFixed(2))
		return
	}
	const current = effectiveScale.value
	const prev = [...zoomSteps].reverse().find((s) => s < current - 0.05) ?? Math.max(0.25, +(current - 0.25).toFixed(2))
	zoomMode.value = 'custom'
	customScale.value = prev
	renderCurrentView()
}

function rotateClockwise() {
	rotation.value = (rotation.value + 90) % 360
	if (isPdfFile.value) {
		renderCurrentView()
	}
}

async function renderCurrentView() {
	if (!loadedPdf.value || !pdfContainer.value) {
		return
	}

	const renderId = ++currentRenderId
	const pdf = loadedPdf.value
	const total = pdf.numPages

	await nextTick()
	if (!pdfContainer.value || renderId !== currentRenderId) {
		return
	}

	pdfContainer.value.replaceChildren()

	// Determine which pages to render based on layout
	let pagesToRender: number[] = []
	if (pageLayout.value === 'single') {
		pagesToRender = [Math.min(Math.max(1, currentPage.value), total)]
	} else if (pageLayout.value === 'double') {
		const start = Math.min(Math.max(1, currentPage.value), total)
		pagesToRender = [start]
		if (start + 1 <= total) {
			pagesToRender.push(start + 1)
		}
	} else {
		// Continuous: render all pages
		pagesToRender = Array.from({ length: total }, (_, i) => i + 1)
	}

	for (const pageNumber of pagesToRender) {
		if (renderId !== currentRenderId) {
			return
		}

		const page = await pdf.getPage(pageNumber)
		const unscaledViewport = page.getViewport({ scale: 1.0, rotation: rotation.value })

		let scale = 1.25
		if (zoomMode.value === 'fit-width') {
			const containerW = (pdfContainer.value?.clientWidth && pdfContainer.value.clientWidth > 100)
				? pdfContainer.value.clientWidth - 48
				: 800
			const targetW = pageLayout.value === 'double' ? (containerW - 24) / 2 : containerW
			scale = targetW / unscaledViewport.width
		} else if (zoomMode.value === 'fit-page') {
			const containerH = (pdfContainer.value?.clientHeight && pdfContainer.value.clientHeight > 100)
				? pdfContainer.value.clientHeight - 48
				: 700
			scale = containerH / unscaledViewport.height
		} else {
			scale = customScale.value
		}

		scale = Math.max(0.2, Math.min(scale, 4.0))
		effectiveScale.value = scale

		const viewport = page.getViewport({ scale, rotation: rotation.value })
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
}

async function renderSelectedFile() {
	const loadId = ++currentLoadId
	errorMessage.value = ''
	loadedPdf.value = null
	totalPages.value = 1
	currentPage.value = 1

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

	if (!isPdfFile.value) {
		isLoading.value = false
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

		loadedPdf.value = pdf
		totalPages.value = pdf.numPages
		currentPage.value = 1

		await renderCurrentView()
	} catch (error) {
		console.error('Failed to load or render PDF:', error)
		if (loadId === currentLoadId) {
			errorMessage.value = t('mobilesheetsmanager', 'Could not load the selected file.')
		}
	} finally {
		if (loadId === currentLoadId) {
			isLoading.value = false
		}
	}
}

function handleResize() {
	if (zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page') {
		renderCurrentView()
	}
}

onMounted(() => {
	window.addEventListener('resize', handleResize)
})

onUnmounted(() => {
	window.removeEventListener('resize', handleResize)
})

// Picking another song leaves any open editing session behind.
watch(
	() => props.song?.id,
	(newSongId, previousSongId) => {
		if (newSongId !== previousSongId) {
			isEditing.value = false
		}
	},
)

// The song the editor works on can be refreshed in place.
watch(
	() => props.song,
	(newSong) => {
		const files = newSong?.files ?? []
		const keepsSelection = files.some((file) => String(file.id) === selectedFileId.value)
		selectedFileId.value = keepsSelection ? selectedFileId.value : (files[0] ? String(files[0].id) : '')

		if (isEditing.value) {
			renderDeferred = true
			return
		}

		renderSelectedFile()
	},
	{ immediate: true },
)

// The v-if that hides the PDF while editing destroys every rendered canvas, so the
// viewer must always re-render when the editor closes.
watch(isEditing, (editing) => {
	if (!editing) {
		renderDeferred = false
		renderSelectedFile()
	}
})

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

			<div v-if="!isEditing && (songComposers.length > 0 || songBooks.length > 0 || songCollections.length > 0 || songArtists.length > 0 || songGenres.length > 0 || song.stars || song.difficulty || song.duration || song.keywords || song.custom || song.custom2)" :class="$style.metadata">
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
				<div v-if="songGenres.length > 0" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Genre:') }}</span>
					<span>{{ songGenres.map((g) => g.name).join(', ') }}</span>
				</div>
				<div v-if="song.stars" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Rating:') }}</span>
					<span>{{ formatStars(song.stars) }}</span>
				</div>
				<div v-if="song.difficulty" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Difficulty:') }}</span>
					<span>{{ formatDifficulty(song.difficulty) }}</span>
				</div>
				<div v-if="song.duration" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Duration:') }}</span>
					<span>{{ formatDuration(song.duration) }}</span>
				</div>
				<div v-if="song.keywords" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Keywords:') }}</span>
					<span>{{ song.keywords }}</span>
				</div>
				<div v-if="song.custom" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Custom:') }}</span>
					<span>{{ song.custom }}</span>
				</div>
				<div v-if="song.custom2" :class="$style.metaItem">
					<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Custom 2:') }}</span>
					<span>{{ song.custom2 }}</span>
				</div>
			</div>
		</header>

		<!-- Metadata bar when header is integrated into top bar -->
		<div v-if="song && hideHeader && !isEditing && (songComposers.length > 0 || songBooks.length > 0 || songCollections.length > 0 || songArtists.length > 0 || songGenres.length > 0 || song.stars || song.difficulty || song.duration || song.keywords || song.custom || song.custom2)" :class="$style.metadata">
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
			<div v-if="songGenres.length > 0" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Genre:') }}</span>
				<span>{{ songGenres.map((g) => g.name).join(', ') }}</span>
			</div>
			<div v-if="song.stars" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Rating:') }}</span>
				<span>{{ formatStars(song.stars) }}</span>
			</div>
			<div v-if="song.difficulty" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Difficulty:') }}</span>
				<span>{{ formatDifficulty(song.difficulty) }}</span>
			</div>
			<div v-if="song.duration" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Duration:') }}</span>
				<span>{{ formatDuration(song.duration) }}</span>
			</div>
			<div v-if="song.keywords" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Keywords:') }}</span>
				<span>{{ song.keywords }}</span>
			</div>
			<div v-if="song.custom" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Custom:') }}</span>
				<span>{{ song.custom }}</span>
			</div>
			<div v-if="song.custom2" :class="$style.metaItem">
				<span :class="$style.metaLabel">{{ t('mobilesheetsmanager', 'Custom 2:') }}</span>
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
			@saved="handleSaved"
			@updated="handleUpdated"
			@cancel="isEditing = false" />

		<!-- PDF / File Viewer View -->
		<template v-else>
			<div v-if="songFiles.length > 1" :class="$style.fileTabs">
				<NcRadioGroup
					:model-value="selectedFileId"
					:label="t('mobilesheetsmanager', 'Files of this song')"
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

			<!-- PDF / Image Viewer Toolbar -->
			<div v-if="song && !isLoading && !errorMessage && (isPdfFile || isImageFile)" :class="$style.toolbar">
				<!-- Left: Page navigation (for multi-page PDFs) -->
				<div :class="$style.toolbarGroup">
					<template v-if="isPdfFile">
						<NcButton
							variant="secondary"
							size="small"
							:disabled="currentPage <= 1 || pageLayout === 'continuous'"
							:title="t('mobilesheetsmanager', 'Previous page')"
							:aria-label="t('mobilesheetsmanager', 'Previous page')"
							@click="prevPage">
							<template #icon>
								<ChevronLeft :size="20" />
							</template>
						</NcButton>
						<span :class="$style.pageIndicator">
							{{ t('mobilesheetsmanager', 'Page {current} of {total}', { current: currentPageDisplay, total: totalPages || 1 }) }}
						</span>
						<NcButton
							variant="secondary"
							size="small"
							:disabled="currentPage >= totalPages || pageLayout === 'continuous'"
							:title="t('mobilesheetsmanager', 'Next page')"
							:aria-label="t('mobilesheetsmanager', 'Next page')"
							@click="nextPage">
							<template #icon>
								<ChevronRight :size="20" />
							</template>
						</NcButton>
					</template>
				</div>

				<!-- Center: Multi-page Layout Selector (for PDFs) -->
				<div v-if="isPdfFile" :class="$style.toolbarGroup">
					<div :class="$style.segmentedGroup">
						<NcButton
							size="small"
							:variant="pageLayout === 'continuous' ? 'primary' : 'secondary'"
							:title="t('mobilesheetsmanager', 'Continuous scroll (All pages)')"
							:aria-label="t('mobilesheetsmanager', 'Continuous scroll (All pages)')"
							@click="setPageLayout('continuous')">
							<template #icon>
								<ViewStream :size="18" />
							</template>
						</NcButton>
						<NcButton
							size="small"
							:variant="pageLayout === 'single' ? 'primary' : 'secondary'"
							:title="t('mobilesheetsmanager', 'Single page')"
							:aria-label="t('mobilesheetsmanager', 'Single page')"
							@click="setPageLayout('single')">
							<template #icon>
								<ViewDay :size="18" />
							</template>
						</NcButton>
						<NcButton
							size="small"
							:variant="pageLayout === 'double' ? 'primary' : 'secondary'"
							:title="t('mobilesheetsmanager', 'Two pages side-by-side')"
							:aria-label="t('mobilesheetsmanager', 'Two pages side-by-side')"
							@click="setPageLayout('double')">
							<template #icon>
								<ViewColumn :size="18" />
							</template>
						</NcButton>
					</div>
				</div>

				<!-- Right: Zoom & Rotate Controls -->
				<div :class="$style.toolbarGroup">
					<NcButton
						variant="secondary"
						size="small"
						:title="t('mobilesheetsmanager', 'Zoom out')"
						:aria-label="t('mobilesheetsmanager', 'Zoom out')"
						@click="zoomOut">
						<template #icon>
							<MagnifyMinus :size="18" />
						</template>
					</NcButton>

					<div :class="$style.zoomSelector">
						<select
							:value="zoomSelectValue"
							:aria-label="t('mobilesheetsmanager', 'Zoom level')"
							:class="$style.zoomDropdown"
							@change="onZoomSelect">
							<option value="fit-width">{{ t('mobilesheetsmanager', 'Fit width') }}</option>
							<option value="fit-page">{{ t('mobilesheetsmanager', 'Fit page') }}</option>
							<option value="0.5">50%</option>
							<option value="0.75">75%</option>
							<option value="1.0">100%</option>
							<option value="1.25">125%</option>
							<option value="1.5">150%</option>
							<option value="2.0">200%</option>
							<option v-if="isCustomZoom" value="custom">
								{{ Math.round((isImageFile ? imageScale : effectiveScale) * 100) }}%
							</option>
						</select>
					</div>

					<NcButton
						variant="secondary"
						size="small"
						:title="t('mobilesheetsmanager', 'Zoom in')"
						:aria-label="t('mobilesheetsmanager', 'Zoom in')"
						@click="zoomIn">
						<template #icon>
							<MagnifyPlus :size="18" />
						</template>
					</NcButton>

					<NcButton
						variant="secondary"
						size="small"
						:title="t('mobilesheetsmanager', 'Fit to width')"
						:aria-label="t('mobilesheetsmanager', 'Fit to width')"
						@click="setZoomMode('fit-width')">
						<template #icon>
							<FitToScreenOutline :size="18" />
						</template>
					</NcButton>

					<NcButton
						variant="secondary"
						size="small"
						:title="t('mobilesheetsmanager', 'Rotate clockwise')"
						:aria-label="t('mobilesheetsmanager', 'Rotate clockwise')"
						@click="rotateClockwise">
						<template #icon>
							<RotateRight :size="18" />
						</template>
					</NcButton>
				</div>
			</div>

			<div v-if="isLoading" :class="$style.loading">
				<NcLoadingIcon :size="32" :name="t('mobilesheetsmanager', 'Loading song...')" />
			</div>

			<p v-if="errorMessage" :class="$style.error" role="alert">
				{{ errorMessage }}
			</p>

			<div
				v-if="song && !isLoading && !errorMessage && isImageFile"
				:class="$style.imageViewer">
				<img
					:src="selectedFile?.path"
					:alt="song?.title"
					:class="$style.viewerImage"
					:style="imageStyle" />
			</div>

			<div
				v-if="song && !isLoading && !errorMessage && isUnsupportedFile"
				:class="$style.nonPdfContainer">
				<p>{{ t('mobilesheetsmanager', 'This file format cannot be rendered in the embedded viewer.') }}</p>
				<NcButton
					variant="secondary"
					:title="t('mobilesheetsmanager', 'Open in external viewer')"
					:aria-label="t('mobilesheetsmanager', 'Open in external viewer')"
					@click="openInExternalViewer">
					<template #icon>
						<OpenInNewIcon :size="20" />
					</template>
					{{ t('mobilesheetsmanager', 'Open file') }}
				</NcButton>
			</div>

			<div
				v-show="song && !isLoading && !errorMessage && isPdfFile"
				ref="pdfContainer"
				:class="[
					$style.pdfViewer,
					pageLayout === 'continuous' ? $style.layoutContinuous : '',
					pageLayout === 'single' ? $style.layoutSingle : '',
					pageLayout === 'double' ? $style.layoutDouble : '',
				]"
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

.pdfViewer :global(.mobilesheetsmanager-pdf-page) {
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
