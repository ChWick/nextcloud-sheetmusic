/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	buildCropCacheKey,
	clearPdfCropCache,
	detectInkBoundsFromRgba,
	FULL_PAGE_CROP,
	getCachedPdfPageCropBox,
	hydratePdfCropCache,
	isCropped,
	rotateCropBox,
	scheduleSaveFileCropBox,
} from '../../../src/utils/pdfCrop'

vi.mock('@nextcloud/axios', () => ({
	default: {
		post: vi.fn().mockResolvedValue({ data: {} }),
	},
}))

function createRgbaPage(
	width: number,
	height: number,
	inkRect?: { x0: number; y0: number; x1: number; y1: number },
): Uint8ClampedArray {
	const data = new Uint8ClampedArray(width * height * 4)
	data.fill(255) // pure white opaque background
	if (inkRect) {
		for (let y = inkRect.y0; y < inkRect.y1; y++) {
			for (let x = inkRect.x0; x < inkRect.x1; x++) {
				const idx = (y * width + x) * 4
				data[idx] = 10
				data[idx + 1] = 10
				data[idx + 2] = 10
				data[idx + 3] = 255
			}
		}
	}
	return data
}

describe('pdfCrop', () => {
	beforeEach(() => {
		clearPdfCropCache()
	})

	it('returns full-page crop for blank white pages', () => {
		const blank = createRgbaPage(100, 140)
		const box = detectInkBoundsFromRgba(blank, 100, 140)
		expect(box).toEqual(FULL_PAGE_CROP)
		expect(isCropped(box)).toBe(false)
	})

	it('detects tight ink bounds with breathing margin around music notation', () => {
		// Ink spans [20..80) x [28..112) on a 100x140 page -> raw [0.2..0.8] x [0.2..0.8]
		const data = createRgbaPage(100, 140, { x0: 20, y0: 28, x1: 80, y1: 112 })
		const box = detectInkBoundsFromRgba(data, 100, 140)

		expect(isCropped(box)).toBe(true)
		expect(box.left).toBeCloseTo(0.185, 3)
		expect(box.top).toBeCloseTo(0.185, 3)
		expect(box.right).toBeCloseTo(0.815, 3)
		expect(box.bottom).toBeCloseTo(0.815, 3)
	})

	it('avoids cropping when notation already fills almost the entire page', () => {
		const fullInk = createRgbaPage(100, 140, { x0: 2, y0: 2, x1: 98, y1: 138 })
		const box = detectInkBoundsFromRgba(fullInk, 100, 140)
		expect(box).toEqual(FULL_PAGE_CROP)
		expect(isCropped(box)).toBe(false)
	})

	it('rotates normalized crop boxes accurately for 90, 180, and 270 degrees', () => {
		const box = { left: 0.1, top: 0.2, right: 0.7, bottom: 0.9 }
		expect(rotateCropBox(box, 0)).toEqual(box)
		expect(rotateCropBox(box, 90)).toEqual({ left: 0.1, top: 0.1, right: 0.8, bottom: 0.7 })
		expect(rotateCropBox(box, 180)).toEqual({ left: 0.3, top: 0.1, right: 0.9, bottom: 0.8 })
		expect(rotateCropBox(box, 270)).toEqual({ left: 0.2, top: 0.3, right: 0.9, bottom: 0.9 })
		// Rotating 90 then 270 returns original
		expect(rotateCropBox(rotateCropBox(box, 90), 270)).toEqual(box)
	})

	it('hydrates and retrieves cached crop boxes by file key and page', () => {
		hydratePdfCropCache('/score.pdf', {
			1: { left: 0.1, top: 0.1, right: 0.9, bottom: 0.9 },
			2: { left: 0.15, top: 0.2, right: 0.85, bottom: 0.8 },
		})
		expect(getCachedPdfPageCropBox(buildCropCacheKey('/score.pdf', 1, 0))).toEqual({
			left: 0.1,
			top: 0.1,
			right: 0.9,
			bottom: 0.9,
		})
		expect(getCachedPdfPageCropBox(buildCropCacheKey('/score.pdf', 2, 0))).toEqual({
			left: 0.15,
			top: 0.2,
			right: 0.85,
			bottom: 0.8,
		})
	})

	it('batches debounced server crop-box write-backs per file', async () => {
		vi.useFakeTimers()
		vi.mocked(axios.post).mockClear()
		try {
			scheduleSaveFileCropBox(42, 1, { left: 0.1, top: 0.1, right: 0.9, bottom: 0.9 })
			scheduleSaveFileCropBox(42, 2, { left: 0.12, top: 0.15, right: 0.88, bottom: 0.85 })
			expect(axios.post).not.toHaveBeenCalled()

			await vi.advanceTimersByTimeAsync(700)
			expect(axios.post).toHaveBeenCalledTimes(1)
			const [url, body] = vi.mocked(axios.post).mock.calls[0]
			expect(String(url)).toContain('/api/files/42/crop-boxes')
			expect(body).toEqual({
				cropBoxes: {
					1: { left: 0.1, top: 0.1, right: 0.9, bottom: 0.9 },
					2: { left: 0.12, top: 0.15, right: 0.88, bottom: 0.85 },
				},
			})
		} finally {
			vi.useRealTimers()
		}
	})
})
