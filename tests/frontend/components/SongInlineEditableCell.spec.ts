import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongInlineEditableCell from '../../../src/components/songs/SongInlineEditableCell.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
}))

describe('SongInlineEditableCell.vue', () => {
	it('renders text mode displayText and starts editing on click or dblclick', async () => {
		const wrapper = mount(SongInlineEditableCell, {
			props: {
				field: 'title',
				value: 'My Song',
				displayText: 'My Song',
				ariaLabel: 'Song title',
				editTitle: 'Edit title inline',
			},
		})

		expect(wrapper.text()).toContain('My Song')
		const triggerBtn = wrapper.find('[data-test="inline-edit-title-btn"]')
		expect(triggerBtn.exists()).toBe(true)

		await triggerBtn.trigger('click')
		const input = wrapper.find('[data-test="inline-edit-input"]')
		expect(input.exists()).toBe(true)
		expect((input.element as HTMLInputElement).value).toBe('My Song')

		await input.setValue('Renamed Song')
		await wrapper.find('[data-test="inline-edit-save"]').trigger('click')

		expect(wrapper.emitted('save')?.[0]).toEqual(['Renamed Song'])
		expect(wrapper.find('[data-test="inline-edit-input"]').exists()).toBe(false)
	})

	it('supports cancelling text edit via Escape without emitting save', async () => {
		const wrapper = mount(SongInlineEditableCell, {
			props: {
				field: 'title',
				value: 'Initial',
				ariaLabel: 'Song title',
				editTitle: 'Edit title inline',
			},
		})

		await wrapper.trigger('dblclick')
		const input = wrapper.find('[data-test="inline-edit-input"]')
		await input.setValue('Changed')
		await input.trigger('keydown.esc')

		expect(wrapper.emitted('save')).toBeFalsy()
		expect(wrapper.find('[data-test="inline-edit-input"]').exists()).toBe(false)
	})

	it('supports select mode for ratings', async () => {
		const wrapper = mount(SongInlineEditableCell, {
			props: {
				field: 'stars',
				mode: 'select',
				value: 3,
				options: [
					{ value: 0, label: 'Unrated' },
					{ value: 3, label: '3 Stars' },
					{ value: 5, label: '5 Stars' },
				],
				ariaLabel: 'Rating',
				editTitle: 'Edit rating inline',
			},
		})

		const triggerBtn = wrapper.find('[data-test="inline-edit-stars-btn"]')
		await triggerBtn.trigger('click')

		const select = wrapper.find('[data-test="inline-edit-select"]')
		expect(select.exists()).toBe(true)
		expect((select.element as HTMLSelectElement).value).toBe('3')

		await select.setValue('5')
		expect(wrapper.emitted('save')?.[0]).toEqual([5])
	})

	it('supports entity mode with proposals, adding existing and creating new tags', async () => {
		const composers = [
			{ id: 1, name: 'Beethoven' },
			{ id: 2, name: 'Bach' },
		]
		const createEntityTag = vi.fn((name: string) => {
			const existing = composers.find((c) => c.name.toLowerCase() === name.toLowerCase())
			return existing ?? { name }
		})

		const wrapper = mount(SongInlineEditableCell, {
			props: {
				field: 'composer',
				mode: 'entity',
				value: 'Bach',
				ariaLabel: 'Composer',
				editTitle: 'Edit composer inline',
				entityOptions: composers,
				entityLabelKey: 'name',
				initialEntities: [{ id: 2, name: 'Bach' }],
				createEntityTag,
			},
		})

		const triggerBtn = wrapper.find('[data-test="inline-edit-composer-btn"]')
		await triggerBtn.trigger('click')

		const selectComponent = wrapper.findComponent({ name: 'NcSelect' })
		expect(selectComponent.exists()).toBe(true)
		expect(selectComponent.props('options')).toEqual(composers)

		// Create new tag
		const created = selectComponent.props('createOption')('Chopin')
		expect(created).toEqual({ name: 'Chopin' })

		// Existing proposal matched
		const beethoven = selectComponent.props('createOption')('Beethoven')
		expect(beethoven).toEqual({ id: 1, name: 'Beethoven' })

		// Update selection to Bach, Beethoven, Chopin
		await selectComponent.vm.$emit('update:modelValue', [
			{ id: 2, name: 'Bach' },
			beethoven,
			created,
		])

		// Save edit
		const saveBtn = wrapper.find('[data-test="inline-edit-save"]')
		await saveBtn.trigger('click')

		expect(wrapper.emitted('save')?.[0]).toEqual([[
			{ id: 2, name: 'Bach' },
			{ id: 1, name: 'Beethoven' },
			{ name: 'Chopin' },
		]])
	})

	it('does not emit save if entity selection is unchanged', async () => {
		const composers = [{ id: 1, name: 'Bach' }]
		const wrapper = mount(SongInlineEditableCell, {
			props: {
				field: 'composer',
				mode: 'entity',
				value: 'Bach',
				ariaLabel: 'Composer',
				editTitle: 'Edit composer inline',
				entityOptions: composers,
				entityLabelKey: 'name',
				initialEntities: [{ id: 1, name: 'Bach' }],
			},
		})

		await wrapper.find('[data-test="inline-edit-composer-btn"]').trigger('click')
		// Click save without changing anything
		await wrapper.find('[data-test="inline-edit-save"]').trigger('click')

		expect(wrapper.emitted('save')).toBeFalsy()
		expect(wrapper.find('[data-test="inline-edit-entity-select"]').exists()).toBe(false)
	})

	it('cancels entity edit when cancel button is clicked', async () => {
		const composers = [{ id: 1, name: 'Bach' }, { id: 2, name: 'Beethoven' }]
		const wrapper = mount(SongInlineEditableCell, {
			props: {
				field: 'composer',
				mode: 'entity',
				value: 'Bach',
				ariaLabel: 'Composer',
				editTitle: 'Edit composer inline',
				entityOptions: composers,
				entityLabelKey: 'name',
				initialEntities: [{ id: 1, name: 'Bach' }],
			},
		})

		await wrapper.find('[data-test="inline-edit-composer-btn"]').trigger('click')
		const selectComponent = wrapper.findComponent({ name: 'NcSelect' })
		await selectComponent.vm.$emit('update:modelValue', [composers[0], composers[1]])

		// Click cancel
		await wrapper.find('[data-test="inline-edit-cancel"]').trigger('click')

		expect(wrapper.emitted('save')).toBeFalsy()
		expect(wrapper.find('[data-test="inline-edit-entity-select"]').exists()).toBe(false)
	})
})
