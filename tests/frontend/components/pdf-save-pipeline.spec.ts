/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import { applyPdfPageEdits, formatPdfSaveSuccessMessage } from '../../../src/components/song-pdf/pdf-save-pipeline'

describe('pdf-save-pipeline', () => {
	it('applies 90-degree rotations, crops, and descending page deletions', async () => {
		const rawDoc = await PDFDocument.create()
		rawDoc.addPage([600, 800])
		rawDoc.addPage([600, 800])
		rawDoc.addPage([600, 800])
		const pdfDoc = await PDFDocument.load(await rawDoc.save())

		const onProgress = vi.fn().mockResolvedValue(undefined)
		const result = await applyPdfPageEdits({
			pdfDoc,
			activePdfDoc: null,
			totalPages: 3,
			pageRotations: [90, 0, 0],
			pageRotateCropToOriginal: {},
			pageCrops: {
				3: { left: 0.1, top: 0.1, width: 0.8, height: 0.8 },
			},
			pageEnhancements: {},
			deletedPages: new Set([2]),
			isPageEnhanced: () => false,
			yieldToBrowser: async () => {},
			onProgress,
		})

		expect(result).toEqual({
			modifiedRotations: true,
			modifiedCrops: true,
			modifiedDeletions: true,
			modifiedEnhancements: false,
			activeTotal: 2,
		})

		const reloaded = await PDFDocument.load(await pdfDoc.save())
		expect(reloaded.getPageCount()).toBe(2)
		expect(reloaded.getPage(0).getRotation().angle).toBe(90)
		const cropBox = reloaded.getPage(1).getCropBox()
		expect(cropBox.width).toBeCloseTo(480, 1)
		expect(cropBox.height).toBeCloseTo(640, 1)
		expect(onProgress).toHaveBeenCalledTimes(4)
	})

	it('formats targeted and combined success messages', () => {
		expect(formatPdfSaveSuccessMessage('Sonata', {
			modifiedRotations: true,
			modifiedCrops: false,
			modifiedDeletions: false,
			modifiedEnhancements: false,
			hasAnyJoin: false,
		})).toContain('Rotated PDF saved successfully for "Sonata"')

		expect(formatPdfSaveSuccessMessage('Sonata', {
			modifiedRotations: true,
			modifiedCrops: true,
			modifiedDeletions: false,
			modifiedEnhancements: false,
			hasAnyJoin: false,
		})).toContain('PDF changes saved successfully for "Sonata"')
	})
})
