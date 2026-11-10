import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShareDialog from '../../../src/components/ShareDialog.vue'
import type { Sublibrary } from '../../../src/types/library'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		put: vi.fn(),
		post: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return {
		...actual,
		translate: vi.fn((_app: string, text: string, vars?: any) => {
			if (vars) {
				let result = text
				for (const [k, v] of Object.entries(vars)) {
					result = result.replace(`{${k}}`, String(v))
				}
				return result
			}
			return text
		}),
	}
})

vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['name', 'size'],
		emits: ['close'],
		template: `
			<div role="dialog" :aria-label="name">
				<h2>{{ name }}</h2>
				<slot />
				<div class="actions">
					<slot name="actions" />
				</div>
			</div>
		`,
	},
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		emits: ['click'],
		template: '<button type="button" @click="$emit(\'click\')"><slot /></button>',
	},
}))

vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: {
		name: 'NcLoadingIcon',
		template: '<span class="loading-icon" />',
	},
}))

vi.mock('@nextcloud/vue/components/NcSelectUsers', () => ({
	default: {
		name: 'NcSelectUsers',
		props: ['modelValue', 'options', 'loading'],
		emits: ['search', 'update:modelValue'],
		template: '<div class="nc-select-users" />',
	},
}))

describe('ShareDialog.vue', () => {
	const mockSublibrary: Sublibrary = {
		id: 5,
		libraryId: 1,
		name: 'Chamber Music',
		slug: 'chamber',
		filters: { genre: ['Chamber'] },
	}

	beforeEach(() => {
		vi.resetAllMocks()
		vi.mocked(axios.get).mockImplementation(async (url: string) => {
			if (url.includes('/shares')) {
				return {
					data: {
						ocs: {
							data: {
								shares: [
									{
										id: '10',
										fullId: 'ocinternal:10',
										shareType: 0,
										shareWith: 'alice',
										shareWithDisplayName: 'Alice Wonderland',
										permission: 'read',
										canWrite: false,
									},
									{
										id: '11',
										fullId: 'ocinternal:11',
										shareType: 3,
										shareWith: '',
										permission: 'read',
										canWrite: false,
										publicUrl: 'https://cloud.test/s/token123',
										webdavUrl: 'https://cloud.test/public.php/dav/files/token123',
									},
								],
							},
						},
					},
				} as any
			}
			return {
				data: {
					ocs: {
						data: {
							exact: { users: [], groups: [] },
							users: [],
							groups: [],
						},
					},
				},
			} as any
		})
	})

	it('renders dialog and loads full library shares when no sublibrary is passed', async () => {
		const wrapper = mount(ShareDialog, {
			props: {
				libraryId: 1,
				libraryName: 'Choir Noten',
			},
		})

		await vi.waitFor(() => {
			expect(axios.get).toHaveBeenCalledWith(
				'/ocs/v2.php/apps/sheetmusic/api/libraries/1/shares',
				expect.anything(),
			)
		})

		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('Share library: Choir Noten')
		expect(wrapper.text()).toContain('Alice Wonderland')
		expect(wrapper.text()).toContain('https://cloud.test/s/token123')
	})

	it('renders dialog and loads sub-library shares when sublibrary prop is passed', async () => {
		const wrapper = mount(ShareDialog, {
			props: {
				libraryId: 1,
				libraryName: 'Choir Noten',
				sublibrary: mockSublibrary,
			},
		})

		await vi.waitFor(() => {
			expect(axios.get).toHaveBeenCalledWith(
				'/ocs/v2.php/apps/sheetmusic/api/libraries/1/sublibraries/5/shares',
				expect.anything(),
			)
		})

		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('Share sub-library: Chamber Music')
		expect(wrapper.text()).toContain('Alice Wonderland')
	})

	it('can delete a share on the correct endpoint', async () => {
		vi.mocked(axios.delete).mockResolvedValue({} as any)

		const wrapper = mount(ShareDialog, {
			props: {
				libraryId: 1,
				libraryName: 'Choir Noten',
			},
		})

		await vi.waitFor(() => {
			expect(wrapper.text()).toContain('Alice Wonderland')
		})

		const deleteButtons = wrapper.findAllComponents({ name: 'NcButton' }).filter(b => b.attributes('title') === 'Revoke share')
		expect(deleteButtons.length).toBeGreaterThan(0)
		await deleteButtons[0].trigger('click')

		expect(axios.delete).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sheetmusic/api/libraries/1/shares/10',
			expect.anything(),
		)
	})

	it('populates NcSelectUsers options with proper displayName and user attributes', async () => {
		vi.mocked(axios.get).mockImplementation(async (url: string) => {
			if (url.includes('/shares')) {
				return { data: { ocs: { data: { shares: [] } } } } as any
			}
			if (url.includes('/sharees')) {
				return {
					data: {
						ocs: {
							data: {
								exact: { users: [], groups: [] },
								users: [
									{
										label: 'Bob The Builder',
										subline: '',
										value: { shareType: 0, shareWith: 'bob' },
										shareWithDisplayNameUnique: 'bob',
									},
								],
								groups: [
									{
										label: 'Sopranos',
										value: { shareType: 1, shareWith: 'sopranos' },
									},
								],
							},
						},
					},
				} as any
			}
			return { data: { ocs: { data: {} } } } as any
		})

		const wrapper = mount(ShareDialog, {
			props: {
				libraryId: 1,
				libraryName: 'Choir Noten',
				ownerId: 'admin',
			},
		})

		await vi.waitFor(() => {
			const selectUsers = wrapper.findComponent({ name: 'NcSelectUsers' })
			expect(selectUsers.exists()).toBe(true)
			const options = selectUsers.props('options') as any[]
			expect(options.length).toBe(2)
			expect(options[0]).toMatchObject({
				id: 'user:bob',
				displayName: 'Bob The Builder',
				user: 'bob',
				subname: '@bob',
				isNoUser: false,
				shareType: 0,
				shareWith: 'bob',
			})
			expect(options[1]).toMatchObject({
				id: 'group:sopranos',
				displayName: 'Sopranos',
				subname: 'Group',
				isNoUser: true,
				shareType: 1,
				shareWith: 'sopranos',
			})
		})
	})
})
