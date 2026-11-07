import type { Song } from '../../../src/types/song'

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import SongViewer from '../../../src/components/SongViewer.vue'

// ── Mocks ──────────────────────────────────────────────────────────────────────

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateFilePath: vi.fn((_app: string, _type: string, path: string) => `/apps-extra/sheetmusic/js/${path}`),
	generateOcsUrl: vi.fn((path: string, params?: Record<string, any>) => {
		let res = path
		if (params) {
			for (const [k, v] of Object.entries(params)) {
				res = res.replace(`{${k}}`, String(v))
			}
		}
		return `/ocs/v2.php${res}`
	}),
}))

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return {
		...actual,
		translate: vi.fn((_app: string, text: string, vars?: Record<string, any>) => {
			if (!vars) return text
			let res = text
			for (const [k, v] of Object.entries(vars)) {
				res = res.replace(`{${k}}`, String(v))
			}
			return res
		}),
		getLanguage: vi.fn(() => 'en'),
		isRTL: vi.fn(() => false),
	}
})

vi.mock('@nextcloud/dialogs', () => ({
	FilePickerClosed: class FilePickerClosed extends Error {},
	getFilePickerBuilder: vi.fn(),
}))

// vi.mock factories are hoisted above all other code, so any variables they
// reference must be created with vi.hoisted() to be available at that point.
const { mockGetDocument, mockRenderPromise, mockPage, mockPdf } = vi.hoisted(() => {
	const mockRenderPromise = { promise: Promise.resolve() }
	const mockPage = {
		getViewport: vi.fn(() => ({ width: 200, height: 300 })),
		render: vi.fn(() => mockRenderPromise),
	}
	const mockPdf = {
		numPages: 1,
		getPage: vi.fn(() => Promise.resolve(mockPage)),
	}
	const mockGetDocument = vi.fn(() => ({ promise: Promise.resolve(mockPdf) }))
	return { mockGetDocument, mockRenderPromise, mockPage, mockPdf }
})

vi.mock('pdfjs-dist', () => ({
	getDocument: mockGetDocument,
	GlobalWorkerOptions: { workerSrc: '' },
}))

vi.mock('pdfjs-dist/build/pdf.worker.min.mjs?url', () => ({ default: '' }))

// ── Helpers ────────────────────────────────────────────────────────────────────

const mockSong: Song = {
	id: 42,
	title: 'Test Sonata',
	composers: [{ id: 1, name: 'J.S. Bach' }],
	books: [],
	collections: [],
	artists: [],
	files: [{ id: 100, path: '/dav/files/admin/Noten/TestSonata.pdf' }],
}

const globalStubs = {
	global: {
		stubs: {
			NcButton: {
				template: '<button :disabled="disabled" :type="type" :title="title" :aria-label="ariaLabel"><slot /><slot name="icon" /></button>',
				props: ['disabled', 'type', 'variant', 'title', 'ariaLabel'],
			},
			NcTextField: {
				template: '<input :value="modelValue" />',
				props: ['modelValue', 'placeholder'],
			},
			NcSelect: {
				template: '<div class="nc-select-stub"></div>',
				props: ['modelValue', 'options'],
			},
			NcLoadingIcon: {
				template: '<div class="nc-loading-stub"></div>',
			},
			NcDialog: {
				template: '<div class="nc-dialog-stub"><slot /></div>',
			},
			NcCheckboxRadioSwitch: {
				template: '<div class="nc-checkbox-stub"><slot /></div>',
			},
			NcRadioGroup: {
				template: '<div class="nc-radio-group-stub"><slot /></div>',
			},
			NcRadioGroupButton: {
				template: '<div class="nc-radio-group-button-stub"></div>',
			},
			// The SongEditor inside SongViewer needs a minimal stub that emits cancel/saved.
			SongEditor: {
				template: '<div class="song-editor-stub"><button class="cancel-btn" @click="$emit(\'cancel\')">Cancel</button><button class="save-btn" @click="$emit(\'saved\', {}, 42)">Save</button></div>',
				props: ['song', 'libraryId', 'composers', 'books', 'collections', 'artists'],
				emits: ['cancel', 'saved', 'updated'],
			},
		},
	},
}

/** Wait for all async work (promises, nextTick) to settle. */
async function settle() {
	await flushPromises()
	await nextTick()
	await flushPromises()
	await nextTick()
}

function setupAxiosMock() {
	vi.mocked(axios.get).mockResolvedValue({
		data: new ArrayBuffer(8),
	})
}

/** Count the PDF page canvases inside the wrapper. */
function pdfCanvasCount(wrapper: ReturnType<typeof mount>): number {
	return wrapper.element.querySelectorAll('canvas.sheetmusic-pdf-page').length
}

// ── Tests ──────────────────────────────────────────────────────────────────────

describe('SongViewer.vue', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		setupAxiosMock()
	})

	it('renders PDF canvases on initial mount', async () => {
		const wrapper = mount(SongViewer, {
			props: { song: mockSong, libraryId: 1 },
			...globalStubs,
		})

		await settle()

		expect(pdfCanvasCount(wrapper)).toBe(1)
		expect(mockGetDocument).toHaveBeenCalledTimes(1)
	})

	it('re-renders PDF after closing the internal editor via cancel', async () => {
		const wrapper = mount(SongViewer, {
			props: { song: mockSong, libraryId: 1 },
			...globalStubs,
		})

		await settle()
		expect(pdfCanvasCount(wrapper)).toBe(1)

		// Open the editor — this triggers v-if and destroys the PDF container.
		const editBtn = wrapper.findAll('button').find(
			(btn) => btn.attributes('title') === 'Edit song' || btn.attributes('aria-label') === 'Edit song',
		)
		expect(editBtn).toBeDefined()
		await editBtn!.trigger('click')
		await settle()

		// The PDF container is removed by v-if; canvases are gone.
		expect(pdfCanvasCount(wrapper)).toBe(0)
		expect(wrapper.find('.song-editor-stub').exists()).toBe(true)

		// Cancel editing — the PDF viewer must re-render.
		const cancelBtn = wrapper.find('.cancel-btn')
		await cancelBtn.trigger('click')
		await settle()

		expect(pdfCanvasCount(wrapper)).toBe(1)
		// getDocument is called: once on mount, once after cancel.
		expect(mockGetDocument).toHaveBeenCalledTimes(2)
	})

	it('re-renders PDF after closing the internal editor via save', async () => {
		const wrapper = mount(SongViewer, {
			props: { song: mockSong, libraryId: 1 },
			...globalStubs,
		})

		await settle()
		expect(pdfCanvasCount(wrapper)).toBe(1)

		// Open editor.
		const editBtn = wrapper.findAll('button').find(
			(btn) => btn.attributes('title') === 'Edit song',
		)
		await editBtn!.trigger('click')
		await settle()

		expect(pdfCanvasCount(wrapper)).toBe(0)

		// Save — the stub emits 'saved'.
		const saveBtn = wrapper.find('.save-btn')
		await saveBtn.trigger('click')
		await settle()

		expect(pdfCanvasCount(wrapper)).toBe(1)
		expect(mockGetDocument).toHaveBeenCalledTimes(2)
	})

	it('shows error when song has no files', async () => {
		const songNoFiles: Song = { ...mockSong, files: [] }
		const wrapper = mount(SongViewer, {
			props: { song: songNoFiles, libraryId: 1 },
			...globalStubs,
		})

		await settle()

		expect(wrapper.text()).toContain('No files attached to this song.')
		expect(pdfCanvasCount(wrapper)).toBe(0)
	})

	it('shows missing file message when song has file but path is missing', async () => {
		const songMissingFile: Song = {
			...mockSong,
			files: [{ id: 99, path: '' }],
		}
		const wrapper = mount(SongViewer, {
			props: { song: songMissingFile, libraryId: 1 },
			...globalStubs,
		})

		await settle()

		expect(wrapper.text()).toContain('Missing file: The file does not exist on the file system.')
		expect(pdfCanvasCount(wrapper)).toBe(0)
	})

	it('renders PDF toolbar with page navigation, layout modes, zoom and rotate buttons', async () => {
		const wrapper = mount(SongViewer, {
			props: { song: mockSong, libraryId: 1 },
			...globalStubs,
		})

		await settle()

		// Page indicator
		expect(wrapper.text()).toContain('Page 1–1 of 1')

		const buttons = wrapper.findAll('button')
		const zoomInBtn = buttons.find((btn) => btn.attributes('title') === 'Zoom in')
		const zoomOutBtn = buttons.find((btn) => btn.attributes('title') === 'Zoom out')
		const fitWidthBtn = buttons.find((btn) => btn.attributes('title') === 'Fit to width')
		const rotateBtn = buttons.find((btn) => btn.attributes('title') === 'Rotate clockwise')
		const continuousBtn = buttons.find((btn) => btn.attributes('title') === 'Continuous scroll (All pages)')
		const singlePageBtn = buttons.find((btn) => btn.attributes('title') === 'Single page')
		const doublePageBtn = buttons.find((btn) => btn.attributes('title') === 'Two pages side-by-side')

		expect(zoomInBtn).toBeDefined()
		expect(zoomOutBtn).toBeDefined()
		expect(fitWidthBtn).toBeDefined()
		expect(rotateBtn).toBeDefined()
		expect(continuousBtn).toBeDefined()
		expect(singlePageBtn).toBeDefined()
		expect(doublePageBtn).toBeDefined()

		// Zoom select dropdown
		const select = wrapper.find('select')
		expect(select.exists()).toBe(true)
	})

	it('switches layout modes (single page, double page, continuous) and updates zoom & rotation', async () => {
		const wrapper = mount(SongViewer, {
			props: { song: mockSong, libraryId: 1 },
			...globalStubs,
		})

		await settle()

		// Switch to single page
		const singlePageBtn = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Single page')
		await singlePageBtn!.trigger('click')
		await settle()
		expect(wrapper.text()).toContain('Page 1 of 1')

		// Switch to double page
		const doublePageBtn = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Two pages side-by-side')
		await doublePageBtn!.trigger('click')
		await settle()
		expect(wrapper.text()).toContain('Page 1 of 1')

		// Switch back to continuous
		const continuousBtn = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Continuous scroll (All pages)')
		await continuousBtn!.trigger('click')
		await settle()
		expect(wrapper.text()).toContain('Page 1–1 of 1')

		// Click zoom in
		const zoomInBtn = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Zoom in')
		await zoomInBtn!.trigger('click')
		await settle()

		// Click zoom out
		const zoomOutBtn = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Zoom out')
		await zoomOutBtn!.trigger('click')
		await settle()

		// Click rotate clockwise
		const rotateBtn = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Rotate clockwise')
		await rotateBtn!.trigger('click')
		await settle()

		// Select zoom preset
		const select = wrapper.find('select')
		await select.setValue('1.5')
		await select.trigger('change')
		await settle()

		expect(pdfCanvasCount(wrapper)).toBe(1)
	})

	it('renders inline image and toolbar for image files', async () => {
		const imageSong: Song = {
			...mockSong,
			files: [{ id: 101, path: '/dav/files/admin/Noten/sheet.png' }],
		}
		const wrapper = mount(SongViewer, {
			props: { song: imageSong, libraryId: 1 },
			...globalStubs,
		})

		await settle()

		const img = wrapper.find('img')
		expect(img.exists()).toBe(true)
		expect(img.attributes('src')).toContain('/dav/files/admin/Noten/sheet.png')
		expect(img.attributes('src')).toMatch(/\?t=101--\d+/)

		// Toolbar should be visible with zoom and rotate
		const zoomInBtn = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Zoom in')
		expect(zoomInBtn).toBeDefined()
		await zoomInBtn!.trigger('click')
		await settle()

		const rotateBtn = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Rotate clockwise')
		expect(rotateBtn).toBeDefined()
		await rotateBtn!.trigger('click')
		await settle()

		// Image should have transform applied
		expect(img.attributes('style')).toContain('rotate(90deg)')
	})

	it('updates preview cache-buster and requests fresh file after saving edited song', async () => {
		const wrapper = mount(SongViewer, {
			props: { song: mockSong, libraryId: 1 },
			...globalStubs,
		})

		await settle()
		const initialFileCalls = vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).includes('/dav/files/'))
		expect(initialFileCalls).toHaveLength(1)
		const initialUrl = initialFileCalls[0][0]
		expect(initialUrl).toContain('/dav/files/admin/Noten/TestSonata.pdf')
		expect(initialUrl).toMatch(/\?t=100--\d+/)

		// Open editor
		const editBtn = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Edit song')
		await editBtn!.trigger('click')
		await settle()

		// Save editor
		const saveBtn = wrapper.find('.save-btn')
		await saveBtn.trigger('click')
		await settle()

		const postSaveFileCalls = vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).includes('/dav/files/'))
		expect(postSaveFileCalls).toHaveLength(2)
		const postSaveUrl = postSaveFileCalls[1][0]
		expect(postSaveUrl).toContain('/dav/files/admin/Noten/TestSonata.pdf')
		// The timestamp query parameter in postSaveUrl must be updated
		expect(postSaveUrl).not.toBe(initialUrl)
	})

	it('updates preview cache-buster when song prop changes', async () => {
		const wrapper = mount(SongViewer, {
			props: { song: mockSong, libraryId: 1 },
			...globalStubs,
		})

		await settle()
		const initialFileCalls = vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).includes('/dav/files/'))
		expect(initialFileCalls).toHaveLength(1)
		const initialUrl = initialFileCalls[0][0]

		// Update song prop with updated lastModified timestamp
		await wrapper.setProps({
			song: {
				...mockSong,
				lastModified: 1700000000,
			},
		})
		await settle()

		const updatedFileCalls = vi.mocked(axios.get).mock.calls.filter(([url]) => String(url).includes('/dav/files/'))
		expect(updatedFileCalls).toHaveLength(2)
		const updatedUrl = updatedFileCalls[1][0]
		expect(updatedUrl).toContain('1700000000')
		expect(updatedUrl).not.toBe(initialUrl)
	})

	it('renders file toolbar with info button and PDF actions for PDF files', async () => {
		const wrapper = mount(SongViewer, {
			props: { song: mockSong, libraryId: 1, hideHeader: true },
			...globalStubs,
		})

		await settle()

		const fileToolbar = wrapper.find('[data-test="song-file-toolbar"]')
		expect(fileToolbar.exists()).toBe(true)
		expect(fileToolbar.text()).toContain('TestSonata.pdf')

		const infoBtn = fileToolbar.find('[data-test="pdf-file-info-trigger"]')
		expect(infoBtn.exists()).toBe(true)
		await infoBtn.trigger('click')
		expect(fileToolbar.find('[data-test="pdf-file-info-dropdown"]').exists()).toBe(true)

		const versionBtn = fileToolbar.findAll('button').find((b) => b.attributes('title') === 'PDF version history')
		const modifyBtn = fileToolbar.findAll('button').find((b) => b.attributes('title') === 'Modify PDF (rotate, crop, enhance, split)')
		const externalBtn = fileToolbar.findAll('button').find((b) => b.attributes('title') === 'Open in external viewer')
		expect(versionBtn).toBeDefined()
		expect(modifyBtn).toBeDefined()
		expect(externalBtn).toBeDefined()
	})

	it('does not render PDF version history button for non-PDF files', async () => {
		const imageSong: Song = {
			...mockSong,
			files: [{ id: 200, path: '/dav/files/admin/Noten/photo.png' }],
		}
		const wrapper = mount(SongViewer, {
			props: { song: imageSong, libraryId: 1, hideHeader: false },
			...globalStubs,
		})

		await settle()

		const versionBtn = wrapper.findAll('button').find((b) => b.attributes('title') === 'PDF version history')
		expect(versionBtn).toBeUndefined()
	})

	it('opens version history dialog when openVersionsModal is invoked', async () => {
		vi.mocked(axios.get).mockImplementation((url: string) => {
			if (url.includes('/versions')) {
				return Promise.resolve({
					data: {
						ocs: {
							data: {
								versions: [
									{ revision: 101, timestamp: 1700000000, size: 2048 },
								],
							},
						},
					},
				}) as any
			}
			return Promise.resolve({ data: new ArrayBuffer(8) }) as any
		})

		const wrapper = mount(SongViewer, {
			props: { song: mockSong, libraryId: 1, hideHeader: true },
			...globalStubs,
		})

		await settle()

		// Invoke openVersionsModal exposed method (as called by SongsView.vue)
		await (wrapper.vm as any).openVersionsModal()
		await settle()

		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining('/api/libraries/1/songs/42/file/100/versions'),
		)
		expect(wrapper.text()).toContain('2.0 KB')
		expect(wrapper.text()).toContain('Revert to this version')
	})

	it('renders bookmarks bar and updates target page when clicking a bookmark', async () => {
		const songWithBookmarks: Song = {
			...mockSong,
			bookmarks: [
				{ id: 1, songId: 42, name: 'I. Allegro', pageNum: 1, endPage: 4 },
				{ id: 2, songId: 42, name: 'II. Adagio', pageNum: 5, endPage: 8 },
			],
		}

		const wrapper = mount(SongViewer, {
			props: { song: songWithBookmarks, libraryId: 1, hideHeader: true },
			...globalStubs,
		})

		await settle()

		// Bookmarks bar should be visible with formatted ranges
		expect(wrapper.text()).toContain('Bookmarks:')
		expect(wrapper.text()).toContain('I. Allegro (pp. 1–4)')
		expect(wrapper.text()).toContain('II. Adagio (pp. 5–8)')

		// Find bookmark button and click it
		const bookmarkBtns = wrapper.findAll('button').filter((b) => b.text().includes('II. Adagio'))
		expect(bookmarkBtns.length).toBeGreaterThan(0)
		await bookmarkBtns[0].trigger('click')
		await settle()

		expect((wrapper.vm as any).activeBookmarkPage).toBe(5)
	})

	it('fetches annotations using fileId when present', async () => {
		const songWithFileId: Song = {
			...mockSong,
			files: [
				{ id: 2304, fileId: 1701, path: '/dav/files/admin/Noten/TakeFive.pdf' },
			],
		}

		mount(SongViewer, {
			props: { song: songWithFileId, libraryId: 1 },
			...globalStubs,
		})

		await settle()

		// Should call fetchSongAnnotations with fileId (1701)
		const annCalls = vi.mocked(axios.get).mock.calls.filter((c) =>
			typeof c[0] === 'string' && c[0].includes('/annotations'),
		)
		expect(annCalls.length).toBeGreaterThanOrEqual(1)
		expect(annCalls[0][1]).toEqual({ params: { fileId: 1701 } })
	})

	it('fetches annotations using file.id as fallback when fileId is absent', async () => {
		const songWithoutFileId: Song = {
			...mockSong,
			files: [
				{ id: 2304, path: '/dav/files/admin/Noten/TakeFive.pdf' },
			],
		}

		mount(SongViewer, {
			props: { song: songWithoutFileId, libraryId: 1 },
			...globalStubs,
		})

		await settle()

		const annCalls = vi.mocked(axios.get).mock.calls.filter((c) =>
			typeof c[0] === 'string' && c[0].includes('/annotations'),
		)
		expect(annCalls.length).toBeGreaterThanOrEqual(1)
		expect(annCalls[0][1]).toEqual({ params: { fileId: 2304 } })
	})

	it('re-fetches annotations with updated fileId when switching selected file', async () => {
		const multiFileSong: Song = {
			...mockSong,
			files: [
				{ id: 101, fileId: 1701, path: '/dav/files/admin/Noten/Movement1.pdf' },
				{ id: 102, fileId: 1702, path: '/dav/files/admin/Noten/Movement2.pdf' },
			],
		}

		const wrapper = mount(SongViewer, {
			props: { song: multiFileSong, libraryId: 1 },
			...globalStubs,
		})

		await settle()

		// Initial load with first file (fileId: 1701)
		let annCalls = vi.mocked(axios.get).mock.calls.filter((c) =>
			typeof c[0] === 'string' && c[0].includes('/annotations'),
		)
		expect(annCalls.some((c) => c[1]?.params?.fileId === 1701)).toBe(true)

		// Switch file
		await (wrapper.vm as any).selectFile('102')
		await settle()

		annCalls = vi.mocked(axios.get).mock.calls.filter((c) =>
			typeof c[0] === 'string' && c[0].includes('/annotations'),
		)
		expect(annCalls.some((c) => c[1]?.params?.fileId === 1702)).toBe(true)
	})
})
