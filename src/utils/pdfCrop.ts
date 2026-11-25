/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { releaseCanvas, renderPdfPageToCanvas } from './pdfjs'

export interface NormalizedCropBox {
	left: number
	top: number
	right: number
	bottom: number
}

export const FULL_PAGE_CROP: NormalizedCropBox = {
	left: 0,
	top: 0,
	right: 1,
	bottom: 1,
}

const MAX_CROP_CACHE_ENTRIES = 200
const cropBoxCache = new Map<string, NormalizedCropBox>()

export function isCropped(box?: NormalizedCropBox | null): boolean {
	if (!box) return false
	return box.left > 0.001 || box.top > 0.001 || box.right < 0.999 || box.bottom < 0.999
}

/**
 * Detect the tight normalized [0..1] ink bounding box from an RGBA pixel buffer,
 * adding a small breathing margin around the detected music notation.
 */
export function detectInkBoundsFromRgba(
	data: Uint8ClampedArray,
	width: number,
	height: number,
	options: {
		whiteThreshold?: number
		marginRatio?: number
		edgeIgnorePx?: number
	} = {},
): NormalizedCropBox {
	if (width <= 4 || height <= 4 || data.length < width * height * 4) {
		return { ...FULL_PAGE_CROP }
	}

	const whiteThreshold = options.whiteThreshold ?? 242
	const marginRatio = options.marginRatio ?? 0.015
	const edgeIgnore = Math.min(options.edgeIgnorePx ?? 1, Math.floor(Math.min(width, height) / 10))

	let minX = width
	let minY = height
	let maxX = -1
	let maxY = -1

	for (let y = edgeIgnore; y < height - edgeIgnore; y++) {
		const rowOffset = y * width * 4
		for (let x = edgeIgnore; x < width - edgeIgnore; x++) {
			const idx = rowOffset + x * 4
			const a = data[idx + 3]
			if (a < 16) continue
			const r = data[idx]
			const g = data[idx + 1]
			const b = data[idx + 2]
			if (r < whiteThreshold || g < whiteThreshold || b < whiteThreshold) {
				if (x < minX) minX = x
				if (x > maxX) maxX = x
				if (y < minY) minY = y
				if (y > maxY) maxY = y
			}
		}
	}

	if (maxX < minX || maxY < minY) {
		return { ...FULL_PAGE_CROP }
	}

	const rawLeft = minX / width
	const rawTop = minY / height
	const rawRight = (maxX + 1) / width
	const rawBottom = (maxY + 1) / height

	const left = Math.max(0, Math.round((rawLeft - marginRatio) * 10000) / 10000)
	const top = Math.max(0, Math.round((rawTop - marginRatio) * 10000) / 10000)
	const right = Math.min(1, Math.round((rawRight + marginRatio) * 10000) / 10000)
	const bottom = Math.min(1, Math.round((rawBottom + marginRatio) * 10000) / 10000)

	const cropW = right - left
	const cropH = bottom - top

	// Guard against tiny specks or negligible savings (< 4% in both dimensions)
	if (cropW < 0.1 || cropH < 0.1 || (cropW > 0.96 && cropH > 0.96)) {
		return { ...FULL_PAGE_CROP }
	}

	return { left, top, right, bottom }
}

/**
 * Probe a PDF.js page at low resolution (~240px wide) to detect its tight ink crop box.
 * Results are cached in memory by `cacheKey`.
 */
export async function detectPdfPageCropBox(
	page: any,
	rotation: number,
	cacheKey?: string,
): Promise<NormalizedCropBox> {
	if (cacheKey) {
		const cached = cropBoxCache.get(cacheKey)
		if (cached) return cached
	}

	if (typeof document === 'undefined') {
		return { ...FULL_PAGE_CROP }
	}

	let probeCanvas: HTMLCanvasElement | null = null
	try {
		const baseViewport = page.getViewport({ scale: 1.0, rotation })
		if (!baseViewport?.width || !baseViewport?.height) {
			return { ...FULL_PAGE_CROP }
		}
		const probeScale = Math.min(0.5, 240 / Math.max(baseViewport.width, baseViewport.height))
		const probeViewport = page.getViewport({ scale: Math.max(0.15, probeScale), rotation })

		probeCanvas = document.createElement('canvas')
		await renderPdfPageToCanvas(page, probeCanvas, probeViewport, {
			outputScale: 1,
			applyCssSize: false,
		})

		const ctx = probeCanvas.getContext('2d')
		if (!ctx || probeCanvas.width <= 0 || probeCanvas.height <= 0) {
			return { ...FULL_PAGE_CROP }
		}
		const imgData = ctx.getImageData(0, 0, probeCanvas.width, probeCanvas.height)
		const box = detectInkBoundsFromRgba(imgData.data, probeCanvas.width, probeCanvas.height)

		if (cacheKey) {
			if (cropBoxCache.size >= MAX_CROP_CACHE_ENTRIES) {
				const oldestKey = cropBoxCache.keys().next().value
				if (oldestKey !== undefined) cropBoxCache.delete(oldestKey)
			}
			cropBoxCache.set(cacheKey, box)
		}
		return box
	} catch {
		return { ...FULL_PAGE_CROP }
	} finally {
		releaseCanvas(probeCanvas)
	}
}

export function clearPdfCropCache(): void {
	cropBoxCache.clear()
}
