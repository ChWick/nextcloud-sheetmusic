import { describe, expect, it } from 'vitest'
import { PDFDocument, rgb, degrees } from 'pdf-lib'
import fs from 'node:fs'
import path from 'node:path'
import { createCanvas } from '@napi-rs/canvas'
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs'
import { computeCropBoxForRotation, type PageCrop } from '../../../src/components/song-pdf/crop-types'

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

	it('supports cropping and rotating in one step across all 4 rotation angles', async () => {
		const doc = await PDFDocument.create()
		const p0 = doc.addPage([200, 400])
		const p90 = doc.addPage([200, 400])
		const p180 = doc.addPage([200, 400])
		const p270 = doc.addPage([200, 400])

		p0.setRotation(degrees(0))
		p90.setRotation(degrees(90))
		p180.setRotation(degrees(180))
		p270.setRotation(degrees(270))

		// User crops 10% left, 20% top, 60% width, 50% height on the displayed canvas
		const crop: PageCrop = { left: 0.1, top: 0.2, width: 0.6, height: 0.5 }

		for (const [page, rot] of [[p0, 0], [p90, 90], [p180, 180], [p270, 270]] as const) {
			const { x, y, width: origW, height: origH } = page.getCropBox()
			const box = computeCropBoxForRotation(x, y, origW, origH, crop, rot)
			page.setCropBox(box.x, box.y, box.width, box.height)
		}

		const savedBytes = await doc.save()
		const reloaded = await PDFDocument.load(savedBytes)
		expect(reloaded.getPageCount()).toBe(4)

		// Page 0 (rot 0):
		const r0 = reloaded.getPage(0)
		expect(r0.getRotation().angle).toBe(0)
		expect(r0.getCropBox()).toEqual({ x: 20, y: 120, width: 120, height: 200 })

		// Page 1 (rot 90):
		const r90 = reloaded.getPage(1)
		expect(r90.getRotation().angle).toBe(90)
		expect(r90.getCropBox()).toEqual({ x: 40, y: 40, width: 100, height: 240 })

		// Page 2 (rot 180):
		const r180 = reloaded.getPage(2)
		expect(r180.getRotation().angle).toBe(180)
		expect(r180.getCropBox()).toEqual({ x: 60, y: 80, width: 120, height: 200 })

		// Page 3 (rot 270):
		const r270 = reloaded.getPage(3)
		expect(r270.getRotation().angle).toBe(270)
		expect(r270.getCropBox()).toEqual({ x: 60, y: 120, width: 100, height: 240 })
	})

	it('crops top white / bottom black PDF correctly with and without rotation, verifying resulting color', async () => {
		async function renderPdfPageToCanvas(pdfBytes: Uint8Array, pageNum = 1) {
			const loaded = await pdfjs.getDocument({ data: pdfBytes.slice() }).promise
			const page = await loaded.getPage(pageNum)
			const vp = page.getViewport({ scale: 1.0 })
			const canvas = createCanvas(Math.floor(vp.width), Math.floor(vp.height))
			const ctx = canvas.getContext('2d')
			await page.render({ canvasContext: ctx, viewport: vp }).promise
			return { canvas, ctx, vp }
		}

		// Helper to create a PDF page where top half is WHITE and bottom half is BLACK in user coordinates
		async function createTopWhiteBottomBlackPdf(fileRotation = 0) {
			const doc = await PDFDocument.create()
			const page = doc.addPage([100, 100])
			// bottom half: y 0..50 is BLACK
			page.drawRectangle({ x: 0, y: 0, width: 100, height: 50, color: rgb(0, 0, 0) })
			// top half: y 50..100 is WHITE
			page.drawRectangle({ x: 0, y: 50, width: 100, height: 50, color: rgb(1, 1, 1) })
			if (fileRotation !== 0) {
				page.setRotation(degrees(fileRotation))
			}
			return await doc.save()
		}

		// 1. Without rotation (0°):
		// On screen, top half is WHITE, bottom half is BLACK.
		// Crop top half (top: 0, height: 0.5):
		{
			const rawPdf = await createTopWhiteBottomBlackPdf(0)
			const doc = await PDFDocument.load(rawPdf)
			const page = doc.getPage(0)
			const crop: PageCrop = { left: 0, top: 0, width: 1, height: 0.5 }
			const { x, y, width: origW, height: origH } = page.getCropBox()
			const box = computeCropBoxForRotation(x, y, origW, origH, crop, 0)
			page.setCropBox(box.x, box.y, box.width, box.height)
			const saved = await doc.save()

			const rendered = await renderPdfPageToCanvas(saved)
			const pixel = rendered.ctx.getImageData(50, 25, 1, 1).data
			// Should be WHITE: r=255, g=255, b=255
			expect(pixel[0]).toBeGreaterThan(200)
			expect(pixel[1]).toBeGreaterThan(200)
			expect(pixel[2]).toBeGreaterThan(200)
		}

		// 2. With 180° rotation:
		// User rotates 180° first.
		// On screen, page is upside down: top half is BLACK, bottom half is WHITE.
		// User crops the displayed top half (crop: top: 0, height: 0.5).
		// Result should be BLACK!
		{
			const rawPdf = await createTopWhiteBottomBlackPdf(0)
			const doc = await PDFDocument.load(rawPdf)
			const page = doc.getPage(0)
			page.setRotation(degrees(180))
			const crop: PageCrop = { left: 0, top: 0, width: 1, height: 0.5 }
			const { x, y, width: origW, height: origH } = page.getCropBox()
			const box = computeCropBoxForRotation(x, y, origW, origH, crop, 180)
			page.setCropBox(box.x, box.y, box.width, box.height)
			const saved = await doc.save()

			const rendered = await renderPdfPageToCanvas(saved)
			const pixel = rendered.ctx.getImageData(50, 25, 1, 1).data
			// Should be BLACK: r=0, g=0, b=0
			expect(pixel[0]).toBeLessThan(50)
			expect(pixel[1]).toBeLessThan(50)
			expect(pixel[2]).toBeLessThan(50)
		}

		// 3. With PDF file orientation (file already rotated 180° in metadata):
		// The file has page.rotate = 180°.
		// In the viewer, bottom black is at top, top white is at bottom.
		// User rotates 180° to bring it upright (netAngle = 0°).
		// On screen, top half is now WHITE.
		// User crops top half (crop: top: 0, height: 0.5).
		// Result should be WHITE!
		{
			const rawPdf = await createTopWhiteBottomBlackPdf(180)
			const doc = await PDFDocument.load(rawPdf)
			const page = doc.getPage(0)
			const currentAngle = page.getRotation().angle // 180
			const userRotation = 180
			const netAngle = (currentAngle + userRotation) % 360 // 0
			page.setRotation(degrees(netAngle))
			const crop: PageCrop = { left: 0, top: 0, width: 1, height: 0.5 }
			const { x, y, width: origW, height: origH } = page.getCropBox()
			const box = computeCropBoxForRotation(x, y, origW, origH, crop, netAngle)
			page.setCropBox(box.x, box.y, box.width, box.height)
			const saved = await doc.save()

			const rendered = await renderPdfPageToCanvas(saved)
			const pixel = rendered.ctx.getImageData(50, 25, 1, 1).data
			// Should be WHITE: r=255, g=255, b=255
			expect(pixel[0]).toBeGreaterThan(200)
			expect(pixel[1]).toBeGreaterThan(200)
			expect(pixel[2]).toBeGreaterThan(200)
		}
	})
})

