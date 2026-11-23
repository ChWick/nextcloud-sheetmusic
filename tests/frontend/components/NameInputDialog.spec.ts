/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import NameInputDialog from '../../../src/components/NameInputDialog.vue'

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name'],
		emits: ['close'],
		template: '<div data-test="dialog" :data-title="name"><slot /><slot name="actions" /></div>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder'],
		emits: ['update:modelValue', 'keyup'],
		template: '<input data-test="name-input" :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" @keyup="$emit(\'keyup\', $event)" />',
	},
}

describe('NameInputDialog.vue', () => {
	it('does not render when closed and renders title, prompt, placeholder, and submit button when open', async () => {
		const wrapper = mount(NameInputDialog, {
			props: {
				open: false,
				title: 'New composer',
				prompt: 'Enter a name:',
				submitLabel: 'Create composer',
				placeholder: 'e.g. Bach',
				name: '',
				isSubmitting: false,
				error: '',
			},
			global: { stubs },
		})
		expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)

		await wrapper.setProps({ open: true, name: 'Mozart' })
		expect(wrapper.find('[data-test="dialog"]').attributes('data-title')).toBe('New composer')
		expect(wrapper.text()).toContain('Enter a name:')
		expect(wrapper.text()).toContain('Create composer')
		expect(wrapper.find('[data-test="name-input"]').attributes('placeholder')).toBe('e.g. Bach')
	})

	it('emits submit when valid and blocks submit when blank or submitting', async () => {
		const wrapper = mount(NameInputDialog, {
			props: {
				open: true,
				title: 'Rename setlist',
				prompt: 'Enter a new name:',
				submitLabel: 'Save',
				name: '   ',
				isSubmitting: false,
				error: '',
			},
			global: { stubs },
		})

		const submitBtn = wrapper.findAll('button').find((b) => b.text() === 'Save')!
		expect(submitBtn.attributes('disabled')).toBeDefined()

		await wrapper.setProps({ name: 'Updated Gig' })
		await submitBtn.trigger('click')
		expect(wrapper.emitted('submit')).toHaveLength(1)

		await wrapper.setProps({ isSubmitting: true })
		await submitBtn.trigger('click')
		expect(wrapper.emitted('submit')).toHaveLength(1)
	})

	it('renders error alert and emits close on cancel', async () => {
		const wrapper = mount(NameInputDialog, {
			props: {
				open: true,
				title: 'New setlist',
				prompt: 'Enter a name:',
				submitLabel: 'Create',
				name: 'Test',
				isSubmitting: false,
				error: 'Name already exists',
			},
			global: { stubs },
		})

		expect(wrapper.find('[role="alert"]').text()).toBe('Name already exists')
		const cancelBtn = wrapper.findAll('button').find((b) => b.text() === 'Cancel')!
		await cancelBtn.trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
