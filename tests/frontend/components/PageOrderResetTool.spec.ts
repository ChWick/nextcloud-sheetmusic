/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PageOrderResetTool from '../../../src/components/tools/PageOrderResetTool.vue'
import { apiGet, apiPost } from '../../../src/api/client'

vi.mock('../../../src/api/client', () => ({
	apiGet: vi.fn(),
	apiPost: vi.fn(),
}))

vi.mock('vue-router', () => ({
	useRouter: () => ({
		push: vi.fn(),
	}),
}))

describe('PageOrderResetTool', () => {
	const mockAuditData = {
		totalFiles: 3,
		stuckCount: 1,
		customCount: 1,
		normalCount: 1,
		items: [
			{
				id: 1,
				songId: 10,
				songTitle: 'Prelude in C',
				fileId: 1,
				fileName: 'prelude.pdf',
				path: '/Music/prelude.pdf',
				pageOrder: '1-1',
				pageCount: 3,
				actualPageCount: 3,
				suggestedPageOrder: '1-3',
				isStuckOneToOne: true,
				isCustom: false,
			},
			{
				id: 2,
				songId: 20,
				songTitle: 'Fugue in G',
				fileId: 2,
				fileName: 'fugue.pdf',
				path: '/Music/fugue.pdf',
				pageOrder: '1,3,2',
				pageCount: 3,
				actualPageCount: 3,
				suggestedPageOrder: '1-3',
				isStuckOneToOne: false,
				isCustom: true,
			},
			{
				id: 3,
				songId: 30,
				songTitle: 'Air on G',
				fileId: 3,
				fileName: 'air.pdf',
				path: '/Music/air.pdf',
				pageOrder: '1-2',
				pageCount: 2,
				actualPageCount: 2,
				suggestedPageOrder: '1-2',
				isStuckOneToOne: false,
				isCustom: false,
			},
		],
	}

	beforeEach(() => {
		vi.clearAllMocks()
		vi.mocked(apiGet).mockResolvedValue({
			data: { ocs: { data: mockAuditData } },
		} as any)
		vi.mocked(apiPost).mockResolvedValue({
			data: { ocs: { data: { success: true, resetCount: 1 } } },
		} as any)
	})

	it('fetches audit on mount and shows items', async () => {
		const wrapper = mount(PageOrderResetTool, {
			props: { selectedLibrary: 1 },
			global: {
				stubs: {
					NcButton: {
						template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
						props: ['disabled'],
					},
					NcLoadingIcon: true,
					NcDialog: true,
					NcProgressBar: true,
					NcCheckboxRadioSwitch: true,
					NcTextField: true,
				},
			},
		})

		await vi.waitFor(() => {
			expect(apiGet).toHaveBeenCalledWith('/api/libraries/1/tools/page-order-audit')
		})

		expect(wrapper.find('[data-test="page-order-reset-tool"]').exists()).toBe(true)
		// By default filterMode is 'stuck', so 1 stuck item is visible
		expect(wrapper.find('[data-test="page-order-row-1"]').exists()).toBe(true)
	})

	it('batch resets filtered items when clicking reset all filtered', async () => {
		const wrapper = mount(PageOrderResetTool, {
			props: { selectedLibrary: 1 },
			global: {
				stubs: {
					NcButton: {
						template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
						props: ['disabled'],
					},
					NcLoadingIcon: true,
					NcDialog: true,
					NcProgressBar: true,
					NcCheckboxRadioSwitch: true,
					NcTextField: true,
				},
			},
		})

		await vi.waitFor(() => {
			expect(apiGet).toHaveBeenCalled()
		})

		const resetAllBtn = wrapper.find('[data-test="reset-all-filtered-btn"]')
		await resetAllBtn.trigger('click')

		expect(apiPost).toHaveBeenCalledWith(
			'/api/libraries/1/tools/reset-page-orders',
			expect.objectContaining({
				fileIds: [1],
				mode: 'stuck',
			}),
		)
	})
})
