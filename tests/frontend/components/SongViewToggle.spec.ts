import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongViewToggle from '../../../src/components/songs/SongViewToggle.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
}))

describe('SongViewToggle.vue', () => {
	it('renders view options and emits update event when changed', async () => {
		const wrapper = mount(SongViewToggle, {
			props: { viewMode: 'table' },
		})

		expect(wrapper.text()).toContain('View:')
		expect(wrapper.findComponent({ name: 'NcRadioGroup' }).exists()).toBe(true)
	})
})
