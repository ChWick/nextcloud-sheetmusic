/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref, type Ref, watch } from 'vue'
import axios from '@nextcloud/axios'
import { generateFilePath } from '@nextcloud/router'
import { getDocument } from 'pdfjs-dist'

export function usePageOrderThumbnails(
	pdfProp: Ref<any>,
	filePath: Ref<string>,
	totalPages: Ref<number>,
) {
	const thumbnails = ref<Record<number, string>>({})
	const isLoading = ref(false)
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
			const wasmUrl = typeof generateFilePath === 'function'
				? generateFilePath('sheetmusic', 'js', 'pdfjs/wasm/')
				: new URL('pdfjs-dist/wasm/', import.meta.url).href
			const cMapUrl = typeof generateFilePath === 'function'
				? generateFilePath('sheetmusic', 'js', 'pdfjs/cmaps/')
				: new URL('pdfjs-dist/cmaps/', import.meta.url).href
			const standardFontDataUrl = typeof generateFilePath === 'function'
				? generateFilePath('sheetmusic', 'js', 'pdfjs/standard_fonts/')
				: new URL('pdfjs-dist/standard_fonts/', import.meta.url).href

			const doc = await getDocument({
				data: pdfData,
				cMapUrl,
				cMapPacked: true,
				standardFontDataUrl,
				wasmUrl,
			}).promise

			internalPdf.value = doc
			return doc
		} catch {
			return null
		}
	}

	async function renderPageThumbnail(pdf: any, pageNum: number): Promise<string> {
		try {
			const page = await pdf.getPage(pageNum)
			const unscaledViewport = page.getViewport({ scale: 1.0 })
			const maxDim = 340
			const scale = Math.min(
				maxDim / Math.max(1, unscaledViewport.width),
				maxDim / Math.max(1, unscaledViewport.height),
			)
			const viewport = page.getViewport({ scale })

			const canvas = document.createElement('canvas')
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
				dataUrl = canvas.toDataURL ? canvas.toDataURL('image/jpeg', 0.85) : ''
			} catch {
				// Fallback if toDataURL throws
			}
			if (!dataUrl || dataUrl === 'data:,') {
				dataUrl = `data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="${viewport.width}" height="${viewport.height}"><rect width="100%" height="100%" fill="%23ffffff"/><text x="50%" y="50%" font-size="20" text-anchor="middle" fill="%23999">${pageNum}</text></svg>`
			}

			return dataUrl
		} catch {
			return ''
		}
	}

	async function loadAllThumbnails() {
		const sessionId = ++activeSessionId
		isLoading.value = true

		try {
			const pdf = await resolvePdf()
			if (sessionId !== activeSessionId || !pdf) {
				isLoading.value = false
				return
			}

			const count = totalPages.value || (pdf.numPages ?? 0)
			for (let p = 1; p <= count; p++) {
				if (sessionId !== activeSessionId) return
				if (!thumbnails.value[p]) {
					const dataUrl = await renderPageThumbnail(pdf, p)
					if (sessionId !== activeSessionId) return
					if (dataUrl) {
						thumbnails.value[p] = dataUrl
					}
				}
			}
		} finally {
			if (sessionId === activeSessionId) {
				isLoading.value = false
			}
		}
	}

	watch(
		[pdfProp, filePath, totalPages],
		() => {
			thumbnails.value = {}
			internalPdf.value = null
			void loadAllThumbnails()
		},
		{ immediate: true },
	)

	return {
		thumbnails,
		isLoading,
		loadAllThumbnails,
	}
}
