import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import TrashView from '../../../src/components/TrashView.vue'

vi.mock('@nextcloud/axios')
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => path,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string, vars?: Record<string, any>) => {
		if (!vars) return text
		let res = text
		for (const [k, v] of Object.entries(vars)) {
			res = res.replace(`{${k}}`, String(v))
		}
		return res
	},
	getLanguage: () => 'en',
	isRTL: () => false,
}))

describe('TrashView.vue', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
		document.body.innerHTML = ''
	})

	it('renders empty trash state when no deleted songs', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: [] } },
		} as any)

		const wrapper = mount(TrashView, {
			props: {
				selectedLibrary: 1,
			},
		})

		await flushPromises()

		expect(wrapper.text()).toContain('Trash is empty')
		expect(wrapper.text()).toContain('No deleted songs found in this library.')
	})

	it('renders table of deleted songs and allows restoring', async () => {
		const mockDeleted = [
			{
				id: 42,
				uuid: 'uuid-42',
				title: 'Deleted Sonata',
				composers: ['Mozart'],
				fileCount: 2,
				deletedAt: 1700005000,
				rating: 4,
			},
		]

		vi.mocked(axios.get).mockResolvedValueOnce({
			data: { ocs: { data: mockDeleted } },
		} as any)

		vi.mocked(axios.post).mockResolvedValueOnce({
			data: { ocs: { data: { songs: [{ id: 42, title: 'Deleted Sonata' }] } } },
		} as any)

		const wrapper = mount(TrashView, {
			props: {
				selectedLibrary: 1,
			},
		})

		await flushPromises()

		expect(wrapper.text()).toContain('Deleted Sonata')
		expect(wrapper.text()).toContain('Mozart')

		const restoreButton = wrapper.findComponent({ name: 'NcButton' })
		expect(restoreButton.exists()).toBe(true)
		await restoreButton.trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/sheetmusic/api/songs/42/restore')
		expect(showSuccess).toHaveBeenCalled()
		expect(wrapper.emitted('restored')).toBeTruthy()
		// Song should be removed from view
		expect(wrapper.text()).toContain('Trash is empty')
	})
})
