import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EntityMergeDialog from '../../../src/components/EntityMergeDialog.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		post: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
	showInfo: vi.fn(),
	showWarning: vi.fn(),
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php${path}`),
	generateUrl: vi.fn((path: string) => path),
}))

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return {
		...actual,
		translate: vi.fn((app: string, text: string, vars?: Record<string, any>, opt?: any) => {
			if (!vars) return text
			let res = text
			for (const [k, v] of Object.entries(vars)) {
				const val = opt?.escape === false ? String(v) : String(v).replace(/"/g, '&quot;')
				res = res.replace(`{${k}}`, val)
			}
			return res
		}),
	}
})

describe('EntityMergeDialog.vue', () => {
	const sampleEntities = [
		{ id: 1, name: 'Johann Sebastian Bach' },
		{ id: 2, name: 'J. S. Bach' },
		{ id: 3, name: 'Bach, J. S.' },
	]
	const songCounts = new Map<number, number>([
		[1, 5],
		[2, 3],
		[3, 1],
	])

	beforeEach(() => {
		vi.resetAllMocks()
	})

	it('renders dialog when open and shows pre-selected duplicate from initialEntity', async () => {
		const wrapper = mount(EntityMergeDialog, {
			props: {
				open: true,
				selectedLibrary: 1,
				entityType: 'composers',
				singular: 'composer',
				plural: 'Composers',
				entities: sampleEntities,
				entitySongCounts: songCounts,
				initialEntity: sampleEntities[1], // J. S. Bach
			},
			attachTo: document.body,
		})

		expect(document.body.textContent).toContain('Merge composer')
		expect(document.body.textContent).toContain('Choose which composer to keep')

		// J. S. Bach should be in the duplicate field
		expect(document.body.textContent).toContain('J. S. Bach')
		wrapper.unmount()
	})

	it('allows selecting target, swapping entities, and submitting merge', async () => {
		vi.mocked(axios.post).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						mergedCount: 1,
						libraryData: { songs: [] },
					},
				},
			},
		})

		const wrapper = mount(EntityMergeDialog, {
			props: {
				open: true,
				selectedLibrary: 1,
				entityType: 'composers',
				singular: 'composer',
				plural: 'Composers',
				entities: sampleEntities,
				entitySongCounts: songCounts,
				initialEntity: sampleEntities[1], // duplicate: J. S. Bach (id 2)
			},
			attachTo: document.body,
		})

		// Select target entity (id 1: Johann Sebastian Bach)
		const vm = wrapper.vm as any
		vm.targetEntityOption = {
			id: 1,
			name: 'Johann Sebastian Bach',
			label: 'Johann Sebastian Bach (5 songs)',
			songCount: 5,
		}
		await wrapper.vm.$nextTick()

		// Verify swap is enabled
		expect(vm.canSwap).toBe(true)

		// Test swap
		vm.swapEntities()
		await wrapper.vm.$nextTick()
		expect(vm.targetEntityOption.id).toBe(2)
		expect(vm.duplicateEntityOptions[0].id).toBe(1)

		// Swap back so target is Johann Sebastian Bach (1) and duplicate is J. S. Bach (2)
		vm.swapEntities()
		await wrapper.vm.$nextTick()
		expect(vm.targetEntityOption.id).toBe(1)
		expect(vm.duplicateEntityOptions[0].id).toBe(2)

		// Verify summary text
		expect(document.body.textContent).toContain('Songs linked to "J. S. Bach" will be reassigned to "Johann Sebastian Bach"')
		expect(document.body.textContent).not.toContain('&quot;')

		// Submit merge
		await vm.submitMerge()

		expect(axios.post).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/mobilesheetsmanager/api/entities/merge',
			{
				libraryId: 1,
				entityType: 'composers',
				keepId: 1,
				duplicateIds: [2],
			},
		)

		expect(showSuccess).toHaveBeenCalledWith('Merged duplicates into "Johann Sebastian Bach".')
		expect(wrapper.emitted('merged')).toBeTruthy()
		expect(wrapper.emitted('close')).toBeTruthy()
		wrapper.unmount()
	})

	it('displays error when API call fails', async () => {
		vi.mocked(axios.post).mockRejectedValueOnce({
			response: {
				data: {
					ocs: {
						data: {
							error: 'Failed to merge entities in database',
						},
					},
				},
			},
		})

		const wrapper = mount(EntityMergeDialog, {
			props: {
				open: true,
				selectedLibrary: 1,
				entityType: 'composers',
				singular: 'composer',
				plural: 'Composers',
				entities: sampleEntities,
				entitySongCounts: songCounts,
				initialEntity: sampleEntities[1],
			},
			attachTo: document.body,
		})

		const vm = wrapper.vm as any
		vm.targetEntityOption = {
			id: 1,
			name: 'Johann Sebastian Bach',
			label: 'Johann Sebastian Bach (5 songs)',
			songCount: 5,
		}
		await wrapper.vm.$nextTick()

		await vm.submitMerge()

		expect(showError).toHaveBeenCalledWith('Failed to merge entities in database')
		expect(document.body.textContent).toContain('Failed to merge entities in database')
		expect(wrapper.emitted('merged')).toBeFalsy()
		wrapper.unmount()
	})
})
