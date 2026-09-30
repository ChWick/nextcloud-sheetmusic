import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongColumnToggle from '../../../src/components/songs/SongColumnToggle.vue'
import { DEFAULT_VISIBLE_COLUMNS } from '../../../src/types/song'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
}))

describe('SongColumnToggle.vue', () => {
	it('renders column toggle dropdown menu and manages visibleColumns props', async () => {
		const wrapper = mount(SongColumnToggle, {
			props: { visibleColumns: DEFAULT_VISIBLE_COLUMNS },
		})

		const actions = wrapper.findComponent({ name: 'NcActions' })
		expect(actions.exists()).toBe(true)
		expect(actions.props('ariaLabel')).toBe('Select visible columns')
	})
})
