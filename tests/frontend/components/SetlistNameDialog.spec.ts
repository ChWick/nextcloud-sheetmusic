/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SetlistNameDialog from '../../../src/components/setlists/SetlistNameDialog.vue'

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
		template: '<input data-test="name-input" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @keyup="$emit(\'keyup\', $event)" />',
	},
}

describe('SetlistNameDialog.vue', () => {
	it('does not render when closed and renders title, prompt, and submit button when open', async () => {
		const wrapper = mount(SetlistNameDialog, {
			props: {
				open: false,
				title: 'New setlist',
				prompt: 'Enter a name:',
				submitLabel: 'Create setlist',
				name: '',
				isSubmitting: false,
				error: '',
			},
			global: { stubs },
		})
		expect(wrapper.find('[data-test="dialog"]').exists()).toBe(false)

		await wrapper.setProps({ open: true, name: 'Concert' })
		expect(wrapper.find('[data-test="dialog"]').attributes('data-title')).toBe('New setlist')
		expect(wrapper.text()).toContain('Enter a name:')
		expect(wrapper.text()).toContain('Create setlist')
	})

	it('emits update:name, submit when valid, and blocks submit when blank or submitting', async () => {
		const wrapper = mount(SetlistNameDialog, {
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
		const wrapper = mount(SetlistNameDialog, {
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
