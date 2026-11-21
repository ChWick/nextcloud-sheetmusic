import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FilePreviewer from '../../../src/components/FilePreviewer.vue'
import { MemoryFileBinaryCache } from '../../../src/utils/file-cache'
import * as fileUtils from '../../../src/utils/file'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: new ArrayBuffer(8) }),
	},
}))

vi.mock('pdfjs-dist', () => ({
	getDocument: vi.fn().mockReturnValue({
		promise: Promise.resolve({
			numPages: 2,
			getPage: vi.fn().mockResolvedValue({
				getViewport: vi.fn().mockReturnValue({ width: 600, height: 800 }),
				render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
			}),
		}),
	}),
	GlobalWorkerOptions: {},
}))

describe('FilePreviewer.vue', () => {
	it('renders image preview for image file path', async () => {
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Joshua.png',
				showExternalButton: true,
			},
		})

		expect(wrapper.find('img').exists()).toBe(true)
		expect(wrapper.find('img').attributes('src')).toBe('/files/scores/Joshua.png')
	})

	it('triggers openFileExternally when external viewer button is clicked', async () => {
		const openSpy = vi.spyOn(fileUtils, 'openFileExternally').mockImplementation(() => {})

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Joshua.pdf',
				showExternalButton: true,
			},
		})

		const externalButton = wrapper.findAll('button').find(b => b.attributes('title')?.includes('external'))
		if (externalButton) {
			await externalButton.trigger('click')
			expect(openSpy).toHaveBeenCalledWith('/files/scores/Joshua.pdf')
		}

		openSpy.mockRestore()
	})

	it('fetches PDF with standard arraybuffer responseType and cache busting headers', async () => {
		const axios = (await import('@nextcloud/axios')).default
		mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Test.pdf',
			},
		})
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/files/scores/Test.pdf', {
			headers: {
				'Cache-Control': 'no-cache',
				'Pragma': 'no-cache',
			},
			responseType: 'arraybuffer',
		})
	})

	it('uses cached binary data from binaryCache without fetching over network', async () => {
		const axios = (await import('@nextcloud/axios')).default
		vi.mocked(axios.get).mockClear()

		const customCache = new MemoryFileBinaryCache()
		const cachedBytes = new Uint8Array([37, 80, 68, 70])
		customCache.set('/files/scores/Cached.pdf?v=1@1', cachedBytes)

		mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Cached.pdf?v=1@1',
				binaryCache: customCache,
			},
		})
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('stores fetched bytes in binaryCache and omits no-cache headers when version parameter is present', async () => {
		const axios = (await import('@nextcloud/axios')).default
		vi.mocked(axios.get).mockClear()

		const customCache = new MemoryFileBinaryCache()
		mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Versioned.pdf?v=10@100',
				binaryCache: customCache,
			},
		})
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/files/scores/Versioned.pdf?v=10@100', {
			responseType: 'arraybuffer',
		})
		expect(customCache.has('/files/scores/Versioned.pdf?v=10@100')).toBe(true)
	})

	it('automatically defaults to single page layout when PDF has more than 30 pages', async () => {
		const pdfjs = await import('pdfjs-dist')
		vi.mocked(pdfjs.getDocument).mockReturnValueOnce({
			promise: Promise.resolve({
				numPages: 160,
				getPage: vi.fn().mockResolvedValue({
					rotate: 0,
					getViewport: vi.fn().mockReturnValue({ width: 600, height: 800 }),
					render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
				}),
			}),
		} as any)

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/LargeJoshua.pdf',
			},
		})
		await flushPromises()

		// Verify page display indicates single page mode (page 1 of 160)
		expect(wrapper.text()).toContain('Page 1 of 160')
	})

	it('creates placeholders with IntersectionObserver for continuous layout mode', async () => {
		const pdfjs = await import('pdfjs-dist')
		vi.mocked(pdfjs.getDocument).mockReturnValueOnce({
			promise: Promise.resolve({
				numPages: 5,
				getPage: vi.fn().mockResolvedValue({
					rotate: 0,
					getViewport: vi.fn().mockReturnValue({ width: 600, height: 800 }),
					render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
				}),
			}),
		} as any)

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/SmallScore.pdf',
			},
		})
		await flushPromises()

		// For continuous layout (<30 pages defaults to continuous), placeholders are created
		const placeholders = wrapper.findAll('.sheetmusic-page-placeholder')
		expect(placeholders.length).toBe(5)
		expect(placeholders[0].attributes('data-page-num')).toBe('1')
		expect(placeholders[4].attributes('data-page-num')).toBe('5')
	})

	it('recognizes PDF file path with query parameter', async () => {
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Test.pdf?t=12345',
			},
		})

		// It should treat as PDF (not unsupported file)
		expect(wrapper.text()).not.toContain('Unsupported file format')
	})

	it('preserves intrinsic page.rotate when getting viewport', async () => {
		const pdfjs = await import('pdfjs-dist')
		const mockGetViewport = vi.fn().mockReturnValue({ width: 600, height: 800 })
		vi.mocked(pdfjs.getDocument).mockReturnValueOnce({
			promise: Promise.resolve({
				numPages: 1,
				getPage: vi.fn().mockResolvedValue({
					rotate: 90,
					getViewport: mockGetViewport,
					render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
				}),
			}),
		} as any)

		mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Rotated.pdf',
			},
		})
		await flushPromises()

		expect(mockGetViewport).toHaveBeenCalledWith(expect.objectContaining({
			rotation: 90,
		}))
	})

	it('shows missing file message when PDF request returns 404', async () => {
		const axios = (await import('@nextcloud/axios')).default
		vi.mocked(axios.get).mockRejectedValueOnce({
			response: { status: 404 },
		})

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/NonExistent.pdf',
			},
		})
		await flushPromises()

		expect(wrapper.text()).toContain('Missing file: The file does not exist on the file system.')
	})

	it('shows missing file message when filePath is empty', async () => {
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '',
			},
		})
		await flushPromises()

		expect(wrapper.text()).toContain('Missing file: The file does not exist on the file system.')
	})

	it('shows missing file message when image fails to load', async () => {
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/MissingImage.png',
			},
		})
		await flushPromises()

		const img = wrapper.find('img')
		expect(img.exists()).toBe(true)

		await img.trigger('error')
		expect(wrapper.text()).toContain('Missing file: The file does not exist on the file system.')
	})

	it('renders PDF page canvas at HiDPI backing resolution when devicePixelRatio > 1', async () => {
		const originalDpr = Object.getOwnPropertyDescriptor(window, 'devicePixelRatio')
		Object.defineProperty(window, 'devicePixelRatio', { value: 2, configurable: true, writable: true })

		const mockCtx = {
			fillStyle: '',
			imageSmoothingEnabled: false,
			imageSmoothingQuality: 'low',
			fillRect: vi.fn(),
		} as unknown as CanvasRenderingContext2D
		const getContextSpy = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(mockCtx)

		try {
			const pdfjs = await import('pdfjs-dist')
			const mockRender = vi.fn().mockReturnValue({ promise: Promise.resolve() })
			vi.mocked(pdfjs.getDocument).mockReturnValueOnce({
				promise: Promise.resolve({
					numPages: 1,
					getPage: vi.fn().mockResolvedValue({
						rotate: 0,
						getViewport: vi.fn().mockReturnValue({ width: 600, height: 800 }),
						render: mockRender,
					}),
				}),
			} as any)

			const wrapper = mount(FilePreviewer, {
				props: {
					filePath: '/files/scores/HiDpi.pdf',
					initialLayout: 'single',
				},
			})
			await flushPromises()

			const canvas = wrapper.find('canvas.sheetmusic-pdf-page').element as HTMLCanvasElement
			expect(canvas).toBeDefined()
			expect(canvas.width).toBe(1200)
			expect(canvas.height).toBe(1600)
			expect(canvas.style.width).toBe('600px')
			expect(canvas.style.height).toBe('800px')
			expect(mockRender).toHaveBeenCalledWith(expect.objectContaining({
				transform: [2, 0, 0, 2, 0, 0],
			}))
		} finally {
			getContextSpy.mockRestore()
			if (originalDpr) {
				Object.defineProperty(window, 'devicePixelRatio', originalDpr)
			} else {
				Object.defineProperty(window, 'devicePixelRatio', { value: 1, configurable: true, writable: true })
			}
		}
	})

	it('caps HiDPI scaling to 2x in continuous scroll mode at 3x DPR to bound canvas memory', async () => {
		const originalDpr = Object.getOwnPropertyDescriptor(window, 'devicePixelRatio')
		const originalIO = globalThis.IntersectionObserver
		Object.defineProperty(window, 'devicePixelRatio', { value: 3, configurable: true, writable: true })
		// Disable IntersectionObserver so continuous mode renders synchronously
		;(globalThis as any).IntersectionObserver = undefined

		const mockCtx = {
			fillStyle: '',
			imageSmoothingEnabled: false,
			imageSmoothingQuality: 'low',
			fillRect: vi.fn(),
		} as unknown as CanvasRenderingContext2D
		const getContextSpy = vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(mockCtx)

		try {
			const pdfjs = await import('pdfjs-dist')
			const mockRender = vi.fn().mockReturnValue({ promise: Promise.resolve() })
			vi.mocked(pdfjs.getDocument).mockReturnValueOnce({
				promise: Promise.resolve({
					numPages: 1,
					getPage: vi.fn().mockResolvedValue({
						rotate: 0,
						getViewport: vi.fn().mockReturnValue({ width: 600, height: 800 }),
						render: mockRender,
					}),
				}),
			} as any)

			const wrapper = mount(FilePreviewer, {
				props: {
					filePath: '/files/scores/ContinuousHiDpi.pdf',
					initialPageLayout: 'continuous',
				},
			})
			await flushPromises()

			const canvas = wrapper.find('canvas.sheetmusic-pdf-page').element as HTMLCanvasElement
			expect(canvas).toBeDefined()
			expect(canvas.width).toBe(1200)
			expect(canvas.height).toBe(1600)
			expect(mockRender).toHaveBeenCalledWith(expect.objectContaining({
				transform: [2, 0, 0, 2, 0, 0],
			}))
		} finally {
			;(globalThis as any).IntersectionObserver = originalIO
			getContextSpy.mockRestore()
			if (originalDpr) {
				Object.defineProperty(window, 'devicePixelRatio', originalDpr)
			} else {
				Object.defineProperty(window, 'devicePixelRatio', { value: 1, configurable: true, writable: true })
			}
		}
	})

	it('renders mixed-size photographed pages in equal half-width slots without resizing on page turns', async () => {
		const pageDims: Record<number, { width: number; height: number }> = {
			1: { width: 3000, height: 4000 },
			2: { width: 1500, height: 2100 },
			3: { width: 2400, height: 3000 },
		}

		const pdfjs = await import('pdfjs-dist')
		vi.mocked(pdfjs.getDocument).mockReturnValueOnce({
			promise: Promise.resolve({
				numPages: 3,
				getPage: vi.fn().mockImplementation((pageNum: number) => {
					const dims = pageDims[pageNum] ?? { width: 600, height: 800 }
					return Promise.resolve({
						rotate: 0,
						getViewport: vi.fn(({ scale }: { scale: number }) => ({
							width: dims.width * scale,
							height: dims.height * scale,
						})),
						render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
					})
				}),
			}),
		} as any)

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/PhotographedMixed.pdf',
				initialPageLayout: 'double',
				initialDoublePageTurnMode: 'half',
				initialZoomMode: 'fit-page',
				noPadding: true,
			},
		})
		await flushPromises()

		// Default fallback container size in happy-dom (clientWidth === 0) is 800x700, doubleGap = 4 => slotW = 398
		const expectedSlotW = 398
		const slotsBefore = wrapper.findAll('.sheetmusic-double-slot')
		expect(slotsBefore).toHaveLength(2)
		const stageEl = slotsBefore[0].element.parentElement as HTMLElement
		expect(stageEl.style.display).toBe('grid')
		expect(stageEl.style.gridTemplateColumns).toBe('repeat(2, minmax(0, 1fr))')
		expect(stageEl.style.width).toBe('100%')
		expect((slotsBefore[0].element as HTMLElement).style.maxWidth).toBe(`${expectedSlotW}px`)
		expect((slotsBefore[1].element as HTMLElement).style.maxWidth).toBe(`${expectedSlotW}px`)

		const getRenderedSize = (pageNum: number) => {
			const placeholder = wrapper.find(`[data-page-num="${pageNum}"]`)
			const canvas = placeholder.find('canvas.sheetmusic-pdf-page').element as HTMLCanvasElement
			return {
				width: Number.parseInt(canvas.style.width, 10),
				height: Number.parseInt(canvas.style.height, 10),
			}
		}

		const page1Size = getRenderedSize(1)
		const page2SizeBefore = getRenderedSize(2)

		// Page 1 (3000x4000) needs scale ~0.1327 (< 0.2) and must fit within 398x700 without being clamped to 0.2
		expect(page1Size.width).toBe(expectedSlotW)
		expect(page1Size.height).toBeLessThanOrEqual(700)
		expect(page2SizeBefore.width).toBe(expectedSlotW)
		expect(page2SizeBefore.height).toBeLessThanOrEqual(700)

		// Half-page turn: [1, 2] -> [3, 2]. Page 2 stays on the right and must NOT change size.
		;(wrapper.vm as any).nextPage()
		await flushPromises()

		const slotsAfterHalf = wrapper.findAll('.sheetmusic-double-slot')
		expect(slotsAfterHalf).toHaveLength(2)
		expect((slotsAfterHalf[0].element as HTMLElement).style.maxWidth).toBe(`${expectedSlotW}px`)
		expect((slotsAfterHalf[1].element as HTMLElement).style.maxWidth).toBe(`${expectedSlotW}px`)

		const page3Size = getRenderedSize(3)
		const page2SizeAfterHalf = getRenderedSize(2)
		expect(page3Size.width).toBe(expectedSlotW)
		expect(page2SizeAfterHalf).toEqual(page2SizeBefore)

		// Step turn: switch to step mode [1, 2] -> [2, 3]. Page 2 moves to left slot and must keep exact same size.
		;(wrapper.vm as any).setDoublePageTurnMode('step')
		;(wrapper.vm as any).goToPage(1)
		await flushPromises()
		const page2InStepBefore = getRenderedSize(2)
		expect(page2InStepBefore).toEqual(page2SizeBefore)

		;(wrapper.vm as any).nextPage()
		await flushPromises()
		const page2InStepAfter = getRenderedSize(2)
		expect(page2InStepAfter).toEqual(page2SizeBefore)
	})

	it('keeps trailing single page in double spread mode at half-width slot size', async () => {
		const pageDims: Record<number, { width: number; height: number }> = {
			1: { width: 2000, height: 2800 },
			2: { width: 1200, height: 1600 },
			3: { width: 2400, height: 3200 },
		}

		const pdfjs = await import('pdfjs-dist')
		vi.mocked(pdfjs.getDocument).mockReturnValueOnce({
			promise: Promise.resolve({
				numPages: 3,
				getPage: vi.fn().mockImplementation((pageNum: number) => {
					const dims = pageDims[pageNum] ?? { width: 600, height: 800 }
					return Promise.resolve({
						rotate: 0,
						getViewport: vi.fn(({ scale }: { scale: number }) => ({
							width: dims.width * scale,
							height: dims.height * scale,
						})),
						render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
					})
				}),
			}),
		} as any)

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/OddPhotographed.pdf',
				initialPageLayout: 'double',
				initialDoublePageTurnMode: 'spread',
				initialZoomMode: 'fit-width',
				noPadding: true,
			},
		})
		await flushPromises()

		// Advance from [1, 2] -> [3, null]
		;(wrapper.vm as any).nextPage()
		await flushPromises()

		const slots = wrapper.findAll('.sheetmusic-double-slot')
		expect(slots).toHaveLength(1)
		expect((slots[0].element as HTMLElement).style.width).toBe('398px')

		const canvas = wrapper.find('[data-page-num="3"] canvas.sheetmusic-pdf-page').element as HTMLCanvasElement
		expect(canvas.style.width).toBe('398px')
	})

	it('strictly enforces 50/50 grid columns when a cropped horizontal page is paired with a portrait page', async () => {
		const pageDims: Record<number, { width: number; height: number }> = {
			1: { width: 1400, height: 600 }, // cropped horizontal page
			2: { width: 500, height: 900 }, // portrait page
		}

		const pdfjs = await import('pdfjs-dist')
		vi.mocked(pdfjs.getDocument).mockReturnValueOnce({
			promise: Promise.resolve({
				numPages: 2,
				getPage: vi.fn().mockImplementation((pageNum: number) => {
					const dims = pageDims[pageNum] ?? { width: 600, height: 800 }
					return Promise.resolve({
						rotate: 0,
						getViewport: vi.fn(({ scale }: { scale: number }) => ({
							width: dims.width * scale,
							height: dims.height * scale,
						})),
						render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
					})
				}),
			}),
		} as any)

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/CroppedHorizontalPair.pdf',
				initialPageLayout: 'double',
				initialZoomMode: 'fit-page',
				noPadding: true,
			},
		})
		await flushPromises()

		const slots = wrapper.findAll('.sheetmusic-double-slot')
		expect(slots).toHaveLength(2)
		const stageEl = slots[0].element.parentElement as HTMLElement
		expect(stageEl.style.display).toBe('grid')
		expect(stageEl.style.gridTemplateColumns).toBe('repeat(2, minmax(0, 1fr))')
		expect(stageEl.style.width).toBe('100%')

		const leftCanvas = wrapper.find('[data-page-num="1"] canvas.sheetmusic-pdf-page').element as HTMLCanvasElement
		const rightCanvas = wrapper.find('[data-page-num="2"] canvas.sheetmusic-pdf-page').element as HTMLCanvasElement
		expect(Number.parseInt(leftCanvas.style.width, 10)).toBeLessThanOrEqual(398)
		expect(Number.parseInt(rightCanvas.style.width, 10)).toBeLessThanOrEqual(398)

		// Even in custom zoom mode, both columns remain an equal 50/50 grid
		;(wrapper.vm as any).setZoomMode('custom')
		await flushPromises()
		expect(stageEl.style.display).toBe('grid')
		expect(stageEl.style.gridTemplateColumns).toBe('repeat(2, minmax(0, 1fr))')
	})
})
