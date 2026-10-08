import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import {
	calculateRotatedBoundingBox,
	executeRotatePage,
} from '../../../src/components/song-pdf/pdf-rotate-engine'

const fixturePdfPath = path.resolve(__dirname, '../../fixtures/sample.pdf')
const samplePdfBytes = fs.readFileSync(fixturePdfPath)

describe('pdf-rotate-engine', () => {
	describe('calculateRotatedBoundingBox', () => {
		it('calculates bounding box for 0 degrees', () => {
			const res = calculateRotatedBoundingBox(100, 200, 0)
			expect(res.bboxWidth).toBeCloseTo(100, 1)
			expect(res.bboxHeight).toBeCloseTo(200, 1)
			expect(res.minX).toBeCloseTo(0, 1)
			expect(res.minY).toBeCloseTo(0, 1)
			expect(res.pdfAngle).toBe(0)
		})

		it('calculates bounding box for 90 degrees CW (swaps width and height)', () => {
			const res = calculateRotatedBoundingBox(100, 200, 90)
			expect(res.bboxWidth).toBeCloseTo(200, 1)
			expect(res.bboxHeight).toBeCloseTo(100, 1)
			expect(res.pdfAngle).toBe(-90)
		})

		it('calculates bounding box for 180 degrees CW', () => {
			const res = calculateRotatedBoundingBox(100, 200, 180)
			expect(res.bboxWidth).toBeCloseTo(100, 1)
			expect(res.bboxHeight).toBeCloseTo(200, 1)
			expect(res.pdfAngle).toBe(-180)
		})

		it('calculates bounding box for 270 degrees CW (90 CCW)', () => {
			const res = calculateRotatedBoundingBox(100, 200, 270)
			expect(res.bboxWidth).toBeCloseTo(200, 1)
			expect(res.bboxHeight).toBeCloseTo(100, 1)
			expect(res.pdfAngle).toBe(-270)
		})

		it('calculates bounding box for 45 degrees rotation', () => {
			const res = calculateRotatedBoundingBox(100, 100, 45)
			const expected = 100 * Math.SQRT2
			expect(res.bboxWidth).toBeCloseTo(expected, 1)
			expect(res.bboxHeight).toBeCloseTo(expected, 1)
		})

		it('calculates bounding box for small arbitrary deskew angle (+2.5° CW)', () => {
			const res = calculateRotatedBoundingBox(595.28, 841.89, 2.5)
			// Small rotation increases bounding box slightly
			expect(res.bboxWidth).toBeGreaterThan(595.28)
			expect(res.bboxHeight).toBeGreaterThan(841.89)
			expect(res.pdfAngle).toBe(-2.5)
		})
	})

	describe('executeRotatePage', () => {
		it('rotates page by 90 degrees using standard PDF setRotation', async () => {
			const doc = await PDFDocument.load(samplePdfBytes)
			const origPage = doc.getPage(0)
			expect(origPage.getRotation()?.angle ?? 0).toBe(0)

			await executeRotatePage(doc, {
				pageIndex: 0,
				angleDegrees: 90,
			})

			expect(doc.getPageCount()).toBe(8)
			expect(doc.getPage(0).getRotation().angle).toBe(90)

			const saved = await doc.save()
			const reloaded = await PDFDocument.load(saved)
			expect(reloaded.getPage(0).getRotation().angle).toBe(90)
		})

		it('rotates page by arbitrary angle (+3.5° CW) and updates page dimensions', async () => {
			const doc = await PDFDocument.load(samplePdfBytes)
			const origSize = doc.getPage(0).getSize()

			await executeRotatePage(doc, {
				pageIndex: 0,
				angleDegrees: 3.5,
				fitToContent: true,
			})

			expect(doc.getPageCount()).toBe(8)
			const newSize = doc.getPage(0).getSize()
			expect(newSize.width).toBeGreaterThan(origSize.width)
			expect(newSize.height).toBeGreaterThan(origSize.height)

			const saved = await doc.save()
			const reloaded = await PDFDocument.load(saved)
			expect(reloaded.getPageCount()).toBe(8)
			const reloadedSize = reloaded.getPage(0).getSize()
			expect(reloadedSize.width).toBeCloseTo(newSize.width, 1)
			expect(reloadedSize.height).toBeCloseTo(newSize.height, 1)
		})

		it('rotates page by arbitrary angle and crops to original page size when cropToOriginalSize is true', async () => {
			const doc = await PDFDocument.load(samplePdfBytes)
			const origSize = doc.getPage(0).getSize()

			await executeRotatePage(doc, {
				pageIndex: 0,
				angleDegrees: 3.5,
				cropToOriginalSize: true,
			})

			expect(doc.getPageCount()).toBe(8)
			const newSize = doc.getPage(0).getSize()
			// Dimensions must exactly match the original dimensions (cropped to boundary)
			expect(newSize.width).toBeCloseTo(origSize.width, 1)
			expect(newSize.height).toBeCloseTo(origSize.height, 1)

			const saved = await doc.save()
			const reloaded = await PDFDocument.load(saved)
			expect(reloaded.getPageCount()).toBe(8)
			const reloadedSize = reloaded.getPage(0).getSize()
			expect(reloadedSize.width).toBeCloseTo(origSize.width, 1)
			expect(reloadedSize.height).toBeCloseTo(origSize.height, 1)
		})

		it('rotates page by arbitrary negative angle (-1.8° CCW)', async () => {
			const doc = await PDFDocument.load(samplePdfBytes)
			const origSize = doc.getPage(1).getSize()

			await executeRotatePage(doc, {
				pageIndex: 1,
				angleDegrees: -1.8,
			})

			expect(doc.getPageCount()).toBe(8)
			const newSize = doc.getPage(1).getSize()
			expect(newSize.width).toBeGreaterThan(origSize.width)
			expect(newSize.height).toBeGreaterThan(origSize.height)

			const saved = await doc.save()
			const reloaded = await PDFDocument.load(saved)
			expect(reloaded.getPageCount()).toBe(8)
		})

		it('supports rotating a cropped page by an arbitrary angle', async () => {
			const doc = await PDFDocument.load(samplePdfBytes)

			// Rotate page 0 by 5° with top half crop
			await executeRotatePage(doc, {
				pageIndex: 0,
				angleDegrees: 5,
				crop: { left: 0, top: 0, width: 1, height: 0.5 },
			})

			expect(doc.getPageCount()).toBe(8)
			const newSize = doc.getPage(0).getSize()
			// Height of half-page is ~421, rotated by 5° is ~451
			expect(newSize.height).toBeLessThan(500)
			expect(newSize.width).toBeGreaterThan(595)

			const saved = await doc.save()
			const reloaded = await PDFDocument.load(saved)
			expect(reloaded.getPageCount()).toBe(8)
		})

		it('throws an error when pageIndex is out of range', async () => {
			const doc = await PDFDocument.load(samplePdfBytes)
			await expect(executeRotatePage(doc, {
				pageIndex: 99,
				angleDegrees: 10,
			})).rejects.toThrow(/out of bounds/)
		})
	})
})

