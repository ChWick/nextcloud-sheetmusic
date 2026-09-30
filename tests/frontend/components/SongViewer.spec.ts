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
	generateFilePath: vi.fn((_app: string, _type: string, path: string) => `/apps-extra/mobilesheetsmanager/js/${path}`),
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
				template: '<button :disabled="disabled" :title="title" :aria-label="ariaLabel" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
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
	return wrapper.element.querySelectorAll('canvas.mobilesheetsmanager-pdf-page').length
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

		expect(wrapper.text()).toContain('No file available for this song.')
		expect(pdfCanvasCount(wrapper)).toBe(0)
	})
})
