import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongViewToggle from '../../../src/components/songs/SongViewToggle.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
}))

describe('SongViewToggle.vue', () => {
	it('renders view options dropdown menu and manages viewMode props', async () => {
		const wrapper = mount(SongViewToggle, {
			props: { viewMode: 'table' },
		})

		const actions = wrapper.findComponent({ name: 'NcActions' })
		expect(actions.exists()).toBe(true)
		expect(actions.props('ariaLabel')).toBe('Table view')
	})
})
