import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SublibraryModal from '../../../../src/components/sublibraries/SublibraryModal.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: vi.fn((_app: string, text: string) => text),
}))

vi.mock('@nextcloud/vue/components/NcDialog', () => ({
	default: {
		name: 'NcDialog',
		props: ['name', 'size'],
		emits: ['close'],
		template: '<div class="nc-dialog" data-test="dialog"><slot /><div class="actions"><slot name="actions" /></div></div>',
	},
}))

vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder'],
		emits: ['update:modelValue'],
		template: '<input :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'label', 'reduce', 'multiple'],
		emits: ['update:modelValue'],
		template: `
			<select :value="modelValue" :multiple="multiple" @change="$emit('update:modelValue', multiple ? Array.from($event.target.selectedOptions).map(o => o.value) : $event.target.value)">
				<option v-for="opt in options" :key="opt.id || opt" :value="reduce ? reduce(opt) : (opt.id || opt)">
					{{ opt.label || opt.name || opt }}
				</option>
			</select>
		`,
	},
}))

describe('SublibraryModal.vue', () => {
	const defaultProps = {
		isOpen: true,
		sublibrary: null,
		libraryFolderPath: '/SheetMusic',
		isSaving: false,
		formError: '',
		instruments: [{ id: 1, name: 'Orgel' }, { id: 2, name: 'Klavier' }],
		composers: [{ id: 1, name: 'J.S. Bach' }],
		genres: [],
		collections: [],
		artists: [],
		books: [],
	}

	it('renders create modal and updates resulting path preview dynamically', async () => {
		const wrapper = mount(SublibraryModal, {
			props: defaultProps,
		})

		const inputs = wrapper.findAll('input')
		expect(inputs.length).toBeGreaterThanOrEqual(2)

		await inputs[0].setValue('Organ Works')
		expect(wrapper.text()).toContain('/SheetMusic/SubLibraries/organ-works')
	})

	it('validates required name and filter values on save', async () => {
		const wrapper = mount(SublibraryModal, {
			props: defaultProps,
		})

		const saveBtn = wrapper.findAll('.actions button').find(b => b.text().includes('Create Sub-Library'))
		expect(saveBtn).toBeDefined()
		await saveBtn!.trigger('click')

		expect(wrapper.text()).toContain('Sub-library name is required.')
		expect(wrapper.emitted('save')).toBeUndefined()
	})

	it('emits save with clean payload when form is valid', async () => {
		const wrapper = mount(SublibraryModal, {
			props: defaultProps,
		})

		const inputs = wrapper.findAll('input')
		await inputs[0].setValue('Organ Works')

		const selects = wrapper.findAll('select')
		await selects[1].setValue(['Orgel'])

		const saveBtn = wrapper.findAll('.actions button').find(b => b.text().includes('Create Sub-Library'))
		await saveBtn!.trigger('click')

		expect(wrapper.emitted('save')?.[0]).toEqual([{
			name: 'Organ Works',
			slug: 'organ-works',
			filters: { instrument: ['Orgel'] },
		}])
	})
})
