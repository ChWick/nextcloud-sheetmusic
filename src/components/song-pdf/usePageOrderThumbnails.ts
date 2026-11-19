/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCurrentInstance, onUnmounted, ref, type Ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { cleanupPdfJsPage, destroyPdfJsDocument, loadPdfJsDocument, releaseCanvas, setBoundedRecordEntry } from '../../utils/pdfjs'

export async function renderPageImage(pdf: any, pageNum: number, maxDim = 340): Promise<string> {
	let page: any = null
	let canvas: HTMLCanvasElement | null = null
	try {
		page = await pdf.getPage(pageNum)
		const unscaledViewport = page.getViewport({ scale: 1.0 })
		const scale = Math.min(
			maxDim / Math.max(1, unscaledViewport.width),
			maxDim / Math.max(1, unscaledViewport.height),
		)
		const viewport = page.getViewport({ scale })

		canvas = document.createElement('canvas')
		canvas.width = Math.max(1, Math.round(viewport.width))
		canvas.height = Math.max(1, Math.round(viewport.height))

		const ctx = canvas.getContext('2d')
		if (ctx) {
			ctx.fillStyle = '#ffffff'
			ctx.fillRect(0, 0, canvas.width, canvas.height)

			await page.render({
				canvasContext: ctx,
				viewport,
			}).promise
		}

		let dataUrl = ''
		try {
			dataUrl = canvas.toDataURL ? canvas.toDataURL('image/jpeg', maxDim > 500 ? 0.92 : 0.85) : ''
		} catch {
			// Fallback if toDataURL throws
		}
		if (!dataUrl || dataUrl === 'data:,') {
			const label = maxDim > 500 ? `High-Res Page ${pageNum}` : `${pageNum}`
			dataUrl = `data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="${viewport.width}" height="${viewport.height}"><rect width="100%" height="100%" fill="%23ffffff"/><text x="50%" y="50%" font-size="${maxDim > 500 ? 32 : 20}" text-anchor="middle" fill="%23666">${label}</text></svg>`
		}

		return dataUrl
	} catch {
		return ''
	} finally {
		releaseCanvas(canvas)
		cleanupPdfJsPage(page)
	}
}

export function usePageOrderThumbnails(
	pdfProp: Ref<any>,
	filePath: Ref<string>,
	totalPages: Ref<number>,
) {
	const thumbnails = ref<Record<number, string>>({})
	const highResCache = ref<Record<number, string>>({})
	const highResOrder = new Set<number>()
	const isLoading = ref(false)
	const isLoadingHighRes = ref(false)
	const internalPdf = ref<any>(null)

	let activeSessionId = 0

	async function resolvePdf(): Promise<any> {
		if (pdfProp.value) {
			return pdfProp.value
		}
		if (internalPdf.value) {
			return internalPdf.value
		}
		const path = filePath.value
		if (!path) return null

		try {
			const response = await axios.get(path, { responseType: 'arraybuffer' })
			const pdfData = response.data instanceof ArrayBuffer ? new Uint8Array(response.data) : response.data
			const doc = await loadPdfJsDocument(pdfData)

			internalPdf.value = doc
			return doc
		} catch {
			return null
		}
	}

	async function getHighResPage(pageNum: number): Promise<string> {
		if (highResCache.value[pageNum]) {
			return highResCache.value[pageNum]
		}

		isLoadingHighRes.value = true
		try {
			const pdf = await resolvePdf()
			if (!pdf) return ''

			const url = await renderPageImage(pdf, pageNum, 1600)
			if (url) {
				highResCache.value = setBoundedRecordEntry(highResCache.value, highResOrder, pageNum, url)
			}
			return url
		} finally {
			isLoadingHighRes.value = false
		}
	}

	async function loadThumbnails() {
		const sessionId = ++activeSessionId
		isLoading.value = true

		try {
			const pdf = await resolvePdf()
			if (!pdf || sessionId !== activeSessionId) {
				return
			}

			const count = totalPages.value || pdf.numPages || 1

			// Render thumbnails sequentially to keep memory usage smooth
			for (let p = 1; p <= count; p++) {
				if (sessionId !== activeSessionId) return
				const url = await renderPageImage(pdf, p, 340)
				if (sessionId !== activeSessionId) return
				if (url) {
					thumbnails.value[p] = url
				}
			}
		} finally {
			if (sessionId === activeSessionId) {
				isLoading.value = false
			}
		}
	}

	watch(
		() => [pdfProp.value, filePath.value, totalPages.value],
		() => {
			if (internalPdf.value) {
				void destroyPdfJsDocument(internalPdf.value)
				internalPdf.value = null
			}
			thumbnails.value = {}
			highResCache.value = {}
			highResOrder.clear()
			loadThumbnails()
		},
		{ immediate: true },
	)

	if (getCurrentInstance()) {
		onUnmounted(() => {
			activeSessionId++
			if (internalPdf.value) {
				void destroyPdfJsDocument(internalPdf.value)
				internalPdf.value = null
			}
		})
	}

	return {
		thumbnails,
		highResCache,
		isLoading,
		isLoadingHighRes,
		getHighResPage,
		loadThumbnails,
	}
}
