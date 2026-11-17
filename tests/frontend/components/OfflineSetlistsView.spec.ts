import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import OfflineSetlistsView from '../../../src/components/setlists/OfflineSetlistsView.vue'

const mockGetOfflineSetlistMap = vi.fn()
const mockFetchSetlists = vi.fn()
const mockFetchSetlist = vi.fn()
const mockFetchSetlistFiles = vi.fn()
const mockApiGet = vi.fn()

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
}))

vi.mock('../../../src/utils/offlineSetlists', () => ({
	getOfflineSetlistMap: () => mockGetOfflineSetlistMap(),
	readOfflineCachedJson: vi.fn().mockResolvedValue(null),
}))

vi.mock('../../../src/api/setlists', () => ({
	fetchSetlists: (...args: unknown[]) => mockFetchSetlists(...args),
	fetchSetlist: (...args: unknown[]) => mockFetchSetlist(...args),
	fetchSetlistFiles: (...args: unknown[]) => mockFetchSetlistFiles(...args),
}))

vi.mock('../../../src/api/client', () => ({
	apiGet: (...args: unknown[]) => mockApiGet(...args),
}))

vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: { template: '<span class="loading" />' },
}))

vi.mock('../../../src/components/setlists/SetlistDetailHeader.vue', () => ({
	default: {
		props: ['name', 'itemCount', 'canWrite', 'offlineSupported'],
		template: '<div class="detail-header" :data-can-write="canWrite">{{ name }}</div>',
	},
}))

vi.mock('../../../src/components/setlists/SetlistFilesSection.vue', () => ({
	default: {
		props: ['files', 'canWrite'],
		template: '<div class="files-section" :data-can-write="canWrite" />',
	},
}))

vi.mock('../../../src/components/setlists/SetlistItemsTable.vue', () => ({
	default: {
		props: ['items', 'canWrite'],
		template: '<div class="items-table" :data-can-write="canWrite" />',
	},
}))

vi.mock('../../../src/components/setlists/SetlistPerformanceViewer.vue', () => ({
	default: { template: '<div class="perf-viewer" />' },
}))

vi.mock('../../../src/components/setlists/SetlistFilePreviewDialog.vue', () => ({
	default: { template: '<div class="file-preview-dialog" />' },
}))

describe('OfflineSetlistsView.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('shows empty state when no setlists are saved offline', async () => {
		mockGetOfflineSetlistMap.mockReturnValue({})
		const wrapper = mount(OfflineSetlistsView, {
			props: { libraries: [{ id: 1, path: '/Music' }] as any[] },
		})
		await flushPromises()

		expect(wrapper.text()).toContain('No setlists have been saved for offline use yet.')
	})

	it('groups offline setlists by library and renders read-only detail', async () => {
		mockGetOfflineSetlistMap.mockReturnValue({
			'1:10': { libraryId: 1, setlistId: 10, syncedAt: 1, urls: [], fileCount: 1 },
			'2:20': { libraryId: 2, setlistId: 20, syncedAt: 1, urls: [], fileCount: 1 },
		})
		mockFetchSetlists.mockImplementation(async (libId: number) => {
			if (libId === 1) {
				return [
					{ id: 10, name: 'Offline Setlist A', itemCount: 2 },
					{ id: 11, name: 'Online Only Setlist', itemCount: 1 },
				]
			}
			return [{ id: 20, name: 'Offline Setlist B', itemCount: 3 }]
		})
		mockApiGet.mockResolvedValue({ data: { ocs: { data: { songs: [] } } } })
		mockFetchSetlist.mockResolvedValue({ id: 10, name: 'Offline Setlist A', items: [] })
		mockFetchSetlistFiles.mockResolvedValue([])

		const wrapper = mount(OfflineSetlistsView, {
			props: {
				libraries: [
					{ id: 1, path: '/Music' },
					{ id: 2, path: '/Choir' },
				] as any[],
			},
		})
		await flushPromises()

		expect(wrapper.text()).toContain('/Music')
		expect(wrapper.text()).toContain('/Choir')
		expect(wrapper.text()).toContain('Offline Setlist A')
		expect(wrapper.text()).toContain('Offline Setlist B')
		expect(wrapper.text()).not.toContain('Online Only Setlist')

		const header = wrapper.find('.detail-header')
		expect(header.attributes('data-can-write')).toBe('false')
		expect(wrapper.find('.items-table').attributes('data-can-write')).toBe('false')
	})
})
