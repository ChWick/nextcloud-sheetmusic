import { PDFDict, PDFDocument, PDFName, type PDFPage } from 'pdf-lib'
import type { ImageBuffer } from './scan-types'
import { encodeImageBufferToPng } from './png-encoder'

export interface EnhancePageOptions {
	pageIndex: number // 0-based page index
	enhancedImageBuffer: ImageBuffer
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
	return Math.max(imgW / pageW, imgH / pageH)
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
								const s = Math.max(imgW / drawnW, imgH / drawnH)
								if (drawnW * drawnH >= 0.4 * pageW * pageH) {
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

	let pdfLibScale = 0
	if (pdfLibPage) {
		const info = getPdfPageImageResolution(pdfLibPage)
		if (info && info.scale > 0) {
			pdfLibScale = info.scale
		}
	}

	if (maxFullPageScale > 0 || pdfLibScale > 0) {
		return Math.max(maxFullPageScale, pdfLibScale)
	}

	if (maxSubImageScale > 0) {
		return hasOperatorList ? Math.max(maxSubImageScale, 300 / 72) : maxSubImageScale
	}

	// For pure vector PDF pages with a real operator list, render at 300 DPI (300 / 72)
	if (hasOperatorList) {
		return 300 / 72
	}

	return 1.0
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
