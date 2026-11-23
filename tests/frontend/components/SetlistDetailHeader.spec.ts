/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SetlistDetailHeader from '../../../src/components/setlists/SetlistDetailHeader.vue'

describe('SetlistDetailHeader.vue', () => {
	it('renders title, item count, optional duration, and emits play and add-item', async () => {
		const wrapper = mount(SetlistDetailHeader, {
			props: {
				name: 'Autumn Concert',
				itemCount: 4,
				totalDurationFormatted: '18:30',
				canWrite: true,
			},
		})

		expect(wrapper.text()).toContain('Autumn Concert')
		expect(wrapper.text()).toContain('4 items')
		expect(wrapper.text()).toContain('18:30')

		const playBtn = wrapper.find('button[aria-label="Play Setlist"]')
		const addBtn = wrapper.find('button[aria-label="Add Song / Bookmark"]')
		expect(playBtn.exists()).toBe(true)
		expect(addBtn.exists()).toBe(true)

		await playBtn.trigger('click')
		await addBtn.trigger('click')
		expect(wrapper.emitted('play')).toHaveLength(1)
		expect(wrapper.emitted('add-item')).toHaveLength(1)
	})

	it('hides Play button when itemCount is 0 and hides Add button when canWrite is false', () => {
		const wrapper = mount(SetlistDetailHeader, {
			props: {
				name: 'Empty Setlist',
				itemCount: 0,
				canWrite: false,
			},
		})

		expect(wrapper.find('button[aria-label="Play Setlist"]').exists()).toBe(false)
		expect(wrapper.find('button[aria-label="Add Song / Bookmark"]').exists()).toBe(false)
	})

	it('renders offline button when offlineSupported is true and emits toggle-offline', async () => {
		const wrapper = mount(SetlistDetailHeader, {
			props: {
				name: 'Gig',
				itemCount: 2,
				offlineSupported: true,
				isOffline: false,
				isSyncingOffline: false,
			},
		})

		const offlineBtn = wrapper.find('button[aria-pressed="false"]')
		expect(offlineBtn.exists()).toBe(true)
		await offlineBtn.trigger('click')
		expect(wrapper.emitted('toggle-offline')).toHaveLength(1)
	})
})
