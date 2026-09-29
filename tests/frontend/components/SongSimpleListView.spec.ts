import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongSimpleListView from '../../../src/components/songs/SongSimpleListView.vue'
import type { Song } from '../../../src/types/song'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
}))

describe('SongSimpleListView.vue', () => {
	const songs: Song[] = [
		{
			id: 1,
			title: 'List Song Title',
			composers: [{ id: 10, name: 'Chopin' }],
			books: [{ id: 100, title: 'Nocturnes' }],
		},
	]

	it('renders list items with song metadata and action buttons', () => {
		const wrapper = mount(SongSimpleListView, {
			props: { songs },
		})

		expect(wrapper.text()).toContain('List Song Title')
		expect(wrapper.text()).toContain('Chopin')
		expect(wrapper.text()).toContain('Nocturnes')
	})

	it('emits view-song and edit-song events', async () => {
		const wrapper = mount(SongSimpleListView, {
			props: { songs },
		})

		const titleBtn = wrapper.find('button')
		await titleBtn.trigger('click')
		expect(wrapper.emitted('view-song')?.[0]).toEqual([songs[0]])
	})

	it('emits filter-entity when metadata chip is clicked and applies active class', async () => {
		const isFilteredMock = vi.fn((type: string, id: number) => type === 'composers' && id === 10)
		const wrapper = mount(SongSimpleListView, {
			props: { songs, isFiltered: isFilteredMock },
		})

		const chopinBtn = wrapper.findAll('button').find((btn) => btn.text() === 'Chopin')
		expect(chopinBtn?.classes().some((c) => c.includes('activeChip'))).toBe(true)

		await chopinBtn?.trigger('click')
		expect(wrapper.emitted('filter-entity')?.[0]).toEqual(['composers', { id: 10, name: 'Chopin' }])
	})

	it('emits toggle-select-song when checkbox is clicked', async () => {
		const wrapper = mount(SongSimpleListView, {
			props: { songs, selectedSongIds: [1] },
		})

		const checkbox = wrapper.find('input[type="checkbox"]')
		expect(checkbox.exists()).toBe(true)
		await checkbox.trigger('change')
		expect(wrapper.emitted('toggle-select-song')?.[0]).toEqual([1])
	})

	it('emits toggle-select-song on long press touch event', async () => {
		vi.useFakeTimers()
		const wrapper = mount(SongSimpleListView, {
			props: { songs },
		})

		const row = wrapper.find('li')
		await row.trigger('touchstart', {
			touches: [{ clientX: 50, clientY: 50 }],
		})

		vi.advanceTimersByTime(500)
		expect(wrapper.emitted('toggle-select-song')?.[0]).toEqual([1])
		vi.useRealTimers()
	})
})
