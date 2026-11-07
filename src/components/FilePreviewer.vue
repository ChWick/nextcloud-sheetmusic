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

const props = withDefaults(
	defineProps<{
		filePath: string
		showExternalButton?: boolean
		hideToolbar?: boolean
		targetPage?: number
		pageOrder?: string | null
		annotations?: SheetAnnotation[]
		showAnnotations?: boolean
	}>(),
	{
		showExternalButton: true,
		hideToolbar: false,
		targetPage: undefined,
		pageOrder: undefined,
		annotations: () => [],
		showAnnotations: true,
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

const pageLayout = ref<PageLayoutMode>('continuous')
const zoomMode = ref<ZoomMode>('fit-width')
const customScale = ref(1.0)
const effectiveScale = ref(1.0)
const rotation = ref(0)
const imageScale = ref(1.0)
const showAnnotationsState = ref(props.showAnnotations ?? true)

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
		showAnnotationsState.value = val ?? true
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
		if ((ann.type === 'drawing' || ann.type === 'highlight' || (ann.type === 'shape' && ann.data?.path)) && ann.data?.path) {
			const path = document.createElementNS('http://www.w3.org/2000/svg', 'path')
			path.setAttribute('d', ann.data.path)
			path.setAttribute('stroke', ann.style?.stroke || '#ff0000')
			path.setAttribute('stroke-width', String(ann.style?.strokeWidth || 2.0))
			path.setAttribute('opacity', String(ann.style?.opacity ?? 1.0))
			path.setAttribute('fill', 'none')
			path.setAttribute('stroke-linecap', 'round')
			path.setAttribute('stroke-linejoin', 'round')
			if (ann.type === 'highlight') {
				path.style.mixBlendMode = 'multiply'
			}
			svg.appendChild(path)
		} else if (ann.type === 'shape') {
			const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect')
			rect.setAttribute('x', String(ann.x * 1000))
			rect.setAttribute('y', String(ann.y * 1000))
			rect.setAttribute('width', String(ann.width * 1000))
			rect.setAttribute('height', String(ann.height * 1000))
			rect.setAttribute('stroke', ann.style?.stroke || '#ff0000')
			rect.setAttribute('stroke-width', String(ann.style?.strokeWidth || 2.0))
			rect.setAttribute('fill', ann.style?.fill || 'transparent')
			svg.appendChild(rect)
		} else if (ann.type === 'stamp') {
			const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
			text.setAttribute('x', String(ann.x * 1000))
			const fontSize = ann.style?.fontSize || 40
			text.setAttribute('y', String((ann.y * 1000) + (fontSize * 0.8)))
			text.setAttribute('fill', ann.style?.color || '#000000')
			text.setAttribute('font-size', String(fontSize))
			text.setAttribute('font-family', 'sans-serif, music, Bravura, "Noto Music"')
			text.setAttribute('data-test', 'annotation-stamp')
			text.textContent = ann.data?.displaySymbol || ann.data?.symbol || ''
			svg.appendChild(text)
		} else if (ann.type === 'text' && ann.data?.text) {
			const text = document.createElementNS('http://www.w3.org/2000/svg', 'text')
			text.setAttribute('x', String(ann.x * 1000))
			text.setAttribute('y', String((ann.y * 1000) + (ann.data.fontSize || 16)))
			text.setAttribute('fill', ann.style?.color || '#000000')
			text.setAttribute('font-size', String(ann.data.fontSize || 16))
			text.setAttribute('font-family', ann.data.fontFamily || 'sans-serif')
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

function openInExternalViewer() {
	if (!props.filePath) return
	openFileExternally(props.filePath)
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

function goToPage(targetPage: number) {
	if (targetPage < 1) targetPage = 1
	if (totalPages.value > 0 && targetPage > totalPages.value) {
		targetPage = totalPages.value
	}
	currentPage.value = targetPage
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
		const idx = Math.min(Math.max(1, currentPage.value), total) - 1
		pagesToRender = [{ pageNum: ordered[idx] || 1, index: idx + 1 }]
		if (idx + 1 < total) {
			pagesToRender.push({ pageNum: ordered[idx + 1], index: idx + 2 })
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
		const response = await axios.get(props.filePath, {
			responseType: 'arraybuffer',
			headers: {
				'Cache-Control': 'no-cache',
				'Pragma': 'no-cache',
			},
		})

		if (loadId !== currentLoadId) return

		const pdfData = response.data instanceof ArrayBuffer ? new Uint8Array(response.data) : response.data
		fileSizeBytes.value = typeof pdfData?.byteLength === 'number' ? pdfData.byteLength : null
		const wasmUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/wasm/') : new URL('pdfjs-dist/wasm/', import.meta.url).href
		const cMapUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/cmaps/') : new URL('pdfjs-dist/cmaps/', import.meta.url).href
		const standardFontDataUrl = typeof generateFilePath === 'function' ? generateFilePath('sheetmusic', 'js', 'pdfjs/standard_fonts/') : new URL('pdfjs-dist/standard_fonts/', import.meta.url).href

		const pdf = await getDocument({
			data: pdfData,
			cMapUrl,
			cMapPacked: true,
			standardFontDataUrl,
			wasmUrl,
		}).promise

		if (loadId !== currentLoadId) return

		loadedPdf.value = pdf
		const ordered = parsePageOrder(props.pageOrder, pdf.numPages)
		totalPages.value = ordered.length
		currentPage.value = props.targetPage && props.targetPage >= 1 ? Math.min(props.targetPage, totalPages.value) : 1

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

		// If the document is large (>30 pages), default to single page layout for optimal performance
		if (pdf.numPages > 30) {
			pageLayout.value = 'single'
		}

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

function handleResize() {
	if (zoomMode.value === 'fit-width' || zoomMode.value === 'fit-page') {
		renderCurrentView()
	}
}

onMounted(() => {
	window.addEventListener('resize', handleResize)
})

onUnmounted(() => {
	cleanupObserver()
	window.removeEventListener('resize', handleResize)
})

watch(() => props.filePath, loadAndRenderFile, { immediate: true })

watch(
	() => props.pageOrder,
	() => {
		if (loadedPdf.value) {
			const ordered = parsePageOrder(props.pageOrder, loadedPdf.value.numPages)
			totalPages.value = ordered.length
			currentPage.value = 1
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
	currentPage,
	totalPages,
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
			:zoom-select-value="zoomSelectValue"
			:is-custom-zoom="isCustomZoom"
			:display-scale="isImageFile ? imageScale : effectiveScale"
			:show-external-button="showExternalButton"
			:show-annotations="showAnnotationsState"
			@previous-page="prevPage"
			@next-page="nextPage"
			@page-layout="setPageLayout"
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
	flex-wrap: wrap;
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
