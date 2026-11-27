import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PerformanceAutoScrollControl from '../../../src/components/setlists/PerformanceAutoScrollControl.vue'

describe('PerformanceAutoScrollControl', () => {
	it('toggles auto-scroll on main button click and opens options popover via side arrow', async () => {
		const wrapper = mount(PerformanceAutoScrollControl, {
			props: {
				mode: 'speed',
				speedPxPerSec: 35,
				startDelaySeconds: 0,
				isRunning: false,
				isPaused: false,
				countdownRemaining: 0,
				songDurationSeconds: 420,
			},
		})

		await wrapper.find('[data-test="performance-autoscroll-toggle"]').trigger('click')
		expect(wrapper.emitted('toggle')).toHaveLength(1)
		expect(wrapper.find('[data-test="performance-autoscroll-popover"]').exists()).toBe(false)

		// Open popover via side arrow
		await wrapper.find('[data-test="performance-autoscroll-menu"]').trigger('click')
		expect(wrapper.find('[data-test="performance-autoscroll-popover"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="autoscroll-speed-value"]').text()).toBe('35 px/s')

		// Adjust speed via +5, -5, and logarithmic slider (log2(80) ≈ 6.32)
		await wrapper.find('[data-test="autoscroll-speed-plus"]').trigger('click')
		await wrapper.find('[data-test="autoscroll-speed-minus"]').trigger('click')
		const slider = wrapper.find('[data-test="autoscroll-speed-slider"]')
		expect(Number(slider.attributes('min'))).toBeCloseTo(Math.log2(5), 4)
		expect(Number(slider.attributes('max'))).toBeCloseTo(Math.log2(200), 4)
		await slider.setValue(String(Math.log2(80)))
		expect(wrapper.emitted('adjustSpeed')).toEqual([[5], [-5]])
		expect(wrapper.emitted('update:speedPxPerSec')).toEqual([[80]])

		// Select delay preset & back to top
		await wrapper.find('[data-test="autoscroll-delay-5"]').trigger('click')
		expect(wrapper.emitted('update:startDelaySeconds')).toEqual([[5]])

		await wrapper.find('[data-test="autoscroll-reset-top"]').trigger('click')
		expect(wrapper.emitted('resetToTop')).toHaveLength(1)

		// Switch to song-duration calibration
		const durationBtn = wrapper.find('[data-test="autoscroll-mode-duration"]')
		expect(durationBtn.text()).toContain('7:00')
		await durationBtn.trigger('click')
		expect(wrapper.emitted('update:mode')).toEqual([['duration']])
	})

	it('disables duration mode when song has no duration and displays countdown when active', async () => {
		const wrapper = mount(PerformanceAutoScrollControl, {
			props: {
				mode: 'speed',
				speedPxPerSec: 35,
				startDelaySeconds: 3,
				isRunning: false,
				isPaused: false,
				countdownRemaining: 3,
				songDurationSeconds: null,
				open: true,
			},
		})

		expect(wrapper.find('[data-test="performance-autoscroll-toggle"]').text()).toContain('3s')
		const durationBtn = wrapper.find('[data-test="autoscroll-mode-duration"]')
		expect((durationBtn.element as HTMLButtonElement).disabled).toBe(true)
	})
})
