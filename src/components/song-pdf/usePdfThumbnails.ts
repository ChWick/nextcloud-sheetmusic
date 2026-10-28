import { getCurrentInstance, onUnmounted, ref, type Ref } from 'vue'
import type { ScanOperation } from './scan-types'
import {
	applyPipeline,
	downsampleImageBuffer,
	imageBufferToImageData,
	imageDataToImageBuffer,
} from './scan-enhance-engine'

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

		// Render at a high intermediate resolution (4x display width = 560px) so that
		// pdf.js does not perform a single-pass 15x nearest-neighbour-like downscale
		// and scan enhancement filters operate at proper resolution before area-average downsampling.
		const displayWidth = 140
		const thumbWidth = displayWidth * 2 // 2x HiDPI backing store (280px)
		const supersampleWidth = displayWidth * 4 // 560px intermediate render
		const renderScale = supersampleWidth / Math.max(1, unscaledViewport.width)
		const viewport = page.getViewport({ scale: renderScale })

		const vpScaled = Math.abs(viewport.width - unscaledViewport.width * renderScale) < 2
		const renderW = vpScaled
			? Math.max(1, Math.round(viewport.width))
			: Math.max(1, Math.round(unscaledViewport.width * renderScale))
		const renderH = vpScaled
			? Math.max(1, Math.round(viewport.height))
			: Math.max(1, Math.round(unscaledViewport.height * renderScale))

		const thumbW = thumbWidth
		const thumbH = Math.max(1, Math.round(renderH * (thumbW / renderW)))

		const srcCanvas = document.createElement('canvas')
		srcCanvas.width = renderW
		srcCanvas.height = renderH

		const canvas = document.createElement('canvas')
		canvas.width = thumbW
		canvas.height = thumbH
		canvas.className = 'sheetmusic-pdf-thumbnail-canvas'

		const srcContext = srcCanvas.getContext('2d')
		const dstContext = canvas.getContext('2d')
		if (srcContext && dstContext) {
			srcContext.imageSmoothingEnabled = true
			srcContext.imageSmoothingQuality = 'high'
			srcContext.fillStyle = '#ffffff'
			srcContext.fillRect(0, 0, srcCanvas.width, srcCanvas.height)
			await page.render({
				canvasContext: srcContext,
				viewport,
				background: 'rgb(255, 255, 255)',
			}).promise

			const imgData = srcContext.getImageData(0, 0, srcCanvas.width, srcCanvas.height)
			let buffer = imageDataToImageBuffer(imgData)

			if (isPageEnhanced(pageNum)) {
				buffer = applyPipeline(buffer, pageEnhancements.value[pageNum])
			}

			// Area-averaging (box-integration) downsampling from 560px -> 280px
			const downsampled = downsampleImageBuffer(buffer, thumbW, thumbH)
			dstContext.putImageData(imageBufferToImageData(downsampled), 0, 0)
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
