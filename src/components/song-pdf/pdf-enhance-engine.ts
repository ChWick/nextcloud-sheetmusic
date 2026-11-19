import { PDFArray, PDFDict, PDFDocument, PDFName, type PDFPage, PDFRawStream, PDFRef, PDFStream } from 'pdf-lib'
import { cleanupPdfJsPage } from '../../utils/pdfjs'
import { encodeImageBufferToJpeg, packGrayscaleBits } from './jpeg-encoder'
import { encodeImageBufferToPng } from './png-encoder'
import type { CompressionOp, ImageBuffer, ScanOperation } from './scan-types'

export interface EnhancePageOptions {
	pageIndex: number // 0-based page index
	enhancedImageBuffer: ImageBuffer
	compression?: CompressionOp
	operations?: ScanOperation[]
}

export interface PdfPageResolutionSummary {
	pageNum: number
	widthPt: number
	heightPt: number
	widthMm: number
	heightMm: number
	pixelWidth: number
	pixelHeight: number
	dpi: number
	scale: number
}

export function formatByteSize(bytes?: number | null): string {
	if (typeof bytes !== 'number' || !Number.isFinite(bytes) || bytes <= 0) {
		return '—'
	}
	if (bytes < 1024) {
		return `${bytes} B`
	}
	const kb = bytes / 1024
	if (kb < 1024) {
		return `${kb >= 100 ? Math.round(kb) : kb.toFixed(1)} KB`
	}
	const mb = kb / 1024
	return `${mb >= 100 ? Math.round(mb) : mb.toFixed(2)} MB`
}

export function isImageBufferGrayscale(img: ImageBuffer): boolean {
	if (img.channels === 1) return true
	const { data, channels, width, height } = img
	const total = width * height
	for (let i = 0; i < total; i++) {
		const idx = i * channels
		const r = data[idx]
		if (data[idx + 1] !== r || data[idx + 2] !== r) {
			return false
		}
	}
	return true
}

/**
 * Flushes pending pdf-lib embeds and deletes all unreferenced indirect objects
 * (such as removed/replaced pages and their old multi-megabyte `/Image` XObjects)
 * from `pdfDoc.context.indirectObjects` so `pdfDoc.save()` does not retain dead streams.
 */
export async function pruneUnreferencedPdfObjects(pdfDoc: PDFDocument): Promise<number> {
	if (typeof (pdfDoc as any).flush === 'function') {
		await (pdfDoc as any).flush()
	}

	const ctx = pdfDoc.context
	const activePageRefs = new Set(pdfDoc.getPages().map((p) => p.ref.toString()))
	const removedPageRefs = new Set<string>()

	for (const [ref, obj] of ctx.enumerateIndirectObjects()) {
		const dict = obj instanceof PDFDict ? obj : ((obj as any)?.dict instanceof PDFDict ? (obj as any).dict : null)
		if (dict) {
			const typeName = dict.lookupMaybe(PDFName.of('Type'), PDFName)?.toString()
			if (typeName === '/Page' && !activePageRefs.has(ref.toString())) {
				removedPageRefs.add(ref.toString())
			}
		}
	}

	const reachable = new Set<string>()
	const queue: any[] = []

	const enqueue = (val: any) => {
		if (!val) return
		if (val instanceof PDFRef) {
			const key = val.toString()
			if (removedPageRefs.has(key) || reachable.has(key)) return
			reachable.add(key)
			queue.push(ctx.lookup(val))
		} else {
			queue.push(val)
		}
	}

	enqueue(ctx.trailerInfo.Root)
	enqueue(ctx.trailerInfo.Info)
	enqueue(ctx.trailerInfo.Encrypt)

	while (queue.length > 0) {
		const curr = queue.pop()
		if (!curr) continue
		if (curr instanceof PDFRef) {
			enqueue(curr)
		} else if (curr instanceof PDFDict) {
			for (const [, v] of curr.entries()) {
				enqueue(v)
			}
		} else if (curr instanceof PDFArray) {
			for (let i = 0; i < curr.size(); i++) {
				enqueue(curr.get(i))
			}
		} else if (curr instanceof PDFStream || curr instanceof PDFRawStream || (curr as any)?.dict instanceof PDFDict) {
			enqueue((curr as any).dict)
		}
	}

	let deletedCount = 0
	for (const [ref] of ctx.enumerateIndirectObjects()) {
		if (!reachable.has(ref.toString())) {
			ctx.delete(ref)
			deletedCount++
		}
	}

	return deletedCount
}

export function resolveActiveCompressionOp(options: EnhancePageOptions): CompressionOp | null {
	if (options.compression && options.compression.enabled !== false) {
		return options.compression
	}
	if (options.operations) {
		for (let i = options.operations.length - 1; i >= 0; i--) {
			const op = options.operations[i]
			if (op.enabled && op.type === 'compression') {
				return op
			}
		}
	}
	return null
}

export interface PageImageResolutionInfo {
	width: number
	height: number
	dpiX: number
	dpiY: number
	scale: number
}

function multiplyMatrix(m1: number[], m2: number[]): number[] {
	return [
		m1[0] * m2[0] + m1[2] * m2[1],
		m1[1] * m2[0] + m1[3] * m2[1],
		m1[0] * m2[2] + m1[2] * m2[3],
		m1[1] * m2[2] + m1[3] * m2[3],
		m1[0] * m2[4] + m1[2] * m2[5] + m1[4],
		m1[1] * m2[4] + m1[3] * m2[5] + m1[5],
	]
}

/**
 * Computes a scale factor S such that Math.round(pageW * S) === imgW and Math.round(pageH * S) === imgH
 * whenever a uniform scale exists within rounding tolerance, otherwise Math.max(imgW / pageW, imgH / pageH).
 */
export function computeExactPageScale(
	pageW: number,
	pageH: number,
	imgW: number,
	imgH: number,
): number {
	if (pageW <= 0 || pageH <= 0 || imgW <= 0 || imgH <= 0) {
		return 1.0
	}
	const lowW = (imgW - 0.49) / pageW
	const highW = (imgW + 0.49) / pageW
	const lowH = (imgH - 0.49) / pageH
	const highH = (imgH + 0.49) / pageH

	const low = Math.max(lowW, lowH)
	const high = Math.min(highW, highH)
	if (low <= high) {
		return (low + high) / 2
	}
	const scaleW = imgW / pageW
	const scaleH = imgH / pageH
	// If aspect ratios differ by more than 10%, preserve total pixel count (geometric mean)
	// rather than inflating the smaller dimension to match the larger one.
	if (Math.abs(scaleW - scaleH) / Math.max(scaleW, scaleH) > 0.1) {
		return Math.sqrt((imgW * imgH) / (pageW * pageH))
	}
	return Math.max(scaleW, scaleH)
}

/**
 * Inspects a pdf-lib PDFPage's XObject resources (including nested Form XObjects)
 * to determine the native pixel dimensions and DPI of embedded scan images.
 */
export function getPdfPageImageResolution(pdfLibPage: PDFPage): PageImageResolutionInfo | null {
	const { width: pageW, height: pageH } = pdfLibPage.getSize()
	if (pageW <= 0 || pageH <= 0) return null

	const visited = new Set<string>()
	let bestInfo: PageImageResolutionInfo | null = null

	function walkResources(resources: PDFDict | undefined) {
		if (!resources) return
		const xObjDict = resources.lookupMaybe(PDFName.of('XObject'), PDFDict)
		if (!xObjDict) return

		for (const [, ref] of xObjDict.entries()) {
			const key = ref.toString()
			if (visited.has(key)) continue
			visited.add(key)

			const obj = (pdfLibPage as any).doc.context.lookup(ref)
			const dict: PDFDict | null = obj?.dict ?? (obj instanceof PDFDict ? obj : null)
			if (!dict) {
				// Check unflushed embedded images in pdf-lib document
				const pendingImages: any[] = (pdfLibPage as any).doc?.images ?? []
				const pending = pendingImages.find((im) => im?.ref?.toString?.() === key)
				if (pending && pending.width > 0 && pending.height > 0) {
					const imgW = Number(pending.width)
					const imgH = Number(pending.height)
					const isRotated =
						Math.abs(imgW / imgH - pageH / pageW) < 0.05 &&
						Math.abs(imgW / imgH - pageW / pageH) >= 0.05
					const refW = isRotated ? pageH : pageW
					const refH = isRotated ? pageW : pageH
					const scale = computeExactPageScale(refW, refH, imgW, imgH)
					const dpiX = (imgW / refW) * 72
					const dpiY = (imgH / refH) * 72
					if (!bestInfo || scale > bestInfo.scale) {
						bestInfo = {
							width: Math.round(pageW * scale),
							height: Math.round(pageH * scale),
							dpiX,
							dpiY,
							scale,
						}
					}
				}
				continue
			}

			const subtype = dict.lookup(PDFName.of('Subtype'))?.toString()
			if (subtype === '/Image') {
				const wObj = dict.lookup(PDFName.of('Width')) as any
				const hObj = dict.lookup(PDFName.of('Height')) as any
				const imgW = typeof wObj?.asNumber === 'function' ? wObj.asNumber() : Number(wObj?.toString?.() ?? 0)
				const imgH = typeof hObj?.asNumber === 'function' ? hObj.asNumber() : Number(hObj?.toString?.() ?? 0)
				if (imgW > 0 && imgH > 0) {
					// Check if image aspect ratio is rotated 90 deg relative to page
					const isRotated =
						Math.abs(imgW / imgH - pageH / pageW) < 0.05 &&
						Math.abs(imgW / imgH - pageW / pageH) >= 0.05
					const refW = isRotated ? pageH : pageW
					const refH = isRotated ? pageW : pageH
					const scale = computeExactPageScale(refW, refH, imgW, imgH)
					const dpiX = (imgW / refW) * 72
					const dpiY = (imgH / refH) * 72

					if (!bestInfo || scale > bestInfo.scale) {
						bestInfo = {
							width: Math.round(pageW * scale),
							height: Math.round(pageH * scale),
							dpiX,
							dpiY,
							scale,
						}
					}
				}
			} else if (subtype === '/Form') {
				const subRes = dict.lookupMaybe(PDFName.of('Resources'), PDFDict)
				walkResources(subRes)
			}
		}
	}

	walkResources(pdfLibPage.node.Resources())
	return bestInfo
}

/**
 * Resolves the native render scale (pixels per PDF point, where 1.0 = 72 DPI) for a PDF page
 * so that rendering the page preserves the exact PDF DPI without losing resolution.
 */
export async function resolvePageRenderScale(
	pdfjsPage?: any,
	pdfLibPage?: PDFPage,
): Promise<number> {
	let pageW = 0
	let pageH = 0

	if (pdfLibPage) {
		const size = pdfLibPage.getSize()
		pageW = size.width
		pageH = size.height
	} else if (typeof pdfjsPage?.getViewport === 'function') {
		const vp = pdfjsPage.getViewport({ scale: 1.0, rotation: 0 })
		pageW = vp.width
		pageH = vp.height
	}

	let maxFullPageScale = 0
	let maxSubImageScale = 0
	const hasOperatorList = typeof pdfjsPage?.getOperatorList === 'function'

	if (hasOperatorList && pageW > 0 && pageH > 0) {
		try {
			const opList = await pdfjsPage.getOperatorList()
			const fnArray: number[] = opList?.fnArray ?? []
			const argsArray: any[] = opList?.argsArray ?? []

			const ctmStack: number[][] = []
			let ctm = [1, 0, 0, 1, 0, 0]

			for (let i = 0; i < fnArray.length; i++) {
				const fn = fnArray[i]
				const args = argsArray[i]

				if (fn === 10) {
					// OPS.save
					ctmStack.push([...ctm])
				} else if (fn === 11) {
					// OPS.restore
					if (ctmStack.length > 0) {
						ctm = ctmStack.pop()!
					}
				} else if (fn === 12 && Array.isArray(args) && args.length >= 6) {
					// OPS.transform
					ctm = multiplyMatrix(ctm, args)
				} else if (fn === 74) {
					// OPS.paintFormXObjectBegin
					ctmStack.push([...ctm])
					const formMatrix = args?.[0]
					if (formMatrix && typeof formMatrix[0] === 'number') {
						ctm = multiplyMatrix(ctm, [
							formMatrix[0],
							formMatrix[1],
							formMatrix[2],
							formMatrix[3],
							formMatrix[4],
							formMatrix[5],
						])
					}
				} else if (fn === 75) {
					// OPS.paintFormXObjectEnd
					if (ctmStack.length > 0) {
						ctm = ctmStack.pop()!
					}
				} else if (fn === 85 || fn === 88 || fn === 83 || fn === 86) {
					// Image painting operators
					let imgW = 0
					let imgH = 0
					if (fn === 85 || fn === 88) {
						imgW = Number(args?.[1] ?? 0)
						imgH = Number(args?.[2] ?? 0)
					} else {
						imgW = Number(args?.[0]?.width ?? 0)
						imgH = Number(args?.[0]?.height ?? 0)
					}

					if (imgW > 0 && imgH > 0) {
						const drawnW = Math.hypot(ctm[0], ctm[1])
						const drawnH = Math.hypot(ctm[2], ctm[3])

						if (drawnW > 1 && drawnH > 1) {
							const coversPageNormal = drawnW >= 0.85 * pageW && drawnH >= 0.85 * pageH
							const coversPageRotated = drawnW >= 0.85 * pageH && drawnH >= 0.85 * pageW

							if (coversPageNormal) {
								const s = computeExactPageScale(pageW, pageH, imgW, imgH)
								if (s > maxFullPageScale) maxFullPageScale = s
							} else if (coversPageRotated) {
								const s = computeExactPageScale(pageH, pageW, imgW, imgH)
								if (s > maxFullPageScale) maxFullPageScale = s
							} else {
								const s = computeExactPageScale(drawnW, drawnH, imgW, imgH)
								if (drawnW * drawnH >= 0.4 * pageW * pageH || drawnW >= 0.75 * pageW || drawnH >= 0.75 * pageH) {
									if (s > maxFullPageScale) maxFullPageScale = s
								} else if (s > maxSubImageScale) {
									maxSubImageScale = s
								}
							}
						} else {
							const sameOrient = (imgW >= imgH) === (pageW >= pageH)
							const refW = sameOrient ? pageW : pageH
							const refH = sameOrient ? pageH : pageW
							const s = computeExactPageScale(refW, refH, imgW, imgH)
							if (s > maxFullPageScale) maxFullPageScale = s
						}
					}
				}
			}
		} catch {
			// Fallback to pdfLibPage inspection if getOperatorList fails
		}
	}

	if (maxFullPageScale > 0) {
		return maxFullPageScale
	}

	if (pdfLibPage) {
		const info = getPdfPageImageResolution(pdfLibPage)
		if (info && info.scale > 0) {
			return info.scale
		}
	}

	if (maxSubImageScale > 0) {
		return maxSubImageScale
	}

	// For pure vector PDF pages with a real operator list, render at 300 DPI (300 / 72)
	if (hasOperatorList) {
		return 300 / 72
	}

	return 1.0
}

/**
 * Inspects a PDF page (via pdfjs and/or pdf-lib) and returns its physical size, native pixel resolution, and DPI.
 */
export async function inspectPdfPageResolution(
	pdfjsPage: any,
	pageNum = 1,
	pdfLibPage?: PDFPage,
): Promise<PdfPageResolutionSummary> {
	const scale = await resolvePageRenderScale(pdfjsPage, pdfLibPage)
	let widthPt = 595.28
	let heightPt = 841.89

	if (typeof pdfjsPage?.getViewport === 'function') {
		const rot = pdfjsPage.rotate ?? 0
		const vp = pdfjsPage.getViewport({ scale: 1.0, rotation: rot })
		if (vp.width > 0 && vp.height > 0) {
			widthPt = vp.width
			heightPt = vp.height
		}
	} else if (pdfLibPage) {
		const sz = pdfLibPage.getSize()
		widthPt = sz.width
		heightPt = sz.height
	}

	const pixelWidth = Math.max(1, Math.round(widthPt * scale))
	const pixelHeight = Math.max(1, Math.round(heightPt * scale))
	const dpi = Math.max(1, Math.round(scale * 72))
	const widthMm = Math.round((widthPt / 72) * 25.4)
	const heightMm = Math.round((heightPt / 72) * 25.4)

	return {
		pageNum,
		widthPt,
		heightPt,
		widthMm,
		heightMm,
		pixelWidth,
		pixelHeight,
		dpi,
		scale,
	}
}

/**
 * Inspect resolution summaries for up to `maxPages` pages of a pdf.js document,
 * cleaning up each page proxy immediately to keep memory bounded.
 */
export async function inspectPdfDocumentResolutions(
	pdf: any,
	maxPages = 25,
): Promise<PdfPageResolutionSummary[]> {
	if (!pdf) return []
	const maxInspect = Math.min(pdf.numPages ?? 0, maxPages)
	const summaries: PdfPageResolutionSummary[] = []
	for (let p = 1; p <= maxInspect; p++) {
		const pg = await pdf.getPage(p)
		try {
			summaries.push(await inspectPdfPageResolution(pg, p))
		} finally {
			cleanupPdfJsPage(pg)
		}
	}
	return summaries
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

	const compressionOp = resolveActiveCompressionOp(options)
	let embeddedImage: Awaited<ReturnType<PDFDocument['embedPng']>>

	if (compressionOp?.format === 'jpeg') {
		const isGray = (compressionOp.colorMode ?? 'grayscale') === 'grayscale'
		const jpegBytes = encodeImageBufferToJpeg(
			options.enhancedImageBuffer,
			compressionOp.quality ?? 75,
			isGray,
		)
		embeddedImage = await pdfDoc.embedJpg(jpegBytes)
	} else {
		// 1. Encode enhanced ImageBuffer to PNG
		const pngBytes = await encodeImageBufferToPng(options.enhancedImageBuffer)

		// 2. Embed into PDFDocument
		embeddedImage = await pdfDoc.embedPng(pngBytes)

		const embedder = (embeddedImage as any)?.embedder
		if (embedder?.image) {
			// Always strip redundant alpha SMask for opaque scan pages
			embedder.image.alphaChannel = null

			if (
				compressionOp &&
				(compressionOp.format === 'grayscale-8bit' ||
					compressionOp.format === 'grayscale-4bit' ||
					compressionOp.format === 'grayscale-2bit' ||
					compressionOp.format === 'bw-1bit')
			) {
				const bitDepth: 1 | 2 | 4 | 8 =
					compressionOp.format === 'bw-1bit'
						? 1
						: compressionOp.format === 'grayscale-2bit'
							? 2
							: compressionOp.format === 'grayscale-4bit'
								? 4
								: 8
				embedder.colorSpace = 'DeviceGray'
				embedder.image.bitsPerComponent = bitDepth
				embedder.image.rgbChannel = packGrayscaleBits(options.enhancedImageBuffer, bitDepth)
			} else if (!compressionOp && isImageBufferGrayscale(options.enhancedImageBuffer)) {
				// Automatically store grayscale-only enhanced scans as single-channel /DeviceGray
				embedder.colorSpace = 'DeviceGray'
				embedder.image.bitsPerComponent = 8
				embedder.image.rgbChannel = packGrayscaleBits(options.enhancedImageBuffer, 8)
			}
		}
	}

	// 3. Insert new page at target index and draw the enhanced image filling the page
	const newPage = pdfDoc.insertPage(options.pageIndex, [origSize.width, origSize.height])
	newPage.setRotation(origRotation)

	newPage.drawImage(embeddedImage, {
		x: 0,
		y: 0,
		width: origSize.width,
		height: origSize.height,
	})

	// 4. Remove original page (now shifted to pageIndex + 1) and prune orphaned indirect objects
	pdfDoc.removePage(options.pageIndex + 1)
	await pruneUnreferencedPdfObjects(pdfDoc)
}
