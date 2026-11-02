import {
	PDFArray,
	PDFDict,
	PDFDocument,
	PDFHexString,
	PDFName,
	PDFNull,
} from 'pdf-lib'

export interface PdfOutlineItem {
	title: string
	pageNum: number
}

/**
 * Updates or removes the Table of Contents (/Outlines) in a pdf-lib PDFDocument.
 * If items is empty, removes any existing /Outlines dictionary from the catalog.
 * Otherwise, builds a standard PDF outline tree pointing to the specified 1-based page numbers.
 */
export async function setPdfDocumentOutline(
	pdfDoc: PDFDocument,
	items: PdfOutlineItem[],
): Promise<boolean> {
	const validItems = items.filter((item) => String(item?.title ?? '').trim() !== '')
	const pageCount = pdfDoc.getPageCount()

	if (validItems.length === 0 || pageCount === 0) {
		if (pdfDoc.catalog.has(PDFName.of('Outlines'))) {
			pdfDoc.catalog.delete(PDFName.of('Outlines'))
		}
		return true
	}

	const outlinesDict = pdfDoc.context.obj({
		Type: 'Outlines',
		Count: validItems.length,
	})
	const outlinesRef = pdfDoc.context.register(outlinesDict)
	pdfDoc.catalog.set(PDFName.of('Outlines'), outlinesRef)

	const outlineRefs = validItems.map(() => pdfDoc.context.nextRef())

	validItems.forEach((item, index) => {
		const clampedPage = Math.max(1, Math.min(pageCount, item.pageNum))
		const page = pdfDoc.getPage(clampedPage - 1)

		const destArray = PDFArray.withContext(pdfDoc.context)
		destArray.push(page.ref)
		destArray.push(PDFName.of('XYZ'))
		destArray.push(PDFNull)
		destArray.push(PDFNull)
		destArray.push(PDFNull)

		const map = new Map()
		map.set(PDFName.of('Title'), PDFHexString.fromText(item.title.trim()))
		map.set(PDFName.of('Parent'), outlinesRef)
		map.set(PDFName.of('Dest'), destArray)

		if (index > 0) {
			map.set(PDFName.of('Prev'), outlineRefs[index - 1])
		}
		if (index < validItems.length - 1) {
			map.set(PDFName.of('Next'), outlineRefs[index + 1])
		}

		const itemDict = PDFDict.fromMapWithContext(map, pdfDoc.context)
		pdfDoc.context.assign(outlineRefs[index], itemDict)
	})

	outlinesDict.set(PDFName.of('First'), outlineRefs[0])
	outlinesDict.set(PDFName.of('Last'), outlineRefs[outlineRefs.length - 1])

	return true
}

/**
 * Loads a PDF byte buffer, embeds the provided outline items as the document's
 * Table of Contents, and returns the serialized modified PDF bytes.
 */
export async function embedBookmarksInPdfBytes(
	pdfBytes: Uint8Array,
	bookmarks: Array<{ name?: string; title?: string; pageNum: number }>,
): Promise<Uint8Array> {
	const pdfDoc = await PDFDocument.load(pdfBytes, { ignoreEncryption: true })
	const items: PdfOutlineItem[] = bookmarks.map((b) => ({
		title: String(b.title ?? b.name ?? '').trim(),
		pageNum: b.pageNum,
	}))

	await setPdfDocumentOutline(pdfDoc, items)
	return await pdfDoc.save()
}

export const embedMovementsInPdfBytes = embedBookmarksInPdfBytes
