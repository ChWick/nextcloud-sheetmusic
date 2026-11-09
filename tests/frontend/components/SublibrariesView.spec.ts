import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SublibrariesView from '../../../src/components/SublibrariesView.vue'
import type { Library, Sublibrary } from '../../../src/types/library'

const mockRouter = { push: vi.fn() }
vi.mock('vue-router', () => ({
	useRouter: () => mockRouter,
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
		put: vi.fn(),
		post: vi.fn(),
		delete: vi.fn(),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php${path}`),
}))

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return {
		...actual,
		translate: vi.fn((_app: string, text: string) => text),
	}
})

vi.mock('../../../src/composables/useLibraryData', () => ({
	useLibraryData: () => ({
		songs: {
			value: [
				{
					id: 1,
					title: 'Toccata and Fugue',
					instruments: [{ id: 1, name: 'Orgel' }],
					composers: [{ id: 1, name: 'J.S. Bach' }],
				},
				{
					id: 2,
					title: 'Moonlight Sonata',
					instruments: [{ id: 2, name: 'Klavier' }],
					composers: [{ id: 2, name: 'Beethoven' }],
				},
			],
		},
		instruments: {
			value: [
				{ id: 1, name: 'Orgel' },
				{ id: 2, name: 'Klavier' },
			],
		},
		composers: {
			value: [
				{ id: 1, name: 'J.S. Bach' },
				{ id: 2, name: 'Beethoven' },
			],
		},
		genres: { value: [] },
		collections: { value: [] },
		artists: { value: [] },
		books: { value: [] },
		loadLibraryData: vi.fn(),
	}),
}))

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
		template: '<button type="button" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
	},
}))

vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: {
		name: 'NcTextField',
		props: ['modelValue', 'value', 'label', 'placeholder'],
		emits: ['update:modelValue', 'update:model-value'],
		template: `
			<div class="nc-text-field">
				<label>{{ label }}</label>
				<input
					:value="modelValue !== undefined ? modelValue : value"
					:placeholder="placeholder"
					@input="$emit('update:modelValue', $event.target.value); $emit('update:model-value', $event.target.value)" />
			</div>
		`,
	},
}))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'label', 'reduce', 'inputLabel', 'clearable', 'multiple'],
		emits: ['update:modelValue'],
		template: `
			<div class="nc-select" :data-multiple="multiple">
				<label>{{ inputLabel }}</label>
				<select
					:multiple="multiple"
					:value="modelValue"
					@change="handleChange($event)">
					<option v-for="opt in options" :key="opt.id" :value="reduce ? reduce(opt) : opt">
						{{ opt[label || 'label'] }}
					</option>
				</select>
			</div>
		`,
		methods: {
			handleChange(event: any) {
				if (this.multiple) {
					const selected = Array.from(event.target.selectedOptions).map((o: any) => o.value)
					this.$emit('update:modelValue', selected)
				} else {
					this.$emit('update:modelValue', event.target.value)
				}
			},
		},
	},
}))

vi.mock('@nextcloud/vue/components/NcLoadingIcon', () => ({
	default: {
		name: 'NcLoadingIcon',
		template: '<span class="loading-icon" />',
	},
}))

describe('SublibrariesView.vue', () => {
	const mockLibraries: Library[] = [
		{ id: 1, path: '/Noten/library.db', autoPaths: true, autoPdfMetadata: true },
		{ id: 2, path: '/Other/library.db', autoPaths: false, autoPdfMetadata: false },
	]

	const mockSublibraries: Sublibrary[] = [
		{
			id: 10,
			libraryId: 1,
			name: 'Organ Works',
			slug: 'organ',
			filters: { instrument: ['Orgel'] },
		},
		{
			id: 11,
			libraryId: 1,
			name: 'Bach Scores',
			slug: 'bach',
			filters: { composer: ['J.S. Bach'] },
		},
	]

	beforeEach(() => {
		vi.resetAllMocks()
		Object.defineProperty(navigator, 'clipboard', {
			value: {
				writeText: vi.fn().mockResolvedValue(undefined),
			},
			writable: true,
			configurable: true,
		})
	})

	it('renders empty state when there are no sub-libraries', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						sublibraries: [],
					},
				},
			},
		} as any)

		const wrapper = mount(SublibrariesView, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		await (new Promise(resolve => setTimeout(resolve, 0)))

		expect(wrapper.text()).toContain('No sub-libraries configured')
		expect(wrapper.find('[data-test="sublibraries-view"]').exists()).toBe(true)
	})

	it('loads and displays sub-libraries with cards and matching song counts', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						sublibraries: mockSublibraries,
					},
				},
			},
		} as any)

		const wrapper = mount(SublibrariesView, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		await (new Promise(resolve => setTimeout(resolve, 0)))

		expect(axios.get).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sheetmusic/api/libraries/1/sublibraries',
			expect.any(Object),
		)

		expect(wrapper.text()).toContain('Organ Works')
		expect(wrapper.text()).toContain('organ')
		expect(wrapper.text()).toContain('/Noten/_SubLibraries_/organ')
		expect(wrapper.text()).toContain('instrument: Orgel')

		expect(wrapper.text()).toContain('Bach Scores')
		expect(wrapper.text()).toContain('bach')
		expect(wrapper.text()).toContain('/Noten/_SubLibraries_/bach')
		expect(wrapper.text()).toContain('composer: J.S. Bach')
	})

	it('copies WebDAV path to clipboard', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						sublibraries: mockSublibraries,
					},
				},
			},
		} as any)

		const wrapper = mount(SublibrariesView, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		await (new Promise(resolve => setTimeout(resolve, 0)))

		const copyBtn = wrapper.find('button[class*="copyLink"]')
		expect(copyBtn.exists()).toBe(true)
		await copyBtn.trigger('click')

		expect(navigator.clipboard.writeText).toHaveBeenCalledWith('/Noten/_SubLibraries_/organ')
	})

	it('navigates to songs view filtered by sub-library when View songs is clicked', async () => {
		vi.mocked(axios.get).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						sublibraries: mockSublibraries,
					},
				},
			},
		} as any)

		const wrapper = mount(SublibrariesView, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		await (new Promise(resolve => setTimeout(resolve, 0)))

		const viewSongsBtn = wrapper.findAll('button').find(b => b.text().includes('View songs'))
		expect(viewSongsBtn).toBeDefined()
		await viewSongsBtn!.trigger('click')

		expect(mockRouter.push).toHaveBeenCalledWith({
			name: 'LibraryView',
			params: { library: 1, view: 'songs' },
			query: { sublibraries: 'organ' },
		})
	})

	it('creates a new sub-library via modal', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: {
				ocs: {
					data: {
						sublibraries: [],
					},
				},
			},
		} as any)

		vi.mocked(axios.post).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						id: 12,
						libraryId: 1,
						name: 'Piano Music',
						slug: 'piano-music',
						filters: { instrument: ['Klavier'] },
					},
				},
			},
		} as any)

		const wrapper = mount(SublibrariesView, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		await (new Promise(resolve => setTimeout(resolve, 0)))

		// Click "Create sub-library"
		const createBtn = wrapper.findAll('button').find(b => b.text().includes('Create sub-library'))
		expect(createBtn).toBeDefined()
		await createBtn!.trigger('click')

		// Dialog should be open
		expect(wrapper.find('[role="dialog"]').exists()).toBe(true)

		// Verify select options have real labels, not "undefined"
		const selectOptions = wrapper.findAll('.nc-select option')
		expect(selectOptions.length).toBeGreaterThan(0)
		for (const opt of selectOptions) {
			expect(opt.text().trim()).not.toBe('undefined')
			expect(opt.text().trim()).not.toBe('')
		}
		expect(selectOptions[0].text().trim()).toBe('Instrument')

		// Fill name input
		const inputs = wrapper.findAll('input')
		await inputs[0].setValue('Piano Music')

		// Verify resulting path preview updates dynamically
		expect(wrapper.text()).toContain('Resulting WebDAV folder path for MobileSheets:')
		expect(wrapper.text()).toContain('/Noten/_SubLibraries_/piano-music')

		// Select values from multi-select dropdown
		const selects = wrapper.findAll('select')
		await selects[1].setValue(['Klavier'])

		// Click save button
		const saveBtn = wrapper.findAll('.actions button').find(b => b.text().includes('Create Sub-Library'))
		expect(saveBtn).toBeDefined()
		await saveBtn!.trigger('click')

		expect(axios.post).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sheetmusic/api/libraries/1/sublibraries',
			{
				name: 'Piano Music',
				slug: 'piano-music',
				filters: {
					instrument: ['Klavier'],
				},
			},
			expect.any(Object),
		)
	})

	it('updates an existing sub-library via edit modal', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: {
				ocs: {
					data: {
						sublibraries: [mockSublibraries[0]],
					},
				},
			},
		} as any)

		vi.mocked(axios.put).mockResolvedValueOnce({
			data: {
				ocs: {
					data: {
						id: 10,
						libraryId: 1,
						name: 'Updated Organ Works',
						slug: 'updated-organ',
						filters: { instrument: ['Orgel', 'Klavier'] },
					},
				},
			},
		} as any)

		const wrapper = mount(SublibrariesView, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		await (new Promise(resolve => setTimeout(resolve, 0)))

		// Find and click edit button
		const editBtn = wrapper.find('button[title="Edit sub-library"]')
		expect(editBtn.exists()).toBe(true)
		await editBtn.trigger('click')

		// Dialog should be open
		expect(wrapper.find('[role="dialog"]').exists()).toBe(true)

		// Modify values input
		const inputs = wrapper.findAll('input')
		await inputs[0].setValue('Updated Organ Works')

		const selects = wrapper.findAll('select')
		await selects[1].setValue(['Orgel', 'Klavier'])

		// Click save button
		const saveBtn = wrapper.findAll('.actions button').find(b => b.text().includes('Save Changes'))
		expect(saveBtn).toBeDefined()
		await saveBtn!.trigger('click')

		expect(axios.put).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sheetmusic/api/libraries/1/sublibraries/10',
			{
				name: 'Updated Organ Works',
				slug: 'organ',
				filters: {
					instrument: expect.arrayContaining(['Orgel', 'Klavier']),
				},
			},
			expect.any(Object),
		)
	})

	it('deletes a sub-library via confirm modal', async () => {
		vi.mocked(axios.get).mockResolvedValue({
			data: {
				ocs: {
					data: {
						sublibraries: [mockSublibraries[0]],
					},
				},
			},
		} as any)

		vi.mocked(axios.delete).mockResolvedValueOnce({
			data: {
				ocs: {
					data: { success: true },
				},
			},
		} as any)

		const wrapper = mount(SublibrariesView, {
			props: {
				selectedLibrary: 1,
				libraries: mockLibraries,
			},
		})

		await (new Promise(resolve => setTimeout(resolve, 0)))

		// Find delete button on card
		const deleteBtn = wrapper.find('button[title="Delete sub-library"]')
		expect(deleteBtn.exists()).toBe(true)
		await deleteBtn.trigger('click')

		// Confirm delete in dialog
		const confirmBtn = wrapper.findAll('.actions button').find(b => b.text().includes('Delete Sub-Library'))
		expect(confirmBtn).toBeDefined()
		await confirmBtn!.trigger('click')

		expect(axios.delete).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/sheetmusic/api/libraries/1/sublibraries/10',
			expect.any(Object),
		)
	})
})
