import { describe, expect, it, vi } from 'vitest'
import { computed, ref, shallowRef } from 'vue'
import { usePdfSplit } from '../../../src/components/song-pdf/usePdfSplit'
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
})
