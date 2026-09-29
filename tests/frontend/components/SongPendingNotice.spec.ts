import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import SongPendingNotice from '../../../src/components/song-editor/SongPendingNotice.vue'
import type { PendingAddition, SongFile } from '../../../src/types/song'

describe('SongPendingNotice', () => {
	const mockPendingDelete: SongFile[] = [
		{ id: 10, path: '/files/OldFile.pdf' },
	]

	const mockRemoveFn = vi.fn()
	const mockPendingAddition: PendingAddition[] = [
		{
			key: 'upload-1',
			name: 'NewUpload.pdf',
			hint: 'Upload from this computer',
			remove: mockRemoveFn,
		},
	]

	const globalStubs = {
		global: {
			stubs: {
				NcButton: {
					template: '<button><slot /></button>',
				},
			},
		},
	}

	it('renders nothing when there are no pending deletes or additions', () => {
		const wrapper = mount(SongPendingNotice, {
			props: {
				pendingDeleteFiles: [],
				pendingAdditions: [],
				saveActionLabel: 'Save and Close',
			},
			...globalStubs,
		})

		expect(wrapper.html()).toBe('<!--v-if-->')
	})

	it('renders pending deletion warning and handles restore click', async () => {
		const wrapper = mount(SongPendingNotice, {
			props: {
				pendingDeleteFiles: mockPendingDelete,
				pendingAdditions: [],
				saveActionLabel: 'Save and Close',
			},
			...globalStubs,
		})

		expect(wrapper.text()).toContain('OldFile.pdf')
		expect(wrapper.text()).toContain('1 file(s) marked for removal')

		const restoreBtn = wrapper.find('button')
		await restoreBtn.trigger('click')

		expect(wrapper.emitted('restoreDelete')).toBeTruthy()
		expect(wrapper.emitted('restoreDelete')![0]).toEqual([mockPendingDelete[0]])
	})

	it('renders pending additions notice and handles remove click', async () => {
		const wrapper = mount(SongPendingNotice, {
			props: {
				pendingDeleteFiles: [],
				pendingAdditions: mockPendingAddition,
				saveActionLabel: 'Create song',
			},
			...globalStubs,
		})

		expect(wrapper.text()).toContain('NewUpload.pdf')
		expect(wrapper.text()).toContain('Upload from this computer')

		const removeBtn = wrapper.find('button')
		await removeBtn.trigger('click')

		expect(wrapper.emitted('removeAddition')).toBeTruthy()
	})
})
