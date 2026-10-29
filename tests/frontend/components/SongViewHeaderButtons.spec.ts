import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongViewHeaderButtons from '../../../src/components/songs/SongViewHeaderButtons.vue'
import type { Song } from '../../../src/types/song'

const mockSong: Song = {
	id: 1,
	title: 'Test Song',
	files: [{ id: 10, path: 'test.pdf' }],
}

describe('SongViewHeaderButtons.vue', () => {
	it('renders edit action buttons when isEditInUrl is true and openSong is provided', () => {
		const wrapper = mount(SongViewHeaderButtons, {
			props: {
				isEditInUrl: true,
				openSong: mockSong,
				standaloneCreate: false,
				isModifyPdfInUrl: false,
			},
		})

		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		expect(buttons.length).toBe(4) // Delete, History, Save without closing, Save and close

		buttons[0].trigger('click')
		expect(wrapper.emitted('deleteEdit')).toBeTruthy()

		buttons[1].trigger('click')
		expect(wrapper.emitted('openHistory')).toBeTruthy()

		buttons[2].trigger('click')
		expect(wrapper.emitted('saveEdit')?.[0]).toEqual([false])

		buttons[3].trigger('click')
		expect(wrapper.emitted('saveEdit')?.[1]).toEqual([true])
	})

	it('renders create action buttons when standaloneCreate is true', () => {
		const wrapper = mount(SongViewHeaderButtons, {
			props: {
				isEditInUrl: false,
				openSong: null,
				standaloneCreate: true,
				isModifyPdfInUrl: false,
			},
		})

		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		expect(buttons.length).toBe(2) // Cancel, Create

		buttons[0].trigger('click')
		expect(wrapper.emitted('cancelCreate')).toBeTruthy()

		buttons[1].trigger('click')
		expect(wrapper.emitted('saveCreate')).toBeTruthy()
	})

	it('renders view buttons when viewing song with PDF file selected', () => {
		const wrapper = mount(SongViewHeaderButtons, {
			props: {
				isEditInUrl: false,
				openSong: mockSong,
				standaloneCreate: false,
				isModifyPdfInUrl: false,
				selectedFile: { id: 10, path: 'score.pdf' },
			},
		})

		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		// Open in external, Modify PDF, PDF version history, History, Duplicate, Edit, Delete
		expect(buttons.length).toBe(7)

		buttons[0].trigger('click')
		expect(wrapper.emitted('openExternal')).toBeTruthy()

		buttons[1].trigger('click')
		expect(wrapper.emitted('modifyPdf')).toBeTruthy()

		buttons[2].trigger('click')
		expect(wrapper.emitted('openVersions')).toBeTruthy()

		buttons[3].trigger('click')
		expect(wrapper.emitted('openHistory')).toBeTruthy()

		buttons[4].trigger('click')
		expect(wrapper.emitted('duplicate')).toBeTruthy()

		buttons[5].trigger('click')
		expect(wrapper.emitted('edit')).toBeTruthy()

		buttons[6].trigger('click')
		expect(wrapper.emitted('deleteView')).toBeTruthy()
	})
})
