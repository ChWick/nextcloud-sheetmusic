import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FilePreviewer from '../../../src/components/FilePreviewer.vue'
import * as fileUtils from '../../../src/utils/file'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: new ArrayBuffer(8) }),
	},
}))

vi.mock('pdfjs-dist', () => ({
	getDocument: vi.fn().mockReturnValue({
		promise: Promise.resolve({
			numPages: 2,
			getPage: vi.fn().mockResolvedValue({
				getViewport: vi.fn().mockReturnValue({ width: 600, height: 800 }),
				render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
			}),
		}),
	}),
	GlobalWorkerOptions: {},
}))

describe('FilePreviewer.vue', () => {
	it('renders image preview for image file path', async () => {
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Joshua.png',
				showExternalButton: true,
			},
		})

		expect(wrapper.find('img').exists()).toBe(true)
		expect(wrapper.find('img').attributes('src')).toBe('/files/scores/Joshua.png')
	})

	it('triggers openFileExternally when external viewer button is clicked', async () => {
		const openSpy = vi.spyOn(fileUtils, 'openFileExternally').mockImplementation(() => {})

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Joshua.pdf',
				showExternalButton: true,
			},
		})

		const externalButton = wrapper.findAll('button').find(b => b.attributes('title')?.includes('external'))
		if (externalButton) {
			await externalButton.trigger('click')
			expect(openSpy).toHaveBeenCalledWith('/files/scores/Joshua.pdf')
		}

		openSpy.mockRestore()
	})

	it('fetches PDF with standard arraybuffer responseType and cache busting headers', async () => {
		const axios = (await import('@nextcloud/axios')).default
		mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Test.pdf',
			},
		})
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/files/scores/Test.pdf', {
			headers: {
				'Cache-Control': 'no-cache',
				'Pragma': 'no-cache',
			},
			responseType: 'arraybuffer',
		})
	})

	it('automatically defaults to single page layout when PDF has more than 30 pages', async () => {
		const pdfjs = await import('pdfjs-dist')
		vi.mocked(pdfjs.getDocument).mockReturnValueOnce({
			promise: Promise.resolve({
				numPages: 160,
				getPage: vi.fn().mockResolvedValue({
					rotate: 0,
					getViewport: vi.fn().mockReturnValue({ width: 600, height: 800 }),
					render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
				}),
			}),
		} as any)

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/LargeJoshua.pdf',
			},
		})
		await flushPromises()

		// Verify page display indicates single page mode (page 1 of 160)
		expect(wrapper.text()).toContain('Page 1 of 160')
	})

	it('creates placeholders with IntersectionObserver for continuous layout mode', async () => {
		const pdfjs = await import('pdfjs-dist')
		vi.mocked(pdfjs.getDocument).mockReturnValueOnce({
			promise: Promise.resolve({
				numPages: 5,
				getPage: vi.fn().mockResolvedValue({
					rotate: 0,
					getViewport: vi.fn().mockReturnValue({ width: 600, height: 800 }),
					render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
				}),
			}),
		} as any)

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/SmallScore.pdf',
			},
		})
		await flushPromises()

		// For continuous layout (<30 pages defaults to continuous), placeholders are created
		const placeholders = wrapper.findAll('.sheetmusic-page-placeholder')
		expect(placeholders.length).toBe(5)
		expect(placeholders[0].attributes('data-page-num')).toBe('1')
		expect(placeholders[4].attributes('data-page-num')).toBe('5')
	})

	it('recognizes PDF file path with query parameter', async () => {
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Test.pdf?t=12345',
			},
		})

		// It should treat as PDF (not unsupported file)
		expect(wrapper.text()).not.toContain('Unsupported file format')
	})

	it('preserves intrinsic page.rotate when getting viewport', async () => {
		const pdfjs = await import('pdfjs-dist')
		const mockGetViewport = vi.fn().mockReturnValue({ width: 600, height: 800 })
		vi.mocked(pdfjs.getDocument).mockReturnValueOnce({
			promise: Promise.resolve({
				numPages: 1,
				getPage: vi.fn().mockResolvedValue({
					rotate: 90,
					getViewport: mockGetViewport,
					render: vi.fn().mockReturnValue({ promise: Promise.resolve() }),
				}),
			}),
		} as any)

		mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/Rotated.pdf',
			},
		})
		await flushPromises()

		expect(mockGetViewport).toHaveBeenCalledWith(expect.objectContaining({
			rotation: 90,
		}))
	})

	it('shows missing file message when PDF request returns 404', async () => {
		const axios = (await import('@nextcloud/axios')).default
		vi.mocked(axios.get).mockRejectedValueOnce({
			response: { status: 404 },
		})

		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/NonExistent.pdf',
			},
		})
		await flushPromises()

		expect(wrapper.text()).toContain('Missing file: The file does not exist on the file system.')
	})

	it('shows missing file message when filePath is empty', async () => {
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '',
			},
		})
		await flushPromises()

		expect(wrapper.text()).toContain('Missing file: The file does not exist on the file system.')
	})

	it('shows missing file message when image fails to load', async () => {
		const wrapper = mount(FilePreviewer, {
			props: {
				filePath: '/files/scores/MissingImage.png',
			},
		})
		await flushPromises()

		const img = wrapper.find('img')
		expect(img.exists()).toBe(true)

		await img.trigger('error')
		expect(wrapper.text()).toContain('Missing file: The file does not exist on the file system.')
	})
})
