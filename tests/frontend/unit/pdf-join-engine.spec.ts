import { describe, expect, it } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import * as fs from 'node:fs'
import * as path from 'node:path'
import {
	computeJoinFitInfo,
	executeJoinTwoPages,
	getEffectivePageDimensions,
} from '../../../src/components/song-pdf/pdf-join-engine'

describe('pdf-join-engine', () => {
	const samplePdfPath = path.resolve(__dirname, '../../fixtures/sample.pdf')
	const samplePdfBytes = fs.readFileSync(samplePdfPath)

	describe('getEffectivePageDimensions', () => {
		it('returns original dimensions when uncropped and unrotated', () => {
			const dim = getEffectivePageDimensions(600, 800)
			expect(dim).toEqual({ width: 600, height: 800 })
		})

		it('applies crop fractions to calculate effective dimensions', () => {
			const dim = getEffectivePageDimensions(600, 800, {
				left: 0.1,
				top: 0.2,
				width: 0.8,
				height: 0.5,
			})
			expect(dim.width).toBeCloseTo(480, 1)
			expect(dim.height).toBeCloseTo(400, 1)
		})

		it('swaps width and height when rotated 90 degrees', () => {
			const dim = getEffectivePageDimensions(600, 800, undefined, 90)
			expect(dim).toEqual({ width: 800, height: 600 })
		})
	})

	describe('computeJoinFitInfo', () => {
		const stdDim = { width: 595.28, height: 841.89 }

		it('correctly calculates vertical fit when two cropped pages fit on one page', () => {
			// Two half-page slices of height 350 and 380: total 350 + 380 + 12 = 742 < 841.89
			const dimA = { width: 500, height: 350 }
			const dimB = { width: 520, height: 380 }

			const fit = computeJoinFitInfo(dimA, dimB, stdDim, 'vertical', 12)
			expect(fit.fitsOnStandardPage).toBe(true)
			expect(fit.contentWidth).toBe(520)
			expect(fit.contentHeight).toBe(742)
			expect(fit.heightPercentage).toBe(Math.round((742 / 841.89) * 100))
			expect(fit.requiredScale).toBe(1)
		})

		it('flags when two pages exceed standard height in vertical layout', () => {
			const dimA = { width: 500, height: 500 }
			const dimB = { width: 500, height: 500 }

			const fit = computeJoinFitInfo(dimA, dimB, stdDim, 'vertical', 12)
			expect(fit.fitsOnStandardPage).toBe(false)
			expect(fit.contentHeight).toBe(1012)
			expect(fit.heightPercentage).toBeGreaterThan(100)
			expect(fit.requiredScale).toBeLessThan(1)
		})

		it('correctly calculates horizontal fit when two columns fit side-by-side', () => {
			// Two columns of width 250: total 250 + 250 + 12 = 512 < 595.28
			const dimA = { width: 250, height: 700 }
			const dimB = { width: 250, height: 750 }

			const fit = computeJoinFitInfo(dimA, dimB, stdDim, 'horizontal', 12)
			expect(fit.fitsOnStandardPage).toBe(true)
			expect(fit.contentWidth).toBe(512)
			expect(fit.contentHeight).toBe(750)
			expect(fit.requiredScale).toBe(1)
		})
	})

	describe('executeJoinTwoPages (Real PDFDocument operations)', () => {
		it('merges two pages vertically into a single page and removes originals', async () => {
			const doc = await PDFDocument.load(samplePdfBytes)
			expect(doc.getPageCount()).toBe(8)

			const res = await executeJoinTwoPages(doc, {
				pageIdx1: 0,
				pageIdx2: 1,
				layout: 'vertical',
				fitToStandardPage: true,
			})

			expect(doc.getPageCount()).toBe(7)
			expect(res.targetWidth).toBeCloseTo(595.28, 1)
			expect(res.targetHeight).toBeCloseTo(841.89, 1)

			// Verify PDF document can be saved and reloaded
			const savedBytes = await doc.save()
			const reloadedDoc = await PDFDocument.load(savedBytes)
			expect(reloadedDoc.getPageCount()).toBe(7)

			const joinedPage = reloadedDoc.getPage(0)
			expect(joinedPage.getWidth()).toBeCloseTo(595.28, 1)
			expect(joinedPage.getHeight()).toBeCloseTo(841.89, 1)
		})

		it('merges two cropped pages (e.g. top half and bottom half) into a single page', async () => {
			const doc = await PDFDocument.load(samplePdfBytes)
			expect(doc.getPageCount()).toBe(8)

			// Crop page 1: top half
			const crop1 = { left: 0, top: 0, width: 1, height: 0.5 }
			// Crop page 2: bottom half
			const crop2 = { left: 0, top: 0.5, width: 1, height: 0.5 }

			const res = await executeJoinTwoPages(doc, {
				pageIdx1: 0,
				pageIdx2: 1,
				layout: 'vertical',
				fitToStandardPage: true,
				crop1,
				crop2,
			})

			expect(doc.getPageCount()).toBe(7)
			expect(res.targetWidth).toBeCloseTo(595.28, 1)
			expect(res.targetHeight).toBeCloseTo(841.89, 1)

			const savedBytes = await doc.save()
			const reloadedDoc = await PDFDocument.load(savedBytes)
			expect(reloadedDoc.getPageCount()).toBe(7)
		})

		it('merges two pages horizontally with natural size layout', async () => {
			const doc = await PDFDocument.load(samplePdfBytes)
			expect(doc.getPageCount()).toBe(8)

			const res = await executeJoinTwoPages(doc, {
				pageIdx1: 0,
				pageIdx2: 7, // page 1 and page 8
				layout: 'horizontal',
				fitToStandardPage: false,
			})

			expect(doc.getPageCount()).toBe(7)
			expect(res.targetWidth).toBeGreaterThan(1000)

			const savedBytes = await doc.save()
			const reloadedDoc = await PDFDocument.load(savedBytes)
			expect(reloadedDoc.getPageCount()).toBe(7)
		})
	})
})

