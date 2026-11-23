<script lang="ts">
export type { SplitSegment } from './SongPdfSplitSidebar.vue'
export type { OriginalSongAction } from './OriginalSongActionSelector.vue'
</script>

<script setup lang="ts">
import type { Song, SongFile, Book, Composer, Collection, Artist, Genre, Instrument, Arranger, Key, CustomGroup, Year, Signature, SourceType, LibraryData } from '../../types/song'
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { PDFDocument } from 'pdf-lib'
import { destroyPdfJsDocument, loadPdfJsDocument } from '../../utils/pdfjs'
import { updateSongFile } from '../../api/songFiles'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getFileName, getPdfFiles, pickPrimarySongFile } from '../../utils/file'
import { fetchUncachedBinaryFile } from '../../utils/file-binary'
import { describeRequestFailure } from '../../utils/api'

import SongPdfHeader from './SongPdfHeader.vue'
import SongPdfToolbar from './SongPdfToolbar.vue'
import SongPdfSaveProgress from './SongPdfSaveProgress.vue'
import SongPdfLoadingState from './SongPdfLoadingState.vue'
import { type BookmarkPageSummary } from './SongPdfPageCard.vue'
import SongPdfPageGrid from './SongPdfPageGrid.vue'
import SongPdfSplitSidebar from './SongPdfSplitSidebar.vue'
import SongPdfVersionsModal from './SongPdfVersionsModal.vue'
import { useFileVersions } from './useFileVersions'
import SongPdfCropModal from './SongPdfCropModal.vue'
import SongPageOrderModal from './SongPageOrderModal.vue'
import SongPageOrderZoomModal from './SongPageOrderZoomModal.vue'
import { usePdfZoomLightbox } from './usePdfZoomLightbox'
import SongPdfJoinModal from './SongPdfJoinModal.vue'
import SongPdfMergeModal from './SongPdfMergeModal.vue'
import SongPdfRotateModal from './SongPdfRotateModal.vue'
import SongPdfEnhanceModal from './SongPdfEnhanceModal.vue'
import { executeJoinTwoPages, type JoinLayout } from './pdf-join-engine'
import { inspectPdfDocumentResolutions, type PdfPageResolutionSummary, pruneUnreferencedPdfObjects } from './pdf-enhance-engine'
import { applyPdfPageEdits, formatPdfSaveSuccessMessage } from './pdf-save-pipeline'
import { usePdfThumbnails } from './usePdfThumbnails'
import { usePdfSplit } from './usePdfSplit'
import SongPdfBookmarksSidebar from './SongPdfBookmarksSidebar.vue'
import { usePdfBookmarks } from './usePdfBookmarks'
import { usePdfPageEdits } from './usePdfPageEdits'
import { useFullscreenBody } from '../../composables/useFullscreenBody'
import FullscreenShell from '../FullscreenShell.vue'


const props = withDefaults(
	defineProps<{
		song: Song
		fileId?: string | number
		libraryId: number
		autoPdfMetadata?: boolean
		initialMode?: 'rotate' | 'split' | 'crop' | 'enhance' | 'bookmarks'
		initialFullscreen?: boolean
		books?: Book[]
		composers?: Composer[]
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
	}>(),
	{
		autoPdfMetadata: true,
		initialFullscreen: false,
		books: () => [],
		composers: () => [],
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
	},
)

const emit = defineEmits<{
	saved: [data: LibraryData, songId?: number]
	cancel: []
}>()

// Target PDF file
const targetFile = computed<SongFile | undefined>(() =>
	pickPrimarySongFile(props.song.files, props.fileId) ?? undefined,
)
const songPdfFiles = computed<SongFile[]>(() => getPdfFiles(props.song.files))

// State
type Mode = 'rotate' | 'split' | 'crop' | 'enhance' | 'bookmarks'
const activeMode = ref<Mode>(props.initialMode ?? 'rotate')
watch(() => props.initialMode, (mode) => {
	if (mode) activeMode.value = mode
})
const thumbnailSize = ref<'small' | 'medium' | 'large'>('medium')

const isLoading = ref(false)
const isSaving = ref(false)
const saveProgressPercent = ref(0)
const saveProgressText = ref('')
const saveProgressCurrent = ref(0)
const saveProgressTotal = ref(0)
const errorMessage = ref('')
const totalPages = ref(0)
const originalPdfBytes = shallowRef<Uint8Array | null>(null)
const activePdfDoc = shallowRef<any>(null)
const pageResolutions = ref<PdfPageResolutionSummary[]>([])

async function refreshPageResolutions(pdf: any) {
	if (!pdf) {
		pageResolutions.value = []
		return
	}
	try {
		pageResolutions.value = await inspectPdfDocumentResolutions(pdf, 25)
	} catch {
		pageResolutions.value = []
	}
}

// Page-specific state (1-indexed, size totalPages)
// Page Order modal state
const isPageOrderModalOpen = ref(false)
const isMergePdfsModalOpen = ref(false)

function handlePageOrderSaved(data: LibraryData) {
	finishAndReturn(() => emit('saved', data, props.song.id))
}

function handlePdfsMerged(data: LibraryData) {
	finishAndReturn(() => emit('saved', data, props.song.id))
}

// Join modal state
const isJoinModalOpen = ref(false)
const joinModalPageA = ref(1)
const joinModalPageB = ref(2)

// Page edits state & helpers (rotations, crops, deletions, enhancements)
const {
	pageRotations,
	pageRotateCropToOriginal,
	pageCrops,
	pageEnhancements,
	deletedPages,
	isRotateModalOpen,
	rotateModalPage,
	isCropModalOpen,
	cropModalPage,
	isEnhanceModalOpen,
	enhanceModalPage,
	hasAnyJoin,
	hasAnyRotation,
	hasAnyCrop,
	hasAnyDeletion,
	remainingPagesCount,
	hasAnyEnhance,
	hasAnyChange,
	resetEditsForPageCount,
	reindexEditsAfterJoin,
	rotatePage,
	rotateAll,
	resetRotations,
	openRotateModal,
	handleApplyCustomRotation,
	openCropModal,
	handleApplyCrop,
	resetAllCrops,
	isPageCropped,
	getCropIndicatorText,
	isPageDeleted,
	toggleDeletePage,
	restoreAllPages,
	openEnhanceModal,
	handleApplyEnhancements,
	resetAllEnhancements,
	getEnhanceIndicatorText,
} = usePdfPageEdits({
	totalPages,
	songTitle: () => props.song.title,
	onEnhanceThumbnailUpdate: (pageNum) => updateThumbnailForPage(pageNum),
})

// Thumbnails
const {
	thumbnailCanvases,
	thumbnailContainers,
	cleanupThumbnailObserver,
	initThumbnailObserver,
	updateThumbnailForPage,
	setContainerRef,
	isPageEnhanced,
} = usePdfThumbnails(activePdfDoc, pageEnhancements)

// Split state & operations
const {
	originalSongAction,
	attachedFilesCount,
	hasExactlyOneFile,
	canRemoveExtractedPages,
	splitSegments,
	selectedPages,
	initSplitSegments,
	addSegment,
	removeSegment,
	assignSelectedPagesToSegment,
	togglePageSelection,
	getSegmentsForPage,
	getPageSegmentsSummary,
	saveSplits: executeSaveSplits,
} = usePdfSplit({
	song: computed(() => props.song),
	libraryId: computed(() => props.libraryId),
	totalPages,
	pageRotations,
	originalPdfBytes,
	targetFile,
	onSaved: (data, targetSongId) => finishAndReturn(() => emit('saved', data, targetSongId)),
	onCancel: () => finishAndReturn(() => emit('cancel')),
})

// Bookmarks state & operations
const {
	bookmarks,
	isExtractingOutline,
	initBookmarksFromSong,
	addBookmark,
	addBookmarkFromSelection,
	removeBookmark,
	updateBookmark,
	assignPagesToBookmark,
	extractFromPdfOutline,
	executeSaveBookmarks,
} = usePdfBookmarks({
	song: computed(() => props.song),
	libraryId: computed(() => props.libraryId),
	totalPages,
	activePdfDoc,
	originalPdfBytes,
	targetFile,
	autoPdfMetadata: computed(() => props.autoPdfMetadata ?? true),
	onPdfUpdated: async (bytes: Uint8Array) => {
		originalPdfBytes.value = bytes
	},
	onSaved: (data, targetSongId) => finishAndReturn(() => emit('saved', data, targetSongId)),
	onCancel: () => finishAndReturn(() => emit('cancel')),
})

function getPageBookmarksSummary(pageNum: number): BookmarkPageSummary[] {
	if (activeMode.value !== 'bookmarks') return []
	const res: BookmarkPageSummary[] = []
	bookmarks.value.forEach((b, idx) => {
		const start = b.pageNum
		const end = b.endPage ?? b.pageNum
		if (pageNum >= start && pageNum <= end) {
			res.push({
				id: b.id,
				name: b.name,
				index: idx + 1,
			})
		}
	})
	return res
}

async function yieldToBrowser() {
	await nextTick()
	await new Promise<void>((resolve) => setTimeout(resolve, 0))
}

async function updateSaveProgress(
	percent: number,
	text: string,
	current = 0,
	total = 0,
	shouldYield = true,
) {
	saveProgressPercent.value = Math.max(0, Math.min(100, Math.round(percent)))
	saveProgressText.value = text
	saveProgressCurrent.value = current
	saveProgressTotal.value = total
	if (shouldYield) {
		await yieldToBrowser()
	} else {
		await nextTick()
	}
}

function resetSaveProgress() {
	saveProgressPercent.value = 0
	saveProgressText.value = ''
	saveProgressCurrent.value = 0
	saveProgressTotal.value = 0
}

async function disposeActivePdfDoc() {
	cleanupThumbnailObserver()
	if (activePdfDoc.value) {
		await destroyPdfJsDocument(activePdfDoc.value)
		activePdfDoc.value = null
	}
}

async function saveSplits() {
	await executeSaveSplits(
		(v) => {
			isSaving.value = v
			if (!v) {
				resetSaveProgress()
			}
		},
		(msg) => { errorMessage.value = msg },
		async (percent, text, current = 0, total = 0) => {
			await updateSaveProgress(percent, text, current, total, false)
		},
	)
}

// Load PDF bytes and initialize
async function loadPdf() {
	if (!targetFile.value?.path) {
		errorMessage.value = t('sheetmusic', 'No PDF file found for this song.')
		showError(errorMessage.value)
		return
	}

	isLoading.value = true
	errorMessage.value = ''
	pageRotations.value = []
	pageRotateCropToOriginal.value = {}
	pageCrops.value = {}
	pageEnhancements.value = {}
	deletedPages.value = new Set()
	hasAnyJoin.value = false
	thumbnailCanvases.value = {}
	selectedPages.value = []

	try {
		await disposeActivePdfDoc()

		const bytes = await fetchUncachedBinaryFile(
			targetFile.value.path,
			`${targetFile.value.id}-${props.song.lastModified ?? ''}-${Date.now()}`,
		)
		originalPdfBytes.value = bytes

		// Pass an independent clone to pdf.js because its worker transfers/detaches the ArrayBuffer
		const pdf = await loadPdfJsDocument(bytes.slice())

		activePdfDoc.value = pdf
		totalPages.value = pdf.numPages
		resetEditsForPageCount(pdf.numPages)
		void refreshPageResolutions(pdf)

		// Initialize split segments: default with 1 segment covering all pages
		initSplitSegments(pdf.numPages, props.song.title)
		initBookmarksFromSong(props.song.bookmarks)

		await nextTick()
		initThumbnailObserver(pdf)
	} catch (err: any) {
		console.error('Failed to load PDF:', err)
		errorMessage.value = t('sheetmusic', 'Failed to load PDF document: {error}', {
			error: err?.message ?? String(err),
		}, { escape: false })
		showError(errorMessage.value)
	} finally {
		isLoading.value = false
	}
}


// Version History state & operations
const {
	isVersionsModalOpen,
	fileVersions,
	isLoadingVersions,
	isRevertingVersion,
	openVersionsModal,
	revertToVersion,
} = useFileVersions({
	libraryId: () => props.libraryId,
	songId: () => props.song.id,
	fileId: () => targetFile.value?.id,
	onReverted: async (data) => {
		finishAndReturn(() => emit('saved', data, props.song.id))
		await loadPdf()
	},
})

function resetAllCurrentEdits() {
	resetRotations()
	resetAllCrops()
	resetAllEnhancements()
	restoreAllPages()
	isVersionsModalOpen.value = false
}

async function saveBookmarks() {
	isSaving.value = true
	try {
		await executeSaveBookmarks(async (pct, text) => {
			await updateSaveProgress(pct, text, 0, 0, false)
		})
	} finally {
		isSaving.value = false
		resetSaveProgress()
	}
}

function handleHeaderSave() {
	if (activeMode.value === 'rotate') {
		saveRotations()
	} else if (activeMode.value === 'crop') {
		saveCrops()
	} else if (activeMode.value === 'enhance') {
		saveEnhancements()
	} else if (activeMode.value === 'split') {
		saveSplits()
	} else if (activeMode.value === 'bookmarks') {
		saveBookmarks()
	}
}

function openJoinModal(pageA: number, pageB?: number) {
	joinModalPageA.value = pageA
	joinModalPageB.value = pageB ?? (pageA < totalPages.value ? pageA + 1 : (pageA > 1 ? pageA - 1 : 1))
	isJoinModalOpen.value = true
}

async function handleExecuteJoin(options: {
	pageA: number
	pageB: number
	layout: JoinLayout
	fitToStandardPage: boolean
	scaleToFit: boolean
	gap: number
	scaleA?: number
	scaleB?: number
}) {
	if (!originalPdfBytes.value) return
	isLoading.value = true
	errorMessage.value = ''

	try {
		const pdfDoc = await PDFDocument.load(originalPdfBytes.value)
		const cropA = pageCrops.value[options.pageA]
		const cropB = pageCrops.value[options.pageB]
		const rotA = pageRotations.value[options.pageA - 1] ?? 0
		const rotB = pageRotations.value[options.pageB - 1] ?? 0

		await executeJoinTwoPages(pdfDoc, {
			pageIdx1: options.pageA - 1,
			pageIdx2: options.pageB - 1,
			layout: options.layout,
			fitToStandardPage: options.fitToStandardPage,
			scaleToFit: options.scaleToFit,
			gap: options.gap,
			crop1: cropA,
			crop2: cropB,
			rotation1: rotA,
			rotation2: rotB,
			scale1: options.scaleA,
			scale2: options.scaleB,
		})

		await pruneUnreferencedPdfObjects(pdfDoc)
		const newBytes = await pdfDoc.save()
		originalPdfBytes.value = newBytes
		reindexEditsAfterJoin(options.pageA, options.pageB)

		await disposeActivePdfDoc()

		// Re-render PDF with pdfjs
		const pdf = await loadPdfJsDocument(newBytes.slice())

		activePdfDoc.value = pdf
		totalPages.value = pdfDoc.getPageCount()
		void refreshPageResolutions(pdf)

		thumbnailCanvases.value = {}
		await nextTick()
		initThumbnailObserver(pdf)

		showSuccess(t('sheetmusic', 'Joined pages {pageA} and {pageB} in "{title}".', {
			pageA: options.pageA,
			pageB: options.pageB,
			title: props.song.title,
		}, { escape: false }))
	} catch (err: any) {
		console.error('Failed to join pages:', err)
		const detail = describeRequestFailure(err, err?.message ?? String(err))
		errorMessage.value = t('sheetmusic', 'Failed to join pages: {error}', {
			error: detail,
		}, { escape: false })
		showError(errorMessage.value)
	} finally {
		isLoading.value = false
	}
}

// Saving Rotated, Cropped, Enhanced, or Modified PDF
async function saveRotationsAndCrops() {
	if (!originalPdfBytes.value || !targetFile.value) return
	isSaving.value = true
	errorMessage.value = ''
	const activeTotal = Math.max(1, totalPages.value - deletedPages.value.size)
	await updateSaveProgress(5, t('sheetmusic', 'Preparing PDF document…'), 0, activeTotal, hasAnyEnhance.value)

	try {
		const pdfDoc = await PDFDocument.load(originalPdfBytes.value)
		const {
			modifiedRotations,
			modifiedCrops,
			modifiedDeletions,
			modifiedEnhancements,
		} = await applyPdfPageEdits({
			pdfDoc,
			activePdfDoc: activePdfDoc.value,
			totalPages: totalPages.value,
			pageRotations: pageRotations.value,
			pageRotateCropToOriginal: pageRotateCropToOriginal.value,
			pageCrops: pageCrops.value,
			pageEnhancements: pageEnhancements.value,
			deletedPages: deletedPages.value,
			isPageEnhanced,
			yieldToBrowser,
			onProgress: updateSaveProgress,
		})

		if (pdfDoc.getPageCount() === 0) {
			throw new Error(t('sheetmusic', 'Cannot save a PDF with no pages.'))
		}

		if (!modifiedRotations && !modifiedCrops && !modifiedDeletions && !modifiedEnhancements && !hasAnyJoin.value) {
			showSuccess(t('sheetmusic', 'No changes to save.'))
			isSaving.value = false
			return
		}

		await updateSaveProgress(85, t('sheetmusic', 'Encoding final PDF document…'), activeTotal, activeTotal, modifiedEnhancements)
		await pruneUnreferencedPdfObjects(pdfDoc)
		const modifiedBytes = await pdfDoc.save()
		const fileName = getFileName(targetFile.value.path) || 'modified.pdf'
		const modifiedFile = new File([modifiedBytes as Uint8Array<ArrayBuffer>], fileName, { type: 'application/pdf' })

		// Update existing file in-place, preserving file ID and triggering Nextcloud file versioning
		await updateSaveProgress(92, t('sheetmusic', 'Uploading PDF to server…'), activeTotal, activeTotal, modifiedEnhancements)
		const uploadRes = await updateSongFile(props.libraryId, props.song.id, targetFile.value.id, modifiedFile)

		await updateSaveProgress(100, t('sheetmusic', 'Saved!'), activeTotal, activeTotal, false)
		const data = uploadRes.data.ocs.data as LibraryData
		const songTitle = props.song.title || t('sheetmusic', 'Song')
		showSuccess(formatPdfSaveSuccessMessage(songTitle, {
			modifiedRotations,
			modifiedCrops,
			modifiedDeletions,
			modifiedEnhancements,
			hasAnyJoin: hasAnyJoin.value,
		}))
		finishAndReturn(() => emit('saved', data, props.song.id))
	} catch (err: any) {
		console.error('Failed to save modified PDF:', err)
		const detail = describeRequestFailure(err, err?.message ?? String(err))
		errorMessage.value = t('sheetmusic', 'Failed to save PDF: {error}', {
			error: detail,
		}, { escape: false })
		showError(errorMessage.value)
	} finally {
		isSaving.value = false
		resetSaveProgress()
	}
}

const saveRotations = saveRotationsAndCrops
const saveCrops = saveRotationsAndCrops
const saveEnhancements = saveRotationsAndCrops

const {
	isFullscreen,
	toggleFullscreen,
	preserveFullscreenOnUnmount,
} = useFullscreenBody(() => Boolean(props.initialFullscreen))

function finishAndReturn(fn: () => void) {
	if (isFullscreen.value) {
		preserveFullscreenOnUnmount()
	}
	fn()
}

function selectAllPages() {
	selectedPages.value = Array.from({ length: totalPages.value }, (_, i) => i + 1)
}

function confirmDiscard(message: string): boolean {
	if (typeof window !== 'undefined' && typeof window.confirm === 'function') {
		return window.confirm(message)
	}
	return true
}

function handleCancel() {
	if (hasAnyChange.value && !confirmDiscard(t('sheetmusic', 'You have unsaved PDF changes. Discard all changes and leave?'))) {
		return
	}
	finishAndReturn(() => emit('cancel'))
}

function handleDiscardAllEdits() {
	if (!hasAnyChange.value) return
	if (!confirmDiscard(t('sheetmusic', 'Discard all unsaved rotations, crops, enhancements, and deletions?'))) {
		return
	}
	resetAllCurrentEdits()
}

// Page Zoom Lightbox Modal state (reuses SongPageOrderZoomModal)
const {
	isZoomModalOpen,
	zoomPageNum,
	zoomHighResCache,
	isLoadingZoomHighRes,
	zoomThumbnailUrl,
	goToZoomPage,
	openPageZoom,
} = usePdfZoomLightbox({
	activePdfDoc,
	totalPages,
	thumbnailCanvases,
})

const isAnyModalOpen = computed(() =>
	isCropModalOpen.value
	|| isRotateModalOpen.value
	|| isEnhanceModalOpen.value
	|| isJoinModalOpen.value
	|| isVersionsModalOpen.value
	|| isPageOrderModalOpen.value
	|| isMergePdfsModalOpen.value
	|| isZoomModalOpen.value,
)

function handleKeyDown(event: KeyboardEvent) {
	if (isAnyModalOpen.value || isSaving.value || isLoading.value) return

	const target = event.target as HTMLElement | null
	if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable)) {
		return
	}

	if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
		event.preventDefault()
		const canSave = activeMode.value === 'split'
			? splitSegments.value.length > 0
			: activeMode.value === 'bookmarks'
				? bookmarks.value.length > 0
				: (hasAnyChange.value && remainingPagesCount.value > 0)
		if (canSave) {
			handleHeaderSave()
		}
		return
	}

	if (event.key === 'Escape' && selectedPages.value.length > 0) {
		selectedPages.value = []
		return
	}

	if (!event.ctrlKey && !event.metaKey && !event.altKey) {
		const modeMap: Record<string, Mode> = {
			1: 'rotate',
			2: 'crop',
			3: 'enhance',
			4: 'split',
			5: 'bookmarks',
		}
		if (modeMap[event.key]) {
			activeMode.value = modeMap[event.key]
		}
	}
}

onMounted(() => {
	loadPdf()
	window.addEventListener('keydown', handleKeyDown)
})

onUnmounted(() => {
	window.removeEventListener('keydown', handleKeyDown)
	void disposeActivePdfDoc()
	originalPdfBytes.value = null
})

watch(() => targetFile.value?.path, () => {
	loadPdf()
})
</script>

<template>
	<FullscreenShell :active="isFullscreen">
		<div :class="$style.container">
		<!-- Top Bar / Sub-header -->
		<SongPdfHeader
			:song="song"
			:target-file="targetFile"
			:total-pages="totalPages"
			:remaining-pages-count="remainingPagesCount"
			:has-any-deletion="hasAnyDeletion"
			:has-any-change="hasAnyChange"
			:has-any-rotation="hasAnyRotation"
			:has-any-crop="hasAnyCrop"
			:has-any-enhance="hasAnyEnhance"
			:has-any-join="hasAnyJoin"
			:active-mode="activeMode"
			:is-saving="isSaving"
			:is-loading="isLoading"
			:split-segments-count="splitSegments.length"
			:bookmarks-count="bookmarks.length"
			:save-progress="saveProgressPercent"
			:file-size-bytes="originalPdfBytes?.byteLength ?? targetFile?.fileSize ?? null"
			:page-resolutions="pageResolutions"
			:is-fullscreen="isFullscreen"
			:pdf-files-count="songPdfFiles.length"
			@update:active-mode="activeMode = $event"
			@cancel="handleCancel"
			@open-page-order-modal="isPageOrderModalOpen = true"
			@open-join-modal="openJoinModal(1, 2)"
			@open-merge-pdfs-modal="isMergePdfsModalOpen = true"
			@open-versions-modal="openVersionsModal"
			@toggle-fullscreen="toggleFullscreen"
			@save="handleHeaderSave" />

		<!-- Saving Progress Bar Banner -->
		<SongPdfSaveProgress
			v-if="isSaving"
			:progress-text="saveProgressText"
			:progress-percent="saveProgressPercent" />

		<!-- Error Banner -->
		<div v-if="errorMessage" :class="$style.errorBanner">
			{{ errorMessage }}
		</div>

		<!-- Main Workspace Area -->
		<SongPdfLoadingState v-if="isLoading" />

		<div v-else :class="$style.contentLayout">
			<!-- Left / Main Panel: Page Preview Grid -->
			<div :class="$style.previewSection">
				<!-- Global Toolbar for Active Mode -->
				<SongPdfToolbar
					:active-mode="activeMode"
					:total-pages="totalPages"
					:has-any-rotation="hasAnyRotation"
					:has-any-crop="hasAnyCrop"
					:has-any-enhance="hasAnyEnhance"
					:has-any-deletion="hasAnyDeletion"
					:has-any-change="hasAnyChange"
					:deleted-pages-count="deletedPages.size"
					:selected-pages-count="selectedPages.length"
					:thumbnail-size="thumbnailSize"
					@rotate-all="rotateAll($event)"
					@open-rotate-modal="openRotateModal(1)"
					@reset-rotations="resetRotations"
					@restore-all-pages="restoreAllPages"
					@open-crop-modal="openCropModal(1)"
					@reset-all-crops="resetAllCrops"
					@open-enhance-modal="openEnhanceModal(1)"
					@reset-all-enhancements="resetAllEnhancements"
					@clear-selection="selectedPages = []"
					@select-all-pages="selectAllPages"
					@discard-all-edits="handleDiscardAllEdits"
					@update:thumbnail-size="thumbnailSize = $event" />

				<!-- Thumbnail Grid -->
				<SongPdfPageGrid
					:total-pages="totalPages"
					:active-mode="activeMode"
					:selected-pages="selectedPages"
					:is-page-cropped="isPageCropped"
					:is-page-enhanced="isPageEnhanced"
					:is-page-deleted="isPageDeleted"
					:page-rotations="pageRotations"
					:page-crops="pageCrops"
					:thumbnail-size="thumbnailSize"
					:get-crop-indicator-text="getCropIndicatorText"
					:get-enhance-indicator-text="getEnhanceIndicatorText"
					:get-page-segments-summary="getPageSegmentsSummary"
					:get-page-bookmarks-summary="getPageBookmarksSummary"
					:remaining-pages-count="remainingPagesCount"
					@card-click="(activeMode === 'split' || activeMode === 'bookmarks') ? togglePageSelection($event, false) : undefined"
					@card-range-select="(activeMode === 'split' || activeMode === 'bookmarks') ? togglePageSelection($event, true) : undefined"
					@zoom-page="openPageZoom"
					@open-crop-modal="openCropModal"
					@open-enhance-modal="openEnhanceModal"
					@rotate-page="rotatePage"
					@open-rotate-modal="openRotateModal"
					@open-join-modal="openJoinModal"
					@toggle-delete-page="toggleDeletePage"
					@set-container-ref="setContainerRef" />
			</div>

			<SongPdfSplitSidebar
				v-if="activeMode === 'split'"
				v-model:original-song-action="originalSongAction"
				:segments="splitSegments"
				:attached-files-count="attachedFilesCount"
				:can-remove-pages="canRemoveExtractedPages"
				:total-pages="totalPages"
				:selected-pages="selectedPages"
				@add-segment="addSegment"
				@remove-segment="removeSegment"
				@assign-selected="assignSelectedPagesToSegment" />

			<SongPdfBookmarksSidebar
				v-if="activeMode === 'bookmarks'"
				:bookmarks="bookmarks"
				:total-pages="totalPages"
				:selected-pages="selectedPages"
				:is-extracting-outline="isExtractingOutline"
				:auto-pdf-metadata="props.autoPdfMetadata ?? true"
				@add-bookmark="addBookmark"
				@add-bookmark-from-selection="() => {
					if (selectedPages.length > 0) {
						addBookmarkFromSelection(Math.min(...selectedPages), Math.max(...selectedPages))
						selectedPages = []
					}
				}"
				@remove-bookmark="removeBookmark"
				@update-bookmark="updateBookmark"
				@assign-selected="(id) => {
					if (selectedPages.length > 0) {
						assignPagesToBookmark(id, Math.min(...selectedPages), Math.max(...selectedPages))
						selectedPages = []
					}
				}"
				@extract-outline="extractFromPdfOutline" />
		</div>

		<!-- Page Crop Modal -->
		<SongPdfCropModal
			v-model:open="isCropModalOpen"
			:page-num="cropModalPage"
			:total-pages="totalPages"
			:pdf="activePdfDoc"
			:initial-crop="pageCrops[cropModalPage]"
			:all-pages-count="totalPages"
			:rotation="pageRotations[cropModalPage - 1]"
			@apply="handleApplyCrop"
			@change-page="cropModalPage = $event" />

		<!-- Page Join Modal -->
		<SongPdfJoinModal
			v-model:open="isJoinModalOpen"
			:pdf="activePdfDoc"
			:total-pages="totalPages"
			:initial-page-a="joinModalPageA"
			:initial-page-b="joinModalPageB"
			:page-crops="pageCrops"
			:page-rotations="pageRotations"
			@join="handleExecuteJoin" />

		<!-- Custom Angle Rotate Modal -->
		<SongPdfRotateModal
			v-model:open="isRotateModalOpen"
			:page-num="rotateModalPage"
			:total-pages="totalPages"
			:pdf="activePdfDoc"
			:initial-angle="pageRotations[rotateModalPage - 1] || 0"
			:initial-crop-to-original-size="pageRotateCropToOriginal[rotateModalPage] || false"
			:all-pages-count="totalPages"
			:crop="pageCrops[rotateModalPage]"
			@apply="handleApplyCustomRotation"
			@change-page="rotateModalPage = $event" />

		<!-- Page Enhance Modal -->
		<SongPdfEnhanceModal
			v-model:open="isEnhanceModalOpen"
			:page-num="enhanceModalPage"
			:total-pages="totalPages"
			:pdf="activePdfDoc"
			:initial-operations="pageEnhancements[enhanceModalPage]"
			:rotation="pageRotations[enhanceModalPage - 1]"
			@apply="handleApplyEnhancements"
			@change-page="enhanceModalPage = $event" />

		<!-- File Version History Dialog -->
		<SongPdfVersionsModal
			v-model:open="isVersionsModalOpen"
			:versions="fileVersions"
			:is-loading="isLoadingVersions"
			:is-reverting="isRevertingVersion"
			:has-unsaved-changes="hasAnyChange"
			@discard-unsaved="resetAllCurrentEdits"
			@revert="revertToVersion" />

		<!-- Page Order Modal -->
		<SongPageOrderModal
			v-if="targetFile && isPageOrderModalOpen"
			v-model:open="isPageOrderModalOpen"
			:file="targetFile"
			:song-id="song.id"
			:library-id="libraryId"
			:pdf="activePdfDoc"
			@saved="handlePageOrderSaved" />

		<!-- Merge PDFs Modal -->
		<SongPdfMergeModal
			v-if="isMergePdfsModalOpen"
			v-model:open="isMergePdfsModalOpen"
			:song="song"
			:library-id="libraryId"
			:pdf-files="songPdfFiles"
			@merged="handlePdfsMerged" />

		<!-- Page Zoom Lightbox Modal -->
		<SongPageOrderZoomModal
			v-model:open="isZoomModalOpen"
			:page-num="zoomPageNum"
			:total-pages="totalPages"
			:high-res-url="zoomHighResCache[zoomPageNum]"
			:thumbnail-url="zoomThumbnailUrl"
			:is-loading-high-res="isLoadingZoomHighRes"
			:show-append-action="false"
			:rotation-deg="pageRotations[zoomPageNum - 1] || 0"
			@prev="goToZoomPage(zoomPageNum - 1)"
			@next="goToZoomPage(zoomPageNum + 1)" />
		</div>
	</FullscreenShell>
</template>

<style module>
.container {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: calc(100vh - var(--header-height, 50px) - 80px);
	height: calc(100dvh - var(--header-height, 50px) - 80px);
	max-height: calc(100vh - var(--header-height, 50px) - 80px);
	max-height: calc(100dvh - var(--header-height, 50px) - 80px);
	min-height: 420px;
	background-color: var(--color-main-background);
	overflow: hidden;
}

.errorBanner {
	background-color: var(--color-error);
	color: var(--color-primary-text);
	padding: 8px 16px;
	font-size: 13px;
}

.contentLayout {
	display: flex;
	flex: 1;
	min-height: 0;
	overflow: hidden;
}

.previewSection {
	flex: 1;
	display: flex;
	flex-direction: column;
	min-width: 0;
	min-height: 0;
	height: 100%;
	overflow: hidden;
}

@media (max-width: 840px) {
	.container {
		height: calc(100vh - var(--header-height, 50px));
		height: calc(100dvh - var(--header-height, 50px));
		max-height: 100vh;
		max-height: 100dvh;
		min-height: 360px;
	}

	.contentLayout {
		flex-direction: column;
	}

	.previewSection {
		flex: 1 1 0;
		min-height: 220px;
	}
}
</style>
