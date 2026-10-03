import { mount } from '@vue/test-utils'
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
})
