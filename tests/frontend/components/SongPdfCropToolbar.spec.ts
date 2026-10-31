import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfCropToolbar from '../../../src/components/song-pdf/SongPdfCropToolbar.vue'

describe('SongPdfCropToolbar.vue', () => {
	const defaultProps = {
		pageNum: 2,
		totalPages: 5,
		zoomLevel: 1.0,
		minZoom: 0.5,
		maxZoom: 3.0,
		isCustomCropped: false,
	}

	it('renders page indicator and emits page change events', async () => {
		const wrapper = mount(SongPdfCropToolbar, {
			props: defaultProps,
		})

		expect(wrapper.text()).toContain('Page 2 of 5')

		const prevBtn = wrapper.find('button[aria-label="Previous page"]')
		const nextBtn = wrapper.find('button[aria-label="Next page"]')

		expect(prevBtn.exists()).toBe(true)
		expect(nextBtn.exists()).toBe(true)

		await prevBtn.trigger('click')
		expect(wrapper.emitted('change-page')?.[0]).toEqual([1])

		await nextBtn.trigger('click')
		expect(wrapper.emitted('change-page')?.[1]).toEqual([3])
	})

	it('disables previous page on page 1 and next page on last page', () => {
		const wrapperFirst = mount(SongPdfCropToolbar, {
			props: { ...defaultProps, pageNum: 1 },
		})
		const prevBtn = wrapperFirst.find('button[aria-label="Previous page"]')
		expect(prevBtn.attributes('disabled')).toBeDefined()

		const wrapperLast = mount(SongPdfCropToolbar, {
			props: { ...defaultProps, pageNum: 5, totalPages: 5 },
		})
		const nextBtn = wrapperLast.find('button[aria-label="Next page"]')
		expect(nextBtn.attributes('disabled')).toBeDefined()
	})

	it('renders zoom controls and emits zoom-in, zoom-out, and zoom-reset events', async () => {
		const wrapper = mount(SongPdfCropToolbar, {
			props: { ...defaultProps, zoomLevel: 1.5 },
		})

		expect(wrapper.find('[data-test="crop-zoom-level"]').text()).toBe('150%')

		const zoomInBtn = wrapper.find('[data-test="crop-zoom-in"]')
		const zoomOutBtn = wrapper.find('[data-test="crop-zoom-out"]')
		const zoomFitBtn = wrapper.find('[data-test="crop-zoom-fit"]')

		await zoomInBtn.trigger('click')
		expect(wrapper.emitted('zoom-in')).toBeTruthy()

		await zoomOutBtn.trigger('click')
		expect(wrapper.emitted('zoom-out')).toBeTruthy()

		await zoomFitBtn.trigger('click')
		expect(wrapper.emitted('zoom-reset')).toBeTruthy()
	})

	it('emits apply-preset and reset-crop events', async () => {
		const wrapper = mount(SongPdfCropToolbar, {
			props: { ...defaultProps, isCustomCropped: true },
		})

		const trim5Btn = wrapper.findAll('button').find((b) => b.text().includes('Trim 5%'))
		const trim10Btn = wrapper.findAll('button').find((b) => b.text().includes('Trim 10%'))
		const resetBtn = wrapper.find('button[aria-label="Reset to full page"]')

		await trim5Btn?.trigger('click')
		expect(wrapper.emitted('apply-preset')?.[0]).toEqual([5])

		await trim10Btn?.trigger('click')
		expect(wrapper.emitted('apply-preset')?.[1]).toEqual([10])

		await resetBtn.trigger('click')
		expect(wrapper.emitted('reset-crop')).toBeTruthy()
	})
})
