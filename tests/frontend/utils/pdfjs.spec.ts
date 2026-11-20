import { afterEach, describe, expect, it, vi } from 'vitest'
import { getEffectivePageRotation, getPdfOutputScale, renderPdfPageToCanvas, watchDevicePixelRatio } from '../../../src/utils/pdfjs'

describe('utils/pdfjs.ts HiDPI helpers', () => {
	const originalDpr = Object.getOwnPropertyDescriptor(window, 'devicePixelRatio')

	function setDpr(value: number) {
		Object.defineProperty(window, 'devicePixelRatio', {
			value,
			configurable: true,
			writable: true,
		})
	}

	afterEach(() => {
		if (originalDpr) {
			Object.defineProperty(window, 'devicePixelRatio', originalDpr)
		} else {
			setDpr(1)
		}
	})

	it('getPdfOutputScale respects devicePixelRatio, maxDpr cap, and maxCanvasPixels budget', () => {
		setDpr(1)
		expect(getPdfOutputScale(800, 1000)).toBe(1)

		setDpr(2)
		expect(getPdfOutputScale(800, 1000)).toBe(2)

		setDpr(4)
		expect(getPdfOutputScale(800, 1000)).toBe(3)
		expect(getPdfOutputScale(800, 1000, { maxDpr: 2 })).toBe(2)

		// 3000 × 3000 = 9M CSS pixels; with maxCanvasPixels = 16M, max scale is sqrt(16/9) ≈ 1.333
		setDpr(3)
		const constrained = getPdfOutputScale(3000, 3000, { maxCanvasPixels: 16_000_000 })
		expect(constrained).toBeCloseTo(4 / 3, 4)

		// Invalid DPR falls back to 1
		setDpr(Number.NaN)
		expect(getPdfOutputScale(800, 1000)).toBe(1)
	})

	it('renderPdfPageToCanvas scales canvas backing store for HiDPI while keeping CSS dimensions', async () => {
		setDpr(2)

		const fillRect = vi.fn()
		const clearRect = vi.fn()
		const mockCtx = {
			fillStyle: '',
			imageSmoothingEnabled: false,
			imageSmoothingQuality: 'low',
			fillRect,
			clearRect,
		} as unknown as CanvasRenderingContext2D

		const canvas = document.createElement('canvas')
		vi.spyOn(canvas, 'getContext').mockReturnValue(mockCtx)

		const renderTask = { promise: Promise.resolve(), cancel: vi.fn() }
		const page = { render: vi.fn().mockReturnValue(renderTask) }
		const onRenderTask = vi.fn()

		const dims = await renderPdfPageToCanvas(page, canvas, { width: 600, height: 800 }, { onRenderTask })

		expect(dims).toEqual({
			cssWidth: 600,
			cssHeight: 800,
			pixelWidth: 1200,
			pixelHeight: 1600,
			outputScale: 2,
		})
		expect(canvas.width).toBe(1200)
		expect(canvas.height).toBe(1600)
		expect(canvas.style.width).toBe('600px')
		expect(canvas.style.height).toBe('800px')
		expect(fillRect).toHaveBeenCalledWith(0, 0, 1200, 1600)
		expect(page.render).toHaveBeenCalledWith(expect.objectContaining({
			canvasContext: mockCtx,
			viewport: { width: 600, height: 800 },
			transform: [2, 0, 0, 2, 0, 0],
		}))
		expect(onRenderTask).toHaveBeenNthCalledWith(1, renderTask)
		expect(onRenderTask).toHaveBeenNthCalledWith(2, null)
	})

	it('omits transform at 1x DPR and supports applyCssSize: false and backgroundColor: null', async () => {
		setDpr(1)

		const clearRect = vi.fn()
		const fillRect = vi.fn()
		const mockCtx = {
			clearRect,
			fillRect,
			imageSmoothingEnabled: false,
			imageSmoothingQuality: 'low',
		} as unknown as CanvasRenderingContext2D

		const canvas = document.createElement('canvas')
		vi.spyOn(canvas, 'getContext').mockReturnValue(mockCtx)

		const page = { render: vi.fn().mockReturnValue({ promise: Promise.resolve() }) }

		await renderPdfPageToCanvas(page, canvas, { width: 300, height: 400 }, {
			applyCssSize: false,
			backgroundColor: null,
		})

		expect(canvas.width).toBe(300)
		expect(canvas.height).toBe(400)
		expect(canvas.style.width).toBe('')
		expect(canvas.style.height).toBe('')
		expect(clearRect).toHaveBeenCalledWith(0, 0, 300, 400)
		expect(fillRect).not.toHaveBeenCalled()
		const renderArg = page.render.mock.calls[0][0]
		expect(renderArg.transform).toBeUndefined()
	})

	it('getEffectivePageRotation normalizes positive, negative, and wrapped rotations', () => {
		expect(getEffectivePageRotation({ rotate: 90 }, 0)).toBe(90)
		expect(getEffectivePageRotation({ rotate: 270 }, 180)).toBe(90)
		expect(getEffectivePageRotation({ rotate: 0 }, -90)).toBe(270)
		expect(getEffectivePageRotation(undefined, 450)).toBe(90)
	})

	it('watchDevicePixelRatio triggers callback on media query change and cleans up on dispose', () => {
		setDpr(1)
		const listeners: Array<() => void> = []
		const removeSpy = vi.fn()
		const matchMediaSpy = vi.spyOn(window, 'matchMedia').mockImplementation((query: string) => ({
			matches: true,
			media: query,
			addEventListener: vi.fn((_event: string, cb: () => void) => {
				listeners.push(cb)
			}),
			removeEventListener: removeSpy,
		} as unknown as MediaQueryList))

		try {
			const onChange = vi.fn()
			const stop = watchDevicePixelRatio(onChange)

			expect(matchMediaSpy).toHaveBeenCalledWith('(resolution: 1dppx)')
			expect(listeners).toHaveLength(1)

			// Simulate moving window to 2x display
			setDpr(2)
			listeners[0]()
			expect(onChange).toHaveBeenCalledTimes(1)
			expect(matchMediaSpy).toHaveBeenCalledWith('(resolution: 2dppx)')

			// Disposing removes listener and ignores subsequent notifications
			stop()
			expect(removeSpy).toHaveBeenCalled()
			listeners[1]?.()
			expect(onChange).toHaveBeenCalledTimes(1)
		} finally {
			matchMediaSpy.mockRestore()
		}
	})
})
