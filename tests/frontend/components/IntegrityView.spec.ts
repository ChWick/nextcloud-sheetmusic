import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import axios from '@nextcloud/axios'
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

vi.mock('vue-router', async (importOriginal) => {
	const actual = await importOriginal<typeof import('vue-router')>()
	return {
		...actual,
		useRouter: () => ({
			push: vi.fn(),
		}),
		useRoute: () => ({
			query: {},
		}),
	}
})

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

		expect(apiGet).toHaveBeenCalledWith('/api/libraries/1/integrity', expect.objectContaining({
			queryParams: expect.objectContaining({
				progressId: expect.stringMatching(/^audit-/),
			}),
		}))
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
		expect(apiPost).toHaveBeenCalledWith('/api/libraries/1/integrity/fix-all', expect.objectContaining({
			issueType: 'invalid_naming_rule',
		}))

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

		expect(apiGet).toHaveBeenCalledWith('/api/libraries/1/integrity', expect.objectContaining({
			queryParams: expect.objectContaining({
				progressId: expect.stringMatching(/^audit-/),
			}),
		}))

		// Find the switch component or input and toggle it off
		const toggle = wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(toggle.exists()).toBe(true)
		await toggle.vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(apiGet).toHaveBeenCalledWith('/api/libraries/1/integrity', expect.objectContaining({
			queryParams: expect.objectContaining({
				autoMatchOrphans: '0',
			}),
		}))
	})

	it('opens Duplicate Songs Resolution dialog from an integrity issue, copies fields via arrow buttons, and submits merge', async () => {
		const duplicateIssue: IntegrityIssue = {
			type: 'duplicate_file_path',
			severity: 'error',
			message: 'Multiple database entries point to the same file path',
			songId: 60,
			songTitle: 'Nachspiel in G',
			fixable: false,
			details: {
				songIds: [60, 61],
				exactDuplicate: false,
			},
		}

		vi.mocked(apiGet)
			.mockResolvedValueOnce({
				data: {
					ocs: {
						data: {
							issues: [duplicateIssue],
						},
					},
				},
			} as any)
			.mockResolvedValueOnce({
				data: {
					ocs: {
						data: {
							songs: [
								{
									id: 60,
									title: 'Nachspiel in G',
									composers: [{ id: 1, name: 'Brosig' }],
									arrangers: [{ id: 11, name: 'Straube' }],
									duration: 210,
									stars: 4,
									files: [{ id: 207, path: '/files/Nachspiel.pdf', type: 1 }],
								},
								{
									id: 61,
									title: 'Nachspiel in D',
									composers: [{ id: 1, name: 'Brosig' }],
									arrangers: [{ id: 12, name: 'Reger' }],
									duration: 180,
									stars: 5,
									files: [{ id: 208, path: '/files/Nachspiel2.pdf', type: 1 }],
								},
							],
							composers: [{ id: 1, name: 'Brosig' }],
							arrangers: [{ id: 11, name: 'Straube' }, { id: 12, name: 'Reger' }],
						},
					},
				},
			} as any)

		vi.mocked(apiPost).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						songs: [],
					},
				},
			},
		} as any)

		const wrapper = mount(IntegrityView, {
			props: { selectedLibrary: 1 },
			global: {
				stubs: {
					NcDialog: {
						template: '<div class="nc-dialog-stub"><slot /></div>',
						props: ['name', 'open', 'size'],
					},
				},
			},
		})
		await flushPromises()

		// Click "Merge Songs" on the duplicate issue item
		const openMergeBtn = wrapper.findAll('button').find(b => b.text().includes('Merge Songs'))
		expect(openMergeBtn).toBeDefined()
		await openMergeBtn!.trigger('click')
		await flushPromises()

		// Click arrow buttons inside the dialog and verify real input / select DOM updates
		await wrapper.find('[data-test="copy-from-a-title"]').trigger('click')
		expect((wrapper.find('input#merge-title-input').element as HTMLInputElement).value).toBe('Nachspiel in G')

		await wrapper.find('[data-test="copy-from-b-arrangers"]').trigger('click')
		expect(wrapper.find('[data-test="merged-field-arrangers"]').text()).toContain('Reger')

		await wrapper.find('[data-test="copy-from-a-duration"]').trigger('click')
		expect((wrapper.find('input#merge-duration-input').element as HTMLInputElement).value).toBe('210')

		// Submit merge
		await wrapper.find('[data-test="submit-merge-btn"]').trigger('click')
		await flushPromises()

		expect(apiPost).toHaveBeenCalledWith(
			'/api/songs/merge',
			expect.objectContaining({
				libraryId: 1,
				keepId: 60,
				droppedId: 61,
				merged: expect.objectContaining({
					title: 'Nachspiel in G',
					composerIds: [1],
					arrangerIds: [12],
					duration: 210,
				}),
			}),
		)
		expect(showSuccess).toHaveBeenCalledWith('Songs merged successfully into "Nachspiel in G".')
		expect(wrapper.text()).toContain('All System Checks Passed!')
	})

	it('displays progress bar during integrity audit when polling updates progress', async () => {
		let resolveAuditPromise: any
		const auditPromise = new Promise((resolve) => {
			resolveAuditPromise = resolve
		})
		vi.mocked(apiGet).mockReturnValueOnce(auditPromise as any)

		vi.mocked(axios.get).mockResolvedValue({
			data: {
				ocs: {
					data: {
						status: 'running',
						stage: 'audit',
						current: 45,
						total: 100,
						percent: 45,
						message: 'Auditing song 45 of 100: Moonlight Sonata',
					},
				},
			},
		} as any)

		const wrapper = mount(IntegrityView, {
			props: { selectedLibrary: 1 },
		})
		await flushPromises()

		// Initial audit progress state is displayed
		expect(wrapper.find('[data-test="audit-progress-container"]').exists()).toBe(true)

		// Wait for polling cycle
		await new Promise((r) => setTimeout(r, 350))
		await flushPromises()

		expect(wrapper.text()).toContain('Auditing song 45 of 100: Moonlight Sonata')
		const progressBar = wrapper.findComponent({ name: 'NcProgressBar' })
		expect(progressBar.exists()).toBe(true)
		expect(progressBar.props('value')).toBe(45)

		// Finish audit
		resolveAuditPromise({
			data: {
				ocs: {
					data: {
						issues: [],
					},
				},
			},
		})
		await flushPromises()

		expect(wrapper.find('[data-test="audit-progress-container"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('All System Checks Passed!')
	})
})

