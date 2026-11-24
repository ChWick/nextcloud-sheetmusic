import axios from '@nextcloud/axios'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { computed, ref, shallowRef } from 'vue'
import { usePdfBookmarks } from '../../../src/components/song-pdf/usePdfBookmarks'
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

describe('usePdfBookmarks.ts', () => {
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

		const pdfBookmarks = usePdfBookmarks({
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
			pdfBookmarks,
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

	it('initializes bookmarks from existing song bookmarks', () => {
		const { pdfBookmarks, song } = setupTest()
		pdfBookmarks.initBookmarksFromSong(song.value.bookmarks)

		expect(pdfBookmarks.bookmarks.value).toHaveLength(1)
		expect(pdfBookmarks.bookmarks.value[0]).toEqual({
			id: 'bm-1',
			name: 'I. Adagio sostenuto',
			pageNum: 1,
			endPage: 4,
		})
	})

	it('adds a new bookmark with sequential start page', () => {
		const { pdfBookmarks, song } = setupTest(12)
		pdfBookmarks.initBookmarksFromSong(song.value.bookmarks)
		pdfBookmarks.addBookmark()

		expect(pdfBookmarks.bookmarks.value).toHaveLength(2)
		expect(pdfBookmarks.bookmarks.value[1].pageNum).toBe(5)
		expect(pdfBookmarks.bookmarks.value[1].endPage).toBe(12)
	})

	it('removes and updates bookmark boundaries cleanly', () => {
		const { pdfBookmarks } = setupTest(15)
		pdfBookmarks.initBookmarksFromSong([])
		pdfBookmarks.addBookmark()
		pdfBookmarks.addBookmark()

		const firstId = pdfBookmarks.bookmarks.value[0].id
		pdfBookmarks.updateBookmark(firstId, { name: 'I. Allegro', pageNum: 1, endPage: 6 })

		expect(pdfBookmarks.bookmarks.value[0].name).toBe('I. Allegro')
		expect(pdfBookmarks.bookmarks.value[0].pageNum).toBe(1)
		expect(pdfBookmarks.bookmarks.value[0].endPage).toBe(6)

		// Clamps endPage if less than pageNum
		pdfBookmarks.updateBookmark(firstId, { endPage: 0 })
		expect(pdfBookmarks.bookmarks.value[0].endPage).toBe(1)

		// Remove second bookmark
		const secondId = pdfBookmarks.bookmarks.value[1].id
		pdfBookmarks.removeBookmark(secondId)
		expect(pdfBookmarks.bookmarks.value).toHaveLength(1)
	})

	it('assignPagesToBookmark sets start and end page', () => {
		const { pdfBookmarks } = setupTest(20)
		pdfBookmarks.initBookmarksFromSong([])
		pdfBookmarks.addBookmark()
		const id = pdfBookmarks.bookmarks.value[0].id

		pdfBookmarks.assignPagesToBookmark(id, 3, 7)
		expect(pdfBookmarks.bookmarks.value[0].pageNum).toBe(3)
		expect(pdfBookmarks.bookmarks.value[0].endPage).toBe(7)
	})

	it('extracts bookmarks from PDF outline', async () => {
		const { pdfBookmarks, activePdfDoc } = setupTest(15)

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

		const count = await pdfBookmarks.extractFromPdfOutline()
		expect(count).toBe(3)
		expect(pdfBookmarks.bookmarks.value).toEqual([
			{ id: 'bm-1', name: 'I. Allegro', pageNum: 1, endPage: 4 },
			{ id: 'bm-2', name: 'II. Adagio', pageNum: 5, endPage: 9 },
			{ id: 'bm-3', name: 'III. Presto', pageNum: 10, endPage: 15 },
		])
	})

	it('saves bookmarks via API and triggers onSaved', async () => {
		const { pdfBookmarks, onSaved } = setupTest(10)
		pdfBookmarks.initBookmarksFromSong([])
		pdfBookmarks.addBookmark()
		pdfBookmarks.updateBookmark(pdfBookmarks.bookmarks.value[0].id, {
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

		await pdfBookmarks.executeSaveBookmarks()

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

	it('adds a new bookmark from page selection', () => {
		const { pdfBookmarks } = setupTest(10)
		pdfBookmarks.initBookmarksFromSong([])
		pdfBookmarks.addBookmarkFromSelection(3, 7)

		expect(pdfBookmarks.bookmarks.value).toHaveLength(1)
		expect(pdfBookmarks.bookmarks.value[0]).toEqual({
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

		const { pdfBookmarks, onSaved, onPdfUpdated } = setupTest(3, {
			originalPdfBytes: pdfBytes,
			targetFile: { id: 99, path: '/library/sonata.pdf' } as unknown as SongFile,
			autoPdfMetadata: true,
		})

		pdfBookmarks.initBookmarksFromSong([])
		pdfBookmarks.addBookmarkFromSelection(1, 2)
		pdfBookmarks.updateBookmark(pdfBookmarks.bookmarks.value[0].id, { name: 'I. Allegro' })

		// Mock song update
		vi.mocked(axios.post).mockResolvedValueOnce({
			data: { ocs: { data: { id: 42, title: 'Piano Sonata No. 14' } } },
		} as any)

		// Mock file update
		vi.mocked(axios.post).mockResolvedValueOnce({
			data: { ocs: { data: { id: 42, title: 'Piano Sonata No. 14 with updated PDF' } } },
		} as any)

		await pdfBookmarks.executeSaveBookmarks()

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

		const { pdfBookmarks, onSaved, onPdfUpdated } = setupTest(1, {
			originalPdfBytes: pdfBytes,
			targetFile: { id: 99, path: '/library/sonata.pdf' } as unknown as SongFile,
			autoPdfMetadata: false,
		})

		pdfBookmarks.initBookmarksFromSong([])
		pdfBookmarks.addBookmark()

		vi.mocked(axios.post).mockResolvedValueOnce({
			data: { ocs: { data: { id: 42, title: 'Piano Sonata No. 14' } } },
		} as any)

		await pdfBookmarks.executeSaveBookmarks()

		expect(axios.post).toHaveBeenCalledTimes(1)
		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/songs/42'),
			expect.any(Object),
		)
		expect(onPdfUpdated).not.toHaveBeenCalled()
		expect(onSaved).toHaveBeenCalledWith({ id: 42, title: 'Piano Sonata No. 14' }, 42)
	})
})
