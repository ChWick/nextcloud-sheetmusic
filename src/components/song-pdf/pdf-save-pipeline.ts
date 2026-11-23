/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { degrees, type PDFDocument } from 'pdf-lib'
import { translate as t } from '@nextcloud/l10n'
import { cleanupPdfJsPage, releaseCanvas } from '../../utils/pdfjs'
import { computeCropBoxForRotation, type PageCrop } from './crop-types'
import { executeRotatePage } from './pdf-rotate-engine'
import type { ScanOperation } from './scan-types'
import { applyPipeline, createImageBuffer, imageDataToImageBuffer } from './scan-enhance-engine'
import { executeEnhancePage, resolvePageRenderScale } from './pdf-enhance-engine'

export interface ApplyPdfPageEditsOptions {
	pdfDoc: PDFDocument
	activePdfDoc: any | null
	totalPages: number
	pageRotations: number[]
	pageRotateCropToOriginal: Record<number, boolean>
	pageCrops: Record<number, PageCrop>
	pageEnhancements: Record<number, ScanOperation[]>
	deletedPages: Set<number>
	isPageEnhanced: (pageNum: number) => boolean
	yieldToBrowser: () => Promise<void>
	onProgress: (percent: number, text: string, current: number, total: number, shouldYield: boolean) => Promise<void>
}

export interface ApplyPdfPageEditsResult {
	modifiedRotations: boolean
	modifiedCrops: boolean
	modifiedDeletions: boolean
	modifiedEnhancements: boolean
	activeTotal: number
}

/**
 * Applies queued scan enhancements, rotations, crops, and page deletions to a loaded pdf-lib document.
 */
export async function applyPdfPageEdits(options: ApplyPdfPageEditsOptions): Promise<ApplyPdfPageEditsResult> {
	const {
		pdfDoc,
		activePdfDoc,
		totalPages,
		pageRotations,
		pageRotateCropToOriginal,
		pageCrops,
		pageEnhancements,
		deletedPages,
		isPageEnhanced,
		yieldToBrowser,
		onProgress,
	} = options

	const activeTotal = Math.max(1, totalPages - deletedPages.size)
	const pages = pdfDoc.getPages()

	let modifiedRotations = false
	let modifiedCrops = false
	let modifiedEnhancements = false
	let processedCount = 0

	for (let i = 0; i < pages.length; i++) {
		const pageNum = i + 1
		if (deletedPages.has(pageNum)) {
			continue
		}

		processedCount++
		const pageIsEnhanced = Boolean(isPageEnhanced(pageNum) && activePdfDoc)
		const startPct = 5 + ((processedCount - 1) / activeTotal) * 75
		const endPct = 5 + (processedCount / activeTotal) * 75

		await onProgress(
			startPct,
			pageIsEnhanced
				? t('sheetmusic', 'Enhancing page {current} of {total}…', { current: processedCount, total: activeTotal }, { escape: false })
				: t('sheetmusic', 'Processing page {current} of {total}…', { current: processedCount, total: activeTotal }, { escape: false }),
			processedCount,
			activeTotal,
			pageIsEnhanced,
		)

		// 1. Scan Enhancement (if configured for page)
		if (pageIsEnhanced && activePdfDoc) {
			let pageToRender: any = null
			let enhanceCanvas: HTMLCanvasElement | null = null
			try {
				pageToRender = await activePdfDoc.getPage(pageNum)
				const origPage = pdfDoc.getPage(i)
				const origSize = origPage.getSize()
				const scale = await resolvePageRenderScale(pageToRender, origPage)
				const unscaled = pageToRender.getViewport({ scale: 1.0, rotation: 0 })
				const vp = pageToRender.getViewport({ scale, rotation: 0 })
				const vpScaled = Math.abs(vp.width - unscaled.width * scale) < 2
				const targetW = vpScaled
					? Math.max(1, Math.round(vp.width))
					: Math.max(1, Math.round(origSize.width * scale))
				const targetH = vpScaled
					? Math.max(1, Math.round(vp.height))
					: Math.max(1, Math.round(origSize.height * scale))
				enhanceCanvas = document.createElement('canvas')
				enhanceCanvas.width = targetW
				enhanceCanvas.height = targetH
				const ctx = enhanceCanvas.getContext('2d')
				let rawBuffer: any = null
				if (ctx) {
					ctx.fillStyle = '#ffffff'
					ctx.fillRect(0, 0, enhanceCanvas.width, enhanceCanvas.height)
					await pageToRender.render({
						canvasContext: ctx,
						viewport: vp,
						background: 'rgb(255, 255, 255)',
					}).promise
					const imgData = ctx.getImageData(0, 0, enhanceCanvas.width, enhanceCanvas.height)
					rawBuffer = imageDataToImageBuffer(imgData)
				} else {
					// Headless / test environment fallback when 2D canvas context is unavailable
					rawBuffer = createImageBuffer(targetW, targetH, 4)
					rawBuffer.data.fill(255)
				}

				if (rawBuffer) {
					await yieldToBrowser()
					const enhancedBuffer = applyPipeline(rawBuffer, pageEnhancements[pageNum], {
						skipJpegSimulation: true,
					})
					await yieldToBrowser()
					await executeEnhancePage(pdfDoc, {
						pageIndex: i,
						enhancedImageBuffer: enhancedBuffer,
						operations: pageEnhancements[pageNum],
					})
					modifiedEnhancements = true
				}
			} catch (enhErr) {
				console.warn(`Could not enhance page ${pageNum}`, enhErr)
			} finally {
				releaseCanvas(enhanceCanvas)
				cleanupPdfJsPage(pageToRender)
			}
		}

		// 2. Calculate net rotation & crop
		const rotDelta = pageRotations[i] ?? 0
		const crop = pageCrops[pageNum]
		const hasCrop = Boolean(crop && (crop.left > 0.005 || crop.top > 0.005 || crop.width < 0.995 || crop.height < 0.995))

		const page = pdfDoc.getPage(i)
		const currentAngle = page.getRotation()?.angle ?? 0
		const netAngle = ((currentAngle + rotDelta) % 360 + 360) % 360

		const isMultipleOf90 = Math.abs(netAngle % 90) < 0.001 || Math.abs((netAngle % 90) - 90) < 0.001

		if (!isMultipleOf90) {
			const cropToOriginal = pageRotateCropToOriginal[pageNum] ?? false
			await executeRotatePage(pdfDoc, {
				pageIndex: i,
				angleDegrees: rotDelta,
				crop: hasCrop ? crop : undefined,
				cropToOriginalSize: cropToOriginal,
			})
			modifiedRotations = true
			if (hasCrop) {
				modifiedCrops = true
			}
		} else {
			if (rotDelta !== 0) {
				page.setRotation(degrees(Math.round(netAngle / 90) * 90 % 360))
				modifiedRotations = true
			}

			// 3. Apply Crop if any, taking the net rotation into account
			if (hasCrop && crop) {
				const { x: origX, y: origY, width: origW, height: origH } = page.getCropBox() ?? page.getMediaBox()
				const box = computeCropBoxForRotation(origX, origY, origW, origH, crop, netAngle)
				page.setCropBox(box.x, box.y, box.width, box.height)
				modifiedCrops = true
			}
		}

		await onProgress(
			endPct,
			pageIsEnhanced
				? t('sheetmusic', 'Enhanced page {current} of {total}', { current: processedCount, total: activeTotal }, { escape: false })
				: t('sheetmusic', 'Processed page {current} of {total}', { current: processedCount, total: activeTotal }, { escape: false }),
			processedCount,
			activeTotal,
			false,
		)
	}

	// 4. Remove deleted pages in descending order to avoid index shifts
	let modifiedDeletions = false
	if (deletedPages.size > 0) {
		const indicesToDelete = Array.from(deletedPages)
			.map((p) => p - 1)
			.sort((a, b) => b - a)

		for (const idx of indicesToDelete) {
			if (idx >= 0 && idx < pdfDoc.getPageCount()) {
				pdfDoc.removePage(idx)
				modifiedDeletions = true
			}
		}
	}

	return {
		modifiedRotations,
		modifiedCrops,
		modifiedDeletions,
		modifiedEnhancements,
		activeTotal,
	}
}

/**
 * Formats the localized success notification message describing which PDF edits were saved.
 */
export function formatPdfSaveSuccessMessage(
	songTitle: string,
	flags: {
		modifiedRotations: boolean
		modifiedCrops: boolean
		modifiedDeletions: boolean
		modifiedEnhancements: boolean
		hasAnyJoin: boolean
	},
): string {
	const { modifiedRotations, modifiedCrops, modifiedDeletions, modifiedEnhancements, hasAnyJoin } = flags
	const hasMultipleTypes = [modifiedRotations, modifiedCrops, modifiedDeletions, modifiedEnhancements, hasAnyJoin].filter(Boolean).length > 1
	if (hasMultipleTypes || hasAnyJoin) {
		return t('sheetmusic', 'PDF changes saved successfully for "{title}".', { title: songTitle }, { escape: false })
	}
	if (modifiedEnhancements) {
		return t('sheetmusic', 'Enhanced scan PDF saved successfully for "{title}".', { title: songTitle }, { escape: false })
	}
	if (modifiedDeletions) {
		return t('sheetmusic', 'Pages deleted and PDF saved successfully for "{title}".', { title: songTitle }, { escape: false })
	}
	if (modifiedRotations) {
		return t('sheetmusic', 'Rotated PDF saved successfully for "{title}".', { title: songTitle }, { escape: false })
	}
	return t('sheetmusic', 'Cropped PDF saved successfully for "{title}".', { title: songTitle }, { escape: false })
}
