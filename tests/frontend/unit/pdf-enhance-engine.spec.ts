import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import { executeEnhancePage } from '../../../src/components/song-pdf/pdf-enhance-engine'
import { applyPipeline, createImageBuffer } from '../../../src/components/song-pdf/scan-enhance-engine'
import { encodeImageBufferToPng } from '../../../src/components/song-pdf/png-encoder'
import type { GaussianOp, GrayLevelMappingOp } from '../../../src/components/song-pdf/scan-types'

const fixturePdfPath = path.resolve(__dirname, '../../fixtures/sample.pdf')
const samplePdfBytes = fs.readFileSync(fixturePdfPath)

describe('pdf-enhance-engine', () => {
	describe('encodeImageBufferToPng', () => {
		it('encodes grayscale ImageBuffer into a valid PNG byte array', async () => {
			const img = createImageBuffer(4, 4, 1, 128)
			const pngBytes = await encodeImageBufferToPng(img)

			// PNG signature check: 137, 80, 78, 71, 13, 10, 26, 10
			expect(pngBytes.length).toBeGreaterThan(30)
			expect(Array.from(pngBytes.subarray(0, 8))).toEqual([137, 80, 78, 71, 13, 10, 26, 10])

			// Embed into PDF to verify validity
			const doc = await PDFDocument.create()
			const embedded = await doc.embedPng(pngBytes)
			expect(embedded.width).toBe(4)
			expect(embedded.height).toBe(4)
		})

		it('encodes RGBA ImageBuffer into a valid PNG byte array', async () => {
			const img = createImageBuffer(4, 4, 4, 255)
			const pngBytes = await encodeImageBufferToPng(img)

			const doc = await PDFDocument.create()
			const embedded = await doc.embedPng(pngBytes)
			expect(embedded.width).toBe(4)
			expect(embedded.height).toBe(4)
		})
	})

	describe('executeEnhancePage', () => {
		it('replaces a PDF page with the enhanced image without mocks on real sample.pdf', async () => {
			const doc = await PDFDocument.load(samplePdfBytes)
			const origCount = doc.getPageCount()
			const origSize = doc.getPage(0).getSize()
			const origRotation = doc.getPage(0).getRotation()

			// Create a synthetic scan page and run enhancement pipeline
			const sourceScan = createImageBuffer(20, 30, 1, 150)
			// Add some music staves/notes
			sourceScan.data[5 * 20 + 10] = 20
			sourceScan.data[15 * 20 + 10] = 20

			const ops: (GaussianOp | GrayLevelMappingOp)[] = [
				{ id: '1', type: 'gaussian', size: 3, enabled: true },
				{ id: '2', type: 'grayLevelMapping', lowerCutoff: 30, upperCutoff: 200, enabled: true },
			]
			const enhanced = applyPipeline(sourceScan, ops)

			await executeEnhancePage(doc, {
				pageIndex: 0,
				enhancedImageBuffer: enhanced,
			})

			// Page count must be preserved
			expect(doc.getPageCount()).toBe(origCount)

			// Page dimensions must be preserved
			const newPage = doc.getPage(0)
			expect(newPage.getSize().width).toBeCloseTo(origSize.width, 1)
			expect(newPage.getSize().height).toBeCloseTo(origSize.height, 1)
			expect(newPage.getRotation().angle).toBe(origRotation.angle)

			// Save and reload to verify PDF document integrity
			const savedBytes = await doc.save()
			const reloadedDoc = await PDFDocument.load(savedBytes)
			expect(reloadedDoc.getPageCount()).toBe(origCount)
			expect(reloadedDoc.getPage(0).getSize().width).toBeCloseTo(origSize.width, 1)
		})

		it('throws an error for out of bounds page index', async () => {
			const doc = await PDFDocument.load(samplePdfBytes)
			const img = createImageBuffer(10, 10, 1, 255)

			await expect(
				executeEnhancePage(doc, {
					pageIndex: 999,
					enhancedImageBuffer: img,
				}),
			).rejects.toThrow('out of bounds')
		})
	})
})

