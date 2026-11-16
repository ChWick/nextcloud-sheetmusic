import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SetlistPerformanceDrawer from '../../../src/components/setlists/SetlistPerformanceDrawer.vue'
import type { SetlistItem } from '../../../src/types/song'

const items: SetlistItem[] = [
	{ id: 1, setlistId: 10, songId: 100, position: 0, songTitle: 'Autumn Leaves' },
	{ id: 2, setlistId: 10, songId: 200, position: 1, songTitle: 'Fly Me to the Moon', bookmarkName: 'Coda' },
]

describe('SetlistPerformanceDrawer', () => {
	it('renders setlist items when open and emits select and close', async () => {
		const wrapper = mount(SetlistPerformanceDrawer, {
			props: {
				open: true,
				setlistName: 'Weekend Gig',
				items,
				currentIndex: 0,
			},
		})

		expect(wrapper.text()).toContain('Weekend Gig')
		expect(wrapper.text()).toContain('Autumn Leaves')
		expect(wrapper.text()).toContain('Fly Me to the Moon')
		expect(wrapper.text()).toContain('Coda')

		const rows = wrapper.findAll('li')
		await rows[1].trigger('click')
		expect(wrapper.emitted('select')?.[0]).toEqual([1])

		await wrapper.find('button').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('renders nothing when closed', () => {
		const wrapper = mount(SetlistPerformanceDrawer, {
			props: {
				open: false,
				setlistName: 'Weekend Gig',
				items,
				currentIndex: 0,
			},
		})

		expect(wrapper.find('aside').exists()).toBe(false)
	})
})
