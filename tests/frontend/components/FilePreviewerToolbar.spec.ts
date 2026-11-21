import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FilePreviewerToolbar from '../../../src/components/file-preview/FilePreviewerToolbar.vue'

describe('FilePreviewerToolbar.vue', () => {
	const props = {
		isPdf: true,
		currentPage: 2,
		totalPages: 5,
		currentPageDisplay: '2',
		pageLayout: 'single' as const,
		zoomSelectValue: 'fit-width',
		isCustomZoom: false,
		displayScale: 1,
		showExternalButton: true,
	}

	it('shows PDF navigation, page layout, zoom, and external controls', () => {
		const wrapper = mount(FilePreviewerToolbar, { props })

		expect(wrapper.text()).toContain('Page 2 of 5')
		expect(wrapper.get('button[aria-label="Previous page"]').exists()).toBe(true)
		expect(wrapper.get('button[aria-label="Two pages side-by-side"]').exists()).toBe(true)
		expect(wrapper.get('select[aria-label="Zoom level"]').exists()).toBe(true)
		expect(wrapper.get('button[aria-label="Open in external viewer"]').exists()).toBe(true)
	})

	it('emits navigation and page layout actions', async () => {
		const wrapper = mount(FilePreviewerToolbar, { props })

		await wrapper.get('button[aria-label="Next page"]').trigger('click')
		await wrapper.get('button[aria-label="Two pages side-by-side"]').trigger('click')

		expect(wrapper.emitted('nextPage')).toHaveLength(1)
		expect(wrapper.emitted('pageLayout')).toEqual([['double']])
	})

	it('hides PDF navigation for images and honors external-button visibility', () => {
		const wrapper = mount(FilePreviewerToolbar, {
			props: {
				...props,
				isPdf: false,
				showExternalButton: false,
			},
		})

		expect(wrapper.find('button[aria-label="Previous page"]').exists()).toBe(false)
		expect(wrapper.find('button[aria-label="Two pages side-by-side"]').exists()).toBe(false)
		expect(wrapper.find('button[aria-label="Open in external viewer"]').exists()).toBe(false)
		expect(wrapper.get('button[aria-label="Zoom in"]').exists()).toBe(true)
	})
})
