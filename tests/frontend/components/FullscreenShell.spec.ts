/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FullscreenShell from '../../../src/components/FullscreenShell.vue'

describe('FullscreenShell.vue', () => {
	it('renders slot content and toggles fullscreenContainer class based on active prop', async () => {
		const wrapper = mount(FullscreenShell, {
			props: { active: false },
			slots: { default: '<div data-test="inner">Content</div>' },
		})

		expect(wrapper.find('[data-test="inner"]').text()).toBe('Content')
		const inactiveClasses = wrapper.classes()
		expect(inactiveClasses).toHaveLength(1)

		await wrapper.setProps({ active: true })
		const activeClasses = wrapper.classes()
		expect(activeClasses.length).toBe(2)
	})
})
