import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import SongViewHeaderButtons from '../../../src/components/songs/SongViewHeaderButtons.vue'
import type { Song } from '../../../src/types/song'

vi.mock('@nextcloud/vue/components/NcActions', () => ({
	default: {
		name: 'NcActions',
		template: '<div class="nc-actions"><slot /></div>',
	},
}))

vi.mock('@nextcloud/vue/components/NcActionButton', () => ({
	default: {
		name: 'NcActionButton',
		emits: ['click'],
		template: '<button type="button" class="nc-action-button" @click="$emit(\'click\')"><slot /><slot name="icon" /></button>',
	},
}))

const mockSong: Song = {
	id: 1,
	title: 'Test Song',
	files: [{ id: 10, path: 'test.pdf' }],
}

describe('SongViewHeaderButtons.vue', () => {
	it('renders edit action buttons when isEditInUrl is true and openSong is provided', async () => {
		const wrapper = mount(SongViewHeaderButtons, {
			props: {
				isEditInUrl: true,
				openSong: mockSong,
				standaloneCreate: false,
				isModifyPdfInUrl: false,
			},
		})

		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		expect(buttons.length).toBe(2) // Save without closing, Save and close

		await buttons[0].trigger('click')
		expect(wrapper.emitted('saveEdit')?.[0]).toEqual([false])

		await buttons[1].trigger('click')
		expect(wrapper.emitted('saveEdit')?.[1]).toEqual([true])

		const actionsMenu = wrapper.findComponent({ name: 'NcActions' })
		expect(actionsMenu.exists()).toBe(true)

		const actionButtons = wrapper.findAllComponents({ name: 'NcActionButton' })
		expect(actionButtons.length).toBe(2) // History, Delete

		await actionButtons[0].trigger('click')
		expect(wrapper.emitted('openHistory')).toBeTruthy()

		await actionButtons[1].trigger('click')
		expect(wrapper.emitted('deleteEdit')).toBeTruthy()
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

	it('renders song-level view buttons when viewing a song', async () => {
		const wrapper = mount(SongViewHeaderButtons, {
			props: {
				isEditInUrl: false,
				openSong: mockSong,
				standaloneCreate: false,
				isModifyPdfInUrl: false,
			},
		})

		const buttons = wrapper.findAllComponents({ name: 'NcButton' })
		// Edit
		expect(buttons.length).toBe(1)

		await buttons[0].trigger('click')
		expect(wrapper.emitted('edit')).toBeTruthy()

		const actionsMenu = wrapper.findComponent({ name: 'NcActions' })
		expect(actionsMenu.exists()).toBe(true)

		const actionButtons = wrapper.findAllComponents({ name: 'NcActionButton' })
		// History, Duplicate, Delete
		expect(actionButtons.length).toBe(3)

		await actionButtons[0].trigger('click')
		expect(wrapper.emitted('openHistory')).toBeTruthy()

		await actionButtons[1].trigger('click')
		expect(wrapper.emitted('duplicate')).toBeTruthy()

		await actionButtons[2].trigger('click')
		expect(wrapper.emitted('deleteView')).toBeTruthy()
	})
})
