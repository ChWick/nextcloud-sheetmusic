import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import IntegrityView from '../../../src/components/IntegrityView.vue'
import type { IntegrityIssue } from '../../../src/types/song'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { apiGet, apiPost } from '../../../src/api/client'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
	showInfo: vi.fn(),
	showWarning: vi.fn(),
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
	}),
}))

vi.mock('../../../src/api/client', () => ({
	apiGet: vi.fn(),
	apiPost: vi.fn(),
	apiDelete: vi.fn(),
}))

describe('IntegrityView.vue', () => {
	const mockNamingIssue: IntegrityIssue = {
		type: 'invalid_naming_rule',
		severity: 'warning',
		message: 'File path "OldName.pdf" does not match standard naming rule.',
		songId: 10,
		songTitle: 'Prelude in C',
		fileId: 100,
		path: 'Noten/OldName.pdf',
		suggestedPath: 'Noten/Bach/Bach - Prelude in C.pdf',
		fixable: true,
	}

	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('loads and renders integrity issues grouped by type', async () => {
		vi.mocked(apiGet).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						issues: [mockNamingIssue],
					},
				},
			},
		} as any)

		const wrapper = mount(IntegrityView, {
			props: { selectedLibrary: 1 },
		})
		await flushPromises()

		expect(apiGet).toHaveBeenCalledWith('/api/libraries/1/integrity')
		expect(wrapper.text()).toContain('Invalid Naming Rules')
		expect(wrapper.text()).toContain('Prelude in C')
		expect(wrapper.text()).toContain('Auto-Fix Issue')
		expect(wrapper.text()).toContain('Fix All in Group (1)')
	})

	it('fixes a single invalid naming rule issue, shows success toast with song title, and updates issues locally without reloading', async () => {
		vi.mocked(apiGet).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						issues: [mockNamingIssue],
					},
				},
			},
		} as any)

		vi.mocked(apiPost).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						success: true,
						fixedCount: 1,
						message: 'File path updated according to naming rules.',
					},
				},
			},
		} as any)

		const wrapper = mount(IntegrityView, {
			props: { selectedLibrary: 1 },
		})
		await flushPromises()

		// Find and click Auto-Fix Issue button
		const autoFixBtn = wrapper.findAll('button').find(b => b.text().includes('Auto-Fix Issue'))
		expect(autoFixBtn).toBeDefined()
		await autoFixBtn!.trigger('click')
		await flushPromises()

		// Verify API was called with issue details
		expect(apiPost).toHaveBeenCalledWith('/api/libraries/1/integrity/fix', {
			issueType: 'invalid_naming_rule',
			songId: 10,
			fileId: 100,
			entityType: undefined,
			entityId: undefined,
			path: 'Noten/OldName.pdf',
		})

		// Verify success toast notification contains song title
		expect(showSuccess).toHaveBeenCalledWith('Naming rule applied for "Prelude in C".')

		// Verify apiGet was NOT called again (no full reload)
		expect(apiGet).toHaveBeenCalledTimes(1)

		// The issue should now be removed from the view
		expect(wrapper.text()).not.toContain('Prelude in C')
		expect(wrapper.text()).toContain('All System Checks Passed!')
	})

	it('batch fixes issues in a group, shows success toast with count, and updates issues locally without reloading', async () => {
		const secondNamingIssue: IntegrityIssue = {
			...mockNamingIssue,
			songId: 11,
			fileId: 101,
			songTitle: 'Fugue in G',
		}

		vi.mocked(apiGet).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						issues: [mockNamingIssue, secondNamingIssue],
					},
				},
			},
		} as any)

		vi.mocked(apiPost).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						success: true,
						fixedCount: 2,
						message: 'Applied autofix to 2 issue(s).',
					},
				},
			},
		} as any)

		const wrapper = mount(IntegrityView, {
			props: { selectedLibrary: 1 },
		})
		await flushPromises()

		// Click Fix All in Group
		const fixAllBtn = wrapper.findAll('button').find(b => b.text().includes('Fix All in Group (2)'))
		expect(fixAllBtn).toBeDefined()
		await fixAllBtn!.trigger('click')
		await flushPromises()

		// Verify API call
		expect(apiPost).toHaveBeenCalledWith('/api/libraries/1/integrity/fix-all', {
			issueType: 'invalid_naming_rule',
		})

		// Verify toast notification
		expect(showSuccess).toHaveBeenCalledWith('Fixed 2 integrity issue(s).')

		// Verify no full re-audit reload
		expect(apiGet).toHaveBeenCalledTimes(1)

		// All naming issues should be removed
		expect(wrapper.text()).toContain('All System Checks Passed!')
	})

	it('shows error toast when fixing a single issue fails', async () => {
		const failureMsg = 'Failed to apply naming rule: Cannot regenerate file 493: source "/remote.php/dav/files/admin/v2/Noten/Cruise Control.pdf" does not exist'

		vi.mocked(apiGet).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						issues: [mockNamingIssue],
					},
				},
			},
		} as any)

		vi.mocked(apiPost).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						success: false,
						fixedCount: 0,
						message: failureMsg,
					},
				},
			},
		} as any)

		const wrapper = mount(IntegrityView, {
			props: { selectedLibrary: 1 },
		})
		await flushPromises()

		const autoFixBtn = wrapper.findAll('button').find(b => b.text().includes('Auto-Fix Issue'))
		expect(autoFixBtn).toBeDefined()
		await autoFixBtn!.trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith(failureMsg)
		// The issue should NOT be removed if it failed
		expect(wrapper.text()).toContain('Prelude in C')
	})

	it('shows error toast when batch fix returns a failure message', async () => {
		const failureMsg = 'Failed to apply naming rule for "Prelude in C": Source does not exist'

		vi.mocked(apiGet).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						issues: [mockNamingIssue],
					},
				},
			},
		} as any)

		vi.mocked(apiPost).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						success: false,
						fixedCount: 0,
						message: failureMsg,
					},
				},
			},
		} as any)

		const wrapper = mount(IntegrityView, {
			props: { selectedLibrary: 1 },
		})
		await flushPromises()

		const fixAllBtn = wrapper.findAll('button').find(b => b.text().includes('Fix All in Group (1)'))
		expect(fixAllBtn).toBeDefined()
		await fixAllBtn!.trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith(failureMsg)
	})

	it('shows error toast when audit integrity request rejects', async () => {
		vi.mocked(apiGet).mockRejectedValueOnce({
			response: {
				status: 500,
				data: {
					ocs: {
						meta: {
							message: 'Database corrupted or locked',
						},
					},
				},
			},
		})

		mount(IntegrityView, {
			props: { selectedLibrary: 1 },
		})
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Database corrupted or locked')
	})

	it('renders missing file issues under Missing Files group and not as naming rules', async () => {
		const mockMissingIssue: IntegrityIssue = {
			type: 'missing_file',
			severity: 'error',
			message: 'File "missing.pdf" referenced by song "Prelude in C" does not exist in Nextcloud storage.',
			songId: 10,
			songTitle: 'Prelude in C',
			fileId: 100,
			path: 'Noten/missing.pdf',
			fixable: false,
		}

		vi.mocked(apiGet).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						issues: [mockMissingIssue],
					},
				},
			},
		} as any)

		const wrapper = mount(IntegrityView, {
			props: { selectedLibrary: 1 },
		})
		await flushPromises()

		expect(wrapper.text()).toContain('Missing Files')
		expect(wrapper.text()).not.toContain('Invalid Naming Rules')
		expect(wrapper.text()).toContain('Prelude in C')
		expect(wrapper.text()).toContain('does not exist in Nextcloud storage')
	})

	it('fixes a fixable orphan file issue and shows success toast with song title', async () => {
		const mockOrphanIssue: IntegrityIssue = {
			type: 'orphan_file',
			severity: 'warning',
			message: 'File "Moonlight Sonata.pdf" exists in library storage but is not linked to any song.',
			songId: 42,
			songTitle: 'Moonlight Sonata',
			path: 'Moonlight Sonata.pdf',
			fixable: true,
		}

		vi.mocked(apiGet).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						issues: [mockOrphanIssue],
					},
				},
			},
		} as any)

		vi.mocked(apiPost).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						success: true,
						fixedCount: 1,
						message: 'Linked orphan file "Moonlight Sonata.pdf" to song "Moonlight Sonata".',
					},
				},
			},
		} as any)

		const wrapper = mount(IntegrityView, {
			props: { selectedLibrary: 1 },
		})
		await flushPromises()

		expect(wrapper.text()).toContain('Orphan Files')
		expect(wrapper.text()).toContain('Moonlight Sonata')

		const autoFixBtn = wrapper.findAll('button').find(b => b.text().includes('Auto-Fix Issue'))
		expect(autoFixBtn).toBeDefined()
		await autoFixBtn!.trigger('click')
		await flushPromises()

		expect(apiPost).toHaveBeenCalledWith('/api/libraries/1/integrity/fix', {
			issueType: 'orphan_file',
			songId: 42,
			fileId: undefined,
			entityType: undefined,
			entityId: undefined,
			path: 'Moonlight Sonata.pdf',
		})

		expect(showSuccess).toHaveBeenCalledWith('Linked orphan file to "Moonlight Sonata".')
		expect(wrapper.text()).not.toContain('Moonlight Sonata.pdf')
	})

	it('passes autoMatchOrphans=0 query param when autoMatchOrphans toggle is disabled', async () => {
		vi.mocked(apiGet).mockResolvedValue({
			data: {
				ocs: {
					data: {
						issues: [],
					},
				},
			},
		} as any)

		const wrapper = mount(IntegrityView, {
			props: { selectedLibrary: 1 },
		})
		await flushPromises()

		expect(apiGet).toHaveBeenCalledWith('/api/libraries/1/integrity')

		// Find the switch component or input and toggle it off
		const toggle = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(toggle.exists()).toBe(true)
		await toggle.vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(apiGet).toHaveBeenCalledWith('/api/libraries/1/integrity', {
			queryParams: {
				autoMatchOrphans: '0',
			},
		})
	})
})

