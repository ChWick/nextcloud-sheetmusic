import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SongPdfJoinControls from '../../../src/components/song-pdf/SongPdfJoinControls.vue'

describe('SongPdfJoinControls.vue', () => {
	const defaultProps = {
		pageA: 1,
		pageB: 2,
		totalPages: 4,
		pageCrops: { 1: { left: 0.1, top: 0.1, width: 0.8, height: 0.8 } },
		layout: 'vertical' as const,
		fitInfo: {
			fitsOnStandardPage: true,
			widthPercentage: 85,
			heightPercentage: 90,
			combinedWidth: 500,
			combinedHeight: 700,
		},
		isSizeAligned: true,
		scaledDimA: { width: 500, height: 350 },
		scaledDimB: { width: 500, height: 350 },
		scaleA: 1.0,
		scaleB: 1.0,
		fitToStandardPage: true,
		scaleToFit: true,
	}

	it('renders page selectors and emits update:pageA and update:pageB', async () => {
		const wrapper = mount(SongPdfJoinControls, {
			props: defaultProps,
		})

		const selects = wrapper.findAll('select')
		expect(selects.length).toBe(2)

		// Page 1 is cropped
		expect(selects[0].text()).toContain('Cropped')

		await selects[0].setValue('3')
		expect(wrapper.emitted('update:pageA')?.[0]).toEqual([3])

		await selects[1].setValue('4')
		expect(wrapper.emitted('update:pageB')?.[0]).toEqual([4])
	})

	it('renders layout toggle and emits update:layout', async () => {
		const wrapper = mount(SongPdfJoinControls, {
			props: defaultProps,
		})

		const buttons = wrapper.findAll('button')
		// The first two buttons in layout toggle
		const verticalBtn = buttons[0]
		const horizontalBtn = buttons[1]

		await horizontalBtn.trigger('click')
		expect(wrapper.emitted('update:layout')?.[0]).toEqual(['horizontal'])

		await verticalBtn.trigger('click')
		expect(wrapper.emitted('update:layout')?.[1]).toEqual(['vertical'])
	})

	it('displays success fit banner when fitting, and warning banner when exceeding', async () => {
		const wrapperSuccess = mount(SongPdfJoinControls, {
			props: defaultProps,
		})
		expect(wrapperSuccess.text()).toContain('Pages fit on a single page!')

		const wrapperWarning = mount(SongPdfJoinControls, {
			props: {
				...defaultProps,
				fitInfo: {
					fitsOnStandardPage: false,
					widthPercentage: 110,
					heightPercentage: 120,
					combinedWidth: 650,
					combinedHeight: 950,
				},
			},
		})
		expect(wrapperWarning.text()).toContain('Content exceeds standard page size')
	})

	it('handles size alignment quick buttons and displays alignment status', async () => {
		const wrapper = mount(SongPdfJoinControls, {
			props: {
				...defaultProps,
				scaleA: 1.2,
			},
		})

		expect(wrapper.find('[data-test="aligned-status"]').exists()).toBe(true)

		const alignBtn = wrapper.find('[data-test="align-sizes-btn"]')
		await alignBtn.trigger('click')
		expect(wrapper.emitted('alignSizes')).toHaveLength(1)

		const resetBtn = wrapper.find('[data-test="reset-sizes-btn"]')
		await resetBtn.trigger('click')
		expect(wrapper.emitted('resetScales')).toHaveLength(1)
	})

	it('handles page scaling sliders, inputs, and match buttons', async () => {
		const wrapper = mount(SongPdfJoinControls, {
			props: defaultProps,
		})

		const sliderA = wrapper.find('[data-test="scale-a-slider"]')
		await sliderA.setValue(1.25)
		await sliderA.trigger('input')
		expect(wrapper.emitted('update:scaleA')).toBeTruthy()

		const inputB = wrapper.find('[data-test="scale-b-input"]')
		await inputB.setValue(110)
		await inputB.trigger('input')
		expect(wrapper.emitted('update:scaleB')).toBeTruthy()

		const matchBBtn = wrapper.find('[data-test="match-b-btn"]')
		await matchBBtn.trigger('click')
		expect(wrapper.emitted('matchPageB')).toHaveLength(1)

		const matchABtn = wrapper.find('[data-test="match-a-btn"]')
		await matchABtn.trigger('click')
		expect(wrapper.emitted('matchPageA')).toHaveLength(1)
	})

	it('handles fitToStandardPage and scaleToFit checkboxes', async () => {
		const wrapper = mount(SongPdfJoinControls, {
			props: defaultProps,
		})

		const checkboxes = wrapper.findAll('input[type="checkbox"]')
		expect(checkboxes.length).toBe(2)

		await checkboxes[0].setValue(false)
		expect(wrapper.emitted('update:fitToStandardPage')?.[0]).toEqual([false])

		await checkboxes[1].setValue(false)
		expect(wrapper.emitted('update:scaleToFit')?.[0]).toEqual([false])
	})
})
