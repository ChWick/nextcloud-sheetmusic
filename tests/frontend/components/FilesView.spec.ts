import type { FileLogEntry, IntegrityIssue, LibraryData } from '../../../src/types/song'

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FilesView from '../../../src/components/FilesView.vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
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

	it('renders files header without the sync tool section', () => {
		const wrapper = mount(FilesView, {
			props: { selectedLibrary: -1 },
			...globalStubs,
		})

		expect(wrapper.find('h1').text()).toBe('Files')
		expect(wrapper.text()).not.toContain('Sync file paths and metadata')
		expect(wrapper.text()).not.toContain('Check and sync now')
	})

	it('executes resolveBoth with file detaches, song updates, and sync recheck', async () => {
		vi.mocked(axios.post).mockResolvedValue({
			data: { ocs: { data: { success: true, issues: [] } } },
		})

		const wrapper = mount(FilesView, {
			props: { selectedLibrary: 2 },
			...globalStubs,
		})

		// Directly invoke executeResolveBoth logic via component methods
		const vm = wrapper.vm as any
		await vm.executeResolveBoth({
			songA: {
				id: 60,
				title: 'Nachspiel A',
				composerIds: [1],
				bookIds: [],
				collectionIds: [],
				artistIds: [],
				genreIds: [],
				keyIds: [],
				keywords: 'organ',
				sortTitle: 'Nachspiel A',
				detachFileIds: [207],
			},
			songB: {
				id: 61,
				title: 'Nachspiel B',
				composerIds: [1],
				bookIds: [],
				collectionIds: [],
				artistIds: [],
				genreIds: [],
				keyIds: [],
				keywords: 'organ',
				sortTitle: 'Nachspiel B',
				detachFileIds: [],
			},
		})

		// Verify API call to resolve-both endpoint
		expect(axios.post).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/mobilesheetsmanager/api/songs/resolve-both',
			expect.objectContaining({
				libraryId: 2,
				songA: expect.objectContaining({ id: 60, title: 'Nachspiel A' }),
				songB: expect.objectContaining({ id: 61, title: 'Nachspiel B' }),
			}),
		)
	})
})
