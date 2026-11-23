/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref, watch, type Ref } from 'vue'
import { setBoundedRecordEntry } from '../../utils/pdfjs'
import { renderPageImage } from './usePageOrderThumbnails'

export interface UsePdfZoomLightboxOptions {
	activePdfDoc: Ref<any>
	totalPages: Ref<number>
	thumbnailCanvases: Ref<Record<number, HTMLCanvasElement>>
}

/**
 * Manages the page zoom lightbox modal state and bounded high-resolution page image cache.
 */
export function usePdfZoomLightbox(options: UsePdfZoomLightboxOptions) {
	const { activePdfDoc, totalPages, thumbnailCanvases } = options

	const isZoomModalOpen = ref(false)
	const zoomPageNum = ref(1)
	const zoomHighResCache = ref<Record<number, string>>({})
	const zoomHighResOrder = new Set<number>()
	const isLoadingZoomHighRes = ref(false)

	watch(activePdfDoc, () => {
		zoomHighResCache.value = {}
		zoomHighResOrder.clear()
	})

	const zoomThumbnailUrl = computed(() => {
		const canvas = thumbnailCanvases.value?.[zoomPageNum.value]
		if (!canvas || typeof canvas.toDataURL !== 'function') return ''
		try {
			return canvas.toDataURL('image/jpeg', 0.85)
		} catch {
			return ''
		}
	})

	async function loadZoomHighResPage(pageNum: number) {
		if (zoomHighResCache.value[pageNum] || !activePdfDoc.value) return
		isLoadingZoomHighRes.value = true
		try {
			const url = await renderPageImage(activePdfDoc.value, pageNum, 1600)
			if (url) {
				zoomHighResCache.value = setBoundedRecordEntry(zoomHighResCache.value, zoomHighResOrder, pageNum, url)
			}
		} finally {
			isLoadingZoomHighRes.value = false
		}
	}

	function goToZoomPage(pageNum: number) {
		if (pageNum < 1 || pageNum > totalPages.value) return
		zoomPageNum.value = pageNum
		void loadZoomHighResPage(pageNum)
	}

	function openPageZoom(pageNum: number) {
		isZoomModalOpen.value = true
		goToZoomPage(pageNum)
	}

	return {
		isZoomModalOpen,
		zoomPageNum,
		zoomHighResCache,
		isLoadingZoomHighRes,
		zoomThumbnailUrl,
		goToZoomPage,
		openPageZoom,
	}
}
