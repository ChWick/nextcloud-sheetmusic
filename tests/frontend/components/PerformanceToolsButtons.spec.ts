import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PerformanceToolsButtons from '../../../src/components/setlists/PerformanceToolsButtons.vue'

describe('PerformanceToolsButtons', () => {
	const baseProps = {
		bpm: 120,
		timeSignature: { beats: 4, unit: 4, label: '4/4' },
		isMetronomeRunning: true,
		currentBeat: 0,
		accentFirstBeat: true,
		soundEnabled: true,
		visualFlashEnabled: true,
		activePitchClass: 9,
		activeNoteLabel: 'A4',
		activeFrequency: 440,
		octave: 4 as const,
		a4Hz: 440,
		waveform: 'triangle' as const,
	}

	it('displays active BPM and pitch badges, enforces mutual exclusion, and closes via closeOpenPopover', async () => {
		const wrapper = mount(PerformanceToolsButtons, {
			props: baseProps,
			attachTo: document.body,
		})

		const metBtn = wrapper.get('[data-test="performance-metronome-button"]')
		const pitchBtn = wrapper.get('[data-test="performance-pitch-pipe-button"]')
		expect(metBtn.text()).toContain('120')
		expect(pitchBtn.text()).toContain('A4')

		await metBtn.trigger('click')
		expect(document.body.querySelector('[data-test="metronome-panel"]')).not.toBeNull()
		expect(wrapper.emitted('update:anyOpen')?.at(-1)).toEqual([true])

		// Opening pitch pipe closes metronome
		await pitchBtn.trigger('click')
		expect(document.body.querySelector('[data-test="pitch-pipe-panel"]')).not.toBeNull()
		expect(document.body.querySelector('[data-test="metronome-panel"]')).toBeNull()

		// closeOpenPopover closes active popover and returns true
		expect(wrapper.vm.closeOpenPopover()).toBe(true)
		await wrapper.vm.$nextTick()
		expect(document.body.querySelector('[data-test="pitch-pipe-panel"]')).toBeNull()
		expect(wrapper.vm.closeOpenPopover()).toBe(false)

		wrapper.unmount()
	})
})
