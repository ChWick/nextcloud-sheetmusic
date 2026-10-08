import { describe, expect, it } from 'vitest'
import { PDFDocument, rgb } from 'pdf-lib'
import fs from 'node:fs'
import path from 'node:path'
import type { PageCrop } from '../../../src/components/song-pdf/crop-types'

/**
 * Pure PDF Crop Engine tests.
 * This test suite uses ZERO MOCKS:
 * - Real in-memory PDF creation & manipulation via pdf-lib
 * - Real PDF file loading from disk (sample.pdf)
 * - Real mathematical coordinate transformations between screen space and PDF user space
 * - Real serialization and deserialization of modified PDF byte streams
 */
describe('PDF Crop Engine (Real PDF documents & zero mocks)', () => {
	/**
	 * Helper that applies a normalized PageCrop [0..1] to a pdf-lib PDFPage
	 * exactly as implemented in SongPdfModifier.saveCrops
	 */
	function applyCropToPage(page: any, crop: PageCrop) {
		const { x, y, width: origW, height: origH } = page.getCropBox() ?? page.getMediaBox()

		// Normalized screen coordinates: top is 0 at top edge of displayed page.
		// In PDF user space, y=0 is at the bottom, so y_new = y + origH * (1 - top - height)
		const newX = x + origW * crop.left
		const newY = y + origH * (1 - crop.top - crop.height)
		const newW = origW * crop.width
		const newH = origH * crop.height

		page.setCropBox(newX, newY, newW, newH)
	}

	it('crops a portrait page and calculates exact PDF user space CropBox', async () => {
		const doc = await PDFDocument.create()
		const page = doc.addPage([595.28, 841.89]) // Standard A4 portrait

		const crop: PageCrop = {
			left: 0.1, // 10% left margin
			top: 0.05, // 5% top margin
			width: 0.8, // 80% width (10% right margin)
			height: 0.85, // 85% height (10% bottom margin)
		}

		applyCropToPage(page, crop)

		const cropBox = page.getCropBox()
		const mediaBox = page.getMediaBox()

		// MediaBox must remain unchanged (lossless)
		expect(mediaBox.x).toBe(0)
		expect(mediaBox.y).toBe(0)
		expect(mediaBox.width).toBeCloseTo(595.28, 2)
		expect(mediaBox.height).toBeCloseTo(841.89, 2)

		// Expected CropBox:
		// newX = 0 + 595.28 * 0.1 = 59.528
		// newY = 0 + 841.89 * (1 - 0.05 - 0.85) = 841.89 * 0.10 = 84.189
		// newW = 595.28 * 0.80 = 476.224
		// newH = 841.89 * 0.85 = 715.6065
		expect(cropBox.x).toBeCloseTo(59.528, 2)
		expect(cropBox.y).toBeCloseTo(84.189, 2)
		expect(cropBox.width).toBeCloseTo(476.224, 2)
		expect(cropBox.height).toBeCloseTo(715.607, 2)
	})

	it('preserves non-zero MediaBox/CropBox offsets when cropping', async () => {
		const doc = await PDFDocument.create()
		const page = doc.addPage([600, 800])
		// Some PDFs have offset origin (e.g. cropped previously or scanned offset)
		page.setMediaBox(50, 100, 500, 700)
		page.setCropBox(50, 100, 500, 700)

		const crop: PageCrop = {
			left: 0.2,
			top: 0.1,
			width: 0.6,
			height: 0.7,
		}

		applyCropToPage(page, crop)

		const cropBox = page.getCropBox()
		// newX = 50 + 500 * 0.2 = 150
		// newY = 100 + 700 * (1 - 0.1 - 0.7) = 100 + 700 * 0.2 = 240
		// newW = 500 * 0.6 = 300
		// newH = 700 * 0.7 = 490
		expect(cropBox.x).toBeCloseTo(150, 2)
		expect(cropBox.y).toBeCloseTo(240, 2)
		expect(cropBox.width).toBeCloseTo(300, 2)
		expect(cropBox.height).toBeCloseTo(490, 2)
	})

	it('persists crops across PDF save and reload cycles', async () => {
		const doc = await PDFDocument.create()
		const page1 = doc.addPage([500, 800])
		const page2 = doc.addPage([600, 900])

		// Draw visible elements to confirm page content integrity
		page1.drawText('Page 1 Content', { x: 100, y: 700, color: rgb(0, 0, 0) })
		page2.drawText('Page 2 Content', { x: 100, y: 800, color: rgb(0, 0, 0) })

		// Crop page 1 only
		applyCropToPage(page1, { left: 0.05, top: 0.05, width: 0.9, height: 0.9 })

		// Save to binary Uint8Array
		const pdfBytes = await doc.save()
		expect(pdfBytes.byteLength).toBeGreaterThan(0)

		// Deserialization: Load the bytes afresh into a new document
		const loadedDoc = await PDFDocument.load(pdfBytes)
		expect(loadedDoc.getPageCount()).toBe(2)

		const reloadedPage1 = loadedDoc.getPage(0)
		const reloadedPage2 = loadedDoc.getPage(1)

		// Page 1 has 90% cropped width and height
		expect(reloadedPage1.getCropBox().width).toBeCloseTo(450, 2)
		expect(reloadedPage1.getCropBox().height).toBeCloseTo(720, 2)
		// Page 1 MediaBox remains the original 500 x 800
		expect(reloadedPage1.getMediaBox().width).toBeCloseTo(500, 2)
		expect(reloadedPage1.getMediaBox().height).toBeCloseTo(800, 2)

		// Page 2 was uncropped: CropBox equals MediaBox
		expect(reloadedPage2.getCropBox().width).toBeCloseTo(600, 2)
		expect(reloadedPage2.getCropBox().height).toBeCloseTo(900, 2)
	})

	it('crops real fixture sample.pdf with mixed portrait and landscape spread pages', async () => {
		const samplePath = path.resolve(__dirname, '../../fixtures/sample.pdf')
		const rawBytes = fs.readFileSync(samplePath)

		const doc = await PDFDocument.load(rawBytes)
		const totalPages = doc.getPageCount()
		expect(totalPages).toBe(8)

		// Apply 5% trim to all pages
		const trim5: PageCrop = { left: 0.05, top: 0.05, width: 0.9, height: 0.9 }
		for (let i = 0; i < totalPages; i++) {
			applyCropToPage(doc.getPage(i), trim5)
		}

		const savedBytes = await doc.save()
		const reloaded = await PDFDocument.load(savedBytes)

		expect(reloaded.getPageCount()).toBe(8)

		// Page 1 (portrait): original 595.276 -> cropped to 90%
		const p1 = reloaded.getPage(0)
		expect(p1.getCropBox().width).toBeCloseTo(595.276 * 0.9, 1)
		expect(p1.getCropBox().height).toBeCloseTo(841.89 * 0.9, 1)

		// Page 2 (landscape spread): original 1190.55 -> cropped to 90%
		const p2 = reloaded.getPage(1)
		expect(p2.getCropBox().width).toBeCloseTo(1190.55 * 0.9, 1)
		expect(p2.getCropBox().height).toBeCloseTo(841.89 * 0.9, 1)

		// MediaBox of all pages is unaltered
		expect(p1.getMediaBox().width).toBeCloseTo(595.276, 1)
		expect(p2.getMediaBox().width).toBeCloseTo(1190.55, 1)
	})
})

