/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AppNavigationSidebar from '../../../src/components/AppNavigationSidebar.vue'
import type { Library } from '../../../src/types/library'

const stubs = {
	NcAppNavigation: {
		template: '<nav><slot /><div data-test="footer"><slot name="footer" /></div></nav>',
	},
	NcAppNavigationItem: {
		props: ['name', 'active', 'to'],
		emits: ['click'],
		template: '<div class="nav-item" :data-name="name" :data-active="String(Boolean(active))" @click="$emit(\'click\')"><slot /></div>',
	},
	NcActions: {
		template: '<div class="nc-actions"><slot /></div>',
	},
	NcActionButton: {
		emits: ['click'],
		template: '<button type="button" class="nc-action-btn" @click="$emit(\'click\')"><slot /></button>',
	},
}

function makeLibrary(id: number, path: string, isShared = false): Library {
	return { id, path, isShared } as Library
}

describe('AppNavigationSidebar.vue', () => {
	it('renders library selector, emits select-library, and highlights active view', async () => {
		const libraries = [makeLibrary(1, '/Music'), makeLibrary(2, '/Choir')]
		const wrapper = mount(AppNavigationSidebar, {
			props: {
				libraries,
				selectedLibrary: 1,
				selectedView: 'setlists',
				currentLibrary: libraries[0],
				isPublicShare: false,
				selectedLibraryPath: '/Music',
				formatLibraryOption: (lib) => lib.path,
			},
			global: { stubs },
		})

		const select = wrapper.find('[data-test="library-selector"]')
		await select.setValue('2')
		expect(wrapper.emitted('select-library')?.[0]).toEqual([2])

		expect(wrapper.find('.nav-item[data-name="Setlists"]').attributes('data-active')).toBe('true')
		expect(wrapper.find('.nav-item[data-name="Songs"]').attributes('data-active')).toBe('false')
		expect(wrapper.find('.nav-item[data-name="Shares"]').exists()).toBe(true)
		expect(wrapper.find('.nav-item[data-name="Trash"]').exists()).toBe(true)
	})

	it('hides admin/private nav items and footer in public share mode', () => {
		const wrapper = mount(AppNavigationSidebar, {
			props: {
				libraries: [],
				selectedLibrary: 1,
				selectedView: 'songs',
				currentLibrary: null,
				isPublicShare: true,
				publicLibraryPath: '/SharedScores',
				selectedLibraryPath: '/SharedScores',
				formatLibraryOption: (lib) => lib.path,
			},
			global: { stubs },
		})

		expect(wrapper.find('[data-test="library-selector"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('/SharedScores')
		expect(wrapper.find('.nav-item[data-name="Shares"]').exists()).toBe(false)
		expect(wrapper.find('.nav-item[data-name="Trash"]').exists()).toBe(false)
		expect(wrapper.find('.nav-item[data-name="Offline mode"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="footer"]').text()).toBe('')
	})

	it('emits footer actions for add library, import library, share, and settings', async () => {
		const lib = makeLibrary(1, '/Music', false)
		const wrapper = mount(AppNavigationSidebar, {
			props: {
				libraries: [lib],
				selectedLibrary: 1,
				selectedView: 'songs',
				currentLibrary: lib,
				isPublicShare: false,
				selectedLibraryPath: '/Music',
				formatLibraryOption: (l) => l.path,
			},
			global: { stubs },
		})

		const actionButtons = wrapper.findAll('.nc-action-btn')
		await actionButtons[0].trigger('click')
		await actionButtons[1].trigger('click')
		expect(wrapper.emitted('open-add-library')).toHaveLength(1)
		expect(wrapper.emitted('open-import-library')).toHaveLength(1)

		await wrapper.find('button[aria-label="Share library"]').trigger('click')
		await wrapper.find('button[aria-label="Library Settings"]').trigger('click')
		expect(wrapper.emitted('open-share')).toHaveLength(1)
		expect(wrapper.emitted('open-settings')).toHaveLength(1)
	})
})
