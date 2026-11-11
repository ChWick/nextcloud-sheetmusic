import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SharesView from '../../../src/components/SharesView.vue'
import type { SharesOverview } from '../../../src/types/library'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php${path}`),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

vi.mock('@nextcloud/l10n', () => ({
	translate: vi.fn((_app: string, text: string) => text),
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['variant', 'size', 'disabled', 'type'],
		emits: ['click'],
		template: '<button type="button" :disabled="disabled" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
	},
}))

vi.mock('@nextcloud/vue/components/NcActions', () => ({
	default: {
		name: 'NcActions',
		template: '<div class="nc-actions"><slot /></div>',
	},
}))

vi.mock('@nextcloud/vue/components/NcActionButton', () => ({
	default: {
		name: 'NcActionButton',
		emits: ['click'],
		template: '<button type="button" class="nc-action-button" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
	},
}))

vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: {
		name: 'NcLoadingIcon',
		template: '<div class="nc-loading-icon" />',
	},
}))

vi.mock('../../../src/components/ViewHeader.vue', () => ({
	default: {
		name: 'ViewHeader',
		props: ['title'],
		template: '<header><h1>{{ title }}</h1><slot /></header>',
	},
}))

vi.mock('../../../src/components/ShareDialog.vue', () => ({
	default: {
		name: 'ShareDialog',
		props: ['libraryId', 'libraryName', 'sublibrary', 'sublibraryPath'],
		emits: ['close'],
		template: '<div class="share-dialog-stub" :data-sub="sublibrary?.slug || \'main\'"><button class="close-btn" @click="$emit(\'close\')">Close</button></div>',
	},
}))

describe('SharesView.vue', () => {
	const mockOverviewData: SharesOverview = {
		library: {
			id: 1,
			name: 'Church Scores',
			path: '/scores',
			shares: [
				{
					id: 101,
					fullId: 'ocinternal:101',
					shareType: 0,
					shareWith: 'bob',
					shareWithDisplayName: 'Bob Singer',
					permission: 'write',
					canWrite: true,
				},
				{
					id: 102,
					fullId: 'ocinternal:102',
					shareType: 3,
					shareWith: '',
					permission: 'read',
					canWrite: false,
					token: 'libPubToken',
					webdavUrl: 'https://example.com/public.php/dav/files/libPubToken',
				},
			],
		},
		sublibraries: [
			{
				id: 10,
				name: 'Organ Repertoire',
				slug: 'organ',
				filters: { instrument: ['Orgel'] },
				shares: [
					{
						id: 201,
						fullId: 'ocinternal:201',
						shareType: 0,
						shareWith: 'foo',
						shareWithDisplayName: 'Foo Organist',
						permission: 'read',
						canWrite: false,
					},
				],
			},
			{
				id: 11,
				name: 'Choir Voices',
				slug: 'choir',
				filters: {},
				shares: [],
			},
		],
	}

	beforeEach(() => {
		vi.clearAllMocks()
		Object.defineProperty(navigator, 'clipboard', {
			value: {
				writeText: vi.fn().mockResolvedValue(undefined),
			},
			configurable: true,
			writable: true,
		})
	})

	it('loads and displays overview of main library and sub-library shares', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: mockOverviewData } },
		})

		const wrapper = mount(SharesView, {
			props: {
				selectedLibrary: 1,
				libraries: [{ id: 1, name: 'Church Scores', path: '/scores', autoPaths: true, autoPdfMetadata: true }],
			},
		})

		// Wait for initial load
		await vi.waitFor(() => {
			expect(axios.get).toHaveBeenCalledWith('/ocs/v2.php/apps/sheetmusic/api/libraries/1/shares/overview', expect.any(Object))
		})
		await wrapper.vm.$nextTick()

		// Verify main library section
		expect(wrapper.find('[data-test="main-library-shares"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Bob Singer')
		expect(wrapper.text()).toContain('Public Link')

		// Verify sub-library cards
		expect(wrapper.find('[data-test="sublibrary-card-organ"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Organ Repertoire')
		expect(wrapper.text()).toContain('Foo Organist')

		expect(wrapper.find('[data-test="sublibrary-card-choir"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('No shares for this sub-library yet.')
	})

	it('opens share dialog for main library', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: mockOverviewData } },
		})

		const wrapper = mount(SharesView, {
			props: {
				selectedLibrary: 1,
			},
		})

		await vi.waitFor(() => {
			expect(wrapper.find('[data-test="main-library-shares"]').exists()).toBe(true)
		})

		// Initially share dialog stub is not rendered
		expect(wrapper.find('.share-dialog-stub').exists()).toBe(false)

		// Click "Share main library" button in header
		const shareBtn = wrapper.find('button')
		await shareBtn.trigger('click')

		expect(wrapper.find('.share-dialog-stub').exists()).toBe(true)
		expect(wrapper.find('.share-dialog-stub').attributes('data-sub')).toBe('main')

		// Closing dialog triggers refresh
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: mockOverviewData } },
		})
		await wrapper.find('.close-btn').trigger('click')
		expect(wrapper.find('.share-dialog-stub').exists()).toBe(false)
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('opens share dialog for a sub-library', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: mockOverviewData } },
		})

		const wrapper = mount(SharesView, {
			props: {
				selectedLibrary: 1,
			},
		})

		await vi.waitFor(() => {
			expect(wrapper.find('[data-test="sublibrary-card-organ"]').exists()).toBe(true)
		})

		const organCard = wrapper.find('[data-test="sublibrary-card-organ"]')
		const shareBtn = organCard.find('button')
		await shareBtn.trigger('click')

		expect(wrapper.find('.share-dialog-stub').exists()).toBe(true)
		expect(wrapper.find('.share-dialog-stub').attributes('data-sub')).toBe('organ')
	})

	it('copies public sync URL to clipboard', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: mockOverviewData } },
		})

		const wrapper = mount(SharesView, {
			props: {
				selectedLibrary: 1,
			},
		})

		await vi.waitFor(() => {
			expect(wrapper.text()).toContain('Copy link')
		})

		const copyBtn = wrapper.findAll('button').find(b => b.text().includes('Copy link'))
		expect(copyBtn).toBeDefined()
		await copyBtn!.trigger('click')

		expect(navigator.clipboard.writeText).toHaveBeenCalledWith('https://example.com/public.php/dav/files/libPubToken')
	})

	it('deletes a share and reloads overview', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: mockOverviewData } },
		})
		vi.mocked(axios.delete).mockResolvedValueOnce({ data: {} })
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						...mockOverviewData,
						library: {
							...mockOverviewData.library,
							shares: [mockOverviewData.library.shares[1]],
						},
					},
				},
			},
		})

		const wrapper = mount(SharesView, {
			props: {
				selectedLibrary: 1,
			},
		})

		await vi.waitFor(() => {
			expect(wrapper.find('[data-test="main-library-shares"]').exists()).toBe(true)
		})

		// Find delete button on main library share (first delete button)
		const deleteBtns = wrapper.findAll('button').filter(b => b.attributes('aria-label') === 'Delete share')
		expect(deleteBtns.length).toBeGreaterThan(0)
		await deleteBtns[0].trigger('click')

		expect(axios.delete).toHaveBeenCalledWith('/ocs/v2.php/apps/sheetmusic/api/libraries/1/shares/101', expect.any(Object))
	})
})
