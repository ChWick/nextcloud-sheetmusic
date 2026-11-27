/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SetlistBreakRow from '../../src/components/setlists/SetlistBreakRow.vue'
import StageClockBadge from '../../src/components/setlists/StageClockBadge.vue'
import {
	computeSetlistTiming,
	formatStageDuration,
	loadSetlistBreaks,
	remapBreaksOnRemove,
	saveSetlistBreaks,
	type SetlistBreak,
} from '../../src/utils/setlist-timing'

describe('setlist timing, break markers, and stage clock', () => {
	beforeEach(() => {
		window.localStorage.clear()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('computes total, remaining, and per-set segment durations including breaks', () => {
		const items = [
			{ duration: 300 }, // 5:00
			{ duration: 240 }, // 4:00
			{ duration: 420 }, // 7:00
			{ duration: 0 }, // missing
		]
		const breaks: SetlistBreak[] = [
			{ id: 'b1', afterIndex: 1, label: 'Intermission', durationMinutes: 15 },
		]

		const timing = computeSetlistTiming(items, breaks, 1)
		expect(timing.songSeconds).toBe(960)
		expect(timing.breakSeconds).toBe(900)
		expect(timing.totalSeconds).toBe(1860) // 31:00
		// From index 1 onward: item[1] (240) + break (900) + item[2] (420) = 1560 (26:00)
		expect(timing.remainingSeconds).toBe(1560)
		expect(timing.missingDurationCount).toBe(1)
		expect(timing.segments).toHaveLength(2)
		expect(timing.segments[0]).toMatchObject({ songCount: 2, durationSeconds: 540 })
		expect(timing.segments[1]).toMatchObject({ songCount: 2, durationSeconds: 420 })
		expect(formatStageDuration(3665)).toBe('1:01:05')
	})

	it('persists breaks per library/setlist and remaps indices when removing items', () => {
		const breaks: SetlistBreak[] = [
			{ id: 'b1', afterIndex: 0, label: 'Pause', durationMinutes: 10 },
			{ id: 'b2', afterIndex: 2, label: 'Intermission', durationMinutes: 20 },
		]
		saveSetlistBreaks(1, 42, breaks)
		expect(loadSetlistBreaks(1, 42)).toEqual(breaks)

		// Removing index 1 shifts b2 from afterIndex 2 -> 1
		const remapped = remapBreaksOnRemove(breaks, 1, 3)
		expect(remapped.map((b) => b.afterIndex)).toEqual([0, 1])
	})

	it('renders SetlistBreakRow with subtotal and emits label, duration, and remove events', async () => {
		const brk: SetlistBreak = { id: 'b1', afterIndex: 1, label: 'Intermission', durationMinutes: 15 }
		const onUpdate = vi.fn()
		const onRemove = vi.fn()
		const wrapper = mount({
			components: { SetlistBreakRow },
			template: '<table><tbody><SetlistBreakRow :break-item="brk" :segment-number="1" :segment-duration-seconds="540" :col-span="5" @update="onUpdate" @remove="onRemove" /></tbody></table>',
			setup: () => ({ brk, onUpdate, onRemove }),
		})

		expect(wrapper.get('[data-test="setlist-segment-subtotal"]').text()).toContain('Set 1: 9:00')
		const minutesInput = wrapper.get('[data-test="setlist-break-minutes-1"]')
		await minutesInput.setValue('20')
		await minutesInput.trigger('change')
		expect(onUpdate).toHaveBeenCalledWith(expect.objectContaining({ durationMinutes: 20 }))

		await wrapper.get('[data-test="setlist-break-remove-1"]').trigger('click')
		expect(onRemove).toHaveBeenCalledWith(1)
	})

	it('switches StageClockBadge between Clock, Remaining, and Countdown modes and ticks down', async () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-27T20:00:00'))

		const wrapper = mount(StageClockBadge, {
			props: {
				remainingSeconds: 1500, // 25:00
				totalSeconds: 2400, // 40:00
				hudVisible: false,
				concertMode: false,
			},
		})

		const badge = wrapper.get('[data-test="stage-clock-badge"]')
		expect(badge.text()).toContain('20:00')

		// Open popover and switch to Remaining mode
		await badge.trigger('click')
		await wrapper.get('[data-test="stage-clock-mode-remaining"]').trigger('click')
		expect(badge.text()).toContain('-25:00')
		expect(wrapper.get('[data-test="stage-clock-finish-time"]').text()).toContain('20:25')

		// Switch to Countdown mode, pick 15m preset, start, and advance 2 seconds
		await wrapper.get('[data-test="stage-clock-mode-countdown"]').trigger('click')
		await wrapper.get('[data-test="stage-clock-preset-15"]').trigger('click')
		expect(badge.text()).toContain('15:00')

		await wrapper.get('[data-test="stage-clock-countdown-toggle"]').trigger('click')
		vi.advanceTimersByTime(2000)
		await wrapper.vm.$nextTick()
		expect(badge.text()).toContain('14:58')

		// Reset restores 15:00
		await wrapper.get('[data-test="stage-clock-countdown-reset"]').trigger('click')
		expect(badge.text()).toContain('15:00')

		// Concert mode closes popover and ignores clicks
		await wrapper.setProps({ concertMode: true })
		expect(wrapper.find('[data-test="stage-clock-popover"]').exists()).toBe(false)
		await badge.trigger('click')
		expect(wrapper.find('[data-test="stage-clock-popover"]').exists()).toBe(false)
	})
})
