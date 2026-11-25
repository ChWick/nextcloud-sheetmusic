/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import {
	detectInkBoundsFromRgba,
	FULL_PAGE_CROP,
	isCropped,
} from '../../../src/utils/pdfCrop'

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
})
