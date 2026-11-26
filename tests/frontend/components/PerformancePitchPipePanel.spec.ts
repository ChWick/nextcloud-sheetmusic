import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PerformancePitchPipePanel from '../../../src/components/setlists/PerformancePitchPipePanel.vue'

describe('PerformancePitchPipePanel', () => {
	it('renders 12 chromatic notes, highlights song key, and emits note/calibration events', async () => {
		const wrapper = mount(PerformancePitchPipePanel, {
			props: {
				activePitchClass: 9,
				activeNoteLabel: 'A4',
				activeFrequency: 440,
				octave: 4,
				a4Hz: 440,
				waveform: 'triangle',
				songKeyPitchClass: 0,
				songKeyLabel: 'C Major',
			},
		})

		expect(wrapper.findAll('[data-test="pitch-pipe-note-grid"] button')).toHaveLength(12)
		expect(wrapper.get('[data-test="pitch-pipe-song-key"]').text()).toContain('C Major')
		expect(wrapper.get('[data-test="pitch-note-0"]').attributes('data-song-key')).toBe('true')
		expect(wrapper.get('[data-test="pitch-pipe-status"]').text()).toContain('A4')
		expect(wrapper.get('[data-test="pitch-pipe-status"]').text()).toContain('440.0 Hz')

		await wrapper.get('[data-test="pitch-note-0"]').trigger('click')
		expect(wrapper.emitted('toggleNote')).toEqual([[0]])

		await wrapper.get('[data-test="pitch-octave-5"]').trigger('click')
		expect(wrapper.emitted('update:octave')).toEqual([[5]])

		await wrapper.get('[data-test="pitch-a4-preset-442"]').trigger('click')
		expect(wrapper.emitted('update:a4Hz')).toEqual([[442]])

		await wrapper.get('[data-test="pitch-pipe-stop-button"]').trigger('click')
		expect(wrapper.emitted('stop')).toHaveLength(1)
	})
})
