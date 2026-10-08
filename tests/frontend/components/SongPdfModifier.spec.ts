import fs from 'node:fs'
import path from 'node:path'
import type { Song } from '../../../src/types/song'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import SongPdfModifier from '../../../src/components/song-pdf/SongPdfModifier.vue'

// Real PDF bytes from fixture
const realPdfPath = path.resolve(__dirname, '../../fixtures/sample.pdf')
const realPdfBuffer = fs.readFileSync(realPdfPath)

const { uploadedFiles, createdSongs, deletedFiles, deletedSongs } = vi.hoisted(() => ({
	uploadedFiles: [] as { songId: number; file: File }[],
	createdSongs: [] as any[],
	deletedFiles: [] as { songId: number; fileId: number }[],
	deletedSongs: [] as { songId: number; deleteFiles?: boolean }[],
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn((_url: string) => {
			// Return a slice of the real PDF buffer (so each call gets fresh ArrayBuffer)
			const copy = realPdfBuffer.buffer.slice(
				realPdfBuffer.byteOffset,
				realPdfBuffer.byteOffset + realPdfBuffer.byteLength,
			)
			return Promise.resolve({ data: copy })
		}),
		post: vi.fn(() => Promise.resolve({ data: { ocs: { data: { id: 101 } } } })),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateFilePath: vi.fn(() => '/fake-path'),
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php${path}`),
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
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'checked', 'type', 'disabled', 'name', 'value'],
		emits: ['update:modelValue', 'update:checked'],
		template: `
			<label>
				<input :type="type || 'checkbox'"
					:name="name"
					:value="value"
					:checked="checked !== undefined ? checked : modelValue"
					:disabled="disabled"
					@change="$emit('update:checked', type === 'radio' ? true : $event.target.checked); $emit('update:modelValue', type === 'radio' ? value : $event.target.checked)" />
				<slot />
			</label>
		`,
	},
}))

// Emulate pdfjs-dist getDocument transferring/detaching the input data ArrayBuffer like a web worker does!
const { mockGetDocument } = vi.hoisted(() => {
	const mockPage = {
		getViewport: vi.fn(() => ({ width: 200, height: 300 })),
		render: vi.fn(() => ({ promise: Promise.resolve() })),
	}
	const mockPdf = {
		numPages: 8,
		getPage: vi.fn(() => Promise.resolve(mockPage)),
	}
	const mockGetDocument = vi.fn((params: { data: Uint8Array | ArrayBuffer }) => {
		// Simulate web worker buffer transfer: detaching the passed buffer!
		if (params?.data instanceof Uint8Array && params.data.buffer) {
			try {
				// StructuredClone with transfer list detaches the source ArrayBuffer
				structuredClone(params.data.buffer, { transfer: [params.data.buffer] })
			} catch {
				// Fallback if structuredClone transfer is unsupported
			}
		}
		return { promise: Promise.resolve(mockPdf) }
	})
	return { mockGetDocument }
})

vi.mock('pdfjs-dist', () => ({
	getDocument: mockGetDocument,
	GlobalWorkerOptions: { workerSrc: '' },
}))

vi.mock('pdfjs-dist/build/pdf.worker.min.mjs?url', () => ({ default: '' }))

// Real pdf-lib is intentionally UNMOCKED here so real PDF parsing, slicing, and rotation are thoroughly tested!

vi.mock('../../../src/api/client', () => ({
	apiPost: vi.fn((endpoint: string, payload: any, options?: any) => {
		if (endpoint === '/api/songs') {
			const newId = 100 + createdSongs.length + 1
			createdSongs.push({ id: newId, ...payload })
			return Promise.resolve({
				data: { ocs: { data: { id: newId, createdSongId: newId, songs: [] } } },
			})
		}
		if (endpoint === '/api/libraries/{libraryId}/songs/{id}/file/upload') {
			const songId = options?.pathParams?.id
			const file = payload?.get?.('file')
			uploadedFiles.push({ songId, file })
			return Promise.resolve({
				data: { ocs: { data: { songs: [] } } },
			})
		}
		if (endpoint.includes('file') && endpoint.includes('delete')) {
			deletedFiles.push({ songId: options?.pathParams?.id, fileId: options?.pathParams?.fileId })
			return Promise.resolve({
				data: { ocs: { data: { songs: [] } } },
			})
		}
		return Promise.resolve({ data: { ocs: { data: { songs: [] } } } })
	}),
	apiDelete: vi.fn((endpoint: string, options?: any) => {
		if (endpoint === '/api/songs/{id}') {
			deletedSongs.push({ songId: options?.pathParams?.id, deleteFiles: options?.queryParams?.deleteFiles })
			return Promise.resolve({
				data: { ocs: { data: { songs: [] } } },
			})
		}
		return Promise.resolve({ data: { ocs: { data: { songs: [] } } } })
	}),
}))

describe('SongPdfModifier.vue (Real PDF parsing & operations)', () => {
	const mockSong: Song = {
		id: 42,
		title: 'Nextcloud Guide',
		files: [
			{ id: 999, path: '/files/nextcloud-guide.pdf' },
		],
		books: [{ id: 10, title: 'Docs' }],
		composers: [{ id: 5, name: 'Nextcloud GmbH' }],
		artists: [{ id: 2, name: 'Nextcloud' }],
		genres: [{ id: 7, name: 'Documentation' }],
		stars: 5,
		difficulty: 2,
	}

	beforeEach(() => {
		vi.clearAllMocks()
		uploadedFiles.length = 0
		createdSongs.length = 0
		deletedFiles.length = 0
		deletedSongs.length = 0
	})

	it('loads real PDF document and renders 8 pages', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		expect(mockGetDocument).toHaveBeenCalled()
		expect(wrapper.text()).toContain('8 pages')
		expect(wrapper.text()).toContain('p. 1')
		expect(wrapper.text()).toContain('p. 8')

		const axios = (await import('@nextcloud/axios')).default
		expect(axios.get).toHaveBeenCalledWith('/files/nextcloud-guide.pdf', {
			responseType: 'arraybuffer',
		})
	})

	it('successfully splits real PDF into multiple valid PDF documents even when worker detaches getDocument buffer', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Switch tab to Split PDF
		const splitTab = wrapper.findAll('button').find((b) => b.text().includes('Split PDF'))
		expect(splitTab).toBeDefined()
		await splitTab?.trigger('click')

		// Part 1 is default (1 to 7). Let's add Part 2 and Part 3
		const addPartBtn = wrapper.findAll('button').find((b) => b.text().includes('Add Part'))
		expect(addPartBtn).toBeDefined()
		await addPartBtn?.trigger('click') // Part 2
		await addPartBtn?.trigger('click') // Part 3

		// Configure page ranges
		// Find segment card number inputs
		const numberInputs = wrapper.findAll('input[type="number"]')
		expect(numberInputs.length).toBe(6) // 3 segments x 2 inputs (start, end)

		// Part 1: pages 1 to 2
		await numberInputs[0].setValue(1)
		await numberInputs[1].setValue(2)

		// Part 2: pages 3 to 5
		await numberInputs[2].setValue(3)
		await numberInputs[3].setValue(5)

		// Part 3: pages 6 to 8
		await numberInputs[4].setValue(6)
		await numberInputs[5].setValue(8)

		// Trigger Split & Create Songs
		const splitSubmitBtn = wrapper.findAll('button').find((b) => b.text().includes('Split & Create Songs'))
		expect(splitSubmitBtn).toBeDefined()

		await splitSubmitBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 50))
		await flushPromises()

		// Verify 3 new songs were created with inherited metadata
		expect(createdSongs).toHaveLength(3)
		expect(createdSongs[0].title).toBe('Nextcloud Guide - Part 1')
		expect(createdSongs[0].composerIds).toEqual([5])
		expect(createdSongs[0].bookIds).toEqual([10])
		expect(createdSongs[0].artistIds).toEqual([2])
		expect(createdSongs[0].genreIds).toEqual([7])
		expect(createdSongs[0].stars).toBe(5)

		// Verify 3 split PDF files were uploaded
		expect(uploadedFiles).toHaveLength(3)
		expect(uploadedFiles[0].songId).toBe(101)
		expect(uploadedFiles[1].songId).toBe(102)
		expect(uploadedFiles[2].songId).toBe(103)

		// CRITICAL: Parse the generated split PDF bytes with real PDFDocument to verify they are 100% valid PDFs!
		for (let i = 0; i < uploadedFiles.length; i++) {
			const uploaded = uploadedFiles[i]
			const arrayBuffer = await uploaded.file.arrayBuffer()
			expect(arrayBuffer.byteLength).toBeGreaterThan(0)

			// This would throw "No PDF header found" if corrupt/detached!
			const parsedDoc = await PDFDocument.load(arrayBuffer)
			expect(parsedDoc).toBeDefined()

			if (i === 0) {
				// Part 1 had pages 1..2 (2 pages)
				expect(parsedDoc.getPageCount()).toBe(2)
			} else if (i === 1) {
				// Part 2 had pages 3..5 (3 pages)
				expect(parsedDoc.getPageCount()).toBe(3)
			} else if (i === 2) {
				// Part 3 had pages 6..8 (3 pages)
				expect(parsedDoc.getPageCount()).toBe(3)
			}
		}

		// By default "Do nothing" is selected, so neither file nor song is deleted
		expect(deletedFiles).toHaveLength(0)
		expect(deletedSongs).toHaveLength(0)
		expect(wrapper.emitted('saved')).toBeTruthy()
		expect(wrapper.emitted('saved')?.[0]?.[1]).toBe(101)
	})

	it('splits real PDF and deletes original PDF file when delete-file option is selected', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Switch tab to Split PDF
		const splitTab = wrapper.findAll('button').find((b) => b.text().includes('Split PDF'))
		await splitTab?.trigger('click')

		// Select option b: "Delete the PDF file from the original song"
		const deleteFileRadio = wrapper.find('input[value="delete-file"]')
		expect(deleteFileRadio.exists()).toBe(true)
		await deleteFileRadio.setValue(true)
		await deleteFileRadio.trigger('change')

		// Split
		const splitSubmitBtn = wrapper.findAll('button').find((b) => b.text().includes('Split & Create Songs'))
		await splitSubmitBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 50))
		await flushPromises()

		// Original PDF file should be deleted, song should NOT be deleted
		expect(deletedFiles).toContainEqual({ songId: 42, fileId: 999 })
		expect(deletedSongs).toHaveLength(0)
		expect(wrapper.emitted('saved')).toBeTruthy()
		// Saved event should pass first newly created split song ID
		expect(wrapper.emitted('saved')?.[0]?.[1]).toBe(101)
	})

	it('splits real PDF and deletes original song and all files when delete-song option is selected', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong, // has exactly 1 file (999)
				libraryId: 1,
			},
		})

		await flushPromises()

		// Switch tab to Split PDF
		const splitTab = wrapper.findAll('button').find((b) => b.text().includes('Split PDF'))
		await splitTab?.trigger('click')

		// Select option a: "Delete original song and all its attached files"
		const deleteSongRadio = wrapper.find('input[value="delete-song"]')
		expect(deleteSongRadio.exists()).toBe(true)
		expect(deleteSongRadio.attributes('disabled')).toBeUndefined()
		await deleteSongRadio.setValue(true)
		await deleteSongRadio.trigger('change')

		// Split
		const splitSubmitBtn = wrapper.findAll('button').find((b) => b.text().includes('Split & Create Songs'))
		await splitSubmitBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 50))
		await flushPromises()

		// Original song should be deleted with deleteFiles: true
		expect(deletedSongs).toContainEqual({ songId: 42, deleteFiles: true })
		expect(wrapper.emitted('saved')).toBeTruthy()
		// Saved event should pass the first newly created split song ID
		expect(wrapper.emitted('saved')?.[0]?.[1]).toBe(101)
	})

	it('disables delete-song option when song has multiple attached files', async () => {
		const multiFileSong: Song = {
			...mockSong,
			files: [
				{ id: 998, path: '/files/part1.pdf' },
				{ id: 999, path: '/files/part2.pdf' },
			],
		}

		const wrapper = mount(SongPdfModifier, {
			props: {
				song: multiFileSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Switch tab to Split PDF
		const splitTab = wrapper.findAll('button').find((b) => b.text().includes('Split PDF'))
		await splitTab?.trigger('click')

		const deleteSongRadio = wrapper.find('input[value="delete-song"]')
		expect(deleteSongRadio.exists()).toBe(true)
		expect(deleteSongRadio.attributes('disabled')).toBeDefined()
	})

	it('rotates real PDF pages and saves a valid rotated PDF document', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Rotate all 90° CW
		const rotateAllCwBtn = wrapper.findAll('button').find((b) => b.text().includes('Rotate All 90° CW'))
		await rotateAllCwBtn?.trigger('click')

		// Save Rotations
		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Rotations'))
		await saveBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 50))
		await flushPromises()

		// Original file replaced: old deleted and new uploaded
		expect(deletedFiles).toContainEqual({ songId: 42, fileId: 999 })
		expect(uploadedFiles).toHaveLength(1)
		expect(uploadedFiles[0].songId).toBe(42)

		// Verify the rotated PDF file with real PDFDocument
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const parsedDoc = await PDFDocument.load(arrayBuffer)
		expect(parsedDoc.getPageCount()).toBe(8)
		// Check that pages were rotated
		const firstPage = parsedDoc.getPage(0)
		expect(firstPage.getRotation().angle).toBe(90)

		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('emits cancel when Cancel button is clicked', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		const cancelButton = wrapper.findAllComponents({ name: 'NcButton' }).find(b => b.text().includes('Cancel'))
		expect(cancelButton?.exists()).toBe(true)
		await cancelButton!.trigger('click')

		expect(wrapper.emitted('cancel')).toBeTruthy()
	})

	it('configures thumbnail containers with dataset pageNum for lazy observer loading', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		const thumbnailSlots = wrapper.findAll('.mobilesheetsmanager-pdf-thumbnail-canvas, div[data-page-num]')
		expect(thumbnailSlots.length).toBeGreaterThan(0)
	})
})
