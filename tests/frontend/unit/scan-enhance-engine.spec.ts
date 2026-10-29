import { describe, expect, it } from 'vitest'
import {
	applyAdaptiveThreshold,
	applyGaussianFilter,
	applyGrayLevelMapping,
	applyOperation,
	applyPipeline,
	createGaussianKernel1D,
	createGrayLevelLut,
	createImageBuffer,
	downsampleImageBuffer,
	rgbaToGrayscale,
} from '../../../src/components/song-pdf/scan-enhance-engine'
import { ScanHistoryManager } from '../../../src/components/song-pdf/scan-history'
import type { AdaptiveThresholdOp, GaussianOp, GrayLevelMappingOp } from '../../../src/components/song-pdf/scan-types'

describe('scan-enhance-engine', () => {
	describe('Gaussian Filter', () => {
		it('generates a normalized 1D Gaussian kernel', () => {
			const kernel = createGaussianKernel1D(5)
			expect(kernel.length).toBe(5)
			// Symmetric
			expect(kernel[0]).toBeCloseTo(kernel[4], 5)
			expect(kernel[1]).toBeCloseTo(kernel[3], 5)
			// Peak at center
			expect(kernel[2]).toBeGreaterThan(kernel[1])
			// Sum to 1.0
			const sum = kernel.reduce((acc, v) => acc + v, 0)
			expect(sum).toBeCloseTo(1.0, 5)
		})

		it('preserves mean on a uniform grayscale image (energy conservation & clamping)', () => {
			const img = createImageBuffer(5, 5, 1, 100)
			const blurred = applyGaussianFilter(img, 3)

			for (let i = 0; i < blurred.data.length; i++) {
				expect(blurred.data[i]).toBe(100)
			}
		})

		it('spreads impulse energy symmetrically on single bright pixel', () => {
			const img = createImageBuffer(5, 5, 1, 0)
			// Center pixel at (2, 2)
			img.data[2 * 5 + 2] = 200

			const blurred = applyGaussianFilter(img, 3)
			// Center is highest
			const centerVal = blurred.data[2 * 5 + 2]
			expect(centerVal).toBeGreaterThan(0)
			expect(centerVal).toBeLessThan(200)

			// Adjacent pixels are symmetric and lower than center
			expect(blurred.data[2 * 5 + 1]).toBe(blurred.data[2 * 5 + 3]) // left and right
			expect(blurred.data[1 * 5 + 2]).toBe(blurred.data[3 * 5 + 2]) // top and bottom
			expect(blurred.data[2 * 5 + 1]).toBeGreaterThan(0)
		})

		it('smooths multi-channel RGB/RGBA images without shifting channels', () => {
			const img = createImageBuffer(4, 4, 4, 0)
			// Fill with red channel 200, green 100, blue 50, alpha 255
			for (let i = 0; i < 16; i++) {
				img.data[i * 4] = 200
				img.data[i * 4 + 1] = 100
				img.data[i * 4 + 2] = 50
				img.data[i * 4 + 3] = 255
			}

			const blurred = applyGaussianFilter(img, 3)
			for (let i = 0; i < 16; i++) {
				expect(blurred.data[i * 4]).toBe(200)
				expect(blurred.data[i * 4 + 1]).toBe(100)
				expect(blurred.data[i * 4 + 2]).toBe(50)
				expect(blurred.data[i * 4 + 3]).toBe(255)
			}
		})
	})

	describe('Gray Level Mapping (Curves / Levels)', () => {
		it('correctly maps cutoffs and distributes intermediate values linearly', () => {
			const lut = createGrayLevelLut(50, 150, 0, 255)

			// Below lower cutoff -> 0
			expect(lut[0]).toBe(0)
			expect(lut[30]).toBe(0)
			expect(lut[50]).toBe(0)

			// Above upper cutoff -> 255
			expect(lut[150]).toBe(255)
			expect(lut[200]).toBe(255)
			expect(lut[255]).toBe(255)

			// Halfway (100 is midway between 50 and 150) -> ~128
			expect(lut[100]).toBe(128)
		})

		it('applies gray level mapping to image buffer', () => {
			const img = createImageBuffer(3, 1, 1)
			img.data[0] = 40 // below lower cutoff
			img.data[1] = 100 // midpoint between 50 and 150
			img.data[2] = 200 // above upper cutoff

			const res = applyGrayLevelMapping(img, 50, 150)
			expect(res.data[0]).toBe(0)
			expect(res.data[1]).toBe(128)
			expect(res.data[2]).toBe(255)
		})

		it('supports custom output min and max values', () => {
			const img = createImageBuffer(3, 1, 1)
			img.data[0] = 10
			img.data[1] = 100
			img.data[2] = 240

			const res = applyGrayLevelMapping(img, 50, 150, { outMin: 20, outMax: 220 })
			expect(res.data[0]).toBe(20)
			expect(res.data[1]).toBe(120) // 20 + 0.5 * (220 - 20) = 120
			expect(res.data[2]).toBe(220)
		})
	})

	describe('Adaptive Thresholding', () => {
		it('detects local dark features across non-uniform lighting gradient', () => {
			// Create a 7x7 image with a gradient background (shadowed left: 60, bright right: 200)
			// Place a local dark mark at (5, 3) which has value 140 (darker than local background of 190)
			const img = createImageBuffer(7, 7, 1)
			for (let y = 0; y < 7; y++) {
				for (let x = 0; x < 7; x++) {
					img.data[y * 7 + x] = 60 + x * 20
				}
			}
			// Local dark dot: local background is ~180, dot is 140
			img.data[3 * 7 + 5] = 140

			const res = applyAdaptiveThreshold(img, {
				windowSize: 3,
				delta: 15,
				blackValue: 0,
				whiteValue: 255,
			})

			// The dark dot at (5, 3) is detected as ink/black
			expect(res.data[3 * 7 + 5]).toBe(0)
			// The neighboring gradient paper at (4, 3) is white
			expect(res.data[3 * 7 + 4]).toBe(255)
		})

		it('respects custom blackValue and whiteValue limits', () => {
			const img = createImageBuffer(3, 3, 1, 100)
			img.data[4] = 20 // center pixel dark

			const res = applyAdaptiveThreshold(img, {
				windowSize: 3,
				delta: 10,
				blackValue: 15, // custom lower limit
				whiteValue: 245, // custom upper limit
			})

			expect(res.data[4]).toBe(15) // center ink
			expect(res.data[0]).toBe(245) // background paper
		})

		it('enforces unconditional lowerLimit and upperLimit cutoffs', () => {
			const img = createImageBuffer(3, 3, 1, 100)
			img.data[0] = 10 // extremely dark
			img.data[1] = 240 // extremely bright

			const res = applyAdaptiveThreshold(img, {
				windowSize: 3,
				delta: 50, // very high delta, normally nothing would be black
				lowerLimit: 15, // guaranteed black cutoff
				upperLimit: 230, // guaranteed white cutoff
			})

			expect(res.data[0]).toBe(0) // forced black by lowerLimit
			expect(res.data[1]).toBe(255) // forced white by upperLimit
		})

		it('whitens paper background while preserving colored annotations in preserveColor mode', () => {
			const img = createImageBuffer(3, 3, 3, 200) // yellowed/light background
			// Place red pen marking at center (255, 0, 0)
			img.data[4 * 3] = 255
			img.data[4 * 3 + 1] = 0
			img.data[4 * 3 + 2] = 0

			const res = applyAdaptiveThreshold(img, {
				windowSize: 3,
				delta: 20,
				whiteValue: 255,
				colorMode: 'preserveColor',
			})

			// Background paper at (0, 0) is whitened to (255, 255, 255)
			expect(res.data[0]).toBe(255)
			expect(res.data[1]).toBe(255)
			expect(res.data[2]).toBe(255)

			// Red annotation at center preserves red hue
			expect(res.data[4 * 3]).toBe(255)
			expect(res.data[4 * 3 + 1]).toBe(0)
			expect(res.data[4 * 3 + 2]).toBe(0)
		})
	})

	describe('Pipeline Stacking', () => {
		it('executes a stack of operations in user-defined order', () => {
			const img = createImageBuffer(5, 5, 1, 120)
			img.data[2 * 5 + 2] = 20

			const ops: (GaussianOp | GrayLevelMappingOp | AdaptiveThresholdOp)[] = [
				{ id: '1', type: 'gaussian', size: 3, enabled: true },
				{ id: '2', type: 'grayLevelMapping', lowerCutoff: 30, upperCutoff: 200, enabled: true },
				{ id: '3', type: 'adaptiveThreshold', windowSize: 3, delta: 10, blackValue: 0, whiteValue: 255, enabled: true },
			]

			const res = applyPipeline(img, ops)
			expect(res.width).toBe(5)
			expect(res.height).toBe(5)
			expect(res.data[2 * 5 + 2]).toBe(0) // Center detected
		})

		it('skips disabled operations in the stack', () => {
			const img = createImageBuffer(3, 1, 1, 50)
			const ops: GrayLevelMappingOp[] = [
				{ id: '1', type: 'grayLevelMapping', lowerCutoff: 60, upperCutoff: 200, enabled: false },
			]

			const res = applyPipeline(img, ops)
			// Unchanged because operation was disabled
			expect(res.data[0]).toBe(50)
		})
	})

	describe('ScanHistoryManager (Undo / Redo)', () => {
		it('supports undo, redo, and history branching', () => {
			const manager = new ScanHistoryManager()
			expect(manager.canUndo()).toBe(false)
			expect(manager.canRedo()).toBe(false)

			const op1: GaussianOp = { id: 'g1', type: 'gaussian', size: 3, enabled: true }
			manager.push([op1])
			expect(manager.canUndo()).toBe(false) // Only 1 state

			const op2: GrayLevelMappingOp = { id: 'm1', type: 'grayLevelMapping', lowerCutoff: 40, upperCutoff: 220, enabled: true }
			manager.push([op1, op2])
			expect(manager.canUndo()).toBe(true)
			expect(manager.canRedo()).toBe(false)

			// Undo
			const undone = manager.undo()
			expect(undone).toHaveLength(1)
			expect(undone?.[0].id).toBe('g1')
			expect(manager.canUndo()).toBe(false)
			expect(manager.canRedo()).toBe(true)

			// Redo
			const redone = manager.redo()
			expect(redone).toHaveLength(2)
			expect(manager.canUndo()).toBe(true)
			expect(manager.canRedo()).toBe(false)

			// Undo then push branches history
			manager.undo()
			const op3: AdaptiveThresholdOp = { id: 'a1', type: 'adaptiveThreshold', windowSize: 15, delta: 10, blackValue: 0, whiteValue: 255, enabled: true }
			manager.push([op1, op3])
			expect(manager.canRedo()).toBe(false)
			expect(manager.getCurrent()).toHaveLength(2)
			expect(manager.getCurrent()[1].id).toBe('a1')
		})
	})

	describe('Area-Averaging Downsampler (downsampleImageBuffer)', () => {
		it('preserves thin black lines as anti-aliased grayscale instead of dropping them like nearest-neighbour', () => {
			// 8x8 white image with a single 1px horizontal black staff line at row y = 2
			const src = createImageBuffer(8, 8, 1, 255)
			for (let x = 0; x < 8; x++) {
				src.data[2 * 8 + x] = 0
			}

			// Downscale 4x to 2x2: top row covers y=0..3 (which includes y=2), bottom row covers y=4..7
			const dst = downsampleImageBuffer(src, 2, 2)
			expect(dst.width).toBe(2)
			expect(dst.height).toBe(2)
			// Top row integrates 1 black row (0) + 3 white rows (255) -> (3 * 255) / 4 = 191
			expect(dst.data[0]).toBe(191)
			expect(dst.data[1]).toBe(191)
			// Bottom row is pure white (255)
			expect(dst.data[2]).toBe(255)
			expect(dst.data[3]).toBe(255)
		})
	})

	describe('Soft Local Threshold & Compression Operation', () => {
		it('produces smooth grayscale transitions around the local threshold when softness > 0', () => {
			// Uniform background ~200 with a pixel slightly below threshold
			const src = createImageBuffer(5, 5, 1, 200)
			// Center pixel at (2,2) has value 185; mean ~ 199.4, delta = 10 -> threshold ~ 189.4
			src.data[2 * 5 + 2] = 185

			const hard = applyAdaptiveThreshold(src, {
				windowSize: 5,
				delta: 10,
				blackValue: 0,
				whiteValue: 255,
				softness: 0,
			})
			expect(hard.data[2 * 5 + 2]).toBe(0) // Hard binary step -> 0

			const soft = applyAdaptiveThreshold(src, {
				windowSize: 5,
				delta: 10,
				blackValue: 0,
				whiteValue: 255,
				softness: 20,
			})
			// With softness = 20, 185 is inside [threshold - 20, threshold + 20], producing an intermediate gray value
			expect(soft.data[2 * 5 + 2]).toBeGreaterThan(30)
			expect(soft.data[2 * 5 + 2]).toBeLessThan(200)
		})

		it('applies compression operation (JPEG simulation and grayscale bit-depth quantization) in pipeline', () => {
			const src = createImageBuffer(16, 16, 4, 255)
			for (let i = 0; i < 16 * 16; i++) {
				const v = (i % 16) * 16
				src.data[i * 4] = v
				src.data[i * 4 + 1] = Math.min(255, v + 20)
				src.data[i * 4 + 2] = Math.max(0, v - 20)
				src.data[i * 4 + 3] = 255
			}

			const bwOut = applyPipeline(src, [
				{ id: 'c1', type: 'compression', format: 'bw-1bit', quality: 75, enabled: true },
			])
			// Every pixel in 1-bit B&W mode must be quantized to 0 or 255 and grayscale (R=G=B)
			for (let i = 0; i < 16 * 16; i++) {
				const r = bwOut.data[i * 4]
				expect(r === 0 || r === 255).toBe(true)
				expect(bwOut.data[i * 4 + 1]).toBe(r)
				expect(bwOut.data[i * 4 + 2]).toBe(r)
			}

			const jpgGrayOut = applyPipeline(src, [
				{ id: 'c2', type: 'compression', format: 'jpeg', quality: 50, colorMode: 'grayscale', enabled: true },
			])
			for (let i = 0; i < 16 * 16; i++) {
				expect(jpgGrayOut.data[i * 4]).toBe(jpgGrayOut.data[i * 4 + 1])
				expect(jpgGrayOut.data[i * 4 + 1]).toBe(jpgGrayOut.data[i * 4 + 2])
			}
		})
	})
})

