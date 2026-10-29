import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfCropOverlay from '../../../src/components/song-pdf/SongPdfCropOverlay.vue'

describe('SongPdfCropOverlay.vue', () => {
	const defaultProps = {
		cropBox: { left: 0.1, top: 0.1, width: 0.8, height: 0.8 },
		canvasWidth: 500,
		canvasHeight: 600,
		isCustomCropped: true,
	}

	it('renders overlay, crop box, 4 corner handles, and 4 edge handles', () => {
		const wrapper = mount(SongPdfCropOverlay, {
			props: defaultProps,
		})

		expect(wrapper.find('[data-test="crop-overlay"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="crop-box"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handle-nw"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handle-ne"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handle-se"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handle-sw"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handle-n"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handle-s"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handle-w"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="handle-e"]').exists()).toBe(true)
	})

	it('computes overlayBoxStyle in pixels according to canvas dimensions', () => {
		const wrapper = mount(SongPdfCropOverlay, {
			props: defaultProps,
		})

		const box = wrapper.find('[data-test="crop-box"]')
		// 0.1 * 500 = 50px, 0.1 * 600 = 60px, 0.8 * 500 = 400px, 0.8 * 600 = 480px
		expect(box.attributes('style')).toContain('left: 50px')
		expect(box.attributes('style')).toContain('top: 60px')
		expect(box.attributes('style')).toContain('width: 400px')
		expect(box.attributes('style')).toContain('height: 480px')
	})

	it('starts new crop creation when pointer down occurs on overlay background', async () => {
		const wrapper = mount(SongPdfCropOverlay, {
			props: defaultProps,
			attachTo: document.body,
		})

		const overlay = wrapper.find('[data-test="crop-overlay"]')
		// Mock getBoundingClientRect
		const el = overlay.element as HTMLElement
		el.getBoundingClientRect = () => ({
			left: 0,
			top: 0,
			right: 500,
			bottom: 600,
			width: 500,
			height: 600,
			x: 0,
			y: 0,
			toJSON: () => {},
		})

		await overlay.trigger('pointerdown', { clientX: 100, clientY: 150 })
		expect(wrapper.emitted('update:cropBox')).toBeTruthy()
		// 100 / 500 = 0.2, 150 / 600 = 0.25
		expect(wrapper.emitted('update:cropBox')?.[0]).toEqual([{
			left: 0.2,
			top: 0.25,
			width: 0,
			height: 0,
		}])

		wrapper.unmount()
	})
})
