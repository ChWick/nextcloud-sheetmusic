import { describe, expect, it } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import {
	buildInitialMergePages,
	buildMergedFileName,
	buildMergedPdf,
	duplicateMergePage,
	getSourceFileColor,
	removeMergePage,
	reorderMergePages,
	reorderPagesByFileOrder,
	rotateMergePage,
	type MergeSourcePdf,
} from '../../../src/components/song-pdf/pdf-merge-engine'

async function createSizedPdf(sizes: Array<[number, number]>): Promise<Uint8Array> {
	const doc = await PDFDocument.create()
	for (const [w, h] of sizes) {
		doc.addPage([w, h])
	}
	return doc.save()
}

describe('pdf-merge-engine', () => {
	it('generates initial merge pages for all or included sources', () => {
		const sources = [
			{ fileId: 10, pageCount: 2 },
			{ fileId: 20, pageCount: 3 },
		]

		const all = buildInitialMergePages(sources)
		expect(all).toHaveLength(5)
		expect(all.map((p) => `${p.fileId}:${p.sourcePageNum}`)).toEqual([
			'10:1',
			'10:2',
			'20:1',
			'20:2',
			'20:3',
		])

		const onlySecond = buildInitialMergePages(sources, new Set([20]))
		expect(onlySecond).toHaveLength(3)
		expect(onlySecond.every((p) => p.fileId === 20)).toBe(true)
	})

	it('reorders pages cleanly', () => {
		const pages = buildInitialMergePages([
			{ fileId: 1, pageCount: 2 },
			{ fileId: 2, pageCount: 1 },
		])
		const moved = reorderMergePages(pages, 2, 0)
		expect(moved.map((p) => `${p.fileId}:${p.sourcePageNum}`)).toEqual(['2:1', '1:1', '1:2'])

		const movedBack = reorderMergePages(moved, 0, 2)
		expect(movedBack.map((p) => `${p.fileId}:${p.sourcePageNum}`)).toEqual(['1:1', '1:2', '2:1'])
	})

	it('reorders pages by file order while preserving internal order and rotations', () => {
		let pages = buildInitialMergePages([
			{ fileId: 1, pageCount: 2 },
			{ fileId: 2, pageCount: 2 },
		])
		// Rotate page 2 of file 1
		pages = rotateMergePage(pages, 1, 90)
		// Swap file 1 and file 2 order
		const reordered = reorderPagesByFileOrder(pages, [2, 1])
		expect(reordered.map((p) => `${p.fileId}:${p.sourcePageNum}:${p.rotation}`)).toEqual([
			'2:1:0',
			'2:2:0',
			'1:1:0',
			'1:2:90',
		])
		expect(reordered[0].fileIndex).toBe(0)
		expect(reordered[2].fileIndex).toBe(1)
	})

	it('rotates, duplicates, and removes pages', () => {
		let pages = buildInitialMergePages([{ fileId: 1, pageCount: 2 }])
		pages = rotateMergePage(pages, 0, -90)
		expect(pages[0].rotation).toBe(270)

		pages = duplicateMergePage(pages, 0)
		expect(pages).toHaveLength(3)
		expect(pages[1].sourcePageNum).toBe(1)
		expect(pages[1].rotation).toBe(270)
		expect(pages[1].id).not.toBe(pages[0].id)

		pages = removeMergePage(pages, 0)
		expect(pages).toHaveLength(2)
		expect(pages.map((p) => p.sourcePageNum)).toEqual([1, 2])
	})

	it('builds sanitized merged file names and deterministic source colors', () => {
		expect(buildMergedFileName('Sonata / No. 1?')).toBe('Sonata - No. 1- (merged).pdf')
		expect(buildMergedFileName('   ')).toBe('merged.pdf')
		expect(getSourceFileColor(0)).toMatch(/^#/)
		expect(getSourceFileColor(8)).toBe(getSourceFileColor(0))
	})

	it('merges real PDF documents in rearranged order with rotations and duplicates', async () => {
		const pdfA = await createSizedPdf([[300, 400], [320, 420]])
		const pdfB = await createSizedPdf([[500, 600], [520, 620]])

		const sources: MergeSourcePdf[] = [
			{ fileId: 1, fileName: 'part1.pdf', bytes: pdfA, pageCount: 2 },
			{ fileId: 2, fileName: 'part2.pdf', bytes: pdfB, pageCount: 2 },
		]

		let pages = buildInitialMergePages(sources)
		// Rearrange: B2 (rotated 90), A1, B1, A1 (duplicate)
		pages = [
			{ ...pages[3], rotation: 90 },
			pages[0],
			pages[2],
		]
		pages = duplicateMergePage(pages, 1)

		const progressCalls: Array<[number, number]> = []
		const mergedBytes = await buildMergedPdf(sources, pages, (cur, total) => {
			progressCalls.push([cur, total])
		})

		expect(progressCalls).toEqual([[1, 4], [2, 4], [3, 4], [4, 4]])

		const mergedDoc = await PDFDocument.load(mergedBytes)
		expect(mergedDoc.getPageCount()).toBe(4)

		expect(mergedDoc.getPage(0).getWidth()).toBeCloseTo(520, 1)
		expect(mergedDoc.getPage(0).getRotation().angle).toBe(90)

		expect(mergedDoc.getPage(1).getWidth()).toBeCloseTo(300, 1)
		expect(mergedDoc.getPage(2).getWidth()).toBeCloseTo(300, 1)
		expect(mergedDoc.getPage(3).getWidth()).toBeCloseTo(500, 1)
	})

	it('throws when trying to merge zero pages', async () => {
		await expect(buildMergedPdf([], [])).rejects.toThrow('Cannot merge PDFs with zero pages.')
	})
})
