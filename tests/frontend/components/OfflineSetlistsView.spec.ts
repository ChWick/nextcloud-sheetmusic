import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import OfflineSetlistsView from '../../../src/components/setlists/OfflineSetlistsView.vue'
import { OFFLINE_STORAGE_KEY } from '../../../src/utils/offlineSetlists'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
	},
}))

describe('OfflineSetlistsView.vue', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		localStorage.removeItem(OFFLINE_STORAGE_KEY)
	})

	afterEach(() => {
		localStorage.removeItem(OFFLINE_STORAGE_KEY)
	})

	it('shows empty state when no setlists are saved offline', async () => {
		const wrapper = mount(OfflineSetlistsView, {
			props: { libraries: [{ id: 1, path: '/Music' }] as any[] },
		})
		await flushPromises()

		expect(wrapper.text()).toContain('No setlists have been saved for offline use yet.')
	})

	it('groups offline setlists by library and renders read-only detail', async () => {
		localStorage.setItem(OFFLINE_STORAGE_KEY, JSON.stringify({
			'1:10': { libraryId: 1, setlistId: 10, syncedAt: 1, urls: [], fileCount: 1 },
			'2:20': { libraryId: 2, setlistId: 20, syncedAt: 1, urls: [], fileCount: 1 },
		}))

		vi.mocked(axios.get).mockImplementation(async (url: string) => {
			if (url.endsWith('/api/libraries/1/setlists')) {
				return {
					data: {
						ocs: {
							data: [
								{ id: 10, name: 'Offline Setlist A', itemCount: 2 },
								{ id: 11, name: 'Online Only Setlist', itemCount: 1 },
							],
						},
					},
				} as any
			}
			if (url.endsWith('/api/libraries/2/setlists')) {
				return {
					data: { ocs: { data: [{ id: 20, name: 'Offline Setlist B', itemCount: 3 }] } },
				} as any
			}
			if (url.endsWith('/api/libraries/1/setlists/10')) {
				return {
					data: { ocs: { data: { id: 10, name: 'Offline Setlist A', items: [] } } },
				} as any
			}
			if (url.endsWith('/api/libraries/1/setlists/10/files')) {
				return { data: { ocs: { data: [] } } } as any
			}
			return { data: { ocs: { data: { songs: [] } } } } as any
		})

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

		// Verify real child components rendered in read-only mode (no rename/delete/add-item buttons)
		expect(wrapper.text()).not.toContain('Rename')
		expect(wrapper.text()).not.toContain('Delete setlist')
		expect(wrapper.text()).not.toContain('Add first item')
	})
})
