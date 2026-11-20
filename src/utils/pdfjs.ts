/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { generateFilePath } from '@nextcloud/router'
import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url'

export interface PdfJsAssetUrls {
	wasmUrl: string
	cMapUrl: string
	standardFontDataUrl: string
}

/**
 * Configure the pdf.js worker source once.
 */
export function ensurePdfJsWorker(): void {
	if (GlobalWorkerOptions && !GlobalWorkerOptions.workerSrc) {
		GlobalWorkerOptions.workerSrc = pdfWorker
	}
}

/**
 * Resolve runtime asset URLs for pdf.js WASM, CMaps, and standard fonts.
 */
export function getPdfJsAssetUrls(): PdfJsAssetUrls {
	const hasRouter = typeof generateFilePath === 'function'
	return {
		wasmUrl: hasRouter
			? generateFilePath('sheetmusic', 'js', 'pdfjs/wasm/')
			: new URL('pdfjs-dist/wasm/', import.meta.url).href,
		cMapUrl: hasRouter
			? generateFilePath('sheetmusic', 'js', 'pdfjs/cmaps/')
			: new URL('pdfjs-dist/cmaps/', import.meta.url).href,
		standardFontDataUrl: hasRouter
			? generateFilePath('sheetmusic', 'js', 'pdfjs/standard_fonts/')
			: new URL('pdfjs-dist/standard_fonts/', import.meta.url).href,
	}
}

/**
 * Open a pdf.js document with shared asset URLs and low-memory defaults.
 *
 * Note: pdf.js transfers/detaches the passed ArrayBuffer when using its worker,
 * so callers that retain the original buffer must pass a `.slice()`.
 */
export async function loadPdfJsDocument(
	data: Uint8Array | ArrayBuffer,
	extraOptions: Record<string, unknown> = {},
): Promise<any> {
	ensurePdfJsWorker()
	const { wasmUrl, cMapUrl, standardFontDataUrl } = getPdfJsAssetUrls()
	return getDocument({
		data,
		cMapUrl,
		cMapPacked: true,
		standardFontDataUrl,
		wasmUrl,
		disableAutoFetch: true,
		isEvalSupported: false,
		...extraOptions,
	}).promise
}

/**
 * Release decoded images, fonts, and operator lists held by a pdf.js page proxy.
 */
export function cleanupPdfJsPage(page: any): void {
	try {
		page?.cleanup?.()
	} catch {
		// Ignore page cleanup errors
	}
}

/**
 * Destroy a pdf.js document and free its worker-side tables and streams.
 */
export async function destroyPdfJsDocument(pdf: any): Promise<void> {
	try {
		await pdf?.destroy?.()
	} catch {
		// Ignore document destroy errors
	}
}

/**
 * Immediately release a canvas's GPU/backing-store pixel buffer.
 */
export function releaseCanvas(canvas: HTMLCanvasElement | null | undefined): void {
	if (!canvas) return
	canvas.width = 0
	canvas.height = 0
}

/**
 * Release all rendered pdf.js page canvases inside a container element.
 */
export function releaseChildCanvases(container: ParentNode | null | undefined): void {
	if (!container || typeof container.querySelectorAll !== 'function') return
	const canvases = container.querySelectorAll('canvas')
	for (const canvas of canvases) {
		releaseCanvas(canvas)
	}
}

/**
 * Compute the normalized effective rotation (0..359°) for a pdf.js page combined with an optional UI rotation delta.
 */
export function getEffectivePageRotation(
	page: { rotate?: number } | null | undefined,
	extraRotation = 0,
): number {
	return ((((page?.rotate ?? 0) + (extraRotation || 0)) % 360) + 360) % 360
}

/**
 * Subscribe to `window.devicePixelRatio` changes (e.g., dragging a window between 1× and 2× displays)
 * and return a cleanup function.
 */
export function watchDevicePixelRatio(onChange: () => void): () => void {
	if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
		return () => {}
	}
	let currentMql: MediaQueryList | null = null
	let disposed = false

	const handleChange = () => {
		if (disposed) return
		subscribe()
		onChange()
	}

	const subscribe = () => {
		currentMql?.removeEventListener?.('change', handleChange)
		const dpr = window.devicePixelRatio || 1
		currentMql = window.matchMedia(`(resolution: ${dpr}dppx)`)
		currentMql?.addEventListener?.('change', handleChange, { once: true })
	}

	subscribe()

	return () => {
		disposed = true
		currentMql?.removeEventListener?.('change', handleChange)
		currentMql = null
	}
}

/**
 * Default maximum devicePixelRatio multiplier for HiDPI PDF canvas rendering.
 */
export const DEFAULT_MAX_PDF_DPR = 3

/**
 * Default maximum backing-store pixel area (4096 × 4096) to prevent GPU/memory blowup on large zooms or mobile.
 */
export const DEFAULT_MAX_PDF_CANVAS_PIXELS = 16_777_216

export interface PdfOutputScaleOptions {
	maxDpr?: number
	maxCanvasPixels?: number
}

/**
 * Compute the HiDPI backing-store output scale for a PDF canvas, respecting
 * `window.devicePixelRatio`, an upper DPR cap, and a safe maximum pixel budget.
 */
export function getPdfOutputScale(
	cssWidth?: number,
	cssHeight?: number,
	options: PdfOutputScaleOptions = {},
): number {
	const maxDpr = options.maxDpr ?? DEFAULT_MAX_PDF_DPR
	const maxCanvasPixels = options.maxCanvasPixels ?? DEFAULT_MAX_PDF_CANVAS_PIXELS
	const rawDpr = (typeof window !== 'undefined' && Number.isFinite(window.devicePixelRatio) && window.devicePixelRatio > 0)
		? window.devicePixelRatio
		: 1
	const dpr = Math.max(1, Math.min(rawDpr, maxDpr))

	if (cssWidth && cssHeight && cssWidth > 0 && cssHeight > 0) {
		const cssPixels = cssWidth * cssHeight
		if (cssPixels * dpr * dpr > maxCanvasPixels) {
			const maxScaleForArea = Math.sqrt(maxCanvasPixels / cssPixels)
			return Math.max(1, Math.min(dpr, maxScaleForArea))
		}
	}

	return dpr
}

export interface RenderPdfPageOptions extends PdfOutputScaleOptions {
	/** Explicit backing-store multiplier (defaults to `getPdfOutputScale(viewport.width, viewport.height)`) */
	outputScale?: number
	/** Override CSS display width in pixels (defaults to `Math.round(viewport.width)`) */
	cssWidth?: number
	/** Override CSS display height in pixels (defaults to `Math.round(viewport.height)`) */
	cssHeight?: number
	/** Set `canvas.style.width` and `canvas.style.height` in CSS pixels (default: true) */
	applyCssSize?: boolean
	/** Fill background color before rendering; pass `null` to clearRect instead (default: '#ffffff') */
	backgroundColor?: string | null
	/** Optional callback to track/cancel the active pdf.js renderTask */
	onRenderTask?: (task: { cancel?: () => void } | null) => void
}

export interface RenderedPdfPageDimensions {
	cssWidth: number
	cssHeight: number
	pixelWidth: number
	pixelHeight: number
	outputScale: number
}

/**
 * Render a pdf.js page onto a canvas with HiDPI (`devicePixelRatio`) backing-store scaling
 * while keeping CSS layout dimensions equal to `viewport.width × viewport.height`.
 */
export async function renderPdfPageToCanvas(
	page: any,
	canvas: HTMLCanvasElement,
	viewport: { width: number; height: number },
	options: RenderPdfPageOptions = {},
): Promise<RenderedPdfPageDimensions> {
	const cssWidth = Math.max(1, Math.round(options.cssWidth ?? viewport.width))
	const cssHeight = Math.max(1, Math.round(options.cssHeight ?? viewport.height))
	const outputScale = options.outputScale ?? getPdfOutputScale(cssWidth, cssHeight, {
		maxDpr: options.maxDpr,
		maxCanvasPixels: options.maxCanvasPixels,
	})
	const pixelWidth = Math.max(1, Math.round(cssWidth * outputScale))
	const pixelHeight = Math.max(1, Math.round(cssHeight * outputScale))

	canvas.width = pixelWidth
	canvas.height = pixelHeight
	if (options.applyCssSize !== false) {
		canvas.style.width = `${cssWidth}px`
		canvas.style.height = `${cssHeight}px`
	}

	const canvasContext = canvas.getContext('2d')
	if (canvasContext) {
		canvasContext.imageSmoothingEnabled = true
		canvasContext.imageSmoothingQuality = 'high'
		if (options.backgroundColor === null) {
			canvasContext.clearRect(0, 0, pixelWidth, pixelHeight)
		} else {
			canvasContext.fillStyle = options.backgroundColor ?? '#ffffff'
			canvasContext.fillRect(0, 0, pixelWidth, pixelHeight)
		}

		const renderParams: Record<string, unknown> = {
			canvasContext,
			viewport,
			background: 'rgb(255, 255, 255)',
		}
		if (outputScale !== 1) {
			renderParams.transform = [outputScale, 0, 0, outputScale, 0, 0]
		}

		const renderTask = page.render(renderParams)
		options.onRenderTask?.(renderTask)
		try {
			await renderTask.promise
		} finally {
			options.onRenderTask?.(null)
		}
	}

	return { cssWidth, cssHeight, pixelWidth, pixelHeight, outputScale }
}

/**
 * Default maximum number of high-resolution zoomed page images to keep cached in memory.
 */
export const MAX_ZOOM_HIGH_RES_CACHE = 12

/**
 * Insert or refresh a key in a bounded LRU record, returning a new record object
 * with oldest entries evicted beyond `maxEntries`.
 */
export function setBoundedRecordEntry<T>(
	record: Record<number, T>,
	order: Set<number>,
	key: number,
	value: T,
	maxEntries = MAX_ZOOM_HIGH_RES_CACHE,
): Record<number, T> {
	order.delete(key)
	order.add(key)
	const next: Record<number, T> = {
		...record,
		[key]: value,
	}
	while (order.size > maxEntries) {
		const oldest = order.values().next().value
		if (oldest === undefined) break
		order.delete(oldest)
		delete next[oldest]
	}
	return next
}
