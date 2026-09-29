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
						songs: [],
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
	})

	it('loads and displays books correctly', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						songs: [],
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
	})
})
