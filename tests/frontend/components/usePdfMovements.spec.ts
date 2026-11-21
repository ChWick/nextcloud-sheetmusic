import axios from '@nextcloud/axios'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { computed, ref, shallowRef } from 'vue'
import { usePdfMovements } from '../../../src/components/song-pdf/usePdfMovements'
import type { Song, SongFile } from '../../../src/types/song'
import { PDFDocument } from 'pdf-lib'

vi.mock('@nextcloud/axios', () => ({
	default: {
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

describe('usePdfMovements.ts', () => {
	function setupTest(numPages = 10, options: {
		originalPdfBytes?: Uint8Array | null
		targetFile?: SongFile
		autoPdfMetadata?: boolean
	} = {}) {
		const song = computed<Song>(() => ({
			id: 42,
			title: 'Piano Sonata No. 14',
			composers: [{ id: 1, name: 'Beethoven' }],
			bookmarks: [
				{ id: 10, songId: 42, name: 'I. Adagio sostenuto', pageNum: 1, endPage: 4 },
			],
		} as unknown as Song))
		const libraryId = computed(() => 1)
		const totalPages = ref(numPages)
		const activePdfDoc = shallowRef<any>(null)
		const originalPdfBytes = ref<Uint8Array | null>(options.originalPdfBytes ?? null)
		const targetFile = computed<SongFile | undefined>(() => options.targetFile)
		const autoPdfMetadata = computed(() => options.autoPdfMetadata ?? true)
		const onPdfUpdated = vi.fn()
		const onSaved = vi.fn()
		const onCancel = vi.fn()

		const movements = usePdfMovements({
			song,
			libraryId,
			totalPages,
			activePdfDoc,
			originalPdfBytes,
			targetFile,
			autoPdfMetadata,
			onPdfUpdated,
			onSaved,
			onCancel,
		})

		return {
			movements,
			song,
			totalPages,
			activePdfDoc,
			originalPdfBytes,
			targetFile,
			autoPdfMetadata,
			onPdfUpdated,
			onSaved,
			onCancel,
		}
	}

	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('initializes movements from existing bookmarks', () => {
		const { movements, song } = setupTest()
		movements.initMovementsFromSong(song.value.bookmarks)

		expect(movements.movements.value).toHaveLength(1)
		expect(movements.movements.value[0]).toEqual({
			id: 'bm-1',
			name: 'I. Adagio sostenuto',
			pageNum: 1,
			endPage: 4,
		})
	})

	it('adds a new movement with sequential start page', () => {
		const { movements, song } = setupTest(12)
		movements.initMovementsFromSong(song.value.bookmarks)
		movements.addMovement()

		expect(movements.movements.value).toHaveLength(2)
		expect(movements.movements.value[1].pageNum).toBe(5)
		expect(movements.movements.value[1].endPage).toBe(12)
	})

	it('removes and updates movement boundaries cleanly', () => {
		const { movements } = setupTest(15)
		movements.initMovementsFromSong([])
		movements.addMovement()
		movements.addMovement()

		const firstId = movements.movements.value[0].id
		movements.updateMovement(firstId, { name: 'I. Allegro', pageNum: 1, endPage: 6 })

		expect(movements.movements.value[0].name).toBe('I. Allegro')
		expect(movements.movements.value[0].pageNum).toBe(1)
		expect(movements.movements.value[0].endPage).toBe(6)

		// Clamps endPage if less than pageNum
		movements.updateMovement(firstId, { endPage: 0 })
		expect(movements.movements.value[0].endPage).toBe(1)

		// Remove second movement
		const secondId = movements.movements.value[1].id
		movements.removeMovement(secondId)
		expect(movements.movements.value).toHaveLength(1)
	})

	it('assignPagesToMovement sets start and end page', () => {
		const { movements } = setupTest(20)
		movements.initMovementsFromSong([])
		movements.addMovement()
		const id = movements.movements.value[0].id

		movements.assignPagesToMovement(id, 3, 7)
		expect(movements.movements.value[0].pageNum).toBe(3)
		expect(movements.movements.value[0].endPage).toBe(7)
	})

	it('extracts movements from PDF outline', async () => {
		const { movements, activePdfDoc } = setupTest(15)

		activePdfDoc.value = {
			getOutline: vi.fn().mockResolvedValue([
				{ title: 'I. Allegro', dest: [{ num: 10, gen: 0 }] },
				{ title: 'II. Adagio', dest: [{ num: 20, gen: 0 }] },
				{ title: 'III. Presto', dest: [{ num: 30, gen: 0 }] },
			]),
			getPageIndex: vi.fn().mockImplementation((ref: any) => {
				if (ref.num === 10) return 0 // page 1
				if (ref.num === 20) return 4 // page 5
				if (ref.num === 30) return 9 // page 10
				return -1
			}),
		}

		const count = await movements.extractFromPdfOutline()
		expect(count).toBe(3)
		expect(movements.movements.value).toEqual([
			{ id: 'bm-1', name: 'I. Allegro', pageNum: 1, endPage: 4 },
			{ id: 'bm-2', name: 'II. Adagio', pageNum: 5, endPage: 9 },
			{ id: 'bm-3', name: 'III. Presto', pageNum: 10, endPage: 15 },
		])
	})

	it('saves movements via API and triggers onSaved', async () => {
		const { movements, onSaved } = setupTest(10)
		movements.initMovementsFromSong([])
		movements.addMovement()
		movements.updateMovement(movements.movements.value[0].id, {
			name: 'I. Moderato',
			pageNum: 1,
			endPage: 5,
		})

		vi.mocked(axios.post).mockResolvedValueOnce({
			data: {
				ocs: {
					data: { id: 42, title: 'Piano Sonata No. 14' },
				},
			},
		} as any)

		await movements.executeSaveMovements()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/songs/42'),
			expect.objectContaining({
				bookmarks: [
					{
						name: 'I. Moderato',
						pageNum: 1,
						endPage: 5,
						position: 0,
						showInLibrary: 1,
					},
				],
			}),
		)
		expect(onSaved).toHaveBeenCalledWith({ id: 42, title: 'Piano Sonata No. 14' }, 42)
	})

	it('adds a new movement from page selection', () => {
		const { movements } = setupTest(10)
		movements.initMovementsFromSong([])
		movements.addMovementFromSelection(3, 7)

		expect(movements.movements.value).toHaveLength(1)
		expect(movements.movements.value[0]).toEqual({
			id: 'bm-1',
			name: 'Bookmark 1',
			pageNum: 3,
			endPage: 7,
		})
	})

	it('embeds PDF Table of Contents and uploads modified PDF when autoPdfMetadata is enabled', async () => {
		const doc = await PDFDocument.create()
		doc.addPage([200, 200])
		doc.addPage([200, 200])
		doc.addPage([200, 200])
		const pdfBytes = await doc.save()

		const { movements, onSaved, onPdfUpdated } = setupTest(3, {
			originalPdfBytes: pdfBytes,
			targetFile: { id: 99, path: '/library/sonata.pdf' } as unknown as SongFile,
			autoPdfMetadata: true,
		})

		movements.initMovementsFromSong([])
		movements.addMovementFromSelection(1, 2)
		movements.updateMovement(movements.movements.value[0].id, { name: 'I. Allegro' })

		// Mock song update
		vi.mocked(axios.post).mockResolvedValueOnce({
			data: { ocs: { data: { id: 42, title: 'Piano Sonata No. 14' } } },
		} as any)

		// Mock file update
		vi.mocked(axios.post).mockResolvedValueOnce({
			data: { ocs: { data: { id: 42, title: 'Piano Sonata No. 14 with updated PDF' } } },
		} as any)

		await movements.executeSaveMovements()

		// Should have called song metadata update
		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/songs/42'),
			expect.any(Object),
		)

		// Should have called file update with FormData
		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/libraries/1/songs/42/file/99/update'),
			expect.any(FormData),
		)

		expect(onPdfUpdated).toHaveBeenCalled()
		expect(onSaved).toHaveBeenCalledWith(
			{ id: 42, title: 'Piano Sonata No. 14 with updated PDF' },
			42,
		)
	})

	it('does not upload or modify PDF file when autoPdfMetadata is false', async () => {
		const doc = await PDFDocument.create()
		doc.addPage([200, 200])
		const pdfBytes = await doc.save()

		const { movements, onSaved, onPdfUpdated } = setupTest(1, {
			originalPdfBytes: pdfBytes,
			targetFile: { id: 99, path: '/library/sonata.pdf' } as unknown as SongFile,
			autoPdfMetadata: false,
		})

		movements.initMovementsFromSong([])
		movements.addMovement()

		vi.mocked(axios.post).mockResolvedValueOnce({
			data: { ocs: { data: { id: 42, title: 'Piano Sonata No. 14' } } },
		} as any)

		await movements.executeSaveMovements()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/songs/42'),
			expect.any(Object),
		)
		expect(onPdfUpdated).not.toHaveBeenCalled()
		expect(onSaved).toHaveBeenCalledWith({ id: 42, title: 'Piano Sonata No. 14' }, 42)
	})
})
