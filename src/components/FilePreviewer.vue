<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, shallowRef, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { cleanupPdfJsPage, destroyPdfJsDocument, getEffectivePageRotation, loadPdfJsDocument, releaseCanvas, releaseChildCanvases, renderPdfPageToCanvas } from '../utils/pdfjs'
import { isImageFile as checkIsImageFile, isPdfFile as checkIsPdfFile, openFileExternally } from '../utils/file'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import ArrowULeftTopIcon from 'vue-material-design-icons/ArrowULeftTop.vue'
import FilePreviewerToolbar from './file-preview/FilePreviewerToolbar.vue'
import XlsxPreviewPane, { type XlsxSheet } from './file-preview/XlsxPreviewPane.vue'
import { useAnnotationOverlayHost } from '../composables/useAnnotationOverlayHost'
import { useFilePreviewZoom } from '../composables/useFilePreviewZoom'
import { useLinkJumpNavigation } from '../composables/useLinkJumpNavigation'
import { usePdfPageNavigation } from '../composables/usePdfPageNavigation'
import { usePdfResizeObserver } from '../composables/usePdfResizeObserver'
import { usePdfTouchZoom } from '../composables/usePdfTouchZoom'
import { useStylusSettings } from '../composables/useStylusSettings'
import { fetchCachedBinaryFile } from '../utils/file-binary'
import { inspectPdfDocumentResolutions, type PdfPageResolutionSummary } from './song-pdf/pdf-enhance-engine'
import { parsePageOrder } from '../utils/page-order'
import type { SheetAnnotation } from '../types/annotation'
import type { Bookmark } from '../types/song'
import type { LinkTargetPickRequest, LinkTargetPickResult } from '../utils/annotationLinks'
import type { MusicFontId } from '../utils/musicFont'
import type { EditorTool, EditorToolStyle } from '../composables/useAnnotationEditor'
import { defaultFileBinaryCache, type FileBinaryCache } from '../utils/file-cache'
import {
	computeDoublePageScale,
	computeFitScale,
	MAX_CUSTOM_SCALE,
	MIN_CUSTOM_SCALE,
	resolvePagesToRender,
} from '../utils/pdf-layout'
import {
	buildCropCacheKey,
	detectPdfPageCropBox,
	FULL_PAGE_CROP,
	getCachedPdfPageCropBox,
	hydratePdfCropCache,
	isCropped,
	scheduleSaveFileCropBox,
	type NormalizedCropBox,
} from '../utils/pdfCrop'
import { observeContinuousPdfPages } from '../utils/pdf-continuous-renderer'
import {
	applyStageLayoutStyles,
	createContinuousPagePlaceholder,
	createDoublePageSlot,
	createPagePlaceholder,
} from '../utils/pdf-stage-dom'
import {
	type DoublePageTurnMode,
	getDoubleSpreadForPage,
	getInitialDoubleSpread,
} from '../utils/page-turn'

const props = withDefaults(
	defineProps<{
		filePath: string
		fileName?: string
		showExternalButton?: boolean
		hideToolbar?: boolean
		targetPage?: number
		pageOrder?: string | null
		annotations?: SheetAnnotation[]
		showAnnotations?: boolean
		editableAnnotations?: boolean
		activeAnnotationTool?: EditorTool
		annotationToolStyle?: EditorToolStyle
		selectedAnnotationUuid?: string | null
		selectedAnnotationUuids?: string[]
		musicFont?: MusicFontId
		bookmarks?: Bookmark[]
		pickingLinkTarget?: boolean
		binaryCache?: FileBinaryCache
		initialPageLayout?: 'continuous' | 'single' | 'double' | 'auto'
		initialDoublePageTurnMode?: DoublePageTurnMode
		initialZoomMode?: 'fit-width' | 'fit-page' | 'custom'
		startAtEnd?: boolean
		noPadding?: boolean
		nextFilePath?: string
		nextTargetPage?: number
		nextPageOrder?: string | null
		interleaveSongs?: boolean
		backgroundColor?: string
		tightCrop?: boolean
		fileId?: number
		initialCropBoxes?: Record<string, NormalizedCropBox> | null
		nextFileId?: number
		nextInitialCropBoxes?: Record<string, NormalizedCropBox> | null
	}>(),
	{
		fileName: undefined,
		showExternalButton: true,
		hideToolbar: false,
		targetPage: undefined,
		pageOrder: undefined,
		annotations: () => [],
		showAnnotations: true,
		editableAnnotations: false,
		activeAnnotationTool: 'select',
		annotationToolStyle: undefined,
		selectedAnnotationUuid: null,
		selectedAnnotationUuids: () => [],
		musicFont: 'bravura',
		bookmarks: () => [],
		pickingLinkTarget: false,
		binaryCache: () => defaultFileBinaryCache,
		initialPageLayout: 'continuous',
		initialDoublePageTurnMode: 'half',
		initialZoomMode: 'fit-page',
		startAtEnd: false,
		noPadding: false,
		nextFilePath: undefined,
		nextTargetPage: undefined,
		nextPageOrder: undefined,
		interleaveSongs: false,
		backgroundColor: '',
		tightCrop: false,
		fileId: undefined,
		initialCropBoxes: null,
		nextFileId: undefined,
		nextInitialCropBoxes: null,
	},
)

const emit = defineEmits<{
	'select-annotation': [uuid: string | null]
	'select-annotations': [uuids: string[]]
	'create-annotation': [draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'> & { uuid?: string }]
	'update-annotation': [before: SheetAnnotation, after: SheetAnnotation]
	'batch-update-annotations': [pairs: Array<{ before: SheetAnnotation; after: SheetAnnotation }>]
	'delete-annotation': [annotation: SheetAnnotation]
	'start-pick-link-target': [req: LinkTargetPickRequest]
	'complete-pick-link-target': [res: LinkTargetPickResult]
}>()

const pdfContainer = ref<HTMLElement>()
const pdfStage = ref<HTMLElement>()
const docxContainer = ref<HTMLElement>()
const xlsxSheets = ref<XlsxSheet[]>([])
const isLoading = ref(false)
const errorMessage = ref('')
let currentLoadId = 0
let currentRenderId = 0

const loadedPdf = shallowRef<any>(null)
const loadedNextPdf = shallowRef<any>(null)
let loadedNextPdfPath = ''
const totalPages = ref(1)
const currentPage = ref(1)
const fileSizeBytes = ref<number | null>(null)
const pageResolutions = ref<PdfPageResolutionSummary[]>([])

const fileExtTarget = computed(() => (props.fileName || props.filePath).split('?')[0].toLowerCase())
const isPdfFile = computed(() => checkIsPdfFile(fileExtTarget.value))
const isImageFile = computed(() => checkIsImageFile(fileExtTarget.value))
const isDocxFile = computed(() => fileExtTarget.value.endsWith('.docx'))
const isXlsxFile = computed(() => {
	const path = fileExtTarget.value
	return path.endsWith('.xlsx') || path.endsWith('.xls')
})
const isUnsupportedFile = computed(() =>
	!isPdfFile.value && !isImageFile.value && !isDocxFile.value && !isXlsxFile.value,
)

const {
	zoomMode,
	customScale,
	effectiveScale,
	rotation,
	imageScale,
	zoomSelectValue,
	isCustomZoom,
	imageStyle,
	setZoomMode,
	onZoomSelect,
	zoomIn,
	zoomOut,
	rotateClockwise,
} = useFilePreviewZoom({
	initialZoomMode: props.initialZoomMode,
	pdfContainer,
	isPdfFile,
	isImageFile,
	onResetPan: () => resetPan(),
	onRerender: () => renderCurrentView(),
})

const {
	activeBeacon: linkBeacon,
	jumpBackEntry,
	jumpFromLink,
	jumpBack,
} = useLinkJumpNavigation({
	goToPage: (page) => goToPage(page),
})

const {
	showAnnotationsState,
	toggleAnnotations,
	mountAnnotationOverlay,
	unmountAnnotationOverlay,
	unmountAnnotationOverlays,
} = useAnnotationOverlayHost({
	pdfContainer,
	totalPages,
	annotations: computed(() => props.annotations),
	showAnnotations: computed(() => props.showAnnotations),
	editableAnnotations: computed(() => props.editableAnnotations),
	activeAnnotationTool: computed(() => props.activeAnnotationTool),
	annotationToolStyle: computed(() => props.annotationToolStyle),
	selectedAnnotationUuid: computed(() => props.selectedAnnotationUuid),
	selectedAnnotationUuids: computed(() => props.selectedAnnotationUuids),
	musicFont: computed(() => props.musicFont),
	bookmarks: computed(() => props.bookmarks),
	linkBeacon,
	pickingLinkTarget: computed(() => props.pickingLinkTarget),
	onJumpToPage: jumpFromLink,
	onStartPickLinkTarget: (req) => emit('start-pick-link-target', req),
	onCompletePickLinkTarget: (res) => emit('complete-pick-link-target', res),
	onSelect: (uuid) => emit('select-annotation', uuid),
	onSelectMany: (uuids) => emit('select-annotations', uuids),
	onCreate: (draft) => emit('create-annotation', draft),
	onUpdate: (before, after) => emit('update-annotation', before, after),
	onBatchUpdate: (pairs) => emit('batch-update-annotations', pairs),
	onDelete: (ann) => emit('delete-annotation', ann),
})

const {
	autoLayout,
	pageLayout,
	doublePageTurnMode,
	doubleSpread,
	canInterleaveWithNext,
	effectiveDoubleTotalPages,
	isShowingNextSongFirstPage,
	currentPageDisplay,
	canGoPrev,
	canGoNext,
	resolveAutoLayoutForPage,
	prevPage,
	nextPage,
	goToPage,
	setPageLayout,
	setAutoLayout,
	setDoublePageTurnMode,
} = usePdfPageNavigation({
	pdfContainer,
	loadedPdf,
	totalPages,
	currentPage,
	rotation,
	pageOrder: computed(() => props.pageOrder),
	initialPageLayout: props.initialPageLayout,
	initialDoublePageTurnMode: props.initialDoublePageTurnMode,
	interleaveSongs: computed(() => props.interleaveSongs),
	nextFilePath: computed(() => props.nextFilePath),
	onRerender: () => renderCurrentView(),
})

function openInExternalViewer() {
	if (!props.filePath) return
	openFileExternally(props.filePath)
}

async function renderPageThumbnail(logicalPage: number, canvas: HTMLCanvasElement, targetWidth = 110): Promise<void> {
	const pdf = loadedPdf.value
	if (!pdf) return
	let page: any = null
	try {
		const ordered = parsePageOrder(props.pageOrder, pdf.numPages)
		const idx = Math.min(Math.max(1, logicalPage), ordered.length) - 1
		const physicalPage = ordered[idx] || 1
		page = await pdf.getPage(physicalPage)
		const pageRotation = getEffectivePageRotation(page, rotation.value)
		const baseViewport = page.getViewport({ scale: 1.0, rotation: pageRotation })
		const scale = targetWidth / Math.max(1, baseViewport.width)
		const viewport = page.getViewport({ scale, rotation: pageRotation })
		await renderPdfPageToCanvas(page, canvas, viewport, { applyCssSize: false })
	} catch {
		// Ignore thumbnail render errors
	} finally {
		cleanupPdfJsPage(page)
	}
}

let intersectionObserver: IntersectionObserver | null = null

function cleanupObserver() {
	if (intersectionObserver) {
		intersectionObserver.disconnect()
		intersectionObserver = null
	}
}

let idlePrefetchCancel: (() => void) | null = null

function cancelIdleCropPrefetch() {
	if (idlePrefetchCancel) {
		idlePrefetchCancel()
		idlePrefetchCancel = null
	}
}

function resolvePageCropBox(
	page: any,
	pageRotation: number,
	filePath: string,
	pageNum: number,
	fileId?: number,
): Promise<NormalizedCropBox> {
	return detectPdfPageCropBox(
		page,
		pageRotation,
		buildCropCacheKey(filePath, pageNum, pageRotation),
		{
			fileKey: filePath,
			pageNum,
			onComputed: (box) => scheduleSaveFileCropBox(fileId, pageNum, box),
		},
	)
}

async function renderCurrentView(onBeforeSwap?: () => void) {
	if (!loadedPdf.value || !pdfContainer.value || !pdfStage.value) return

	cancelIdleCropPrefetch()
	const renderId = ++currentRenderId
	const pdf = loadedPdf.value
	const ordered = parsePageOrder(props.pageOrder, pdf.numPages)

	await nextTick()
	if (!pdfContainer.value || !pdfStage.value || renderId !== currentRenderId) return

	if (autoLayout.value) {
		const resolved = await resolveAutoLayoutForPage(currentPage.value)
		if (renderId !== currentRenderId) return
		if (pageLayout.value !== resolved) {
			pageLayout.value = resolved
			if (resolved === 'double') {
				doubleSpread.value = getDoubleSpreadForPage(currentPage.value, totalPages.value, doublePageTurnMode.value)
			}
		}
	}

	const pagesToRender = resolvePagesToRender({
		layout: pageLayout.value,
		currentPage: currentPage.value,
		doubleSpread: doubleSpread.value,
		orderedPages: ordered,
		canInterleaveWithNext: canInterleaveWithNext.value,
		nextTargetPage: props.nextTargetPage,
	})

	const pad = props.noPadding ? 0 : 48
	const doubleGap = props.noPadding ? 4 : 24
	const containerW = (pdfContainer.value?.clientWidth && pdfContainer.value.clientWidth > 100)
		? pdfContainer.value.clientWidth - pad
		: 800
	const containerH = (pdfContainer.value?.clientHeight && pdfContainer.value.clientHeight > 100)
		? pdfContainer.value.clientHeight - pad
		: 700
	const isDoubleLayout = pageLayout.value === 'double'
	const doubleSlotW = Math.max(1, Math.floor((containerW - doubleGap) / 2))
	const targetW = isDoubleLayout ? doubleSlotW : containerW

	// Calculate base scale. When tightCrop is enabled, anchor to the active page so we never
	// probe non-active pages on startup, and retain the loaded PDFPageProxy for reuse by renderPage
	// so PDF.js does not decode the same page twice.
	let scale = 1.25
	let docRefFitScale = 1.0
	const preloadedPages = new Map<number, any>()
	let refPage: any = null
	let refPageNum = 1
	try {
		refPageNum = (isDoubleLayout && !props.tightCrop)
			? (ordered[0] || 1)
			: (pagesToRender[0]?.pageNum || ordered[0] || 1)
		refPage = await pdf.getPage(refPageNum)
		const pageRotation = getEffectivePageRotation(refPage, rotation.value)
		const unscaledViewport = refPage.getViewport({ scale: 1.0, rotation: pageRotation })
		const refCrop = props.tightCrop
			? await resolvePageCropBox(refPage, pageRotation, props.filePath, refPageNum, props.fileId)
			: FULL_PAGE_CROP
		const refW = unscaledViewport.width * (refCrop.right - refCrop.left)
		const refH = unscaledViewport.height * (refCrop.bottom - refCrop.top)

		if (zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page') {
			scale = computeFitScale(refW, refH, targetW, containerH, zoomMode.value)
			docRefFitScale = scale
		} else {
			docRefFitScale = computeFitScale(refW, refH, targetW, containerH, 'fit-page')
			scale = Math.max(MIN_CUSTOM_SCALE, Math.min(customScale.value, MAX_CUSTOM_SCALE))
		}

		// Keep refPage warm if it will be rendered immediately in this pass
		const willRenderRef = pagesToRender.some((p) => !p.isNextFile && p.pageNum === refPageNum)
		if (willRenderRef && refPage) {
			preloadedPages.set(refPageNum, refPage)
			refPage = null
		}
	} catch {
		scale = 1.25
		docRefFitScale = 1.0
	} finally {
		if (refPage) {
			cleanupPdfJsPage(refPage)
		}
	}
	effectiveScale.value = scale
	const nextStageGap = isDoubleLayout
		? `${doubleGap}px`
		: `${Math.round((props.noPadding ? 4 : 16) * scale)}px`

	const renderedPlaceholders = new Set<HTMLElement>()

	async function getNextPdfDocument(): Promise<any | null> {
		if (!props.nextFilePath) return null
		if (loadedNextPdf.value && loadedNextPdfPath === props.nextFilePath) {
			return loadedNextPdf.value
		}
		const binaryData = await fetchBinaryData(props.nextFilePath, currentLoadId)
		if (!binaryData) return null
		const nextPdf = await loadPdfJsDocument(binaryData.slice())
		loadedNextPdf.value = nextPdf
		loadedNextPdfPath = props.nextFilePath
		return nextPdf
	}

	// Helper to render a specific page into its placeholder
	const renderPage = async (
		pageNumber: number,
		placeholder: HTMLElement,
		isNextFile = false,
		onRenderTask?: (task: { cancel?: () => void } | null) => void,
	) => {
		if (renderId !== currentRenderId || renderedPlaceholders.has(placeholder)) return
		renderedPlaceholders.add(placeholder)

		let page: any = null
		let canvas: HTMLCanvasElement | null = null
		try {
			let targetPdf = pdf
			let resolvedPageNum = pageNumber
			if (isNextFile) {
				const nextPdf = await getNextPdfDocument()
				if (!nextPdf || renderId !== currentRenderId) return
				targetPdf = nextPdf
				const nextOrdered = parsePageOrder(props.nextPageOrder, nextPdf.numPages)
				const idx = Math.min(Math.max(1, pageNumber), nextOrdered.length) - 1
				resolvedPageNum = nextOrdered[idx] || 1
			}
			if (!isNextFile && preloadedPages.has(resolvedPageNum)) {
				page = preloadedPages.get(resolvedPageNum)
				preloadedPages.delete(resolvedPageNum)
			} else {
				page = await targetPdf.getPage(resolvedPageNum)
			}
			if (renderId !== currentRenderId) return

			const pageRotation = getEffectivePageRotation(page, rotation.value)
			const unscaledViewport = page.getViewport({ scale: 1.0, rotation: pageRotation })
			const cachePath = isNextFile ? (props.nextFilePath || '') : props.filePath
			const targetFileId = isNextFile ? props.nextFileId : props.fileId
			const cropBox: NormalizedCropBox = props.tightCrop
				? await resolvePageCropBox(page, pageRotation, cachePath, resolvedPageNum, targetFileId)
				: FULL_PAGE_CROP
			if (renderId !== currentRenderId) return

			const cropped = isCropped(cropBox)
			const cropW = cropBox.right - cropBox.left
			const cropH = cropBox.bottom - cropBox.top
			const effUnscaledW = unscaledViewport.width * cropW
			const effUnscaledH = unscaledViewport.height * cropH

			let pageScale = effectiveScale.value
			if (isDoubleLayout) {
				pageScale = computeDoublePageScale({
					unscaledWidth: effUnscaledW,
					unscaledHeight: effUnscaledH,
					doubleSlotW,
					containerH,
					zoomMode: zoomMode.value,
					effectiveScale: effectiveScale.value,
					docRefFitScale,
				})
			} else if (cropped && (zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page')) {
				pageScale = computeFitScale(effUnscaledW, effUnscaledH, targetW, containerH, zoomMode.value)
			}
			const viewport = page.getViewport({ scale: pageScale, rotation: pageRotation })
			const displayW = cropped ? viewport.width * cropW : viewport.width
			const displayH = cropped ? viewport.height * cropH : viewport.height

			canvas = document.createElement('canvas')
			canvas.className = 'sheetmusic-pdf-page'
			canvas.style.display = 'block'
			canvas.style.boxShadow = '0 2px 8px rgba(0, 0, 0, 0.2)'

			await renderPdfPageToCanvas(page, canvas, viewport, {
				onRenderTask,
				maxDpr: pageLayout.value === 'continuous' ? 2 : undefined,
				cssWidth: cropped ? displayW : undefined,
				cssHeight: cropped ? displayH : undefined,
				offsetX: cropped ? -cropBox.left * viewport.width : 0,
				offsetY: cropped ? -cropBox.top * viewport.height : 0,
			})

			if (renderId === currentRenderId) {
				placeholder.replaceChildren(canvas)
				canvas = null
				placeholder.style.position = 'relative'
				placeholder.style.minHeight = ''
				placeholder.style.minWidth = ''
				placeholder.dataset.pageWidth = String(viewport.width)
				placeholder.dataset.pageHeight = String(viewport.height)
				if (cropped) {
					placeholder.dataset.cropLeft = String(cropBox.left)
					placeholder.dataset.cropTop = String(cropBox.top)
					placeholder.dataset.cropRight = String(cropBox.right)
					placeholder.dataset.cropBottom = String(cropBox.bottom)
				} else {
					delete placeholder.dataset.cropLeft
					delete placeholder.dataset.cropTop
					delete placeholder.dataset.cropRight
					delete placeholder.dataset.cropBottom
				}
				if (!isNextFile) {
					mountAnnotationOverlay(placeholder, resolvedPageNum, viewport.width, viewport.height, cropped ? cropBox : undefined)
				}
			}
		} catch (err: any) {
			if (err?.name === 'RenderingCancelledException') {
				renderedPlaceholders.delete(placeholder)
				return
			}
			console.warn(`Could not render page ${pageNumber}:`, err)
		} finally {
			onRenderTask?.(null)
			releaseCanvas(canvas)
			cleanupPdfJsPage(page)
		}
	}

	function swapStageContent(children: HTMLElement[]) {
		if (!pdfStage.value) return
		cleanupObserver()
		unmountAnnotationOverlays()
		releaseChildCanvases(pdfStage.value)
		onBeforeSwap?.()
		applyStageLayoutStyles(pdfStage.value, {
			gap: nextStageGap,
			isDoubleTwoPage: isDoubleLayout && children.length === 2,
			isCustomZoom: zoomMode.value === 'custom',
		})
		pdfStage.value.replaceChildren(...children)
	}

	if (pageLayout.value === 'continuous') {
		if (typeof IntersectionObserver === 'undefined') {
			swapStageContent([])
			for (const item of pagesToRender) {
				const placeholder = createPagePlaceholder(item, 'sheetmusic-page-placeholder')
				pdfStage.value.append(placeholder)
				await renderPage(item.pageNum, placeholder)
			}
			return
		}

		const placeholders = pagesToRender.map((item) => createContinuousPagePlaceholder(item, effectiveScale.value))

		// Render the active page(s) off-DOM before the atomic DOM swap.
		// When tightCrop is enabled, only pre-render the active page to keep startup fast.
		const preRenderIndices = new Set<number>()
		pagesToRender.forEach((item, idx) => {
			const radius = props.tightCrop ? 0 : 1
			if (Math.abs(item.index - currentPage.value) <= radius) {
				preRenderIndices.add(idx)
			}
		})
		if (preRenderIndices.size === 0 && pagesToRender.length > 0) {
			preRenderIndices.add(0)
		}
		await Promise.all(
			Array.from(preRenderIndices)
				.filter((idx) => placeholders[idx] && pagesToRender[idx])
				.map((idx) => renderPage(pagesToRender[idx].pageNum, placeholders[idx])),
		)
		if (renderId !== currentRenderId || !pdfContainer.value || !pdfStage.value) {
			return
		}

		swapStageContent(placeholders)

		intersectionObserver = observeContinuousPdfPages({
			root: pdfContainer.value,
			placeholders,
			preRenderedPlaceholders: Array.from(preRenderIndices)
				.map((idx) => placeholders[idx])
				.filter((el): el is HTMLElement => Boolean(el)),
			renderedPlaceholders,
			isStale: () => renderId !== currentRenderId,
			renderPage: (pageNum, targetEl, onRenderTask) => renderPage(pageNum, targetEl, false, onRenderTask),
			onEvict: (el) => {
				unmountAnnotationOverlay(el)
				releaseChildCanvases(el)
			},
		})
	} else if (isDoubleLayout) {
		const isTwoPageSpread = pagesToRender.length === 2
		const slotPairs = pagesToRender.map((item) => createDoublePageSlot(item, {
			doubleSlotW,
			isTwoPageSpread,
			isCustomZoom: zoomMode.value === 'custom',
		}))
		await Promise.all(
			pagesToRender.map((item, idx) => renderPage(item.pageNum, slotPairs[idx].placeholder, Boolean(item.isNextFile))),
		)
		if (renderId !== currentRenderId || !pdfContainer.value || !pdfStage.value) {
			return
		}
		swapStageContent(slotPairs.map((pair) => pair.slot))
	} else {
		// Single page mode: render off-DOM first, then swap atomically to avoid flicker
		const newPlaceholders = pagesToRender.map((item) => createPagePlaceholder(item))
		await Promise.all(
			pagesToRender.map((item, idx) => renderPage(item.pageNum, newPlaceholders[idx], Boolean(item.isNextFile))),
		)
		if (renderId !== currentRenderId || !pdfContainer.value || !pdfStage.value) {
			return
		}
		swapStageContent(newPlaceholders)
	}

	// Clean up any unused preloaded pages (e.g. if render was aborted early)
	for (const leftover of preloadedPages.values()) {
		cleanupPdfJsPage(leftover)
	}
	preloadedPages.clear()

	// Lazily prefetch crop boxes for the next 1-2 upcoming pages during idle time
	// so page flipping stays instant without slowing initial startup.
	if (props.tightCrop && pageLayout.value !== 'continuous') {
		const activeNums = new Set(pagesToRender.filter((p) => !p.isNextFile).map((p) => p.pageNum))
		const lastActiveIdx = ordered.findIndex((n) => n === pagesToRender[pagesToRender.length - 1]?.pageNum)
		const upcoming = (lastActiveIdx >= 0 ? ordered.slice(lastActiveIdx + 1, lastActiveIdx + 3) : [])
			.filter((n) => !activeNums.has(n))
		if (upcoming.length > 0) {
			const runPrefetch = () => {
				idlePrefetchCancel = null
				void (async () => {
					for (const nextPageNum of upcoming) {
						if (renderId !== currentRenderId || loadedPdf.value !== pdf) return
						let p: any = null
						try {
							p = await pdf.getPage(nextPageNum)
							if (renderId !== currentRenderId) return
							const rot = getEffectivePageRotation(p, rotation.value)
							const key = buildCropCacheKey(props.filePath, nextPageNum, rot)
							if (!getCachedPdfPageCropBox(key)) {
								await resolvePageCropBox(p, rot, props.filePath, nextPageNum, props.fileId)
							}
						} catch {
							// Best-effort prefetch
						} finally {
							cleanupPdfJsPage(p)
						}
					}
				})()
			}
			if (typeof window !== 'undefined' && 'requestIdleCallback' in window) {
				const handle = (window as any).requestIdleCallback(runPrefetch, { timeout: 600 })
				idlePrefetchCancel = () => (window as any).cancelIdleCallback?.(handle)
			} else {
				const handle = setTimeout(runPrefetch, 150)
				idlePrefetchCancel = () => clearTimeout(handle)
			}
		}
	}
}

async function fetchBinaryData(cacheKey: string, loadId: number): Promise<Uint8Array | null> {
	return fetchCachedBinaryFile(cacheKey, {
		cache: props.binaryCache ?? defaultFileBinaryCache,
		isStale: () => loadId !== currentLoadId,
	})
}

async function loadAndRenderFile() {
	const loadId = ++currentLoadId
	errorMessage.value = ''
	if (loadedPdf.value) {
		void destroyPdfJsDocument(loadedPdf.value)
		loadedPdf.value = null
	}
	if (loadedNextPdf.value) {
		void destroyPdfJsDocument(loadedNextPdf.value)
		loadedNextPdf.value = null
		loadedNextPdfPath = ''
	}
	totalPages.value = 1
	currentPage.value = 1
	rotation.value = 0
	fileSizeBytes.value = null
	pageResolutions.value = []
	xlsxSheets.value = []

	await nextTick()
	cleanupObserver()
	if (pdfStage.value) {
		releaseChildCanvases(pdfStage.value)
		pdfStage.value.replaceChildren()
	} else if (pdfContainer.value) {
		pdfContainer.value.replaceChildren()
	}
	if (docxContainer.value) {
		docxContainer.value.replaceChildren()
	}

	if (!props.filePath) {
		isLoading.value = false
		errorMessage.value = t('sheetmusic', 'Missing file: The file does not exist on the file system.')
		return
	}

	if (!isPdfFile.value && !isDocxFile.value && !isXlsxFile.value) {
		isLoading.value = false
		return
	}

	isLoading.value = true

	try {
		const binaryData = await fetchBinaryData(props.filePath, loadId)
		if (loadId !== currentLoadId) return

		fileSizeBytes.value = typeof binaryData?.byteLength === 'number' ? binaryData.byteLength : null

		if (isDocxFile.value) {
			if (!binaryData) {
				errorMessage.value = t('sheetmusic', 'Failed to load document.')
				return
			}
			const { renderAsync } = await import('docx-preview')
			if (loadId !== currentLoadId) return
			isLoading.value = false
			await nextTick()
			if (loadId !== currentLoadId || !docxContainer.value) return
			await renderAsync(binaryData.slice().buffer, docxContainer.value, undefined, {
				inWrapper: true,
				ignoreWidth: false,
				ignoreHeight: false,
			})
			return
		}

		if (isXlsxFile.value) {
			if (!binaryData) {
				errorMessage.value = t('sheetmusic', 'Failed to load spreadsheet.')
				return
			}
			const XLSX = await import('xlsx')
			if (loadId !== currentLoadId) return
			const workbook = XLSX.read(binaryData, { type: 'array' })
			xlsxSheets.value = workbook.SheetNames.map((name) => ({
				name,
				html: XLSX.utils.sheet_to_html(workbook.Sheets[name], { editable: false }),
			}))
			return
		}

		const pdfData = binaryData
		const pdf = await loadPdfJsDocument(pdfData ? pdfData.slice() : pdfData)

		if (loadId !== currentLoadId) return

		loadedPdf.value = pdf
		const ordered = parsePageOrder(props.pageOrder, pdf.numPages)
		totalPages.value = ordered.length

		if (props.startAtEnd && totalPages.value > 0) {
			currentPage.value = totalPages.value
		} else {
			currentPage.value = props.targetPage && props.targetPage >= 1
				? Math.min(props.targetPage, totalPages.value)
				: 1
		}

		// Collect page resolution summaries (up to first 25 pages for responsiveness)
		try {
			const summaries = await inspectPdfDocumentResolutions(pdf, 25)
			if (loadId === currentLoadId) {
				pageResolutions.value = summaries
			}
		} catch {
			// Ignore resolution inspection errors on mock or malformed PDFs
		}

		if (loadId !== currentLoadId) return
		isLoading.value = false
		await nextTick()
		if (loadId !== currentLoadId) return

		syncObservedSize()

		// If the document is large (>30 pages) and caller didn't specify a paged/auto layout, default to single page
		if (pdf.numPages > 30 && props.initialPageLayout === 'continuous') {
			pageLayout.value = 'single'
		} else if (autoLayout.value) {
			pageLayout.value = await resolveAutoLayoutForPage(currentPage.value)
		}

		doubleSpread.value = effectiveDoubleTotalPages.value > 1
			? getDoubleSpreadForPage(currentPage.value, effectiveDoubleTotalPages.value, doublePageTurnMode.value)
			: getInitialDoubleSpread(effectiveDoubleTotalPages.value)

		await renderCurrentView()
	} catch (error: any) {
		console.error('Failed to load or render PDF:', error)
		if (loadId === currentLoadId) {
			const status = error?.response?.status ?? error?.status
			if (status === 404) {
				errorMessage.value = t('sheetmusic', 'Missing file: The file does not exist on the file system.')
			} else {
				errorMessage.value = t('sheetmusic', 'Could not load the selected file.')
			}
		}
	} finally {
		if (loadId === currentLoadId) {
			isLoading.value = false
		}
	}
}

function handleImageError() {
	errorMessage.value = t('sheetmusic', 'Missing file: The file does not exist on the file system.')
}

const { syncObservedSize } = usePdfResizeObserver({
	containerRef: pdfContainer,
	shouldRerenderOnResize: () => autoLayout.value || zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page',
	canRerender: () => Boolean(loadedPdf.value),
	onRerender: () => {
		renderCurrentView()
	},
})

onUnmounted(() => {
	cancelIdleCropPrefetch()
	unmountAnnotationOverlays()
	cleanupObserver()
	releaseChildCanvases(pdfStage.value)
	if (loadedPdf.value) {
		void destroyPdfJsDocument(loadedPdf.value)
		loadedPdf.value = null
	}
	if (loadedNextPdf.value) {
		void destroyPdfJsDocument(loadedNextPdf.value)
		loadedNextPdf.value = null
		loadedNextPdfPath = ''
	}
})

watch(() => props.filePath, loadAndRenderFile, { immediate: true })

watch(
	() => [props.filePath, props.initialCropBoxes] as const,
	([filePath, boxes]) => {
		if (filePath && boxes) {
			hydratePdfCropCache(filePath, boxes)
		}
	},
	{ immediate: true },
)

watch(
	() => [props.nextFilePath, props.nextInitialCropBoxes] as const,
	([filePath, boxes]) => {
		if (filePath && boxes) {
			hydratePdfCropCache(filePath, boxes)
		}
	},
	{ immediate: true },
)

watch(
	() => props.tightCrop,
	() => {
		if (loadedPdf.value) {
			renderCurrentView()
		}
	},
)

watch(
	() => props.pageOrder,
	() => {
		if (loadedPdf.value) {
			const ordered = parsePageOrder(props.pageOrder, loadedPdf.value.numPages)
			totalPages.value = ordered.length
			currentPage.value = 1
			doubleSpread.value = getInitialDoubleSpread(totalPages.value)
			renderCurrentView()
		}
	},
)

watch(
	() => props.targetPage,
	(newPage) => {
		if (newPage && newPage >= 1) {
			goToPage(newPage)
		}
	},
)

const { settings: stylusSettings } = useStylusSettings()

const { wasRecentlyPinching, wasRecentlyDragging, resetPan } = usePdfTouchZoom({
	containerRef: pdfContainer,
	stageRef: pdfStage,
	getCurrentScale: () => effectiveScale.value,
	isEnabled: () => isPdfFile.value && !isLoading.value && !errorMessage.value,
	isAnnotating: () => props.editableAnnotations,
	isTouchZoomLocked: () => stylusSettings.value.lockTouchZoomWhileAnnotating,
	onCommitZoom: async (scale, onBeforeSwap) => {
		zoomMode.value = 'custom'
		customScale.value = scale
		await renderCurrentView(onBeforeSwap)
	},
})

watch(() => props.filePath, () => {
	resetPan()
})

function canPanHorizontally(): boolean {
	const el = pdfContainer.value
	return Boolean(el && el.scrollWidth > el.clientWidth + 2)
}

defineExpose({
	openInExternalViewer,
	goToPage,
	renderPageThumbnail,
	prevPage,
	nextPage,
	setPageLayout,
	setAutoLayout,
	setDoublePageTurnMode,
	setZoomMode,
	wasRecentlyPinching,
	wasRecentlyDragging,
	canPanHorizontally,
	currentPage,
	totalPages,
	currentPageDisplay,
	pageLayout,
	autoLayout,
	doublePageTurnMode,
	doubleSpread,
	zoomMode,
	canGoPrev,
	canGoNext,
	isShowingNextSongFirstPage,
	fileSizeBytes,
	pageResolutions,
	showAnnotationsState,
	toggleAnnotations,
})
</script>

<template>
	<div :class="$style.wrapper">
		<div v-if="isLoading" :class="$style.loading">
			<NcLoadingIcon :size="32" :name="t('sheetmusic', 'Loading file...')" />
		</div>

		<p v-if="errorMessage" :class="$style.error" role="alert">
			{{ errorMessage }}
		</p>

		<div v-if="!isLoading && !errorMessage && isImageFile" :class="$style.imageViewer">
			<img
				:src="filePath"
				:alt="filePath"
				:style="imageStyle"
				:class="$style.previewImage"
				@error="handleImageError">
		</div>

		<div
			v-show="!isLoading && !errorMessage && isPdfFile"
			ref="pdfContainer"
			:style="backgroundColor ? { backgroundColor } : undefined"
			:class="[$style.pdfContainer, !editableAnnotations && $style.pdfContainerGrabbable]">
			<div
				ref="pdfStage"
				:class="[$style.pdfStage, $style[`layout-${pageLayout}`], noPadding && $style.noPadding]" />
		</div>

		<div
			v-show="!isLoading && !errorMessage && isDocxFile"
			ref="docxContainer"
			:class="$style.docxContainer" />

		<XlsxPreviewPane
			v-if="!isLoading && !errorMessage && isXlsxFile"
			:sheets="xlsxSheets" />

		<div v-if="!isLoading && !errorMessage && isUnsupportedFile" :class="$style.unsupported">
			<p>{{ t('sheetmusic', 'Preview is not available for this file type.') }}</p>
			<NcButton
				v-if="showExternalButton"
				variant="primary"
				@click="openInExternalViewer">
				<template #icon>
					<OpenInNewIcon :size="18" />
				</template>
				{{ t('sheetmusic', 'Open File Externally') }}
			</NcButton>
		</div>

		<!-- Floating Jump-Back Chip after clicking a page link -->
		<button
			v-if="jumpBackEntry && !isLoading && isPdfFile"
			type="button"
			:class="$style.linkJumpBackChip"
			:aria-label="t('sheetmusic', 'Return to page {page}', { page: jumpBackEntry.returnPage })"
			data-test="link-jump-back-button"
			@click.stop="jumpBack">
			<ArrowULeftTopIcon :size="16" />
			<span>{{ t('sheetmusic', 'Return to p. {page}', { page: jumpBackEntry.returnPage }) }}</span>
		</button>

		<!-- PDF / Image Viewer Toolbar -->
		<FilePreviewerToolbar
			v-if="!hideToolbar && !isLoading && !errorMessage && (isPdfFile || isImageFile)"
			:is-pdf="isPdfFile"
			:current-page="currentPage"
			:total-pages="totalPages"
			:current-page-display="currentPageDisplay"
			:page-layout="pageLayout"
			:double-page-turn-mode="doublePageTurnMode"
			:can-go-prev="canGoPrev"
			:can-go-next="canGoNext"
			:zoom-select-value="zoomSelectValue"
			:is-custom-zoom="isCustomZoom"
			:display-scale="isImageFile ? imageScale : effectiveScale"
			:show-external-button="showExternalButton"
			:show-annotations="showAnnotationsState"
			@previous-page="prevPage"
			@next-page="nextPage"
			@page-layout="setPageLayout"
			@double-page-turn-mode="setDoublePageTurnMode"
			@zoom-select="onZoomSelect"
			@zoom-in="zoomIn"
			@zoom-out="zoomOut"
			@fit-width="setZoomMode('fit-width')"
			@rotate="rotateClockwise"
			@open-external="openInExternalViewer"
			@toggle-annotations="toggleAnnotations" />
	</div>
</template>

<style module>
.wrapper {
	position: relative;
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	flex: 1;
	min-height: 0;
}

button[type='button'].linkJumpBackChip {
	position: absolute;
	left: 50%;
	bottom: 64px;
	transform: translateX(-50%);
	z-index: 25;
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin: 0;
	padding: 6px 14px;
	border: 1px solid var(--color-primary-element, #0082c9);
	border-radius: 999px;
	background: var(--color-main-background, #ffffff);
	color: var(--color-main-text, #222222);
	font-size: 0.85rem;
	font-weight: 600;
	box-shadow: 0 4px 14px rgba(0, 0, 0, 0.22);
	cursor: pointer;
}

button[type='button'].linkJumpBackChip:hover {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-text, #ffffff);
}

.loading {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 48px;
}

.error {
	margin: 16px;
	padding: 14px 16px;
	border: 1px solid var(--color-error);
	border-left-width: 4px;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.imageViewer {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 24px;
	overflow: auto;
	flex: 1;
	min-height: 0;
}

.previewImage {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
}

.pdfContainer {
	display: flex;
	overflow: auto;
	flex: 1;
	min-height: 0;
	width: 100%;
	box-sizing: border-box;
	background-color: var(--color-background-dark, #f5f5f5);
	touch-action: pan-x pan-y;
	-webkit-overflow-scrolling: touch;
}

.pdfContainerGrabbable {
	cursor: grab;
}

:global(html:not(.sheetmusic-panning)) .pdfContainer:global(.sheetmusic-pan-ready),
:global(html:not(.sheetmusic-panning)) .pdfContainer:global(.sheetmusic-pan-ready) * {
	cursor: grab !important;
}

:global(html.sheetmusic-panning),
:global(html.sheetmusic-panning *) {
	cursor: grabbing !important;
}

.pdfStage {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 24px;
	min-width: 100%;
	width: max-content;
	margin: auto;
	box-sizing: border-box;
}

.pdfStage.layout-double {
	flex-direction: row;
	flex-wrap: nowrap;
	align-items: center;
	justify-content: center;
}

.pdfStage.noPadding {
	padding: 0;
	gap: 4px;
}

.pdfStage.noPadding.layout-single {
	justify-content: center;
}

.docxContainer {
	flex: 1;
	min-height: 0;
	overflow: auto;
	padding: 16px;
	background-color: var(--color-background-dark, #f5f5f5);
}

.docxContainer :global(.docx-wrapper) {
	background: transparent;
	padding: 8px;
}

.docxContainer :global(.docx-wrapper > section.docx) {
	margin-bottom: 16px;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.12);
}

.unsupported {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 48px 16px;
	gap: 16px;
	color: var(--color-text-maxcontrast);
}
</style>
