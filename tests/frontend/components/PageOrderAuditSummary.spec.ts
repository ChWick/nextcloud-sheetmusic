/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PageOrderAuditSummary from '../../../src/components/tools/PageOrderAuditSummary.vue'

describe('PageOrderAuditSummary', () => {
	it('renders summary stats correctly', () => {
		const wrapper = mount(PageOrderAuditSummary, {
			props: {
				totalFiles: 42,
				stuckCount: 5,
				customCount: 3,
				normalCount: 34,
			},
		})

		expect(wrapper.find('[data-test="total-files-count"]').text()).toBe('42')
		expect(wrapper.find('[data-test="stuck-count"]').text()).toBe('5')
		expect(wrapper.find('[data-test="custom-count"]').text()).toBe('3')
		expect(wrapper.find('[data-test="normal-count"]').text()).toBe('34')
	})

	it('renders zero stats cleanly without errors', () => {
		const wrapper = mount(PageOrderAuditSummary, {
			props: {
				totalFiles: 0,
				stuckCount: 0,
				customCount: 0,
				normalCount: 0,
			},
		})

		expect(wrapper.find('[data-test="stuck-count"]').text()).toBe('0')
	})
})
