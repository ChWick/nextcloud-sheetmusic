/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { usePageOrderThumbnails } from '../../../src/components/song-pdf/usePageOrderThumbnails'

describe('usePageOrderThumbnails', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('renders thumbnails for pages from provided pdf doc', async () => {
		const mockPage = {
			getViewport: vi.fn(() => ({ width: 600, height: 800 })),
			render: vi.fn(() => ({ promise: Promise.resolve() })),
		}
		const mockPdf = {
			numPages: 2,
			getPage: vi.fn(() => Promise.resolve(mockPage)),
		}

		const pdfRef = ref<any>(mockPdf)
		const filePathRef = ref('')
		const totalPagesRef = ref(2)

		const { thumbnails, isLoading } = usePageOrderThumbnails(pdfRef, filePathRef, totalPagesRef)

		// Wait for microtasks
		await new Promise((r) => setTimeout(r, 50))

		expect(mockPdf.getPage).toHaveBeenCalledWith(1)
		expect(mockPdf.getPage).toHaveBeenCalledWith(2)
		expect(thumbnails.value[1]).toBeDefined()
		expect(thumbnails.value[2]).toBeDefined()
		expect(isLoading.value).toBe(false)
	})

	it('renders high-res page on demand via getHighResPage', async () => {
		const mockPage = {
			getViewport: vi.fn(() => ({ width: 1200, height: 1600 })),
			render: vi.fn(() => ({ promise: Promise.resolve() })),
		}
		const mockPdf = {
			numPages: 2,
			getPage: vi.fn(() => Promise.resolve(mockPage)),
		}

		const pdfRef = ref<any>(mockPdf)
		const filePathRef = ref('')
		const totalPagesRef = ref(2)

		const { getHighResPage, highResCache } = usePageOrderThumbnails(pdfRef, filePathRef, totalPagesRef)

		const highRes = await getHighResPage(1)
		expect(highRes).toBeDefined()
		expect(highResCache.value[1]).toBe(highRes)
	})

	it('resets thumbnails when filePath or pdf changes', async () => {
		const mockPage = {
			getViewport: vi.fn(() => ({ width: 600, height: 800 })),
			render: vi.fn(() => ({ promise: Promise.resolve() })),
		}
		const mockPdf1 = {
			numPages: 1,
			getPage: vi.fn(() => Promise.resolve(mockPage)),
		}

		const pdfRef = ref<any>(mockPdf1)
		const filePathRef = ref('')
		const totalPagesRef = ref(1)

		const { thumbnails } = usePageOrderThumbnails(pdfRef, filePathRef, totalPagesRef)
		await new Promise((r) => setTimeout(r, 50))
		expect(thumbnails.value[1]).toBeDefined()

		// Change pdf
		pdfRef.value = null
		totalPagesRef.value = 0
		await new Promise((r) => setTimeout(r, 20))
		expect(thumbnails.value).toEqual({})
	})
})
