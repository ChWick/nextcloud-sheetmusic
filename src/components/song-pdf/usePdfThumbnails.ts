import { getCurrentInstance, onUnmounted, ref, type Ref } from 'vue'
import { cleanupPdfJsPage, releaseCanvas } from '../../utils/pdfjs'
import type { ScanOperation } from './scan-types'
import {
	applyPipeline,
	downsampleImageBuffer,
	imageBufferToImageData,
	imageDataToImageBuffer,
} from './scan-enhance-engine'

const MAX_CONCURRENT_RENDERS = 3
const MAX_CACHED_THUMBNAILS = 60

export function usePdfThumbnails(
	activePdfDoc: Ref<any>,
	pageEnhancements: Ref<Record<number, ScanOperation[]>>,
) {
	const thumbnailCanvases = ref<Record<number, HTMLCanvasElement>>({})
	const thumbnailContainers = ref<Record<number, HTMLElement | null>>({})
	let thumbnailObserver: IntersectionObserver | null = null
	const renderedThumbnails = new Set<number>()
	const visiblePages = new Set<number>()
	const cacheOrder = new Set<number>()
	const pendingQueue: number[] = []
	const pendingSet = new Set<number>()
	const activeRenderTasks = new Map<number, { cancel?: () => void }>()
	let activeWorkers = 0
	let observerGeneration = 0

	function touchCache(pageNum: number) {
		cacheOrder.delete(pageNum)
		cacheOrder.add(pageNum)
	}

	function evictOldThumbnails() {
		if (cacheOrder.size <= MAX_CACHED_THUMBNAILS) return
		for (const pageNum of cacheOrder) {
			if (cacheOrder.size <= MAX_CACHED_THUMBNAILS) break
			if (visiblePages.has(pageNum)) continue
			cacheOrder.delete(pageNum)
			renderedThumbnails.delete(pageNum)
			const canvas = thumbnailCanvases.value[pageNum]
			if (canvas) {
				releaseCanvas(canvas)
				delete thumbnailCanvases.value[pageNum]
			}
			const container = thumbnailContainers.value[pageNum]
			if (container) {
				container.replaceChildren()
			}
		}
	}

	function cleanupThumbnailObserver() {
		observerGeneration++
		if (thumbnailObserver) {
			thumbnailObserver.disconnect()
			thumbnailObserver = null
		}
		for (const task of activeRenderTasks.values()) {
			try {
				task.cancel?.()
			} catch {
				// Ignore cancel errors
			}
		}
		activeRenderTasks.clear()
		pendingQueue.length = 0
		pendingSet.clear()
		visiblePages.clear()
		for (const canvas of Object.values(thumbnailCanvases.value)) {
			releaseCanvas(canvas)
		}
		cacheOrder.clear()
		renderedThumbnails.clear()
	}

	function isPageEnhanced(pageNum: number): boolean {
		const ops = pageEnhancements.value[pageNum]
		return Array.isArray(ops) && ops.some((op) => op.enabled)
	}

	async function renderCanvasForPage(
		pdf: any,
		pageNum: number,
		onRenderTask?: (task: { cancel?: () => void } | null) => void,
	): Promise<HTMLCanvasElement | null> {
		const page = await pdf.getPage(pageNum)
		let srcCanvas: HTMLCanvasElement | null = null
		try {
			const unscaledViewport = page.getViewport({ scale: 1.0 })

			const maxDisplayWidth = 140
			const maxDisplayHeight = 190
			const displayScale = Math.min(
				maxDisplayWidth / Math.max(1, unscaledViewport.width),
				maxDisplayHeight / Math.max(1, unscaledViewport.height),
			)
			const displayW = Math.max(1, Math.round(unscaledViewport.width * displayScale))
			const displayH = Math.max(1, Math.round(unscaledViewport.height * displayScale))

			const thumbW = displayW * 2 // 2x HiDPI backing store
			const thumbH = displayH * 2

			const canvas = document.createElement('canvas')
			canvas.width = thumbW
			canvas.height = thumbH
			canvas.style.width = `${displayW}px`
			canvas.style.height = `${displayH}px`
			canvas.className = 'sheetmusic-pdf-thumbnail-canvas'

			const enhanced = isPageEnhanced(pageNum)

			if (!enhanced) {
				// Fast path: render directly at 2x thumbnail size without 4x supersampling or CPU readback
				const dstContext = canvas.getContext('2d')
				if (dstContext) {
					dstContext.imageSmoothingEnabled = true
					dstContext.imageSmoothingQuality = 'high'
					dstContext.fillStyle = '#ffffff'
					dstContext.fillRect(0, 0, canvas.width, canvas.height)
					const thumbViewport = page.getViewport({ scale: displayScale * 2 })
					const renderTask = page.render({
						canvasContext: dstContext,
						viewport: thumbViewport,
						background: 'rgb(255, 255, 255)',
					})
					onRenderTask?.(renderTask)
					await renderTask.promise
					onRenderTask?.(null)
				}
				return canvas
			}

			// Enhanced path: render at 4x intermediate resolution so scan filters operate cleanly before downsampling
			const renderScale = displayScale * 4
			const viewport = page.getViewport({ scale: renderScale })

			const vpScaled = Math.abs(viewport.width - unscaledViewport.width * renderScale) < 2
			const renderW = vpScaled
				? Math.max(1, Math.round(viewport.width))
				: Math.max(1, Math.round(unscaledViewport.width * renderScale))
			const renderH = vpScaled
				? Math.max(1, Math.round(viewport.height))
				: Math.max(1, Math.round(unscaledViewport.height * renderScale))

			srcCanvas = document.createElement('canvas')
			srcCanvas.width = renderW
			srcCanvas.height = renderH

			const srcContext = srcCanvas.getContext('2d')
			const dstContext = canvas.getContext('2d')
			if (srcContext && dstContext) {
				srcContext.imageSmoothingEnabled = true
				srcContext.imageSmoothingQuality = 'high'
				srcContext.fillStyle = '#ffffff'
				srcContext.fillRect(0, 0, srcCanvas.width, srcCanvas.height)
				const renderTask = page.render({
					canvasContext: srcContext,
					viewport,
					background: 'rgb(255, 255, 255)',
				})
				onRenderTask?.(renderTask)
				await renderTask.promise
				onRenderTask?.(null)

				const imgData = srcContext.getImageData(0, 0, srcCanvas.width, srcCanvas.height)
				let buffer = imageDataToImageBuffer(imgData)
				buffer = applyPipeline(buffer, pageEnhancements.value[pageNum])

				// Area-averaging (box-integration) downsampling to 2x thumbnail size
				const downsampled = downsampleImageBuffer(buffer, thumbW, thumbH)
				dstContext.putImageData(imageBufferToImageData(downsampled), 0, 0)
			}
			return canvas
		} finally {
			onRenderTask?.(null)
			releaseCanvas(srcCanvas)
			cleanupPdfJsPage(page)
		}
	}

	async function renderSingleThumbnail(pdf: any, pageNum: number) {
		if (renderedThumbnails.has(pageNum)) return
		renderedThumbnails.add(pageNum)
		const gen = observerGeneration

		try {
			const canvas = await renderCanvasForPage(pdf, pageNum, (task) => {
				if (task) {
					activeRenderTasks.set(pageNum, task)
				} else {
					activeRenderTasks.delete(pageNum)
				}
			})
			if (!canvas || gen !== observerGeneration) {
				releaseCanvas(canvas)
				return
			}
			thumbnailCanvases.value[pageNum] = canvas
			touchCache(pageNum)

			const container = thumbnailContainers.value[pageNum]
			if (container) {
				container.replaceChildren(canvas)
			}
			evictOldThumbnails()
		} catch (e: any) {
			if (e?.name === 'RenderingCancelledException') {
				renderedThumbnails.delete(pageNum)
				return
			}
			console.warn(`Could not render thumbnail for page ${pageNum}`, e)
		}
	}

	function pumpQueue(pdf: any, gen: number) {
		while (activeWorkers < MAX_CONCURRENT_RENDERS && pendingQueue.length > 0) {
			const pageNum = pendingQueue.shift()!
			pendingSet.delete(pageNum)
			if (gen !== observerGeneration || renderedThumbnails.has(pageNum) || !visiblePages.has(pageNum)) {
				continue
			}
			activeWorkers++
			void renderSingleThumbnail(pdf, pageNum).finally(() => {
				activeWorkers--
				if (gen === observerGeneration) {
					pumpQueue(pdf, gen)
				}
			})
		}
	}

	function enqueueThumbnail(pdf: any, pageNum: number) {
		if (renderedThumbnails.has(pageNum) || pendingSet.has(pageNum)) return
		pendingSet.add(pageNum)
		pendingQueue.push(pageNum)
		pumpQueue(pdf, observerGeneration)
	}

	async function updateThumbnailForPage(pageNum: number) {
		if (!activePdfDoc.value) return
		try {
			const canvas = await renderCanvasForPage(activePdfDoc.value, pageNum)
			if (!canvas) return
			const prev = thumbnailCanvases.value[pageNum]
			if (prev && prev !== canvas) {
				releaseCanvas(prev)
			}
			thumbnailCanvases.value[pageNum] = canvas
			renderedThumbnails.add(pageNum)
			touchCache(pageNum)

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
					const pageNum = Number((entry.target as HTMLElement).dataset.pageNum)
					if (!pageNum) continue
					if (entry.isIntersecting) {
						visiblePages.add(pageNum)
						if (renderedThumbnails.has(pageNum)) {
							touchCache(pageNum)
						} else {
							enqueueThumbnail(pdf, pageNum)
						}
					} else {
						visiblePages.delete(pageNum)
						if (pendingSet.has(pageNum)) {
							pendingSet.delete(pageNum)
							const idx = pendingQueue.indexOf(pageNum)
							if (idx >= 0) pendingQueue.splice(idx, 1)
						}
						const activeTask = activeRenderTasks.get(pageNum)
						if (activeTask) {
							try {
								activeTask.cancel?.()
							} catch {
								// Ignore cancel errors
							}
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
