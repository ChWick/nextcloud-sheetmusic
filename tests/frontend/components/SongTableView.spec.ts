import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongTableView from '../../../src/components/songs/SongTableView.vue'
import type { Song } from '../../../src/types/song'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app: string, text: string) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
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
			genres: [{ id: 7, name: 'Classical' }],
			keys: [{ id: 4, name: 'C Minor' }],
			customGroups: [{ id: 9, name: 'Main Choir' }],
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
		expect(wrapper.text()).toContain('Genre')
		expect(wrapper.text()).toContain('Key')
		expect(wrapper.text()).toContain('Custom Group')

		expect(wrapper.text()).toContain('Symphony No. 5')
		expect(wrapper.text()).toContain('Beethoven')
		expect(wrapper.text()).toContain('Classics Vol 1')
		expect(wrapper.text()).toContain('Symphonies')
		expect(wrapper.text()).toContain('Berlin Phil')
		expect(wrapper.text()).toContain('Classical')
		expect(wrapper.text()).toContain('C Minor')
		expect(wrapper.text()).toContain('Main Choir')
	})

	it('emits view-song and edit-song events when buttons are clicked', async () => {
		const wrapper = mount(SongTableView, {
			props: { songs },
		})

		// Sorted by title asc: 'Air on the G String' (songs[1]) is first row
		const row = wrapper.findAll('tbody tr')[0]
		await row.trigger('click')
		expect(wrapper.emitted('view-song')?.[0]).toEqual([songs[1]])

		const editBtn = wrapper.findAll('button').find((btn) => btn.attributes('title') === 'Edit song' || btn.attributes('aria-label') === 'Edit song')
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

	it('supports inline editing of song title via inline edit button or double-click and emits inline-update-song', async () => {
		const wrapper = mount(SongTableView, {
			props: { songs },
		})

		// First row is Air on the G String (songs[1])
		const firstRow = wrapper.findAll('tbody tr')[0]
		const inlineBtn = firstRow.find('[data-test="inline-edit-title-btn"]')
		expect(inlineBtn.exists()).toBe(true)

		await inlineBtn.trigger('click')
		expect(wrapper.emitted('view-song')).toBeFalsy()

		const input = firstRow.find('[data-test="inline-edit-input"]')
		expect(input.exists()).toBe(true)
		expect((input.element as HTMLInputElement).value).toBe('Air on the G String')

		await input.setValue('Air on the G String (BWV 1068)')
		await input.trigger('keydown.enter')

		expect(wrapper.emitted('inline-update-song')?.[0]).toEqual([
			songs[1],
			{ title: 'Air on the G String (BWV 1068)' },
		])
		expect(firstRow.find('[data-test="inline-edit-input"]').exists()).toBe(false)
	})

	it('supports cancelling inline edit with Escape or cancel button without emitting inline-update-song', async () => {
		const wrapper = mount(SongTableView, {
			props: { songs },
		})

		const secondRow = wrapper.findAll('tbody tr')[1]
		const titleCell = secondRow.findAll('td')[1]
		await titleCell.trigger('dblclick')

		const input = secondRow.find('[data-test="inline-edit-input"]')
		expect(input.exists()).toBe(true)
		await input.setValue('Changed Title')
		await input.trigger('keydown.esc')

		expect(wrapper.emitted('inline-update-song')).toBeFalsy()
		expect(secondRow.find('[data-test="inline-edit-input"]').exists()).toBe(false)
	})

	it('supports inline editing of scalar fields such as opus and stars', async () => {
		const wrapper = mount(SongTableView, {
			props: { songs },
		})

		const firstRow = wrapper.findAll('tbody tr')[0]
		const opusBtn = firstRow.find('[data-test="inline-edit-opus-btn"]')
		await opusBtn.trigger('click')

		const opusInput = firstRow.find('[data-test="inline-edit-input"]')
		await opusInput.setValue('BWV 1068')
		const saveBtn = firstRow.find('[data-test="inline-edit-save"]')
		await saveBtn.trigger('click')

		expect(wrapper.emitted('inline-update-song')?.[0]).toEqual([
			songs[1],
			{ opus: 'BWV 1068' },
		])

		const starsBtn = firstRow.find('[data-test="inline-edit-stars-btn"]')
		await starsBtn.trigger('click')
		const starsSelect = firstRow.find('[data-test="inline-edit-select"]')
		await starsSelect.setValue('4')

		expect(wrapper.emitted('inline-update-song')?.[1]).toEqual([
			songs[1],
			{ stars: 4 },
		])
	})

	it('supports inline editing of entity columns with proposals (composer, collection, book) and duration', async () => {
		const libraryComposers = [
			{ id: 1, name: 'Beethoven' },
			{ id: 2, name: 'Bach' },
			{ id: 3, name: 'Mozart' },
		]
		const libraryCollections = [
			{ id: 100, name: 'Symphonies' },
			{ id: 101, name: 'Concertos' },
		]
		const wrapper = mount(SongTableView, {
			props: {
				songs,
				composers: libraryComposers,
				collections: libraryCollections,
			},
		})

		const firstRow = wrapper.findAll('tbody tr')[0]

		// Edit composer inline - should have proposals from props
		const composerBtn = firstRow.find('[data-test="inline-edit-composer-btn"]')
		expect(composerBtn.exists()).toBe(true)
		await composerBtn.trigger('click')

		const composerSelect = firstRow.findComponent({ name: 'NcSelect' })
		expect(composerSelect.exists()).toBe(true)
		expect(composerSelect.props('options')).toEqual(libraryComposers)

		// Add an existing composer (Mozart) and a new one (Vivaldi)
		const createComposer = composerSelect.props('createOption')
		const vivaldi = createComposer('Vivaldi')
		expect(vivaldi).toEqual({ name: 'Vivaldi' })

		// Existing composer resolves to option with ID
		const mozart = createComposer('Mozart')
		expect(mozart).toEqual({ id: 3, name: 'Mozart' })

		await composerSelect.vm.$emit('update:modelValue', [
			{ id: 2, name: 'Bach' },
			mozart,
			vivaldi,
		])

		const composerSaveBtn = firstRow.find('[data-test="inline-edit-save"]')
		await composerSaveBtn.trigger('click')

		expect(wrapper.emitted('inline-update-song')?.[0]).toEqual([
			songs[1],
			{
				composers: [
					{ id: 2, name: 'Bach' },
					{ id: 3, name: 'Mozart' },
					{ name: 'Vivaldi' },
				],
			},
		])

		// Edit collection inline - proposals from props
		const collectionBtn = firstRow.find('[data-test="inline-edit-collection-btn"]')
		expect(collectionBtn.exists()).toBe(true)
		await collectionBtn.trigger('click')

		const collectionSelect = firstRow.findComponent({ name: 'NcSelect' })
		expect(collectionSelect.exists()).toBe(true)
		expect(collectionSelect.props('options')).toEqual(libraryCollections)

		const createCollection = collectionSelect.props('createOption')
		const baroqueCol = createCollection('Baroque Gems')
		expect(baroqueCol).toEqual({ name: 'Baroque Gems' })

		await collectionSelect.vm.$emit('update:modelValue', [
			libraryCollections[1],
			baroqueCol,
		])

		const collectionSaveBtn = firstRow.find('[data-test="inline-edit-save"]')
		await collectionSaveBtn.trigger('click')

		expect(wrapper.emitted('inline-update-song')?.[1]).toEqual([
			songs[1],
			{
				collections: [
					{ id: 101, name: 'Concertos' },
					{ name: 'Baroque Gems' },
				],
			},
		])

		// Edit book inline - fallback proposals gathered from songs
		const bookBtn = firstRow.find('[data-test="inline-edit-book-btn"]')
		await bookBtn.trigger('click')
		const bookSelect = firstRow.findComponent({ name: 'NcSelect' })
		expect(bookSelect.props('options')).toEqual([
			{ id: 10, title: 'Classics Vol 1' },
		])

		const createBookOption = bookSelect.props('createOption')
		const baroqueBook = createBookOption('Baroque Favorites')
		expect(baroqueBook).toEqual({ title: 'Baroque Favorites' })

		await bookSelect.vm.$emit('update:modelValue', [baroqueBook])
		const bookSaveBtn = firstRow.find('[data-test="inline-edit-save"]')
		await bookSaveBtn.trigger('click')

		expect(wrapper.emitted('inline-update-song')?.[2]).toEqual([
			songs[1],
			{ books: [{ title: 'Baroque Favorites' }] },
		])

		// Edit duration inline (mm:ss -> seconds)
		const durationBtn = firstRow.find('[data-test="inline-edit-duration-btn"]')
		await durationBtn.trigger('click')
		const durationInput = firstRow.find('[data-test="inline-edit-input"]')
		await durationInput.setValue('3:15')
		await durationInput.trigger('keydown.enter')

		expect(wrapper.emitted('inline-update-song')?.[3]).toEqual([
			songs[1],
			{ duration: 195 },
		])
	})
})
