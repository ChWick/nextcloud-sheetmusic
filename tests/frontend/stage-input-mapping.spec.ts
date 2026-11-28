/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it } from 'vitest'
import StageInputMappingControls from '../../src/components/setlists/StageInputMappingControls.vue'
import {
	DEFAULT_STAGE_INPUT_MAPPING,
	loadStageInputMapping,
	resolveKeyAction,
	resolveTapZoneAction,
	saveStageInputMapping,
	validateStageInputMapping,
} from '../../src/utils/stage-input-mapping'

describe('stage-input-mapping and StageInputMappingControls', () => {
	beforeEach(() => {
		window.localStorage.clear()
	})

	it('resolves tap-zone actions accurately for all layouts', () => {
		expect(resolveTapZoneAction('left-right', 0.1, 0.5)).toBe('prev-page')
		expect(resolveTapZoneAction('left-right', 0.5, 0.5)).toBe('toggle-hud')
		expect(resolveTapZoneAction('left-right', 0.9, 0.5)).toBe('next-page')

		expect(resolveTapZoneAction('top-bottom', 0.5, 0.1)).toBe('prev-page')
		expect(resolveTapZoneAction('top-bottom', 0.5, 0.5)).toBe('toggle-hud')
		expect(resolveTapZoneAction('top-bottom', 0.5, 0.9)).toBe('next-page')

		expect(resolveTapZoneAction('right-wide', 0.1, 0.5)).toBe('prev-page')
		expect(resolveTapZoneAction('right-wide', 0.3, 0.5)).toBe('toggle-hud')
		expect(resolveTapZoneAction('right-wide', 0.6, 0.5)).toBe('next-page')

		expect(resolveTapZoneAction('disabled', 0.95, 0.95)).toBe('toggle-hud')
	})

	it('resolves 2-pedal / 4-pedal / keyboard mappings and persists validated settings', () => {
		const custom = validateStageInputMapping({
			tapZoneLayout: 'top-bottom',
			pedalNext: 'next-song',
			pedalPrev: 'prev-song',
			pedalAux1: 'toggle-metronome',
			pedalAux2: 'toggle-night-mode',
			keySpace: 'toggle-autoscroll',
			keyEnter: 'invalid-action',
		})

		expect(custom.keyEnter).toBe(DEFAULT_STAGE_INPUT_MAPPING.keyEnter)
		expect(DEFAULT_STAGE_INPUT_MAPPING.keySpace).toBe('autoscroll-or-next-page')
		expect(resolveKeyAction('ArrowRight', custom)).toBe('next-song')
		expect(resolveKeyAction('PageUp', custom)).toBe('prev-song')
		expect(resolveKeyAction('ArrowUp', custom)).toBe('toggle-metronome')
		expect(resolveKeyAction('ArrowDown', custom)).toBe('toggle-night-mode')
		expect(resolveKeyAction(' ', custom)).toBe('toggle-autoscroll')
		expect(resolveKeyAction('KeyX', custom)).toBeNull()

		saveStageInputMapping(custom)
		expect(loadStageInputMapping()).toEqual(custom)
	})

	it('updates tap-zone layout, pedal slot bindings, and resets defaults in StageInputMappingControls', async () => {
		const wrapper = mount(StageInputMappingControls, {
			props: { mapping: { ...DEFAULT_STAGE_INPUT_MAPPING } },
		})

		await wrapper.get('[data-test="tap-layout-top-bottom"]').trigger('click')
		expect(wrapper.emitted('update:mapping')?.[0]?.[0]).toMatchObject({ tapZoneLayout: 'top-bottom' })

		const aux1 = wrapper.get('[data-test="pedal-slot-pedalAux1"]').findComponent({ name: 'NcSelect' })
		aux1.vm.$emit('update:modelValue', { value: 'toggle-metronome', label: 'Toggle metronome' })
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:mapping')?.[1]?.[0]).toMatchObject({ pedalAux1: 'toggle-metronome' })

		await wrapper.get('[data-test="stage-input-reset-button"]').trigger('click')
		expect(wrapper.emitted('update:mapping')?.[2]?.[0]).toEqual(DEFAULT_STAGE_INPUT_MAPPING)
	})
})
