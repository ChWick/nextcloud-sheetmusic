import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SetlistPerformanceViewer from '../../../src/components/setlists/SetlistPerformanceViewer.vue'
import type { SetlistItem, Song } from '../../../src/types/song'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockImplementation(async (url: string) => {
			if (url.includes('/annotations')) {
				return { data: { ocs: { data: [] } } }
			}
			return { data: new ArrayBuffer(0) }
		}),
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

		// Open Viewer Settings and enable custom settings for this setlist
		const settingsButton = document.body.querySelector('button[aria-label="Viewer settings & defaults"]') as HTMLButtonElement | null
		expect(settingsButton).not.toBeNull()
		settingsButton!.click()
		await wrapper.vm.$nextTick()

		const setlistScopeBtn = document.body.querySelector('button[data-settings-scope="setlist"]') as HTMLButtonElement | null
		expect(setlistScopeBtn).not.toBeNull()
		setlistScopeBtn!.click()
		await wrapper.vm.$nextTick()

		layoutSelect.value = 'single'
		layoutSelect.dispatchEvent(new Event('change'))
		await wrapper.vm.$nextTick()
		expect(layoutSelect.value).toBe('single')

		// Switch back to library defaults -> restores library default ('double')
		const libraryScopeBtn = document.body.querySelector('button[data-settings-scope="library"]') as HTMLButtonElement | null
		expect(libraryScopeBtn).not.toBeNull()
		libraryScopeBtn!.click()
		await wrapper.vm.$nextTick()
		expect(layoutSelect.value).toBe('double')

		wrapper.unmount()
	})

	it('supports single-song mode with preferredFileId and hides setlist-only controls', async () => {
		const multiFileSong: Song = {
			id: 30,
			title: 'Violin Concerto',
			files: [
				{ id: 301, path: '/dav/files/admin/score.pdf' },
				{ id: 302, path: '/dav/files/admin/violin.pdf' },
			],
		}
		const wrapper = mount(SetlistPerformanceViewer, {
			props: {
				items: [{ id: 30, setlistId: 0, songId: 30, position: 0, songTitle: 'Violin Concerto', pageNum: 2 }],
				songs: [multiFileSong],
				libraryId: 1,
				preferredFileId: 302,
			},
			attachTo: document.body,
		})

		expect(document.body.textContent).toContain('Violin Concerto')
		expect(document.body.textContent).not.toContain('1 / 1')

		const previewerEl = document.body.querySelector('.mock-file-previewer')
		expect(previewerEl?.getAttribute('data-file-path')).toContain('violin.pdf')
		expect(previewerEl?.getAttribute('data-target-page')).toBe('2')

		// Setlist-only controls are hidden in single-song mode
		expect(document.body.querySelector('button[aria-label="Previous song"]')).toBeNull()
		expect(document.body.querySelector('button[aria-label="Next song"]')).toBeNull()
		expect(document.body.querySelector('button[aria-label="Setlist items"]')).toBeNull()
		expect(document.body.querySelector('button[aria-label="Viewer settings & defaults"]')).toBeNull()

		// Page navigation controls remain available
		expect(document.body.querySelector('button[aria-label="Previous page"]')).not.toBeNull()
		expect(document.body.querySelector('button[aria-label="Next page"]')).not.toBeNull()

		wrapper.unmount()
	})

	it('reuses initialAnnotations in single-song mode without re-fetching annotations', async () => {
		const { default: axios } = await import('@nextcloud/axios')
		vi.mocked(axios.get).mockClear()

		const wrapper = mount(SetlistPerformanceViewer, {
			props: {
				items: [{ id: 10, setlistId: 0, songId: 10, position: 0, songTitle: 'Song One', pageNum: 1 }],
				songs: [sampleSongs[0]],
				libraryId: 1,
				initialAnnotations: [],
			},
			attachTo: document.body,
		})
		await wrapper.vm.$nextTick()

		const annotationCalls = vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).includes('/annotations'))
		expect(annotationCalls).toHaveLength(0)
		wrapper.unmount()
	})

	it('preserves fullscreen on unmount when exiting via the Stop button', async () => {
		const exitFullscreenMock = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(document, 'fullscreenElement', { configurable: true, value: document.documentElement })
		Object.defineProperty(document, 'exitFullscreen', { configurable: true, value: exitFullscreenMock })

		try {
			const wrapper = mount(SetlistPerformanceViewer, {
				props: {
					items: sampleItems,
					songs: sampleSongs,
					libraryId: 1,
				},
				attachTo: document.body,
			})

			const stopBtn = document.body.querySelector('[data-test="stop-performance-button"]') as HTMLButtonElement | null
			expect(stopBtn).not.toBeNull()
			stopBtn!.click()
			await wrapper.vm.$nextTick()

			expect(wrapper.emitted('close')).toHaveLength(1)
			wrapper.unmount()
			expect(exitFullscreenMock).not.toHaveBeenCalled()
		} finally {
			Object.defineProperty(document, 'fullscreenElement', { configurable: true, value: null })
		}
	})
})
