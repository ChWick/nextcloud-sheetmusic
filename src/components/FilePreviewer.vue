<script setup lang="ts">
import { computed, getCurrentInstance, h, nextTick, onMounted, onUnmounted, ref, render, shallowRef, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { cleanupPdfJsPage, destroyPdfJsDocument, loadPdfJsDocument, releaseCanvas, releaseChildCanvases } from '../utils/pdfjs'
import { openFileExternally } from '../utils/file'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import FilePreviewerToolbar from './file-preview/FilePreviewerToolbar.vue'
import SongAnnotationOverlay from './song-viewer/SongAnnotationOverlay.vue'
import { usePdfTouchZoom } from '../composables/usePdfTouchZoom'
import { isEffectivelyOffline } from '../composables/useOnlineStatus'
import { readOfflineCachedBinary } from '../utils/offlineSetlists'
import { inspectPdfDocumentResolutions, type PdfPageResolutionSummary } from './song-pdf/pdf-enhance-engine'
import { parsePageOrder } from '../utils/page-order'
import type { SheetAnnotation } from '../types/annotation'
import type { EditorTool, EditorToolStyle } from '../composables/useAnnotationEditor'
import { defaultFileBinaryCache, type FileBinaryCache } from '../utils/file-cache'
import {
	type DoublePageTurnMode,
	type DoubleSpread,
	formatDoubleSpreadDisplay,
	getDoubleSpreadForPage,
	getInitialDoubleSpread,
	nextDoubleSpread,
	prevDoubleSpread,
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
		binaryCache: () => defaultFileBinaryCache,
		initialPageLayout: 'continuous',
		initialDoublePageTurnMode: 'half',
		initialZoomMode: 'fit-width',
		startAtEnd: false,
		noPadding: false,
		nextFilePath: undefined,
		nextTargetPage: undefined,
		nextPageOrder: undefined,
		interleaveSongs: false,
	},
)

const emit = defineEmits<{
	'select-annotation': [uuid: string | null]
	'create-annotation': [draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'>]
	'update-annotation': [before: SheetAnnotation, after: SheetAnnotation]
	'delete-annotation': [annotation: SheetAnnotation]
}>()

const appContext = getCurrentInstance()?.appContext ?? null

const pdfContainer = ref<HTMLElement>()
const pdfStage = ref<HTMLElement>()
const docxContainer = ref<HTMLElement>()
const xlsxSheets = ref<Array<{ name: string; html: string }>>([])
const activeXlsxSheetIndex = ref(0)
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

type PageLayoutMode = 'continuous' | 'single' | 'double'
type ZoomMode = 'fit-width' | 'fit-page' | 'custom'

function isContainerLandscape(): boolean {
	if (typeof window === 'undefined') return false
	const containerW = pdfContainer.value?.clientWidth || window.innerWidth
	const containerH = pdfContainer.value?.clientHeight || window.innerHeight
	return containerW >= containerH && containerW >= 700
}

function detectResponsiveLayout(): PageLayoutMode {
	return isContainerLandscape() ? 'double' : 'single'
}

async function isLogicalPageLandscape(logicalPage: number): Promise<boolean> {
	if (!loadedPdf.value) return false
	let page: any = null
	try {
		const ordered = parsePageOrder(props.pageOrder, loadedPdf.value.numPages)
		const idx = Math.min(Math.max(1, logicalPage), ordered.length) - 1
		const pageNum = ordered[idx] || 1
		page = await loadedPdf.value.getPage(pageNum)
		const pageRotation = (((page.rotate ?? 0) + rotation.value) % 360 + 360) % 360
		const viewport = page.getViewport({ scale: 1.0, rotation: pageRotation })
		return viewport.width > viewport.height
	} catch {
		return false
	} finally {
		cleanupPdfJsPage(page)
	}
}

async function resolveAutoLayoutForPage(logicalPage: number): Promise<PageLayoutMode> {
	if (!isContainerLandscape()) return 'single'
	if (await isLogicalPageLandscape(logicalPage)) return 'single'
	return 'double'
}

const autoLayout = ref(props.initialPageLayout === 'auto')
const pageLayout = ref<PageLayoutMode>(
	props.initialPageLayout === 'auto' ? detectResponsiveLayout() : props.initialPageLayout,
)
const doublePageTurnMode = ref<DoublePageTurnMode>(props.initialDoublePageTurnMode)
const doubleSpread = ref<DoubleSpread>([1, null])
const zoomMode = ref<ZoomMode>(props.initialZoomMode)
const customScale = ref(1.0)
const effectiveScale = ref(1.0)
const rotation = ref(0)
const imageScale = ref(1.0)
const showAnnotationsState = ref(props.showAnnotations ?? true)

function toggleAnnotations() {
	showAnnotationsState.value = !showAnnotationsState.value
	refreshAnnotationOverlays()
}

function unmountAnnotationOverlays() {
	if (!pdfContainer.value) return
	const mounts = pdfContainer.value.querySelectorAll<HTMLElement>('.sheetmusic-annotation-mount')
	mounts.forEach((el) => {
		render(null, el)
	})
}

function mountAnnotationOverlay(placeholder: HTMLElement, pageNum: number, pageWidth: number, pageHeight: number) {
	let mountEl = placeholder.querySelector<HTMLElement>('.sheetmusic-annotation-mount')
	if (!mountEl) {
		mountEl = document.createElement('div')
		mountEl.className = 'sheetmusic-annotation-mount'
		mountEl.style.position = 'absolute'
		mountEl.style.inset = '0'
		mountEl.style.pointerEvents = 'none'
		placeholder.appendChild(mountEl)
	}

	const vnode = h(SongAnnotationOverlay, {
		annotations: props.annotations,
		pageNumber: pageNum,
		visible: showAnnotationsState.value || props.editableAnnotations,
		pageWidth,
		pageHeight,
		totalPages: totalPages.value,
		editable: props.editableAnnotations,
		activeTool: props.activeAnnotationTool,
		toolStyle: props.annotationToolStyle,
		selectedUuid: props.selectedAnnotationUuid,
		onJumpToPage: (targetZeroIdx: number) => goToPage(targetZeroIdx + 1),
		onSelect: (uuid: string | null) => emit('select-annotation', uuid),
		onCreateAnnotation: (draft: Omit<SheetAnnotation, 'songId' | 'fileId' | 'uuid'>) => emit('create-annotation', draft),
		onUpdateAnnotation: (before: SheetAnnotation, after: SheetAnnotation) => emit('update-annotation', before, after),
		onDeleteAnnotation: (ann: SheetAnnotation) => emit('delete-annotation', ann),
	})
	if (appContext) {
		vnode.appContext = appContext
	}
	render(vnode, mountEl)
}

function refreshAnnotationOverlays() {
	if (!pdfContainer.value) return
	const placeholders = pdfContainer.value.querySelectorAll<HTMLElement>('[data-page-num]')
	placeholders.forEach((el) => {
		const pageNum = Number(el.dataset.pageNum)
		const canvas = el.querySelector('canvas')
		if (pageNum && canvas) {
			const pw = Number(el.dataset.pageWidth) || canvas.width || 595
			const ph = Number(el.dataset.pageHeight) || canvas.height || 842
			mountAnnotationOverlay(el, pageNum, pw, ph)
		}
	})
}

watch(
	() => [
		props.annotations,
		showAnnotationsState.value,
		props.editableAnnotations,
		props.activeAnnotationTool,
		props.annotationToolStyle,
		props.selectedAnnotationUuid,
	],
	() => {
		refreshAnnotationOverlays()
	},
	{ deep: true },
)

watch(
	() => props.showAnnotations,
	(val) => {
		showAnnotationsState.value = val ?? true
		refreshAnnotationOverlays()
	},
)

const zoomSteps = [0.25, 0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0, 2.5, 3.0, 4.0]

const fileExtTarget = computed(() => (props.fileName || props.filePath).split('?')[0].toLowerCase())

const isPdfFile = computed(() => {
	return fileExtTarget.value.endsWith('.pdf')
})

const isImageFile = computed(() => {
	const path = fileExtTarget.value
	return path.endsWith('.png') || path.endsWith('.jpg') || path.endsWith('.jpeg') || path.endsWith('.webp') || path.endsWith('.gif') || path.endsWith('.svg') || path.endsWith('.bmp')
})

const isDocxFile = computed(() => {
	return fileExtTarget.value.endsWith('.docx')
})

const isXlsxFile = computed(() => {
	const path = fileExtTarget.value
	return path.endsWith('.xlsx') || path.endsWith('.xls')
})

const isUnsupportedFile = computed(() => {
	return !isPdfFile.value && !isImageFile.value && !isDocxFile.value && !isXlsxFile.value
})

const canInterleaveWithNext = computed(() => {
	return Boolean(props.interleaveSongs && props.nextFilePath && props.nextFilePath.split('?')[0].toLowerCase().endsWith('.pdf'))
})

const effectiveDoubleTotalPages = computed(() => {
	if (!canInterleaveWithNext.value) {
		return totalPages.value
	}
	if (doublePageTurnMode.value === 'step') {
		return totalPages.value + 1
	}
	return totalPages.value % 2 === 1 ? totalPages.value + 1 : totalPages.value
})

const isShowingNextSongFirstPage = computed(() => {
	return pageLayout.value === 'double'
		&& canInterleaveWithNext.value
		&& doubleSpread.value[1] === totalPages.value + 1
})

const currentPageDisplay = computed(() => {
	if (pageLayout.value === 'double') {
		if (isShowingNextSongFirstPage.value) {
			return `${doubleSpread.value[0]} / →1`
		}
		return formatDoubleSpreadDisplay(doubleSpread.value)
	}
	if (pageLayout.value === 'continuous') {
		return `1–${totalPages.value || 1}`
	}
	return `${currentPage.value}`
})

const canGoPrev = computed(() => {
	if (pageLayout.value === 'continuous') return false
	if (pageLayout.value === 'double') {
		return prevDoubleSpread(doubleSpread.value, effectiveDoubleTotalPages.value, doublePageTurnMode.value) !== null
	}
	return currentPage.value > 1
})

const canGoNext = computed(() => {
	if (pageLayout.value === 'continuous') return false
	if (pageLayout.value === 'double') {
		return nextDoubleSpread(doubleSpread.value, effectiveDoubleTotalPages.value, doublePageTurnMode.value) !== null
	}
	return currentPage.value < totalPages.value
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

function openInExternalViewer() {
	if (!props.filePath) return
	openFileExternally(props.filePath)
}

function prevPage(): boolean {
	if (pageLayout.value === 'double') {
		const prev = prevDoubleSpread(doubleSpread.value, effectiveDoubleTotalPages.value, doublePageTurnMode.value)
		if (!prev) return false
		doubleSpread.value = prev
		currentPage.value = prev[0]
		renderCurrentView()
		return true
	}
	if (pageLayout.value === 'single') {
		if (currentPage.value <= 1) return false
		currentPage.value -= 1
		renderCurrentView()
		return true
	}
	return false
}

function nextPage(): boolean {
	if (pageLayout.value === 'double') {
		const next = nextDoubleSpread(doubleSpread.value, effectiveDoubleTotalPages.value, doublePageTurnMode.value)
		if (!next) return false
		doubleSpread.value = next
		currentPage.value = next[0]
		renderCurrentView()
		return true
	}
	if (pageLayout.value === 'single') {
		if (currentPage.value >= totalPages.value) return false
		currentPage.value += 1
		renderCurrentView()
		return true
	}
	return false
}

function goToPage(targetPage: number) {
	if (targetPage < 1) targetPage = 1
	if (totalPages.value > 0 && targetPage > totalPages.value) {
		targetPage = totalPages.value
	}
	currentPage.value = targetPage
	doubleSpread.value = getDoubleSpreadForPage(targetPage, totalPages.value, doublePageTurnMode.value)
	if (pageLayout.value === 'continuous') {
		const placeholder = (pdfContainer.value?.querySelector(`[data-page-index="${targetPage}"]`) as HTMLElement | null)
			?? (pdfContainer.value?.querySelector(`[data-page-num="${targetPage}"]`) as HTMLElement | null)
		if (placeholder) {
			placeholder.scrollIntoView({ behavior: 'smooth', block: 'start' })
		}
	} else {
		renderCurrentView()
	}
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
		const pageRotation = (((page.rotate ?? 0) + rotation.value) % 360 + 360) % 360
		const baseViewport = page.getViewport({ scale: 1.0, rotation: pageRotation })
		const scale = targetWidth / Math.max(1, baseViewport.width)
		const viewport = page.getViewport({ scale, rotation: pageRotation })
		canvas.width = Math.floor(viewport.width)
		canvas.height = Math.floor(viewport.height)
		const ctx = canvas.getContext('2d')
		if (!ctx) return
		ctx.fillStyle = '#ffffff'
		ctx.fillRect(0, 0, canvas.width, canvas.height)
		await page.render({
			canvasContext: ctx,
			viewport,
			background: 'rgb(255, 255, 255)',
		}).promise
	} catch {
		// Ignore thumbnail render errors
	} finally {
		cleanupPdfJsPage(page)
	}
}

function setPageLayout(mode: PageLayoutMode) {
	autoLayout.value = false
	if (pageLayout.value === mode) return
	pageLayout.value = mode
	if (mode === 'double') {
		doubleSpread.value = getDoubleSpreadForPage(currentPage.value, totalPages.value, doublePageTurnMode.value)
	}
	renderCurrentView()
}

async function setAutoLayout(enabled: boolean) {
	autoLayout.value = enabled
	if (enabled) {
		const detected = await resolveAutoLayoutForPage(currentPage.value)
		if (pageLayout.value !== detected) {
			pageLayout.value = detected
			if (detected === 'double') {
				doubleSpread.value = getDoubleSpreadForPage(currentPage.value, totalPages.value, doublePageTurnMode.value)
			}
			renderCurrentView()
		}
	}
}

function setDoublePageTurnMode(mode: DoublePageTurnMode) {
	if (doublePageTurnMode.value === mode) return
	doublePageTurnMode.value = mode
	doubleSpread.value = getDoubleSpreadForPage(currentPage.value, totalPages.value, mode)
	if (pageLayout.value === 'double') {
		renderCurrentView()
	}
}

function setZoomMode(mode: ZoomMode) {
	zoomMode.value = mode
	if (mode === 'fit-width' || mode === 'fit-page') {
		resetPan()
		if (pdfContainer.value) {
			pdfContainer.value.scrollLeft = 0
			pdfContainer.value.scrollTop = 0
		}
	}
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

let intersectionObserver: IntersectionObserver | null = null
const renderedPages = new Set<number>()

function cleanupObserver() {
	if (intersectionObserver) {
		intersectionObserver.disconnect()
		intersectionObserver = null
	}
	renderedPages.clear()
}

async function renderCurrentView(onBeforeSwap?: () => void) {
	if (!loadedPdf.value || !pdfContainer.value || !pdfStage.value) return

	const renderId = ++currentRenderId
	const pdf = loadedPdf.value
	const ordered = parsePageOrder(props.pageOrder, pdf.numPages)
	const total = ordered.length

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

	let pagesToRender: Array<{ pageNum: number; index: number; isNextFile?: boolean }> = []
	if (pageLayout.value === 'single') {
		const idx = Math.min(Math.max(1, currentPage.value), total) - 1
		pagesToRender = [{ pageNum: ordered[idx] || 1, index: idx + 1 }]
	} else if (pageLayout.value === 'double') {
		const [leftLogical, rightLogical] = doubleSpread.value
		const leftIdx = Math.min(Math.max(1, leftLogical), total) - 1
		pagesToRender = [{ pageNum: ordered[leftIdx] || 1, index: leftIdx + 1 }]
		if (rightLogical !== null && rightLogical >= 1) {
			if (rightLogical <= total) {
				const rightIdx = rightLogical - 1
				pagesToRender.push({ pageNum: ordered[rightIdx] || 1, index: rightIdx + 1 })
			} else if (rightLogical === total + 1 && canInterleaveWithNext.value) {
				pagesToRender.push({
					pageNum: props.nextTargetPage && props.nextTargetPage >= 1 ? props.nextTargetPage : 1,
					index: total + 1,
					isNextFile: true,
				})
			}
		}
	} else {
		pagesToRender = ordered.map((p, idx) => ({ pageNum: p, index: idx + 1 }))
	}

	// Calculate base scale using first page
	let scale = 1.25
	let firstPage: any = null
	try {
		firstPage = await pdf.getPage(pagesToRender[0]?.pageNum || 1)
		const pageRotation = (((firstPage.rotate ?? 0) + rotation.value) % 360 + 360) % 360
		const unscaledViewport = firstPage.getViewport({ scale: 1.0, rotation: pageRotation })

		const pad = props.noPadding ? 0 : 48
		const doubleGap = props.noPadding ? 4 : 24
		const containerW = (pdfContainer.value?.clientWidth && pdfContainer.value.clientWidth > 100)
			? pdfContainer.value.clientWidth - pad
			: 800
		const containerH = (pdfContainer.value?.clientHeight && pdfContainer.value.clientHeight > 100)
			? pdfContainer.value.clientHeight - pad
			: 700
		const targetW = pageLayout.value === 'double' && pagesToRender.length > 1
			? (containerW - doubleGap) / 2
			: containerW

		if (zoomMode.value === 'fit-width') {
			scale = targetW / unscaledViewport.width
		} else if (zoomMode.value === 'fit-page') {
			scale = Math.min(targetW / unscaledViewport.width, containerH / unscaledViewport.height)
		} else {
			scale = customScale.value
		}
	} catch {
		scale = 1.25
	} finally {
		cleanupPdfJsPage(firstPage)
	}
	scale = Math.max(0.2, Math.min(scale, 4.0))
	effectiveScale.value = scale
	const nextStageGap = `${Math.round((props.noPadding ? 4 : 16) * scale)}px`

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
			page = await targetPdf.getPage(resolvedPageNum)
			if (renderId !== currentRenderId) return

			const pageRotation = (((page.rotate ?? 0) + rotation.value) % 360 + 360) % 360
			const viewport = page.getViewport({ scale: effectiveScale.value, rotation: pageRotation })

			canvas = document.createElement('canvas')
			canvas.width = Math.floor(viewport.width)
			canvas.height = Math.floor(viewport.height)
			canvas.className = 'sheetmusic-pdf-page'
			canvas.style.display = 'block'
			canvas.style.boxShadow = '0 2px 8px rgba(0, 0, 0, 0.2)'

			const canvasContext = canvas.getContext('2d')
			if (canvasContext) {
				canvasContext.fillStyle = '#ffffff'
				canvasContext.fillRect(0, 0, canvas.width, canvas.height)
				const renderTask = page.render({
					canvasContext,
					viewport,
					background: 'rgb(255, 255, 255)',
				})
				onRenderTask?.(renderTask)
				await renderTask.promise
				onRenderTask?.(null)
			}

			if (renderId === currentRenderId) {
				placeholder.replaceChildren(canvas)
				canvas = null
				placeholder.style.position = 'relative'
				placeholder.style.minHeight = ''
				placeholder.style.minWidth = ''
				placeholder.dataset.pageWidth = String(viewport.width)
				placeholder.dataset.pageHeight = String(viewport.height)
				if (!isNextFile) {
					mountAnnotationOverlay(placeholder, resolvedPageNum, viewport.width, viewport.height)
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
		pdfStage.value.style.gap = nextStageGap
		pdfStage.value.replaceChildren(...children)
	}

	if (pageLayout.value === 'continuous') {
		if (typeof IntersectionObserver === 'undefined') {
			swapStageContent([])
			for (const item of pagesToRender) {
				const placeholder = document.createElement('div')
				placeholder.className = 'sheetmusic-page-placeholder'
				placeholder.dataset.pageNum = String(item.pageNum)
				placeholder.dataset.pageIndex = String(item.index)
				pdfStage.value.append(placeholder)
				await renderPage(item.pageNum, placeholder)
			}
			return
		}

		const placeholders = pagesToRender.map((item) => {
			const placeholder = document.createElement('div')
			placeholder.className = 'sheetmusic-page-placeholder'
			placeholder.dataset.pageNum = String(item.pageNum)
			placeholder.dataset.pageIndex = String(item.index)
			// Estimated placeholder size to stabilize scroll bar
			placeholder.style.minWidth = '200px'
			placeholder.style.minHeight = `${Math.round(800 * (effectiveScale.value / 1.25))}px`
			placeholder.style.display = 'flex'
			placeholder.style.justifyContent = 'center'
			placeholder.style.alignItems = 'center'
			placeholder.style.backgroundColor = '#ffffff'
			placeholder.style.boxShadow = '0 2px 8px rgba(0, 0, 0, 0.15)'
			return placeholder
		})

		// Render the first page and pages around currentPage off-DOM so visible pages have
		// real dimensions before the atomic DOM swap (avoiding placeholder->canvas flicker).
		const preRenderIndices = new Set<number>([0])
		pagesToRender.forEach((item, idx) => {
			if (Math.abs(item.index - currentPage.value) <= 1) {
				preRenderIndices.add(idx)
			}
		})
		await Promise.all(
			Array.from(preRenderIndices)
				.filter((idx) => placeholders[idx] && pagesToRender[idx])
				.map((idx) => renderPage(pagesToRender[idx].pageNum, placeholders[idx])),
		)
		if (renderId !== currentRenderId || !pdfContainer.value || !pdfStage.value) {
			return
		}

		swapStageContent(placeholders)

		const MAX_CONTINUOUS_RENDERED_PAGES = 16
		const MAX_CONCURRENT_CONTINUOUS_RENDERS = 3
		const visiblePlaceholders = new Set<HTMLElement>()
		const renderedOrder = new Set<HTMLElement>()
		const pendingQueue: HTMLElement[] = []
		const pendingSet = new Set<HTMLElement>()
		const activeTasks = new Map<HTMLElement, { cancel?: () => void }>()
		let activeWorkers = 0

		for (const idx of preRenderIndices) {
			const el = placeholders[idx]
			if (el && renderedPlaceholders.has(el)) {
				renderedOrder.add(el)
			}
		}

		const evictOffscreenPlaceholders = () => {
			if (renderedOrder.size <= MAX_CONTINUOUS_RENDERED_PAGES) return
			for (const el of renderedOrder) {
				if (renderedOrder.size <= MAX_CONTINUOUS_RENDERED_PAGES) break
				if (visiblePlaceholders.has(el)) continue
				renderedOrder.delete(el)
				renderedPlaceholders.delete(el)
				if (el.dataset.pageHeight) {
					el.style.minHeight = `${el.dataset.pageHeight}px`
				}
				if (el.dataset.pageWidth) {
					el.style.minWidth = `${el.dataset.pageWidth}px`
				}
				const mountEl = el.querySelector<HTMLElement>('.sheetmusic-annotation-mount')
				if (mountEl) {
					render(null, mountEl)
				}
				releaseChildCanvases(el)
				el.replaceChildren()
			}
		}

		const pumpContinuousQueue = () => {
			while (activeWorkers < MAX_CONCURRENT_CONTINUOUS_RENDERS && pendingQueue.length > 0) {
				const targetEl = pendingQueue.shift()!
				pendingSet.delete(targetEl)
				if (renderId !== currentRenderId || renderedPlaceholders.has(targetEl) || !visiblePlaceholders.has(targetEl)) {
					continue
				}
				const pageNum = Number(targetEl.dataset.pageNum)
				if (!pageNum) continue
				activeWorkers++
				void renderPage(pageNum, targetEl, false, (task) => {
					if (task) {
						activeTasks.set(targetEl, task)
					} else {
						activeTasks.delete(targetEl)
					}
				}).finally(() => {
					activeWorkers--
					if (renderId !== currentRenderId) return
					if (renderedPlaceholders.has(targetEl)) {
						renderedOrder.delete(targetEl)
						renderedOrder.add(targetEl)
						evictOffscreenPlaceholders()
					}
					pumpContinuousQueue()
				})
			}
		}

		intersectionObserver = new IntersectionObserver(
			(entries) => {
				for (const entry of entries) {
					const targetEl = entry.target as HTMLElement
					if (entry.isIntersecting) {
						visiblePlaceholders.add(targetEl)
						if (renderedPlaceholders.has(targetEl)) {
							renderedOrder.delete(targetEl)
							renderedOrder.add(targetEl)
						} else if (!pendingSet.has(targetEl)) {
							pendingSet.add(targetEl)
							pendingQueue.push(targetEl)
							pumpContinuousQueue()
						}
					} else {
						visiblePlaceholders.delete(targetEl)
						if (pendingSet.has(targetEl)) {
							pendingSet.delete(targetEl)
							const idx = pendingQueue.indexOf(targetEl)
							if (idx >= 0) pendingQueue.splice(idx, 1)
						}
						const activeTask = activeTasks.get(targetEl)
						if (activeTask) {
							try {
								activeTask.cancel?.()
							} catch {
								// Ignore cancel errors
							}
						}
						evictOffscreenPlaceholders()
					}
				}
			},
			{
				root: pdfContainer.value,
				rootMargin: '600px 0px 600px 0px', // Preload pages within 600px of scroll view
			},
		)

		for (const placeholder of placeholders) {
			intersectionObserver.observe(placeholder)
		}
	} else {
		// Single or double page mode: render off-DOM first, then swap atomically to avoid flicker
		const newPlaceholders = pagesToRender.map((item) => {
			const placeholder = document.createElement('div')
			placeholder.dataset.pageNum = String(item.pageNum)
			placeholder.dataset.pageIndex = String(item.index)
			return placeholder
		})
		await Promise.all(
			pagesToRender.map((item, idx) => renderPage(item.pageNum, newPlaceholders[idx], Boolean(item.isNextFile))),
		)
		if (renderId !== currentRenderId || !pdfContainer.value || !pdfStage.value) {
			return
		}
		swapStageContent(newPlaceholders)
	}
}

async function fetchBinaryData(cacheKey: string, loadId: number): Promise<Uint8Array | null> {
	const cache = props.binaryCache ?? defaultFileBinaryCache
	let data: Uint8Array | null = cache.get(cacheKey)
	if (!data && isEffectivelyOffline()) {
		data = await readOfflineCachedBinary(cacheKey)
		if (!data) {
			throw new Error('File is not available offline')
		}
		if (loadId !== currentLoadId) return null
	}
	if (!data) {
		const hasVersionParam = cacheKey.includes('v=') || cacheKey.includes('t=')
		try {
			const response = await axios.get(cacheKey, {
				responseType: 'arraybuffer',
				...(hasVersionParam ? {} : {
					headers: {
						'Cache-Control': 'no-cache',
						'Pragma': 'no-cache',
					},
				}),
			})

			if (loadId !== currentLoadId) return null

			const raw = response.data instanceof ArrayBuffer ? new Uint8Array(response.data) : response.data
			data = raw
		} catch (err) {
			const offlineData = await readOfflineCachedBinary(cacheKey)
			if (!offlineData) {
				throw err
			}
			if (loadId !== currentLoadId) return null
			data = offlineData
		}
		if (data) {
			cache.set(cacheKey, data)
		}
	}
	return data
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
	activeXlsxSheetIndex.value = 0

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
			activeXlsxSheetIndex.value = 0
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

		if (pdfContainer.value) {
			lastObservedWidth = pdfContainer.value.clientWidth
			lastObservedHeight = pdfContainer.value.clientHeight
		}

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

let resizeObserver: ResizeObserver | null = null
let resizeDebounceTimer: ReturnType<typeof setTimeout> | null = null
let lastObservedWidth = 0
let lastObservedHeight = 0

function handleResize() {
	if (!(autoLayout.value || zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page')) {
		return
	}
	if (resizeDebounceTimer !== null) {
		clearTimeout(resizeDebounceTimer)
	}
	resizeDebounceTimer = setTimeout(() => {
		resizeDebounceTimer = null
		const el = pdfContainer.value
		if (el) {
			const w = el.clientWidth
			const h = el.clientHeight
			if (w === lastObservedWidth && h === lastObservedHeight) {
				return
			}
			lastObservedWidth = w
			lastObservedHeight = h
		}
		renderCurrentView()
	}, 150)
}

onMounted(() => {
	window.addEventListener('resize', handleResize)
	if (typeof ResizeObserver !== 'undefined' && pdfContainer.value) {
		lastObservedWidth = pdfContainer.value.clientWidth
		lastObservedHeight = pdfContainer.value.clientHeight
		resizeObserver = new ResizeObserver(() => {
			handleResize()
		})
		resizeObserver.observe(pdfContainer.value)
	}
})

onUnmounted(() => {
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
	window.removeEventListener('resize', handleResize)
	if (resizeDebounceTimer !== null) {
		clearTimeout(resizeDebounceTimer)
		resizeDebounceTimer = null
	}
	if (resizeObserver) {
		resizeObserver.disconnect()
		resizeObserver = null
	}
})

watch(() => props.filePath, loadAndRenderFile, { immediate: true })

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

const { wasRecentlyPinching, resetPan } = usePdfTouchZoom({
	containerRef: pdfContainer,
	stageRef: pdfStage,
	getCurrentScale: () => effectiveScale.value,
	isEnabled: () => isPdfFile.value && !isLoading.value && !errorMessage.value,
	isAnnotating: () => props.editableAnnotations,
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
			:class="[$style.pdfContainer, !editableAnnotations && $style.pdfContainerGrabbable]">
			<div
				ref="pdfStage"
				:class="[$style.pdfStage, $style[`layout-${pageLayout}`], noPadding && $style.noPadding]" />
		</div>

		<div
			v-show="!isLoading && !errorMessage && isDocxFile"
			ref="docxContainer"
			:class="$style.docxContainer" />

		<div
			v-if="!isLoading && !errorMessage && isXlsxFile"
			:class="$style.xlsxContainer">
			<div v-if="xlsxSheets.length > 1" :class="$style.xlsxTabs" role="tablist">
				<button
					v-for="(sheet, idx) in xlsxSheets"
					:key="sheet.name"
					type="button"
					role="tab"
					:aria-selected="activeXlsxSheetIndex === idx"
					:class="[$style.xlsxTab, activeXlsxSheetIndex === idx && $style.xlsxTabActive]"
					@click="activeXlsxSheetIndex = idx">
					{{ sheet.name }}
				</button>
			</div>
			<!-- eslint-disable-next-line vue/no-v-html -->
			<div
				v-if="xlsxSheets[activeXlsxSheetIndex]"
				:class="$style.xlsxSheetContent"
				v-html="xlsxSheets[activeXlsxSheetIndex].html" />
		</div>

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
	display: flex;
	flex-direction: column;
	width: 100%;
	height: 100%;
	flex: 1;
	min-height: 0;
}

.loading {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 48px;
}

.error {
	padding: 16px;
	color: var(--color-error);
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

.xlsxContainer {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-height: 0;
	overflow: hidden;
	background-color: var(--color-main-background);
}

.xlsxTabs {
	display: flex;
	gap: 4px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
	overflow-x: auto;
	flex-shrink: 0;
}

.xlsxTab {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 4px 12px;
	font-size: 0.85rem;
	cursor: pointer;
	white-space: nowrap;
}

.xlsxTabActive {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
	font-weight: 600;
}

.xlsxSheetContent {
	flex: 1;
	min-height: 0;
	overflow: auto;
	padding: 16px;
}

.xlsxSheetContent :global(table) {
	border-collapse: collapse;
	width: 100%;
	font-size: 0.9rem;
}

.xlsxSheetContent :global(td),
.xlsxSheetContent :global(th) {
	border: 1px solid var(--color-border);
	padding: 6px 10px;
	text-align: left;
	vertical-align: top;
}

.xlsxSheetContent :global(tr:nth-child(even)) {
	background-color: var(--color-background-hover);
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
