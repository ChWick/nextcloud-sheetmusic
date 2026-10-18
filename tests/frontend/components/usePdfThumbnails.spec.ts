import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { usePdfThumbnails } from '../../../src/components/song-pdf/usePdfThumbnails'

describe('usePdfThumbnails', () => {
	function createMockPdf(numPages = 3) {
		return {
			numPages,
			getPage: vi.fn((pageNum: number) => Promise.resolve({
				getViewport: vi.fn(() => ({ width: 280, height: 380 })),
				render: vi.fn(() => ({ promise: Promise.resolve() })),
			})),
		}
	}

	it('detects enhanced pages properly', () => {
		const activePdfDoc = ref(createMockPdf())
		const pageEnhancements = ref<Record<number, any[]>>({
			1: [{ id: '1', type: 'gaussian', enabled: true }],
			2: [{ id: '2', type: 'gaussian', enabled: false }],
		})
		const { isPageEnhanced } = usePdfThumbnails(activePdfDoc, pageEnhancements)

		expect(isPageEnhanced(1)).toBe(true)
		expect(isPageEnhanced(2)).toBe(false)
		expect(isPageEnhanced(3)).toBe(false)
	})

	it('renders single thumbnail and sets container child canvas', async () => {
		const mockPdf = createMockPdf()
		const activePdfDoc = ref(mockPdf)
		const pageEnhancements = ref({})
		const { setContainerRef, renderSingleThumbnail, thumbnailCanvases } = usePdfThumbnails(activePdfDoc, pageEnhancements)

		const container = document.createElement('div')
		setContainerRef(1, container)
		expect(container.dataset.pageNum).toBe('1')

		await renderSingleThumbnail(mockPdf, 1)

		expect(thumbnailCanvases.value[1]).toBeDefined()
		expect(container.firstChild).toBe(thumbnailCanvases.value[1])
	})

	it('updates thumbnail for a page when enhancements change', async () => {
		const mockPdf = createMockPdf()
		const activePdfDoc = ref(mockPdf)
		const pageEnhancements = ref({})
		const { setContainerRef, updateThumbnailForPage, thumbnailCanvases } = usePdfThumbnails(activePdfDoc, pageEnhancements)

		const container = document.createElement('div')
		setContainerRef(1, container)

		await updateThumbnailForPage(1)
		expect(thumbnailCanvases.value[1]).toBeDefined()
		expect(container.children.length).toBe(1)
	})

	it('cleans up observer and resets rendered state', async () => {
		const mockPdf = createMockPdf()
		const activePdfDoc = ref(mockPdf)
		const pageEnhancements = ref({})
		const { initThumbnailObserver, cleanupThumbnailObserver, renderedThumbnails } = usePdfThumbnails(activePdfDoc, pageEnhancements)

		initThumbnailObserver(mockPdf)
		expect(renderedThumbnails.size).toBeGreaterThanOrEqual(0)

		cleanupThumbnailObserver()
		expect(renderedThumbnails.size).toBe(0)
	})
})
