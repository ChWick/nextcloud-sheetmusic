import fs from 'node:fs'
import path from 'node:path'
import type { Song } from '../../../src/types/song'
import { config, flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { PDFDocument } from 'pdf-lib'
import SongPdfModifier from '../../../src/components/song-pdf/SongPdfModifier.vue'
import { getPdfPageImageResolution } from '../../../src/components/song-pdf/pdf-enhance-engine'

// Avoid teleporting child modals (NcDialog) to body so wrapper.find queries work without mocks
config.global.stubs = { teleport: true }

// Real PDF bytes from fixture
const realPdfPath = path.resolve(__dirname, '../../fixtures/sample.pdf')
const realPdfBuffer = fs.readFileSync(realPdfPath)

const { uploadedFiles, updatedFiles, createdSongs, deletedFiles, deletedSongs } = vi.hoisted(() => ({
	uploadedFiles: [] as { songId: number; file: File }[],
	updatedFiles: [] as { songId: number; fileId: number; file: File }[],
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

vi.mock('focus-trap', () => ({
	createFocusTrap: () => ({
		activate: vi.fn(),
		deactivate: vi.fn(),
		pause: vi.fn(),
		unpause: vi.fn(),
	}),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
	showInfo: vi.fn(),
	showWarning: vi.fn(),
}))

const { mockGetDocument, mockPdf } = vi.hoisted(() => {
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
	return { mockGetDocument, mockPdf }
})

vi.mock('pdfjs-dist', () => ({
	getDocument: mockGetDocument,
	GlobalWorkerOptions: { workerSrc: '' },
}))

vi.mock('pdfjs-dist/build/pdf.worker.min.mjs?url', () => ({ default: '' }))

// Real pdf-lib is intentionally UNMOCKED here so real PDF parsing, slicing, and rotation are thoroughly tested!

const defaultApiPostImplementation = (endpoint: string, payload: any, options?: any) => {
	if (endpoint === '/api/songs') {
		const newId = 100 + createdSongs.length + 1
		createdSongs.push({ id: newId, ...payload })
		return Promise.resolve({
			data: { ocs: { data: { id: newId, createdSongId: newId, songs: [] } } },
		})
	}
	if (endpoint.includes('file') && endpoint.includes('update')) {
		const songId = options?.pathParams?.id
		const fileId = options?.pathParams?.fileId
		const file = payload?.get?.('file')
		updatedFiles.push({ songId, fileId, file })
		uploadedFiles.push({ songId, file })
		return Promise.resolve({
			data: { ocs: { data: { songs: [] } } },
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
	if (endpoint.includes('versions') && endpoint.includes('revert')) {
		return Promise.resolve({
			data: { ocs: { data: { songs: [] } } },
		})
	}
	return Promise.resolve({ data: { ocs: { data: { songs: [] } } } })
}

vi.mock('../../../src/api/client', () => ({
	apiGet: vi.fn((endpoint: string, _options?: any) => {
		if (endpoint.includes('versions')) {
			return Promise.resolve({
				data: {
					ocs: {
						data: {
							versions: [
								{ revision: 1718000000, timestamp: 1718000000, size: 10240 },
								{ revision: 1717000000, timestamp: 1717000000, size: 8192 },
							],
						},
					},
				},
			})
		}
		return Promise.resolve({ data: { ocs: { data: {} } } })
	}),
	apiPost: vi.fn((endpoint: string, payload: any, options?: any) => defaultApiPostImplementation(endpoint, payload, options)),
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

	beforeEach(async () => {
		vi.clearAllMocks()
		mockPdf.numPages = 8
		const apiClient = await import('../../../src/api/client')
		vi.mocked(apiClient.apiPost).mockImplementation(defaultApiPostImplementation as any)
		uploadedFiles.length = 0
		updatedFiles.length = 0
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
		expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/files/nextcloud-guide.pdf'), {
			headers: {
				'Cache-Control': 'no-cache',
				'Pragma': 'no-cache',
			},
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
		await vi.waitFor(() => expect(createdSongs).toHaveLength(3), { timeout: 5000 })
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
		await vi.waitFor(() => expect(wrapper.emitted('saved')).toBeTruthy(), { timeout: 5000 })
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
		await vi.waitFor(() => expect(wrapper.emitted('saved')).toBeTruthy(), { timeout: 5000 })
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
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		// Original file updated in place, preserving version history and file ID
		expect(updatedFiles).toContainEqual(expect.objectContaining({ songId: 42, fileId: 999 }))
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

		const thumbnailSlots = wrapper.findAll('.sheetmusic-pdf-thumbnail-canvas, div[data-page-num]')
		expect(thumbnailSlots.length).toBeGreaterThan(0)
	})

	it('surfaces detailed server error message when split upload fails', async () => {
		const apiClient = await import('../../../src/api/client')
		const dialogs = await import('@nextcloud/dialogs')

		vi.mocked(apiClient.apiPost).mockImplementation((endpoint: string) => {
			if (endpoint === '/api/songs') {
				return Promise.resolve({
					data: { ocs: { data: { id: 201, createdSongId: 201, songs: [] } } },
				} as any)
			}
			if (endpoint.includes('/file/upload')) {
				const error: any = new Error('Request failed with status code 500')
				error.response = {
					status: 500,
					data: {
						ocs: {
							data: {
								message: 'Failed to create target folder in Nextcloud storage',
							},
						},
					},
				}
				return Promise.reject(error)
			}
			return Promise.resolve({ data: { ocs: { data: { songs: [] } } } } as any)
		})

		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		const splitTab = wrapper.findAll('button').find((b) => b.text().includes('Split PDF'))
		await splitTab?.trigger('click')

		const splitSubmitBtn = wrapper.findAll('button').find((b) => b.text().includes('Split & Create Songs'))
		await splitSubmitBtn?.trigger('click')

		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		expect(dialogs.showError).toHaveBeenCalledWith(expect.stringContaining('Failed to create target folder in Nextcloud storage'))
	})

	it('supports cropping pages and saves cropped PDF with updated CropBox', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Switch to Crop Pages mode
		const cropTab = wrapper.findAll('button').find((b) => b.text().includes('Crop Pages'))
		expect(cropTab).toBeDefined()
		await cropTab?.trigger('click')

		// Click Crop button on page 1
		const page1CropBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Crop page 1'))
		expect(page1CropBtn).toBeDefined()
		await page1CropBtn?.trigger('click')
		await flushPromises()

		// Verify modal is opened
		const cropModal = wrapper.findComponent({ name: 'SongPdfCropModal' })
		expect(cropModal.exists()).toBe(true)
		expect(cropModal.props('open')).toBe(true)
		expect(cropModal.props('pageNum')).toBe(1)

		// Simulate applying a 10% margin crop to page 1
		cropModal.vm.$emit('apply', { left: 0.1, top: 0.1, width: 0.8, height: 0.8 }, 'current')
		await flushPromises()

		// Verify crop badge is displayed on page 1 card
		expect(wrapper.text()).toContain('Cropped')

		// Click Save Crops button
		const saveCropsBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Crops'))
		expect(saveCropsBtn).toBeDefined()
		expect(saveCropsBtn?.attributes('disabled')).toBeUndefined()

		await saveCropsBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		// Verify replacement cropped PDF was uploaded
		expect(uploadedFiles).toHaveLength(1)
		expect(uploadedFiles[0].songId).toBe(mockSong.id)

		// Verify uploaded PDF has new CropBox applied
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)
		const page1 = savedPdf.getPage(0)
		const cropBox = page1.getCropBox()

		// Original sample page size is 595.28 x 841.89
		// With 0.1 normalized margins, width is 0.8 * 595.28 ~= 476.22, height is 0.8 * 841.89 ~= 673.51
		expect(cropBox.width).toBeCloseTo(595.28 * 0.8, 1)
		expect(cropBox.height).toBeCloseTo(841.89 * 0.8, 1)
	})

	it('supports applying crop to all pages in PDF', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Switch to Crop Pages mode
		const cropTab = wrapper.findAll('button').find((b) => b.text().includes('Crop Pages'))
		await cropTab?.trigger('click')

		// Click 'Crop All Pages…' in toolbar
		const cropAllBtn = wrapper.findAll('button').find((b) => b.text().includes('Crop All Pages…'))
		expect(cropAllBtn).toBeDefined()
		await cropAllBtn?.trigger('click')
		await flushPromises()

		const cropModal = wrapper.findComponent({ name: 'SongPdfCropModal' })
		expect(cropModal.exists()).toBe(true)

		// Apply to all pages
		cropModal.vm.$emit('apply', { left: 0.05, top: 0.05, width: 0.9, height: 0.9 }, 'all')
		await flushPromises()

		// Save Crops
		const saveCropsBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Crops'))
		await saveCropsBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		expect(uploadedFiles).toHaveLength(1)
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)

		// All 8 pages should have the 0.9 dimension crop relative to their original dimensions
		expect(savedPdf.getPageCount()).toBe(8)
		for (let i = 0; i < 8; i++) {
			const page = savedPdf.getPage(i)
			const origWidth = (i === 0 || i === 7) ? 595.28 : 1190.55
			expect(page.getCropBox().width).toBeCloseTo(origWidth * 0.9, 1)
			expect(page.getCropBox().height).toBeCloseTo(841.89 * 0.9, 1)
		}
	})

	it('supports rotating and cropping simultaneously in one step and saves both modifications', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// 1. In Rotate mode: Rotate page 1 by 90° CW
		const rotateCwBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Rotate page 90° CW'))
		expect(rotateCwBtn).toBeDefined()
		await rotateCwBtn?.trigger('click')
		await flushPromises()

		// 2. Switch to Crop mode
		const cropTab = wrapper.findAll('button').find((b) => b.text().includes('Crop Pages'))
		await cropTab?.trigger('click')
		await flushPromises()

		// 3. Open Crop modal for page 1
		const page1CropBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Crop page 1'))
		expect(page1CropBtn).toBeDefined()
		await page1CropBtn?.trigger('click')
		await flushPromises()

		const cropModal = wrapper.findComponent({ name: 'SongPdfCropModal' })
		expect(cropModal.exists()).toBe(true)
		// Verify rotation was passed to crop modal
		expect(cropModal.props('rotation')).toBe(90)

		// Apply crop with 10% margins to page 1
		cropModal.vm.$emit('apply', { left: 0.1, top: 0.1, width: 0.8, height: 0.8 }, 'current')
		await flushPromises()

		// 4. Save modifications
		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Crops') || b.text().includes('Save Changes'))
		expect(saveBtn).toBeDefined()
		expect(saveBtn?.attributes('disabled')).toBeUndefined()

		await saveBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		// 5. Verify single upload containing BOTH rotation and crop applied
		expect(uploadedFiles).toHaveLength(1)
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)

		const page1 = savedPdf.getPage(0)
		// Check that page 1 has 90° rotation
		expect(page1.getRotation().angle).toBe(90)

		// Check that page 1 has rotation-aware CropBox applied
		// For 90° rotation:
		// x = origX + origW * crop.top = 0 + 595.276 * 0.1 = 59.5276
		// y = origY + origH * crop.left = 0 + 841.89 * 0.1 = 84.189
		// width = origW * crop.height = 595.276 * 0.8 = 476.2208
		// height = origH * crop.width = 841.89 * 0.8 = 673.512
		const cropBox = page1.getCropBox()
		expect(cropBox.x).toBeCloseTo(595.28 * 0.1, 1)
		expect(cropBox.y).toBeCloseTo(841.89 * 0.1, 1)
		expect(cropBox.width).toBeCloseTo(595.28 * 0.8, 1)
		expect(cropBox.height).toBeCloseTo(841.89 * 0.8, 1)
	})

	it('supports rotating 180° first and then cropping, applying correct CropBox', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// 1. Rotate page 1 by 180° (click CW twice)
		const rotateCwBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Rotate page 90° CW'))
		expect(rotateCwBtn).toBeDefined()
		await rotateCwBtn?.trigger('click')
		await flushPromises()
		await rotateCwBtn?.trigger('click')
		await flushPromises()

		// 2. Switch to Crop mode
		const cropTab = wrapper.findAll('button').find((b) => b.text().includes('Crop Pages'))
		await cropTab?.trigger('click')
		await flushPromises()

		// 3. Open Crop modal for page 1
		const page1CropBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Crop page 1'))
		expect(page1CropBtn).toBeDefined()
		await page1CropBtn?.trigger('click')
		await flushPromises()

		const cropModal = wrapper.findComponent({ name: 'SongPdfCropModal' })
		expect(cropModal.exists()).toBe(true)
		expect(cropModal.props('rotation')).toBe(180)

		// Apply crop with top half (crop: left: 0, top: 0, width: 1, height: 0.5)
		cropModal.vm.$emit('apply', { left: 0, top: 0, width: 1, height: 0.5 }, 'current')
		await flushPromises()

		// 4. Save modifications
		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Crops') || b.text().includes('Save Changes'))
		expect(saveBtn).toBeDefined()
		expect(saveBtn?.attributes('disabled')).toBeUndefined()

		await saveBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		expect(uploadedFiles).toHaveLength(1)
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)

		const page1 = savedPdf.getPage(0)
		expect(page1.getRotation().angle).toBe(180)

		// For 180° rotation, crop top half (top: 0, height: 0.5):
		// x = 0 + 595.276 * (1 - 0 - 1) = 0
		// y = 0 + 841.89 * 0 = 0
		// width = 595.276
		// height = 841.89 * 0.5 = 420.945
		const cropBox = page1.getCropBox()
		expect(cropBox.x).toBeCloseTo(0, 1)
		expect(cropBox.y).toBeCloseTo(0, 1)
		expect(cropBox.width).toBeCloseTo(595.28, 1)
		expect(cropBox.height).toBeCloseTo(841.89 * 0.5, 1)
	})

	it('supports deleting a single page and saves PDF with page removed', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Initial state: 8 pages
		expect(wrapper.text()).toContain('8 pages')

		// Delete page 2
		const delPage2Btn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Delete page 2'))
		expect(delPage2Btn).toBeDefined()
		await delPage2Btn?.trigger('click')
		await flushPromises()

		// Verify page 2 is marked deleted and header shows remaining count
		expect(wrapper.text()).toContain('Deleted')
		expect(wrapper.text()).toContain('7 / 8 pages')

		// Save button should be enabled
		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Changes') || b.text().includes('Save Rotations'))
		expect(saveBtn).toBeDefined()
		expect(saveBtn?.attributes('disabled')).toBeUndefined()

		await saveBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		// Verify uploaded PDF has 7 pages
		expect(uploadedFiles).toHaveLength(1)
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)
		expect(savedPdf.getPageCount()).toBe(7)
	})

	it('supports restoring a deleted page before saving', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Delete page 2
		const delPage2Btn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Delete page 2'))
		expect(delPage2Btn).toBeDefined()
		await delPage2Btn?.trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Deleted')
		expect(wrapper.text()).toContain('7 / 8 pages')

		// Restore page 2
		const restorePage2Btn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Restore page 2'))
		expect(restorePage2Btn).toBeDefined()
		await restorePage2Btn?.trigger('click')
		await flushPromises()

		expect(wrapper.text()).not.toContain('Deleted')
		expect(wrapper.text()).toContain('8 pages')

		// Save button should now be disabled since no changes remain
		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Changes') || b.text().includes('Save Rotations'))
		expect(saveBtn?.attributes('disabled')).toBeDefined()
	})

	it('supports deleting a page, rotating, and cropping other pages in a single step', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// 1. In Rotate mode: rotate page 1 CW 90°
		const rotatePage1CwBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Rotate page 90° CW'))
		await rotatePage1CwBtn?.trigger('click')

		// 2. Delete page 2
		const delPage2Btn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Delete page 2'))
		await delPage2Btn?.trigger('click')
		await flushPromises()

		// 3. Switch to Crop mode and crop page 3
		const cropTab = wrapper.findAll('button').find((b) => b.text().includes('Crop Pages'))
		await cropTab?.trigger('click')
		await flushPromises()

		const page3CropBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Crop page 3'))
		await page3CropBtn?.trigger('click')
		await flushPromises()

		const cropModal = wrapper.findComponent({ name: 'SongPdfCropModal' })
		expect(cropModal.exists()).toBe(true)
		cropModal.vm.$emit('apply', { left: 0.1, top: 0.1, width: 0.8, height: 0.8 }, 'current')
		await flushPromises()

		// 4. Save modifications
		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Changes') || b.text().includes('Save Crops') || b.text().includes('Save Rotations'))
		expect(saveBtn?.attributes('disabled')).toBeUndefined()
		await saveBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		// 5. Verify single upload containing deletions, rotations, and crops
		expect(uploadedFiles).toHaveLength(1)
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)

		// Page count was 8, page 2 was removed -> 7 pages left
		expect(savedPdf.getPageCount()).toBe(7)

		// Saved page 1 (original page 1): 90° rotation
		const savedPage1 = savedPdf.getPage(0)
		expect(savedPage1.getRotation().angle).toBe(90)

		// Saved page 2 (original page 3, because original page 2 was removed): cropped box
		// Note: Page 3 in sample.pdf is a spread with width 1190.55
		const savedPage2 = savedPdf.getPage(1)
		const cropBox = savedPage2.getCropBox()
		expect(cropBox.x).toBeCloseTo(1190.55 * 0.1, 1)
		expect(cropBox.y).toBeCloseTo(841.89 * (1 - 0.1 - 0.8), 1)
		expect(cropBox.width).toBeCloseTo(1190.55 * 0.8, 1)
		expect(cropBox.height).toBeCloseTo(841.89 * 0.8, 1)
	})

	it('supports cropping page 1 and page 2, joining them into a single page, and saving the PDF', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()
		expect(wrapper.text()).toContain('8 pages')

		// 1. Crop page 1: top half
		const cropModal = wrapper.findComponent({ name: 'SongPdfCropModal' })
		cropModal.vm.$emit('apply', { left: 0, top: 0, width: 1, height: 0.5 }, 'current')
		await flushPromises()

		// 2. Open join modal and trigger join
		const joinModal = wrapper.findComponent({ name: 'SongPdfJoinModal' })
		expect(joinModal.exists()).toBe(true)

		mockPdf.numPages = 7
		joinModal.vm.$emit('join', {
			pageA: 1,
			pageB: 2,
			layout: 'vertical',
			fitToStandardPage: true,
			scaleToFit: true,
			gap: 12,
		})
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		// 3. Document should now have 7 pages (pages 1 and 2 joined into page 1)
		expect(wrapper.text()).toContain('7 pages')

		// 4. Save Changes button should be enabled
		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Changes'))
		expect(saveBtn).toBeDefined()
		expect(saveBtn?.attributes('disabled')).toBeUndefined()

		await saveBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		// 5. Verify single uploaded PDF with 7 pages
		expect(uploadedFiles).toHaveLength(1)
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)
		expect(savedPdf.getPageCount()).toBe(7)

		// Page 1 is the joined single page with standard dimensions
		const page1 = savedPdf.getPage(0)
		expect(page1.getWidth()).toBeCloseTo(595.28, 1)
		expect(page1.getHeight()).toBeCloseTo(841.89, 1)
	})

	it('supports joining two pages with custom page scales to align widths and saves PDF', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		const joinModal = wrapper.findComponent({ name: 'SongPdfJoinModal' })
		expect(joinModal.exists()).toBe(true)

		mockPdf.numPages = 7
		joinModal.vm.$emit('join', {
			pageA: 1,
			pageB: 2,
			layout: 'vertical',
			fitToStandardPage: true,
			scaleToFit: true,
			gap: 12,
			scaleA: 1.0,
			scaleB: 1.4,
		})
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		expect(wrapper.text()).toContain('7 pages')

		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Changes'))
		expect(saveBtn).toBeDefined()
		await saveBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		expect(uploadedFiles).toHaveLength(1)
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)
		expect(savedPdf.getPageCount()).toBe(7)
	})

	it('opens join modal via card button on page 1 with pre-filled page numbers', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Find Join with page 2 button on page 1 card
		const joinBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Join with page 2'))
		expect(joinBtn).toBeDefined()
		await joinBtn?.trigger('click')
		await flushPromises()

		const joinModal = wrapper.findComponent({ name: 'SongPdfJoinModal' })
		expect(joinModal.props('open')).toBe(true)
		expect(joinModal.props('initialPageA')).toBe(1)
		expect(joinModal.props('initialPageB')).toBe(2)
	})

	it('supports rotating a page by an arbitrary angle (e.g. 3.5°) and saving the rotated PDF', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Open custom rotation modal and apply 3.5°
		const rotateModal = wrapper.findComponent({ name: 'SongPdfRotateModal' })
		expect(rotateModal.exists()).toBe(true)

		rotateModal.vm.$emit('apply', 3.5, 'current')
		await flushPromises()

		// Verify page 1 badge shows 3.5°
		expect(wrapper.text()).toContain('3.5°')

		// Click Save Rotations
		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Changes') || b.text().includes('Save Rotations'))
		expect(saveBtn).toBeDefined()
		await saveBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		// Verify uploaded PDF
		expect(uploadedFiles).toHaveLength(1)
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)
		expect(savedPdf.getPageCount()).toBe(8)

		// Page 1 rotated by 3.5° has bounding box > original 595.28 x 841.89
		const page1 = savedPdf.getPage(0)
		expect(page1.getWidth()).toBeGreaterThan(595.28)
		expect(page1.getHeight()).toBeGreaterThan(841.89)
	})

	it('opens rotate modal via card button on page 1 with pre-filled page number', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Find custom angle button on page 1 card
		const rotateCustomBtn = wrapper.findAll('button').find((b) => b.attributes('title')?.includes('Rotate page 1 by custom angle'))
		expect(rotateCustomBtn).toBeDefined()
		await rotateCustomBtn?.trigger('click')
		await flushPromises()

		const rotateModal = wrapper.findComponent({ name: 'SongPdfRotateModal' })
		expect(rotateModal.props('open')).toBe(true)
		expect(rotateModal.props('pageNum')).toBe(1)
	})

	it('supports applying arbitrary rotation to all pages in PDF', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		const rotateModal = wrapper.findComponent({ name: 'SongPdfRotateModal' })
		rotateModal.vm.$emit('apply', 2.5, 'all')
		await flushPromises()

		// All 8 page cards should reflect the 2.5° badge
		const badges = wrapper.findAll('.sheetmusic-rotation-badge, span').filter((s) => s.text() === '2.5°')
		expect(badges.length).toBeGreaterThanOrEqual(8)

		// Click Save
		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Changes') || b.text().includes('Save Rotations'))
		await saveBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		expect(uploadedFiles).toHaveLength(1)
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)
		for (let i = 0; i < 8; i++) {
			expect(savedPdf.getPage(i).getWidth()).toBeGreaterThan(595.28)
			expect(savedPdf.getPage(i).getHeight()).toBeGreaterThan(841.89)
		}
	})

	it('supports rotating a page with cropToOriginalSize option keeping original dimensions', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Apply 3.5° with cropToOriginalSize = true
		const rotateModal = wrapper.findComponent({ name: 'SongPdfRotateModal' })
		rotateModal.vm.$emit('apply', 3.5, 'current', true)
		await flushPromises()

		expect(wrapper.text()).toContain('3.5°')

		// Click Save
		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Changes') || b.text().includes('Save Rotations'))
		await saveBtn?.trigger('click')
		await flushPromises()
		await new Promise((r) => setTimeout(r, 150))
		await flushPromises()

		expect(uploadedFiles).toHaveLength(1)
		const arrayBuffer = await uploadedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)

		// Page 0 was rotated by 3.5° but cropped to original size:
		// Dimensions must match the original 595.28 x 841.89
		const page1 = savedPdf.getPage(0)
		expect(page1.getWidth()).toBeCloseTo(595.28, 1)
		expect(page1.getHeight()).toBeCloseTo(841.89, 1)
	})

	it('switches to enhance mode and opens SongPdfEnhanceModal', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Switch to Enhance Scans tab
		const enhanceTab = wrapper.findAll('button').find((b) => b.text().includes('Enhance Scans'))
		expect(enhanceTab).toBeDefined()
		await enhanceTab?.trigger('click')

		expect(wrapper.text()).toContain('Enhance scan quality')
		expect(wrapper.text()).toContain('Enhance All Pages…')

		// Open enhance modal for page 1
		const enhanceAllBtn = wrapper.findAll('button').find((b) => b.text().includes('Enhance All Pages…'))
		await enhanceAllBtn?.trigger('click')

		const modal = wrapper.findComponent({ name: 'SongPdfEnhanceModal' })
		expect(modal.exists()).toBe(true)
		expect(modal.props('open')).toBe(true)
	})

	it('applies enhancements to all pages, shows badge, and saves modified PDF document', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Switch to Enhance Scans tab
		const enhanceTab = wrapper.findAll('button').find((b) => b.text().includes('Enhance Scans'))
		await enhanceTab?.trigger('click')

		// Emit apply from SongPdfEnhanceModal with operations applied to 'all'
		const modal = wrapper.findComponent({ name: 'SongPdfEnhanceModal' })
		modal.vm.$emit('apply', [
			{ id: 'op-1', type: 'gaussian', size: 3, enabled: true },
			{ id: 'op-2', type: 'grayLevelMapping', lowerCutoff: 30, upperCutoff: 220, colorMode: 'grayscale', enabled: true },
		], 'all')
		await flushPromises()

		// Check that pages show Enhanced badge
		expect(wrapper.text()).toContain('Enhanced')

		// Save Enhanced PDF
		const saveBtn = wrapper.findAll('button').find((b) => b.text().includes('Save Enhanced PDF'))
		expect(saveBtn).toBeDefined()
		await saveBtn?.trigger('click')
		await flushPromises()

		// Progress bar should be visible while saving
		let observedProgressBar = wrapper.find('[data-test="pdf-save-progress"]').exists()
		let maxObservedPercent = 0
		for (let attempt = 0; attempt < 50 && wrapper.text().includes('Saving…'); attempt++) {
			const progressBanner = wrapper.find('[data-test="pdf-save-progress"]')
			if (progressBanner.exists()) {
				observedProgressBar = true
				const pctText = wrapper.find('[data-test="pdf-save-progress-percent"]').text()
				const pctVal = Number.parseInt(pctText, 10)
				if (!Number.isNaN(pctVal) && pctVal > maxObservedPercent) {
					maxObservedPercent = pctVal
				}
			}
			await new Promise((r) => setTimeout(r, 150))
			await flushPromises()
		}
		expect(observedProgressBar).toBe(true)
		expect(maxObservedPercent).toBeGreaterThan(0)
		expect(wrapper.find('[data-test="pdf-save-progress"]').exists()).toBe(false)

		// Verifies in-place update
		expect(updatedFiles).toHaveLength(1)
		expect(updatedFiles[0].songId).toBe(mockSong.id)
		expect(updatedFiles[0].fileId).toBe(999)

		// Verify that saved PDF is a valid document with all pages preserved and identical DPI
		const origPdf = await PDFDocument.load(realPdfBuffer)
		const origResPage0 = getPdfPageImageResolution(origPdf.getPage(0))
		const arrayBuffer = await updatedFiles[0].file.arrayBuffer()
		const savedPdf = await PDFDocument.load(arrayBuffer)
		expect(savedPdf.getPageCount()).toBe(8)
		const savedResPage0 = getPdfPageImageResolution(savedPdf.getPage(0))
		expect(savedResPage0?.width).toBe(origResPage0?.width)
		expect(savedResPage0?.height).toBe(origResPage0?.height)
		expect(savedResPage0?.dpiX).toBeCloseTo(origResPage0!.dpiX, 2)
		expect(savedResPage0?.dpiY).toBeCloseTo(origResPage0!.dpiY, 2)
		expect(wrapper.emitted('saved')).toBeTruthy()
	}, 20000)

	it('resets all scan enhancements when Reset All Enhancements is clicked', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Switch to Enhance Scans tab
		const enhanceTab = wrapper.findAll('button').find((b) => b.text().includes('Enhance Scans'))
		await enhanceTab?.trigger('click')

		// Emit apply
		const modal = wrapper.findComponent({ name: 'SongPdfEnhanceModal' })
		modal.vm.$emit('apply', [
			{ id: 'op-1', type: 'gaussian', size: 3, enabled: true },
		], 'all')
		await flushPromises()

		expect(wrapper.find('[class*="enhanceBadge"]').exists()).toBe(true)

		// Click Reset All Enhancements
		const resetBtn = wrapper.findAll('button').find((b) => b.text().includes('Reset All Enhancements'))
		await resetBtn?.trigger('click')
		await flushPromises()

		expect(wrapper.find('[class*="enhanceBadge"]').exists()).toBe(false)
	})

	it('opens Version History dialog, displays versions and allows reverting', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		// Switch to Enhance Scans tab
		const enhanceTab = wrapper.findAll('button').find((b) => b.text().includes('Enhance Scans'))
		await enhanceTab?.trigger('click')

		// Click Version History...
		const historyBtn = wrapper.findAll('button').find((b) => b.text().includes('Version History…'))
		expect(historyBtn).toBeDefined()
		await historyBtn?.trigger('click')
		await flushPromises()

		// Check Version History dialog is open and displays revisions
		expect(wrapper.text()).toContain('PDF Version History')
		expect(wrapper.text()).toContain('Revert to this version')

		// Click Revert
		const revertBtn = wrapper.findAll('button').find((b) => b.text().includes('Revert to this version'))
		expect(revertBtn).toBeDefined()
		await revertBtn?.trigger('click')
		await flushPromises()

		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('displays PDF file size and resolution info badge and toggles detailed dropdown', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		const infoTrigger = wrapper.find('[data-test="pdf-modifier-info-trigger"]')
		expect(infoTrigger.exists()).toBe(true)
		expect(infoTrigger.text()).toContain('773 KB')
		expect(infoTrigger.text()).toContain('DPI')

		await infoTrigger.trigger('click')
		const dropdown = wrapper.find('[data-test="pdf-modifier-info-dropdown"]')
		expect(dropdown.exists()).toBe(true)
		expect(dropdown.text()).toContain('PDF File & Resolution Info')
		expect(dropdown.text()).toContain('791,921 B')
	})

	it('opens Page Order dialog from header button', async () => {
		const wrapper = mount(SongPdfModifier, {
			props: {
				song: mockSong,
				libraryId: 1,
			},
		})

		await flushPromises()

		const pageOrderBtn = wrapper.findAll('button').find((b) => b.text().includes('Page Order…'))
		expect(pageOrderBtn).toBeDefined()
		await pageOrderBtn?.trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('Edit Page Order')
	})
})

