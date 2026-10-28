<script lang="ts">
export type { SplitSegment } from './SongPdfSplitSidebar.vue'
export type { OriginalSongAction } from './OriginalSongActionSelector.vue'
</script>

<script setup lang="ts">
import type { Song, SongFile, Book, Composer, Collection, Artist, Genre, Instrument, Key, CustomGroup, Year, Signature, SourceType, LibraryData } from '../../types/song'
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
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
import SongPdfPageCard, { type SplitSegmentSummary } from './SongPdfPageCard.vue'
import SongPdfSplitSidebar, { getSegmentColor, type SplitSegment } from './SongPdfSplitSidebar.vue'
import SongPdfVersionsModal from './SongPdfVersionsModal.vue'
import { useFileVersions } from './useFileVersions'
import SongPdfCropModal from './SongPdfCropModal.vue'
import SongPdfJoinModal from './SongPdfJoinModal.vue'
import SongPdfRotateModal from './SongPdfRotateModal.vue'
import SongPdfEnhanceModal from './SongPdfEnhanceModal.vue'
import { computeCropBoxForRotation, type PageCrop } from './crop-types'
import { executeJoinTwoPages, type JoinLayout } from './pdf-join-engine'
import { executeRotatePage } from './pdf-rotate-engine'
import type { ScanOperation } from './scan-types'
import { applyPipeline, createImageBuffer, imageDataToImageBuffer, imageBufferToImageData } from './scan-enhance-engine'
import { executeEnhancePage, resolvePageRenderScale } from './pdf-enhance-engine'
import { usePdfThumbnails } from './usePdfThumbnails'
import { usePdfSplit } from './usePdfSplit'

GlobalWorkerOptions.workerSrc = pdfWorker


const props = withDefaults(
	defineProps<{
		song: Song
		fileId?: string | number
		libraryId: number
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
	}>(),
	{
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
type Mode = 'rotate' | 'split' | 'crop' | 'enhance'
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

// Page-specific state (1-indexed, size totalPages)
// pageRotations[pageIdx] = 0 | 90 | 180 | 270 (relative rotation added)
const pageRotations = ref<number[]>([])

// Page-specific crop state: Record<pageNum, PageCrop>
const pageCrops = ref<Record<number, PageCrop>>({})

// Page-specific scan enhancement state: Record<pageNum, ScanOperation[]>
const pageEnhancements = ref<Record<number, ScanOperation[]>>({})

// Deleted pages (1-indexed page numbers)
const deletedPages = ref<Set<number>>(new Set())

// Enhance modal state
const isEnhanceModalOpen = ref(false)
const enhanceModalPage = ref(1)


// Crop modal state
const isCropModalOpen = ref(false)
const cropModalPage = ref(1)

// Rotate modal state (arbitrary angle / deskew)
const isRotateModalOpen = ref(false)
const rotateModalPage = ref(1)
const pageRotateCropToOriginal = ref<Record<number, boolean>>({})

// Join modal state
const isJoinModalOpen = ref(false)
const joinModalPageA = ref(1)
const joinModalPageB = ref(2)
const hasAnyJoin = ref(false)

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
		pageRotations.value = Array(pdf.numPages).fill(0)
		pageCrops.value = {}

		// Initialize split segments: default with 1 segment covering all pages
		initSplitSegments(pdf.numPages, props.song.title)

		await nextTick()
		initThumbnailObserver(pdf)
	} catch (err: any) {
		console.error('Failed to load PDF:', err)
		errorMessage.value = t('sheetmusic', 'Failed to load PDF document: {error}', {
			error: err?.message ?? String(err),
		})
		showError(errorMessage.value)
	} finally {
		isLoading.value = false
	}
}


// Rotation helpers
function rotatePage(pageIndex: number, deltaDeg: number) {
	const current = pageRotations.value[pageIndex] ?? 0
	const next = (current + deltaDeg) % 360
	pageRotations.value[pageIndex] = Math.round(((next + 360) % 360) * 10) / 10
}

function rotateAll(deltaDeg: number) {
	for (let i = 0; i < pageRotations.value.length; i++) {
		rotatePage(i, deltaDeg)
	}
}

function resetRotations() {
	for (let i = 0; i < pageRotations.value.length; i++) {
		pageRotations.value[i] = 0
	}
	pageRotateCropToOriginal.value = {}
	showInfo(t('sheetmusic', 'Reset all page rotations for "{title}".', { title: props.song.title }))
}

function openRotateModal(pageNum: number) {
	rotateModalPage.value = pageNum
	isRotateModalOpen.value = true
}

function handleApplyCustomRotation(angle: number, scope: 'current' | 'all', cropToOriginalSize = false) {
	const normalized = Math.round((((angle % 360) + 360) % 360) * 10) / 10
	if (scope === 'all') {
		for (let i = 0; i < pageRotations.value.length; i++) {
			pageRotations.value[i] = normalized
			pageRotateCropToOriginal.value[i + 1] = cropToOriginalSize
		}
	} else {
		const idx = rotateModalPage.value - 1
		if (idx >= 0 && idx < pageRotations.value.length) {
			pageRotations.value[idx] = normalized
			pageRotateCropToOriginal.value[rotateModalPage.value] = cropToOriginalSize
		}
	}
}

const hasAnyRotation = computed(() => {
	return pageRotations.value.some((rot) => Math.abs(rot) > 0.001)
})

// Crop helpers
function openCropModal(pageNum: number) {
	cropModalPage.value = pageNum
	isCropModalOpen.value = true
}

function handleApplyCrop(crop: PageCrop, scope: 'current' | 'all') {
	if (scope === 'all') {
		const updated: Record<number, PageCrop> = {}
		for (let p = 1; p <= totalPages.value; p++) {
			updated[p] = { ...crop }
		}
		pageCrops.value = updated
	} else {
		pageCrops.value = {
			...pageCrops.value,
			[cropModalPage.value]: { ...crop },
		}
	}
}

function resetAllCrops() {
	pageCrops.value = {}
	showInfo(t('sheetmusic', 'Reset all page crops for "{title}".', { title: props.song.title }))
}

const hasAnyCrop = computed(() => {
	return Object.values(pageCrops.value).some((c) => (
		c.left > 0.005 ||
		c.top > 0.005 ||
		c.width < 0.995 ||
		c.height < 0.995
	))
})

function isPageCropped(pageNum: number): boolean {
	const c = pageCrops.value[pageNum]
	if (!c) return false
	return (
		c.left > 0.005 ||
		c.top > 0.005 ||
		c.width < 0.995 ||
		c.height < 0.995
	)
}

function getCropIndicatorText(pageNum: number): string {
	const c = pageCrops.value[pageNum]
	if (!c) return ''
	const top = Math.round(c.top * 100)
	const right = Math.round((1 - (c.left + c.width)) * 100)
	const bottom = Math.round((1 - (c.top + c.height)) * 100)
	const left = Math.round(c.left * 100)
	return `${top}% ${right}% ${bottom}% ${left}%`
}

// Page deletion helpers
function isPageDeleted(pageNum: number): boolean {
	return deletedPages.value.has(pageNum)
}

function toggleDeletePage(pageNum: number) {
	const next = new Set(deletedPages.value)
	if (next.has(pageNum)) {
		next.delete(pageNum)
	} else {
		if (totalPages.value - next.size <= 1) {
			showError(t('sheetmusic', 'Cannot delete page. A PDF document must have at least one page.'))
			return
		}
		next.add(pageNum)
	}
	deletedPages.value = next
}

function restoreAllPages() {
	deletedPages.value = new Set()
}

const hasAnyDeletion = computed(() => {
	return deletedPages.value.size > 0
})

const remainingPagesCount = computed(() => {
	return Math.max(0, totalPages.value - deletedPages.value.size)
})

const hasAnyChange = computed(() => {
	return hasAnyRotation.value || hasAnyCrop.value || hasAnyDeletion.value || hasAnyJoin.value || hasAnyEnhance.value
})

// Enhance helpers
function openEnhanceModal(pageNum: number) {
	enhanceModalPage.value = pageNum
	isEnhanceModalOpen.value = true
}

function handleApplyEnhancements(operations: ScanOperation[], scope: 'current' | 'all') {
	if (scope === 'all') {
		const updated: Record<number, ScanOperation[]> = {}
		for (let p = 1; p <= totalPages.value; p++) {
			updated[p] = operations.map((op) => ({ ...op }))
		}
		pageEnhancements.value = updated
		for (let p = 1; p <= totalPages.value; p++) {
			updateThumbnailForPage(p)
		}
	} else {
		pageEnhancements.value = {
			...pageEnhancements.value,
			[enhanceModalPage.value]: operations.map((op) => ({ ...op })),
		}
		updateThumbnailForPage(enhanceModalPage.value)
	}
}

function resetAllEnhancements() {
	pageEnhancements.value = {}
	for (let p = 1; p <= totalPages.value; p++) {
		updateThumbnailForPage(p)
	}
	showInfo(t('sheetmusic', 'Reset all scan enhancements for "{title}".', { title: props.song.title }))
}

const hasAnyEnhance = computed(() => {
	return Object.values(pageEnhancements.value).some((ops) =>
		Array.isArray(ops) && ops.some((op) => op.enabled),
	)
})

function getEnhanceIndicatorText(pageNum: number): string {
	const ops = pageEnhancements.value[pageNum]
	if (!ops || ops.length === 0) return ''
	const active = ops.filter((op) => op.enabled)
	if (active.length === 0) return ''
	return `${active.length} ${active.length === 1 ? 'filter' : 'filters'}`
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

function handleHeaderSave() {
	if (activeMode.value === 'rotate') {
		saveRotations()
	} else if (activeMode.value === 'crop') {
		saveCrops()
	} else if (activeMode.value === 'enhance') {
		saveEnhancements()
	} else if (activeMode.value === 'split') {
		saveSplits()
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

		cleanupThumbnailObserver()
		thumbnailCanvases.value = {}
		await nextTick()
		initThumbnailObserver(pdf)

		showSuccess(t('sheetmusic', 'Joined pages {pageA} and {pageB} in "{title}".', {
			pageA: options.pageA,
			pageB: options.pageB,
			title: props.song.title,
		}))
	} catch (err: any) {
		console.error('Failed to join pages:', err)
		const detail = describeRequestFailure(err, err?.message ?? String(err))
		errorMessage.value = t('sheetmusic', 'Failed to join pages: {error}', {
			error: detail,
		})
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
					? t('sheetmusic', 'Enhancing page {current} of {total}…', { current: processedCount, total: activeTotal })
					: t('sheetmusic', 'Processing page {current} of {total}…', { current: processedCount, total: activeTotal }),
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
						const enhancedBuffer = applyPipeline(rawBuffer, pageEnhancements.value[pageNum])
						await yieldToBrowser()
						await executeEnhancePage(pdfDoc, {
							pageIndex: i,
							enhancedImageBuffer: enhancedBuffer,
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
					? t('sheetmusic', 'Enhanced page {current} of {total}', { current: processedCount, total: activeTotal })
					: t('sheetmusic', 'Processed page {current} of {total}', { current: processedCount, total: activeTotal }),
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
			? t('sheetmusic', 'PDF changes saved successfully for "{title}".', { title: songTitle })
			: modifiedEnhancements
				? t('sheetmusic', 'Enhanced scan PDF saved successfully for "{title}".', { title: songTitle })
				: modifiedDeletions
					? t('sheetmusic', 'Pages deleted and PDF saved successfully for "{title}".', { title: songTitle })
					: modifiedRotations
						? t('sheetmusic', 'Rotated PDF saved successfully for "{title}".', { title: songTitle })
						: t('sheetmusic', 'Cropped PDF saved successfully for "{title}".', { title: songTitle })
		showSuccess(successMsg)
		emit('saved', data, props.song.id)
	} catch (err: any) {
		console.error('Failed to save modified PDF:', err)
		const detail = describeRequestFailure(err, err?.message ?? String(err))
		errorMessage.value = t('sheetmusic', 'Failed to save PDF: {error}', {
			error: detail,
		})
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

onMounted(() => {
	loadPdf()
})

watch(() => targetFile.value?.path, () => {
	loadPdf()
})
</script>

<template>
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
			:save-progress="saveProgressPercent"
			@update:active-mode="activeMode = $event"
			@update:activeMode="activeMode = $event"
			@cancel="emit('cancel')"
			@save="handleHeaderSave" />

		<!-- Saving Progress Bar Banner -->
		<div
			v-if="isSaving"
			:class="$style.saveProgressBanner"
			data-test="pdf-save-progress">
			<div :class="$style.saveProgressHeader">
				<div :class="$style.saveProgressInfo">
					<NcLoadingIcon :size="18" />
					<span :class="$style.saveProgressText" data-test="pdf-save-progress-text">
						{{ saveProgressText || t('sheetmusic', 'Saving PDF…') }}
					</span>
				</div>
				<span :class="$style.saveProgressPercent" data-test="pdf-save-progress-percent">
					{{ saveProgressPercent }}%
				</span>
			</div>
			<div
				:class="$style.saveProgressTrack"
				role="progressbar"
				:aria-valuenow="saveProgressPercent"
				aria-valuemin="0"
				aria-valuemax="100">
				<div
					:class="$style.saveProgressFill"
					data-test="pdf-save-progress-fill"
					:style="{ width: `${saveProgressPercent}%` }" />
			</div>
		</div>

		<!-- Error Banner -->
		<div v-if="errorMessage" :class="$style.errorBanner">
			{{ errorMessage }}
		</div>

		<!-- Main Workspace Area -->
		<div v-if="isLoading" :class="$style.loadingArea">
			<NcLoadingIcon :size="48" />
			<span>{{ t('sheetmusic', 'Loading PDF pages…') }}</span>
		</div>

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
				<div :class="$style.grid">
					<SongPdfPageCard
						v-for="pageNum in totalPages"
						:key="pageNum"
						:page-num="pageNum"
						:active-mode="activeMode"
						:is-selected="selectedPages.includes(pageNum)"
						:is-cropped="isPageCropped(pageNum)"
						:is-enhanced="isPageEnhanced(pageNum)"
						:is-deleted="isPageDeleted(pageNum)"
						:rotation-deg="pageRotations[pageNum - 1] || 0"
						:crop-indicator-text="getCropIndicatorText(pageNum)"
						:enhance-indicator-text="getEnhanceIndicatorText(pageNum)"
						:page-segments="getPageSegmentsSummary(pageNum)"
						:total-pages="totalPages"
						:can-delete="remainingPagesCount > 1"
						@card-click="activeMode === 'split' ? togglePageSelection(pageNum) : undefined"
						@open-crop-modal="openCropModal(pageNum)"
						@open-enhance-modal="openEnhanceModal(pageNum)"
						@rotate-page="rotatePage(pageNum - 1, $event)"
						@open-rotate-modal="openRotateModal(pageNum)"
						@open-join-modal="openJoinModal(pageNum, $event)"
						@toggle-delete-page="toggleDeletePage(pageNum)"
						@set-container-ref="setContainerRef" />
				</div>
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
	</div>
</template>

<style module>
.container {
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	background-color: var(--color-main-background);
	overflow: hidden;
}

.errorBanner {
	background-color: var(--color-error);
	color: var(--color-primary-text);
	padding: 8px 16px;
	font-size: 13px;
}

.saveProgressBanner {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 10px 16px;
	background-color: var(--color-background-hover, #f3f4f6);
	border-bottom: 1px solid var(--color-border, #e5e7eb);
	flex-shrink: 0;
}

.saveProgressHeader {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.saveProgressInfo {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.saveProgressText {
	font-size: 13px;
	font-weight: 500;
	color: var(--color-main-text, #111827);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.saveProgressPercent {
	font-size: 13px;
	font-weight: 700;
	color: var(--color-primary-element, #0082c9);
	flex-shrink: 0;
}

.saveProgressTrack {
	width: 100%;
	height: 8px;
	background-color: var(--color-border, #d1d5db);
	border-radius: 999px;
	overflow: hidden;
}

.saveProgressFill {
	height: 100%;
	background-color: var(--color-primary-element, #0082c9);
	border-radius: 999px;
	transition: width 0.15s ease-out;
}

.loadingArea {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	height: 100%;
	gap: 12px;
	color: var(--color-text-maxcontrast);
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
	height: 100%;
	overflow: hidden;
}

.grid {
	flex: 1;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
	gap: 16px;
	padding: 16px;
	overflow-y: auto;
}
</style>
