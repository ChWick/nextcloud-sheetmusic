import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SetlistPerformanceTopBar from '../../../src/components/setlists/SetlistPerformanceTopBar.vue'

describe('SetlistPerformanceTopBar', () => {
	it('displays song title, counter, bookmark badge, and custom settings indicator', () => {
		const wrapper = mount(SetlistPerformanceTopBar, {
			props: {
				visible: true,
				setlistName: 'Friday Gig',
				songTitle: 'Blue Bossa',
				bookmarkName: 'Bridge',
				itemIndex: 1,
				itemCount: 5,
				showAnnotations: false,
				isFullscreen: false,
				showSettingsButton: true,
				isSettingsOpen: false,
				isSetlistOverride: true,
			},
		})

		expect(wrapper.text()).toContain('2 / 5')
		expect(wrapper.text()).toContain('Blue Bossa')
		expect(wrapper.text()).toContain('Bridge')
		expect(wrapper.text()).toContain('Friday Gig')
		expect(wrapper.text()).toContain('Custom')
	})

	it('emits exit, toggleDrawer, toggleAnnotations, toggleSettings, and toggleFullscreen', async () => {
		const wrapper = mount(SetlistPerformanceTopBar, {
			props: {
				visible: true,
				setlistName: 'Friday Gig',
				songTitle: 'Autumn Leaves',
				itemIndex: 0,
				itemCount: 3,
				showAnnotations: false,
				isFullscreen: false,
				showSettingsButton: true,
				isSettingsOpen: false,
				isSetlistOverride: false,
			},
		})

		await wrapper.find('button[aria-label="Exit performance mode"]').trigger('click')
		await wrapper.find('button[aria-label="Setlist items"]').trigger('click')
		await wrapper.find('button[aria-label="Toggle annotations"]').trigger('click')
		await wrapper.find('button[aria-label="Viewer settings & defaults"]').trigger('click')
		await wrapper.find('button[aria-label="Fullscreen"]').trigger('click')

		expect(wrapper.emitted('exit')).toHaveLength(1)
		expect(wrapper.emitted('toggleDrawer')).toHaveLength(1)
		expect(wrapper.emitted('toggleAnnotations')).toHaveLength(1)
		expect(wrapper.emitted('toggleSettings')).toHaveLength(1)
		expect(wrapper.emitted('toggleFullscreen')).toHaveLength(1)
	})
})
