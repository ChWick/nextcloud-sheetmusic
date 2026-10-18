import type { AdaptiveThresholdOp, GaussianOp, GrayLevelMappingOp, ImageBuffer, ScanOperation } from './scan-types'

export function createImageBuffer(width: number, height: number, channels: 1 | 3 | 4 = 1, fill = 0): ImageBuffer {
	const data = new Uint8ClampedArray(width * height * channels)
	if (fill !== 0) {
		data.fill(fill)
	}
	return { width, height, channels, data }
}

export function cloneImageBuffer(src: ImageBuffer): ImageBuffer {
	const copy = new Uint8ClampedArray(src.data.length)
	copy.set(src.data)
	return {
		width: src.width,
		height: src.height,
		channels: src.channels,
		data: copy,
	}
}

/**
 * Converts an ImageBuffer to single-channel Grayscale (luminance).
 */
export function rgbaToGrayscale(src: ImageBuffer): ImageBuffer {
	if (src.channels === 1) {
		return cloneImageBuffer(src)
	}

	const dst = createImageBuffer(src.width, src.height, 1)
	const srcData = src.data
	const dstData = dst.data
	const pixelCount = src.width * src.height
	const ch = src.channels

	for (let i = 0; i < pixelCount; i++) {
		const idx = i * ch
		const r = srcData[idx]
		const g = srcData[idx + 1]
		const b = srcData[idx + 2]
		dstData[i] = Math.round(0.299 * r + 0.587 * g + 0.114 * b)
	}

	return dst
}

/**
 * Converts an ImageBuffer to 4-channel RGBA.
 */
export function grayscaleToRgba(src: ImageBuffer): ImageBuffer {
	if (src.channels === 4) {
		return cloneImageBuffer(src)
	}

	const dst = createImageBuffer(src.width, src.height, 4)
	const srcData = src.data
	const dstData = dst.data
	const pixelCount = src.width * src.height
	const ch = src.channels

	for (let i = 0; i < pixelCount; i++) {
		const outIdx = i * 4
		if (ch === 1) {
			const v = srcData[i]
			dstData[outIdx] = v
			dstData[outIdx + 1] = v
			dstData[outIdx + 2] = v
			dstData[outIdx + 3] = 255
		} else if (ch === 3) {
			const inIdx = i * 3
			dstData[outIdx] = srcData[inIdx]
			dstData[outIdx + 1] = srcData[inIdx + 1]
			dstData[outIdx + 2] = srcData[inIdx + 2]
			dstData[outIdx + 3] = 255
		}
	}

	return dst
}

/**
 * Converts standard HTML5 ImageData to ImageBuffer.
 */
export function imageDataToImageBuffer(imageData: ImageData): ImageBuffer {
	const copy = new Uint8ClampedArray(imageData.data.length)
	copy.set(imageData.data)
	return {
		width: imageData.width,
		height: imageData.height,
		channels: 4,
		data: copy,
	}
}

/**
 * Converts ImageBuffer to HTML5 ImageData (always RGBA).
 */
export function imageBufferToImageData(buffer: ImageBuffer): ImageData {
	const rgba = buffer.channels === 4 ? buffer : grayscaleToRgba(buffer)
	return new ImageData(new Uint8ClampedArray(rgba.data), rgba.width, rgba.height)
}

/**
 * Creates 1D normalized Gaussian kernel.
 */
export function createGaussianKernel1D(size: number): Float32Array {
	const kernelSize = Math.max(1, Math.floor(size) | 1) // Ensure odd integer >= 1
	const r = Math.floor(kernelSize / 2)
	const sigma = Math.max(r / 2, 0.8)
	const kernel = new Float32Array(kernelSize)
	let sum = 0

	for (let i = -r; i <= r; i++) {
		const v = Math.exp(-(i * i) / (2 * sigma * sigma))
		kernel[i + r] = v
		sum += v
	}

	for (let i = 0; i < kernelSize; i++) {
		kernel[i] /= sum
	}

	return kernel
}

/**
 * Applies a Gaussian filter using separable 1D horizontal and vertical convolution passes.
 * Operates on Grayscale, RGB, or RGBA buffers with edge clamping.
 */
export function applyGaussianFilter(img: ImageBuffer, size: number): ImageBuffer {
	const kernelSize = Math.max(1, Math.floor(size) | 1)
	if (kernelSize <= 1) {
		return cloneImageBuffer(img)
	}

	const kernel = createGaussianKernel1D(kernelSize)
	const r = Math.floor(kernelSize / 2)
	const { width: w, height: h, channels: ch } = img
	const srcData = img.data

	// Temporary buffer for horizontal pass
	const intermediate = new Float32Array(w * h * ch)
	const dst = createImageBuffer(w, h, ch)
	const dstData = dst.data

	// 1. Horizontal Convolution Pass
	for (let y = 0; y < h; y++) {
		const rowOffset = y * w
		for (let x = 0; x < w; x++) {
			for (let c = 0; c < ch; c++) {
				// For RGBA, preserve alpha without blurring if desired, or blur RGB
				if (c === 3 && ch === 4) {
					intermediate[(rowOffset + x) * ch + c] = srcData[(rowOffset + x) * ch + c]
					continue
				}

				let acc = 0
				for (let k = -r; k <= r; k++) {
					const clampedX = Math.min(w - 1, Math.max(0, x + k))
					acc += srcData[(rowOffset + clampedX) * ch + c] * kernel[k + r]
				}
				intermediate[(rowOffset + x) * ch + c] = acc
			}
		}
	}

	// 2. Vertical Convolution Pass
	for (let x = 0; x < w; x++) {
		for (let y = 0; y < h; y++) {
			for (let c = 0; c < ch; c++) {
				if (c === 3 && ch === 4) {
					dstData[(y * w + x) * ch + c] = intermediate[(y * w + x) * ch + c]
					continue
				}

				let acc = 0
				for (let k = -r; k <= r; k++) {
					const clampedY = Math.min(h - 1, Math.max(0, y + k))
					acc += intermediate[(clampedY * w + x) * ch + c] * kernel[k + r]
				}
				dstData[(y * w + x) * ch + c] = Math.round(acc)
			}
		}
	}

	return dst
}

/**
 * Precomputes 256-entry Look-Up Table (LUT) for Gray Level Mapping (similar to GIMP's curve/levels editor).
 * Values <= lowerCutoff become outMin.
 * Values >= upperCutoff become outMax.
 * Values in between are linearly distributed.
 */
export function createGrayLevelLut(
	lowerCutoff: number,
	upperCutoff: number,
	outMin = 0,
	outMax = 255,
): Uint8ClampedArray {
	const L = Math.min(255, Math.max(0, Math.round(lowerCutoff)))
	const U = Math.min(255, Math.max(0, Math.round(upperCutoff)))
	const lut = new Uint8ClampedArray(256)
	const range = Math.max(1, U - L)

	for (let i = 0; i < 256; i++) {
		if (i <= L) {
			lut[i] = outMin
		} else if (i >= U) {
			lut[i] = outMax
		} else {
			const fraction = (i - L) / range
			lut[i] = Math.round(outMin + fraction * (outMax - outMin))
		}
	}

	return lut
}

/**
 * Applies Gray Level Mapping (cutoffs & linear distribution) to an ImageBuffer.
 */
export function applyGrayLevelMapping(
	img: ImageBuffer,
	lowerCutoff: number,
	upperCutoff: number,
	options?: {
		outMin?: number
		outMax?: number
		colorMode?: 'color' | 'grayscale'
	},
): ImageBuffer {
	const outMin = options?.outMin ?? 0
	const outMax = options?.outMax ?? 255
	const colorMode = options?.colorMode ?? 'color'
	const lut = createGrayLevelLut(lowerCutoff, upperCutoff, outMin, outMax)

	if (colorMode === 'grayscale' && img.channels > 1) {
		const gray = rgbaToGrayscale(img)
		const dstData = gray.data
		for (let i = 0; i < dstData.length; i++) {
			dstData[i] = lut[dstData[i]]
		}
		// Return with matching channels as input for pipeline consistency
		return img.channels === 4 ? grayscaleToRgba(gray) : gray
	}

	const dst = cloneImageBuffer(img)
	const data = dst.data
	const ch = dst.channels
	const totalPixels = dst.width * dst.height

	for (let i = 0; i < totalPixels; i++) {
		const idx = i * ch
		data[idx] = lut[data[idx]] // R or Gray
		if (ch >= 3) {
			data[idx + 1] = lut[data[idx + 1]] // G
			data[idx + 2] = lut[data[idx + 2]] // B
			// Alpha (ch === 4) is left untouched
		}
	}

	return dst
}

/**
 * Computes 2D Integral Image (Summed-Area Table) for single-channel luminance data.
 * Size is (w + 1) * (h + 1).
 */
export function computeIntegralImage(luminanceData: Uint8ClampedArray | Uint8Array, w: number, h: number): Float64Array {
	const stride = w + 1
	const integral = new Float64Array(stride * (h + 1))

	for (let y = 0; y < h; y++) {
		let rowSum = 0
		const inRowOffset = y * w
		const outRowOffset = (y + 1) * stride
		const prevRowOffset = y * stride

		for (let x = 0; x < w; x++) {
			rowSum += luminanceData[inRowOffset + x]
			integral[outRowOffset + x + 1] = rowSum + integral[prevRowOffset + x + 1]
		}
	}

	return integral
}

export interface AdaptiveThresholdOptions {
	windowSize: number
	delta: number
	blackValue?: number
	whiteValue?: number
	lowerLimit?: number
	upperLimit?: number
	colorMode?: 'binarize' | 'preserveColor'
}

/**
 * Applies Adaptive Thresholding using an O(1) Integral Image for local neighborhood mean.
 * Supports custom lower and upper limits for white/black values and color preservation mode.
 */
export function applyAdaptiveThreshold(img: ImageBuffer, options: AdaptiveThresholdOptions): ImageBuffer {
	const windowSize = Math.max(3, Math.floor(options.windowSize) | 1)
	const r = Math.floor(windowSize / 2)
	const delta = options.delta
	const blackValue = options.blackValue ?? 0
	const whiteValue = options.whiteValue ?? 255
	const lowerLimit = options.lowerLimit
	const upperLimit = options.upperLimit
	const colorMode = options.colorMode ?? 'binarize'

	const { width: w, height: h, channels: ch } = img
	const gray = img.channels === 1 ? img : rgbaToGrayscale(img)
	const grayData = gray.data
	const integral = computeIntegralImage(grayData, w, h)
	const stride = w + 1

	const dst = cloneImageBuffer(img)
	const dstData = dst.data

	for (let y = 0; y < h; y++) {
		const y1 = Math.max(0, y - r)
		const y2 = Math.min(h - 1, y + r)
		const yOffset = y * w

		for (let x = 0; x < w; x++) {
			const x1 = Math.max(0, x - r)
			const x2 = Math.min(w - 1, x + r)

			const count = (x2 - x1 + 1) * (y2 - y1 + 1)
			const sum =
				integral[(y2 + 1) * stride + (x2 + 1)] -
				integral[y1 * stride + (x2 + 1)] -
				integral[(y2 + 1) * stride + x1] +
				integral[y1 * stride + x1]

			const mean = sum / count
			const threshold = mean - delta
			const v = grayData[yOffset + x]

			let isBlack: boolean
			if (lowerLimit !== undefined && v <= lowerLimit) {
				isBlack = true
			} else if (upperLimit !== undefined && v >= upperLimit) {
				isBlack = false
			} else {
				isBlack = v <= threshold
			}

			const outVal = isBlack ? blackValue : whiteValue
			const idx = (yOffset + x) * ch

			if (colorMode === 'preserveColor' && ch >= 3) {
				if (isBlack) {
					// Foreground: keep original color or scale by black value ratio
					if (blackValue === 0) {
						// Keep original color
						// (already set from clone)
					} else {
						// Tint towards blackValue
						const scale = 1 - blackValue / 255
						dstData[idx] = Math.round(dstData[idx] * scale)
						dstData[idx + 1] = Math.round(dstData[idx + 1] * scale)
						dstData[idx + 2] = Math.round(dstData[idx + 2] * scale)
					}
				} else {
					// Paper background: whiten to whiteValue
					dstData[idx] = whiteValue
					dstData[idx + 1] = whiteValue
					dstData[idx + 2] = whiteValue
				}
			} else {
				dstData[idx] = outVal
				if (ch >= 3) {
					dstData[idx + 1] = outVal
					dstData[idx + 2] = outVal
				}
			}
		}
	}

	return dst
}

/**
 * Executes a single ScanOperation on an ImageBuffer.
 */
export function applyOperation(img: ImageBuffer, op: ScanOperation): ImageBuffer {
	if (!op.enabled) {
		return cloneImageBuffer(img)
	}

	switch (op.type) {
		case 'gaussian':
			return applyGaussianFilter(img, op.size)
		case 'grayLevelMapping':
			return applyGrayLevelMapping(img, op.lowerCutoff, op.upperCutoff, {
				outMin: op.outMin,
				outMax: op.outMax,
				colorMode: op.colorMode,
			})
		case 'adaptiveThreshold':
			return applyAdaptiveThreshold(img, {
				windowSize: op.windowSize,
				delta: op.delta,
				blackValue: op.blackValue,
				whiteValue: op.whiteValue,
				lowerLimit: op.lowerLimit,
				upperLimit: op.upperLimit,
				colorMode: op.colorMode,
			})
		default:
			return cloneImageBuffer(img)
	}
}

/**
 * Sequentially executes a stack of operations on an ImageBuffer.
 */
export function applyPipeline(img: ImageBuffer, operations: ScanOperation[]): ImageBuffer {
	let current = img
	for (const op of operations) {
		if (op.enabled) {
			current = applyOperation(current, op)
		}
	}
	return current
}

