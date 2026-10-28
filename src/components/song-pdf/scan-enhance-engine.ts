import { simulateJpegCompression } from './jpeg-encoder'
import type { AdaptiveThresholdOp, CompressionOp, GaussianOp, GrayLevelMappingOp, ImageBuffer, ScanOperation } from './scan-types'

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
	softness?: number
}

/**
 * Applies Adaptive Thresholding using an O(1) Integral Image for local neighborhood mean.
 * Supports custom lower and upper limits for white/black values, soft local thresholding,
 * and color preservation mode.
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
	const softness = Math.max(0, options.softness ?? 0)

	const { width: w, height: h, channels: ch } = img
	const gray = img.channels === 1 ? img : rgbaToGrayscale(img)
	const grayData = gray.data
	const integral = computeIntegralImage(grayData, w, h)
	const stride = w + 1

	const dst = cloneImageBuffer(img)
	const dstData = dst.data
	const fgScale = 1 - blackValue / 255

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

			// t in [0, 1]: 0 = full foreground (blackValue), 1 = full paper background (whiteValue).
			// When softness > 0, pixels at or above threshold (v >= mean - delta) remain 100% pure
			// paper white (t = 1), while pixels in [threshold - softness, threshold] transition
			// smoothly from blackValue (t = 0) to whiteValue (t = 1). This gives anti-aliased soft
			// edges on notes/staff lines without leaking scanner noise into the paper background.
			let t: number
			if (lowerLimit !== undefined && v <= lowerLimit) {
				t = 0
			} else if (upperLimit !== undefined && v >= upperLimit) {
				t = 1
			} else if (softness <= 0) {
				t = v <= threshold ? 0 : 1
			} else if (v >= threshold) {
				t = 1
			} else {
				t = Math.min(1, Math.max(0, (v - (threshold - softness)) / softness))
			}

			const outVal = Math.round(blackValue + t * (whiteValue - blackValue))
			const idx = (yOffset + x) * ch

			if (colorMode === 'preserveColor' && ch >= 3) {
				if (t <= 0) {
					// Foreground: keep original color or scale by black value ratio
					if (blackValue !== 0) {
						dstData[idx] = Math.round(dstData[idx] * fgScale)
						dstData[idx + 1] = Math.round(dstData[idx + 1] * fgScale)
						dstData[idx + 2] = Math.round(dstData[idx + 2] * fgScale)
					}
				} else if (t >= 1) {
					// Paper background: whiten to whiteValue
					dstData[idx] = whiteValue
					dstData[idx + 1] = whiteValue
					dstData[idx + 2] = whiteValue
				} else {
					// Smooth transition between foreground color and paper whiteValue
					const fgR = dstData[idx] * fgScale
					const fgG = dstData[idx + 1] * fgScale
					const fgB = dstData[idx + 2] * fgScale
					dstData[idx] = Math.round(fgR * (1 - t) + whiteValue * t)
					dstData[idx + 1] = Math.round(fgG * (1 - t) + whiteValue * t)
					dstData[idx + 2] = Math.round(fgB * (1 - t) + whiteValue * t)
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
 * Applies image compression / bit-depth quantization for preview and pipeline output.
 */
export function applyCompression(img: ImageBuffer, op: CompressionOp): ImageBuffer {
	if (op.format === 'jpeg') {
		const isGray = (op.colorMode ?? 'grayscale') === 'grayscale'
		return simulateJpegCompression(img, op.quality ?? 75, isGray)
	}

	if (op.format === 'rgb-24bit') {
		return cloneImageBuffer(img)
	}

	// Grayscale bit-depth quantization ('grayscale-8bit' | 'grayscale-4bit' | 'grayscale-2bit' | 'bw-1bit')
	const bitDepth =
		op.format === 'bw-1bit'
			? 1
			: op.format === 'grayscale-2bit'
				? 2
				: op.format === 'grayscale-4bit'
					? 4
					: 8
	const levels = (1 << bitDepth) - 1
	const dst = cloneImageBuffer(img)
	const dstData = dst.data
	const ch = dst.channels
	const pixelCount = dst.width * dst.height

	for (let i = 0; i < pixelCount; i++) {
		const idx = i * ch
		const lum =
			ch >= 3
				? 0.299 * dstData[idx] + 0.587 * dstData[idx + 1] + 0.114 * dstData[idx + 2]
				: dstData[idx]
		const q =
			bitDepth === 8
				? Math.round(lum)
				: Math.round((Math.round((lum / 255) * levels) / levels) * 255)
		dstData[idx] = q
		if (ch >= 3) {
			dstData[idx + 1] = q
			dstData[idx + 2] = q
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
				softness: op.softness,
			})
		case 'compression':
			return applyCompression(img, op)
		default:
			return cloneImageBuffer(img)
	}
}

export interface ApplyPipelineOptions {
	/**
	 * When true, skips the lossy DCT preview simulation for JPEG compression operations
	 * because the final PDF exporter encodes the buffer directly to a JPEG stream.
	 */
	skipJpegSimulation?: boolean
}

/**
 * Sequentially executes a stack of operations on an ImageBuffer.
 */
export function applyPipeline(
	img: ImageBuffer,
	operations: ScanOperation[],
	options?: ApplyPipelineOptions,
): ImageBuffer {
	let current = img
	for (const op of operations) {
		if (op.enabled) {
			if (options?.skipJpegSimulation && op.type === 'compression' && op.format === 'jpeg') {
				if ((op.colorMode ?? 'grayscale') === 'grayscale') {
					current = applyCompression(current, {
						...op,
						format: 'grayscale-8bit',
					})
				}
				continue
			}
			current = applyOperation(current, op)
		}
	}
	return current
}

/**
 * High-quality separable 2-pass area-averaging (box-integration) image resampler.
 * Unlike nearest-neighbour or single-pass 2x2 bilinear sampling (which drops thin
 * staff lines and creates severe aliasing when downscaling high-DPI sheet music scans),
 * this integrates the exact area-weighted contribution of every source pixel overlapping
 * each destination pixel's footprint.
 */
export function downsampleImageBuffer(
	src: ImageBuffer,
	targetWidth: number,
	targetHeight: number,
): ImageBuffer {
	const srcW = src.width
	const srcH = src.height
	const ch = src.channels
	const dstW = Math.max(1, Math.round(targetWidth))
	const dstH = Math.max(1, Math.round(targetHeight))

	if (dstW === srcW && dstH === srcH) {
		return cloneImageBuffer(src)
	}

	const srcData = src.data
	const horiz = new Float32Array(dstW * srcH * ch)

	// Pass 1: Horizontal resampling (srcW -> dstW)
	if (dstW === srcW) {
		for (let i = 0; i < srcData.length; i++) {
			horiz[i] = srcData[i]
		}
	} else if (dstW < srcW) {
		const scaleX = srcW / dstW
		const invScaleX = 1 / scaleX
		const x0Arr = new Float32Array(dstW)
		const x1Arr = new Float32Array(dstW)
		const ix0Arr = new Int32Array(dstW)
		const ix1Arr = new Int32Array(dstW)

		for (let dx = 0; dx < dstW; dx++) {
			const x0 = dx * scaleX
			const x1 = (dx + 1) * scaleX
			x0Arr[dx] = x0
			x1Arr[dx] = x1
			ix0Arr[dx] = Math.floor(x0)
			ix1Arr[dx] = Math.min(srcW - 1, Math.ceil(x1) - 1)
		}

		for (let y = 0; y < srcH; y++) {
			const srcRow = y * srcW * ch
			const dstRow = y * dstW * ch
			for (let dx = 0; dx < dstW; dx++) {
				const x0 = x0Arr[dx]
				const x1 = x1Arr[dx]
				const ix0 = ix0Arr[dx]
				const ix1 = ix1Arr[dx]
				const outBase = dstRow + dx * ch

				for (let sx = ix0; sx <= ix1; sx++) {
					const w = (sx + 1 < x1 ? sx + 1 : x1) - (sx > x0 ? sx : x0)
					const inBase = srcRow + sx * ch
					for (let c = 0; c < ch; c++) {
						horiz[outBase + c] += srcData[inBase + c] * w
					}
				}
				for (let c = 0; c < ch; c++) {
					horiz[outBase + c] *= invScaleX
				}
			}
		}
	} else {
		// Bilinear horizontal upscale if dstW > srcW
		const scaleX = (srcW - 1) / Math.max(1, dstW - 1)
		for (let y = 0; y < srcH; y++) {
			const srcRow = y * srcW * ch
			const dstRow = y * dstW * ch
			for (let dx = 0; dx < dstW; dx++) {
				const gx = dx * scaleX
				const ix = Math.floor(gx)
				const fx = gx - ix
				const ix1 = Math.min(srcW - 1, ix + 1)
				const outBase = dstRow + dx * ch
				const in0 = srcRow + ix * ch
				const in1 = srcRow + ix1 * ch
				for (let c = 0; c < ch; c++) {
					horiz[outBase + c] = srcData[in0 + c] * (1 - fx) + srcData[in1 + c] * fx
				}
			}
		}
	}

	// Pass 2: Vertical resampling (srcH -> dstH)
	const dst = createImageBuffer(dstW, dstH, ch)
	const dstData = dst.data

	if (dstH === srcH) {
		for (let i = 0; i < dstData.length; i++) {
			dstData[i] = Math.round(horiz[i])
		}
	} else if (dstH < srcH) {
		const scaleY = srcH / dstH
		const invScaleY = 1 / scaleY
		const rowStride = dstW * ch
		const acc = new Float32Array(rowStride)

		for (let dy = 0; dy < dstH; dy++) {
			const y0 = dy * scaleY
			const y1 = (dy + 1) * scaleY
			const iy0 = Math.floor(y0)
			const iy1 = Math.min(srcH - 1, Math.ceil(y1) - 1)
			acc.fill(0)

			for (let sy = iy0; sy <= iy1; sy++) {
				const w = (sy + 1 < y1 ? sy + 1 : y1) - (sy > y0 ? sy : y0)
				const srcRow = sy * rowStride
				for (let i = 0; i < rowStride; i++) {
					acc[i] += horiz[srcRow + i] * w
				}
			}

			const dstRow = dy * rowStride
			for (let i = 0; i < rowStride; i++) {
				dstData[dstRow + i] = Math.round(acc[i] * invScaleY)
			}
		}
	} else {
		// Bilinear vertical upscale if dstH > srcH
		const scaleY = (srcH - 1) / Math.max(1, dstH - 1)
		const rowStride = dstW * ch
		for (let dy = 0; dy < dstH; dy++) {
			const gy = dy * scaleY
			const iy = Math.floor(gy)
			const fy = gy - iy
			const iy1 = Math.min(srcH - 1, iy + 1)
			const r0 = iy * rowStride
			const r1 = iy1 * rowStride
			const dstRow = dy * rowStride
			for (let i = 0; i < rowStride; i++) {
				dstData[dstRow + i] = Math.round(horiz[r0 + i] * (1 - fy) + horiz[r1 + i] * fy)
			}
		}
	}

	return dst
}

