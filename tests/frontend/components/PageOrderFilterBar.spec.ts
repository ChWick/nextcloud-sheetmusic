/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PageOrderFilterBar from '../../../src/components/tools/PageOrderFilterBar.vue'

describe('PageOrderFilterBar', () => {
	it('emits update:filterMode when clicking filter tab buttons', async () => {
		const wrapper = mount(PageOrderFilterBar, {
			props: {
				filterMode: 'stuck',
				searchQuery: '',
				stuckCount: 2,
				totalFiles: 10,
			},
			global: {
				stubs: {
					NcButton: true,
					NcTextField: true,
				},
			},
		})

		const allBtn = wrapper.find('[data-test="filter-all"]')
		await allBtn.trigger('click')

		expect(wrapper.emitted('update:filterMode')?.[0]).toEqual(['all'])

		const customBtn = wrapper.find('[data-test="filter-custom"]')
		await customBtn.trigger('click')

		expect(wrapper.emitted('update:filterMode')?.[1]).toEqual(['custom'])
	})

	it('emits refresh when refresh button is clicked', async () => {
		const wrapper = mount(PageOrderFilterBar, {
			props: {
				filterMode: 'stuck',
				searchQuery: '',
				stuckCount: 0,
				totalFiles: 5,
			},
			global: {
				stubs: {
					NcButton: {
						template: '<button @click="$emit(\'click\')"><slot /></button>',
					},
					NcTextField: true,
				},
			},
		})

		const refreshBtn = wrapper.find('[data-test="refresh-audit-btn"]')
		await refreshBtn.trigger('click')

		expect(wrapper.emitted('refresh')).toBeTruthy()
	})
})
