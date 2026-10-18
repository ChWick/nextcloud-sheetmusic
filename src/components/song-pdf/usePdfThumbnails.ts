import { getCurrentInstance, onUnmounted, ref, type Ref } from 'vue'
import type { ScanOperation } from './scan-types'
import { applyPipeline, imageDataToImageBuffer, imageBufferToImageData } from './scan-enhance-engine'

export function usePdfThumbnails(
	activePdfDoc: Ref<any>,
	pageEnhancements: Ref<Record<number, ScanOperation[]>>,
) {
	const thumbnailCanvases = ref<Record<number, HTMLCanvasElement>>({})
	const thumbnailContainers = ref<Record<number, HTMLElement | null>>({})
	let thumbnailObserver: IntersectionObserver | null = null
	const renderedThumbnails = new Set<number>()

	function cleanupThumbnailObserver() {
		if (thumbnailObserver) {
			thumbnailObserver.disconnect()
			thumbnailObserver = null
		}
		renderedThumbnails.clear()
	}

	function isPageEnhanced(pageNum: number): boolean {
		const ops = pageEnhancements.value[pageNum]
		return Array.isArray(ops) && ops.some((op) => op.enabled)
	}

	async function renderCanvasForPage(pdf: any, pageNum: number): Promise<HTMLCanvasElement | null> {
		const page = await pdf.getPage(pageNum)
		const unscaledViewport = page.getViewport({ scale: 1.0 })
		const targetWidth = 140
		const scale = targetWidth / unscaledViewport.width
		const viewport = page.getViewport({ scale })

		const canvas = document.createElement('canvas')
		canvas.width = Math.floor(viewport.width)
		canvas.height = Math.floor(viewport.height)
		canvas.className = 'sheetmusic-pdf-thumbnail-canvas'

		const canvasContext = canvas.getContext('2d')
		if (canvasContext) {
			canvasContext.fillStyle = '#ffffff'
			canvasContext.fillRect(0, 0, canvas.width, canvas.height)
			await page.render({
				canvasContext,
				viewport,
				background: 'rgb(255, 255, 255)',
			}).promise

			if (isPageEnhanced(pageNum)) {
				const imgData = canvasContext.getImageData(0, 0, canvas.width, canvas.height)
				const buffer = imageDataToImageBuffer(imgData)
				const enhanced = applyPipeline(buffer, pageEnhancements.value[pageNum])
				const newImgData = imageBufferToImageData(enhanced)
				canvasContext.putImageData(newImgData, 0, 0)
			}
		}
		return canvas
	}

	async function renderSingleThumbnail(pdf: any, pageNum: number) {
		if (renderedThumbnails.has(pageNum)) return
		renderedThumbnails.add(pageNum)

		try {
			const canvas = await renderCanvasForPage(pdf, pageNum)
			if (!canvas) return
			thumbnailCanvases.value[pageNum] = canvas

			const container = thumbnailContainers.value[pageNum]
			if (container) {
				container.replaceChildren(canvas)
			}
		} catch (e) {
			console.warn(`Could not render thumbnail for page ${pageNum}`, e)
		}
	}

	async function updateThumbnailForPage(pageNum: number) {
		if (!activePdfDoc.value) return
		try {
			const canvas = await renderCanvasForPage(activePdfDoc.value, pageNum)
			if (!canvas) return
			thumbnailCanvases.value[pageNum] = canvas

			const container = thumbnailContainers.value[pageNum]
			if (container) {
				container.replaceChildren(canvas)
			}
		} catch (e) {
			console.warn(`Could not update thumbnail for page ${pageNum}`, e)
		}
	}

	function initThumbnailObserver(pdf: any) {
		cleanupThumbnailObserver()

		// If IntersectionObserver is not supported (e.g. test environment without IO), render all pages
		if (typeof IntersectionObserver === 'undefined') {
			for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
				renderSingleThumbnail(pdf, pageNum)
			}
			return
		}

		thumbnailObserver = new IntersectionObserver(
			(entries) => {
				for (const entry of entries) {
					if (entry.isIntersecting) {
						const pageNum = Number((entry.target as HTMLElement).dataset.pageNum)
						if (pageNum && !renderedThumbnails.has(pageNum)) {
							renderSingleThumbnail(pdf, pageNum)
						}
					}
				}
			},
			{
				rootMargin: '300px 0px 300px 0px', // preload ahead of scrolling
			},
		)

		// Observe all registered containers that are currently attached
		for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
			const container = thumbnailContainers.value[pageNum]
			if (container) {
				container.dataset.pageNum = String(pageNum)
				thumbnailObserver.observe(container)
			}
		}
	}

	function setContainerRef(pageNum: number, el: any) {
		if (el) {
			thumbnailContainers.value[pageNum] = el as HTMLElement
			el.dataset.pageNum = String(pageNum)
			const existingCanvas = thumbnailCanvases.value[pageNum]
			if (existingCanvas && (!el.hasChildNodes() || el.firstChild !== existingCanvas)) {
				el.replaceChildren(existingCanvas)
			} else if (thumbnailObserver && !renderedThumbnails.has(pageNum)) {
				thumbnailObserver.observe(el)
			}
		}
	}

	if (getCurrentInstance()) {
		onUnmounted(() => {
			cleanupThumbnailObserver()
		})
	}

	return {
		thumbnailCanvases,
		thumbnailContainers,
		renderedThumbnails,
		cleanupThumbnailObserver,
		initThumbnailObserver,
		renderSingleThumbnail,
		updateThumbnailForPage,
		setContainerRef,
		isPageEnhanced,
	}
}
