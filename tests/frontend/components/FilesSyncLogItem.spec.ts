import type { FileLogEntry } from '../../../src/types/song'

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FilesSyncLogItem from '../../../src/components/files/FilesSyncLogItem.vue'

vi.mock('@nextcloud/router', () => ({
	generateUrl: vi.fn((path: string, params?: Record<string, any>) => {
		if (path === '/f/{fileId}' && params?.fileId) {
			return `/f/${params.fileId}`
		}
		return path
	}),
}))

describe('FilesSyncLogItem', () => {
	it('renders song entry with song title link and file link', async () => {
		const entry: FileLogEntry = {
			songId: 10,
			songTitle: 'Autumn Leaves',
			filePath: '/remote.php/dav/files/admin/Jazz/Autumn.pdf',
			message: 'File moved',
			level: 'info',
			code: 'path-moved',
			fileId: 789,
		}

		const wrapper = mount(FilesSyncLogItem, {
			props: { entry },
		})

		expect(wrapper.text()).toContain('Autumn Leaves')
		expect(wrapper.text()).toContain('Jazz/Autumn.pdf')
		expect(wrapper.text()).toContain('File moved to its naming-rule path')

		const link = wrapper.find('a')
		expect(link.exists()).toBe(true)
		expect(link.attributes('href')).toBe('/f/789')

		const songBtn = wrapper.find('button')
		await songBtn.trigger('click')

		expect(wrapper.emitted('openSong')).toBeTruthy()
		expect(wrapper.emitted('openSong')![0]).toEqual([entry])
	})

	it('renders library-scope entry without song link', () => {
		const entry: FileLogEntry = {
			songId: 0,
			songTitle: '',
			filePath: '',
			message: 'Hashcodes error',
			level: 'error',
			code: 'hashcodes-unreadable',
			fileId: null,
		}

		const wrapper = mount(FilesSyncLogItem, {
			props: { entry },
		})

		expect(wrapper.text()).toContain('Library')
		expect(wrapper.find('button').exists()).toBe(false)
		expect(wrapper.find('a').exists()).toBe(false)
	})
})
