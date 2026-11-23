/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ProgressDialog from '../../../src/components/ProgressDialog.vue'

describe('ProgressDialog.vue', () => {
	it('renders title, progress bar, and message when open', () => {
		const wrapper = mount(ProgressDialog, {
			props: {
				open: true,
				title: 'Resetting Page Ordering…',
				percent: 45,
				message: 'Resetting page 2 of 5…',
				testId: 'page-order-progress',
			},
			global: {
				stubs: {
					NcDialog: {
						props: ['name'],
						template: '<div data-test="dialog" :data-title="name"><slot /></div>',
					},
					NcProgressBar: true,
				},
			},
		})

		expect(wrapper.find('[data-test="dialog"]').attributes('data-title')).toBe('Resetting Page Ordering…')
		expect(wrapper.find('[data-test="page-order-progress"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Resetting page 2 of 5…')
	})

	it('falls back to fallbackMessage when message is empty', () => {
		const wrapper = mount(ProgressDialog, {
			props: {
				open: true,
				title: 'Fixing issues…',
				percent: 0,
				message: '',
				fallbackMessage: 'Fixing integrity issues in library…',
			},
			global: {
				stubs: {
					NcDialog: { template: '<div><slot /></div>' },
					NcProgressBar: true,
				},
			},
		})

		expect(wrapper.text()).toContain('Fixing integrity issues in library…')
	})

	it('does not render when open is false', () => {
		const wrapper = mount(ProgressDialog, {
			props: {
				open: false,
				title: 'Fixing issues…',
				percent: 0,
				message: '',
				testId: 'progress-box',
			},
		})

		expect(wrapper.find('[data-test="progress-box"]').exists()).toBe(false)
	})
})
