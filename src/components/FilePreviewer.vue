<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, shallowRef, watch } from 'vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateFilePath } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { openFileExternally } from '../utils/file'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import FilePreviewerToolbar from './file-preview/FilePreviewerToolbar.vue'
import { inspectPdfPageResolution, type PdfPageResolutionSummary } from './song-pdf/pdf-enhance-engine'
import { parsePageOrder } from '../utils/page-order'
import type { SheetAnnotation } from '../types/annotation'
import {
	getAnnotationDashArray,
	getAnnotationDisplaySymbol,
	getAnnotationFontFamily,
	getAnnotationPath,
	getAnnotationTextPosition,
	getStampFontFamily,
	getStampVector,
	isStampItalic,
	isStampBold,
} from '../utils/annotation'
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
		showExternalButton?: boolean
		hideToolbar?: boolean
		targetPage?: number
		pageOrder?: string | null
		annotations?: SheetAnnotation[]
		showAnnotations?: boolean
		binaryCache?: FileBinaryCache
		initialPageLayout?: 'continuous' | 'single' | 'double' | 'auto'
		initialDoublePageTurnMode?: DoublePageTurnMode
		initialZoomMode?: 'fit-width' | 'fit-page' | 'custom'
		startAtEnd?: boolean
	}>(),
	{
		showExternalButton: true,
		hideToolbar: false,
		targetPage: undefined,
		pageOrder: undefined,
		annotations: () => [],
		showAnnotations: false,
		binaryCache: () => defaultFileBinaryCache,
		initialPageLayout: 'continuous',
		initialDoublePageTurnMode: 'half',
		initialZoomMode: 'fit-width',
		startAtEnd: false,
	},
)

GlobalWorkerOptions.workerSrc = pdfWorker

const pdfContainer = ref<HTMLElement>()
const isLoading = ref(false)
const errorMessage = ref('')
let currentLoadId = 0
let currentRenderId = 0

const loadedPdf = shallowRef<any>(null)
const totalPages = ref(1)
const currentPage = ref(1)
const fileSizeBytes = ref<number | null>(null)
const pageResolutions = ref<PdfPageResolutionSummary[]>([])

type PageLayoutMode = 'continuous' | 'single' | 'double'
type ZoomMode = 'fit-width' | 'fit-page' | 'custom'

function detectResponsiveLayout(): PageLayoutMode {
	if (typeof window === 'undefined') return 'single'
	const containerW = pdfContainer.value?.clientWidth || window.innerWidth
	const containerH = pdfContainer.value?.clientHeight || window.innerHeight
	return containerW >= containerH && containerW >= 700 ? 'double' : 'single'
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
const showAnnotationsState = ref(props.showAnnotations ?? false)

function toggleAnnotations() {
	showAnnotationsState.value = !showAnnotationsState.value
	updateAnnotationOverlaysVisibility()
}

function updateAnnotationOverlaysVisibility() {
	if (!pdfContainer.value) return
	const overlays = pdfContainer.value.querySelectorAll<SVGElement>('.sheetmusic-annotation-overlay')
	overlays.forEach((el) => {
		el.style.display = showAnnotationsState.value ? 'block' : 'none'
	})
}

function refreshAnnotationOverlays() {
	if (!pdfContainer.value) return
	const placeholders = pdfContainer.value.querySelectorAll<HTMLElement>('[data-page-num]')
	placeholders.forEach((el) => {
		const existing = el.querySelectorAll('.sheetmusic-annotation-overlay')
		existing.forEach((node) => node.remove())

		const pageNum = Number(el.dataset.pageNum)
		if (pageNum && el.querySelector('canvas')) {
			const overlay = createAnnotationSvg(pageNum)
			if (overlay) {
				el.appendChild(overlay)
			}
		}
	})
}

watch(
	() => props.annotations,
	() => {
		refreshAnnotationOverlays()
	},
	{ deep: true },
)

watch(
	() => props.showAnnotations,
	(val) => {
		showAnnotationsState.value = val ?? false
		updateAnnotationOverlaysVisibility()
	},
)

function createAnnotationSvg(pageNum: number): SVGElement | null {
	const pageAnns = (props.annotations || []).filter(
		(a) => !a.deletedAt && (a.pageNumber === pageNum || a.pageNumber === pageNum - 1),
	)
	if (pageAnns.length === 0) return null

	const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
	svg.setAttribute('viewBox', '0 0 1000 1000')
	svg.setAttribute('preserveAspectRatio', 'none')
	svg.setAttribute('class', 'sheetmusic-annotation-overlay')
	svg.setAttribute('data-test', 'annotation-overlay')
	svg.style.position = 'absolute'
	svg.style.top = '0'
	svg.style.left = '0'
	svg.style.width = '100%'
	svg.style.height = '100%'
	svg.style.pointerEvents = 'none'
	svg.style.display = showAnnotationsState.value ? 'block' : 'none'

	for (const ann of pageAnns) {
		const dashArray = getAnnotationDashArray(ann)
		const isLineShape = ann.type === 'shape' && (
			Boolean(ann.data?.path) ||
			(Array.isArray(ann.data?.points) && ann.data.points.length > 0) ||
			ann.data?.drawMode === 0 ||
			ann.data?.drawMode === 1 ||
			ann.data?.drawMode === 3 ||
			ann.data?.drawMode === 4 ||
			ann.data?.drawMode === 5 ||
			ann.data?.isHairpin ||
			ann.data?.isArrow ||
			ann.data?.shapeType === 'arrow' ||
			ann.data?.shapeType === 'line'
		)

		if ((ann.type === 'drawing' || ann.type === 'highlight' || isLineShape)) {
			const path = document.createElementNS('http://www.w3.org/2000/svg', 'path')
			path.setAttribute('d', getAnnotationPath(ann))
			path.setAttribute('stroke', ann.style?.stroke || '#ff0000')
			path.setAttribute('stroke-width', String(ann.style?.strokeWidth || 2.0))
			if (dashArray) {
				path.setAttribute('stroke-dasharray', dashArray)
			}
			path.setAttribute('opacity', String(ann.style?.opacity ?? 1.0))
			path.setAttribute('fill', 'none')
			path.setAttribute('stroke-linecap', 'round')
			path.setAttribute('stroke-linejoin', 'round')
			if (ann.type === 'highlight') {
				path.style.mixBlendMode = 'multiply'
			}
			path.setAttribute('data-test', ann.type === 'shape' ? 'annotation-shape' : 'annotation-drawing')
			svg.appendChild(path)
		} else if (ann.type === 'shape') {
			const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
			rect.setAttribute('x', String(ann.x * 1000))
			rect.setAttribute('y', String(ann.y * 1000))
			rect.setAttribute('width', String(ann.width * 1000))
			rect.setAttribute('height', String(ann.height * 1000))
			rect.setAttribute('stroke', ann.style?.stroke || '#ff0000')
			rect.setAttribute('stroke-width', String(ann.style?.strokeWidth || 2.0))
			if (dashArray) {
				rect.setAttribute('stroke-dasharray', dashArray)
			}
			rect.setAttribute('fill', ann.style?.fill || 'transparent')
			rect.setAttribute('data-test', 'annotation-shape')
			svg.appendChild(rect)
		} else if (ann.type === 'stamp') {
			const vector = getStampVector(ann)
			if (vector) {
				const svgStamp = document.createElementNS('http://www.w3.org/2000/svg', 'svg')
				const w = Math.round((ann.width || 0.04) * 10000) / 10
				const h = Math.round((ann.height || 0.04) * 10000) / 10
				const x = Math.round(ann.x * 10000) / 10
				const y = Math.round(ann.y * 10000) / 10
				svgStamp.setAttribute('x', String(x))
				svgStamp.setAttribute('y', String(y))
				svgStamp.setAttribute('width', String(w))
				svgStamp.setAttribute('height', String(h))
				svgStamp.setAttribute('viewBox', vector.viewBox)
				svgStamp.setAttribute('preserveAspectRatio', 'xMidYMid meet')
				svgStamp.setAttribute('data-test', 'annotation-stamp')

				const path = document.createElementNS('http://www.w3.org/2000/svg', 'path')
				path.setAttribute('d', vector.d)
				path.setAttribute('fill', ann.style?.color || '#000000')
				svgStamp.appendChild(path)
				svg.appendChild(svgStamp)
			} else {
				const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
				const fontSize = ann.style?.fontSize || 40
				const cx = Math.round((ann.x + (ann.width || 0.04) / 2) * 10000) / 10
				const cy = Math.round((ann.y + (ann.height || 0.04) / 2) * 10000) / 10
				text.setAttribute('x', String(cx))
				text.setAttribute('y', String(cy))
				text.setAttribute('text-anchor', 'middle')
				text.setAttribute('dominant-baseline', 'central')
				text.setAttribute('fill', ann.style?.color || '#000000')
				text.setAttribute('font-size', String(fontSize))
				if (isStampItalic(ann)) {
					text.setAttribute('font-style', 'italic')
				}
				if (isStampBold(ann)) {
					text.setAttribute('font-weight', 'bold')
				}
				text.setAttribute('font-family', getStampFontFamily(ann))
				text.setAttribute('data-test', 'annotation-stamp')
				text.textContent = getAnnotationDisplaySymbol(ann)
				svg.appendChild(text)
			}
		} else if (ann.type === 'text' && ann.data?.text) {
			const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
			const fontSize = ann.style?.fontSize || ann.data?.scaledFontSize || (ann.data?.fontSize ? (ann.data.fontSize < 100 ? Math.round(ann.data.fontSize / (ann.data?.sourceWidth || 596) * 1000) : ann.data.fontSize) : 24)
			const fontFamily = getAnnotationFontFamily(ann.style?.fontFamily || ann.data?.fontFamily)
			const textPos = getAnnotationTextPosition(ann)
			text.setAttribute('x', String(textPos.x))
			text.setAttribute('y', String(textPos.y))
			text.setAttribute('fill', ann.style?.color || '#000000')
			text.setAttribute('font-size', String(fontSize))
			text.setAttribute('font-family', fontFamily)
			text.setAttribute('dominant-baseline', textPos.dominantBaseline)
			if (textPos.textAnchor) {
				text.setAttribute('text-anchor', textPos.textAnchor)
			}
			text.setAttribute('data-test', 'annotation-text')
			text.textContent = ann.data.text
			svg.appendChild(text)
		} else if (ann.type === 'link') {
			const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
			rect.setAttribute('x', String(ann.x * 1000))
			rect.setAttribute('y', String(ann.y * 1000))
			rect.setAttribute('width', String(ann.width * 1000))
			rect.setAttribute('height', String(ann.height * 1000))
			rect.style.pointerEvents = 'auto'
			rect.style.cursor = 'pointer'
			rect.style.fill = 'rgba(0, 120, 255, 0.2)'
			rect.style.stroke = 'rgba(0, 120, 255, 0.6)'
			rect.style.strokeWidth = '1.5'
			rect.onclick = () => {
				if (typeof ann.data?.targetPage === 'number') {
					goToPage(ann.data.targetPage + 1)
				}
			}
			svg.appendChild(rect)
		}
	}

	return svg
}

const zoomSteps = [0.25, 0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0, 2.5, 3.0, 4.0]

const isPdfFile = computed(() => {
	const path = props.filePath.split('?')[0].toLowerCase()
	return path.endsWith('.pdf')
})

const isImageFile = computed(() => {
	const path = props.filePath.split('?')[0].toLowerCase()
	return path.endsWith('.png') || path.endsWith('.jpg') || path.endsWith('.jpeg') || path.endsWith('.webp') || path.endsWith('.gif') || path.endsWith('.svg') || path.endsWith('.bmp')
})

const isUnsupportedFile = computed(() => {
	return !isPdfFile.value && !isImageFile.value
})

const currentPageDisplay = computed(() => {
	if (pageLayout.value === 'double') {
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
		return prevDoubleSpread(doubleSpread.value, totalPages.value, doublePageTurnMode.value) !== null
	}
	return currentPage.value > 1
})

const canGoNext = computed(() => {
	if (pageLayout.value === 'continuous') return false
	if (pageLayout.value === 'double') {
		return nextDoubleSpread(doubleSpread.value, totalPages.value, doublePageTurnMode.value) !== null
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
		const prev = prevDoubleSpread(doubleSpread.value, totalPages.value, doublePageTurnMode.value)
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
		const next = nextDoubleSpread(doubleSpread.value, totalPages.value, doublePageTurnMode.value)
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

function setPageLayout(mode: PageLayoutMode) {
	autoLayout.value = false
	if (pageLayout.value === mode) return
	pageLayout.value = mode
	if (mode === 'double') {
		doubleSpread.value = getDoubleSpreadForPage(currentPage.value, totalPages.value, doublePageTurnMode.value)
	}
	renderCurrentView()
}

function setAutoLayout(enabled: boolean) {
	autoLayout.value = enabled
	if (enabled) {
		const detected = detectResponsiveLayout()
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

async function renderCurrentView() {
	if (!loadedPdf.value || !pdfContainer.value) return

	const renderId = ++currentRenderId
	const pdf = loadedPdf.value
	const ordered = parsePageOrder(props.pageOrder, pdf.numPages)
	const total = ordered.length

	await nextTick()
	if (!pdfContainer.value || renderId !== currentRenderId) return

	cleanupObserver()
	pdfContainer.value.replaceChildren()

	let pagesToRender: Array<{ pageNum: number; index: number }> = []
	if (pageLayout.value === 'single') {
		const idx = Math.min(Math.max(1, currentPage.value), total) - 1
		pagesToRender = [{ pageNum: ordered[idx] || 1, index: idx + 1 }]
	} else if (pageLayout.value === 'double') {
		const [leftLogical, rightLogical] = doubleSpread.value
		const leftIdx = Math.min(Math.max(1, leftLogical), total) - 1
		pagesToRender = [{ pageNum: ordered[leftIdx] || 1, index: leftIdx + 1 }]
		if (rightLogical !== null && rightLogical >= 1 && rightLogical <= total) {
			const rightIdx = rightLogical - 1
			pagesToRender.push({ pageNum: ordered[rightIdx] || 1, index: rightIdx + 1 })
		}
	} else {
		pagesToRender = ordered.map((p, idx) => ({ pageNum: p, index: idx + 1 }))
	}

	// Calculate base scale using first page
	let scale = 1.25
	try {
		const firstPage = await pdf.getPage(pagesToRender[0]?.pageNum || 1)
		const pageRotation = (((firstPage.rotate ?? 0) + rotation.value) % 360 + 360) % 360
		const unscaledViewport = firstPage.getViewport({ scale: 1.0, rotation: pageRotation })

		const containerW = (pdfContainer.value?.clientWidth && pdfContainer.value.clientWidth > 100)
			? pdfContainer.value.clientWidth - 48
			: 800
		const containerH = (pdfContainer.value?.clientHeight && pdfContainer.value.clientHeight > 100)
			? pdfContainer.value.clientHeight - 48
			: 700
		const targetW = pageLayout.value === 'double' && pagesToRender.length > 1
			? (containerW - 24) / 2
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
	}
	scale = Math.max(0.2, Math.min(scale, 4.0))
	effectiveScale.value = scale

	const renderedPlaceholders = new Set<HTMLElement>()

	// Helper to render a specific page into its placeholder
	const renderPage = async (pageNumber: number, placeholder: HTMLElement) => {
		if (renderId !== currentRenderId || renderedPlaceholders.has(placeholder)) return
		renderedPlaceholders.add(placeholder)

		try {
			const page = await pdf.getPage(pageNumber)
			if (renderId !== currentRenderId) return

			const pageRotation = (((page.rotate ?? 0) + rotation.value) % 360 + 360) % 360
			const viewport = page.getViewport({ scale: effectiveScale.value, rotation: pageRotation })

			const canvas = document.createElement('canvas')
			canvas.width = Math.floor(viewport.width)
			canvas.height = Math.floor(viewport.height)
			canvas.className = 'sheetmusic-pdf-page'
			canvas.style.display = 'block'
			canvas.style.boxShadow = '0 2px 8px rgba(0, 0, 0, 0.2)'

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

			if (renderId === currentRenderId) {
				placeholder.replaceChildren(canvas)
				placeholder.style.position = 'relative'
				placeholder.style.minHeight = ''
				placeholder.style.minWidth = ''
				const overlay = createAnnotationSvg(pageNumber)
				if (overlay) {
					placeholder.appendChild(overlay)
				}
			}
		} catch (err) {
			console.warn(`Could not render page ${pageNumber}:`, err)
		}
	}

	if (pageLayout.value === 'continuous') {
		if (typeof IntersectionObserver === 'undefined') {
			for (const item of pagesToRender) {
				const placeholder = document.createElement('div')
				placeholder.className = 'sheetmusic-page-placeholder'
				placeholder.dataset.pageNum = String(item.pageNum)
				placeholder.dataset.pageIndex = String(item.index)
				pdfContainer.value.append(placeholder)
				await renderPage(item.pageNum, placeholder)
			}
			return
		}

		intersectionObserver = new IntersectionObserver(
			(entries) => {
				for (const entry of entries) {
					if (entry.isIntersecting) {
						const targetEl = entry.target as HTMLElement
						const pageNum = Number(targetEl.dataset.pageNum)
						if (pageNum && !renderedPlaceholders.has(targetEl)) {
							renderPage(pageNum, targetEl)
						}
					}
				}
			},
			{
				root: pdfContainer.value,
				rootMargin: '600px 0px 600px 0px', // Preload pages within 600px of scroll view
			},
		)

		for (const item of pagesToRender) {
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

			pdfContainer.value.append(placeholder)
			intersectionObserver.observe(placeholder)

			// Render the first page immediately so the user always sees content right away
			if (item === pagesToRender[0]) {
				renderPage(item.pageNum, placeholder)
			}
		}
	} else {
		// Single or double page mode: render immediately
		for (const item of pagesToRender) {
			const placeholder = document.createElement('div')
			placeholder.dataset.pageNum = String(item.pageNum)
			placeholder.dataset.pageIndex = String(item.index)
			pdfContainer.value.append(placeholder)
			await renderPage(item.pageNum, placeholder)
		}
	}
}

async function loadAndRenderFile() {
	const loadId = ++currentLoadId
	errorMessage.value = ''
	loadedPdf.value = null
	totalPages.value = 1
	currentPage.value = 1
	rotation.value = 0
	fileSizeBytes.value = null
	pageResolutions.value = []

	await nextTick()
	cleanupObserver()
	if (pdfContainer.value) {
		pdfContainer.value.replaceChildren()
	}

	if (!props.filePath) {
		isLoading.value = false
		errorMessage.value = t('sheetmusic', 'Missing file: The file does not exist on the file system.')
		return
	}

	if (!isPdfFile.value) {
		isLoading.value = false
		return
	}

	isLoading.value = true

	try {
		const cache = props.binaryCache ?? defaultFileBinaryCache
		const cacheKey = props.filePath
		let pdfData: Uint8Array | null = cache.get(cacheKey)

		if (!pdfData) {
			const hasVersionParam = props.filePath.includes('v=') || props.filePath.includes('t=')
			const response = await axios.get(props.filePath, {
				responseType: 'arraybuffer',
				...(hasVersionParam ? {} : {
					headers: {
						'Cache-Control': 'no-cache',
						'Pragma': 'no-cache',
					},
				}),
			})

			if (loadId !== currentLoadId) return

			const raw = response.data instanceof ArrayBuffer ? new Uint8Array(response.data) : response.data
			pdfData = raw
			if (pdfData) {
				cache.set(cacheKey, pdfData)
			}
		}

		if (loadId !== currentLoadId) return

		fileSizeBytes.value = typeof pdfData?.byteLength === 'number' ? pdfData.byteLength : null
		const wasmUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/wasm/') : new URL('pdfjs-dist/wasm/', import.meta.url).href
		const cMapUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/cmaps/') : new URL('pdfjs-dist/cmaps/', import.meta.url).href
		const standardFontDataUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/standard_fonts/') : new URL('pdfjs-dist/standard_fonts/', import.meta.url).href

		const pdf = await getDocument({
			data: pdfData ? pdfData.slice() : pdfData,
			cMapUrl,
			cMapPacked: true,
			standardFontDataUrl,
			wasmUrl,
		}).promise

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
			const maxInspect = Math.min(pdf.numPages, 25)
			const summaries: PdfPageResolutionSummary[] = []
			for (let p = 1; p <= maxInspect; p++) {
				const pg = await pdf.getPage(p)
				summaries.push(await inspectPdfPageResolution(pg, p))
			}
			if (loadId === currentLoadId) {
				pageResolutions.value = summaries
			}
		} catch {
			// Ignore resolution inspection errors on mock or malformed PDFs
		}

		// If the document is large (>30 pages) and caller didn't specify a paged/auto layout, default to single page
		if (pdf.numPages > 30 && props.initialPageLayout === 'continuous') {
			pageLayout.value = 'single'
		} else if (autoLayout.value) {
			pageLayout.value = detectResponsiveLayout()
		}

		doubleSpread.value = totalPages.value > 1
			? getDoubleSpreadForPage(currentPage.value, totalPages.value, doublePageTurnMode.value)
			: getInitialDoubleSpread(totalPages.value)

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

function handleResize() {
	let layoutChanged = false
	if (autoLayout.value) {
		const detected = detectResponsiveLayout()
		if (pageLayout.value !== detected) {
			pageLayout.value = detected
			if (detected === 'double') {
				doubleSpread.value = getDoubleSpreadForPage(currentPage.value, totalPages.value, doublePageTurnMode.value)
			}
			layoutChanged = true
		}
	}
	if (layoutChanged || zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page') {
		renderCurrentView()
	}
}

onMounted(() => {
	window.addEventListener('resize', handleResize)
	if (typeof ResizeObserver !== 'undefined' && pdfContainer.value) {
		resizeObserver = new ResizeObserver(() => {
			handleResize()
		})
		resizeObserver.observe(pdfContainer.value)
	}
})

onUnmounted(() => {
	cleanupObserver()
	window.removeEventListener('resize', handleResize)
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

defineExpose({
	openInExternalViewer,
	goToPage,
	prevPage,
	nextPage,
	setPageLayout,
	setAutoLayout,
	setDoublePageTurnMode,
	setZoomMode,
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
	fileSizeBytes,
	pageResolutions,
	showAnnotationsState,
	toggleAnnotations,
})
</script>

<template>
	<div :class="$style.wrapper">
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
			:class="[$style.pdfContainer, $style[`layout-${pageLayout}`]]" />

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
}

.previewImage {
	max-width: 100%;
	max-height: 100%;
	object-fit: contain;
}

.pdfContainer {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 16px;
	padding: 24px;
	overflow: auto;
	flex: 1;
	background-color: var(--color-background-dark, #f5f5f5);
}

.pdfContainer.layout-double {
	flex-direction: row;
	flex-wrap: nowrap;
	align-items: center;
	justify-content: center;
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
