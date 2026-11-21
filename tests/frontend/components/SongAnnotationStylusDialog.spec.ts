/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SongAnnotationStylusDialog from '../../../src/components/song-viewer/SongAnnotationStylusDialog.vue'
import { resetStylusSettingsStateForTests, useStylusSettings } from '../../../src/composables/useStylusSettings'

describe('SongAnnotationStylusDialog.vue', () => {
	beforeEach(() => {
		resetStylusSettingsStateForTests()
	})

	it('runs 5-point tip offset calibration and updates stylus settings', async () => {
		const wrapper = mount(SongAnnotationStylusDialog, {
			global: { stubs: { teleport: true } },
		})

		await wrapper.find('[data-test="start-tip-calibration"]').trigger('click')
		const pad = wrapper.find('[data-test="tip-calibration-pad"]')
		expect(pad.exists()).toBe(true)

		vi.spyOn(pad.element, 'getBoundingClientRect').mockReturnValue({
			left: 0,
			top: 0,
			width: 400,
			height: 200,
			right: 400,
			bottom: 200,
			x: 0,
			y: 0,
			toJSON: () => ({}),
		})

		// 5 targets at (200,100), (80,50), (320,50), (320,150), (80,150)
		// Simulate stylus consistently tapping 6px left and 4px below each crosshair
		const targets = [
			[200, 100],
			[80, 50],
			[320, 50],
			[320, 150],
			[80, 150],
		]
		for (const [tx, ty] of targets) {
			await pad.trigger('pointerdown', {
				pointerType: 'pen',
				clientX: tx - 6,
				clientY: ty + 4,
				button: 0,
				pointerId: 1,
			})
		}

		const { settings } = useStylusSettings()
		expect(settings.value.offsetX).toBe(6)
		expect(settings.value.offsetY).toBe(-4)
		expect(wrapper.find('[data-test="tip-calibration-status"]').text()).toContain('+6')

		wrapper.unmount()
	})

	it('updates pressure mode, scratchpad telemetry, and resets defaults', async () => {
		const wrapper = mount(SongAnnotationStylusDialog, {
			global: { stubs: { teleport: true } },
		})

		await wrapper.find('[data-test="pressure-mode-hardware"]').trigger('click')
		const { settings } = useStylusSettings()
		expect(settings.value.pressureMode).toBe('hardware')

		const scratchpad = wrapper.find('[data-test="pressure-scratchpad"]')
		vi.spyOn(scratchpad.element, 'getBoundingClientRect').mockReturnValue({
			left: 0,
			top: 0,
			width: 400,
			height: 140,
			right: 400,
			bottom: 140,
			x: 0,
			y: 0,
			toJSON: () => ({}),
		})

		await scratchpad.trigger('pointerdown', {
			pointerType: 'pen',
			pressure: 0.15,
			clientX: 40,
			clientY: 70,
			button: 0,
			pointerId: 1,
		})
		for (const [cx, p] of [[100, 0.3], [180, 0.65], [260, 0.85]]) {
			await scratchpad.trigger('pointermove', {
				pointerType: 'pen',
				pressure: p,
				clientX: cx,
				clientY: 70,
				button: 0,
				pointerId: 1,
			})
		}
		await scratchpad.trigger('pointerup', {
			pointerType: 'pen',
			pressure: 0.85,
			clientX: 260,
			clientY: 70,
			button: 0,
			pointerId: 1,
		})

		expect(wrapper.find('[data-test="pad-input-type"]').text()).toBe('pen')

		await wrapper.find('[data-test="calibrate-pressure-range"]').trigger('click')
		expect(settings.value.pressureMin).toBe(0.15)
		expect(settings.value.pressureMax).toBe(0.85)

		await wrapper.find('[data-test="stylus-reset-all"]').trigger('click')
		expect(settings.value.pressureMode).toBe('auto')
		expect(settings.value.offsetX).toBe(0)

		wrapper.unmount()
	})
})
