/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { PDFDocument, degrees } from 'pdf-lib'
import { pruneUnreferencedPdfObjects } from './pdf-enhance-engine'

export interface MergeSourcePdf {
	fileId: number | string
	fileName: string
	bytes: Uint8Array
	pageCount: number
}

export interface MergePageEntry {
	id: string
	fileId: number | string
	fileIndex: number
	sourcePageNum: number
	rotation: number
}

export type MergeOriginalsAction = 'replace-and-delete' | 'replace-and-detach' | 'keep-all-new-file'

export const SOURCE_FILE_COLORS = [
	'#0082c9',
	'#2e7d32',
	'#c62828',
	'#6a1b9a',
	'#ef6c00',
	'#00838f',
	'#ad1457',
	'#4527a0',
] as const

export function getSourceFileColor(fileIndex: number): string {
	return SOURCE_FILE_COLORS[Math.abs(fileIndex) % SOURCE_FILE_COLORS.length]
}

let entryCounter = 0

export function createMergePageEntry(
	fileId: number | string,
	fileIndex: number,
	sourcePageNum: number,
	rotation = 0,
): MergePageEntry {
	entryCounter++
	return {
		id: `merge-page-${fileId}-${sourcePageNum}-${entryCounter}`,
		fileId,
		fileIndex,
		sourcePageNum,
		rotation: normalizeRotation(rotation),
	}
}

export function normalizeRotation(deg: number): number {
	return ((deg % 360) + 360) % 360
}

export function buildInitialMergePages(
	sources: Array<{ fileId: number | string; pageCount: number }>,
	includedFileIds?: Set<number | string>,
): MergePageEntry[] {
	const pages: MergePageEntry[] = []
	sources.forEach((source, fileIndex) => {
		if (includedFileIds && !includedFileIds.has(source.fileId)) {
			return
		}
		const count = Math.max(0, source.pageCount)
		for (let p = 1; p <= count; p++) {
			pages.push(createMergePageEntry(source.fileId, fileIndex, p, 0))
		}
	})
	return pages
}

export function reorderMergePages(
	pages: MergePageEntry[],
	fromIndex: number,
	toIndex: number,
): MergePageEntry[] {
	if (fromIndex === toIndex || fromIndex < 0 || fromIndex >= pages.length) {
		return [...pages]
	}
	const next = [...pages]
	const [moved] = next.splice(fromIndex, 1)
	const clampedTarget = Math.max(0, Math.min(toIndex, next.length))
	next.splice(clampedTarget, 0, moved)
	return next
}

/**
 * Reorders existing merge pages so that pages are grouped according to `orderedFileIds`,
 * preserving each file's internal page order, duplicates, removals, and rotations.
 */
export function reorderPagesByFileOrder(
	pages: MergePageEntry[],
	orderedFileIds: Array<number | string>,
): MergePageEntry[] {
	const indexMap = new Map<number | string, number>()
	orderedFileIds.forEach((id, idx) => indexMap.set(id, idx))

	const buckets = new Map<number | string, MergePageEntry[]>()
	for (const id of orderedFileIds) {
		buckets.set(id, [])
	}
	for (const page of pages) {
		const bucket = buckets.get(page.fileId)
		if (bucket) {
			bucket.push({
				...page,
				fileIndex: indexMap.get(page.fileId) ?? page.fileIndex,
			})
		}
	}

	const result: MergePageEntry[] = []
	for (const id of orderedFileIds) {
		const bucket = buckets.get(id)
		if (bucket) {
			result.push(...bucket)
		}
	}
	return result
}

export function rotateMergePage(
	pages: MergePageEntry[],
	index: number,
	deltaDegrees: number,
): MergePageEntry[] {
	if (index < 0 || index >= pages.length) {
		return [...pages]
	}
	return pages.map((entry, i) =>
		i === index
			? { ...entry, rotation: normalizeRotation(entry.rotation + deltaDegrees) }
			: entry,
	)
}

export function duplicateMergePage(
	pages: MergePageEntry[],
	index: number,
): MergePageEntry[] {
	if (index < 0 || index >= pages.length) {
		return [...pages]
	}
	const original = pages[index]
	const copy = createMergePageEntry(
		original.fileId,
		original.fileIndex,
		original.sourcePageNum,
		original.rotation,
	)
	const next = [...pages]
	next.splice(index + 1, 0, copy)
	return next
}

export function removeMergePage(
	pages: MergePageEntry[],
	index: number,
): MergePageEntry[] {
	if (index < 0 || index >= pages.length) {
		return [...pages]
	}
	const next = [...pages]
	next.splice(index, 1)
	return next
}

export function buildMergedFileName(songTitle: string, fallbackName = 'merged.pdf'): string {
	const trimmed = songTitle.trim()
	if (!trimmed) {
		return fallbackName.toLowerCase().endsWith('.pdf') ? fallbackName : `${fallbackName}.pdf`
	}
	const sanitized = trimmed.replace(/[/\\?%*:|"<>]/g, '-').trim()
	if (!sanitized) {
		return fallbackName
	}
	return `${sanitized} (merged).pdf`
}

/**
 * Merges pages from one or more source PDFs in the exact order and rotation
 * specified by `pages`, returning the encoded PDF bytes.
 */
export async function buildMergedPdf(
	sources: MergeSourcePdf[],
	pages: MergePageEntry[],
	onProgress?: (current: number, total: number) => Promise<void> | void,
): Promise<Uint8Array> {
	if (pages.length === 0) {
		throw new Error('Cannot merge PDFs with zero pages.')
	}

	const sourceMap = new Map<number | string, MergeSourcePdf>()
	for (const src of sources) {
		sourceMap.set(src.fileId, src)
	}

	// Load only the referenced source PDFs once
	const loadedDocs = new Map<number | string, PDFDocument>()
	for (const entry of pages) {
		if (loadedDocs.has(entry.fileId)) continue
		const src = sourceMap.get(entry.fileId)
		if (!src) {
			throw new Error(`Missing source PDF for file ID ${String(entry.fileId)}.`)
		}
		loadedDocs.set(entry.fileId, await PDFDocument.load(src.bytes))
	}

	const mergedDoc = await PDFDocument.create()
	const total = pages.length

	for (let i = 0; i < total; i++) {
		const entry = pages[i]
		const srcDoc = loadedDocs.get(entry.fileId)
		if (!srcDoc) {
			throw new Error(`Source PDF not loaded for file ID ${String(entry.fileId)}.`)
		}
		const pageIdx = entry.sourcePageNum - 1
		if (pageIdx < 0 || pageIdx >= srcDoc.getPageCount()) {
			throw new Error(`Page ${entry.sourcePageNum} is out of range for source PDF ${String(entry.fileId)}.`)
		}

		const [copiedPage] = await mergedDoc.copyPages(srcDoc, [pageIdx])
		if (entry.rotation !== 0) {
			const baseAngle = copiedPage.getRotation()?.angle ?? 0
			const netAngle = normalizeRotation(baseAngle + entry.rotation)
			copiedPage.setRotation(degrees(netAngle))
		}
		mergedDoc.addPage(copiedPage)

		if (onProgress) {
			await onProgress(i + 1, total)
		}
	}

	await pruneUnreferencedPdfObjects(mergedDoc)
	return mergedDoc.save()
}
