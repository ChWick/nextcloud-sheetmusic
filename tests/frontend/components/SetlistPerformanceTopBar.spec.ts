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

	it('emits exit, toggleAnnotations, toggleSettings, and toggleFullscreen', async () => {
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
		await wrapper.find('[data-test="stop-performance-button"]').trigger('click')
		await wrapper.find('button[aria-label="Toggle annotations"]').trigger('click')
		await wrapper.find('button[aria-label="Viewer settings & defaults"]').trigger('click')
		await wrapper.find('[data-test="performance-concert-mode-button"]').trigger('click')
		await wrapper.find('button[aria-label="Fullscreen"]').trigger('click')

		expect(wrapper.emitted('exit')).toHaveLength(1)
		expect(wrapper.emitted('stop')).toHaveLength(1)
		expect(wrapper.emitted('toggleAnnotations')).toHaveLength(1)
		expect(wrapper.emitted('toggleSettings')).toHaveLength(1)
		expect(wrapper.emitted('enterConcertMode')).toHaveLength(1)
		expect(wrapper.emitted('toggleFullscreen')).toHaveLength(1)
	})

	it('renders individual annotation layer toggles when multiple layers exist', async () => {
		const wrapper = mount(SetlistPerformanceTopBar, {
			props: {
				visible: true,
				setlistName: 'Friday Gig',
				songTitle: 'Autumn Leaves',
				itemIndex: 0,
				itemCount: 3,
				showAnnotations: true,
				annotationLayers: [
					{ id: 0, name: 'Default', visible: true, order: 0 },
					{ id: 1, name: 'Fingerings', visible: false, order: 1 },
				],
				isFullscreen: false,
				showSettingsButton: false,
				isSettingsOpen: false,
				isSetlistOverride: false,
			},
		})

		const trigger = wrapper.find('[data-test="performance-layers-button"]')
		expect(trigger.exists()).toBe(true)
		expect(trigger.text()).toContain('1/2')

		await trigger.trigger('click')
		expect(wrapper.find('[data-test="performance-layers-popover"]').exists()).toBe(true)

		await wrapper.find('[data-test="performance-layer-toggle-1"]').trigger('click')
		expect(wrapper.emitted('toggleLayerVisibility')).toEqual([[1]])

		// Hiding the top bar (e.g. entering Concert Mode) closes the layers popover
		await wrapper.setProps({ visible: false })
		expect(wrapper.find('[data-test="performance-layers-popover"]').exists()).toBe(false)
	})
})
