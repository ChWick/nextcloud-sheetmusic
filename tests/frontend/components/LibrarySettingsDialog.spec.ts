import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LibrarySettingsDialog from '../../../src/components/LibrarySettingsDialog.vue'
import type { Library } from '../../../src/types/library'

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
		translate: vi.fn((_app: string, text: string) => text),
	}
})

vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['name', 'size'],
		emits: ['close'],
		template: `
			<div role="dialog" :aria-label="name">
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

vi.mock('@nextcloud/vue/components/NcCheckboxRadioSwitch', () => ({
	default: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type', 'disabled'],
		emits: ['update:modelValue'],
		template: `
			<label>
				<input type="checkbox"
					:checked="modelValue"
					:disabled="disabled"
					@change="$emit('update:modelValue', $event.target.checked)" />
				<slot />
			</label>
		`,
	},
}))

vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: {
		name: 'NcLoadingIcon',
		template: '<span class="loading-icon" />',
	},
}))

describe('LibrarySettingsDialog.vue', () => {
	const mockLibraries: Library[] = [
		{ id: 1, path: '/Noten/library.db', autoPaths: true, autoPdfMetadata: true },
		{ id: 2, path: '/Noten/other.db', autoPaths: false, autoPdfMetadata: false },
	]

	beforeEach(() => {
		vi.resetAllMocks()
		vi.mocked(axios.get).mockResolvedValue({
			data: {
				ocs: {
					data: {
						sublibraries: [],
					},
				},
			},
		} as any)
	})

	it('renders dialog with settings enabled for library 1', () => {
		const wrapper = mount(LibrarySettingsDialog, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		const checkboxes = wrapper.findAll('input[type="checkbox"]')
		expect(checkboxes).toHaveLength(2)
		expect((checkboxes[0].element as HTMLInputElement).checked).toBe(true)
		expect((checkboxes[1].element as HTMLInputElement).checked).toBe(true)
	})

	it('renders dialog with settings disabled for library 2', () => {
		const wrapper = mount(LibrarySettingsDialog, {
			props: {
				selectedLibrary: 2,
				libraries: mockLibraries,
			},
		})

		const checkboxes = wrapper.findAll('input[type="checkbox"]')
		expect(checkboxes).toHaveLength(2)
		expect((checkboxes[0].element as HTMLInputElement).checked).toBe(false)
		expect((checkboxes[1].element as HTMLInputElement).checked).toBe(false)
	})

	it('toggles autoPaths and calls PUT endpoint, emitting updated libraries', async () => {
		vi.mocked(axios.put).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						library: { id: 1, path: '/Noten/library.db', autoPaths: false, autoPdfMetadata: true },
					},
				},
			},
		})

		const wrapper = mount(LibrarySettingsDialog, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		const checkboxes = wrapper.findAll('input[type="checkbox"]')
		await checkboxes[0].setValue(false)

		expect(axios.put).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sheetmusic/api/libraries/1/settings',
			{ autoPaths: false },
			expect.objectContaining({
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			}),
		)

		expect(wrapper.emitted('update:libraries')).toBeTruthy()
		const emittedLibs = wrapper.emitted('update:libraries')![0][0] as Library[]
		expect(emittedLibs.find(l => l.id === 1)?.autoPaths).toBe(false)
	})

	it('toggles autoPdfMetadata and calls PUT endpoint, emitting updated libraries', async () => {
		vi.mocked(axios.put).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						library: { id: 1, path: '/Noten/library.db', autoPaths: true, autoPdfMetadata: false },
					},
				},
			},
		})

		const wrapper = mount(LibrarySettingsDialog, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		const checkboxes = wrapper.findAll('input[type="checkbox"]')
		await checkboxes[1].setValue(false)

		expect(axios.put).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sheetmusic/api/libraries/1/settings',
			{ autoPdfMetadata: false },
			expect.objectContaining({
				headers: { 'OCS-APIREQUEST': 'true', Accept: 'application/json' },
			}),
		)

		expect(wrapper.emitted('update:libraries')).toBeTruthy()
		const emittedLibs = wrapper.emitted('update:libraries')![0][0] as Library[]
		expect(emittedLibs.find(l => l.id === 1)?.autoPdfMetadata).toBe(false)
	})

	it('handles save errors gracefully', async () => {
		vi.mocked(axios.put).mockRejectedValueOnce(new Error('Network error'))

		const wrapper = mount(LibrarySettingsDialog, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		const checkbox = wrapper.find('input[type="checkbox"]')
		await checkbox.setValue(false)

		expect(axios.put).toHaveBeenCalled()
		const errorText = wrapper.find('[role="alert"]')
		expect(errorText.exists()).toBe(true)
		expect(errorText.text()).toContain('Could not save library settings.')
	})

	it('emits close when close button is clicked', async () => {
		const wrapper = mount(LibrarySettingsDialog, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		const closeButton = wrapper.find('.actions button')
		await closeButton.trigger('click')

		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('emits open-sublibraries when manage sub-libraries button is clicked', async () => {
		const wrapper = mount(LibrarySettingsDialog, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		const manageButton = wrapper.findAll('button').find(b => b.text().includes('Manage Sub-Libraries'))
		expect(manageButton).toBeDefined()
		await manageButton!.trigger('click')

		expect(wrapper.emitted('open-sublibraries')).toBeTruthy()
	})
})

