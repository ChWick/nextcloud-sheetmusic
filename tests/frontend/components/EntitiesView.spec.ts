import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EntitiesView from '../../../src/components/EntitiesView.vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php${path}`),
	generateUrl: vi.fn((path: string) => path),
}))

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return {
		...actual,
		translate: vi.fn((app: string, text: string, vars?: Record<string, any>) => {
			if (!vars) return text
			let res = text
			for (const [k, v] of Object.entries(vars)) {
				res = res.replace(`{${k}}`, String(v))
			}
			return res
		}),
	}
})

const mockRoute = { query: {} }
const mockRouter = { push: vi.fn() }

vi.mock('vue-router', () => ({
	useRoute: () => mockRoute,
	useRouter: () => mockRouter,
}))

describe('EntitiesView.vue', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		mockRoute.query = {}
	})

	it('loads and displays composers correctly', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						songs: [
							{ id: 1, title: 'Symphony', composers: [{ id: 1, name: 'Beethoven' }] },
							{ id: 2, title: 'Concerto', composers: [{ id: 2, name: 'Bach' }] },
							{ id: 3, title: 'Fugue', composers: [{ id: 2, name: 'Bach' }] },
						],
						composers: [
							{ id: 1, name: 'Beethoven' },
							{ id: 2, name: 'Bach' },
						],
						books: [],
						collections: [],
						artists: [],
					},
				},
			},
		})

		const wrapper = mount(EntitiesView, {
			props: { selectedLibrary: 1, entityType: 'composers' },
		})

		await new Promise((r) => setTimeout(r, 10))

		expect(axios.get).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/mobilesheetsmanager/api/songs',
			{ params: { id: 1 } },
		)

		expect(wrapper.text()).toContain('Beethoven')
		expect(wrapper.text()).toContain('Bach')
		expect(wrapper.text()).toContain('1 song')
		expect(wrapper.text()).toContain('2 songs')
	})

	it('loads and displays books correctly', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						songs: [
							{ id: 1, title: 'Symphony', books: [{ id: 10, title: 'Real Book Vol 1' }] },
						],
						composers: [],
						books: [{ id: 10, title: 'Real Book Vol 1' }],
						collections: [],
						artists: [],
					},
				},
			},
		})

		const wrapper = mount(EntitiesView, {
			props: { selectedLibrary: 1, entityType: 'books' },
		})

		await new Promise((r) => setTimeout(r, 10))

		expect(wrapper.text()).toContain('Real Book Vol 1')
		expect(wrapper.text()).toContain('1 song')
	})

	it('shows zero songs for entries without attached songs', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						songs: [],
						composers: [{ id: 1, name: 'Beethoven' }],
						books: [],
						collections: [],
						artists: [],
					},
				},
			},
		})

		const wrapper = mount(EntitiesView, {
			props: { selectedLibrary: 1, entityType: 'composers' },
		})

		await new Promise((r) => setTimeout(r, 10))

		expect(wrapper.text()).toContain('0 songs')
	})

	it('filters entities by text search input', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						songs: [],
						composers: [
							{ id: 1, name: 'Georg Friedrich Händel' },
							{ id: 2, name: 'Johann Sebastian Bach' },
							{ id: 3, name: 'Wolfgang Amadeus Mozart' },
						],
						books: [],
						collections: [],
						artists: [],
					},
				},
			},
		})

		const wrapper = mount(EntitiesView, {
			props: { selectedLibrary: 1, entityType: 'composers' },
		})

		await new Promise((r) => setTimeout(r, 10))

		expect(wrapper.text()).toContain('Händel')
		expect(wrapper.text()).toContain('Bach')
		expect(wrapper.text()).toContain('Mozart')

		const searchInput = wrapper.find('input')
		expect(searchInput.exists()).toBe(true)

		await searchInput.setValue('Händel')

		expect(wrapper.text()).toContain('Händel')
		expect(wrapper.text()).not.toContain('Bach')
		expect(wrapper.text()).not.toContain('Mozart')

		// Diacritic-insensitive check 1: query without umlaut matches entity with umlaut
		await searchInput.setValue('handel')
		expect(wrapper.text()).toContain('Händel')
		expect(wrapper.text()).not.toContain('Bach')

		// Diacritic-insensitive check 2: query with accent on standard characters matches
		await searchInput.setValue('Bách')
		expect(wrapper.text()).toContain('Bach')
		expect(wrapper.text()).not.toContain('Händel')
	})
})
