import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfEnhanceOperationItem from '../../../src/components/song-pdf/SongPdfEnhanceOperationItem.vue'
import type { AdaptiveThresholdOp, CompressionOp, GaussianOp, GrayLevelMappingOp } from '../../../src/components/song-pdf/scan-types'

describe('SongPdfEnhanceOperationItem.vue', () => {
	it('renders Gaussian operation parameters and handles slider events', async () => {
		const op: GaussianOp = {
			id: 'op-1',
			type: 'gaussian',
			size: 5,
			enabled: true,
		}

		const wrapper = mount(SongPdfEnhanceOperationItem, {
			props: {
				operation: op,
				index: 0,
				total: 3,
			},
		})

		expect(wrapper.text()).toContain('Gaussian Filter')
		expect(wrapper.text()).toContain('5')

		const slider = wrapper.find('input[type="range"]')
		expect(slider.exists()).toBe(true)
		await slider.setValue(7)
		await slider.trigger('input')
		await slider.trigger('change')

		expect(wrapper.emitted('input')).toBeTruthy()
		expect(wrapper.emitted('change')).toBeTruthy()
	})

	it('renders GrayLevelMapping parameters and handles color mode select', async () => {
		const op: GrayLevelMappingOp = {
			id: 'op-2',
			type: 'grayLevelMapping',
			lowerCutoff: 30,
			upperCutoff: 220,
			colorMode: 'color',
			enabled: true,
		}

		const wrapper = mount(SongPdfEnhanceOperationItem, {
			props: {
				operation: op,
				index: 1,
				total: 3,
			},
		})

		expect(wrapper.text()).toContain('Gray Level Mapping (Curves)')
		const sliders = wrapper.findAll('input[type="range"]')
		expect(sliders.length).toBe(2)

		const select = wrapper.find('select')
		expect(select.exists()).toBe(true)
		await select.setValue('grayscale')
		expect(wrapper.emitted('input')).toBeTruthy()
		expect(wrapper.emitted('change')).toBeTruthy()
	})

	it('renders AdaptiveThreshold parameters and handles mode select', async () => {
		const op: AdaptiveThresholdOp = {
			id: 'op-3',
			type: 'adaptiveThreshold',
			windowSize: 25,
			delta: 10,
			blackValue: 0,
			whiteValue: 255,
			colorMode: 'binarize',
			enabled: true,
		}

		const wrapper = mount(SongPdfEnhanceOperationItem, {
			props: {
				operation: op,
				index: 2,
				total: 3,
			},
		})

		expect(wrapper.text()).toContain('Adaptive Thresholding')
		const sliders = wrapper.findAll('input[type="range"]')
		expect(sliders.length).toBe(5) // windowSize, delta, softness, blackValue, whiteValue

		const select = wrapper.find('select')
		expect(select.exists()).toBe(true)
		await select.setValue('preserveColor')
		expect(wrapper.emitted('input')).toBeTruthy()
		expect(wrapper.emitted('change')).toBeTruthy()
	})

	it('renders Compression parameters and handles format and quality changes', async () => {
		const op: CompressionOp = {
			id: 'op-4',
			type: 'compression',
			format: 'jpeg',
			quality: 75,
			colorMode: 'grayscale',
			enabled: true,
		}

		const wrapper = mount(SongPdfEnhanceOperationItem, {
			props: {
				operation: op,
				index: 3,
				total: 4,
			},
		})

		expect(wrapper.text()).toContain('Image Compression')
		expect(wrapper.text()).toContain('75%')

		const formatSelect = wrapper.find('[data-test="compression-format-select"]')
		const qualitySlider = wrapper.find('[data-test="compression-quality-slider"]')
		const colorModeSelect = wrapper.find('[data-test="compression-colormode-select"]')
		expect(formatSelect.exists()).toBe(true)
		expect(qualitySlider.exists()).toBe(true)
		expect(colorModeSelect.exists()).toBe(true)

		await formatSelect.setValue('grayscale-8bit')
		expect(wrapper.emitted('input')).toBeTruthy()
		expect(wrapper.emitted('change')).toBeTruthy()
		// Quality slider is hidden when format is lossless 8-bit grayscale
		expect(wrapper.find('[data-test="compression-quality-slider"]').exists()).toBe(false)
	})

	it('emits toggle, move up/down, and remove actions correctly', async () => {
		const op: GaussianOp = {
			id: 'op-1',
			type: 'gaussian',
			size: 3,
			enabled: true,
		}

		const wrapper = mount(SongPdfEnhanceOperationItem, {
			props: {
				operation: op,
				index: 1,
				total: 3,
			},
		})

		// Toggle checkbox
		const checkbox = wrapper.find('input[type="checkbox"]')
		await checkbox.trigger('change')
		expect(wrapper.emitted('toggle')).toHaveLength(1)

		// Move buttons
		const buttons = wrapper.findAll('button')
		// 0: move up, 1: move down, 2: delete
		expect(buttons[0].attributes('disabled')).toBeUndefined()
		expect(buttons[1].attributes('disabled')).toBeUndefined()

		await buttons[0].trigger('click')
		expect(wrapper.emitted('move')?.[0]).toEqual(['up'])

		await buttons[1].trigger('click')
		expect(wrapper.emitted('move')?.[1]).toEqual(['down'])

		// Remove button
		await buttons[2].trigger('click')
		expect(wrapper.emitted('remove')).toHaveLength(1)
	})

	it('disables move up for first item and move down for last item', async () => {
		const op: GaussianOp = {
			id: 'op-1',
			type: 'gaussian',
			size: 3,
			enabled: false,
		}

		const wrapperFirst = mount(SongPdfEnhanceOperationItem, {
			props: {
				operation: op,
				index: 0,
				total: 2,
			},
		})
		const buttonsFirst = wrapperFirst.findAll('button')
		expect(buttonsFirst[0].attributes('disabled')).toBeDefined()
		expect(buttonsFirst[1].attributes('disabled')).toBeUndefined()

		const wrapperLast = mount(SongPdfEnhanceOperationItem, {
			props: {
				operation: op,
				index: 1,
				total: 2,
			},
		})
		const buttonsLast = wrapperLast.findAll('button')
		expect(buttonsLast[0].attributes('disabled')).toBeUndefined()
		expect(buttonsLast[1].attributes('disabled')).toBeDefined()
	})
})
