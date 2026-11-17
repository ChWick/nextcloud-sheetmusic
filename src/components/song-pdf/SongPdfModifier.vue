<script lang="ts">
export type { SplitSegment } from './SongPdfSplitSidebar.vue'
export type { OriginalSongAction } from './OriginalSongActionSelector.vue'
</script>

<script setup lang="ts">
import type { Song, SongFile, Book, Composer, Collection, Artist, Genre, Instrument, Arranger, Key, CustomGroup, Year, Signature, SourceType, LibraryData } from '../../types/song'
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { PDFDocument, degrees } from 'pdf-lib'
import { apiDelete, apiPost } from '../../api/client'
import { showError, showInfo, showSuccess } from '@nextcloud/dialogs'
import { getCacheBustedFileUrl, getFileName } from '../../utils/file'
import { describeRequestFailure } from '../../utils/api'
import type { OriginalSongAction } from './OriginalSongActionSelector.vue'

import SongPdfHeader from './SongPdfHeader.vue'
import SongPdfToolbar from './SongPdfToolbar.vue'
import SongPdfSaveProgress from './SongPdfSaveProgress.vue'
import SongPdfLoadingState from './SongPdfLoadingState.vue'
import SongPdfPageCard, { type BookmarkPageSummary } from './SongPdfPageCard.vue'
import SongPdfPageGrid from './SongPdfPageGrid.vue'
import SongPdfSplitSidebar, { getSegmentColor, type SplitSegment } from './SongPdfSplitSidebar.vue'
import SongPdfVersionsModal from './SongPdfVersionsModal.vue'
import { useFileVersions } from './useFileVersions'
import SongPdfCropModal from './SongPdfCropModal.vue'
import SongPageOrderModal from './SongPageOrderModal.vue'
import SongPdfJoinModal from './SongPdfJoinModal.vue'
import SongPdfRotateModal from './SongPdfRotateModal.vue'
import SongPdfEnhanceModal from './SongPdfEnhanceModal.vue'
import { computeCropBoxForRotation, type PageCrop } from './crop-types'
import { executeJoinTwoPages, type JoinLayout } from './pdf-join-engine'
import { executeRotatePage } from './pdf-rotate-engine'
import type { ScanOperation } from './scan-types'
import { applyPipeline, createImageBuffer, imageDataToImageBuffer, imageBufferToImageData } from './scan-enhance-engine'
import { executeEnhancePage, inspectPdfPageResolution, type PdfPageResolutionSummary, pruneUnreferencedPdfObjects, resolvePageRenderScale } from './pdf-enhance-engine'
import { usePdfThumbnails } from './usePdfThumbnails'
import { usePdfSplit } from './usePdfSplit'
import SongPdfBookmarksSidebar from './SongPdfBookmarksSidebar.vue'
import { usePdfBookmarks } from './usePdfBookmarks'
import { usePdfPageEdits } from './usePdfPageEdits'

GlobalWorkerOptions.workerSrc = pdfWorker


const props = withDefaults(
	defineProps<{
		song: Song
		fileId?: string | number
		libraryId: number
		autoPdfMetadata?: boolean
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
const targetFile = computed<SongFile | undefined>(() => {
	const files = props.song.files ?? []
	if (props.fileId) {
		const found = files.find((f) => String(f.id) === String(props.fileId))
		if (found) return found
	}
	return files.find((f) => f.path.toLowerCase().endsWith('.pdf')) ?? files[0]
})

// State
type Mode = 'rotate' | 'split' | 'crop' | 'enhance' | 'bookmarks'
const activeMode = ref<Mode>('rotate')

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
		const maxInspect = Math.min(pdf.numPages ?? 0, 25)
		const list: PdfPageResolutionSummary[] = []
		for (let p = 1; p <= maxInspect; p++) {
			const pg = await pdf.getPage(p)
			list.push(await inspectPdfPageResolution(pg, p))
		}
		pageResolutions.value = list
	} catch {
		pageResolutions.value = []
	}
}

// Page-specific state (1-indexed, size totalPages)
// Page Order modal state
const isPageOrderModalOpen = ref(false)

function handlePageOrderSaved(data: LibraryData) {
	emit('saved', data, props.song.id)
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
	onSaved: (data, targetSongId) => emit('saved', data, targetSongId),
	onCancel: () => emit('cancel'),
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
	onSaved: (data, targetSongId) => emit('saved', data, targetSongId),
	onCancel: () => emit('cancel'),
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

async function saveSplits() {
	await executeSaveSplits(
		(v) => {
			isSaving.value = v
			if (!v) {
				saveProgressPercent.value = 0
				saveProgressText.value = ''
				saveProgressCurrent.value = 0
				saveProgressTotal.value = 0
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
		const targetUrl = getCacheBustedFileUrl(targetFile.value.path, `${targetFile.value.id}-${props.song.lastModified ?? ''}-${Date.now()}`)
		const response = await axios.get(targetUrl, {
			responseType: 'arraybuffer',
			headers: {
				'Cache-Control': 'no-cache',
				'Pragma': 'no-cache',
			},
		})
		const bytes = response.data instanceof ArrayBuffer ? new Uint8Array(response.data) : new Uint8Array(response.data)
		// Store an independent clone of bytes so pdfjs worker transfer does not detach the array buffer
		originalPdfBytes.value = bytes.slice()

		const wasmUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/wasm/') : new URL('pdfjs-dist/wasm/', import.meta.url).href
		const cMapUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/cmaps/') : new URL('pdfjs-dist/cmaps/', import.meta.url).href
		const standardFontDataUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/standard_fonts/') : new URL('pdfjs-dist/standard_fonts/', import.meta.url).href

		const pdf = await getDocument({
			data: bytes.slice(),
			cMapUrl,
			cMapPacked: true,
			standardFontDataUrl,
			wasmUrl,
		}).promise

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
		emit('saved', data, props.song.id)
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
		saveProgressPercent.value = 0
		saveProgressText.value = ''
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
		hasAnyJoin.value = true

		// Re-index / shift state for remaining pages
		const lowerPage = Math.min(options.pageA, options.pageB)
		const higherPage = Math.max(options.pageA, options.pageB)

		// pageRotations: lowerPage - 1 becomes 0, higherPage - 1 is removed
		const newRots = [...pageRotations.value]
		newRots[lowerPage - 1] = 0
		newRots.splice(higherPage - 1, 1)
		pageRotations.value = newRots

		// pageCrops: lowerPage crop is baked in, higherPage is removed, subsequent shift down
		const newCrops: Record<number, PageCrop> = {}
		for (const [kStr, crop] of Object.entries(pageCrops.value)) {
			const k = Number(kStr)
			if (k === lowerPage || k === higherPage) continue
			if (k > higherPage) {
				newCrops[k - 1] = crop
			} else {
				newCrops[k] = crop
			}
		}
		pageCrops.value = newCrops

		// deletedPages: shift down if needed
		const newDeleted = new Set<number>()
		for (const p of deletedPages.value) {
			if (p === higherPage || p === lowerPage) continue
			if (p > higherPage) {
				newDeleted.add(p - 1)
			} else {
				newDeleted.add(p)
			}
		}
		deletedPages.value = newDeleted

		// Re-render PDF with pdfjs
		const wasmUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/wasm/') : new URL('pdfjs-dist/wasm/', import.meta.url).href
		const cMapUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/cmaps/') : new URL('pdfjs-dist/cmaps/', import.meta.url).href
		const standardFontDataUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/standard_fonts/') : new URL('pdfjs-dist/standard_fonts/', import.meta.url).href

		const pdf = await getDocument({
			data: newBytes.slice(),
			cMapUrl,
			cMapPacked: true,
			standardFontDataUrl,
			wasmUrl,
		}).promise

		activePdfDoc.value = pdf
		totalPages.value = pdfDoc.getPageCount()
		void refreshPageResolutions(pdf)

		cleanupThumbnailObserver()
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
		const pages = pdfDoc.getPages()

		let modifiedRotations = false
		let modifiedCrops = false
		let modifiedEnhancements = false
		let processedCount = 0
		for (let i = 0; i < pages.length; i++) {
			const pageNum = i + 1
			// Skip deleted pages from modifications
			if (deletedPages.value.has(pageNum)) {
				continue
			}

			processedCount++
			const pageIsEnhanced = Boolean(isPageEnhanced(pageNum) && activePdfDoc.value)
			const startPct = 5 + ((processedCount - 1) / activeTotal) * 75
			const endPct = 5 + (processedCount / activeTotal) * 75

			await updateSaveProgress(
				startPct,
				pageIsEnhanced
					? t('sheetmusic', 'Enhancing page {current} of {total}…', { current: processedCount, total: activeTotal }, { escape: false })
					: t('sheetmusic', 'Processing page {current} of {total}…', { current: processedCount, total: activeTotal }, { escape: false }),
				processedCount,
				activeTotal,
				pageIsEnhanced,
			)

			// 1. Scan Enhancement (if configured for page)
			if (pageIsEnhanced && activePdfDoc.value) {
				try {
					const pageToRender = await activePdfDoc.value.getPage(pageNum)
					const origPage = pdfDoc.getPage(i)
					const origSize = origPage.getSize()
					const scale = await resolvePageRenderScale(pageToRender, origPage)
					const unscaled = pageToRender.getViewport({ scale: 1.0, rotation: 0 })
					const vp = pageToRender.getViewport({ scale, rotation: 0 })
					const vpScaled = Math.abs(vp.width - unscaled.width * scale) < 2
					const targetW = vpScaled
						? Math.max(1, Math.round(vp.width))
						: Math.max(1, Math.round(origSize.width * scale))
					const targetH = vpScaled
						? Math.max(1, Math.round(vp.height))
						: Math.max(1, Math.round(origSize.height * scale))
					const canvas = document.createElement('canvas')
					canvas.width = targetW
					canvas.height = targetH
					const ctx = canvas.getContext('2d')
					let rawBuffer: any = null
					if (ctx) {
						ctx.fillStyle = '#ffffff'
						ctx.fillRect(0, 0, canvas.width, canvas.height)
						await pageToRender.render({
							canvasContext: ctx,
							viewport: vp,
							background: 'rgb(255, 255, 255)',
						}).promise
						const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height)
						rawBuffer = imageDataToImageBuffer(imgData)
					} else {
						// Headless / test environment fallback when 2D canvas context is unavailable
						rawBuffer = createImageBuffer(targetW, targetH, 4)
						rawBuffer.data.fill(255)
					}

					if (rawBuffer) {
						await yieldToBrowser()
						const enhancedBuffer = applyPipeline(rawBuffer, pageEnhancements.value[pageNum], {
							skipJpegSimulation: true,
						})
						await yieldToBrowser()
						await executeEnhancePage(pdfDoc, {
							pageIndex: i,
							enhancedImageBuffer: enhancedBuffer,
							operations: pageEnhancements.value[pageNum],
						})
						modifiedEnhancements = true
					}
				} catch (enhErr) {
					console.warn(`Could not enhance page ${pageNum}`, enhErr)
				}
			}

			// 2. Calculate net rotation
			const rotDelta = pageRotations.value[i] ?? 0
			const crop = pageCrops.value[pageNum]
			const hasCrop = crop && (crop.left > 0.005 || crop.top > 0.005 || crop.width < 0.995 || crop.height < 0.995)

			const page = pdfDoc.getPage(i)
			const currentAngle = page.getRotation()?.angle ?? 0
			const netAngle = ((currentAngle + rotDelta) % 360 + 360) % 360

			const isMultipleOf90 = Math.abs(netAngle % 90) < 0.001 || Math.abs((netAngle % 90) - 90) < 0.001

			if (!isMultipleOf90) {
				const cropToOriginal = pageRotateCropToOriginal.value[pageNum] ?? false
				// Arbitrary angle rotation via Form XObject embedding & transformation
				await executeRotatePage(pdfDoc, {
					pageIndex: i,
					angleDegrees: rotDelta,
					crop: hasCrop ? crop : undefined,
					cropToOriginalSize: cropToOriginal,
				})
				modifiedRotations = true
				if (hasCrop) {
					modifiedCrops = true
				}
			} else {
				if (rotDelta !== 0) {
					page.setRotation(degrees(Math.round(netAngle / 90) * 90 % 360))
					modifiedRotations = true
				}

				// 3. Apply Crop if any, taking the net rotation into account
				if (hasCrop) {
					const { x: origX, y: origY, width: origW, height: origH } = page.getCropBox() ?? page.getMediaBox()
					const box = computeCropBoxForRotation(origX, origY, origW, origH, crop, netAngle)
					page.setCropBox(box.x, box.y, box.width, box.height)
					modifiedCrops = true
				}
			}

			await updateSaveProgress(
				endPct,
				pageIsEnhanced
					? t('sheetmusic', 'Enhanced page {current} of {total}', { current: processedCount, total: activeTotal }, { escape: false })
					: t('sheetmusic', 'Processed page {current} of {total}', { current: processedCount, total: activeTotal }, { escape: false }),
				processedCount,
				activeTotal,
				false,
			)
		}

		// 4. Remove deleted pages in descending order to avoid index shifts
		let modifiedDeletions = false
		if (deletedPages.value.size > 0) {
			const indicesToDelete = Array.from(deletedPages.value)
				.map((p) => p - 1)
				.sort((a, b) => b - a)

			for (const idx of indicesToDelete) {
				if (idx >= 0 && idx < pdfDoc.getPageCount()) {
					pdfDoc.removePage(idx)
					modifiedDeletions = true
				}
			}
		}

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
		const formData = new FormData()
		formData.append('file', modifiedFile)
		const uploadRes = await (apiPost as any)('/api/libraries/{libraryId}/songs/{id}/file/{fileId}/update', formData, {
			pathParams: { libraryId: props.libraryId, id: props.song.id, fileId: targetFile.value.id },
		})

		await updateSaveProgress(100, t('sheetmusic', 'Saved!'), activeTotal, activeTotal, false)
		const data = uploadRes.data.ocs.data as LibraryData
		const songTitle = props.song.title || t('sheetmusic', 'Song')
		const hasMultipleTypes = [modifiedRotations, modifiedCrops, modifiedDeletions, modifiedEnhancements, hasAnyJoin.value].filter(Boolean).length > 1
		const successMsg = hasMultipleTypes || hasAnyJoin.value
			? t('sheetmusic', 'PDF changes saved successfully for "{title}".', { title: songTitle }, { escape: false })
			: modifiedEnhancements
				? t('sheetmusic', 'Enhanced scan PDF saved successfully for "{title}".', { title: songTitle }, { escape: false })
				: modifiedDeletions
					? t('sheetmusic', 'Pages deleted and PDF saved successfully for "{title}".', { title: songTitle }, { escape: false })
					: modifiedRotations
						? t('sheetmusic', 'Rotated PDF saved successfully for "{title}".', { title: songTitle }, { escape: false })
						: t('sheetmusic', 'Cropped PDF saved successfully for "{title}".', { title: songTitle }, { escape: false })
		showSuccess(successMsg)
		emit('saved', data, props.song.id)
	} catch (err: any) {
		console.error('Failed to save modified PDF:', err)
		const detail = describeRequestFailure(err, err?.message ?? String(err))
		errorMessage.value = t('sheetmusic', 'Failed to save PDF: {error}', {
			error: detail,
		}, { escape: false })
		showError(errorMessage.value)
	} finally {
		isSaving.value = false
		saveProgressPercent.value = 0
		saveProgressText.value = ''
		saveProgressCurrent.value = 0
		saveProgressTotal.value = 0
	}
}

const saveRotations = saveRotationsAndCrops
const saveCrops = saveRotationsAndCrops
const saveEnhancements = saveRotationsAndCrops

const isFullscreen = ref(false)

async function toggleFullscreen() {
	const next = !isFullscreen.value
	isFullscreen.value = next
	try {
		if (next && !document.fullscreenElement && document.documentElement.requestFullscreen) {
			await document.documentElement.requestFullscreen()
		} else if (!next && document.fullscreenElement && document.exitFullscreen) {
			await document.exitFullscreen()
		}
	} catch {
		// Fullscreen API may be blocked by browser policy; CSS fullscreen still applies
	}
}

function handleFullscreenChange() {
	isFullscreen.value = Boolean(document.fullscreenElement)
}

onMounted(() => {
	loadPdf()
	document.addEventListener('fullscreenchange', handleFullscreenChange)
})

onUnmounted(() => {
	document.removeEventListener('fullscreenchange', handleFullscreenChange)
	if (document.fullscreenElement && document.exitFullscreen) {
		document.exitFullscreen().catch(() => {})
	}
})

watch(() => targetFile.value?.path, () => {
	loadPdf()
})
</script>

<template>
	<div :class="[$style.container, isFullscreen && $style.fullscreenContainer]">
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
			@update:active-mode="activeMode = $event"
			@update:activeMode="activeMode = $event"
			@cancel="emit('cancel')"
			@open-page-order-modal="isPageOrderModalOpen = true"
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
					:deleted-pages-count="deletedPages.size"
					:selected-pages-count="selectedPages.length"
					@rotate-all="rotateAll($event)"
					@open-rotate-modal="openRotateModal(1)"
					@reset-rotations="resetRotations"
					@open-join-modal="openJoinModal(1, 2)"
					@restore-all-pages="restoreAllPages"
					@open-crop-modal="openCropModal(1)"
					@reset-all-crops="resetAllCrops"
					@open-enhance-modal="openEnhanceModal(1)"
					@reset-all-enhancements="resetAllEnhancements"
					@open-versions-modal="openVersionsModal"
					@clear-selection="selectedPages = []" />

				<!-- Thumbnail Grid -->
				<SongPdfPageGrid
					:total-pages="totalPages"
					:active-mode="activeMode"
					:selected-pages="selectedPages"
					:is-page-cropped="isPageCropped"
					:is-page-enhanced="isPageEnhanced"
					:is-page-deleted="isPageDeleted"
					:page-rotations="pageRotations"
					:get-crop-indicator-text="getCropIndicatorText"
					:get-enhance-indicator-text="getEnhanceIndicatorText"
					:get-page-segments-summary="getPageSegmentsSummary"
					:get-page-bookmarks-summary="getPageBookmarksSummary"
					:remaining-pages-count="remainingPagesCount"
					@card-click="(activeMode === 'split' || activeMode === 'bookmarks') ? togglePageSelection($event) : undefined"
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
	</div>
</template>

<style module>
.container {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: calc(100vh - var(--header-height, 50px) - 80px);
	max-height: calc(100vh - var(--header-height, 50px) - 80px);
	min-height: 420px;
	background-color: var(--color-main-background);
	overflow: hidden;
}

.fullscreenContainer {
	position: fixed;
	inset: 0;
	width: 100vw;
	height: 100vh;
	max-height: 100vh;
	z-index: 9999;
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
		max-height: 100vh;
		min-height: 360px;
	}

	.contentLayout {
		flex-direction: column;
	}
}
</style>
