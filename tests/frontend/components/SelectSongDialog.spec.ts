import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SelectSongDialog from '../../../src/components/song-editor/SelectSongDialog.vue'

describe('SelectSongDialog.vue', () => {
	const mockLibraryData = {
		songs: [
			{ id: 101, title: 'Joshua', composers: [{ id: 1, name: 'Händel' }], files: [] },
			{ id: 102, title: 'Messiah', composers: [{ id: 1, name: 'Händel' }], files: [] },
			{ id: 103, title: 'Toccata', composers: [{ id: 2, name: 'Bach' }], files: [] },
		],
		composers: [
			{ id: 1, name: 'Händel' },
			{ id: 2, name: 'Bach' },
		],
		books: [],
		collections: [],
		artists: [],
		genres: [],
		keys: [],
	}

	const globalStubs = {
		global: {
			stubs: {
				NcDialog: {
					template: '<div class="nc-dialog-stub"><h2>{{ name }}</h2><slot /><slot name="actions" /></div>',
					props: ['name', 'open'],
				},
				NcButton: {
					template: '<button :disabled="disabled" :aria-expanded="ariaExpanded" :aria-controls="ariaControls" @click="$emit(\'click\')"><slot /></button>',
					props: ['disabled', 'variant', 'ariaExpanded', 'ariaControls'],
					emits: ['click'],
				},
				NcTextField: {
					template: '<input :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
					props: ['value'],
				},
				SongFilterBar: {
					template: '<div class="song-filter-bar-stub"><input :value="searchTitle" aria-label="Search title" @input="$emit(\'update:searchTitle\', $event.target.value)" /></div>',
					props: ['searchTitle'],
				},
			},
		},
	}

	it('renders songs list and filters by search text', async () => {
		const wrapper = mount(SelectSongDialog, {
			props: {
				open: true,
				orphanPath: '/MobileSheets/Joshua.pdf',
				filename: 'Joshua.pdf',
				libraryId: 1,
				libraryData: mockLibraryData as any,
			},
			...globalStubs,
		})

		const songList = wrapper.find('ul')
		expect(wrapper.find('.song-filter-bar-stub').exists()).toBe(true)
		expect(wrapper.findAll('input')).toHaveLength(1)
		expect(songList.text()).toContain('Joshua')
		expect(songList.text()).toContain('Messiah')
		expect(songList.text()).toContain('Toccata')

		const searchInput = wrapper.find('input')
		await searchInput.setValue('Toccata')

		expect(songList.text()).not.toContain('Joshua')
		expect(songList.text()).toContain('Toccata')
	})

	it('allows mobile users to expand and collapse the filters', async () => {
		const wrapper = mount(SelectSongDialog, {
			props: {
				open: true,
				orphanPath: '/MobileSheets/Joshua.pdf',
				filename: 'Joshua.pdf',
				libraryId: 1,
				libraryData: mockLibraryData as any,
			},
			...globalStubs,
		})

		const filterPanel = wrapper.find('#select-song-filter-panel')
		const toggle = wrapper.find('[aria-controls="select-song-filter-panel"]')

		expect(toggle.attributes('aria-expanded')).toBe('false')
		expect(filterPanel.classes().some(className => className.includes('filtersCollapsed'))).toBe(true)

		await toggle.trigger('click')
		expect(toggle.attributes('aria-expanded')).toBe('true')
		expect(filterPanel.classes().some(className => className.includes('filtersCollapsed'))).toBe(false)

		await toggle.trigger('click')
		expect(toggle.attributes('aria-expanded')).toBe('false')
		expect(filterPanel.classes().some(className => className.includes('filtersCollapsed'))).toBe(true)
	})

	it('emits selectSong when Select & Attach is clicked', async () => {
		const wrapper = mount(SelectSongDialog, {
			props: {
				open: true,
				orphanPath: '/MobileSheets/Joshua.pdf',
				filename: 'Joshua.pdf',
				libraryId: 1,
				libraryData: mockLibraryData as any,
			},
			...globalStubs,
		})

		const attachButtons = wrapper.findAll('button').filter(b => b.text().includes('Select & Attach'))
		expect(attachButtons.length).toBe(3)

		await attachButtons[0].trigger('click')
		expect(wrapper.emitted('selectSong')?.[0]).toEqual([101])
	})
})
