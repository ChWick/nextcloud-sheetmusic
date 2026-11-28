/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import {
	computeDoublePageScale,
	computeFitScale,
	MAX_FIT_SCALE,
	MIN_FIT_SCALE,
	resolvePagesToRender,
} from '../../../src/utils/pdf-layout'

describe('pdf-layout', () => {
	it('computes fit-width and fit-page scales clamped to bounds', () => {
		expect(computeFitScale(600, 800, 1200, 800, 'fit-width')).toBe(2)
		expect(computeFitScale(600, 800, 1200, 800, 'fit-page')).toBe(1)
		expect(computeFitScale(10, 10, 1000, 1000, 'fit-width')).toBe(MAX_FIT_SCALE)
		expect(computeFitScale(100000, 100000, 1, 1, 'fit-page')).toBe(MIN_FIT_SCALE)
	})

	it('computes double-page scale for fit modes and proportional custom zoom', () => {
		expect(computeDoublePageScale({
			unscaledWidth: 600,
			unscaledHeight: 800,
			doubleSlotW: 600,
			containerH: 800,
			zoomMode: 'fit-page',
			effectiveScale: 1,
			docRefFitScale: 1,
		})).toBe(1)

		// Second page is twice as large unscaled -> half the fit-page scale -> scales proportionally
		expect(computeDoublePageScale({
			unscaledWidth: 1200,
			unscaledHeight: 1600,
			doubleSlotW: 600,
			containerH: 800,
			zoomMode: 'custom',
			effectiveScale: 2,
			docRefFitScale: 1,
		})).toBe(1)

		// Clamps to MAX_FIT_SCALE
		expect(computeDoublePageScale({
			unscaledWidth: 60,
			unscaledHeight: 80,
			doubleSlotW: 600,
			containerH: 800,
			zoomMode: 'custom',
			effectiveScale: 3,
			docRefFitScale: 1,
		})).toBe(MAX_FIT_SCALE)
	})

	it('resolves pages for single, continuous, double, and interleaved next-song layouts', () => {
		expect(resolvePagesToRender({
			layout: 'single',
			currentPage: 2,
			doubleSpread: [1, 2],
			orderedPages: [3, 1, 2],
			canInterleaveWithNext: false,
		})).toEqual([{ pageNum: 1, index: 2 }])

		expect(resolvePagesToRender({
			layout: 'continuous',
			currentPage: 1,
			doubleSpread: [1, 2],
			orderedPages: [2, 1],
			canInterleaveWithNext: false,
		})).toEqual([
			{ pageNum: 2, index: 1 },
			{ pageNum: 1, index: 2 },
		])

		expect(resolvePagesToRender({
			layout: 'double',
			currentPage: 2,
			doubleSpread: [2, 3],
			orderedPages: [1, 2],
			canInterleaveWithNext: true,
			nextTargetPage: 4,
		})).toEqual([
			{ pageNum: 2, index: 2 },
			{ pageNum: 4, index: 3, isNextFile: true },
		])

		// Even-page half-turn interleaved spread: next song page 1 on the left slot, last page on the right slot
		expect(resolvePagesToRender({
			layout: 'double',
			currentPage: 2,
			doubleSpread: [3, 2],
			orderedPages: [1, 2],
			canInterleaveWithNext: true,
			nextTargetPage: 1,
		})).toEqual([
			{ pageNum: 1, index: 3, isNextFile: true },
			{ pageNum: 2, index: 2 },
		])
	})
})
