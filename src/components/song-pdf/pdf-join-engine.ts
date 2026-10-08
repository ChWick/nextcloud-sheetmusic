import type { PageCrop } from './crop-types'
import { computeCropBoxForRotation } from './crop-types'
import { PDFDocument, degrees } from 'pdf-lib'

export type JoinLayout = 'vertical' | 'horizontal'

export interface JoinFitInfo {
	fitsOnStandardPage: boolean
	contentWidth: number
	contentHeight: number
	standardWidth: number
	standardHeight: number
	widthPercentage: number
	heightPercentage: number
	requiredScale: number
}

export interface JoinPagesOptions {
	pageIdx1: number // 0-based index of page A
	pageIdx2: number // 0-based index of page B
	layout: JoinLayout
	fitToStandardPage?: boolean
	scaleToFit?: boolean
	gap?: number
	crop1?: PageCrop
	crop2?: PageCrop
	rotation1?: number
	rotation2?: number
}

/**
 * Returns effective display dimensions (width, height) of a page given its original
 * media/crop box, optional crop fraction, and net rotation.
 */
export function getEffectivePageDimensions(
	origWidth: number,
	origHeight: number,
	crop?: PageCrop,
	rotation = 0,
): { width: number; height: number } {
	const rot = ((rotation % 360) + 360) % 360
	const isPerpendicular = rot === 90 || rot === 270

	let w = origWidth
	let h = origHeight

	if (crop && (crop.left > 0.005 || crop.top > 0.005 || crop.width < 0.995 || crop.height < 0.995)) {
		w = origWidth * crop.width
		h = origHeight * crop.height
	}

	if (isPerpendicular) {
		return { width: h, height: w }
	}

	return { width: w, height: h }
}

/**
 * Checks whether two pages (with their respective crops and rotations) can fit
 * onto a single standard target page in vertical or horizontal orientation.
 */
export function computeJoinFitInfo(
	dimA: { width: number; height: number },
	dimB: { width: number; height: number },
	stdDim: { width: number; height: number },
	layout: JoinLayout = 'vertical',
	gap = 12,
): JoinFitInfo {
	const stdW = stdDim.width || 595.28
	const stdH = stdDim.height || 841.89

	let contentWidth: number
	let contentHeight: number

	if (layout === 'vertical') {
		contentWidth = Math.max(dimA.width, dimB.width)
		contentHeight = dimA.height + dimB.height + gap
	} else {
		contentWidth = dimA.width + dimB.width + gap
		contentHeight = Math.max(dimA.height, dimB.height)
	}

	const fitsOnStandardPage = contentWidth <= stdW && contentHeight <= stdH
	const widthPercentage = Math.round((contentWidth / stdW) * 100)
	const heightPercentage = Math.round((contentHeight / stdH) * 100)

	const requiredScale = Math.min(
		1,
		(stdW - 16) / contentWidth,
		(stdH - 16) / contentHeight,
	)

	return {
		fitsOnStandardPage,
		contentWidth,
		contentHeight,
		standardWidth: stdW,
		standardHeight: stdH,
		widthPercentage,
		heightPercentage,
		requiredScale: Math.max(0.1, Math.min(1, requiredScale)),
	}
}

/**
 * Merges two pages into a single joined page inside a PDFDocument.
 * Inserts the joined page at pageIdx1, and removes both original pages.
 */
export async function executeJoinTwoPages(
	pdfDoc: PDFDocument,
	options: JoinPagesOptions,
): Promise<{ targetWidth: number; targetHeight: number }> {
	const {
		pageIdx1,
		pageIdx2,
		layout = 'vertical',
		fitToStandardPage = true,
		scaleToFit = true,
		gap = 12,
		crop1,
		crop2,
		rotation1 = 0,
		rotation2 = 0,
	} = options

	const pageCount = pdfDoc.getPageCount()
	if (pageIdx1 < 0 || pageIdx1 >= pageCount || pageIdx2 < 0 || pageIdx2 >= pageCount || pageIdx1 === pageIdx2) {
		throw new Error('Invalid page indices for joining pages.')
	}

	// Always treat first page in document order as pageA
	const isReversed = pageIdx1 > pageIdx2
	const idxA = isReversed ? pageIdx2 : pageIdx1
	const idxB = isReversed ? pageIdx1 : pageIdx2
	const cropA = isReversed ? crop2 : crop1
	const cropB = isReversed ? crop1 : crop2
	const rotA = isReversed ? rotation2 : rotation1
	const rotB = isReversed ? rotation1 : rotation2

	const pA = pdfDoc.getPage(idxA)
	const pB = pdfDoc.getPage(idxB)

	// Standard reference dimension (from page A's media box)
	const stdBox = pA.getMediaBox()
	const stdW = stdBox.width || 595.28
	const stdH = stdBox.height || 841.89

	// Compute bounding boxes for embedding
	const origBoxA = pA.getCropBox() ?? pA.getMediaBox()
	const origBoxB = pB.getCropBox() ?? pB.getMediaBox()

	const netAngleA = (((pA.getRotation()?.angle ?? 0) + rotA) % 360 + 360) % 360
	const netAngleB = (((pB.getRotation()?.angle ?? 0) + rotB) % 360 + 360) % 360

	const bBoxA = cropA && (cropA.left > 0.005 || cropA.top > 0.005 || cropA.width < 0.995 || cropA.height < 0.995)
		? computeCropBoxForRotation(origBoxA.x, origBoxA.y, origBoxA.width, origBoxA.height, cropA, netAngleA)
		: origBoxA

	const bBoxB = cropB && (cropB.left > 0.005 || cropB.top > 0.005 || cropB.width < 0.995 || cropB.height < 0.995)
		? computeCropBoxForRotation(origBoxB.x, origBoxB.y, origBoxB.width, origBoxB.height, cropB, netAngleB)
		: origBoxB

	const embedBoxA = {
		left: bBoxA.x,
		bottom: bBoxA.y,
		right: bBoxA.x + bBoxA.width,
		top: bBoxA.y + bBoxA.height,
	}
	const embedBoxB = {
		left: bBoxB.x,
		bottom: bBoxB.y,
		right: bBoxB.x + bBoxB.width,
		top: bBoxB.y + bBoxB.height,
	}

	const [embA, embB] = await pdfDoc.embedPages([pA, pB], [embedBoxA, embedBoxB])

	const wA = embA.width
	const hA = embA.height
	const wB = embB.width
	const hB = embB.height

	let targetW: number
	let targetH: number
	let scale = 1

	let aX = 0
	let aY = 0
	let bX = 0
	let bY = 0

	if (layout === 'vertical') {
		const naturalW = Math.max(wA, wB)
		const naturalH = hA + hB + gap

		if (fitToStandardPage) {
			targetW = stdW
			targetH = stdH
			if (scaleToFit && (naturalW > targetW || naturalH > targetH)) {
				scale = Math.min(1, (targetW - 16) / naturalW, (targetH - 16) / naturalH)
			}
		} else {
			targetW = naturalW + 16
			targetH = naturalH + 16
		}

		const scaledHA = hA * scale
		const scaledHB = hB * scale
		const scaledWA = wA * scale
		const scaledWB = wB * scale

		const totalContentH = scaledHA + scaledHB + gap
		const vMargin = Math.max(0, (targetH - totalContentH) / 2)

		// Page B is bottom
		bX = (targetW - scaledWB) / 2
		bY = vMargin

		// Page A is top
		aX = (targetW - scaledWA) / 2
		aY = bY + scaledHB + gap
	} else {
		// Horizontal: Page A on left, Page B on right
		const naturalW = wA + wB + gap
		const naturalH = Math.max(hA, hB)

		if (fitToStandardPage) {
			targetW = stdW
			targetH = stdH
			if (scaleToFit && (naturalW > targetW || naturalH > targetH)) {
				scale = Math.min(1, (targetW - 16) / naturalW, (targetH - 16) / naturalH)
			}
		} else {
			targetW = naturalW + 16
			targetH = naturalH + 16
		}

		const scaledWA = wA * scale
		const scaledWB = wB * scale
		const scaledHA = hA * scale
		const scaledHB = hB * scale

		const totalContentW = scaledWA + scaledWB + gap
		const hMargin = Math.max(0, (targetW - totalContentW) / 2)

		// Page A is left
		aX = hMargin
		aY = (targetH - scaledHA) / 2

		// Page B is right
		bX = aX + scaledWA + gap
		bY = (targetH - scaledHB) / 2
	}

	// Insert new combined page at idxA
	const newPage = pdfDoc.insertPage(idxA, [targetW, targetH])
	newPage.drawPage(embA, { x: aX, y: aY, width: wA * scale, height: hA * scale })
	newPage.drawPage(embB, { x: bX, y: bY, width: wB * scale, height: hB * scale })

	// Remove original two pages in descending order
	// Since newPage was inserted at idxA, original pA is now at idxA + 1, original pB is at idxB + 1
	const removeIdx1 = idxB + 1
	const removeIdx2 = idxA + 1
	pdfDoc.removePage(removeIdx1)
	pdfDoc.removePage(removeIdx2)

	return { targetWidth: targetW, targetHeight: targetH }
}

