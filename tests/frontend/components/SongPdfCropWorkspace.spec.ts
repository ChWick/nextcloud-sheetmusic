import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfCropWorkspace from '../../../src/components/song-pdf/SongPdfCropWorkspace.vue'

describe('SongPdfCropWorkspace.vue', () => {
	const defaultProps = {
		canvasWidth: 400,
		canvasHeight: 600,
		zoomLevel: 1.0,
		isLoadingPage: false,
		isCustomCropped: false,
		cropBox: { left: 0, top: 0, width: 1, height: 1 },
	}

	it('renders workspace, canvas, and crop overlay with scaled dimensions', () => {
		const wrapper = mount(SongPdfCropWorkspace, {
			props: {
				...defaultProps,
				zoomLevel: 1.5,
			},
		})

		const workspace = wrapper.find('[data-test="crop-workspace"]')
		expect(workspace.exists()).toBe(true)

		const canvasWrapper = wrapper.find('div[style*="width: 600px"]')
		expect(canvasWrapper.exists()).toBe(true)
		expect(canvasWrapper.attributes('style')).toContain('height: 900px')

		const overlay = wrapper.find('[data-test="crop-overlay"]')
		expect(overlay.exists()).toBe(true)
	})

	it('displays loading spinner overlay when isLoadingPage is true', () => {
		const wrapper = mount(SongPdfCropWorkspace, {
			props: {
				...defaultProps,
				isLoadingPage: true,
			},
		})

		const loadingOverlay = wrapper.find('[data-test="crop-loading-overlay"]')
		expect(loadingOverlay.exists()).toBe(true)
	})

	it('emits zoom-change on wheel with Ctrl key', async () => {
		const wrapper = mount(SongPdfCropWorkspace, {
			props: defaultProps,
		})

		const workspace = wrapper.find('[data-test="crop-workspace"]')
		await workspace.trigger('wheel', {
			ctrlKey: true,
			deltaY: -100, // Zoom in
		})

		expect(wrapper.emitted('zoom-change')).toBeTruthy()
		const [zoomVal] = wrapper.emitted('zoom-change')![0] as [number]
		expect(zoomVal).toBeGreaterThan(1.0)
	})

	it('emits update:cropBox when overlay updates crop', async () => {
		const wrapper = mount(SongPdfCropWorkspace, {
			props: defaultProps,
		})

		const newCrop = { left: 0.1, top: 0.1, width: 0.8, height: 0.8 }
		// Emit update:cropBox from child component
		const overlay = wrapper.findComponent({ name: 'SongPdfCropOverlay' })
		expect(overlay.exists()).toBe(true)
		overlay.vm.$emit('update:cropBox', newCrop)

		expect(wrapper.emitted('update:cropBox')).toBeTruthy()
		expect(wrapper.emitted('update:cropBox')?.[0]).toEqual([newCrop])
	})
})
