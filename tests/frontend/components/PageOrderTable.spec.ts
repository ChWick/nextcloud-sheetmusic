/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PageOrderTable from '../../../src/components/tools/PageOrderTable.vue'
import type { PageOrderAuditItem } from '../../../src/types/tools'

describe('PageOrderTable', () => {
	const mockItems: PageOrderAuditItem[] = [
		{
			id: 101,
			songId: 1,
			songTitle: 'Autumn Leaves',
			fileId: 101,
			fileName: 'autumn_leaves.pdf',
			path: '/Music/autumn_leaves.pdf',
			pageOrder: '1-1',
			pageCount: 3,
			actualPageCount: 3,
			suggestedPageOrder: '1-3',
			isStuckOneToOne: true,
			isCustom: false,
		},
		{
			id: 102,
			songId: 2,
			songTitle: 'Moon River',
			fileId: 102,
			fileName: 'moon_river.pdf',
			path: '/Music/moon_river.pdf',
			pageOrder: '1-2',
			pageCount: 2,
			actualPageCount: 2,
			suggestedPageOrder: '1-2',
			isStuckOneToOne: false,
			isCustom: false,
		},
	]

	it('renders table rows and badges correctly', () => {
		const wrapper = mount(PageOrderTable, {
			props: {
				items: mockItems,
				selectedIds: new Set(),
			},
			global: {
				stubs: {
					NcButton: true,
					NcCheckboxRadioSwitch: true,
				},
			},
		})

		expect(wrapper.find('[data-test="page-order-row-101"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="page-order-row-102"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="status-stuck"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="status-correct"]').exists()).toBe(true)
	})

	it('emits reset-item when clicking row reset button', async () => {
		const wrapper = mount(PageOrderTable, {
			props: {
				items: mockItems,
				selectedIds: new Set(),
			},
			global: {
				stubs: {
					NcButton: {
						template: '<button @click="$emit(\'click\')"><slot /></button>',
					},
					NcCheckboxRadioSwitch: true,
				},
			},
		})

		const resetBtn = wrapper.find('[data-test="reset-row-btn-101"]')
		await resetBtn.trigger('click')

		expect(wrapper.emitted('reset-item')?.[0]).toEqual([mockItems[0]])
	})

	it('renders empty state when items is empty', () => {
		const wrapper = mount(PageOrderTable, {
			props: {
				items: [],
				selectedIds: new Set(),
			},
			global: {
				stubs: {
					NcButton: true,
					NcCheckboxRadioSwitch: true,
				},
			},
		})

		expect(wrapper.find('[data-test="page-order-empty"]').exists()).toBe(true)
	})
})
