import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongListDisplayMenu from '../../../src/components/songs/SongListDisplayMenu.vue'
import { DEFAULT_VISIBLE_COLUMNS } from '../../../src/types/song'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
}))

describe('SongListDisplayMenu.vue', () => {
	it('opens popover and emits viewMode, sortKey, sortOrder, and visibleColumns updates', async () => {
		const wrapper = mount(SongListDisplayMenu, {
			props: {
				viewMode: 'table',
				sortKey: 'title',
				sortOrder: 'asc',
				visibleColumns: [...DEFAULT_VISIBLE_COLUMNS],
			},
		})

		expect(wrapper.find('[data-test="song-list-display-popover"]').exists()).toBe(false)
		await wrapper.find('[data-test="song-list-display-trigger"]').trigger('click')
		expect(wrapper.find('[data-test="song-list-display-popover"]').exists()).toBe(true)

		const cardsBtn = wrapper.findAll('button').find((b) => b.text().includes('Cards'))
		await cardsBtn?.trigger('click')
		expect(wrapper.emitted('update:viewMode')?.[0]).toEqual(['cards'])

		const select = wrapper.find('select')
		await select.setValue('composer')
		expect(wrapper.emitted('update:sortKey')?.[0]).toEqual(['composer'])

		const sortDirBtn = wrapper.find('button[title="Ascending"]')
		await sortDirBtn.trigger('click')
		expect(wrapper.emitted('update:sortOrder')?.[0]).toEqual(['desc'])

		const checkboxes = wrapper.findAll('input[type="checkbox"]')
		await checkboxes[0].trigger('change')
		expect(wrapper.emitted('update:visibleColumns')).toBeTruthy()
	})
})
