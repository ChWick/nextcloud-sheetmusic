import type { FileLogEntry } from '../../../src/types/song'

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FilesView from '../../../src/components/FilesView.vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		post: vi.fn(),
		isAxiosError: vi.fn((err: any) => err?.isAxiosError === true),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php${path}`),
	generateUrl: vi.fn((path: string) => path),
}))

vi.mock('@nextcloud/l10n', () => ({
	translate: vi.fn((app: string, text: string) => text),
	getLanguage: vi.fn(() => 'en'),
	isRTL: vi.fn(() => false),
}))

const mockRouterPush = vi.fn()
vi.mock('vue-router', () => ({
	useRouter: () => ({
		push: mockRouterPush,
	}),
}))

describe('FilesView.vue', () => {
	const globalStubs = {
		global: {
			stubs: {
				ViewHeader: {
					template: '<header><h1>{{ title }}</h1></header>',
					props: ['title'],
				},
				NcDialog: {
					template: '<div class="nc-dialog-stub"><slot /></div>',
				},
				NcButton: {
					template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
					props: ['disabled', 'variant'],
				},
			},
		},
	}

	beforeEach(() => {
		vi.resetAllMocks()
	})

	it('renders header and sync tool card when no library is selected', () => {
		const wrapper = mount(FilesView, {
			props: { selectedLibrary: -1 },
			...globalStubs,
		})

		expect(wrapper.find('h1').text()).toBe('Files')
		expect(wrapper.text()).toContain('Sync file paths and metadata')
		expect(wrapper.text()).toContain('Select a library first.')
	})

	it('triggers sync OCS request and opens report dialog when sync button clicked', async () => {
		const mockLog: FileLogEntry[] = [
			{ songId: 5, songTitle: 'Song 5', filePath: '/f5.pdf', message: 'Moved', level: 'info', code: 'path-moved', fileId: 50 },
		]

		vi.mocked(axios.post).mockResolvedValue({
			data: {
				ocs: {
					data: {
						log: mockLog,
					},
				},
			},
		})

		const wrapper = mount(FilesView, {
			props: { selectedLibrary: 2 },
			...globalStubs,
		})

		const syncBtn = wrapper.find('button')
		await syncBtn.trigger('click')

		expect(axios.post).toHaveBeenCalledWith('/ocs/v2.php/apps/mobilesheetsmanager/api/sync-library', {
			libraryId: 2,
		})

		expect(wrapper.find('.nc-dialog-stub').exists()).toBe(true)
		expect(wrapper.text()).toContain('Song 5')
	})

	it('handles sync request error correctly', async () => {
		vi.mocked(axios.post).mockRejectedValue({
			isAxiosError: true,
			response: {
				data: {
					ocs: {
						data: {
							message: 'Library database locked',
						},
					},
				},
			},
		})

		const wrapper = mount(FilesView, {
			props: { selectedLibrary: 2 },
			...globalStubs,
		})

		const syncBtn = wrapper.find('button')
		await syncBtn.trigger('click')

		expect(wrapper.text()).toContain('Library database locked')
	})

	it('navigates to song editor when song link in report log is clicked', async () => {
		const mockLog: FileLogEntry[] = [
			{ songId: 12, songTitle: 'Target Song', filePath: '/f12.pdf', message: 'Updated', level: 'info', code: 'metadata-updated', fileId: 120 },
		]

		vi.mocked(axios.post).mockResolvedValue({
			data: {
				ocs: {
					data: {
						log: mockLog,
					},
				},
			},
		})

		const wrapper = mount(FilesView, {
			props: { selectedLibrary: 1 },
			...globalStubs,
		})

		await wrapper.find('button').trigger('click')

		const songBtn = wrapper.findAll('button').find((btn) => btn.text().includes('Target Song'))
		expect(songBtn).toBeDefined()
		await songBtn!.trigger('click')

		expect(mockRouterPush).toHaveBeenCalledWith({
			name: 'LibraryView',
			params: { library: '1', view: 'songs' },
			query: { song: '12', edit: '1' },
		})
	})
})
