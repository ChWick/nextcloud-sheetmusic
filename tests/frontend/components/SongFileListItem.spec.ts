import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SongFileListItem from '../../../src/components/song-editor/SongFileListItem.vue'

describe('SongFileListItem', () => {
	const mockFile = {
		id: 42,
		path: '/dav/files/admin/Noten/Miles/So What.pdf',
	}

	const globalStubs = {
		global: {
			stubs: {
				NcButton: {
					template: '<button :disabled="disabled"><slot /></button>',
					props: ['disabled'],
				},
			},
		},
	}

	it('renders filename and path correctly', () => {
		const wrapper = mount(SongFileListItem, {
			props: { file: mockFile },
			...globalStubs,
		})

		expect(wrapper.text()).toContain('So What.pdf')
		expect(wrapper.text()).toContain('/dav/files/admin/Noten/Miles/So What.pdf')
	})

	it('emits openExternal event when viewer button is clicked', async () => {
		const wrapper = mount(SongFileListItem, {
			props: { file: mockFile },
			...globalStubs,
		})

		const buttons = wrapper.findAll('button')
		await buttons[0].trigger('click')

		expect(wrapper.emitted('openExternal')).toBeTruthy()
		expect(wrapper.emitted('openExternal')![0]).toEqual([mockFile])
	})

	it('emits detach event when detach button is clicked', async () => {
		const wrapper = mount(SongFileListItem, {
			props: { file: mockFile },
			...globalStubs,
		})

		const detachBtn = wrapper.findAll('button')[1]
		await detachBtn.trigger('click')

		expect(wrapper.emitted('detach')).toBeTruthy()
		expect(wrapper.emitted('detach')![0]).toEqual([mockFile])
	})

	it('emits delete event when delete button is clicked', async () => {
		const wrapper = mount(SongFileListItem, {
			props: { file: mockFile },
			...globalStubs,
		})

		const deleteBtn = wrapper.findAll('button')[2]
		await deleteBtn.trigger('click')

		expect(wrapper.emitted('delete')).toBeTruthy()
		expect(wrapper.emitted('delete')![0]).toEqual([mockFile])
	})

	it('disables action buttons when disabled prop is true', () => {
		const wrapper = mount(SongFileListItem, {
			props: { file: mockFile, disabled: true },
			...globalStubs,
		})

		const detachBtn = wrapper.findAll('button')[1]
		const deleteBtn = wrapper.findAll('button')[2]
		expect(detachBtn.attributes('disabled')).toBeDefined()
		expect(deleteBtn.attributes('disabled')).toBeDefined()
	})
})
