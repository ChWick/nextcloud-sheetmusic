import { PDFDocument } from 'pdf-lib'
import type { ImageBuffer, ScanOperation } from './scan-types'
import { applyPipeline } from './scan-enhance-engine'
import { encodeImageBufferToPng } from './png-encoder'

export interface EnhancePageOptions {
	pageIndex: number // 0-based page index
	enhancedImageBuffer: ImageBuffer
}

/**
 * Replaces a page in a PDFDocument with the enhanced image, preserving original page dimensions and rotation.
 */
export async function executeEnhancePage(
	pdfDoc: PDFDocument,
	options: EnhancePageOptions,
): Promise<void> {
	const pageCount = pdfDoc.getPageCount()
	if (options.pageIndex < 0 || options.pageIndex >= pageCount) {
		throw new Error(`Page index ${options.pageIndex} out of bounds (document has ${pageCount} pages)`)
	}

	const origPage = pdfDoc.getPage(options.pageIndex)
	const origSize = origPage.getSize()
	const origRotation = origPage.getRotation()

	// 1. Encode enhanced ImageBuffer to PNG
	const pngBytes = await encodeImageBufferToPng(options.enhancedImageBuffer)

	// 2. Embed into PDFDocument
	const embeddedImage = await pdfDoc.embedPng(pngBytes)

	// 3. Insert new page at target index and draw the enhanced image filling the page
	const newPage = pdfDoc.insertPage(options.pageIndex, [origSize.width, origSize.height])
	newPage.setRotation(origRotation)

	newPage.drawImage(embeddedImage, {
		x: 0,
		y: 0,
		width: origSize.width,
		height: origSize.height,
	})

	// 4. Remove original page (now shifted to pageIndex + 1)
	pdfDoc.removePage(options.pageIndex + 1)
}

