import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import SongsView from '../../../src/components/SongsView.vue'

const mockRoute = reactive<{ params: Record<string, any>; query: Record<string, any> }>({
	params: { library: '1', view: 'songs' },
	query: {},
})
const mockPush = vi.fn((loc: any) => {
	if (loc?.query !== undefined) {
		mockRoute.query = { ...loc.query }
	}
	return Promise.resolve()
})
const mockReplace = vi.fn((loc: any) => {
	if (loc?.query !== undefined) {
		mockRoute.query = { ...loc.query }
	}
	return Promise.resolve()
})

vi.mock('vue-router', async (importOriginal) => {
	const actual = await importOriginal<typeof import('vue-router')>()
	return {
		...actual,
		useRoute: () => mockRoute,
		useRouter: () => ({ push: mockPush, replace: mockReplace }),
	}
})

const mockApiGet = vi.fn()
vi.mock('../../../src/api/client', () => ({
	apiGet: (...args: any[]) => mockApiGet(...args),
	apiPost: vi.fn(),
	apiDelete: vi.fn(),
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: { ocs: { data: { sublibraries: [] } } } }),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

describe('SongsView.vue not-found and loading states', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		mockRoute.query = {}
		mockApiGet.mockResolvedValue({
			data: {
				ocs: {
					data: {
						songs: [{ id: 1, title: 'Existing Song', files: [] }],
						composers: [],
						books: [],
						collections: [],
						artists: [],
						genres: [],
						keys: [],
						customGroups: [],
						years: [],
						signatures: [],
						sourceTypes: [],
						instruments: [],
						arrangers: [],
					},
				},
			},
		})
	})

	const stubs = {
		SongViewer: { template: '<div data-test="song-viewer-stub" />' },
		SongEditor: { template: '<div data-test="song-editor-stub" />' },
		SongPdfModifier: { template: '<div data-test="song-pdf-modifier-stub" />' },
		SongTableView: { template: '<div data-test="song-table-stub" />' },
		SongCardView: { template: '<div />' },
		SongSimpleListView: { template: '<div />' },
		SongFilterBar: { template: '<div />' },
		SongListDisplayMenu: { template: '<div />' },
		BatchSongEditorDialog: { template: '<div />' },
		BatchDeleteSongsDialog: { template: '<div />' },
		SongMergeDialog: { template: '<div />' },
		SongHistoryModal: { template: '<div />' },
	}

	it('shows loading state while library loads and then Song not found for a missing song ID', async () => {
		let resolveSongs!: (val: any) => void
		mockApiGet.mockImplementationOnce(() => new Promise((resolve) => { resolveSongs = resolve }))
		mockRoute.query = { song: '999' }

		const wrapper = mount(SongsView, {
			props: { selectedLibrary: 1, libraries: [{ id: 1, path: '/Noten' }] },
			global: { stubs },
		})

		expect(wrapper.find('[data-test="song-loading-state"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="song-not-found"]').exists()).toBe(false)

		resolveSongs({
			data: {
				ocs: {
					data: {
						songs: [{ id: 1, title: 'Existing Song', files: [] }],
					},
				},
			},
		})
		await flushPromises()

		expect(wrapper.find('[data-test="song-loading-state"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="song-not-found"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Song not found')

		await wrapper.find('[data-test="not-found-action"]').trigger('click')
		expect(mockPush).toHaveBeenCalled()
	})

	it('shows Song not found immediately for an invalid non-numeric song query param', async () => {
		mockRoute.query = { song: 'not-a-number' }

		const wrapper = mount(SongsView, {
			props: { selectedLibrary: 1, libraries: [{ id: 1, path: '/Noten' }] },
			global: { stubs },
		})
		await flushPromises()

		expect(wrapper.find('[data-test="song-not-found"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Song not found')
	})
})
