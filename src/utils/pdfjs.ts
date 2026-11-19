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
