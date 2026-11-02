/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PageOrderProgressDialog from '../../../src/components/tools/PageOrderProgressDialog.vue'

describe('PageOrderProgressDialog', () => {
	it('renders progress bar and message when open', () => {
		const wrapper = mount(PageOrderProgressDialog, {
			props: {
				open: true,
				percent: 45,
				message: 'Resetting page 2 of 5…',
			},
			global: {
				stubs: {
					NcDialog: {
						template: '<div data-test="dialog"><slot /></div>',
					},
					NcProgressBar: true,
				},
			},
		})

		expect(wrapper.find('[data-test="page-order-progress"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('Resetting page 2 of 5…')
	})

	it('does not render when open is false', () => {
		const wrapper = mount(PageOrderProgressDialog, {
			props: {
				open: false,
				percent: 0,
				message: '',
			},
		})

		expect(wrapper.find('[data-test="page-order-progress"]').exists()).toBe(false)
	})
})
