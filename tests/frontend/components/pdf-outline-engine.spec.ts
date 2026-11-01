import { describe, expect, it } from 'vitest'
import { PDFDocument, PDFName } from 'pdf-lib'
import { getDocument } from 'pdfjs-dist'
import {
	embedMovementsInPdfBytes,
	setPdfDocumentOutline,
} from '../../../src/components/song-pdf/pdf-outline-engine'

describe('pdf-outline-engine', () => {
	it('writes outline items and can be read back by pdfjs', async () => {
		const doc = await PDFDocument.create()
		doc.addPage([300, 300])
		doc.addPage([300, 300])
		doc.addPage([300, 300])

		const items = [
			{ title: 'I. Allegro moderato', pageNum: 1 },
			{ title: 'II. Scherzo — Allegro', pageNum: 2 },
			{ title: 'III. Rondo: Presto', pageNum: 3 },
		]

		await setPdfDocumentOutline(doc, items)
		expect(doc.catalog.has(PDFName.of('Outlines'))).toBe(true)

		const bytes = await doc.save()
		const loadingTask = getDocument({ data: bytes })
		const loaded = await loadingTask.promise
		const outline = await loaded.getOutline()

		expect(outline).toHaveLength(3)
		expect(outline[0].title).toBe('I. Allegro moderato')
		expect(outline[1].title).toBe('II. Scherzo — Allegro')
		expect(outline[2].title).toBe('III. Rondo: Presto')
	})

	it('removes /Outlines when empty items are provided', async () => {
		const doc = await PDFDocument.create()
		doc.addPage([300, 300])

		// First add
		await setPdfDocumentOutline(doc, [{ title: 'Intro', pageNum: 1 }])
		expect(doc.catalog.has(PDFName.of('Outlines'))).toBe(true)

		// Then remove
		await setPdfDocumentOutline(doc, [])
		expect(doc.catalog.has(PDFName.of('Outlines'))).toBe(false)

		const bytes = await doc.save()
		const loadingTask = getDocument({ data: bytes })
		const loaded = await loadingTask.promise
		const outline = await loaded.getOutline()
		expect(outline).toBeNull()
	})

	it('embedMovementsInPdfBytes serializes PDF with movements', async () => {
		const doc = await PDFDocument.create()
		doc.addPage([200, 200])
		doc.addPage([200, 200])
		const initialBytes = await doc.save()

		const updatedBytes = await embedMovementsInPdfBytes(initialBytes, [
			{ name: 'Movement 1', pageNum: 1 },
			{ name: 'Movement 2', pageNum: 2 },
		])

		expect(updatedBytes.length).toBeGreaterThan(0)

		const loadingTask = getDocument({ data: updatedBytes })
		const loaded = await loadingTask.promise
		const outline = await loaded.getOutline()
		expect(outline).toHaveLength(2)
		expect(outline[0].title).toBe('Movement 1')
		expect(outline[1].title).toBe('Movement 2')
	})

	it('handles Unicode and special characters in movement titles', async () => {
		const doc = await PDFDocument.create()
		doc.addPage([200, 200])
		const initialBytes = await doc.save()

		const updatedBytes = await embedMovementsInPdfBytes(initialBytes, [
			{ name: 'Sonate № 14 „Mondschein“ — Adagio sostenuto', pageNum: 1 },
		])

		const loadingTask = getDocument({ data: updatedBytes })
		const loaded = await loadingTask.promise
		const outline = await loaded.getOutline()
		expect(outline).toHaveLength(1)
		expect(outline[0].title).toBe('Sonate № 14 „Mondschein“ — Adagio sostenuto')
	})
})
