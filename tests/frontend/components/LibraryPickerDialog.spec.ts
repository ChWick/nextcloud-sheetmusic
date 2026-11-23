/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import LibraryPickerDialog from '../../../src/components/LibraryPickerDialog.vue'

const stubs = {
	NcDialog: {
		props: ['name'],
		emits: ['close'],
		template: '<div data-test="dialog" :data-title="name"><slot /><slot name="actions" /></div>',
	},
	NcProgressBar: {
		props: ['value', 'error'],
		template: '<div data-test="progress-bar" :data-value="value" :data-error="String(Boolean(error))" />',
	},
}

describe('LibraryPickerDialog.vue', () => {
	it('renders picker button, disables submit without selectedPath, and emits pick/submit', async () => {
		const wrapper = mount(LibraryPickerDialog, {
			props: {
				open: true,
				title: 'Add Sheet Music Library',
				description: 'Select a folder',
				pickerLabel: 'Choose folder from Nextcloud',
				submitLabel: 'Add library',
				isSubmitting: false,
			},
			global: { stubs },
		})

		expect(wrapper.find('[data-test="dialog"]').attributes('data-title')).toBe('Add Sheet Music Library')
		expect(wrapper.find('[data-test="library-picker-button"]').text()).toBe('Choose folder from Nextcloud')
		expect(wrapper.find('[data-test="library-submit-button"]').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-test="library-picker-button"]').trigger('click')
		expect(wrapper.emitted('pick')).toHaveLength(1)

		await wrapper.setProps({ selectedPath: '/SheetMusic' })
		expect(wrapper.find('[data-test="library-picker-button"]').text()).toBe('/SheetMusic')
		await wrapper.find('[data-test="library-submit-button"]').trigger('click')
		expect(wrapper.emitted('submit')).toHaveLength(1)
	})

	it('shows progress bar and blocks close while submitting, and renders error alert', async () => {
		const wrapper = mount(LibraryPickerDialog, {
			props: {
				open: true,
				title: 'Import MobileSheets Library',
				description: 'Select a db file',
				selectedPath: '/mobilesheets.db',
				pickerLabel: 'Choose db',
				submitLabel: 'Import library',
				isSubmitting: true,
				progress: { percent: 60, message: 'Importing songs…', status: 'running' },
				error: 'Something went wrong',
			},
			global: { stubs },
		})

		expect(wrapper.find('[data-test="progress-bar"]').attributes('data-value')).toBe('60')
		expect(wrapper.text()).toContain('Importing songs…')
		expect(wrapper.find('[role="alert"]').text()).toBe('Something went wrong')

		const cancelBtn = wrapper.findAll('button').find((b) => b.text() === 'Cancel')!
		await cancelBtn.trigger('click')
		expect(wrapper.emitted('close')).toBeUndefined()

		await wrapper.setProps({ isSubmitting: false })
		await cancelBtn.trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
