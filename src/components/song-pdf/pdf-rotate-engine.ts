import type { PageCrop } from './crop-types'
import { computeCropBoxForRotation } from './crop-types'
import { PDFDocument, degrees } from 'pdf-lib'

export interface RotatedBoundingBox {
	bboxWidth: number
	bboxHeight: number
	minX: number
	minY: number
	pdfAngle: number
}

/**
 * Calculates the bounding box and translation offsets for rotating an item of dimensions
 * (width, height) by `angleDegrees` clockwise (as experienced in UI).
 *
 * In PDF / Cartesian coordinate space, positive rotation is counter-clockwise,
 * so a clockwise rotation of A degrees corresponds to -A degrees in PDF drawing operations.
 */
export function calculateRotatedBoundingBox(
	width: number,
	height: number,
	angleDegrees: number,
): RotatedBoundingBox {
	const normalizedUiAngle = ((angleDegrees % 360) + 360) % 360
	const rad = (-normalizedUiAngle * Math.PI) / 180
	const cos = Math.cos(rad)
	const sin = Math.sin(rad)

	const corners = [
		{ x: 0, y: 0 },
		{ x: width, y: 0 },
		{ x: width, y: height },
		{ x: 0, y: height },
	]

	const rotated = corners.map((p) => ({
		x: p.x * cos - p.y * sin,
		y: p.x * sin + p.y * cos,
	}))

	const minX = Math.min(...rotated.map((p) => p.x))
	const maxX = Math.max(...rotated.map((p) => p.x))
	const minY = Math.min(...rotated.map((p) => p.y))
	const maxY = Math.max(...rotated.map((p) => p.y))

	return {
		bboxWidth: Math.round((maxX - minX) * 1000) / 1000,
		bboxHeight: Math.round((maxY - minY) * 1000) / 1000,
		minX: Math.round(minX * 1000) / 1000,
		minY: Math.round(minY * 1000) / 1000,
		pdfAngle: -normalizedUiAngle === 0 ? 0 : -normalizedUiAngle,
	}
}

export interface RotatePageOptions {
	pageIndex: number // 0-based page index
	angleDegrees: number // Clockwise rotation in degrees (can be any angle, e.g. 1.5, -2, 45, 90)
	crop?: PageCrop
	fitToContent?: boolean // If true (default), page size expands so corners aren't clipped
	cropToOriginalSize?: boolean // If true, keeps original page size and crops rotated content to page bounds
}

/**
 * Executes a page rotation in a PDF document.
 * If the angle is a multiple of 90 (0, 90, 180, 270) and no crop requires re-embedding,
 * it sets the standard PDF `/Rotate` attribute for efficiency and preservation of document structure.
 *
 * If the angle is NOT a multiple of 90 (arbitrary angle), it embeds the page as a Form XObject,
 * computes the required rotated bounding box (preventing clipped music), creates a replacement page,
 * and draws the embedded content rotated by the arbitrary angle.
 */
export async function executeRotatePage(
	pdfDoc: PDFDocument,
	options: RotatePageOptions,
): Promise<void> {
	const pageCount = pdfDoc.getPageCount()
	if (options.pageIndex < 0 || options.pageIndex >= pageCount) {
		throw new Error(`Page index ${options.pageIndex} out of bounds (document has ${pageCount} pages)`)
	}

	const page = pdfDoc.getPage(options.pageIndex)
	const currentAngle = page.getRotation()?.angle ?? 0
	const netAngle = ((currentAngle + options.angleDegrees) % 360 + 360) % 360

	// Check if this is a multiple of 90 (standard PDF rotation)
	const isMultipleOf90 = Math.abs(netAngle % 90) < 0.001 || Math.abs((netAngle % 90) - 90) < 0.001

	if (isMultipleOf90) {
		const rounded90 = Math.round(netAngle / 90) * 90 % 360
		page.setRotation(degrees(rounded90))

		// If page has a crop, apply crop box taking net rotation into account
		if (options.crop && (options.crop.left > 0.005 || options.crop.top > 0.005 || options.crop.width < 0.995 || options.crop.height < 0.995)) {
			const { x: origX, y: origY, width: origW, height: origH } = page.getCropBox() ?? page.getMediaBox()
			const box = computeCropBoxForRotation(origX, origY, origW, origH, options.crop, rounded90)
			page.setCropBox(box.x, box.y, box.width, box.height)
		}
		return
	}

	// Arbitrary angle rotation:
	// 1. Ensure page has content stream so pdf-lib embedPage doesn't fail on empty pages
	if (!page.node.Contents()) {
		page.drawRectangle({ x: 0, y: 0, width: 0, height: 0 })
	}

	// 2. Determine source bounding box (taking optional crop into account)
	const origBox = page.getCropBox() ?? page.getMediaBox()
	let bBox = origBox

	if (options.crop && (options.crop.left > 0.005 || options.crop.top > 0.005 || options.crop.width < 0.995 || options.crop.height < 0.995)) {
		// Crop is applied relative to current visual view before arbitrary rotation
		bBox = computeCropBoxForRotation(origBox.x, origBox.y, origBox.width, origBox.height, options.crop, currentAngle)
	}

	const embedBox = {
		left: bBox.x,
		bottom: bBox.y,
		right: bBox.x + bBox.width,
		top: bBox.y + bBox.height,
	}

	const emb = await pdfDoc.embedPage(page, embedBox)

	// 3. Calculate rotated bounding box
	// Net clockwise rotation relative to the embedded unrotated content
	const totalCWAngle = ((currentAngle + options.angleDegrees) % 360 + 360) % 360
	const { bboxWidth, bboxHeight, minX, minY, pdfAngle } = calculateRotatedBoundingBox(
		emb.width,
		emb.height,
		totalCWAngle,
	)

	const fitToContent = options.cropToOriginalSize !== undefined
		? !options.cropToOriginalSize
		: options.fitToContent !== false
	const targetWidth = fitToContent ? bboxWidth : bBox.width
	const targetHeight = fitToContent ? bboxHeight : bBox.height

	// Calculate draw placement
	let drawX = -minX
	let drawY = -minY

	if (!fitToContent) {
		// Center inside original dimensions
		drawX = (targetWidth - bboxWidth) / 2 - minX
		drawY = (targetHeight - bboxHeight) / 2 - minY
	}

	// 4. Insert new page at target index and draw rotated embedded page
	const newPage = pdfDoc.insertPage(options.pageIndex, [targetWidth, targetHeight])
	newPage.drawPage(emb, {
		x: drawX,
		y: drawY,
		rotate: degrees(pdfAngle),
	})

	// 5. Remove original page (which shifted to pageIndex + 1)
	pdfDoc.removePage(options.pageIndex + 1)
}
