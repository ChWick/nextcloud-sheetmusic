/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'
import { usePdfZoomLightbox } from '../../../src/components/song-pdf/usePdfZoomLightbox'

vi.mock('../../../src/components/song-pdf/usePageOrderThumbnails', () => ({
	renderPageImage: vi.fn(async (_pdf: any, pageNum: number) => `data:image/jpeg;base64,page-${pageNum}`),
}))

describe('usePdfZoomLightbox', () => {
	it('opens lightbox, clamps page navigation, caches high-res renders, and resets on PDF swap', async () => {
		const activePdfDoc = ref<any>({ id: 1 })
		const totalPages = ref(3)
		const fakeCanvas = { toDataURL: () => 'data:image/jpeg;base64,thumb-2' } as unknown as HTMLCanvasElement
		const thumbnailCanvases = ref<Record<number, HTMLCanvasElement>>({ 2: fakeCanvas })

		const lightbox = usePdfZoomLightbox({
			activePdfDoc,
			totalPages,
			thumbnailCanvases,
		})

		lightbox.openPageZoom(2)
		expect(lightbox.isZoomModalOpen.value).toBe(true)
		expect(lightbox.zoomPageNum.value).toBe(2)
		expect(lightbox.zoomThumbnailUrl.value).toBe('data:image/jpeg;base64,thumb-2')

		await nextTick()
		await Promise.resolve()
		expect(lightbox.zoomHighResCache.value[2]).toBe('data:image/jpeg;base64,page-2')

		// Out-of-bounds navigation is ignored
		lightbox.goToZoomPage(0)
		lightbox.goToZoomPage(4)
		expect(lightbox.zoomPageNum.value).toBe(2)

		// Replacing activePdfDoc clears cache
		activePdfDoc.value = { id: 2 }
		await nextTick()
		expect(lightbox.zoomHighResCache.value).toEqual({})
	})
})
