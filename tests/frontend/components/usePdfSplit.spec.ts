import { describe, expect, it, vi } from 'vitest'
import { computed, ref, shallowRef } from 'vue'
import { getExtractedPageNumbers, getRemainingPageNumbers, usePdfSplit } from '../../../src/components/song-pdf/usePdfSplit'
import type { Song, SongFile } from '../../../src/types/song'

describe('usePdfSplit.ts', () => {
	function setupTest(numPages = 5) {
		const song = computed<Song>(() => ({
			id: 42,
			title: 'Sonata in C',
			files: [{ id: 101, path: 'Sonata.pdf' } as SongFile],
		} as Song))
		const libraryId = computed(() => 1)
		const totalPages = ref(numPages)
		const pageRotations = ref(Array(numPages).fill(0))
		const originalPdfBytes = shallowRef<Uint8Array | null>(null)
		const targetFile = computed(() => song.value.files?.[0])
		const onSaved = vi.fn()
		const onCancel = vi.fn()

		const split = usePdfSplit({
			song,
			libraryId,
			totalPages,
			pageRotations,
			originalPdfBytes,
			targetFile,
			onSaved,
			onCancel,
		})

		return { split, song, totalPages }
	}

	it('initializes default split segment covering all pages', () => {
		const { split } = setupTest(4)
		split.initSplitSegments(4, 'Sonata in C')

		expect(split.splitSegments.value).toHaveLength(1)
		expect(split.splitSegments.value[0]).toEqual({
			id: 'seg-1',
			title: 'Sonata in C - Part 1',
			startPage: 1,
			endPage: 4,
		})
	})

	it('adds segments with sequential start pages and titles', () => {
		const { split } = setupTest(6)
		split.initSplitSegments(6, 'Sonata in C')
		split.splitSegments.value[0].endPage = 2

		split.addSegment()
		expect(split.splitSegments.value).toHaveLength(2)
		expect(split.splitSegments.value[1].title).toBe('Sonata in C - Part 2')
		expect(split.splitSegments.value[1].startPage).toBe(3)
		expect(split.splitSegments.value[1].endPage).toBe(3)
	})

	it('removes segments but maintains at least one segment', () => {
		const { split } = setupTest(4)
		split.initSplitSegments(4, 'Sonata in C')
		split.addSegment()
		expect(split.splitSegments.value).toHaveLength(2)

		split.removeSegment(1)
		expect(split.splitSegments.value).toHaveLength(1)

		// Cannot remove when only 1 segment remains
		split.removeSegment(0)
		expect(split.splitSegments.value).toHaveLength(1)
	})

	it('toggles page selection and assigns selection to segment', () => {
		const { split } = setupTest(8)
		split.initSplitSegments(8, 'Sonata in C')

		split.togglePageSelection(3)
		split.togglePageSelection(5)
		split.togglePageSelection(4)
		expect(split.selectedPages.value).toEqual([3, 5, 4])

		// Toggle off 5
		split.togglePageSelection(5)
		expect(split.selectedPages.value).toEqual([3, 4])

		split.togglePageSelection(7)
		split.assignSelectedPagesToSegment(split.splitSegments.value[0])
		expect(split.splitSegments.value[0].startPage).toBe(3)
		expect(split.splitSegments.value[0].endPage).toBe(7)
		expect(split.selectedPages.value).toEqual([])
	})

	it('generates segment summaries for page cards', () => {
		const { split } = setupTest(4)
		split.initSplitSegments(4, 'Sonata in C')
		split.splitSegments.value[0].startPage = 1
		split.splitSegments.value[0].endPage = 2

		const summariesP1 = split.getPageSegmentsSummary(1)
		expect(summariesP1).toHaveLength(1)
		expect(summariesP1[0].title).toBe('Sonata in C - Part 1')
		expect(summariesP1[0].index).toBe(1)

		const summariesP3 = split.getPageSegmentsSummary(3)
		expect(summariesP3).toHaveLength(0)
	})

	it('selects a contiguous range when shiftKey is true', () => {
		const { split } = setupTest(8)
		split.initSplitSegments(8, 'Sonata in C')

		split.togglePageSelection(2)
		split.togglePageSelection(5, true)
		expect(split.selectedPages.value).toEqual([2, 3, 4, 5])
	})

	it('computes extracted and remaining pages and canRemoveExtractedPages', () => {
		const segments = [
			{ id: 'seg-1', title: 'Part 1', startPage: 2, endPage: 3 },
			{ id: 'seg-2', title: 'Part 2', startPage: 3, endPage: 4 },
		]
		expect(Array.from(getExtractedPageNumbers(segments, 6))).toEqual([2, 3, 4])
		expect(getRemainingPageNumbers(segments, 6)).toEqual([1, 5, 6])

		const { split } = setupTest(5)
		split.initSplitSegments(5, 'Sonata in C')
		// Default segment covers 1..5 -> no remaining pages
		expect(split.canRemoveExtractedPages.value).toBe(false)

		// Narrow segment to 2..3 -> pages 1, 4, 5 remain
		split.splitSegments.value[0].startPage = 2
		split.splitSegments.value[0].endPage = 3
		expect(split.canRemoveExtractedPages.value).toBe(true)
	})

	it('rejects remove-pages save when all pages are covered by split segments', async () => {
		const song = computed<Song>(() => ({
			id: 42,
			title: 'Sonata in C',
			files: [{ id: 101, path: 'Sonata.pdf' } as SongFile],
		} as Song))
		const split = usePdfSplit({
			song,
			libraryId: computed(() => 1),
			totalPages: ref(4),
			pageRotations: ref([0, 0, 0, 0]),
			originalPdfBytes: shallowRef<Uint8Array | null>(new Uint8Array([1, 2, 3])),
			targetFile: computed(() => song.value.files?.[0]),
			onSaved: vi.fn(),
			onCancel: vi.fn(),
		})
		split.initSplitSegments(4, 'Sonata in C')
		split.originalSongAction.value = 'remove-pages'

		const setIsSaving = vi.fn()
		const setErrorMessage = vi.fn()
		await split.saveSplits(setIsSaving, setErrorMessage)

		expect(setIsSaving).not.toHaveBeenCalled()
		expect(setErrorMessage).toHaveBeenCalledWith(expect.stringContaining('Cannot remove all pages'))
	})
})
