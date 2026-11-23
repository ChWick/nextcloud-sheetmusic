import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { PDFDocument } from 'pdf-lib'
import axios from '@nextcloud/axios'
import SongPdfMergeModal from '../../../src/components/song-pdf/SongPdfMergeModal.vue'
import * as songFilesApi from '../../../src/api/songFiles'
import * as apiClient from '../../../src/api/client'
import type { LibraryData, Song, SongFile } from '../../../src/types/song'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return {
		...actual,
		translate: (_app: string, text: string, vars?: Record<string, any>) => {
			if (!vars) return text
			let res = text
			for (const [k, v] of Object.entries(vars)) {
				res = res.replace(`{${k}}`, String(v))
			}
			return res
		},
		getLanguage: vi.fn(() => 'en'),
		isRTL: vi.fn(() => false),
	}
})

const mockLoadPdfJsDocument = vi.fn()
const mockDestroyPdfJsDocument = vi.fn(() => Promise.resolve())
const mockRenderPdfPageToCanvas = vi.fn(() => Promise.resolve({ pixelWidth: 100, pixelHeight: 140 }))

vi.mock('../../../src/utils/pdfjs', () => ({
	loadPdfJsDocument: (...args: any[]) => mockLoadPdfJsDocument(...args),
	destroyPdfJsDocument: (...args: any[]) => mockDestroyPdfJsDocument(...args),
	renderPdfPageToCanvas: (...args: any[]) => mockRenderPdfPageToCanvas(...args),
	cleanupPdfJsPage: vi.fn(),
	releaseCanvas: vi.fn(),
}))

vi.mock('../../../src/api/songFiles', () => ({
	updateSongFile: vi.fn(),
	uploadSongFile: vi.fn(),
}))

vi.mock('../../../src/api/client', () => ({
	apiPost: vi.fn(),
}))

async function makePdfBytes(pageCount: number): Promise<Uint8Array> {
	const doc = await PDFDocument.create()
	for (let i = 0; i < pageCount; i++) {
		doc.addPage([400 + i * 20, 600])
	}
	return doc.save()
}

function makeFakePdfJsDoc(numPages: number) {
	return {
		numPages,
		getPage: vi.fn(() =>
			Promise.resolve({
				getViewport: vi.fn(() => ({ width: 400, height: 600 })),
			}),
		),
	}
}

const mockLibraryData: LibraryData = {
	songs: [],
	composers: [],
	books: [],
	collections: [],
	artists: [],
}

const pdfFile1: SongFile = { id: 101, path: '/dav/files/admin/Music/Part1.pdf', pageCount: 2 }
const pdfFile2: SongFile = { id: 102, path: '/dav/files/admin/Music/Part2.pdf', pageCount: 1 }

const mockSong: Song = {
	id: 7,
	title: 'Moonlight Sonata',
	files: [pdfFile1, pdfFile2],
}

const stubs = {
	NcDialog: {
		props: ['open', 'name'],
		emits: ['update:open'],
		template: '<div v-if="open" class="nc-dialog-stub"><slot /><div class="actions"><slot name="actions" /></div></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'title', 'ariaLabel'],
		emits: ['click'],
		template: '<button type="button" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcLoadingIcon: { template: '<span class="loading" />' },
	NcTextField: {
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	SongPageOrderZoomModal: { template: '<div class="zoom-modal-stub" />' },
	SongPdfSaveProgress: { template: '<div class="progress-stub" />' },
}

describe('SongPdfMergeModal.vue', () => {
	let bytes1: Uint8Array
	let bytes2: Uint8Array

	beforeEach(async () => {
		vi.clearAllMocks()
		bytes1 = await makePdfBytes(2)
		bytes2 = await makePdfBytes(1)

		vi.mocked(axios.get)
			.mockResolvedValueOnce({ data: bytes1.buffer.slice(0) } as any)
			.mockResolvedValueOnce({ data: bytes2.buffer.slice(0) } as any)

		mockLoadPdfJsDocument
			.mockResolvedValueOnce(makeFakePdfJsDoc(2))
			.mockResolvedValueOnce(makeFakePdfJsDoc(1))

		vi.mocked(songFilesApi.updateSongFile).mockResolvedValue({
			data: { ocs: { data: mockLibraryData } },
		} as any)
		vi.mocked(songFilesApi.uploadSongFile).mockResolvedValue({
			data: { ocs: { data: mockLibraryData } },
		} as any)
		vi.mocked(apiClient.apiPost).mockResolvedValue({
			data: { ocs: { data: mockLibraryData } },
		} as any)
	})

	it('loads all PDFs, displays source files and pages, and allows rearranging, rotating, duplicating, and removing pages', async () => {
		const wrapper = mount(SongPdfMergeModal, {
			props: {
				open: true,
				song: mockSong,
				libraryId: 1,
				pdfFiles: [pdfFile1, pdfFile2],
			},
			global: { stubs },
		})

		await flushPromises()

		expect(wrapper.findAll('[data-test^="merge-page-card-"]')).toHaveLength(3)
		expect(wrapper.find('[data-test="merge-source-101"]').text()).toContain('Part1.pdf')
		expect(wrapper.find('[data-test="merge-source-102"]').text()).toContain('Part2.pdf')

		// Move page 0 right
		await wrapper.find('[data-test="merge-page-right-0"]').trigger('click')
		expect(wrapper.find('[data-test="merge-page-card-0"]').text()).toContain('PDF 1 · p.2')
		expect(wrapper.find('[data-test="merge-page-card-1"]').text()).toContain('PDF 1 · p.1')

		// Rotate page 0 CW
		await wrapper.find('[data-test="merge-page-rot-cw-0"]').trigger('click')
		expect(wrapper.find('[data-test="merge-page-card-0"]').text()).toContain('90°')

		// Duplicate page 2
		await wrapper.find('[data-test="merge-page-duplicate-2"]').trigger('click')
		expect(wrapper.findAll('[data-test^="merge-page-card-"]')).toHaveLength(4)

		// Remove duplicated page
		await wrapper.find('[data-test="merge-page-remove-3"]').trigger('click')
		expect(wrapper.findAll('[data-test^="merge-page-card-"]')).toHaveLength(3)

		// Move second source file up -> its page becomes first
		await wrapper.find('[data-test="merge-source-up-102"]').trigger('click')
		expect(wrapper.find('[data-test="merge-page-card-0"]').text()).toContain('PDF 1 · p.1')
		expect(wrapper.find('[data-test="merge-source-102"]').text()).toContain('Part2.pdf')
	})

	it('merges by replacing first PDF and deleting other merged PDFs by default', async () => {
		const wrapper = mount(SongPdfMergeModal, {
			props: {
				open: true,
				song: mockSong,
				libraryId: 3,
				pdfFiles: [pdfFile1, pdfFile2],
			},
			global: { stubs },
		})

		await flushPromises()

		await wrapper.find('[data-test="merge-pdfs-submit"]').trigger('click')
		await flushPromises()

		expect(songFilesApi.updateSongFile).toHaveBeenCalledTimes(1)
		expect(songFilesApi.updateSongFile).toHaveBeenCalledWith(
			3,
			7,
			101,
			expect.any(File),
		)

		expect(apiClient.apiPost).toHaveBeenCalledWith(
			'/api/songs/{id}/file/{fileId}/delete',
			{ libraryId: 3 },
			{ pathParams: { id: 7, fileId: 102 } },
		)

		expect(wrapper.emitted('merged')?.[0]).toEqual([mockLibraryData])
		expect(wrapper.emitted('update:open')?.[0]).toEqual([false])
	})

	it('supports replace-and-detach mode', async () => {
		const wrapper = mount(SongPdfMergeModal, {
			props: {
				open: true,
				song: mockSong,
				libraryId: 3,
				pdfFiles: [pdfFile1, pdfFile2],
			},
			global: { stubs },
		})

		await flushPromises()

		await wrapper.find('[data-test="merge-action-replace-detach"]').setValue(true)
		await wrapper.find('[data-test="merge-pdfs-submit"]').trigger('click')
		await flushPromises()

		expect(songFilesApi.updateSongFile).toHaveBeenCalledTimes(1)
		expect(apiClient.apiPost).toHaveBeenCalledWith(
			'/api/songs/{id}/file/{fileId}/detach',
			{ libraryId: 3 },
			{ pathParams: { id: 7, fileId: 102 } },
		)
	})

	it('supports keep-all-new-file mode by uploading a new PDF', async () => {
		const wrapper = mount(SongPdfMergeModal, {
			props: {
				open: true,
				song: mockSong,
				libraryId: 3,
				pdfFiles: [pdfFile1, pdfFile2],
			},
			global: { stubs },
		})

		await flushPromises()

		await wrapper.find('[data-test="merge-action-keep-new"]').setValue(true)
		await wrapper.find('[data-test="merge-pdfs-submit"]').trigger('click')
		await flushPromises()

		expect(songFilesApi.updateSongFile).not.toHaveBeenCalled()
		expect(apiClient.apiPost).not.toHaveBeenCalled()
		expect(songFilesApi.uploadSongFile).toHaveBeenCalledWith(
			3,
			7,
			expect.any(File),
			{ pageCount: 3, pageOrder: '1-3' },
		)
	})

	it('disables submit when fewer than 2 source PDFs are selected', async () => {
		const wrapper = mount(SongPdfMergeModal, {
			props: {
				open: true,
				song: mockSong,
				libraryId: 1,
				pdfFiles: [pdfFile1, pdfFile2],
			},
			global: { stubs },
		})

		await flushPromises()

		const submitBtn = wrapper.find('[data-test="merge-pdfs-submit"]')
		expect(submitBtn.attributes('disabled')).toBeUndefined()

		await wrapper.find('[data-test="merge-source-checkbox-102"]').trigger('change')
		expect(submitBtn.attributes('disabled')).toBeDefined()
	})
})
