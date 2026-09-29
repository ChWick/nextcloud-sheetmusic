import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongTableView from '../../../src/components/songs/SongTableView.vue'
import type { Song } from '../../../src/types/song'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
}))

describe('SongTableView.vue', () => {
	const songs: Song[] = [
		{
			id: 1,
			title: 'Symphony No. 5',
			composers: [{ id: 1, name: 'Beethoven' }],
			books: [{ id: 10, title: 'Classics Vol 1' }],
			collections: [{ id: 100, name: 'Symphonies' }],
			artists: [{ id: 5, name: 'Berlin Phil' }],
		},
		{
			id: 2,
			title: 'Air on the G String',
			composers: [{ id: 2, name: 'Bach' }],
		},
	]

	it('renders table headers and rows with formatted metadata', () => {
		const wrapper = mount(SongTableView, {
			props: { songs },
		})

		expect(wrapper.text()).toContain('Title')
		expect(wrapper.text()).toContain('Composer')
		expect(wrapper.text()).toContain('Book')
		expect(wrapper.text()).toContain('Collection')
		expect(wrapper.text()).toContain('Artist')

		expect(wrapper.text()).toContain('Symphony No. 5')
		expect(wrapper.text()).toContain('Beethoven')
		expect(wrapper.text()).toContain('Classics Vol 1')
		expect(wrapper.text()).toContain('Symphonies')
		expect(wrapper.text()).toContain('Berlin Phil')
	})

	it('emits view-song and edit-song events when buttons are clicked', async () => {
		const wrapper = mount(SongTableView, {
			props: { songs },
		})

		// Sorted by title asc: 'Air on the G String' (songs[1]) is first row
		const titleBtn = wrapper.find('button')
		await titleBtn.trigger('click')
		expect(wrapper.emitted('view-song')?.[0]).toEqual([songs[1]])

		const editBtn = wrapper.findAll('button').find((btn) => btn.text() === 'Edit')
		await editBtn?.trigger('click')
		expect(wrapper.emitted('edit-song')?.[0]).toEqual([songs[1]])
	})

	it('sorts rows when column headers are clicked', async () => {
		const wrapper = mount(SongTableView, {
			props: { songs },
		})

		// Initially sorted by title asc: Air on the G String, Symphony No. 5
		const initialRows = wrapper.findAll('tbody tr')
		expect(initialRows[0].text()).toContain('Air on the G String')

		// Click Title header to toggle desc
		const titleHeader = wrapper.findAll('th').find((th) => th.text().includes('Title'))
		await titleHeader?.trigger('click')

		const toggledRows = wrapper.findAll('tbody tr')
		expect(toggledRows[0].text()).toContain('Symphony No. 5')
	})

	it('emits filter-entity when metadata chip is clicked and applies active class', async () => {
		const isFilteredMock = vi.fn((type: string, id: number) => type === 'composers' && id === 1)
		const wrapper = mount(SongTableView, {
			props: { songs, isFiltered: isFilteredMock },
		})

		const beethovenBtn = wrapper.findAll('button').find((btn) => btn.text() === 'Beethoven')
		const bachBtn = wrapper.findAll('button').find((btn) => btn.text() === 'Bach')

		expect(beethovenBtn?.classes().some((c) => c.includes('activeChip'))).toBe(true)
		expect(bachBtn?.classes().some((c) => c.includes('activeChip'))).toBe(false)

		await beethovenBtn?.trigger('click')
		expect(wrapper.emitted('filter-entity')?.[0]).toEqual(['composers', { id: 1, name: 'Beethoven' }])
	})
})
