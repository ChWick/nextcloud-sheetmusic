import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongCardView from '../../../src/components/songs/SongCardView.vue'
import type { Song } from '../../../src/types/song'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
}))

describe('SongCardView.vue', () => {
	const songs: Song[] = [
		{
			id: 1,
			title: 'Card Song Title',
			composers: [{ id: 1, name: 'Mozart' }],
			collections: [{ id: 2, name: 'Jazz' }],
		},
	]

	it('renders card grid with song metadata', () => {
		const wrapper = mount(SongCardView, {
			props: { songs },
		})

		expect(wrapper.text()).toContain('Card Song Title')
		expect(wrapper.text()).toContain('Mozart')
		expect(wrapper.text()).toContain('Jazz')
	})

	it('emits view-song and edit-song events', async () => {
		const wrapper = mount(SongCardView, {
			props: { songs },
		})

		const titleBtn = wrapper.find('button')
		await titleBtn.trigger('click')
		expect(wrapper.emitted('view-song')?.[0]).toEqual([songs[0]])
	})

	it('emits filter-entity when metadata chip is clicked in card view', async () => {
		const isFilteredMock = vi.fn((type: string, id: number) => type === 'composers' && id === 1)
		const wrapper = mount(SongCardView, {
			props: { songs, isFiltered: isFilteredMock },
		})

		const mozartBtn = wrapper.findAll('button').find((btn) => btn.text() === 'Mozart')
		expect(mozartBtn?.classes().some((c) => c.includes('activeChip'))).toBe(true)

		await mozartBtn?.trigger('click')
		expect(wrapper.emitted('filter-entity')?.[0]).toEqual(['composers', { id: 1, name: 'Mozart' }])
	})
})
