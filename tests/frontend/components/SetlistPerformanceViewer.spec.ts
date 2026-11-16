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
		template: '<div class="mock-file-previewer" :data-file-path="filePath" :data-target-page="targetPage" :data-initial-layout="initialPageLayout" />',
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
			attachTo: document.body,
		})

		expect(document.body.textContent).toContain('Song One')
		expect(document.body.textContent).toContain('1 / 2')
		const previewerEl = document.body.querySelector('.mock-file-previewer')
		expect(previewerEl?.getAttribute('data-file-path')).toContain('one.pdf')

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
		await wrapper.vm.$nextTick()

		expect(document.body.textContent).toContain('Song Two')
		expect(document.body.textContent).toContain('2 / 2')
		expect(document.body.textContent).toContain('Coda')
		expect(document.body.querySelector('.mock-file-previewer')?.getAttribute('data-target-page')).toBe('3')

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
			attachTo: document.body,
		})
		await wrapper.vm.$nextTick()

		const stepButton = document.body.querySelector('button[data-turn-mode="step"]') as HTMLButtonElement | null
		expect(stepButton).not.toBeNull()
		stepButton!.click()
		expect(mockSetDoublePageTurnMode).toHaveBeenCalledWith('step')

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		expect(wrapper.emitted('close')).toBeTruthy()

		wrapper.unmount()
	})

	it('auto-hides the HUD bars after inactivity', async () => {
		vi.useFakeTimers()
		const wrapper = mount(SetlistPerformanceViewer, {
			props: {
				setlistName: 'Friday Gig',
				items: sampleItems,
				songs: sampleSongs,
				libraryId: 1,
			},
			attachTo: document.body,
		})

		const footer = document.body.querySelector('footer') as HTMLElement | null
		expect(footer).not.toBeNull()
		expect(footer!.style.display).not.toBe('none')

		vi.advanceTimersByTime(3100)
		await wrapper.vm.$nextTick()

		expect(footer!.style.display).toBe('none')

		wrapper.unmount()
		vi.useRealTimers()
	})

	it('preserves chosen double layout when jumping to a bookmark song', async () => {
		const wrapper = mount(SetlistPerformanceViewer, {
			props: {
				setlistName: 'Friday Gig',
				items: sampleItems,
				songs: sampleSongs,
				libraryId: 1,
			},
			attachTo: document.body,
		})

		const layoutSelect = document.body.querySelector('select[aria-label="Page layout"]') as HTMLSelectElement | null
		expect(layoutSelect).not.toBeNull()
		layoutSelect!.value = 'double'
		layoutSelect!.dispatchEvent(new Event('change'))
		await wrapper.vm.$nextTick()

		// Advance to Song Two (which has a bookmark at page 3)
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
		await wrapper.vm.$nextTick()

		const previewerEl = document.body.querySelector('.mock-file-previewer')
		expect(previewerEl?.getAttribute('data-target-page')).toBe('3')
		expect(previewerEl?.getAttribute('data-initial-layout')).toBe('double')

		wrapper.unmount()
	})

	it('toggles HUD bars on click in continuous scroll mode', async () => {
		const wrapper = mount(SetlistPerformanceViewer, {
			props: {
				setlistName: 'Friday Gig',
				items: sampleItems,
				songs: sampleSongs,
				libraryId: 1,
			},
			attachTo: document.body,
		})

		const layoutSelect = document.body.querySelector('select[aria-label="Page layout"]') as HTMLSelectElement | null
		layoutSelect!.value = 'continuous'
		layoutSelect!.dispatchEvent(new Event('change'))
		await wrapper.vm.$nextTick()

		const footer = document.body.querySelector('footer') as HTMLElement
		const stage = document.body.querySelector('.mock-file-previewer')?.parentElement as HTMLElement
		expect(footer.style.display).not.toBe('none')

		stage.click()
		await wrapper.vm.$nextTick()
		expect(footer.style.display).toBe('none')

		stage.click()
		await wrapper.vm.$nextTick()
		expect(footer.style.display).not.toBe('none')

		wrapper.unmount()
	})

	it('supports setlist-specific override without overwriting library defaults', async () => {
		window.localStorage.clear()
		const wrapper = mount(SetlistPerformanceViewer, {
			props: {
				setlistId: 5,
				setlistName: 'Friday Gig',
				items: sampleItems,
				songs: sampleSongs,
				libraryId: 1,
			},
			attachTo: document.body,
		})

		// Change layout while override is off -> updates library default
		const layoutSelect = document.body.querySelector('select[aria-label="Page layout"]') as HTMLSelectElement
		layoutSelect.value = 'double'
		layoutSelect.dispatchEvent(new Event('change'))
		await wrapper.vm.$nextTick()

		// Enable setlist override and switch to single page
		const overrideCheckbox = document.body.querySelector('input[type="checkbox"]') as HTMLInputElement
		expect(overrideCheckbox).not.toBeNull()
		overrideCheckbox.checked = true
		overrideCheckbox.dispatchEvent(new Event('change'))
		await wrapper.vm.$nextTick()

		layoutSelect.value = 'single'
		layoutSelect.dispatchEvent(new Event('change'))
		await wrapper.vm.$nextTick()
		expect(layoutSelect.value).toBe('single')

		// Uncheck setlist override -> restores library default ('double')
		overrideCheckbox.checked = false
		overrideCheckbox.dispatchEvent(new Event('change'))
		await wrapper.vm.$nextTick()
		expect(layoutSelect.value).toBe('double')

		wrapper.unmount()
	})
})
