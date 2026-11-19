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
		expect(thumbnailCanvases.value[1].style.width).toBe('140px')
		expect(thumbnailCanvases.value[1].style.height).toBe('190px')
	})

	it('fits landscape (90deg rotated) pages within 140x190 display bounds with explicit style dimensions', async () => {
		const landscapePdf = {
			numPages: 1,
			getPage: vi.fn(() => Promise.resolve({
				getViewport: vi.fn(({ scale = 1 }: { scale?: number } = {}) => ({
					width: 842 * scale,
					height: 595 * scale,
				})),
				render: vi.fn(() => ({ promise: Promise.resolve() })),
			})),
		}
		const activePdfDoc = ref(landscapePdf)
		const pageEnhancements = ref({})
		const { renderSingleThumbnail, thumbnailCanvases } = usePdfThumbnails(activePdfDoc, pageEnhancements)

		await renderSingleThumbnail(landscapePdf, 1)

		const canvas = thumbnailCanvases.value[1]
		expect(canvas).toBeDefined()
		expect(canvas.style.width).toBe('140px')
		expect(canvas.style.height).toBe('99px')
		expect(canvas.width).toBe(280)
		expect(canvas.height).toBe(198)
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

	it('calls page.cleanup() after rendering a thumbnail', async () => {
		const cleanupSpy = vi.fn()
		const mockPdf = {
			numPages: 1,
			getPage: vi.fn(() => Promise.resolve({
				getViewport: vi.fn(() => ({ width: 280, height: 380 })),
				render: vi.fn(() => ({ promise: Promise.resolve() })),
				cleanup: cleanupSpy,
			})),
		}
		const activePdfDoc = ref(mockPdf)
		const pageEnhancements = ref({})
		const { renderSingleThumbnail } = usePdfThumbnails(activePdfDoc, pageEnhancements)

		await renderSingleThumbnail(mockPdf, 1)

		expect(cleanupSpy).toHaveBeenCalledTimes(1)
	})

	it('evicts off-screen cached canvases beyond the LRU limit on large PDFs', async () => {
		const totalPages = 70
		const mockPdf = {
			numPages: totalPages,
			getPage: vi.fn(() => Promise.resolve({
				getViewport: vi.fn(() => ({ width: 280, height: 380 })),
				render: vi.fn(() => ({ promise: Promise.resolve() })),
				cleanup: vi.fn(),
			})),
		}

		let observerCallback: ((entries: any[]) => void) | null = null
		const observeSpy = vi.fn()
		const disconnectSpy = vi.fn()
		const OriginalIO = globalThis.IntersectionObserver

		globalThis.IntersectionObserver = class {
			constructor(cb: (entries: any[]) => void) {
				observerCallback = cb
			}

			observe = observeSpy
			disconnect = disconnectSpy
			unobserve = vi.fn()
			takeRecords = vi.fn(() => [])
			root = null
			rootMargin = ''
			thresholds = []
		} as any

		try {
			const activePdfDoc = ref(mockPdf)
			const pageEnhancements = ref({})
			const { setContainerRef, initThumbnailObserver, thumbnailCanvases, cleanupThumbnailObserver } = usePdfThumbnails(activePdfDoc, pageEnhancements)

			const containers: HTMLElement[] = []
			for (let p = 1; p <= totalPages; p++) {
				const el = document.createElement('div')
				setContainerRef(p, el)
				containers.push(el)
			}

			initThumbnailObserver(mockPdf)
			expect(observerCallback).not.toBeNull()

			// Simulate scrolling through all 70 pages, leaving earlier pages off-screen
			for (let p = 1; p <= totalPages; p++) {
				observerCallback!([{ target: containers[p - 1], isIntersecting: true }])
				await new Promise((r) => setTimeout(r, 0))
				observerCallback!([{ target: containers[p - 1], isIntersecting: false }])
			}

			// Wait for queued renders to drain
			await new Promise((r) => setTimeout(r, 20))

			expect(Object.keys(thumbnailCanvases.value).length).toBeLessThanOrEqual(60)
			// Earliest pages should have been evicted while latest pages remain cached
			expect(thumbnailCanvases.value[1]).toBeUndefined()
			expect(thumbnailCanvases.value[totalPages]).toBeDefined()

			cleanupThumbnailObserver()
		} finally {
			globalThis.IntersectionObserver = OriginalIO
		}
	})
})
