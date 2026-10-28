import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import {
	computeExactPageScale,
	executeEnhancePage,
	getPdfPageImageResolution,
	resolvePageRenderScale,
} from '../../../src/components/song-pdf/pdf-enhance-engine'
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

		it('preserves identical PDF DPI and pixel resolution before and after enhancement for portrait and landscape scans', async () => {
			const doc = await PDFDocument.create()
			// Create a 300 DPI portrait page (144x216 pt = 2x3 inches -> 600x900 px at 300 DPI)
			const portraitScan = createImageBuffer(600, 900, 1, 200)
			const portraitPng = await encodeImageBufferToPng(portraitScan)
			const embPortrait = await doc.embedPng(portraitPng)
			const page0 = doc.addPage([144, 216])
			page0.drawImage(embPortrait, { x: 0, y: 0, width: 144, height: 216 })

			// Create a 300 DPI landscape page (216x144 pt = 3x2 inches -> 900x600 px at 300 DPI)
			const landscapeScan = createImageBuffer(900, 600, 1, 200)
			const landscapePng = await encodeImageBufferToPng(landscapeScan)
			const embLandscape = await doc.embedPng(landscapePng)
			const page1 = doc.addPage([216, 144])
			page1.drawImage(embLandscape, { x: 0, y: 0, width: 216, height: 144 })

			// Verify initial DPI is 300 DPI on both pages
			const before0 = getPdfPageImageResolution(doc.getPage(0))
			const before1 = getPdfPageImageResolution(doc.getPage(1))
			expect(before0).not.toBeNull()
			expect(before0!.dpiX).toBeCloseTo(300, 1)
			expect(before0!.dpiY).toBeCloseTo(300, 1)
			expect(before0!.width).toBe(600)
			expect(before0!.height).toBe(900)

			expect(before1).not.toBeNull()
			expect(before1!.dpiX).toBeCloseTo(300, 1)
			expect(before1!.dpiY).toBeCloseTo(300, 1)
			expect(before1!.width).toBe(900)
			expect(before1!.height).toBe(600)

			// Resolve render scales for both pages
			const scale0 = await resolvePageRenderScale(undefined, doc.getPage(0))
			const scale1 = await resolvePageRenderScale(undefined, doc.getPage(1))
			expect(Math.round(144 * scale0)).toBe(600)
			expect(Math.round(216 * scale0)).toBe(900)
			expect(Math.round(216 * scale1)).toBe(900)
			expect(Math.round(144 * scale1)).toBe(600)

			// Apply enhancement at resolved scale and replace pages
			const enhanced0 = applyPipeline(
				createImageBuffer(Math.round(144 * scale0), Math.round(216 * scale0), 1, 180),
				[{ id: '1', type: 'grayLevelMapping', lowerCutoff: 30, upperCutoff: 210, enabled: true }],
			)
			await executeEnhancePage(doc, { pageIndex: 0, enhancedImageBuffer: enhanced0 })

			const enhanced1 = applyPipeline(
				createImageBuffer(Math.round(216 * scale1), Math.round(144 * scale1), 1, 180),
				[{ id: '1', type: 'grayLevelMapping', lowerCutoff: 30, upperCutoff: 210, enabled: true }],
			)
			await executeEnhancePage(doc, { pageIndex: 1, enhancedImageBuffer: enhanced1 })

			// Save, reload, and verify that the PDF DPI and pixel resolution are 100% identical
			const savedBytes = await doc.save()
			const reloaded = await PDFDocument.load(savedBytes)

			const after0 = getPdfPageImageResolution(reloaded.getPage(0))
			const after1 = getPdfPageImageResolution(reloaded.getPage(1))
			expect(after0!.width).toBe(before0!.width)
			expect(after0!.height).toBe(before0!.height)
			expect(after0!.dpiX).toBeCloseTo(before0!.dpiX, 3)
			expect(after0!.dpiY).toBeCloseTo(before0!.dpiY, 3)

			expect(after1!.width).toBe(before1!.width)
			expect(after1!.height).toBe(before1!.height)
			expect(after1!.dpiX).toBeCloseTo(before1!.dpiX, 3)
			expect(after1!.dpiY).toBeCloseTo(before1!.dpiY, 3)
		})

		it('resolves exact native scale from pdfjs operatorList with CTM transforms', async () => {
			const mockPdfjsPage = {
				getViewport: ({ scale = 1 }: { scale?: number } = {}) => ({
					width: 841.89 * scale,
					height: 595.28 * scale,
				}),
				getOperatorList: async () => ({
					// save (10), transform (12), paintImageXObject (85), restore (11)
					fnArray: [10, 12, 85, 11],
					argsArray: [
						null,
						[841.89, 0, 0, 595.28, 0, 0],
						['img_landscape_300dpi', 3508, 2480],
						null,
					],
				}),
			}

			const scale = await resolvePageRenderScale(mockPdfjsPage)
			expect(Math.round(841.89 * scale)).toBe(3508)
			expect(Math.round(595.28 * scale)).toBe(2480)
			expect(scale * 72).toBeCloseTo(300, 0)
		})

		it('computes exact page scale reproducing integer pixel dimensions', () => {
			const s = computeExactPageScale(595.276, 841.89, 829, 1173)
			expect(Math.round(595.276 * s)).toBe(829)
			expect(Math.round(841.89 * s)).toBe(1173)
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
