import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SetlistPerformanceViewer from '../../../src/components/setlists/SetlistPerformanceViewer.vue'
import type { SetlistItem, Song } from '../../../src/types/song'

vi.mock('../../../src/api/annotations', () => ({
	fetchSongAnnotations: vi.fn().mockResolvedValue([]),
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: new ArrayBuffer(0) }),
	},
}))

const mockPrevPage = vi.fn().mockReturnValue(false)
const mockNextPage = vi.fn().mockReturnValue(false)
const mockSetPageLayout = vi.fn()
const mockSetAutoLayout = vi.fn()
const mockSetDoublePageTurnMode = vi.fn()
const mockSetZoomMode = vi.fn()
const mockToggleAnnotations = vi.fn()

vi.mock('../../../src/components/FilePreviewer.vue', () => ({
	default: {
		name: 'FilePreviewer',
		props: [
			'filePath',
			'pageOrder',
			'targetPage',
			'startAtEnd',
			'annotations',
			'showAnnotations',
			'hideToolbar',
			'showExternalButton',
			'initialPageLayout',
			'initialDoublePageTurnMode',
			'initialZoomMode',
		],
		setup(_props: unknown, { expose }: { expose: (exposed: Record<string, unknown>) => void }) {
			expose({
				prevPage: mockPrevPage,
				nextPage: mockNextPage,
				setPageLayout: mockSetPageLayout,
				setAutoLayout: mockSetAutoLayout,
				setDoublePageTurnMode: mockSetDoublePageTurnMode,
				setZoomMode: mockSetZoomMode,
				toggleAnnotations: mockToggleAnnotations,
				pageLayout: 'double',
				currentPageDisplay: '1–2',
				totalPages: 4,
				canGoPrev: false,
				canGoNext: true,
			})
			return {}
		},
		template: '<div class="mock-file-previewer" :data-file-path="filePath" :data-target-page="targetPage" />',
	},
}))

const sampleSongs: Song[] = [
	{
		id: 10,
		title: 'Song One',
		files: [{ id: 1, path: '/SheetMusic/one.pdf', isPrimary: true }],
	},
	{
		id: 20,
		title: 'Song Two',
		files: [{ id: 2, path: '/SheetMusic/two.pdf', isPrimary: true }],
	},
]

const sampleItems: SetlistItem[] = [
	{ id: 1, setlistId: 5, songId: 10, position: 0, songTitle: 'Song One', pageNum: 1 },
	{ id: 2, setlistId: 5, songId: 20, position: 1, songTitle: 'Song Two', bookmarkName: 'Coda', pageNum: 3 },
]

describe('SetlistPerformanceViewer', () => {
	it('renders the initial setlist item and advances to the next song when at end of pages', async () => {
		mockNextPage.mockReturnValueOnce(false)
		const wrapper = mount(SetlistPerformanceViewer, {
			props: {
				setlistName: 'Friday Gig',
				items: sampleItems,
				songs: sampleSongs,
				libraryId: 1,
			},
		})

		expect(wrapper.text()).toContain('Song One')
		expect(wrapper.text()).toContain('1 / 2')
		expect(wrapper.find('.mock-file-previewer').attributes('data-file-path')).toContain('one.pdf')

		// Press ArrowRight -> mockNextPage returns false -> advances to Song Two
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Song Two')
		expect(wrapper.text()).toContain('2 / 2')
		expect(wrapper.text()).toContain('Coda')
		expect(wrapper.find('.mock-file-previewer').attributes('data-target-page')).toBe('3')

		wrapper.unmount()
	})

	it('allows changing double page turn mode and emits close on Escape', async () => {
		const wrapper = mount(SetlistPerformanceViewer, {
			props: {
				setlistName: 'Friday Gig',
				items: sampleItems,
				songs: sampleSongs,
				libraryId: 1,
			},
		})
		await wrapper.vm.$nextTick()

		const turnModeSelect = wrapper.find('select[aria-label="Page turning mode"]')
		expect(turnModeSelect.exists()).toBe(true)
		await turnModeSelect.setValue('step')
		expect(mockSetDoublePageTurnMode).toHaveBeenCalledWith('step')

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		expect(wrapper.emitted('close')).toBeTruthy()

		wrapper.unmount()
	})
})
