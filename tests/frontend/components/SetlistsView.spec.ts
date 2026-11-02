import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import axios from '@nextcloud/axios'
import SetlistsView from '../../../src/components/SetlistsView.vue'

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
vi.mock('vue-router', () => ({
	useRouter: () => ({
		push: vi.fn(),
	}),
	useRoute: () => ({
		query: {},
		params: {},
	}),
}))

describe('SetlistsView.vue', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
		document.body.innerHTML = ''
	})

	it('renders empty setlists state when no setlists exist', async () => {
		vi.mocked(axios.get).mockImplementation((url: string) => {
			if (url.includes('/api/songs')) {
				return Promise.resolve({ data: { ocs: { data: { songs: [] } } } }) as any
			}
			return Promise.resolve({ data: { ocs: { data: [] } } }) as any
		})

		const wrapper = mount(SetlistsView, {
			props: {
				selectedLibrary: 1,
			},
		})

		await flushPromises()

		expect(wrapper.text()).toContain('Setlists')
		expect(wrapper.text()).toContain('No setlists yet')
	})

	it('renders list of setlists and displays selected setlist items', async () => {
		const mockSetlists = [
			{ id: 10, name: 'Concert Gig', itemCount: 2 },
			{ id: 20, name: 'Sunday Service', itemCount: 0 },
		]
		const mockSetlistDetails = {
			id: 10,
			name: 'Concert Gig',
			itemCount: 2,
			items: [
				{
					id: 101,
					setlistId: 10,
					songId: 1,
					songTitle: 'First Song',
					bookmarkId: null,
					bookmarkName: null,
					position: 0,
					duration: 180,
				},
				{
					id: 102,
					setlistId: 10,
					songId: 2,
					songTitle: 'Second Song',
					bookmarkId: 5,
					bookmarkName: 'Movement II',
					pageNum: 4,
					position: 1,
					duration: 240,
				},
			],
		}

		vi.mocked(axios.get).mockImplementation((url: string) => {
			if (url.includes('/api/songs')) {
				return Promise.resolve({ data: { ocs: { data: { songs: [] } } } }) as any
			}
			if (url.includes('/api/libraries/1/setlists/10')) {
				return Promise.resolve({ data: { ocs: { data: mockSetlistDetails } } }) as any
			}
			if (url.includes('/api/libraries/1/setlists')) {
				return Promise.resolve({ data: { ocs: { data: mockSetlists } } }) as any
			}
			return Promise.resolve({ data: { ocs: { data: [] } } }) as any
		})

		const wrapper = mount(SetlistsView, {
			props: {
				selectedLibrary: 1,
			},
		})

		await flushPromises()

		expect(wrapper.text()).toContain('Concert Gig')
		expect(wrapper.text()).toContain('Sunday Service')

		// Items in detail pane
		expect(wrapper.text()).toContain('First Song')
		expect(wrapper.text()).toContain('Second Song')
		expect(wrapper.text()).toContain('Movement II')
		expect(wrapper.text()).toContain('(p. 4)')
	})

	it('reorders items when move button is clicked', async () => {
		const mockSetlists = [{ id: 10, name: 'Concert Gig', itemCount: 2 }]
		const mockSetlistDetails = {
			id: 10,
			name: 'Concert Gig',
			itemCount: 2,
			items: [
				{ id: 101, setlistId: 10, songId: 1, songTitle: 'Song A', position: 0 },
				{ id: 102, setlistId: 10, songId: 2, songTitle: 'Song B', position: 1 },
			],
		}

		vi.mocked(axios.get).mockImplementation((url: string) => {
			if (url.includes('/api/libraries/1/setlists/10')) {
				return Promise.resolve({ data: { ocs: { data: mockSetlistDetails } } }) as any
			}
			if (url.includes('/api/libraries/1/setlists')) {
				return Promise.resolve({ data: { ocs: { data: mockSetlists } } }) as any
			}
			return Promise.resolve({ data: { ocs: { data: { songs: [] } } } }) as any
		})
		vi.mocked(axios.post).mockResolvedValue({ data: { ocs: { data: { success: true } } } } as any)

		const wrapper = mount(SetlistsView, {
			props: {
				selectedLibrary: 1,
			},
		})

		await flushPromises()

		// Click Move Down on the first item
		const moveDownButtons = wrapper.findAll('button[title="Move down"]')
		expect(moveDownButtons.length).toBeGreaterThan(0)
		await moveDownButtons[0].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/sheetmusic/api/setlists/10/reorder',
			expect.objectContaining({
				itemIds: [102, 101],
			}),
		)
	})

	it('opens create dialog and creates new setlist', async () => {
		vi.mocked(axios.get).mockResolvedValue({ data: { ocs: { data: [] } } } as any)
		vi.mocked(axios.post).mockResolvedValue({
			data: { ocs: { data: { id: 99, name: 'Brand New Setlist' } } },
		} as any)

		const wrapper = mount(SetlistsView, {
			props: {
				selectedLibrary: 1,
			},
		})

		await flushPromises()

		// Click Create button in ViewHeader
		const header = wrapper.findComponent({ name: 'ViewHeader' })
		header.vm.$emit('create')
		await flushPromises()

		expect(document.body.textContent).toContain('Enter a name for the new setlist:')

		const textFields = wrapper.findAllComponents({ name: 'NcTextField' })
		const dialogField = textFields.find(c => c.props('placeholder')?.includes('Recital'))
		expect(dialogField).toBeDefined()
		dialogField!.vm.$emit('update:modelValue', 'Brand New Setlist')
		await wrapper.vm.$nextTick()

		const createBtn = Array.from(document.body.querySelectorAll('button')).find(b => b.textContent?.includes('Create setlist'))
		expect(createBtn).toBeDefined()
		createBtn!.click()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/sheetmusic/api/setlists',
			expect.objectContaining({
				libraryId: 1,
				name: 'Brand New Setlist',
			}),
		)
	})
})
